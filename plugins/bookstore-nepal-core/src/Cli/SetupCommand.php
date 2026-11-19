<?php
/**
 * `wp bsn setup` command.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Cli;

use BookstoreNepal\Catalog;
use BookstoreNepal\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Configures the store for Nepal; safe to run repeatedly.
 */
class SetupCommand {

	public const TRACKING_PAGE_OPTION = 'bsn_tracking_page_id';

	/**
	 * Registers the command when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bsn setup', self::class );
		}
	}

	/**
	 * Applies store settings, catalogue attributes, shipping zones, COD and the tracking page.
	 *
	 * Existing shipping rates are left alone unless a rate flag is given.
	 *
	 * ## OPTIONS
	 *
	 * [--valley=<npr>]
	 * : Delivery rate for Kathmandu Valley in NPR.
	 *
	 * [--rest=<npr>]
	 * : Delivery rate for the rest of Nepal in NPR.
	 *
	 * [--free-over=<npr>]
	 * : Order total above which delivery is free, in NPR.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Flags.
	 */
	public function __invoke( $args, $assoc_args ) {
		$errors = $this->run( (array) $assoc_args );
		foreach ( $errors as $error ) {
			\WP_CLI::warning( $error );
		}
		if ( $errors ) {
			\WP_CLI::error( sprintf( 'Setup finished with %d problem(s).', count( $errors ) ) );
		}
		\WP_CLI::success( 'Store configured.' );
	}

	/**
	 * Does the work; testable without WP-CLI.
	 *
	 * @param array $assoc_args Flags: valley, rest, free-over.
	 * @return string[] Error messages; empty means success.
	 */
	public function run( array $assoc_args ): array {
		$rates = array();
		$errors = array();
		foreach ( array( 'valley' => 'valley', 'rest' => 'rest', 'free-over' => 'free_over' ) as $flag => $key ) {
			if ( ! array_key_exists( $flag, $assoc_args ) ) {
				continue;
			}
			$value = $assoc_args[ $flag ];
			if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) ) {
				$errors[] = sprintf( '--%s must be a non-negative whole number of NPR.', $flag );
				continue;
			}
			$rates[ $key ] = (int) $value;
		}
		if ( $errors ) {
			return $errors;
		}

		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_default_country', 'NP:NP-D-KATHMANDU' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_notify_low_stock_amount', 3 );
		update_option( 'woocommerce_notify_no_stock_amount', 0 );
		update_option( 'woocommerce_price_num_decimals', 0 );

		$this->enable_cod();
		$this->ensure_tracking_page();

		return array_merge( Catalog::install(), Shipping::seed( $rates ) );
	}

	/**
	 * Enables cash on delivery for every shipping method.
	 */
	private function enable_cod(): void {
		$settings = (array) get_option( 'woocommerce_cod_settings', array() );
		update_option(
			'woocommerce_cod_settings',
			array_merge(
				$settings,
				array(
					'enabled'            => 'yes',
					'title'              => 'Cash on delivery',
					'enable_for_methods' => array(),
					'enable_for_virtual' => 'no',
				)
			)
		);
	}

	/**
	 * Creates the tracking page once; replaces it if it was deleted or trashed.
	 */
	private function ensure_tracking_page(): void {
		$id = (int) get_option( self::TRACKING_PAGE_OPTION );
		if ( $id && 'page' === get_post_type( $id ) && ! in_array( get_post_status( $id ), array( 'trash', false ), true ) ) {
			return;
		}
		$new = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Track your order',
				'post_content' => '[woocommerce_order_tracking]',
			)
		);
		if ( $new && ! is_wp_error( $new ) ) {
			update_option( self::TRACKING_PAGE_OPTION, (int) $new );
		}
	}
}
