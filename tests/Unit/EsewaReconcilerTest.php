<?php
use BookstoreNepal\Gateway\EsewaGateway;
use BookstoreNepal\Gateway\EsewaReconciler;
use BookstoreNepal\Gateway\EsewaSignature;

/**
 * Background reconciliation of eSewa orders with eSewa's status API, and the guard on
 * WooCommerce's unpaid-order auto-cancel. Runs again under HPOS (EsewaReconcilerHposTest).
 */
class EsewaReconcilerTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	private const TEST_SECRET = '8gBm/:&EnhH.1/q';

	/** @var EsewaGateway */
	private $gateway;

	/** @var WC_Product_Simple */
	private $product;

	/** @var array<string,string|array|WP_Error> Status-API answer per transaction UUID. */
	private $statuses = array();

	/** @var string[] Transaction UUIDs the status API was asked about, in order. */
	private $requested = array();

	/** @var int[] Timeouts the status requests were made with. */
	private $timeouts = array();

	/** @var callable|null Runs inside the status-API request (simulates a concurrent request). */
	private $during_request;

	/** @var string File error_log() writes to during the test. */
	private $log;

	/** @var string|false error_log setting before the test. */
	private $previous_log;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option(
			'woocommerce_esewa_settings',
			array(
				'enabled'  => 'yes',
				'testmode' => 'yes',
			)
		);
		// The reconciler uses WooCommerce's gateway object; rebuild it from this test's settings.
		$this->reload_payment_gateways();
		wc_load_cart();
		wc_clear_notices();
		delete_option( EsewaReconciler::OPTION_OFFSETS );
		EsewaReconciler::reset_request_state();

		$this->gateway = new EsewaGateway();

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Picture book' );
		$this->product->set_regular_price( '500' );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();

		$this->statuses       = array();
		$this->requested      = array();
		$this->timeouts       = array();
		$this->during_request = null;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		// Keep the expected "status check failed" log lines out of the test output.
		$this->log          = wp_tempnam( 'bsn-esewa-log' );
		$this->previous_log = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet
	}

	private function logged(): string {
		return (string) file_get_contents( $this->log );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $this->log );
		EsewaReconciler::unschedule();
		EsewaReconciler::reset_request_state();
		parent::tear_down();
		// Rebuild from the rolled-back settings; the options cache holds this test's values until flushed.
		wp_cache_flush();
		$this->reload_payment_gateways();
	}

	public function mock_http( $pre, $args, $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$uuid              = (string) ( $query['transaction_uuid'] ?? '' );
		$this->requested[] = $uuid;
		$this->timeouts[]  = (int) $args['timeout'];
		if ( $this->during_request ) {
			$during               = $this->during_request;
			$this->during_request = null;
			$during();
		}
		$answer = $this->statuses[ $uuid ] ?? new WP_Error( 'http_request_failed', 'No mock configured.' );
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}
		$answer = is_array( $answer ) ? $answer : array( 'status' => $answer );
		$code   = $answer['code'] ?? 200;
		return array(
			'headers'  => array(),
			'body'     => isset( $answer['raw'] ) ? $answer['raw'] : wp_json_encode(
				array(
					'product_code'     => $answer['product_code'] ?? 'EPAYTEST',
					'transaction_uuid' => $uuid,
					'total_amount'     => $answer['amount'] ?? (float) ( $query['total_amount'] ?? 0 ),
					'status'           => $answer['status'],
					'ref_id'           => 'COMPLETE' === $answer['status'] ? '0007G36' : null,
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
	 * A pending eSewa checkout order for one R500 book, created $minutes ago.
	 */
	private function order( int $minutes = 15 ): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( $this->gateway );
		$order->set_billing_email( 'reader@example.com' );
		$order->set_created_via( 'checkout' );
		$order->calculate_totals();
		$order->set_date_created( time() - $minutes * MINUTE_IN_SECONDS );
		$order->save();
		$this->gateway->process_payment( $order->get_id() );
		return $this->fresh( $order );
	}

	private function uuid( WC_Order $order ): string {
		return (string) $order->get_meta( EsewaGateway::META_UUID );
	}

	/**
	 * Adds an earlier transaction UUID, as if the customer had tried once before.
	 */
	private function add_earlier_attempt( WC_Order $order, string $uuid ): WC_Order {
		$order->update_meta_data( EsewaGateway::META_UUID, $uuid );
		$order->update_meta_data( EsewaGateway::META_UUIDS, array( $uuid => '500' ) );
		$order->save();
		$this->gateway->process_payment( $order->get_id() ); // The retry mints a new current UUID.
		$order = $this->fresh( $order );
		$this->assertNotSame( $uuid, $this->uuid( $order ) );
		return $order;
	}

	/**
	 * eSewa's signed success redirect for one of the order's transactions.
	 */
	private function callback_success( WC_Order $order, string $uuid ): string {
		$payload              = array(
			'transaction_code'   => '0LD5CEH',
			'status'             => 'COMPLETE',
			'total_amount'       => '500.0',
			'transaction_uuid'   => $uuid,
			'product_code'       => 'EPAYTEST',
			'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
		);
		$payload['signature'] = EsewaSignature::sign( $payload, explode( ',', $payload['signed_field_names'] ), self::TEST_SECRET );
		return $this->gateway->handle_callback(
			array(
				'result'   => 'success',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
				'data'     => base64_encode( wp_json_encode( $payload ) ),
			)
		);
	}

	private function fresh( WC_Order $order ): WC_Order {
		$this->evict( $order->get_id() );
		return wc_get_order( $order->get_id() );
	}

	private function evict( int $id ): void {
		wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		clean_post_cache( $id );
	}

	/** @return string[] */
	private function note_texts( WC_Order $order ): array {
		return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
	}

	private function count_notes_containing( WC_Order $order, string $fragment ): int {
		return count( array_filter( $this->note_texts( $order ), fn( $note ) => false !== strpos( $note, $fragment ) ) );
	}

	private function stock(): int {
		return wc_get_product( $this->product->get_id() )->get_stock_quantity();
	}

	private function switch_to_cod( WC_Order $order ): WC_Order {
		$order = $this->fresh( $order );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->save();
		return $this->fresh( $order );
	}

	private function assert_untouched( WC_Order $order, array $notes_before ): void {
		$order = $this->fresh( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( $notes_before, $this->note_texts( $order ), 'No notes added.' );
	}

	public function test_paid_order_whose_customer_never_returned_is_completed_once(): void {
		$order = $this->order();
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$completions = did_action( 'woocommerce_payment_complete' );
		$email_hooks = did_action( 'woocommerce_order_status_pending_to_processing' );

		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '0007G36', $order->get_transaction_id() );
		$this->assertSame( '0007G36', $order->get_meta( EsewaGateway::META_REF_ID ) );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Payment confirmed by eSewa status check (customer did not return to the site). Ref: 0007G36' ) );
		$this->assertSame( array( $this->uuid( $order ) ), $this->requested );
		$this->assertSame( array( 8 ), $this->timeouts, 'Status API timeout is 8 seconds.' );

		$notes = $this->note_texts( $order );
		EsewaReconciler::run();
		$order = $this->fresh( $order );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'Not completed twice.' );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ), 'No second email.' );
		$this->assertSame( 9, $this->stock(), 'Stock reduced once.' );
		$this->assertSame( $notes, $this->note_texts( $order ) );
		$this->assertCount( 1, $this->requested, 'Paid orders are not checked again.' );
	}

	public function test_failed_order_paid_on_esewa_is_completed(): void {
		$order = $this->order();
		$order->update_status( 'failed' );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		EsewaReconciler::run();
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
		$this->assertSame( 9, $this->stock() );
	}

	public function test_every_remembered_uuid_is_checked(): void {
		$order   = $this->order();
		$earlier = $order->get_id() . '-1700000000';
		$order   = $this->add_earlier_attempt( $order, $earlier );
		$this->statuses[ $this->uuid( $order ) ] = 'NOT_FOUND';
		$this->statuses[ $earlier ]               = 'COMPLETE';

		EsewaReconciler::run();

		$this->assertSame( array( $this->uuid( $order ), $earlier ), $this->requested, 'Newest first, then the earlier attempt.' );
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 9, $this->stock() );
	}

	public function test_callback_completing_first_means_no_second_completion(): void {
		$order = $this->order();
		$uuid  = $this->uuid( $order );
		$this->statuses[ $uuid ] = 'COMPLETE';
		$payload = array(
			'transaction_code'   => '0LD5CEH',
			'status'             => 'COMPLETE',
			'total_amount'       => '500.0',
			'transaction_uuid'   => $uuid,
			'product_code'       => 'EPAYTEST',
			'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
		);
		$payload['signature'] = EsewaSignature::sign( $payload, explode( ',', $payload['signed_field_names'] ), self::TEST_SECRET );
		$this->gateway->handle_callback(
			array(
				'result'   => 'success',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
				'data'     => base64_encode( wp_json_encode( $payload ) ),
			)
		);
		$completions = did_action( 'woocommerce_payment_complete' );
		$this->requested = array();

		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( '0LD5CEH', $order->get_transaction_id() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_callback_completing_while_reconcile_waits_on_status_api(): void {
		$order = $this->order();
		$id    = $order->get_id();
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		// While the reconciler waits on eSewa, the callback request pays the order and commits.
		// This request still holds a pending copy in its caches.
		$this->during_request = function () use ( $id ) {
			$this->evict( $id );
			$stale      = wc_get_order( $id );
			$stale_post = wp_cache_get( $id, 'posts' );
			$stale_meta = wp_cache_get( $id, 'post_meta' );
			( new WC_Order( $id ) )->payment_complete( 'CALLBACK' );
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->set( $stale, $id );
			} else {
				wp_cache_set( $id, $stale_post, 'posts' );
				wp_cache_set( $id, $stale_meta, 'post_meta' );
			}
		};
		$completions = did_action( 'woocommerce_payment_complete' );

		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'CALLBACK', $order->get_transaction_id() );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'Only the callback completed it.' );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'customer did not return' ) );
	}

	public function test_order_locked_by_a_callback_is_left_for_the_next_run(): void {
		$order = $this->order();
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$notes = $this->note_texts( $order );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'bsn_order_' . $order->get_id() ) ) );
		try {
			EsewaReconciler::run();
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'bsn_order_' . $order->get_id() ) );
			$other->close();
		}
		$this->assert_untouched( $order, $notes );

		EsewaReconciler::run();
		$this->assertSame( 'processing', $this->fresh( $order )->get_status(), 'Completed on the next run.' );
	}

	public function test_pending_ambiguous_or_unreachable_api_leaves_order_untouched(): void {
		foreach ( array( 'PENDING', 'AMBIGUOUS', array( 'status' => 'COMPLETE', 'code' => 503 ), new WP_Error( 'http_request_failed', 'Operation timed out' ) ) as $answer ) {
			EsewaReconciler::reset_request_state();
			$order = $this->order();
			$notes = $this->note_texts( $order );
			$this->statuses[ $this->uuid( $order ) ] = $answer;
			$this->requested                          = array();
			EsewaReconciler::run();
			$this->assertSame( array( $this->uuid( $order ) ), $this->requested );
			$this->assert_untouched( $order, $notes );
			$order->delete( true );
		}
	}

	public function test_not_found_adds_one_note_across_runs(): void {
		$order = $this->order();
		$uuid  = $this->uuid( $order );
		$this->statuses[ $uuid ] = 'NOT_FOUND';
		EsewaReconciler::run();
		EsewaReconciler::run();
		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 10, $this->stock() );
		$this->assertCount( 3, $this->requested, 'NOT_FOUND is checked again on later runs.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'NOT_FOUND' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $uuid ) );

		// A different status for the same transaction is noted once too.
		$this->statuses[ $uuid ] = 'CANCELED';
		EsewaReconciler::run();
		EsewaReconciler::run();
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'CANCELED' ) );
		$this->assertCount( 4, $this->requested, 'A cancelled transaction is final: not checked again.' );
	}

	public function test_partial_refund_note_says_esewa_still_holds_part_of_the_payment(): void {
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = 'PARTIAL_REFUND';
		EsewaReconciler::run();
		EsewaReconciler::run();
		$order = $this->fresh( $order );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'PARTIAL_REFUND' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'eSewa still holds part of this payment' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'check the eSewa merchant portal' ) );
		$this->assertFalse( $order->is_paid() );
		$this->assertCount( 1, $this->requested, 'Final status: not asked again.' );
		$this->assertTrue( $this->should_cancel( $order ), 'May still be auto-cancelled.' );
	}

	public function test_partial_refund_of_another_attempt_on_a_paid_order_is_noted(): void {
		$order = $this->order();
		$first = $order->get_id() . '-1700000000';
		$order = $this->add_earlier_attempt( $order, $first );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$this->callback_success( $order, $this->uuid( $order ) );
		$this->statuses[ $first ] = 'PARTIAL_REFUND';
		EsewaReconciler::run();
		EsewaReconciler::run();
		$order = $this->fresh( $order );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'eSewa still holds part of this payment' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $first ) );
	}

	public function test_complete_with_wrong_amount_is_noted_once_and_not_completed(): void {
		$order = $this->order();
		$this->statuses[ $this->uuid( $order ) ] = array(
			'status' => 'COMPLETE',
			'amount' => 100.0,
		);
		EsewaReconciler::run();
		EsewaReconciler::run();
		$order = $this->fresh( $order );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'amount mismatch' ) );
	}

	public function test_orders_younger_than_ten_minutes_or_older_than_two_days_are_skipped(): void {
		$young = $this->order( 5 );
		$old   = $this->order( 49 * 60 );
		$this->statuses[ $this->uuid( $young ) ] = 'COMPLETE';
		$this->statuses[ $this->uuid( $old ) ]   = 'COMPLETE';
		EsewaReconciler::run();
		$this->assertSame( array(), $this->requested );
		$this->assertSame( 'pending', $this->fresh( $young )->get_status() );
		$this->assertSame( 'pending', $this->fresh( $old )->get_status() );
	}

	public function test_order_switched_to_cod_with_paid_uuid_gets_one_stray_note(): void {
		$order = $this->order();
		$uuid  = $this->uuid( $order );
		$order = $this->switch_to_cod( $order );
		$this->statuses[ $uuid ] = 'COMPLETE';

		EsewaReconciler::run();
		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'after it was switched to another payment method' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $uuid ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'check the eSewa merchant portal and refund' ) );
		$this->assertSame( array( $uuid ), $this->requested, 'A noted stray payment is not checked again.' );
	}

	public function test_cod_order_already_processing_with_paid_uuid_gets_stray_note(): void {
		$order = $this->order();
		$uuid  = $this->uuid( $order );
		$order = $this->switch_to_cod( $order );
		$order->update_status( 'processing' );
		$this->statuses[ $uuid ] = 'COMPLETE';
		EsewaReconciler::run();
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'after it was switched to another payment method' ) );
	}

	public function test_stray_already_noted_by_signed_callback_is_not_noted_again(): void {
		$order   = $this->order();
		$uuid    = $this->uuid( $order );
		$payload = array(
			'transaction_code'   => '0LD5CEH',
			'status'             => 'COMPLETE',
			'total_amount'       => '500.0',
			'transaction_uuid'   => $uuid,
			'product_code'       => 'EPAYTEST',
			'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
		);
		$payload['signature'] = EsewaSignature::sign( $payload, explode( ',', $payload['signed_field_names'] ), self::TEST_SECRET );
		$order = $this->switch_to_cod( $order );
		$this->gateway->handle_callback(
			array(
				'result'   => 'success',
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
				'data'     => base64_encode( wp_json_encode( $payload ) ),
			)
		);
		$this->statuses[ $uuid ] = 'COMPLETE';
		EsewaReconciler::run();
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'after it was switched to another payment method' ) );
		$this->assertSame( array(), $this->requested );
	}

	public function test_second_payment_on_order_paid_by_callback_gets_one_refund_note(): void {
		// Paid on attempt A, the tab died; retried and paid on B, and B's callback completed the order.
		$order   = $this->order();
		$first   = $order->get_id() . '-1700000000';
		$order   = $this->add_earlier_attempt( $order, $first );
		$second  = $this->uuid( $order );
		$this->statuses[ $first ]  = 'COMPLETE';
		$this->statuses[ $second ] = 'COMPLETE';
		$this->callback_success( $order, $second );
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
		$completions     = did_action( 'woocommerce_payment_complete' );
		$this->requested = array();

		EsewaReconciler::run();
		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( array( $first ), $this->requested, 'Only the other transaction is asked, once.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'eSewa took a second payment for this order — refund ref 0007G36 in the eSewa merchant portal' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $first ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '0LD5CEH', $order->get_transaction_id() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( 9, $this->stock() );
	}

	public function test_reconcile_that_completes_an_order_checks_its_other_transactions(): void {
		$order  = $this->order();
		$first  = $order->get_id() . '-1700000000';
		$order  = $this->add_earlier_attempt( $order, $first );
		$second = $this->uuid( $order );
		$this->statuses[ $first ]  = 'COMPLETE';
		$this->statuses[ $second ] = 'COMPLETE';

		EsewaReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( array( $second, $first ), $this->requested );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $second, $order->get_meta( EsewaGateway::META_PAID_UUID ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'eSewa took a second payment for this order' ) );
		$this->assertSame( 9, $this->stock() );

		EsewaReconciler::run();
		$this->assertCount( 2, $this->requested, 'Nothing left to ask.' );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'eSewa took a second payment for this order' ) );
	}

	public function test_unpaid_other_transactions_of_a_paid_order_are_asked_until_answered(): void {
		$order  = $this->order();
		$first  = $order->get_id() . '-1700000000';
		$order  = $this->add_earlier_attempt( $order, $first );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$this->callback_success( $order, $this->uuid( $order ) );
		$notes           = $this->note_texts( $this->fresh( $order ) );
		$this->requested = array();

		$this->statuses[ $first ] = 'PENDING';
		EsewaReconciler::run();
		$this->statuses[ $first ] = 'NOT_FOUND';
		EsewaReconciler::run();
		EsewaReconciler::run();

		$this->assertSame( array( $first, $first ), $this->requested, 'PENDING asked again; NOT_FOUND settles it.' );
		$this->assertSame( $notes, $this->note_texts( $this->fresh( $order ) ), 'No notes for an unpaid extra attempt.' );
	}

	public function test_paid_orders_with_one_transaction_or_older_than_two_days_are_not_asked(): void {
		$single = $this->order();
		$this->statuses[ $this->uuid( $single ) ] = 'COMPLETE';
		$this->callback_success( $single, $this->uuid( $single ) );

		$old   = $this->order( 49 * 60 );
		$first = $old->get_id() . '-1700000000';
		$old   = $this->add_earlier_attempt( $old, $first );
		$this->statuses[ $this->uuid( $old ) ] = 'COMPLETE';
		$this->statuses[ $first ]              = 'COMPLETE';
		$this->callback_success( $old, $this->uuid( $old ) );
		$this->requested = array();

		EsewaReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_orders_without_esewa_transactions_are_ignored(): void {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals();
		$order->set_date_created( time() - HOUR_IN_SECONDS );
		$order->save();
		EsewaReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_batch_is_capped_and_later_runs_reach_the_rest(): void {
		$orders = array();
		for ( $i = 0; $i < EsewaReconciler::BATCH_SIZE + 3; $i++ ) {
			$order                                   = $this->order( 15 + $i );
			$orders[]                                = $order;
			$this->statuses[ $this->uuid( $order ) ] = 'NOT_FOUND';
		}
		EsewaReconciler::run();
		$this->assertCount( EsewaReconciler::BATCH_SIZE, $this->requested );
		$first = $this->requested;

		EsewaReconciler::run();
		$this->assertCount( EsewaReconciler::BATCH_SIZE + 3, array_unique( array_merge( $first, array_slice( $this->requested, count( $first ) ) ) ), 'Every order checked within two runs.' );
	}

	public function test_unreachable_api_stops_the_run(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->order( 15 + $i );
		}
		EsewaReconciler::run(); // No mock: every request fails.
		$this->assertCount( 1, $this->requested );
		$this->assertStringContainsString( 'eSewa status check failed: No mock configured.', $this->logged(), 'Logged for the debug log.' );
	}

	public function test_live_mode_without_credentials_does_not_call_the_api(): void {
		$order = $this->order();
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'no' ) );
		$this->reload_payment_gateways();
		EsewaReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_recurring_action_scheduled_once_and_removed_on_deactivation(): void {
		$this->assertTrue( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ), 'Action Scheduler loaded with WooCommerce.' );
		EsewaReconciler::unschedule();
		EsewaReconciler::schedule();
		EsewaReconciler::schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => EsewaReconciler::HOOK,
				'group'    => 'bsn',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		$this->assertCount( 1, $actions );
		$action = reset( $actions );
		$this->assertSame( 'bsn_esewa_reconcile', EsewaReconciler::HOOK );
		$this->assertSame( 300, $action->get_schedule()->get_recurrence() );
		$this->assertNotFalse( has_action( 'bsn_esewa_reconcile', array( EsewaReconciler::class, 'run' ) ) );
		$this->assertNotFalse( has_action( 'init', array( EsewaReconciler::class, 'maybe_schedule' ) ) );

		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( BSN_PLUGIN_FILE ), array( EsewaReconciler::class, 'unschedule' ) ) );
		EsewaReconciler::unschedule();
		$this->assertFalse( as_next_scheduled_action( EsewaReconciler::HOOK, array(), 'bsn' ) );
	}

	public function test_maybe_schedule_skips_front_end_page_views(): void {
		EsewaReconciler::unschedule();
		EsewaReconciler::maybe_schedule();
		$this->assertFalse( as_next_scheduled_action( EsewaReconciler::HOOK, array(), 'bsn' ) );
		$this->assertFalse( EsewaReconciler::is_scheduling_request( false ) );
	}

	public function test_maybe_schedule_runs_for_admin_requests(): void {
		EsewaReconciler::unschedule();
		set_current_screen( 'dashboard' );
		try {
			EsewaReconciler::maybe_schedule();
		} finally {
			set_current_screen( 'front' );
			$GLOBALS['current_screen'] = null;
		}
		$this->assertNotFalse( as_next_scheduled_action( EsewaReconciler::HOOK, array(), 'bsn' ) );
	}

	public function test_maybe_schedule_runs_for_cron_requests(): void {
		EsewaReconciler::unschedule();
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			EsewaReconciler::maybe_schedule();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
		$this->assertNotFalse( as_next_scheduled_action( EsewaReconciler::HOOK, array(), 'bsn' ) );
	}

	public function test_wp_cli_requests_schedule(): void {
		// WP_CLI cannot be defined here without leaking into every later test, so the check takes it as an argument.
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI, 'Precondition: not under WP-CLI.' );
		$this->assertTrue( EsewaReconciler::is_scheduling_request( true ) );
	}

	/**
	 * Asks WooCommerce's unpaid-order auto-cancel whether to cancel this order.
	 */
	private function should_cancel( WC_Order $order, bool $cancel = true ): bool {
		return (bool) apply_filters( 'woocommerce_cancel_unpaid_order', $cancel, $order );
	}

	public function test_auto_cancel_completes_order_esewa_says_is_paid(): void {
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$this->assertFalse( $this->should_cancel( $order ) );
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Payment confirmed by eSewa status check (customer did not return to the site). Ref: 0007G36' ) );
	}

	public function test_auto_cancel_waits_when_esewa_cannot_say(): void {
		$order = $this->order( 90 );
		$notes = $this->note_texts( $order );
		$this->statuses[ $this->uuid( $order ) ] = new WP_Error( 'http_request_failed', 'Operation timed out' );
		$this->assertFalse( $this->should_cancel( $order ), 'API error: not cancelled now.' );
		$this->assert_untouched( $order, $notes );

		// The API is down for this request: other eSewa orders are not cancelled either, without more requests.
		$second = $this->order( 90 );
		$this->statuses[ $this->uuid( $second ) ] = 'NOT_FOUND';
		$this->assertFalse( $this->should_cancel( $second ) );
		$this->assertCount( 1, $this->requested );

		EsewaReconciler::reset_request_state();
		$this->statuses[ $this->uuid( $order ) ] = 'PENDING';
		$this->assertFalse( $this->should_cancel( $order ), 'PENDING: not cancelled now.' );
		$this->assert_untouched( $order, $notes );
	}

	public function test_auto_cancel_server_error_stops_further_requests(): void {
		$first = $this->order( 90 );
		$this->statuses[ $this->uuid( $first ) ] = array(
			'status' => 'COMPLETE',
			'code'   => 503,
		);
		$this->assertFalse( $this->should_cancel( $first ) );
		$second = $this->order( 90 );
		$this->statuses[ $this->uuid( $second ) ] = 'NOT_FOUND';
		$this->assertFalse( $this->should_cancel( $second ), '5xx: eSewa is down, nothing is cancelled this time.' );
		$this->assertCount( 1, $this->requested );
	}

	/**
	 * Answers eSewa might give about one bad transaction while the API itself works.
	 */
	public function bad_answers(): array {
		return array(
			'HTTP 400'     => array( array( 'status' => 'NOT_FOUND', 'code' => 400 ) ),
			'HTTP 404'     => array( array( 'status' => 'NOT_FOUND', 'code' => 404 ) ),
			'empty status' => array( '' ),
			'unreadable'   => array(
				array(
					'status' => '',
					'raw'    => '<html>Bad gateway page</html>',
				),
			),
		);
	}

	/**
	 * @dataProvider bad_answers
	 */
	public function test_auto_cancel_bad_answer_for_one_order_does_not_block_the_others( $answer ): void {
		$bad   = $this->order( 90 );
		$notes = $this->note_texts( $bad );
		$this->statuses[ $this->uuid( $bad ) ] = $answer;
		$this->assertFalse( $this->should_cancel( $bad ), 'That order is not cancelled now.' );
		$this->assert_untouched( $bad, $notes );

		$good = $this->order( 90 );
		$this->statuses[ $this->uuid( $good ) ] = 'NOT_FOUND';
		$this->assertTrue( $this->should_cancel( $good ), 'The next order is still checked.' );
		$this->assertCount( 2, $this->requested );
	}

	/**
	 * @dataProvider bad_answers
	 */
	public function test_bad_answer_for_one_transaction_does_not_stop_the_run( $answer ): void {
		$first   = $this->order( 30 );
		$earlier = $first->get_id() . '-1700000000';
		$first   = $this->add_earlier_attempt( $first, $earlier );
		$second  = $this->order( 20 );
		$this->statuses[ $this->uuid( $first ) ]  = $answer;
		$this->statuses[ $earlier ]               = 'NOT_FOUND';
		$this->statuses[ $this->uuid( $second ) ] = 'COMPLETE';

		EsewaReconciler::run();

		$this->assertContains( $earlier, $this->requested, 'The order\'s other transactions are still checked.' );
		$this->assertSame( 'processing', $this->fresh( $second )->get_status(), 'Later orders are still checked.' );
		$this->assertSame( 'pending', $this->fresh( $first )->get_status() );
	}

	public function test_auto_cancel_goes_ahead_when_esewa_has_no_payment(): void {
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = 'NOT_FOUND';
		$this->assertTrue( $this->should_cancel( $order ) );
		$this->assertFalse( $this->should_cancel( $order, false ), 'Original value kept.' );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_auto_cancel_waits_in_live_mode_without_credentials(): void {
		// Paid in test mode; the owner turns test mode off before entering the live keys.
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'product_code' => '', 'secret_key' => '' ) );
		$this->reload_payment_gateways();
		$this->assertFalse( $this->should_cancel( $order ), 'Nothing can be verified: never cancel (and restock) a possibly paid order.' );
		$this->assertSame( array(), $this->requested );

		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'product_code' => 'LIVECODE', 'secret_key' => '' ) );
		$this->reload_payment_gateways();
		$this->assertFalse( $this->should_cancel( $order ), 'Product code without a secret is not usable either.' );
		$this->assertSame( array(), $this->requested );
	}

	public function test_amount_mismatch_note_tells_staff_to_refund_or_reconcile(): void {
		$order = $this->order();
		$this->statuses[ $this->uuid( $order ) ] = array(
			'status' => 'COMPLETE',
			'amount' => 100.0,
		);
		EsewaReconciler::run();
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'Check the payment in the eSewa merchant portal and refund or reconcile it.' ) );
	}

	public function test_auto_cancel_of_complete_with_wrong_amount_waits_for_staff(): void {
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = array(
			'status' => 'COMPLETE',
			'amount' => 100.0,
		);
		$this->assertFalse( $this->should_cancel( $order ) );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_auto_cancel_leaves_other_orders_alone(): void {
		$order = $this->order( 90 );
		$order = $this->switch_to_cod( $order );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$this->assertTrue( $this->should_cancel( $order ) );
		$this->assertFalse( $this->should_cancel( $order, false ) );

		$plain = wc_create_order();
		$plain->set_payment_method( 'cod' );
		$plain->save();
		$this->assertTrue( $this->should_cancel( $plain ) );
		$this->assertSame( array(), $this->requested );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_auto_cancel_not_asked_to_cancel_makes_no_request(): void {
		$order = $this->order( 90 );
		$this->statuses[ $this->uuid( $order ) ] = 'COMPLETE';
		$this->assertFalse( $this->should_cancel( $order, false ) );
		$this->assertSame( array(), $this->requested, 'The scheduled check will complete it.' );
	}

	public function test_woocommerce_unpaid_order_cron_does_not_cancel_paid_esewa_order(): void {
		update_option( 'woocommerce_hold_stock_minutes', '60' );
		$paid   = $this->order( 90 );
		$unpaid = $this->order( 90 );
		$this->statuses[ $this->uuid( $paid ) ]   = 'COMPLETE';
		$this->statuses[ $this->uuid( $unpaid ) ] = 'NOT_FOUND';
		$this->age_last_update( $paid, 90 );
		$this->age_last_update( $unpaid, 90 );

		wc_cancel_unpaid_orders();

		$this->assertSame( 'processing', $this->fresh( $paid )->get_status() );
		$this->assertSame( 'cancelled', $this->fresh( $unpaid )->get_status() );
		$this->assertSame( 9, $this->stock(), 'Paid order keeps its stock; the cancelled one is restocked.' );
	}

	/**
	 * Backdates an order's last-modified time, which WooCommerce's auto-cancel goes by.
	 */
	private function age_last_update( WC_Order $order, int $minutes ): void {
		global $wpdb;
		$gmt = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $gmt ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified_gmt' => $gmt,
					'post_modified'     => get_date_from_gmt( $gmt ),
				),
				array( 'ID' => $order->get_id() )
			);
		}
		$this->evict( $order->get_id() );
	}
}
