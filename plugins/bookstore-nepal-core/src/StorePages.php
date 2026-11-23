<?php
/**
 * The store information pages: About, Contact, Delivery, Returns, Privacy, Terms and FAQ.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the pages `wp bsn setup` guarantees, without ever overwriting the owner's work.
 *
 * The text lives in theme patterns (bookstore-nepal/page-<key>) and each page holds only a pattern block,
 * so the wording stays translatable and updates with the theme. The owner can "Detach pattern" in the page
 * editor to turn it into ordinary editable blocks.
 */
class StorePages {

	public const CREATED = 'created';
	public const FILLED  = 'filled';
	public const KEPT    = 'left alone';

	/**
	 * Pages by key: the option holding the page ID, the slug for a new page, and its title.
	 * The returns, privacy and terms pages reuse the pages WooCommerce and WordPress already point at.
	 *
	 * @return array<string,array{option:string,slug:string,title:string}>
	 */
	public static function definitions(): array {
		return array(
			'about'    => array(
				'option' => 'bsn_page_about',
				'slug'   => 'about',
				'title'  => __( 'About us', 'bookstore-nepal' ),
			),
			'contact'  => array(
				'option' => 'bsn_page_contact',
				'slug'   => 'contact',
				'title'  => __( 'Contact us', 'bookstore-nepal' ),
			),
			'delivery' => array(
				'option' => 'bsn_page_delivery',
				'slug'   => 'delivery',
				'title'  => __( 'Delivery information', 'bookstore-nepal' ),
			),
			'returns'  => array(
				'option' => 'woocommerce_refund_returns_page_id',
				'slug'   => 'refund_returns',
				'title'  => __( 'Returns & refunds', 'bookstore-nepal' ),
			),
			'privacy'  => array(
				'option' => 'wp_page_for_privacy_policy',
				'slug'   => 'privacy-policy',
				'title'  => __( 'Privacy policy', 'bookstore-nepal' ),
			),
			'terms'    => array(
				'option' => 'woocommerce_terms_page_id',
				'slug'   => 'terms',
				'title'  => __( 'Terms & conditions', 'bookstore-nepal' ),
			),
			'faq'      => array(
				'option' => 'bsn_page_faq',
				'slug'   => 'faq',
				'title'  => __( 'Frequently asked questions', 'bookstore-nepal' ),
			),
		);
	}

	/**
	 * Makes sure every page exists and is published.
	 *
	 * @return array<string,string> Page key => CREATED, FILLED or KEPT, with a reason after KEPT when it needs a word.
	 */
	public static function ensure_all(): array {
		$report = array();
		foreach ( self::definitions() as $key => $def ) {
			$report[ $key ] = self::ensure( $key, $def );
		}
		return $report;
	}

	/**
	 * One page.
	 *
	 * @param string $key Page key.
	 * @param array  $def Definition from definitions().
	 */
	private static function ensure( string $key, array $def ): string {
		$id   = (int) get_option( $def['option'] );
		$post = $id ? get_post( $id ) : null;
		if ( ! ( $post instanceof \WP_Post ) || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			// The option is empty, points at a deleted or trashed page: adopt a page that already holds the
			// slug (the owner may have made one) rather than create "about-2" beside it.
			$post = get_page_by_path( $def['slug'], OBJECT, 'page' );
			if ( ! ( $post instanceof \WP_Post ) || 'trash' === $post->post_status ) {
				$post = null;
			}
		}

		if ( null !== $post ) {
			update_option( $def['option'], (int) $post->ID );
			// Only a draft nobody has edited is ours to fill; published, private, pending, scheduled
			// and edited pages are the owner's.
			if ( 'draft' !== $post->post_status ) {
				return self::KEPT;
			}
			if ( ! self::untouched( $post ) ) {
				return self::KEPT . ' (draft the owner has edited)';
			}
			$updated = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_title'   => $def['title'],
					'post_content' => self::content( $key, $def['title'] ),
					'post_status'  => 'publish',
				),
				true
			);
			return is_wp_error( $updated ) || ! $updated ? self::KEPT . ' (could not update)' : self::FILLED;
		}

		$new = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_content' => self::content( $key, $def['title'] ),
			),
			true
		);
		if ( is_wp_error( $new ) || ! $new ) {
			return self::KEPT . ' (could not create)';
		}
		update_option( $def['option'], (int) $new );
		return self::CREATED;
	}

	/**
	 * Whether a page is still exactly as WordPress or WooCommerce created it. Saving in the editor moves
	 * post_modified on from post_date; the GMT columns are unreliable for drafts, so the local pair is used.
	 *
	 * @param \WP_Post $post Page.
	 */
	private static function untouched( \WP_Post $post ): bool {
		return $post->post_modified === $post->post_date;
	}

	/**
	 * Whether the theme's page patterns are registered (the Bookstore Nepal theme is active).
	 */
	public static function pattern_available(): bool {
		return \WP_Block_Patterns_Registry::get_instance()->is_registered( 'bookstore-nepal/page-faq' );
	}

	/**
	 * The pattern block a store page holds, whether or not the theme's patterns are registered yet.
	 *
	 * @param string $key Page key.
	 */
	public static function pattern_block( string $key ): string {
		return '<!-- wp:pattern {"slug":"bookstore-nepal/page-' . $key . '"} /-->';
	}

	/**
	 * Page content: a pattern block when the theme provides the pattern, else a short plain paragraph.
	 *
	 * @param string $key   Page key.
	 * @param string $title Page title.
	 */
	public static function content( string $key, string $title ): string {
		if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( 'bookstore-nepal/page-' . $key ) ) {
			return self::pattern_block( $key );
		}
		return '<!-- wp:paragraph -->' . "\n" . '<p>'
			. esc_html(
				sprintf(
					/* translators: %s: page title. */
					__( '[CLIENT: write the "%s" page here.]', 'bookstore-nepal' ),
					$title
				)
			)
			. '</p>' . "\n" . '<!-- /wp:paragraph -->';
	}
}
