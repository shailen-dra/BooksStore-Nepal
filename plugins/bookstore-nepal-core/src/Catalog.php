<?php
/**
 * Catalogue attributes and book fields.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Global book attributes, plus page count and author product fields.
 */
class Catalog {

	public const META_PAGES  = '_bsn_page_count';
	public const META_AUTHOR = '_bsn_author';

	/**
	 * Slug => [ label, terms ] where terms is slug => name.
	 */
	public const ATTRIBUTES = array(
		'age-group' => array(
			'Age group',
			array(
				'0-3'  => '0–3',
				'4-7'  => '4–7',
				'8-12' => '8–12',
			),
		),
		'language'  => array(
			'Language',
			array(
				'nepali'  => 'Nepali',
				'english' => 'English',
			),
		),
		'book-type' => array(
			'Book type',
			array(
				'picture-book' => 'Picture book',
				'story'        => 'Story',
				'activity'     => 'Activity',
				'educational'  => 'Educational',
			),
		),
	);

	/**
	 * Hooks runtime behaviour.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( self::class, 'save_fields' ) );
		add_action( 'woocommerce_single_product_summary', array( self::class, 'render_details' ), 25 );
	}

	/**
	 * Creates attributes and terms; safe to run repeatedly.
	 */
	public static function install(): void {
		// Make sure lookups below reflect the database, not stale caches.
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		foreach ( self::ATTRIBUTES as $slug => $def ) {
			list( $label, $terms ) = $def;
			$taxonomy              = wc_attribute_taxonomy_name( $slug );

			if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				$created = wc_create_attribute(
					array(
						'name'         => $label,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => true,
					)
				);
				if ( is_wp_error( $created ) ) {
					continue;
				}
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
			}

			foreach ( $terms as $term_slug => $name ) {
				if ( ! get_term_by( 'slug', $term_slug, $taxonomy ) ) {
					wp_insert_term( $name, $taxonomy, array( 'slug' => $term_slug ) );
				}
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	}

	/**
	 * Admin fields on the General tab.
	 */
	public static function render_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_AUTHOR,
				'label'             => __( 'Author', 'bookstore-nepal' ),
				'type'              => 'text',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_PAGES,
				'label'             => __( 'Page count', 'bookstore-nepal' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves and sanitizes the fields.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_fields( $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before this hook.
		if ( isset( $_POST[ self::META_PAGES ] ) ) {
			$raw   = trim( wp_unslash( (string) $_POST[ self::META_PAGES ] ) );
			$pages = ( '' !== $raw && ctype_digit( $raw ) ) ? (string) absint( $raw ) : '';
			$product->update_meta_data( self::META_PAGES, $pages );
		}
		if ( isset( $_POST[ self::META_AUTHOR ] ) ) {
			$product->update_meta_data( self::META_AUTHOR, sanitize_text_field( wp_unslash( (string) $_POST[ self::META_AUTHOR ] ) ) );
		}
		// phpcs:enable
		$product->save_meta_data();
	}

	/**
	 * Front-end details under the price.
	 */
	public static function render_details(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$rows   = array();
		$author = (string) $product->get_meta( self::META_AUTHOR );
		if ( '' !== $author ) {
			$rows[ __( 'Author', 'bookstore-nepal' ) ] = $author;
		}
		$age = wc_get_product_terms( $product->get_id(), 'pa_age-group', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $age ) && $age ) {
			$rows[ __( 'Age', 'bookstore-nepal' ) ] = implode( ', ', $age );
		}
		$lang = wc_get_product_terms( $product->get_id(), 'pa_language', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $lang ) && $lang ) {
			$rows[ __( 'Language', 'bookstore-nepal' ) ] = implode( ', ', $lang );
		}
		$pages = (string) $product->get_meta( self::META_PAGES );
		if ( '' !== $pages ) {
			$rows[ __( 'Pages', 'bookstore-nepal' ) ] = $pages;
		}
		if ( ! $rows ) {
			return;
		}
		echo '<ul class="bsn-book-details">';
		foreach ( $rows as $label => $value ) {
			printf( '<li><strong>%s:</strong> %s</li>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</ul>';
	}
}
