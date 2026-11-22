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

	public function test_woocommerce_applies_bulk_action_for_custom_statuses(): void {
		require_once WC_ABSPATH . 'includes/admin/list-tables/abstract-class-wc-admin-list-table.php';
		require_once WC_ABSPATH . 'includes/admin/list-tables/class-wc-admin-list-table-orders.php';
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		// The legacy orders screen runs this core handler on handle_bulk_actions-edit-shop_order.
		$table    = new WC_Admin_List_Table_Orders();
		$redirect = $table->handle_bulk_actions( 'edit.php', 'mark_packed', array( $order->get_id() ) );
		$this->assertSame( 'packed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( 'bulk_action=marked_packed', $redirect );
		$this->assertStringContainsString( 'changed=1', $redirect );

		$table->handle_bulk_actions( 'edit.php', 'mark_shipped', array( $order->get_id() ) );
		$this->assertSame( 'shipped', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'internal', 'search' => 'Shipped' ) ) );
	}
}
