<?php
/**
 * Puts back global state that WP_UnitTestCase does not reset for plugin tests.
 *
 * @package BookstoreNepal
 */

/**
 * The DB rolls back after each test, but registered taxonomies, the rewrite object's permalink
 * structure and shortcodes do not. Call the save method in set_up and the restore method in tear_down.
 */
trait RestoresGlobalState {

	/** @var array<string,WP_Taxonomy> Attribute taxonomies registered before the test. */
	private $saved_attribute_taxonomies = array();

	/** @var string|null Permalink structure before the test. */
	private $saved_permalink_structure = null;

	/** @var array<string,callable>|null Registered shortcodes before the test. */
	private $saved_shortcodes = null;

	/**
	 * Unregisters the book attribute taxonomies so Catalog::install() can create them from scratch
	 * (a registered taxonomy without a DB row makes wc_create_attribute() fail), remembering them.
	 */
	protected function save_attribute_taxonomies(): void {
		$this->saved_attribute_taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			if ( 0 === strpos( $name, 'pa_' ) ) {
				$this->saved_attribute_taxonomies[ $name ] = $taxonomy;
				unregister_taxonomy( $name );
			}
		}
		$this->forget_attribute_cache();
	}

	/**
	 * Drops attribute taxonomies the test registered and re-registers the remembered ones.
	 */
	protected function restore_attribute_taxonomies(): void {
		global $wp_taxonomies;
		foreach ( array_keys( get_taxonomies() ) as $name ) {
			if ( 0 === strpos( $name, 'pa_' ) ) {
				unregister_taxonomy( $name );
			}
		}
		foreach ( $this->saved_attribute_taxonomies as $name => $taxonomy ) {
			// The same steps register_taxonomy() takes, reusing the original object.
			$taxonomy->add_rewrite_rules();
			$wp_taxonomies[ $name ] = $taxonomy; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$taxonomy->add_hooks();
		}
		$this->saved_attribute_taxonomies = array();
		$this->forget_attribute_cache();
	}

	/**
	 * WooCommerce caches the attribute list; the rows behind it change with each test.
	 */
	private function forget_attribute_cache(): void {
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	}

	/**
	 * Remembers the permalink structure held by $wp_rewrite.
	 */
	protected function save_permalink_structure(): void {
		global $wp_rewrite;
		$this->saved_permalink_structure = (string) $wp_rewrite->permalink_structure;
	}

	/**
	 * Puts the remembered permalink structure back on $wp_rewrite when a test changed it.
	 */
	protected function restore_permalink_structure(): void {
		global $wp_rewrite;
		if ( null !== $this->saved_permalink_structure && $this->saved_permalink_structure !== (string) $wp_rewrite->permalink_structure ) {
			$this->set_permalink_structure( $this->saved_permalink_structure );
		}
		$this->saved_permalink_structure = null;
	}

	/**
	 * Remembers the registered shortcodes.
	 */
	protected function save_shortcodes(): void {
		global $shortcode_tags;
		$this->saved_shortcodes = $shortcode_tags;
	}

	/**
	 * Puts back the remembered shortcodes, dropping any the test added.
	 */
	protected function restore_shortcodes(): void {
		global $shortcode_tags;
		if ( null !== $this->saved_shortcodes ) {
			$shortcode_tags = $this->saved_shortcodes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$this->saved_shortcodes = null;
	}

	/**
	 * Rebuilds WooCommerce's gateway objects, which read their settings once when built.
	 * WC_Payment_Gateways::init() adds to the existing list, so empty it first or removed gateways linger.
	 */
	protected function reload_payment_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}
}
