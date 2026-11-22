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
	 * Adds the bulk actions to the orders screen. WooCommerce itself applies any mark_<status> action
	 * whose status is registered, and shows its own confirmation notice.
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
}
