<?php
/**
 * eSewa payment reconciliation.
 *
 * A customer who pays on eSewa but never comes back to the site leaves the order pending.
 * Every 5 minutes (Action Scheduler) this asks eSewa's status API about recent unpaid eSewa
 * orders and completes those eSewa reports paid, through the gateway's locked completion path.
 * It also notes eSewa payments for orders since switched to another payment method, and second
 * payments on orders already paid.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled status checks for eSewa orders.
 */
class EsewaReconciler {

	public const HOOK     = 'bsn_esewa_reconcile';
	public const GROUP    = 'bsn';
	public const INTERVAL = 300;
	// Leave the customer time to finish on eSewa and come back by themselves.
	public const MIN_AGE = 600;
	// After this, staff check the eSewa merchant portal by hand.
	public const MAX_AGE = 172800;
	// Orders checked per run. Orders paid by another method, and paid eSewa orders with more
	// than one transaction, take up to 5 each; unpaid eSewa orders the rest.
	public const BATCH_SIZE        = 20;
	public const STRAY_BATCH_SIZE  = 5;
	public const DOUBLE_BATCH_SIZE = 5;
	// Seconds after which a run stops starting new checks (the status API timeout is 8s each).
	public const TIME_BUDGET = 20;
	// Where each query's page offset is kept, so successive runs go round all candidates.
	public const OPTION_OFFSETS = 'bsn_esewa_reconcile_offsets';

	/** @var bool The status API failed during this request: stop calling it until the next run. */
	private static $api_unavailable = false;

	/**
	 * Hooks the scheduled check.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'flag_switched_order' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'filter_cancel_unpaid_order' ), 10, 2 );
	}

	/**
	 * Guards WooCommerce's auto-cancel of unpaid orders (which also restocks): before an eSewa
	 * order is cancelled, ask eSewa. Paid → complete it instead; eSewa undecided, unreachable or
	 * paid for a different amount → don't cancel now (the scheduled check decides later); no
	 * payment → WooCommerce's decision stands. Without live credentials nothing is cancelled.
	 *
	 * @param mixed $cancel Whether WooCommerce will cancel the order.
	 * @param mixed $order  The unpaid order.
	 * @return mixed
	 */
	public static function filter_cancel_unpaid_order( $cancel, $order ) {
		// Not being cancelled anyway: no request needed, the scheduled check covers it.
		if ( ! $cancel || ! $order instanceof WC_Order || EsewaGateway::ID !== $order->get_payment_method() ) {
			return $cancel;
		}
		$gateway = EsewaGateway::instance();
		if ( ! $gateway->has_credentials() ) {
			// Live mode without keys: a paid order cannot be recognised, so cancelling (and
			// restocking) could cancel one. Wait until the keys are entered.
			return false;
		}
		if ( self::$api_unavailable ) {
			return false;
		}
		$result = $gateway->reconcile( $order->get_id() );
		if ( $result['api_down'] ) {
			self::$api_unavailable = true;
		}
		$keep = array(
			EsewaGateway::RECONCILE_PAID,
			EsewaGateway::RECONCILE_ALREADY_PAID,
			EsewaGateway::RECONCILE_RETRY,
			EsewaGateway::RECONCILE_MISMATCH,
		);
		if ( in_array( $result['outcome'], $keep, true ) ) {
			return false;
		}
		// A live payment attempt cannot be checked while the shop is in test mode: hold the order
		// for staff (reconcile() has added the note saying to check the live merchant portal).
		return $gateway->holds_unverifiable_payment( $order ) ? false : $cancel;
	}

	/**
	 * Makes sure the recurring check is scheduled. Hooked on init; skipped on front-end page
	 * views to save the lookup there (admin, cron, Action Scheduler and WP-CLI requests do it).
	 */
	public static function maybe_schedule(): void {
		if ( self::is_scheduling_request( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::schedule();
		}
	}

	/**
	 * Whether this request should make sure the check is scheduled: wp-admin (including Action
	 * Scheduler's async runner), WP-Cron or WP-CLI.
	 *
	 * @param bool $is_cli Whether this is a WP-CLI request.
	 */
	public static function is_scheduling_request( bool $is_cli ): bool {
		return is_admin() || wp_doing_cron() || $is_cli;
	}

	/**
	 * Schedules the recurring check unless it already is.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), self::GROUP, true );
	}

	/**
	 * Removes the recurring check. Runs on plugin deactivation.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Forgets that the status API failed earlier in this request.
	 */
	public static function reset_request_state(): void {
		self::$api_unavailable = false;
	}

	/**
	 * Flags an order switched from eSewa to another method after a transaction was started, so
	 * the stray-payment check can find it with one cheap query; unflags it if switched back.
	 * Hooked on woocommerce_before_order_object_save, so the flag is saved with the change.
	 *
	 * @param mixed $order Order being saved.
	 */
	public static function flag_switched_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! array_key_exists( 'payment_method', $order->get_changes() ) ) {
			return;
		}
		if ( EsewaGateway::ID === $order->get_payment_method() ) {
			$order->delete_meta_data( EsewaGateway::META_STRAY_CHECK );
		} elseif ( $order->get_meta( EsewaGateway::META_UUIDS ) || '' !== (string) $order->get_meta( EsewaGateway::META_UUID ) ) {
			$order->update_meta_data( EsewaGateway::META_STRAY_CHECK, '1' );
		}
	}

	/**
	 * The scheduled check, for orders created between 10 minutes and 48 hours ago, at most
	 * BATCH_SIZE per run: orders switched to another method (stray payments), paid eSewa orders
	 * with more than one transaction (second payments), then unpaid eSewa orders.
	 */
	public static function run(): void {
		self::reset_request_state();
		$gateway = EsewaGateway::instance();
		if ( '' === $gateway->product_code() ) {
			return; // Live mode without credentials: nothing can be verified.
		}
		/**
		 * Seconds after which a reconciliation run stops starting new status checks.
		 *
		 * @param int $seconds Seconds.
		 */
		$deadline = microtime( true ) + max( 1, (int) apply_filters( 'bsn_esewa_reconcile_time_budget', self::TIME_BUDGET ) );
		$offsets  = get_option( self::OPTION_OFFSETS, array() );
		$offsets  = is_array( $offsets ) ? $offsets : array();

		$strays = self::process(
			$gateway,
			'stray',
			self::has_meta_args( EsewaGateway::META_STRAY_CHECK ),
			self::STRAY_BATCH_SIZE,
			$offsets,
			$deadline
		);
		$doubles = self::process(
			$gateway,
			'double',
			array_merge(
				self::has_meta_args( EsewaGateway::META_MULTIPLE ),
				array(
					'payment_method' => EsewaGateway::ID,
					'status'         => wc_get_is_paid_statuses(),
				)
			),
			self::DOUBLE_BATCH_SIZE,
			$offsets,
			$deadline
		);
		self::process(
			$gateway,
			'esewa',
			array(
				'payment_method' => EsewaGateway::ID,
				'status'         => array( 'pending', 'failed' ),
			),
			self::BATCH_SIZE - $strays - $doubles,
			$offsets,
			$deadline
		);
		update_option( self::OPTION_OFFSETS, $offsets, false );
	}

	/**
	 * Checks one page of candidate orders and moves that query's offset on.
	 *
	 * @param EsewaGateway $gateway  Gateway.
	 * @param string       $key      Offset key.
	 * @param array        $args     wc_get_orders() arguments selecting the candidates.
	 * @param int          $limit    Orders to check at most.
	 * @param array        $offsets  Offsets per key (updated).
	 * @param float        $deadline microtime() after which no new checks start.
	 * @return int Orders checked.
	 */
	private static function process( EsewaGateway $gateway, string $key, array $args, int $limit, array &$offsets, float $deadline ): int {
		if ( $limit < 1 ) {
			return 0;
		}
		$offset = max( 0, (int) ( $offsets[ $key ] ?? 0 ) );
		$ids    = self::candidates( $args, $limit, $offset );
		if ( ! $ids && $offset > 0 ) {
			$offset = 0; // Went past the end (orders left the window): start again.
			$ids    = self::candidates( $args, $limit, $offset );
		}
		$checked = 0;
		$kept    = 0; // Checked orders still matching the query, i.e. still in front of the next page.
		foreach ( $ids as $id ) {
			if ( self::$api_unavailable || microtime( true ) > $deadline ) {
				break;
			}
			$result = $gateway->reconcile( (int) $id );
			++$checked;
			if ( $result['api_down'] ) {
				self::$api_unavailable = true;
			}
			if ( 'esewa' !== $key || in_array( $result['outcome'], array( EsewaGateway::RECONCILE_RETRY, EsewaGateway::RECONCILE_UNPAID, EsewaGateway::RECONCILE_MISMATCH ), true ) ) {
				++$kept;
			}
		}
		$reached_end      = $checked === count( $ids ) && count( $ids ) < $limit;
		$offsets[ $key ] = $reached_end ? 0 : $offset + $kept;
		return $checked;
	}

	/**
	 * wc_get_orders() arguments for "has this meta key", in the form each order store accepts.
	 * The key is set on few orders, so either store answers from its meta_key index.
	 *
	 * @param string $key Meta key.
	 * @return array
	 */
	private static function has_meta_args( string $key ): array {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
				),
			);
		}
		// The posts store passes these through to WP_Query (it rejects meta_query).
		return array(
			'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
		);
	}

	/**
	 * Candidate order IDs, oldest first.
	 *
	 * @param array $args   wc_get_orders() arguments.
	 * @param int   $limit  Page size.
	 * @param int   $offset Page offset.
	 * @return int[]
	 */
	private static function candidates( array $args, int $limit, int $offset ): array {
		$now = time();
		$ids = wc_get_orders(
			array_merge(
				$args,
				array(
					'date_created' => ( $now - self::MAX_AGE ) . '...' . ( $now - self::MIN_AGE ),
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'limit'        => $limit,
					'offset'       => $offset,
					'return'       => 'ids',
				)
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
