<?php
/**
 * PHPUnit bootstrap: WordPress test suite + WooCommerce + our plugin.
 *
 * Env overrides: WP_TESTS_DIR (default: <repo>/.wp-tests-lib), WC_PLUGIN_DIR.
 *
 * @package BookstoreNepal
 */

$bsn_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $bsn_tests_dir ) {
	// Durable, git-ignored default (see bin/install-wp-tests.sh); the system temp dir is purged on reboot.
	$bsn_tests_dir = dirname( __DIR__ ) . '/.wp-tests-lib';
}

$bsn_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $bsn_polyfills );
}

if ( ! file_exists( $bsn_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$bsn_tests_dir}/includes/functions.php. Run bin/install-wp-tests.sh or set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $bsn_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$wc_dir = getenv( 'WC_PLUGIN_DIR' );
		if ( ! $wc_dir ) {
			$wc_dir = getenv( 'HOME' ) . '/Herd/bookstore-nepal/wp-content/plugins/woocommerce';
		}
		$wc_file = rtrim( $wc_dir, '/' ) . '/woocommerce.php';
		if ( ! file_exists( $wc_file ) ) {
			echo "WooCommerce not found at {$wc_file}. Set WC_PLUGIN_DIR.\n";
			exit( 1 );
		}
		require $wc_file;
		require dirname( __DIR__ ) . '/plugins/bookstore-nepal-core/bookstore-nepal-core.php';
	}
);

// Create WooCommerce tables once the test DB is ready.
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( ! class_exists( 'WC_Install' ) ) {
			return;
		}
		WC_Install::install();
		// HPOS tables too, so tests can run against either order store.
		wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

// ACF Pro is not installed in tests; these stand-ins behave like its storage.
require __DIR__ . '/Support/acf-stubs.php';

require $bsn_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/RestoresGlobalState.php';
