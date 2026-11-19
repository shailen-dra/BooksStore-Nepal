<?php
class OrderStatusesTest extends WP_UnitTestCase {

	public function test_custom_order_statuses(): void {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		$order->update_status( 'packed' );
		$this->assertSame( 'packed', wc_get_order( $order->get_id() )->get_status() );
		$order->update_status( 'shipped' );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_status() );
		$order->update_status( 'completed' );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );

		$statuses = wc_get_order_statuses();
		$this->assertSame( 'Delivered', $statuses['wc-completed'] );
		$this->assertSame( 'Packed', $statuses['wc-packed'] );
		$this->assertSame( 'Shipped', $statuses['wc-shipped'] );
		$keys = array_keys( $statuses );
		$this->assertSame( array_search( 'wc-processing', $keys, true ) + 1, array_search( 'wc-packed', $keys, true ) );
		$this->assertSame( array_search( 'wc-packed', $keys, true ) + 1, array_search( 'wc-shipped', $keys, true ) );
	}

	public function test_post_statuses_registered_for_legacy_store(): void {
		$this->assertNotNull( get_post_status_object( 'wc-packed' ) );
		$this->assertNotNull( get_post_status_object( 'wc-shipped' ) );
	}

	public function test_counted_as_paid_for_reports(): void {
		$paid = wc_get_is_paid_statuses();
		$this->assertContains( 'packed', $paid );
		$this->assertContains( 'shipped', $paid );
	}

	public function test_bulk_actions_on_both_order_screens(): void {
		foreach ( array( 'bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders' ) as $hook ) {
			$actions = apply_filters( $hook, array( 'trash' => 'Move to Trash' ) );
			$this->assertArrayHasKey( 'mark_packed', $actions, $hook );
			$this->assertArrayHasKey( 'mark_shipped', $actions, $hook );
			$this->assertSame( 'Change status to packed', $actions['mark_packed'] );
			$this->assertSame( 'Change status to shipped', $actions['mark_shipped'] );
		}
	}

	public function test_bulk_handler_updates_orders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		$redirect = apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'admin.php?page=wc-orders', 'mark_packed', array( $order->get_id() ) );
		$this->assertSame( 'packed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( 'bsn_changed=1', $redirect );

		apply_filters( 'handle_bulk_actions-edit-shop_order', 'edit.php', 'mark_shipped', array( $order->get_id() ) );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_status() );

		$untouched = apply_filters( 'handle_bulk_actions-edit-shop_order', 'edit.php', 'trash', array( $order->get_id() ) );
		$this->assertSame( 'edit.php', $untouched );
	}

	public function test_bulk_handler_ignores_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();
		apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'x', 'mark_packed', array( $order->get_id() ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}
}
