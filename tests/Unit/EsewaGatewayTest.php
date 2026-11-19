<?php
use BookstoreNepal\Gateway\EsewaBlocks;
use BookstoreNepal\Gateway\EsewaGateway;
use BookstoreNepal\Gateway\EsewaSignature;

class EsewaGatewayTest extends WP_UnitTestCase {

	private const TEST_SECRET = '8gBm/:&EnhH.1/q';

	/** @var EsewaGateway */
	private $gateway;

	/** @var WC_Product_Simple */
	private $product;

	/** @var array|WP_Error|null Mocked status-check response; null = no mock (fail the test). */
	private $status_response;

	/** @var string[] URLs the gateway requested. */
	private $requested = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		update_option(
			'woocommerce_esewa_settings',
			array(
				'enabled'  => 'yes',
				'testmode' => 'yes',
			)
		);
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();

		$this->gateway = new EsewaGateway();

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Picture book' );
		$this->product->set_regular_price( '500' );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();

		$this->status_response = null;
		$this->requested       = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	public function mock_http( $pre, $args, $url ) {
		$this->requested[] = $url;
		if ( null === $this->status_response ) {
			return new WP_Error( 'http_request_failed', 'No mock configured.' );
		}
		return $this->status_response;
	}

	private function status_api( string $status, string $uuid, $amount = 500.0, int $code = 200 ): void {
		$this->status_response = array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'product_code'     => 'EPAYTEST',
					'transaction_uuid' => $uuid,
					'total_amount'     => $amount,
					'status'           => $status,
					'ref_id'           => 'COMPLETE' === $status ? '0007G36' : null,
				)
			),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A pending eSewa order for one R500 book, with checkout already run.
	 */
	private function order(): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( $this->gateway );
		$order->set_billing_email( 'reader@example.com' );
		$order->calculate_totals();
		$order->save();
		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		return wc_get_order( $order->get_id() );
	}

	private function payload( WC_Order $order, array $overrides = array(), string $secret = self::TEST_SECRET ): array {
		$payload = array_merge(
			array(
				'transaction_code'   => '0LD5CEH',
				'status'             => 'COMPLETE',
				'total_amount'       => '500.0',
				'transaction_uuid'   => $order->get_meta( EsewaGateway::META_UUID ),
				'product_code'       => 'EPAYTEST',
				'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
			),
			$overrides
		);
		$payload['signature'] = EsewaSignature::sign( $payload, explode( ',', $payload['signed_field_names'] ), $secret );
		return $payload;
	}

	private function success_query( WC_Order $order, array $payload ): array {
		return array(
			'result'   => 'success',
			'order_id' => (string) $order->get_id(),
			'key'      => $order->get_order_key(),
			'data'     => base64_encode( wp_json_encode( $payload ) ),
		);
	}

	private function fresh( WC_Order $order ): WC_Order {
		$this->evict( $order->get_id() );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Drops this request's cached copies of an order (HPOS order cache, CPT post/meta caches).
	 */
	private function evict( int $id ): void {
		wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		clean_post_cache( $id );
	}

	/**
	 * Simulates a concurrent request: this request has the order cached as pending,
	 * then another request completes the payment and commits it. This request's
	 * caches still hold the pre-payment copy afterwards.
	 */
	private function pay_behind_stale_cache( WC_Order $order ): void {
		$id    = $order->get_id();
		$this->evict( $id );
		$stale      = wc_get_order( $id ); // Primes the caches with the pending order.
		$stale_post = wp_cache_get( $id, 'posts' );
		$stale_meta = wp_cache_get( $id, 'post_meta' );

		$other = new WC_Order( $id );
		$other->payment_complete( 'OTHER-REQUEST' );

		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->set( $stale, $id );
		} else {
			wp_cache_set( $id, $stale_post, 'posts' );
			wp_cache_set( $id, $stale_meta, 'post_meta' );
		}
		$this->assertSame( 'pending', wc_get_order( $id )->get_status(), 'Precondition: this request sees a stale pending copy.' );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Precondition: stock reduced by the other request.' );
	}

	public function test_concurrent_duplicate_behind_stale_cache_not_completed_twice(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$query = $this->success_query( $order, $this->payload( $order ) );
		$this->pay_behind_stale_cache( $order );

		$completions = did_action( 'woocommerce_payment_complete' );
		$email_hooks = did_action( 'woocommerce_order_status_pending_to_processing' );
		$redirect    = $this->gateway->handle_callback( $query );

		$order = $this->fresh( $order );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'OTHER-REQUEST', $order->get_transaction_id() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ), 'payment_complete not called again.' );
		$this->assertSame( $email_hooks, did_action( 'woocommerce_order_status_pending_to_processing' ), 'No second email.' );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Stock reduced once.' );
		$this->assertSame( array(), $this->requested, 'Status API not called for a paid order.' );
	}

	public function test_failure_url_behind_stale_cache_leaves_paid_order_paid(): void {
		$order = $this->order();
		$this->pay_behind_stale_cache( $order );

		$redirect = $this->gateway->handle_callback(
			array(
				'result'   => 'failure',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
	}

	public function test_lock_held_elsewhere_logs_and_tells_customer(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );

		// Another request (separate DB connection) is processing this order.
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'bsn_esewa_' . $order->get_id() ) ) );
		add_filter( 'bsn_esewa_lock_timeout', '__return_zero' );
		$log      = wp_tempnam( 'bsn-esewa-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet

		try {
			$redirect = $this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		} finally {
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet
			remove_filter( 'bsn_esewa_lock_timeout', '__return_zero' );
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'bsn_esewa_' . $order->get_id() ) );
			$other->close();
		}

		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertStringContainsString( 'order ' . $order->get_id(), $logged );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
		$this->assertSame( array(), $this->requested );
		$notices = wp_list_pluck( wc_get_notices( 'notice' ), 'notice' );
		$this->assertContains( 'We’re still confirming your eSewa payment. Please refresh this page in a moment, or contact us if it does not update.', $notices );
	}

	private function notes( WC_Order $order ): string {
		return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	private function assert_unpaid_with_note( WC_Order $order, string $note_fragment ): void {
		$order = $this->fresh( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertStringContainsString( $note_fragment, $this->notes( $order ) );
		$this->assertStringNotContainsString( self::TEST_SECRET, $this->notes( $order ) );
		$this->assertSame( 10, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Stock untouched.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ), 'Customer sees an error notice.' );
	}

	public function test_process_payment_stores_uuid_and_redirects_to_receipt_page(): void {
		$order = $this->order();
		$uuid  = $order->get_meta( EsewaGateway::META_UUID );
		$this->assertMatchesRegularExpression( '/^' . $order->get_id() . '-\d+$/', $uuid );
		$this->assertSame( 'pending', $order->get_status() );

		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( $order->get_checkout_payment_url( true ), $result['redirect'] );
	}

	public function test_receipt_form_posts_signed_fields_to_test_form_url(): void {
		$order = $this->order();
		$uuid  = $order->get_meta( EsewaGateway::META_UUID );
		$form  = $this->gateway->form_fields_for( $order );

		$this->assertSame( '500', $form['total_amount'] );
		$this->assertSame( '500', $form['amount'] );
		$this->assertSame( '0', $form['tax_amount'] );
		$this->assertSame( '0', $form['product_service_charge'] );
		$this->assertSame( '0', $form['product_delivery_charge'] );
		$this->assertSame( $uuid, $form['transaction_uuid'] );
		$this->assertSame( 'EPAYTEST', $form['product_code'] );
		$this->assertSame( 'total_amount,transaction_uuid,product_code', $form['signed_field_names'] );
		$this->assertSame(
			EsewaSignature::sign( $form, array( 'total_amount', 'transaction_uuid', 'product_code' ), self::TEST_SECRET ),
			$form['signature']
		);
		$this->assertStringContainsString( 'wc-api=bsn_esewa', $form['success_url'] );
		$this->assertStringContainsString( 'result=success', $form['success_url'] );
		$this->assertStringContainsString( 'result=failure', $form['failure_url'] );

		ob_start();
		$this->gateway->receipt_page( $order->get_id() );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'action="https://rc-epay.esewa.com.np/api/epay/main/v2/form"', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'Pay with eSewa', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( $form['signature'] ) . '"', $html );
	}

	public function test_valid_callback_completes_order(): void {
		$order = $this->order();
		$uuid  = $order->get_meta( EsewaGateway::META_UUID );
		$this->status_api( 'COMPLETE', $uuid );

		$redirect = $this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '0LD5CEH', $order->get_transaction_id() );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity() );
		$this->assertCount( 1, $this->requested );
		$this->assertStringStartsWith( 'https://rc.esewa.com.np/api/epay/transaction/status/?', $this->requested[0] );
		$this->assertStringContainsString( 'transaction_uuid=' . $uuid, $this->requested[0] );
		$this->assertStringContainsString( 'total_amount=500', $this->requested[0] );
		$this->assertStringContainsString( 'product_code=EPAYTEST', $this->requested[0] );
	}

	public function test_data_appended_with_second_question_mark_is_understood(): void {
		// eSewa appends "?data=..." to success_url even when it already has a query string.
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$query        = $this->success_query( $order, $this->payload( $order ) );
		$data         = $query['data'];
		unset( $query['data'] );
		$query['key'] = $query['key'] . '?data=' . str_replace( '+', ' ', $data ); // "+" arrives as a space.

		$this->gateway->handle_callback( $query );
		$this->assertTrue( $this->fresh( $order )->is_paid() );
	}

	public function test_tampered_signature_leaves_order_pending(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );

		$payload              = $this->payload( $order );
		$payload['signature'] = base64_encode( str_repeat( 'x', 32 ) );
		$redirect             = $this->gateway->handle_callback( $this->success_query( $order, $payload ) );
		$this->assert_unpaid_with_note( $order, 'signature' );
		$this->assertSame( wc_get_cart_url(), $redirect );

		// Validly formed but signed with someone else's key.
		wc_clear_notices();
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order, array(), 'attacker-secret' ) ) );
		$this->assert_unpaid_with_note( $order, 'signature' );
		$this->assertSame( array(), $this->requested, 'Status API not called for forged data.' );
	}

	public function test_unsigned_fields_are_not_trusted(): void {
		$order   = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		// Signature only covers total_amount; status/uuid/transaction_code are unsigned.
		$payload = $this->payload( $order, array( 'signed_field_names' => 'total_amount' ) );
		$this->gateway->handle_callback( $this->success_query( $order, $payload ) );
		$this->assert_unpaid_with_note( $order, 'signed' );
	}

	public function test_amount_mismatch_leaves_order_pending(): void {
		$order = $this->order();
		$this->assertSame( '500', $order->get_total() );
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ), 100.0 );

		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order, array( 'total_amount' => '100.0' ) ) ) );
		$this->assert_unpaid_with_note( $order, 'callback rejected: amount mismatch (eSewa 100.0, order total 500)' );
		$this->assertSame( array(), $this->requested, 'Rejected before the status API.' );
	}

	public function test_status_api_amount_mismatch_leaves_order_pending(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ), 100.0 );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		$this->assert_unpaid_with_note( $order, 'status check rejected: amount mismatch' );
	}

	public function test_status_api_other_transaction_rejected(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', '999-1700000000' );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		$this->assert_unpaid_with_note( $order, 'status check rejected: transaction does not match' );
	}

	public function test_uuid_of_other_order_rejected(): void {
		$order = $this->order();
		$other = $this->order();
		$this->status_api( 'COMPLETE', $other->get_meta( EsewaGateway::META_UUID ) );

		// A genuine, correctly signed payment for the other order, replayed against this one.
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $other ) ) );
		$this->assert_unpaid_with_note( $order, 'callback rejected: transaction does not belong to this order' );
		$this->assertSame( array(), $this->requested, 'Rejected before the status API.' );
		$this->assertSame( 'pending', $this->fresh( $other )->get_status() );
	}

	public function test_wrong_order_key_rejected(): void {
		$order        = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$query        = $this->success_query( $order, $this->payload( $order ) );
		$query['key'] = 'wc_order_guess';
		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_callback( $query ) );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_callback_status_not_complete_rejected(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order, array( 'status' => 'PENDING' ) ) ) );
		$this->assert_unpaid_with_note( $order, 'PENDING' );
	}

	public function test_duplicate_callback_processed_once(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$query = $this->success_query( $order, $this->payload( $order ) );

		// "pending_to_processing" is the action WooCommerce's order emails (new order,
		// processing order) are triggered from, sent now or deferred.
		$completions = did_action( 'woocommerce_payment_complete' );
		$email_hooks = did_action( 'woocommerce_order_status_pending_to_processing' );
		$this->gateway->handle_callback( $query );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ), 'Order emails triggered once.' );
		$notes_after_first = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$redirect = $this->gateway->handle_callback( $query );

		$order = $this->fresh( $order );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'payment_complete not called again.' );
		$this->assertSame( 9, wc_get_product( $this->product->get_id() )->get_stock_quantity(), 'Stock reduced once.' );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ), 'No second email.' );
		$this->assertCount( $notes_after_first, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertCount( 1, $this->requested, 'Status API called once.' );
	}

	public function test_status_api_not_complete_leaves_pending(): void {
		$order = $this->order();
		$this->status_api( 'PENDING', $order->get_meta( EsewaGateway::META_UUID ) );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		$this->assert_unpaid_with_note( $order, 'PENDING' );
	}

	public function test_status_api_error_or_non_200_leaves_pending(): void {
		$order = $this->order();
		$this->status_response = new WP_Error( 'http_request_failed', 'Operation timed out' );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		$this->assert_unpaid_with_note( $order, 'status check' );

		wc_clear_notices();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ), 500.0, 503 );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );
		$this->assert_unpaid_with_note( $order, '503' );
	}

	public function test_failure_url_marks_failed_and_keeps_cart(): void {
		WC()->cart->add_to_cart( $this->product->get_id() );
		$order = $this->order();

		$redirect = $this->gateway->handle_callback(
			array(
				'result'   => 'failure',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);

		$this->assertSame( 'failed', $this->fresh( $order )->get_status() );
		$this->assertSame( wc_get_cart_url(), $redirect );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'Cart preserved.' );
		$notices = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
		$this->assertContains( 'eSewa payment was not completed. You can try again or choose another payment method.', $notices );
		$this->assertTrue( $this->fresh( $order )->needs_payment(), 'Customer can retry payment.' );
	}

	public function test_failure_url_does_not_touch_paid_order(): void {
		$order = $this->order();
		$this->status_api( 'COMPLETE', $order->get_meta( EsewaGateway::META_UUID ) );
		$this->gateway->handle_callback( $this->success_query( $order, $this->payload( $order ) ) );

		$redirect = $this->gateway->handle_callback(
			array(
				'result'   => 'failure',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			)
		);
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
	}

	public function test_failure_url_needs_order_key(): void {
		$order = $this->order();
		$this->gateway->handle_callback(
			array(
				'result'   => 'failure',
				'order_id' => (string) $order->get_id(),
				'key'      => 'wc_order_guess',
			)
		);
		$this->assertSame( 'pending', $this->fresh( $order )->get_status() );
	}

	public function test_live_mode_needs_credentials_and_npr(): void {
		$this->assertTrue( $this->gateway->is_available() );

		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'product_code' => '', 'secret_key' => '' ) );
		$this->assertFalse( ( new EsewaGateway() )->is_available(), 'Live mode without keys.' );

		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'yes' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertFalse( ( new EsewaGateway() )->is_available(), 'Non-NPR store.' );
	}

	public function test_gateway_registered_and_blocks_integration(): void {
		$this->assertContains( EsewaGateway::class, apply_filters( 'woocommerce_payment_gateways', array() ) );
		$blocks = new EsewaBlocks();
		$blocks->initialize();
		$this->assertSame( 'esewa', $blocks->get_name() );
		$this->assertTrue( $blocks->is_active() );
		$data = $blocks->get_payment_method_data();
		$this->assertSame( 'eSewa', $data['title'] );
		$this->assertContains( 'products', $data['supports'] );
		$this->assertSame( array( 'bsn-esewa-blocks' ), $blocks->get_payment_method_script_handles() );
	}
}
