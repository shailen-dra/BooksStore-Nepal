<?php
/**
 * Plugin Name:       Bookstore Nepal Core
 * Description:       Catalogue attributes, Nepal shipping, eSewa and Khalti gateways, gift finder and store setup for Bookstore Nepal.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Text Domain:       bookstore-nepal
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;

define( 'BSN_VERSION', '0.1.0' );
define( 'BSN_PLUGIN_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BookstoreNepal\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = __DIR__ . '/src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boots the plugin once WooCommerce has loaded.
 */
function bsn_boot(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	do_action( 'bsn_booted' );
}
add_action( 'plugins_loaded', 'bsn_boot', 20 );

register_activation_hook( BSN_PLUGIN_FILE, array( \BookstoreNepal\Catalog::class, 'install' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\Catalog::class, 'register' ) );
// A closure: WordPress passes $network_wide to activation hooks, which seed() must not receive.
register_activation_hook(
	BSN_PLUGIN_FILE,
	static function (): void {
		\BookstoreNepal\Shipping::seed();
	}
);
add_action( 'bsn_booted', array( \BookstoreNepal\Shipping::class, 'register' ) );
add_action( 'before_woocommerce_init', array( \BookstoreNepal\Gateway\EsewaGateway::class, 'declare_compatibility' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\Gateway\EsewaGateway::class, 'register' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\Gateway\EsewaReconciler::class, 'register' ) );
// EsewaGateway::declare_compatibility() above declares HPOS and block checkout support for the whole plugin, Khalti included.
add_action( 'bsn_booted', array( \BookstoreNepal\Gateway\KhaltiGateway::class, 'register' ) );
register_deactivation_hook( BSN_PLUGIN_FILE, array( \BookstoreNepal\Gateway\EsewaReconciler::class, 'unschedule' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\OrderStatuses::class, 'register' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\Cli\SetupCommand::class, 'register' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\GiftFinder::class, 'register' ) );
add_action( 'bsn_booted', array( \BookstoreNepal\ShopContact::class, 'register' ) );
