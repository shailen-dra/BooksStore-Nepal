<?php
use BookstoreNepal\Gateway\KhaltiBlocks;
use BookstoreNepal\Gateway\KhaltiGateway;

/**
 * Khalti Web Checkout (KPG-2): initiate, the return handler and its lookup verification.
 * Runs again under HPOS (KhaltiGatewayHposTest). Keys are fake; HTTP is mocked.
 */
class KhaltiGatewayTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	private const TEST_KEY = 'test_secret_key_fake_0000';
	private const LIVE_KEY = 'live_secret_key_fake_1111';

	/** @var bool Whether a test rebuilt WooCommerce's gateway objects from its own settings. */
	private $gateways_reloaded = false;

	/** @var KhaltiGateway */
	private $gateway;

	/** @var WC_Product_Simple */
	private $product;

	/** @var array|WP_Error|null Mocked initiate response; null = a fresh pidx each call. */
	private $initiate_response;

	/** @var array<string,string|array|WP_Error> Lookup answer per pidx. */
	private $lookups = array();

	/** @var array<int,array{url:string,args:array}> Requests made, in order. */
	private $requests = array();

	/** @var int Counter for minted fake pidx values. */
	private $pidx_counter = 0;

	/** @var string File error_log() writes to during the test. */
	private $log;

	/** @var string|false error_log setting before the test. */
	private $previous_log;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		update_option(
			'woocommerce_bsn_khalti_settings',
			array(
				'enabled'         => 'yes',
				'testmode'        => 'yes',
				'test_secret_key' => self::TEST_KEY,
				'secret_key'      => self::LIVE_KEY,
			)
		);
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();

		$this->gateway = new KhaltiGateway();

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Picture book' );
		$this->product->set_regular_price( '500' );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();

		$this->initiate_response = null;
		$this->lookups           = array();
		$this->requests          = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		// Keep the expected "initiate/lookup failed" log lines out of the test output.
		$this->log          = wp_tempnam( 'bsn-khalti-log' );
		$this->previous_log = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet
	}

	private function logged(): string {
		return (string) file_get_contents( $this->log );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $this->log );
		parent::tear_down();
		if ( $this->gateways_reloaded ) {
			$this->gateways_reloaded = false;
			wp_cache_flush();
			$this->reload_payment_gateways();
		}
	}

	private static function response( int $code, $body ): array {
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( false !== strpos( $url, '/epayment/initiate/' ) ) {
			if ( null !== $this->initiate_response ) {
				return $this->initiate_response;
			}
			$pidx = 'PIDX' . ( ++$this->pidx_counter ) . 'abcdefghij';
			return self::response(
				200,
				array(
					'pidx'        => $pidx,
					'payment_url' => 'https://test-pay.khalti.com/?pidx=' . $pidx,
					'expires_at'  => '2026-11-22T16:26:16.471649+05:45',
					'expires_in'  => 1800,
				)
			);
		}
		if ( false !== strpos( $url, '/epayment/lookup/' ) ) {
			$body   = json_decode( (string) $args['body'], true );
			$pidx   = (string) ( $body['pidx'] ?? '' );
			$answer = $this->lookups[ $pidx ] ?? new WP_Error( 'http_request_failed', 'No mock configured.' );
			if ( $answer instanceof WP_Error ) {
				return $answer;
			}
			$answer = is_array( $answer ) ? $answer : array( 'status' => $answer );
			$status = $answer['status'];
			$code   = $answer['code'] ?? ( in_array( $status, array( 'Expired', 'User canceled' ), true ) ? 400 : 200 );
			if ( isset( $answer['raw'] ) ) {
				return self::response( $code, $answer['raw'] );
			}
			$paid = in_array( $status, array( 'Completed', 'Refunded', 'Partially Refunded' ), true );
			return self::response(
				$code,
				array(
					'pidx'           => $answer['pidx'] ?? $pidx,
					'total_amount'   => $answer['amount'] ?? 50000,
					'status'         => $status,
					'transaction_id' => $paid ? 'TXN' . $pidx : null,
					'fee'            => 0,
					'refunded'       => in_array( $status, array( 'Refunded', 'Partially Refunded' ), true ),
				)
			);
		}
		return new WP_Error( 'http_request_failed', 'Unexpected request.' );
	}

	/** @return array<int,array{url:string,args:array}> Lookup requests only. */
	private function lookup_requests(): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], '/epayment/lookup/' ) ) );
	}

	/**
	 * A pending Khalti order for one R500 book, with checkout already run (one pidx).
	 */
	private function order(): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( $this->gateway );
		$order->set_billing_first_name( 'Sita' );
		$order->set_billing_last_name( 'Sharma' );
		$order->set_billing_email( 'reader@example.com' );
		$order->set_billing_phone( '+977 980-0000001' );
		$order->calculate_totals();
		$order->save();
		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		return $this->fresh( $order );
	}

	private function pidx( WC_Order $order ): string {
		return (string) $order->get_meta( KhaltiGateway::META_PIDX );
	}

	/**
	 * The query Khalti's redirect to return_url carries (Khalti's params are appended to ours).
	 */
	private function return_query( WC_Order $order, string $pidx, string $status = 'Completed', array $overrides = array() ): array {
		return array_merge(
			array(
				'order_id'          => (string) $order->get_id(),
				'key'               => $order->get_order_key(),
				'pidx'              => $pidx,
				'status'            => $status,
				'transaction_id'    => 'Completed' === $status ? 'TXNFROMQUERY' : '',
				'tidx'              => 'Completed' === $status ? 'TXNFROMQUERY' : '',
				'amount'            => '50000',
				'total_amount'      => '50000',
				'mobile'            => '98XXXXX001',
				'purchase_order_id' => (string) $order->get_id(),
			),
			$overrides
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
		return count( array_filter( $this->note_texts( $order ), static fn( $note ) => false !== strpos( $note, $fragment ) ) );
	}

	private function stock(): int {
		return wc_get_product( $this->product->get_id() )->get_stock_quantity();
	}

	private function assert_unpaid( WC_Order $order, string $status = 'pending' ): void {
		$order = $this->fresh( $order );
		$this->assertSame( $status, $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 10, $this->stock(), 'Stock untouched.' );
		$notes = implode( "\n", $this->note_texts( $order ) );
		$this->assertStringNotContainsString( self::TEST_KEY, $notes );
		$this->assertStringNotContainsString( self::LIVE_KEY, $notes );
	}

	private function switch_to_cod( WC_Order $order ): WC_Order {
		$order = $this->fresh( $order );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->save();
		return $this->fresh( $order );
	}

	/** Adds an earlier pidx, as if the customer had tried once before, then retries. */
	private function add_earlier_attempt( WC_Order $order, string $pidx ): WC_Order {
		$order->update_meta_data( KhaltiGateway::META_PIDX, $pidx );
		$order->update_meta_data( KhaltiGateway::META_PIDXS, array( $pidx => '50000' ) );
		$order->save();
		$this->gateway->process_payment( $order->get_id() );
		$order = $this->fresh( $order );
		$this->assertNotSame( $pidx, $this->pidx( $order ) );
		return $order;
	}

	// ---- Settings and availability -------------------------------------------------------

	public function test_settings_title_description_and_password_key_fields(): void {
		$gateway = new KhaltiGateway();
		$this->assertSame( 'bsn_khalti', KhaltiGateway::ID );
		$this->assertSame( 'Khalti', $gateway->form_fields['title']['default'] );
		$this->assertSame( 'Pay with your Khalti wallet, mobile banking or card. You will be sent to Khalti to confirm.', $gateway->form_fields['description']['default'] );
		$this->assertSame( 'yes', $gateway->form_fields['testmode']['default'] );
		$this->assertSame( 'password', $gateway->form_fields['secret_key']['type'] );
		$this->assertSame( 'password', $gateway->form_fields['test_secret_key']['type'] );
		$this->assertSame( '', $gateway->form_fields['secret_key']['default'] );
		$this->assertSame( '', $gateway->form_fields['test_secret_key']['default'] );
	}

	public function test_available_only_for_npr_with_the_key_for_the_current_mode(): void {
		$this->assertTrue( $this->gateway->is_available() );

		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'yes', 'test_secret_key' => '', 'secret_key' => self::LIVE_KEY ) );
		$this->assertFalse( ( new KhaltiGateway() )->is_available(), 'Test mode without a test key.' );

		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY, 'secret_key' => '' ) );
		$this->assertFalse( ( new KhaltiGateway() )->is_available(), 'Live mode without a live key.' );

		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'secret_key' => self::LIVE_KEY ) );
		$this->assertTrue( ( new KhaltiGateway() )->is_available(), 'Live mode with a live key.' );

		update_option( 'woocommerce_currency', 'USD' );
		$this->assertFalse( ( new KhaltiGateway() )->is_available(), 'Non-NPR store.' );

		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'no', 'testmode' => 'yes', 'test_secret_key' => self::TEST_KEY ) );
		$this->assertFalse( ( new KhaltiGateway() )->is_available(), 'Disabled.' );
	}

	// ---- Initiate ------------------------------------------------------------------------

	public function test_process_payment_initiates_with_khalti_and_redirects_to_payment_url(): void {
		$order = $this->order();
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://dev.khalti.com/api/v2/epayment/initiate/', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 8, (int) $request['args']['timeout'] );
		$this->assertSame( 'Key ' . self::TEST_KEY, $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );

		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 50000, $body['amount'], 'Amount in paisa, as an integer.' );
		$this->assertSame( (string) $order->get_id(), $body['purchase_order_id'] );
		$this->assertSame( 'Order #' . $order->get_order_number(), $body['purchase_order_name'] );
		$this->assertSame( home_url( '/' ), $body['website_url'] );
		parse_str( (string) wp_parse_url( $body['return_url'], PHP_URL_QUERY ), $return );
		$this->assertSame( 'bsn_khalti', $return['wc-api'] );
		$this->assertSame( (string) $order->get_id(), $return['order_id'] );
		$this->assertSame( $order->get_order_key(), $return['key'] );
		$this->assertSame(
			array(
				'name'  => 'Sita Sharma',
				'email' => 'reader@example.com',
				'phone' => '9800000001',
			),
			$body['customer_info']
		);

		$pidx = $this->pidx( $order );
		$this->assertSame( 'PIDX1abcdefghij', $pidx );
		$this->assertSame( array( $pidx => '50000' ), $order->get_meta( KhaltiGateway::META_PIDXS ) );
		$this->assertSame( 'pending', $order->get_status() );

		$result = $this->gateway->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://test-pay.khalti.com/?pidx=PIDX2abcdefghij', $result['redirect'] );
		$order = $this->fresh( $order );
		$this->assertSame( 'PIDX2abcdefghij', $this->pidx( $order ) );
		$this->assertSame( array( 'PIDX1abcdefghij', 'PIDX2abcdefghij' ), array_keys( $order->get_meta( KhaltiGateway::META_PIDXS ) ) );
		$this->assertSame( '1', $order->get_meta( KhaltiGateway::META_MULTIPLE ) );
	}

	public function test_customer_phone_left_out_when_not_a_nepali_mobile_number(): void {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( $this->gateway );
		$order->set_billing_email( 'reader@example.com' );
		$order->set_billing_phone( '01-4412345' );
		$order->calculate_totals();
		$order->save();
		$this->gateway->process_payment( $order->get_id() );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'phone', $body['customer_info'] );
		$this->assertSame( 'reader@example.com', $body['customer_info']['email'] );
	}

	public function test_live_mode_uses_live_key_and_live_url(): void {
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY, 'secret_key' => self::LIVE_KEY ) );
		$this->gateway = new KhaltiGateway();
		$order         = $this->order();
		$this->assertSame( 'https://khalti.com/api/v2/epayment/initiate/', $this->requests[0]['url'] );
		$this->assertSame( 'Key ' . self::LIVE_KEY, $this->requests[0]['args']['headers']['Authorization'] );

		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$this->gateway->handle_return( $this->return_query( $order, $this->pidx( $order ) ) );
		$lookup = $this->lookup_requests()[0];
		$this->assertSame( 'https://khalti.com/api/v2/epayment/lookup/', $lookup['url'] );
		$this->assertSame( 'Key ' . self::LIVE_KEY, $lookup['args']['headers']['Authorization'] );
	}

	/**
	 * Initiate answers that must not send the customer anywhere.
	 */
	public function bad_initiate_answers(): array {
		return array(
			'network error'       => array( new WP_Error( 'http_request_failed', 'Operation timed out' ) ),
			'invalid key'         => array( self::response( 401, array( 'detail' => 'Invalid token.', 'status_code' => 401 ) ) ),
			'validation error'    => array( self::response( 400, array( 'amount' => array( 'Amount should be greater than Rs. 10, that is 1000 paisa.' ), 'error_key' => 'validation_error' ) ) ),
			'server error'        => array( self::response( 503, '<html>Down</html>' ) ),
			'no pidx'             => array( self::response( 200, array( 'payment_url' => 'https://test-pay.khalti.com/?pidx=x' ) ) ),
			'payment url elsewhere' => array( self::response( 200, array( 'pidx' => 'PIDXevil', 'payment_url' => 'https://evil.example.com/?pidx=PIDXevil' ) ) ),
			'plain http payment url' => array( self::response( 200, array( 'pidx' => 'PIDXhttp', 'payment_url' => 'http://test-pay.khalti.com/?pidx=PIDXhttp' ) ) ),
		);
	}

	/**
	 * @dataProvider bad_initiate_answers
	 */
	public function test_initiate_failure_shows_notice_and_does_not_redirect( $answer ): void {
		$this->initiate_response = $answer;
		$order                   = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( $this->gateway );
		$order->calculate_totals();
		$order->save();

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertArrayNotHasKey( 'redirect', $result );
		$order = $this->fresh( $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( '', $this->pidx( $order ) );
		$this->assertEmpty( $order->get_meta( KhaltiGateway::META_PIDXS ) );
		$notices = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
		$this->assertContains( 'We could not start your Khalti payment. Please try again in a moment or choose another payment method.', $notices );
		$notes = implode( "\n", $this->note_texts( $order ) );
		$this->assertStringContainsString( 'Khalti payment could not be started', $notes );
		$this->assertStringNotContainsString( self::TEST_KEY, $notes );
		$this->assertStringContainsString( 'initiate failed for order ' . $order->get_id(), $this->logged() );
		$this->assertStringNotContainsString( self::TEST_KEY, $this->logged() );
	}

	public function test_remembered_pidxs_are_capped_dropping_the_oldest(): void {
		$order = $this->order();
		$list  = array();
		for ( $i = 1; $i <= KhaltiGateway::MAX_REMEMBERED_PIDXS; $i++ ) {
			$list[ 'OLD' . $i ] = '50000';
		}
		$order->update_meta_data( KhaltiGateway::META_PIDXS, $list );
		$order->update_meta_data( KhaltiGateway::META_PIDX, 'OLD' . KhaltiGateway::MAX_REMEMBERED_PIDXS );
		$order->save();
		$this->gateway->process_payment( $order->get_id() );

		$remembered = $this->fresh( $order )->get_meta( KhaltiGateway::META_PIDXS );
		$this->assertCount( KhaltiGateway::MAX_REMEMBERED_PIDXS, $remembered );
		$this->assertArrayNotHasKey( 'OLD1', $remembered, 'Oldest dropped.' );
		$this->assertArrayHasKey( 'OLD2', $remembered );
		$this->assertArrayHasKey( $this->pidx( $this->fresh( $order ) ), $remembered, 'Newest kept.' );
	}

	// ---- Return handler: completion ------------------------------------------------------

	public function test_completed_lookup_completes_the_order(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';

		$redirect = $this->gateway->handle_return( $this->return_query( $order, $pidx ) );

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'TXN' . $pidx, $order->get_transaction_id(), 'Transaction id from the lookup, not the query.' );
		$this->assertSame( $pidx, $order->get_meta( KhaltiGateway::META_PAID_PIDX ) );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertSame( 9, $this->stock() );
		$lookups = $this->lookup_requests();
		$this->assertCount( 1, $lookups );
		$this->assertSame( 'https://dev.khalti.com/api/v2/epayment/lookup/', $lookups[0]['url'] );
		$this->assertSame( 'POST', $lookups[0]['args']['method'] );
		$this->assertSame( 8, (int) $lookups[0]['args']['timeout'] );
		$this->assertSame( 'Key ' . self::TEST_KEY, $lookups[0]['args']['headers']['Authorization'] );
		$this->assertSame( array( 'pidx' => $pidx ), json_decode( $lookups[0]['args']['body'], true ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Khalti payment confirmed by lookup' ) );
	}

	public function test_khalti_params_appended_with_a_second_question_mark_are_understood(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';
		$query = array(
			'order_id' => (string) $order->get_id(),
			'key'      => $order->get_order_key() . '?pidx=' . $pidx,
			'status'   => 'Completed',
		);
		$this->gateway->handle_return( $query );
		$this->assertTrue( $this->fresh( $order )->is_paid() );
	}

	public function test_return_for_an_earlier_attempt_completes_the_order(): void {
		$order   = $this->order();
		$earlier = 'EARLIERpidx000';
		$order   = $this->add_earlier_attempt( $order, $earlier );
		$this->lookups[ $earlier ] = 'Completed';

		$this->gateway->handle_return( $this->return_query( $order, $earlier ) );

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $earlier, $order->get_meta( KhaltiGateway::META_PAID_PIDX ) );
	}

	public function test_query_status_is_never_trusted(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Pending';
		$this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed' ) );
		$this->assert_unpaid( $order );
		$this->assertCount( 1, $this->lookup_requests(), 'Always verified with lookup.' );

		// The query says cancelled, but Khalti says paid: the lookup decides.
		$this->lookups[ $pidx ] = 'Completed';
		$this->gateway->handle_return( $this->return_query( $order, $pidx, 'User canceled' ) );
		$this->assertTrue( $this->fresh( $order )->is_paid() );
	}

	public function test_duplicate_return_completes_once(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';
		$query = $this->return_query( $order, $pidx );

		$completions = did_action( 'woocommerce_payment_complete' );
		$email_hooks = did_action( 'woocommerce_order_status_pending_to_processing' );
		$this->gateway->handle_return( $query );
		$notes = $this->note_texts( $this->fresh( $order ) );

		$redirect = $this->gateway->handle_return( $query );

		$order = $this->fresh( $order );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'payment_complete once.' );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ), 'Order emails once.' );
		$this->assertSame( 9, $this->stock(), 'Stock reduced once.' );
		$this->assertSame( $notes, $this->note_texts( $order ), 'No new notes.' );
		$this->assertCount( 1, $this->lookup_requests(), 'A paid order is not looked up again.' );
	}

	public function test_concurrent_duplicate_behind_stale_cache_not_completed_twice(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$id    = $order->get_id();
		$this->lookups[ $pidx ] = 'Completed';
		$query = $this->return_query( $order, $pidx );

		// Another request completes the order; this request's caches still hold it pending.
		$this->evict( $id );
		$stale      = wc_get_order( $id );
		$stale_post = wp_cache_get( $id, 'posts' );
		$stale_meta = wp_cache_get( $id, 'post_meta' );
		( new WC_Order( $id ) )->payment_complete( 'OTHER-REQUEST' );
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->set( $stale, $id );
		} else {
			wp_cache_set( $id, $stale_post, 'posts' );
			wp_cache_set( $id, $stale_meta, 'post_meta' );
		}
		$this->assertSame( 'pending', wc_get_order( $id )->get_status(), 'Precondition: stale pending copy.' );
		$completions = did_action( 'woocommerce_payment_complete' );

		$this->gateway->handle_return( $query );

		$order = $this->fresh( $order );
		$this->assertSame( 'OTHER-REQUEST', $order->get_transaction_id() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( array(), $this->lookup_requests() );
	}

	public function test_lock_held_elsewhere_logs_and_tells_customer(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'bsn_order_' . $order->get_id() ) ) );
		add_filter( 'bsn_khalti_lock_timeout', '__return_zero' );
		$log      = wp_tempnam( 'bsn-khalti-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		try {
			$redirect = $this->gateway->handle_return( $this->return_query( $order, $pidx ) );
		} finally {
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet
			remove_filter( 'bsn_khalti_lock_timeout', '__return_zero' );
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'bsn_order_' . $order->get_id() ) );
			$other->close();
		}
		$logged = (string) file_get_contents( $log );
		unlink( $log );
		$this->assertStringContainsString( 'order ' . $order->get_id(), $logged );
		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
		$this->assertSame( array(), $this->lookup_requests() );
		$this->assertContains( 'We’re confirming your Khalti payment. Please refresh this page in a moment, or contact us if it does not update.', wp_list_pluck( wc_get_notices( 'notice' ), 'notice' ) );
	}

	// ---- Return handler: tampering -------------------------------------------------------

	public function test_wrong_order_key_or_order_never_completes(): void {
		$order = $this->order();
		$other = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ]                 = 'Completed';
		$this->lookups[ $this->pidx( $other ) ] = 'Completed';
		$notes = $this->note_texts( $order );

		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed', array( 'key' => 'wc_order_guess' ) ) ) );
		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed', array( 'key' => '' ) ) ) );
		// This order's pidx and key, sent against another order.
		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed', array( 'order_id' => (string) $other->get_id() ) ) ) );
		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed', array( 'order_id' => '999999' ) ) ) );

		$this->assert_unpaid( $order );
		$this->assertFalse( $this->fresh( $other )->is_paid() );
		$this->assertSame( $notes, $this->note_texts( $this->fresh( $order ) ), 'No notes without the order key.' );
		$this->assertSame( array(), $this->lookup_requests() );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_pidx_of_another_order_never_completes(): void {
		$order = $this->order();
		$other = $this->order();
		$this->lookups[ $this->pidx( $other ) ] = 'Completed';

		// A genuine paid pidx for the other order, replayed against this one.
		$redirect = $this->gateway->handle_return( $this->return_query( $order, $this->pidx( $other ) ) );

		$this->assertSame( wc_get_cart_url(), $redirect );
		$this->assert_unpaid( $order );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'does not belong to this order' ) );
		$this->assertSame( array(), $this->lookup_requests(), 'Rejected before lookup.' );
		$this->assertSame( 'pending', $this->fresh( $other )->get_status() );

		// Missing pidx.
		wc_clear_notices();
		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, '' ) ) );
		$this->assert_unpaid( $order );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_query_amount_is_ignored_and_lookup_amount_mismatch_never_completes(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = array(
			'status' => 'Completed',
			'amount' => 10000,
		);
		$query = $this->return_query( $order, $pidx, 'Completed', array( 'amount' => '50000', 'total_amount' => '50000' ) );

		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $query ) );
		$this->gateway->handle_return( $query );

		$this->assert_unpaid( $order );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'amount mismatch' ), 'Noted once.' );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'Check the payment in the Khalti merchant portal and refund or reconcile it.' ), 'Staff get refund guidance.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_lookup_for_another_pidx_never_completes(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = array(
			'status' => 'Completed',
			'pidx'   => 'SOMEOTHERpidx',
		);
		$this->gateway->handle_return( $this->return_query( $order, $pidx ) );
		$this->assert_unpaid( $order );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'does not match' ) );
	}

	public function test_pidx_from_before_modes_were_recorded_is_still_checked(): void {
		// add_earlier_attempt() stores the list in its old shape (pidx => amount, no mode).
		$order   = $this->order();
		$earlier = 'LEGACYpidx000';
		$order   = $this->add_earlier_attempt( $order, $earlier );
		$this->lookups[ $earlier ] = 'Completed';
		$this->gateway->handle_return( $this->return_query( $order, $earlier ) );
		$this->assertTrue( $this->fresh( $order )->is_paid() );
	}

	// ---- Return handler: other statuses --------------------------------------------------

	public function cancelled_statuses(): array {
		return array(
			'User canceled' => array( 'User canceled' ),
			'Expired'       => array( 'Expired' ),
		);
	}

	/**
	 * @dataProvider cancelled_statuses
	 */
	public function test_cancelled_or_expired_fails_order_and_keeps_cart( string $status ): void {
		WC()->cart->add_to_cart( $this->product->get_id() );
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = $status;

		$redirect = $this->gateway->handle_return( $this->return_query( $order, $pidx, $status ) );

		$this->assertSame( wc_get_cart_url(), $redirect );
		$this->assert_unpaid( $order, 'failed' );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'Cart preserved.' );
		$this->assertContains( 'Khalti payment was not completed. You can try again or choose another payment method.', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertTrue( $this->fresh( $order )->needs_payment(), 'Customer can retry.' );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), $status ) );

		// Refreshing the return URL adds nothing.
		$this->gateway->handle_return( $this->return_query( $order, $pidx, $status ) );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), $status ) );
	}

	public function open_answers(): array {
		return array(
			'Pending'       => array( 'Pending' ),
			'Initiated'     => array( 'Initiated' ),
			'network error' => array( new WP_Error( 'http_request_failed', 'Operation timed out' ) ),
			'server error'  => array(
				array(
					'status' => 'Completed',
					'code'   => 503,
				),
			),
			'not found'     => array(
				array(
					'status' => '',
					'code'   => 404,
					'raw'    => '{"detail":"Not found.","error_key":"validation_error"}',
				),
			),
		);
	}

	/**
	 * @dataProvider open_answers
	 */
	public function test_pending_or_unanswered_lookup_keeps_order_pending( $answer ): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = $answer;
		$redirect = $this->gateway->handle_return( $this->return_query( $order, $pidx ) );
		$this->gateway->handle_return( $this->return_query( $order, $pidx ) );

		$this->assertSame( $order->get_checkout_order_received_url(), $redirect );
		$this->assert_unpaid( $order );
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), $pidx ), 'One note for the pidx, however often the customer returns.' );
		$this->assertContains( 'We’re confirming your Khalti payment. Please refresh this page in a moment, or contact us if it does not update.', wp_list_pluck( wc_get_notices( 'notice' ), 'notice' ) );
	}

	public function test_refunded_lookup_is_noted_and_never_paid(): void {
		foreach ( array( 'Refunded', 'Partially Refunded' ) as $status ) {
			wc_clear_notices();
			$order = $this->order();
			$pidx  = $this->pidx( $order );
			$this->lookups[ $pidx ] = $status;
			$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $this->return_query( $order, $pidx ) ) );
			$this->gateway->handle_return( $this->return_query( $order, $pidx ) );
			$this->assert_unpaid( $order );
			$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), $status ), $status );
			$this->assertNotEmpty( wc_get_notices( 'error' ) );
		}
	}

	// ---- Return handler: switched orders, paid orders ------------------------------------

	public function test_completed_pidx_for_order_switched_to_cod_gets_one_stray_note(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$query = $this->return_query( $order, $pidx );
		$order = $this->switch_to_cod( $order );
		$this->lookups[ $pidx ] = 'Completed';

		$this->assertSame( wc_get_cart_url(), $this->gateway->handle_return( $query ) );
		$this->gateway->handle_return( $query );

		$order = $this->fresh( $order );
		$this->assertSame( 'cod', $order->get_payment_method(), 'Payment method not changed back.' );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'after it was switched to another payment method' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'refund in the Khalti merchant portal' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, $pidx ) );
		$this->assertCount( 1, $this->lookup_requests(), 'A noted stray payment is not looked up again.' );
		$this->assertContains( 'This order is no longer set to be paid with Khalti. If money was deducted from your Khalti account, please contact us and we will refund it.', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
	}

	public function test_switched_order_without_completed_payment_or_key_gets_no_note(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$order = $this->switch_to_cod( $order );
		$notes = $this->note_texts( $order );

		$this->lookups[ $pidx ] = 'User canceled';
		$this->gateway->handle_return( $this->return_query( $order, $pidx, 'User canceled' ) );
		$this->assertSame( 'pending', $this->fresh( $order )->get_status(), 'A COD order is not failed by Khalti.' );

		$this->lookups[ $pidx ] = 'Completed';
		$this->gateway->handle_return( $this->return_query( $order, $pidx, 'Completed', array( 'key' => 'wc_order_guess' ) ) );
		$this->gateway->handle_return( $this->return_query( $order, 'NOTOURSpidx' ) );

		$this->assertSame( $notes, $this->note_texts( $this->fresh( $order ) ) );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
		$this->assertCount( 1, $this->lookup_requests(), 'Only the keyed return with our pidx is looked up.' );
	}

	// ---- Registration --------------------------------------------------------------------

	public function test_gateway_registered_with_blocks_integration(): void {
		$this->assertContains( KhaltiGateway::class, apply_filters( 'woocommerce_payment_gateways', array() ) );
		$this->assertNotFalse( has_action( 'woocommerce_api_bsn_khalti', array( KhaltiGateway::class, 'dispatch_return' ) ) );
		$this->gateways_reloaded = true;
		$this->reload_payment_gateways();
		$this->assertInstanceOf( KhaltiGateway::class, WC()->payment_gateways()->payment_gateways()['bsn_khalti'] );
		$blocks = new KhaltiBlocks();
		$blocks->initialize();
		$this->assertSame( 'bsn_khalti', $blocks->get_name() );
		$this->assertTrue( $blocks->is_active() );
		$data = $blocks->get_payment_method_data();
		$this->assertSame( 'Khalti', $data['title'] );
		$this->assertSame( 'Pay with your Khalti wallet, mobile banking or card. You will be sent to Khalti to confirm.', $data['description'] );
		$this->assertContains( 'products', $data['supports'] );
		$this->assertArrayNotHasKey( 'test_secret_key', $data );
		$this->assertStringNotContainsString( self::TEST_KEY, wp_json_encode( $data ) );
		$this->assertSame( array( 'bsn-khalti-blocks' ), $blocks->get_payment_method_script_handles() );
		$this->assertStringEndsWith( 'assets/js/khalti-blocks.js', wp_scripts()->registered['bsn-khalti-blocks']->src );

		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'yes', 'test_secret_key' => '' ) );
		$this->reload_payment_gateways();
		$this->assertFalse( ( new KhaltiBlocks() )->is_active(), 'Inactive without a key.' );
	}
}
