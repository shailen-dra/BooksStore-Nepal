<?php
/**
 * Custom order statuses: Packed and Shipped.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Adds wc-packed and wc-shipped, relabels Completed as Delivered, and adds bulk actions.
 */
class OrderStatuses {

	/**
	 * Status slug (without wc- prefix) => label.
	 *
	 * @return array<string,string>
	 */
	private static function custom(): array {
		return array(
			'packed'  => _x( 'Packed', 'order status', 'bookstore-nepal' ),
			'shipped' => _x( 'Shipped', 'order status', 'bookstore-nepal' ),
		);
	}

	/**
	 * Hooks runtime behaviour.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_statuses' ) );
		add_filter( 'wc_order_statuses', array( self::class, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( self::class, 'paid_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( self::class, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( self::class, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( self::class, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'bulk_notice' ) );
	}

	/**
	 * Registers the statuses with the post-type order store.
	 */
	public static function register_post_statuses(): void {
		foreach ( self::custom() as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders. */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'bookstore-nepal' ), // phpcs:ignore WordPress.WP.I18n
				)
			);
		}
	}

	/**
	 * Inserts Packed and Shipped after Processing and relabels Completed.
	 *
	 * @param array $statuses Status key => label.
	 * @return array
	 */
	public static function order_statuses( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();
		$out      = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = 'wc-completed' === $key ? __( 'Delivered', 'bookstore-nepal' ) : $label;
			if ( 'wc-processing' === $key ) {
				foreach ( self::custom() as $slug => $custom_label ) {
					$out[ 'wc-' . $slug ] = $custom_label;
				}
			}
		}
		foreach ( self::custom() as $slug => $custom_label ) {
			if ( ! isset( $out[ 'wc-' . $slug ] ) ) {
				$out[ 'wc-' . $slug ] = $custom_label;
			}
		}
		return $out;
	}

	/**
	 * Packed and shipped orders are already paid or confirmed, so count them in reports.
	 *
	 * @param array $statuses Paid statuses.
	 * @return array
	 */
	public static function paid_statuses( $statuses ) {
		return array_values( array_unique( array_merge( (array) $statuses, array_keys( self::custom() ) ) ) );
	}

	/**
	 * Adds the bulk actions to the orders screen.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions                 = is_array( $actions ) ? $actions : array();
		$actions['mark_packed']  = __( 'Change status to packed', 'bookstore-nepal' );
		$actions['mark_shipped'] = __( 'Change status to shipped', 'bookstore-nepal' );
		return $actions;
	}

	/**
	 * Applies the bulk status change.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Chosen bulk action.
	 * @param array  $ids      Selected order ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'mark_packed', 'mark_shipped' ), true ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$status  = 'mark_packed' === $action ? 'packed' : 'shipped';
		$changed = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( absint( $id ) );
			if ( $order instanceof \WC_Order ) {
				$order->update_status( $status, __( 'Order status changed by bulk edit.', 'bookstore-nepal' ), true );
				++$changed;
			}
		}
		return add_query_arg( 'bsn_changed', $changed, $redirect );
	}

	/**
	 * Confirms a bulk change.
	 */
	public static function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['bsn_changed'] ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$count = absint( wp_unslash( $_GET['bsn_changed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			/* translators: %d: number of orders. */
			sprintf( _n( '%d order status changed.', '%d order statuses changed.', $count, 'bookstore-nepal' ), $count )
		) . '</p></div>';
	}
}
