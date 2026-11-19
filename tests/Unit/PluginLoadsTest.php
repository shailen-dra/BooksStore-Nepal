<?php
/**
 * @package BookstoreNepal
 */

class PluginLoadsTest extends WP_UnitTestCase {

	public function test_plugin_boots_after_woocommerce(): void {
		$this->assertTrue( defined( 'BSN_VERSION' ) );
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', 'bsn_boot' ) );
	}
}
