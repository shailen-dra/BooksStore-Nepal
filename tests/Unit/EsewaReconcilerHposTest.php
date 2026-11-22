<?php
require_once __DIR__ . '/EsewaReconcilerTest.php';

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Runs every eSewa reconciliation test again with HPOS (custom order tables) as the order store.
 */
class EsewaReconcilerHposTest extends EsewaReconcilerTest {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS active.' );
		$this->assertTrue( OrderUtil::orders_cache_usage_is_enabled(), 'Order cache active.' );
	}
}
