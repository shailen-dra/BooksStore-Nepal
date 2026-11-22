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
		add_filter( 'posts_search', array( self::class, 'search_author' ), 10, 2 );
	}

	/**
	 * Lets front-end product searches match the author field as well as title and description.
	 *
	 * The whole search phrase is matched against the author, so "Devkota" finds
	 * "Laxmi Prasad Devkota" and "Mira Rai" finds "Mira Rai". Each word WordPress parsed from the
	 * search may also match the author instead of the title or description, so "Devkota poems"
	 * finds a book of poems by Devkota. Excluded words ("-poems") still exclude.
	 *
	 * @param string    $search Search SQL from WP_Query (starts with " AND (").
	 * @param \WP_Query $query  The query.
	 * @return string
	 */
	public static function search_author( $search, $query ): string {
		$search = (string) $search;
		if ( '' === $search || ! $query instanceof \WP_Query || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $search;
		}
		if ( ! in_array( 'product', (array) $query->get( 'post_type' ), true ) ) {
			return $search;
		}
		$phrase = trim( (string) $query->get( 's' ) );
		if ( '' === $phrase ) {
			return $search;
		}
		global $wpdb;
		$extra = self::author_exists( '%' . $wpdb->esc_like( $phrase ) . '%' ) . ' OR ';
		$terms = self::author_terms_clause( $query, $phrase );
		if ( '' !== $terms ) {
			$extra .= $terms . ' OR ';
		}
		// WP_Query wraps the term clauses as " AND ( ... ) "; OR the author checks into that group.
		$result = preg_replace_callback(
			'/^\s*AND\s*\(/',
			static function () use ( $extra ): string {
				return ' AND ( ' . $extra;
			},
			$search,
			1
		);
		return null === $result ? $search : $result;
	}

	/**
	 * SQL that is true when the product's author matches a LIKE pattern.
	 *
	 * @param string $like Escaped LIKE pattern.
	 * @return string
	 */
	private static function author_exists( string $like ): string {
		global $wpdb;
		return $wpdb->prepare(
			"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} bsn_author WHERE bsn_author.post_id = {$wpdb->posts}.ID AND bsn_author.meta_key = %s AND bsn_author.meta_value LIKE %s )",
			self::META_AUTHOR,
			$like
		);
	}

	/**
	 * Mirrors WordPress's per-word search, letting each word match the author too.
	 *
	 * @param \WP_Query $query  The query, after WordPress parsed its search terms.
	 * @param string    $phrase The trimmed search phrase.
	 * @return string SQL, or empty string when there is nothing beyond the whole-phrase match.
	 */
	private static function author_terms_clause( \WP_Query $query, string $phrase ): string {
		global $wpdb;
		$terms = array_values( array_filter( array_map( 'strval', (array) $query->get( 'search_terms' ) ), 'strlen' ) );
		if ( ! $terms || array( $phrase ) === $terms ) {
			return '';
		}
		$allowed = array( 'post_title', 'post_excerpt', 'post_content' );
		$columns = array_values( array_intersect( (array) $query->get( 'search_columns' ), $allowed ) );
		$columns = $columns ? $columns : $allowed;
		$wild    = $query->get( 'exact' ) ? '' : '%';
		/** This filter is documented in wp-includes/class-wp-query.php */
		$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$parts  = array();
		foreach ( $terms as $term ) {
			$exclude = $prefix && str_starts_with( $term, $prefix );
			if ( $exclude ) {
				$term = substr( $term, strlen( $prefix ) );
				if ( '' === $term ) {
					continue;
				}
			}
			$like    = $wild . $wpdb->esc_like( $term ) . $wild;
			$matches = array();
			foreach ( $columns as $column ) {
				$matches[] = $wpdb->prepare( "{$wpdb->posts}.{$column} LIKE %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column is from an allow-list.
			}
			$parts[] = $exclude
				? 'NOT ( ' . implode( ' OR ', $matches ) . ' )'
				: '( ' . self::author_exists( $like ) . ' OR ' . implode( ' OR ', $matches ) . ' )';
		}
		return $parts ? '( ' . implode( ' AND ', $parts ) . ' )' : '';
	}

	/**
	 * Creates attributes and terms; safe to run repeatedly.
	 *
	 * @return string[] Human-readable errors; empty array means success.
	 */
	public static function install(): array {
		if ( ! function_exists( 'wc_create_attribute' ) || ! class_exists( '\WC_Cache_Helper' ) ) {
			return self::report( array( __( 'WooCommerce is required to set up the catalogue.', 'bookstore-nepal' ) ) );
		}

		$errors = array();

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
					/* translators: 1: attribute slug, 2: error message. */
					$errors[] = sprintf( __( 'Could not create attribute %1$s: %2$s', 'bookstore-nepal' ), $slug, $created->get_error_message() );
					continue;
				}
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
			}

			foreach ( $terms as $term_slug => $name ) {
				if ( get_term_by( 'slug', $term_slug, $taxonomy ) ) {
					continue;
				}
				$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $term_slug ) );
				if ( is_wp_error( $inserted ) ) {
					/* translators: 1: term name, 2: attribute slug, 3: error message. */
					$errors[] = sprintf( __( 'Could not create term %1$s in %2$s: %3$s', 'bookstore-nepal' ), $name, $slug, $inserted->get_error_message() );
				}
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		return self::report( $errors );
	}

	/**
	 * Logs errors and passes them through.
	 *
	 * @param string[] $errors Errors.
	 * @return string[]
	 */
	private static function report( array $errors ): array {
		foreach ( $errors as $error ) {
			error_log( 'bookstore-nepal: ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $errors;
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
