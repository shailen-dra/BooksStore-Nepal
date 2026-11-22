<?php
/**
 * Order lock and fresh re-read shared by the payment gateways.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Every decision that pays, fails or notes an order is taken while holding a MySQL named lock
 * for that order, on a copy re-read from the database, so two requests (a double return, the
 * return and the scheduled check) never both act on the same stale state.
 */
trait LocksOrders {

	/**
	 * Loads an order bypassing request-local caches: WooCommerce's order object cache
	 * (HPOS), the HPOS datastore cache, and the post/meta caches (CPT store). Without
	 * this, a request that waited for the lock would see the order as it was before
	 * the other request paid it, and pay or fail it a second time.
	 *
	 * @param int $order_id Order ID.
	 */
	private function reload_order( int $order_id ): ?WC_Order {
		if ( class_exists( OrderCache::class ) ) {
			wc_get_container()->get( OrderCache::class )->remove( $order_id );
		}
		if ( OrderUtil::custom_orders_table_usage_is_enabled() && class_exists( OrdersTableDataStore::class ) ) {
			wc_get_container()->get( OrdersTableDataStore::class )->clear_cached_data( array( $order_id ) );
		}
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Takes the order's lock. One name per order, shared by every gateway, so a Khalti return
	 * and an eSewa callback (or reconciliation) for the same order never run side by side.
	 * MySQL named locks are re-entrant per connection, so nesting in one request is safe.
	 *
	 * @param int $order_id Order ID.
	 * @param int $timeout  Seconds to wait.
	 * @return string|null Lock name when held.
	 */
	private function lock_order( int $order_id, int $timeout ): ?string {
		return $this->acquire_lock( 'bsn_order_' . $order_id, $timeout );
	}

	/**
	 * Takes a MySQL named lock.
	 *
	 * @param string $name    Lock name (e.g. "bsn_order_123").
	 * @param int    $timeout Seconds to wait.
	 * @return string|null Lock name when held.
	 */
	private function acquire_lock( string $name, int $timeout ): ?string {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $got ? $name : null;
	}

	/**
	 * Releases a lock taken by acquire_lock().
	 *
	 * @param string $name Lock name.
	 */
	private function unlock( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
