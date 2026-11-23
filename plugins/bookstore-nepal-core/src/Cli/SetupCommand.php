<?php
/**
 * `wp bsn setup` command.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Cli;

use BookstoreNepal\Catalog;
use BookstoreNepal\ShopContact;
use BookstoreNepal\Shipping;
use BookstoreNepal\StorePages;

defined( 'ABSPATH' ) || exit;

/**
 * Configures the store for Nepal; safe to run repeatedly.
 *
 * Settings the owner may change in wp-admin (cash on delivery, stock alerts, guest checkout,
 * decimals, phone field, countries) are applied on the first run only; see DONE_OPTION.
 */
class SetupCommand {

	public const TRACKING_PAGE_OPTION = 'bsn_tracking_page_id';

	/** Set once the store defaults have been applied; delete it to apply them again. */
	public const DONE_OPTION = 'bsn_setup_done';

	/**
	 * Whether the last run skipped the store defaults because setup had already run.
	 *
	 * @var bool
	 */
	private bool $defaults_skipped = false;

	/**
	 * What the last run did with each store information page (key => outcome).
	 *
	 * @var array<string,string>
	 */
	private array $page_report = array();

	/**
	 * Wording boxes the last run filled, or null when ACF Pro was not active.
	 *
	 * @var int|null
	 */
	private ?int $wording_filled = null;

	/**
	 * Registers the command when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bsn setup', self::class );
		}
	}

	/**
	 * Applies store settings, catalogue attributes, shipping zones, COD, the tracking page and the store information pages.
	 *
	 * Store defaults the owner can change in wp-admin (cash on delivery, low-stock alerts, guest
	 * checkout, price decimals, required phone, selling and shipping to Nepal only) are applied on
	 * the first run only. To apply them again: `wp option delete bsn_setup_done`, then re-run.
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
	 * [--phone=<number>]
	 * : Shop phone number shown on the Contact page and in the footer. Set only when given; an empty value clears it.
	 *
	 * [--whatsapp=<number>]
	 * : WhatsApp number: a Nepali mobile, e.g. 9812345678 or 9779812345678. Set only when given.
	 *
	 * [--email=<address>]
	 * : Contact email shown to customers. Set only when given; an empty value clears it (the footer then shows a placeholder).
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Flags.
	 */
	public function __invoke( $args, $assoc_args ) {
		$errors = $this->run( (array) $assoc_args );
		if ( $this->defaults_skipped ) {
			\WP_CLI::log( 'Store defaults (cash on delivery, stock alerts, guest checkout, decimals, phone, countries) were not changed because setup already ran. To re-apply them: wp option delete ' . self::DONE_OPTION . ' && wp bsn setup' );
		}
		foreach ( $this->page_report as $key => $outcome ) {
			\WP_CLI::log( sprintf( 'Page %s: %s', $key, $outcome ) );
		}
		if ( null === $this->wording_filled ) {
			\WP_CLI::warning( 'ACF Pro is not active, so the wording fields were not filled. Install and activate ACF Pro, then run wp bsn setup again.' );
		} else {
			\WP_CLI::log( sprintf( 'Wording fields: %d filled', $this->wording_filled ) );
			foreach ( \BookstoreNepal\Content\Prefill::detached_pages() as $key ) {
				\WP_CLI::log( sprintf( 'Page %s: rewritten in the block editor, so it keeps its own blocks and the block editor; its wording boxes are hidden. Check the page.', $key ) );
			}
		}
		if ( $this->page_report && ! StorePages::pattern_available() ) {
			\WP_CLI::warning( 'The Bookstore Nepal theme is not active, so the store information pages got a short placeholder paragraph instead of their text. Activate the theme, then delete those pages (or their stored IDs) and run setup again, or write the text in the page editor.' );
		}
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
	 * @param array $assoc_args Flags: valley, rest, free-over, phone, whatsapp, email.
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
			if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d+\z/', $value ) ) ) {
				$errors[] = sprintf( '--%s must be a non-negative whole number of NPR.', $flag );
				continue;
			}
			$rates[ $key ] = (int) $value;
		}
		$contact = array();
		foreach ( array( 'phone' => array( ShopContact::PHONE, 'sanitize_phone' ), 'whatsapp' => array( ShopContact::WHATSAPP, 'sanitize_whatsapp' ), 'email' => array( ShopContact::EMAIL, 'sanitize_email' ) ) as $flag => $map ) {
			if ( ! array_key_exists( $flag, $assoc_args ) ) {
				continue;
			}
			$raw   = is_scalar( $assoc_args[ $flag ] ) ? trim( (string) $assoc_args[ $flag ] ) : '';
			$clean = ShopContact::{$map[1]}( $raw );
			if ( '' !== $raw && '' === $clean ) {
				$errors[] = sprintf( '--%s is not a valid value.', $flag );
				continue;
			}
			$contact[ $map[0] ] = $clean;
		}
		if ( $errors ) {
			return $errors;
		}
		foreach ( $contact as $option => $value ) {
			update_option( $option, $value );
		}

		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_default_country', 'NP:NP-D-KATHMANDU' );
		update_option( 'woocommerce_manage_stock', 'yes' );

		$this->defaults_skipped = (bool) get_option( self::DONE_OPTION );
		if ( ! $this->defaults_skipped ) {
			$this->apply_store_defaults();
			update_option( self::DONE_OPTION, gmdate( 'c' ), false );
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
		}

		$this->ensure_tracking_page();
		// Structural like the tracking page, so it runs every time; it never touches a page the owner has published or edited.
		$this->page_report = StorePages::ensure_all();
		// Fill the wording boxes now that the pages exist (only with ACF Pro active; null means it was not).
		$this->wording_filled = \BookstoreNepal\Content\Content::acf_active() ? \BookstoreNepal\Content\Prefill::run() : null;

		$errors = array_merge( Catalog::install(), Shipping::seed( $rates ) );

		// Rules built in this request would miss post types registered while permalinks were plain and the
		// attribute taxonomies created above, so let WordPress rebuild them on the next request.
		delete_option( 'rewrite_rules' );

		return $errors;
	}

	/**
	 * Boxes the last run() filled with default wording, or null when ACF Pro was not active.
	 */
	public function wording_filled(): ?int {
		return $this->wording_filled;
	}

	/**
	 * Whether the last run() skipped the store defaults because setup had already run.
	 */
	public function defaults_skipped(): bool {
		return $this->defaults_skipped;
	}

	/**
	 * First-run defaults the owner may later change in wp-admin.
	 */
	private function apply_store_defaults(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_notify_low_stock_amount', 3 );
		update_option( 'woocommerce_notify_no_stock_amount', 0 );
		update_option( 'woocommerce_stock_email_recipient', (string) get_option( 'admin_email' ) );
		update_option( 'woocommerce_price_num_decimals', 0 );
		// Riders and couriers phone the customer; the block checkout reads this option too.
		update_option( 'woocommerce_checkout_phone_field', 'required' );
		// Delivery exists only inside Nepal, so customers cannot pick another country.
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'NP' ) );
		update_option( 'woocommerce_ship_to_countries', 'specific' );
		update_option( 'woocommerce_specific_ship_to_countries', array( 'NP' ) );
		$this->enable_cod();
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
