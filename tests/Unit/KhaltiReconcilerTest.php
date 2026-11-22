<?php
use BookstoreNepal\Gateway\KhaltiGateway;
use BookstoreNepal\Gateway\KhaltiReconciler;

/**
 * Background reconciliation of Khalti orders with Khalti's lookup API, and the guard on
 * WooCommerce's unpaid-order auto-cancel. Runs again under HPOS (KhaltiReconcilerHposTest).
 */
class KhaltiReconcilerTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	private const TEST_KEY = 'test_secret_key_fake_0000';

	/** @var KhaltiGateway */
	private $gateway;

	/** @var WC_Product_Simple */
	private $product;

	/** @var array<string,string|array|WP_Error> Lookup answer per pidx. */
	private $lookups = array();

	/** @var string[] pidx values looked up, in order. */
	private $requested = array();

	/** @var int[] Timeouts the lookups were made with. */
	private $timeouts = array();

	/** @var int Counter for minted fake pidx values. */
	private $pidx_counter = 0;

	/** @var callable|null Runs inside the lookup request (simulates a concurrent request). */
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
			'woocommerce_bsn_khalti_settings',
			array(
				'enabled'         => 'yes',
				'testmode'        => 'yes',
				'test_secret_key' => self::TEST_KEY,
			)
		);
		$this->reload_payment_gateways();
		wc_load_cart();
		wc_clear_notices();
		delete_option( KhaltiReconciler::OPTION_OFFSETS );
		KhaltiReconciler::reset_request_state();

		$this->gateway = new KhaltiGateway();

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Picture book' );
		$this->product->set_regular_price( '500' );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();

		$this->lookups        = array();
		$this->requested      = array();
		$this->timeouts       = array();
		$this->during_request = null;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		$this->log          = wp_tempnam( 'bsn-khalti-log' );
		$this->previous_log = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $this->log );
		KhaltiReconciler::unschedule();
		KhaltiReconciler::reset_request_state();
		parent::tear_down();
		wp_cache_flush();
		$this->reload_payment_gateways();
	}

	private function logged(): string {
		return (string) file_get_contents( $this->log );
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
		if ( false !== strpos( $url, '/epayment/initiate/' ) ) {
			$pidx = 'PIDX' . ( ++$this->pidx_counter ) . 'r';
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
		if ( false === strpos( $url, '/epayment/lookup/' ) ) {
			return new WP_Error( 'http_request_failed', 'Unexpected request.' );
		}
		$body              = json_decode( (string) $args['body'], true );
		$pidx              = (string) ( $body['pidx'] ?? '' );
		$this->requested[] = $pidx;
		$this->timeouts[]  = (int) $args['timeout'];
		if ( $this->during_request ) {
			$during               = $this->during_request;
			$this->during_request = null;
			$during();
		}
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
				'pidx'           => $pidx,
				'total_amount'   => $answer['amount'] ?? 50000,
				'status'         => $status,
				'transaction_id' => $paid ? 'TXN' . $pidx : null,
				'fee'            => 0,
				'refunded'       => in_array( $status, array( 'Refunded', 'Partially Refunded' ), true ),
			)
		);
	}

	/**
	 * A pending Khalti checkout order for one R500 book, created $minutes ago.
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
		$this->assertSame( 'success', $this->gateway->process_payment( $order->get_id() )['result'] );
		return $this->fresh( $order );
	}

	private function pidx( WC_Order $order ): string {
		return (string) $order->get_meta( KhaltiGateway::META_PIDX );
	}

	/** A second attempt: the earlier pidx stays remembered. */
	private function retry( WC_Order $order ): WC_Order {
		$this->gateway->process_payment( $order->get_id() );
		return $this->fresh( $order );
	}

	private function customer_returns( WC_Order $order, string $pidx ): string {
		return $this->gateway->handle_return(
			array(
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
				'pidx'     => $pidx,
				'status'   => 'Completed',
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
		return count( array_filter( $this->note_texts( $order ), static fn( $note ) => false !== strpos( $note, $fragment ) ) );
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
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';
		$completions = did_action( 'woocommerce_payment_complete' );
		$email_hooks = did_action( 'woocommerce_order_status_pending_to_processing' );

		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'TXN' . $pidx, $order->get_transaction_id() );
		$this->assertSame( $pidx, $order->get_meta( KhaltiGateway::META_PAID_PIDX ) );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( $email_hooks + 1, did_action( 'woocommerce_order_status_pending_to_processing' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Payment confirmed by Khalti lookup (customer did not return to the site)' ) );
		$this->assertSame( array( $pidx ), $this->requested );
		$this->assertSame( array( 8 ), $this->timeouts, 'Lookup timeout is 8 seconds.' );

		$notes = $this->note_texts( $order );
		KhaltiReconciler::run();
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'Not completed twice.' );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( $notes, $this->note_texts( $this->fresh( $order ) ) );
		$this->assertCount( 1, $this->requested, 'Paid orders with one pidx are not looked up again.' );
	}

	public function test_failed_order_paid_on_khalti_is_completed(): void {
		$order = $this->order();
		$order->update_status( 'failed' );
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		KhaltiReconciler::run();
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
	}

	public function test_every_remembered_pidx_is_checked_newest_first(): void {
		$order   = $this->order();
		$earlier = $this->pidx( $order );
		$order   = $this->retry( $order );
		$this->lookups[ $this->pidx( $order ) ] = 'Expired';
		$this->lookups[ $earlier ]               = 'Completed';

		KhaltiReconciler::run();

		$this->assertSame( array( $this->pidx( $order ), $earlier ), $this->requested );
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $earlier, $order->get_meta( KhaltiGateway::META_PAID_PIDX ) );
	}

	public function test_return_completing_first_means_no_second_completion(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$this->customer_returns( $order, $this->pidx( $order ) );
		$completions     = did_action( 'woocommerce_payment_complete' );
		$this->requested = array();

		KhaltiReconciler::run();

		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( array(), $this->requested );
	}

	public function test_return_completing_while_reconcile_waits_on_lookup(): void {
		$order = $this->order();
		$id    = $order->get_id();
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$this->during_request = function () use ( $id ) {
			$this->evict( $id );
			$stale      = wc_get_order( $id );
			$stale_post = wp_cache_get( $id, 'posts' );
			$stale_meta = wp_cache_get( $id, 'post_meta' );
			( new WC_Order( $id ) )->payment_complete( 'RETURN' );
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->set( $stale, $id );
			} else {
				wp_cache_set( $id, $stale_post, 'posts' );
				wp_cache_set( $id, $stale_meta, 'post_meta' );
			}
		};
		$completions = did_action( 'woocommerce_payment_complete' );

		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'RETURN', $order->get_transaction_id() );
		$this->assertSame( $completions + 1, did_action( 'woocommerce_payment_complete' ), 'Only the return completed it.' );
		$this->assertSame( 9, $this->stock() );
		$this->assertSame( 0, $this->count_notes_containing( $order, 'customer did not return' ) );
	}

	public function test_order_locked_by_a_return_is_left_for_the_next_run(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$notes = $this->note_texts( $order );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'bsn_order_' . $order->get_id() ) ) );
		try {
			KhaltiReconciler::run();
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'bsn_order_' . $order->get_id() ) );
			$other->close();
		}
		$this->assert_untouched( $order, $notes );

		KhaltiReconciler::run();
		$this->assertSame( 'processing', $this->fresh( $order )->get_status(), 'Completed on the next run.' );
	}

	public function test_pending_initiated_or_unreachable_api_leaves_order_untouched(): void {
		$answers = array(
			'Pending',
			'Initiated',
			array(
				'status' => 'Completed',
				'code'   => 503,
			),
			new WP_Error( 'http_request_failed', 'Operation timed out' ),
		);
		foreach ( $answers as $answer ) {
			KhaltiReconciler::reset_request_state();
			$order = $this->order();
			$notes = $this->note_texts( $order );
			$this->lookups[ $this->pidx( $order ) ] = $answer;
			$this->requested                         = array();
			KhaltiReconciler::run();
			$this->assertSame( array( $this->pidx( $order ) ), $this->requested );
			$this->assert_untouched( $order, $notes );
			$order->delete( true );
		}
	}

	public function test_cancelled_pidx_is_noted_once_and_not_asked_again(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'User canceled';
		KhaltiReconciler::run();
		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'pending', $order->get_status(), 'WooCommerce\'s own auto-cancel decides.' );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'User canceled' ) );
		$this->assertSame( array( $pidx ), $this->requested, 'A final status is not asked again.' );
	}

	public function test_partially_refunded_note_says_khalti_still_holds_part_of_the_payment(): void {
		$order = $this->order( 90 );
		$this->lookups[ $this->pidx( $order ) ] = 'Partially Refunded';
		KhaltiReconciler::run();
		KhaltiReconciler::run();
		$order = $this->fresh( $order );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Khalti still holds part of this payment' ) );
		$this->assertFalse( $order->is_paid() );
		$this->assertCount( 1, $this->requested );
	}

	public function test_refunded_is_noted_once_and_never_paid(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = 'Refunded';
		KhaltiReconciler::run();
		KhaltiReconciler::run();
		$order = $this->fresh( $order );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Refunded' ) );
	}

	public function test_completed_with_wrong_amount_is_noted_once_and_not_completed(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = array(
			'status' => 'Completed',
			'amount' => 10000,
		);
		KhaltiReconciler::run();
		KhaltiReconciler::run();
		$order = $this->fresh( $order );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'amount mismatch' ) );
	}

	public function test_orders_younger_than_ten_minutes_or_older_than_two_days_are_skipped(): void {
		$young = $this->order( 5 );
		$old   = $this->order( 49 * 60 );
		$this->lookups[ $this->pidx( $young ) ] = 'Completed';
		$this->lookups[ $this->pidx( $old ) ]   = 'Completed';
		KhaltiReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_order_switched_to_cod_with_completed_pidx_gets_one_stray_note(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$order = $this->switch_to_cod( $order );
		$this->assertSame( '1', $order->get_meta( KhaltiGateway::META_STRAY_CHECK ), 'Flagged for the stray check.' );
		$this->lookups[ $pidx ] = 'Completed';

		KhaltiReconciler::run();
		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'after it was switched to another payment method' ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'refund in the Khalti merchant portal' ) );
		$this->assertSame( array( $pidx ), $this->requested, 'A noted stray payment is not looked up again.' );
		$this->assertSame( '', (string) $order->get_meta( KhaltiGateway::META_STRAY_CHECK ), 'Flag cleared once nothing is left to ask.' );
	}

	public function test_stray_noted_by_the_return_is_not_noted_again(): void {
		$order = $this->order();
		$pidx  = $this->pidx( $order );
		$order = $this->switch_to_cod( $order );
		$this->lookups[ $pidx ] = 'Completed';
		$this->customer_returns( $order, $pidx );
		KhaltiReconciler::run();
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'after it was switched to another payment method' ) );
		$this->assertSame( array( $pidx ), $this->requested, 'Only the return looked it up.' );
	}

	public function test_second_payment_on_order_paid_by_return_gets_one_refund_note(): void {
		$order  = $this->order();
		$first  = $this->pidx( $order );
		$order  = $this->retry( $order );
		$second = $this->pidx( $order );
		$this->lookups[ $first ]  = 'Completed';
		$this->lookups[ $second ] = 'Completed';
		$this->customer_returns( $order, $second );
		$this->assertSame( 'processing', $this->fresh( $order )->get_status() );
		$completions     = did_action( 'woocommerce_payment_complete' );
		$this->requested = array();

		KhaltiReconciler::run();
		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( array( $first ), $this->requested, 'Only the other pidx is asked, once.' );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Khalti took a second payment for this order — refund transaction TXN' . $first . ' in the Khalti merchant portal' ) );
		$this->assertSame( 'TXN' . $second, $order->get_transaction_id() );
		$this->assertSame( $completions, did_action( 'woocommerce_payment_complete' ) );
		$this->assertSame( 9, $this->stock() );
	}

	public function test_reconcile_that_completes_an_order_checks_its_other_pidxs(): void {
		$order  = $this->order();
		$first  = $this->pidx( $order );
		$order  = $this->retry( $order );
		$second = $this->pidx( $order );
		$this->lookups[ $first ]  = 'Completed';
		$this->lookups[ $second ] = 'Completed';

		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( array( $second, $first ), $this->requested );
		$this->assertSame( $second, $order->get_meta( KhaltiGateway::META_PAID_PIDX ) );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Khalti took a second payment for this order' ) );
		$this->assertSame( 9, $this->stock() );

		KhaltiReconciler::run();
		$this->assertCount( 2, $this->requested, 'Nothing left to ask.' );
	}

	public function test_orders_without_khalti_payments_are_ignored(): void {
		$order = wc_create_order();
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( 'cod' );
		$order->calculate_totals();
		$order->set_date_created( time() - HOUR_IN_SECONDS );
		$order->save();
		KhaltiReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_batch_is_capped_and_later_runs_reach_the_rest(): void {
		for ( $i = 0; $i < KhaltiReconciler::BATCH_SIZE + 3; $i++ ) {
			$order                                   = $this->order( 15 + $i );
			$this->lookups[ $this->pidx( $order ) ] = 'Pending';
		}
		KhaltiReconciler::run();
		$this->assertCount( KhaltiReconciler::BATCH_SIZE, $this->requested );
		$first = $this->requested;

		KhaltiReconciler::run();
		$this->assertCount( KhaltiReconciler::BATCH_SIZE + 3, array_unique( array_merge( $first, array_slice( $this->requested, count( $first ) ) ) ), 'Every order checked within two runs.' );
	}

	public function test_unreachable_api_stops_the_run(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->order( 15 + $i );
		}
		KhaltiReconciler::run(); // No mock: every lookup fails.
		$this->assertCount( 1, $this->requested );
		$this->assertStringContainsString( 'Khalti lookup failed: No mock configured.', $this->logged() );
		$this->assertStringNotContainsString( self::TEST_KEY, $this->logged() );
	}

	public function test_rejected_key_stops_the_run(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$order = $this->order( 15 + $i );
			$this->lookups[ $this->pidx( $order ) ] = array(
				'status' => '',
				'code'   => 401,
				'raw'    => '{"detail":"Invalid token.","status_code":401}',
			);
		}
		KhaltiReconciler::run();
		$this->assertCount( 1, $this->requested, 'A rejected key fails every lookup: stop.' );
	}

	public function test_without_a_key_the_api_is_not_called(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY ) );
		$this->reload_payment_gateways();
		KhaltiReconciler::run();
		$this->assertSame( array(), $this->requested );
	}

	public function test_recurring_action_scheduled_once_and_removed_on_deactivation(): void {
		KhaltiReconciler::unschedule();
		KhaltiReconciler::schedule();
		KhaltiReconciler::schedule();
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => 'bsn_khalti_reconcile',
				'group'    => 'bsn',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		$this->assertCount( 1, $actions );
		$this->assertSame( 300, reset( $actions )->get_schedule()->get_recurrence() );
		$this->assertNotFalse( has_action( 'bsn_khalti_reconcile', array( KhaltiReconciler::class, 'run' ) ) );
		$this->assertNotFalse( has_action( 'init', array( KhaltiReconciler::class, 'maybe_schedule' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_order_object_save', array( KhaltiReconciler::class, 'flag_switched_order' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( BSN_PLUGIN_FILE ), array( KhaltiReconciler::class, 'unschedule' ) ) );
		KhaltiReconciler::unschedule();
		$this->assertFalse( as_next_scheduled_action( 'bsn_khalti_reconcile', array(), 'bsn' ) );
	}

	public function test_maybe_schedule_only_on_scheduling_requests(): void {
		KhaltiReconciler::unschedule();
		KhaltiReconciler::maybe_schedule();
		$this->assertFalse( as_next_scheduled_action( 'bsn_khalti_reconcile', array(), 'bsn' ), 'Front-end page views skip it.' );
		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			KhaltiReconciler::maybe_schedule();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
		$this->assertNotFalse( as_next_scheduled_action( 'bsn_khalti_reconcile', array(), 'bsn' ) );
	}

	// ---- WooCommerce's unpaid-order auto-cancel ------------------------------------------

	private function should_cancel( WC_Order $order, bool $cancel = true ): bool {
		return (bool) apply_filters( 'woocommerce_cancel_unpaid_order', $cancel, $order );
	}

	public function test_auto_cancel_completes_order_khalti_says_is_paid(): void {
		$order = $this->order( 90 );
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$this->assertFalse( $this->should_cancel( $order ) );
		$order = $this->fresh( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 9, $this->stock() );
	}

	public function test_auto_cancel_waits_when_khalti_cannot_say(): void {
		$order = $this->order( 90 );
		$notes = $this->note_texts( $order );
		$this->lookups[ $this->pidx( $order ) ] = new WP_Error( 'http_request_failed', 'Operation timed out' );
		$this->assertFalse( $this->should_cancel( $order ), 'API error: not cancelled now.' );
		$this->assert_untouched( $order, $notes );

		// Khalti is down for this request: other Khalti orders are not cancelled either, without more requests.
		$second = $this->order( 90 );
		$this->lookups[ $this->pidx( $second ) ] = 'Expired';
		$this->assertFalse( $this->should_cancel( $second ) );
		$this->assertCount( 1, $this->requested );

		KhaltiReconciler::reset_request_state();
		$this->lookups[ $this->pidx( $order ) ] = 'Pending';
		$this->assertFalse( $this->should_cancel( $order ), 'Pending: not cancelled now.' );
		$this->assert_untouched( $order, $notes );
	}

	public function test_auto_cancel_server_error_stops_further_requests(): void {
		$first = $this->order( 90 );
		$this->lookups[ $this->pidx( $first ) ] = array(
			'status' => 'Completed',
			'code'   => 503,
		);
		$this->assertFalse( $this->should_cancel( $first ) );
		$second = $this->order( 90 );
		$this->lookups[ $this->pidx( $second ) ] = 'Expired';
		$this->assertFalse( $this->should_cancel( $second ), '5xx: Khalti is down, nothing is cancelled this time.' );
		$this->assertCount( 1, $this->requested );
	}

	public function bad_answers(): array {
		return array(
			'HTTP 400 validation' => array(
				array(
					'status' => '',
					'code'   => 400,
					'raw'    => '{"detail":"Not found.","error_key":"validation_error"}',
				),
			),
			'HTTP 404'            => array(
				array(
					'status' => '',
					'code'   => 404,
					'raw'    => '{"detail":"Not found."}',
				),
			),
			'unreadable'          => array(
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
		$this->lookups[ $this->pidx( $bad ) ] = $answer;
		$this->assertFalse( $this->should_cancel( $bad ), 'That order is not cancelled now.' );
		$this->assert_untouched( $bad, $notes );

		$good = $this->order( 90 );
		$this->lookups[ $this->pidx( $good ) ] = 'Expired';
		$this->assertTrue( $this->should_cancel( $good ), 'The next order is still checked.' );
		$this->assertCount( 2, $this->requested );
	}

	public function test_auto_cancel_goes_ahead_when_khalti_has_no_payment(): void {
		foreach ( array( 'Expired', 'User canceled' ) as $status ) {
			$order = $this->order( 90 );
			$this->lookups[ $this->pidx( $order ) ] = $status;
			$this->assertTrue( $this->should_cancel( $order ), $status );
			$this->assertFalse( $this->should_cancel( $order, false ), 'Original value kept.' );
			$this->assertFalse( $this->fresh( $order )->is_paid() );
		}
	}

	public function test_auto_cancel_waits_when_the_current_mode_has_no_key(): void {
		// Paid in test mode; the owner turns test mode off before entering the live key.
		$order = $this->order( 90 );
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY, 'secret_key' => '' ) );
		$this->reload_payment_gateways();
		$this->assertFalse( $this->should_cancel( $order ), 'Nothing can be verified: never cancel (and restock) a possibly paid order.' );
		$this->assertSame( array(), $this->requested );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_pidx_started_in_the_other_mode_is_noted_once_and_left_to_woocommerce(): void {
		$order = $this->order( 90 ); // Started in test mode.
		$pidx  = $this->pidx( $order );
		$this->lookups[ $pidx ] = 'Completed';
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY, 'secret_key' => 'live_secret_key_fake_1111' ) );
		$this->reload_payment_gateways();

		KhaltiReconciler::run();
		KhaltiReconciler::run();

		$order = $this->fresh( $order );
		$this->assertSame( array(), $this->requested, 'A test-mode pidx is not looked up in live mode.' );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 1, $this->count_notes_containing( $order, 'Khalti payment started in test mode — check the sandbox dashboard' ) );
		$this->assertTrue( $this->should_cancel( $order ), 'Its only pidx cannot be checked: WooCommerce decides.' );
		$this->assertSame( array(), $this->requested );
	}

	public function test_pidx_of_the_current_mode_is_still_checked_beside_one_from_the_other_mode(): void {
		update_option( 'woocommerce_bsn_khalti_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'test_secret_key' => self::TEST_KEY, 'secret_key' => 'live_secret_key_fake_1111' ) );
		$this->reload_payment_gateways();
		$this->gateway = new KhaltiGateway();
		$order         = $this->order( 90 ); // Live pidx.
		$live          = $this->pidx( $order );
		$test          = 'TESTMODEpidx';
		$list          = $order->get_meta( KhaltiGateway::META_PIDXS );
		$order->update_meta_data(
			KhaltiGateway::META_PIDXS,
			array(
				$test => array(
					'amount' => '50000',
					'mode'   => 'test',
				),
			) + $list
		);
		$order->save();
		$this->lookups[ $live ] = 'Pending';
		$this->assertFalse( $this->should_cancel( $this->fresh( $order ) ), 'The live pidx is Pending: wait.' );
		$this->assertSame( array( $live ), $this->requested );
	}

	public function test_amount_mismatch_note_tells_staff_to_refund_or_reconcile(): void {
		$order = $this->order();
		$this->lookups[ $this->pidx( $order ) ] = array(
			'status' => 'Completed',
			'amount' => 10000,
		);
		KhaltiReconciler::run();
		$this->assertSame( 1, $this->count_notes_containing( $this->fresh( $order ), 'Check the payment in the Khalti merchant portal and refund or reconcile it.' ) );
	}

	public function test_auto_cancel_of_completed_with_wrong_amount_waits_for_staff(): void {
		$order = $this->order( 90 );
		$this->lookups[ $this->pidx( $order ) ] = array(
			'status' => 'Completed',
			'amount' => 10000,
		);
		$this->assertFalse( $this->should_cancel( $order ) );
		$this->assertFalse( $this->fresh( $order )->is_paid() );
	}

	public function test_auto_cancel_leaves_other_orders_alone_and_makes_no_request_when_not_cancelling(): void {
		$order = $this->order( 90 );
		$this->lookups[ $this->pidx( $order ) ] = 'Completed';
		$this->assertFalse( $this->should_cancel( $order, false ) );
		$this->assertSame( array(), $this->requested, 'The scheduled check will complete it.' );

		$cod = $this->switch_to_cod( $this->order( 90 ) );
		$this->assertTrue( $this->should_cancel( $cod ) );
		$this->assertSame( array(), $this->requested );
	}

	public function test_woocommerce_unpaid_order_cron_does_not_cancel_paid_khalti_order(): void {
		update_option( 'woocommerce_hold_stock_minutes', '60' );
		$paid   = $this->order( 90 );
		$unpaid = $this->order( 90 );
		$this->lookups[ $this->pidx( $paid ) ]   = 'Completed';
		$this->lookups[ $this->pidx( $unpaid ) ] = 'Expired';
		$this->age_last_update( $paid, 90 );
		$this->age_last_update( $unpaid, 90 );

		wc_cancel_unpaid_orders();

		$this->assertSame( 'processing', $this->fresh( $paid )->get_status() );
		$this->assertSame( 'cancelled', $this->fresh( $unpaid )->get_status() );
	}

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
