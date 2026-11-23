<?php
/**
 * Search and sharing basics: page descriptions, link previews, canonical links and a tidy sitemap.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the description Google shows under a result and the Open Graph tags Facebook, Viber and
 * WhatsApp read for link previews. Steps aside when an SEO plugin (Yoast, Rank Math, SEOPress,
 * All in One SEO) is active so the two never print duplicate tags.
 */
class Seo {

	/** Longest description kept; search results cut off around here. */
	public const MAX_LENGTH = 155;

	/**
	 * Hooks the head output, robots rules and sitemap filters.
	 */
	public static function register(): void {
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'filter_sitemap_providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'filter_sitemap_terms' ), 10, 2 );
		if ( self::seo_plugin_active() ) {
			return;
		}
		add_action( 'wp_head', array( self::class, 'print_head' ), 2 );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ) );
	}

	/**
	 * Whether a dedicated SEO plugin is handling these tags already.
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Prints description, canonical (where WordPress prints none) and link-preview tags.
	 */
	public static function print_head(): void {
		$tags = self::tags();
		if ( '' !== $tags['description'] ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $tags['description'] ) );
		}
		$canonical = self::archive_canonical();
		if ( null !== $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
		}
		foreach ( self::open_graph( $tags ) as $property => $content ) {
			$attr = 0 === strpos( $property, 'twitter:' ) ? 'name' : 'property';
			printf( "<meta %s=\"%s\" content=\"%s\" />\n", $attr, esc_attr( $property ), esc_attr( $content ) );
		}
	}

	/**
	 * Title, description, URL, type and image for the current request.
	 *
	 * @return array{title:string,description:string,url:string,type:string,image:string}
	 */
	public static function tags(): array {
		$tags = array(
			'title'       => wp_strip_all_tags( html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' ) ),
			'description' => '',
			'url'         => '',
			'type'        => 'website',
			'image'       => '',
		);

		if ( is_front_page() ) {
			$tags['description'] = self::home_description();
			$tags['url']         = home_url( '/' );
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$tags['description'] = self::site_description();
			$tags['url']         = (string) self::archive_canonical();
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$tags['description'] = self::product_description( $product );
				$tags['url']         = (string) get_permalink( $product->get_id() );
				$tags['type']        = 'product';
				$tags['image']       = self::image_url( (int) $product->get_image_id() );
			}
		} elseif ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$tags['description'] = self::term_description( $term );
				$tags['url']         = (string) self::archive_canonical();
			}
		} elseif ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$tags['description'] = self::post_description( $post );
				$tags['url']         = (string) get_permalink( $post );
				$tags['image']       = self::image_url( (int) get_post_thumbnail_id( $post ) );
			}
		}

		if ( '' === $tags['image'] ) {
			$tags['image'] = self::image_url( (int) get_option( 'site_icon' ) );
		}
		$tags['description'] = self::trim( $tags['description'] );
		return $tags;
	}

	/**
	 * Open Graph and Twitter card tags built from tags().
	 *
	 * @param array $tags Output of tags().
	 * @return array<string,string>
	 */
	public static function open_graph( array $tags ): array {
		if ( '' === $tags['url'] ) {
			return array();
		}
		$og = array(
			'og:site_name' => wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ),
			'og:locale'    => str_replace( '-', '_', get_bloginfo( 'language' ) ),
			'og:type'      => $tags['type'],
			'og:title'     => $tags['title'],
			'og:url'       => $tags['url'],
		);
		if ( '' !== $tags['description'] ) {
			$og['og:description'] = $tags['description'];
		}
		if ( '' !== $tags['image'] ) {
			$og['og:image'] = $tags['image'];
		}
		$og['twitter:card'] = '' !== $tags['image'] ? 'summary_large_image' : 'summary';
		return $og;
	}

	/**
	 * The home page lead line from the Home page form, falling back to the site description.
	 */
	public static function home_description(): string {
		$defaults = (array) apply_filters( 'bsn_content_defaults', array() );
		$lead     = \BookstoreNepal\Content\Content::text( 'bsn_hero_lead', (string) ( $defaults['bsn_hero_lead'] ?? '' ) );
		return '' !== trim( $lead ) ? $lead : self::site_description();
	}

	/**
	 * The footer's one-line shop summary, else the WordPress tagline.
	 */
	public static function site_description(): string {
		$defaults = (array) apply_filters( 'bsn_content_defaults', array() );
		$about    = \BookstoreNepal\Content\Content::text( 'bsn_footer_about', (string) ( $defaults['bsn_footer_about'] ?? '' ) );
		return '' !== trim( $about ) ? $about : (string) get_bloginfo( 'description' );
	}

	/**
	 * Short description, then long description, then a sentence built from the book's details.
	 *
	 * @param \WC_Product $product The book.
	 */
	public static function product_description( \WC_Product $product ): string {
		foreach ( array( $product->get_short_description(), $product->get_description() ) as $text ) {
			$text = self::plain( $text );
			if ( '' !== $text ) {
				return $text;
			}
		}
		$author = trim( (string) $product->get_meta( Catalog::META_AUTHOR ) );
		$ages   = wc_get_product_terms( $product->get_id(), 'pa_age-group', array( 'fields' => 'names' ) );
		$text   = $product->get_name();
		if ( '' !== $author ) {
			/* translators: %s: author name */
			$text .= ' ' . sprintf( __( 'by %s', 'bookstore-nepal' ), $author );
		}
		$text .= ' — ' . ( $ages
			/* translators: %s: age range, e.g. 4–7 */
			? sprintf( __( 'a children’s book for ages %s.', 'bookstore-nepal' ), $ages[0] )
			: __( 'a children’s book.', 'bookstore-nepal' ) );
		return $text . ' ' . __( 'Delivered all over Nepal.', 'bookstore-nepal' );
	}

	/**
	 * The term's own description, else one sentence naming it.
	 *
	 * @param \WP_Term $term Category or filter term (age group, language, book type).
	 */
	public static function term_description( \WP_Term $term ): string {
		$text = self::plain( $term->description );
		if ( '' !== $text ) {
			return $text;
		}
		$name = 'pa_age-group' === $term->taxonomy
			/* translators: %s: age range, e.g. 4–7 */
			? sprintf( __( 'ages %s', 'bookstore-nepal' ), $term->name )
			: $term->name;
		/* translators: %s: category, age range, language or book type */
		return sprintf( __( 'Children’s books: %s. Delivered all over Nepal.', 'bookstore-nepal' ), $name );
	}

	/**
	 * Excerpt if written, else the opening words of the page itself (headings left out).
	 *
	 * @param \WP_Post $post Page or post.
	 */
	public static function post_description( \WP_Post $post ): string {
		$text = self::plain( $post->post_excerpt );
		if ( '' !== $text ) {
			return $text;
		}
		$html = do_blocks( (string) $post->post_content );
		$html = preg_replace( '#<(h[1-6]|script|style|form|button)\b[^>]*>.*?</\1>#is', ' ', $html );
		return self::plain( shortcode_unautop( strip_shortcodes( (string) $html ) ) );
	}

	/**
	 * Canonical link for the shop and product archives, which WordPress leaves without one. Filter and
	 * sort parameters are dropped so every filtered view points at the plain listing (page number kept).
	 */
	public static function archive_canonical(): ?string {
		if ( ! function_exists( 'is_shop' ) ) {
			return null;
		}
		if ( is_shop() ) {
			$base = wc_get_page_permalink( 'shop' );
		} elseif ( is_product_taxonomy() ) {
			$link = get_term_link( get_queried_object() );
			$base = is_wp_error( $link ) ? '' : $link;
		} else {
			return null;
		}
		if ( '' === (string) $base ) {
			return null;
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$base = user_trailingslashit( trailingslashit( $base ) . 'page/' . $paged );
		}
		return $base;
	}

	/**
	 * Keeps the placeholder "Uncategorized" category and author pages out of search results.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ): array {
		$robots = (array) $robots;
		if ( is_author() || ( function_exists( 'is_product_category' ) && is_product_category() && self::is_default_category( get_queried_object() ) ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * Drops the users sitemap: author pages hold nothing for shoppers and would list login names.
	 *
	 * @param mixed  $provider Sitemap provider.
	 * @param string $name     Provider name.
	 * @return mixed
	 */
	public static function filter_sitemap_providers( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Leaves WooCommerce's placeholder "Uncategorized" category out of the category sitemap.
	 *
	 * @param array  $args     get_terms() arguments.
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	public static function filter_sitemap_terms( $args, $taxonomy ): array {
		$args    = (array) $args;
		$default = (int) get_option( 'default_product_cat' );
		if ( 'product_cat' === $taxonomy && $default ) {
			$args['exclude'] = array_merge( array_map( 'intval', (array) ( $args['exclude'] ?? array() ) ), array( $default ) );
		}
		return $args;
	}

	/**
	 * Whether a term is WooCommerce's default product category.
	 *
	 * @param mixed $term Queried object.
	 */
	private static function is_default_category( $term ): bool {
		return $term instanceof \WP_Term && (int) $term->term_id === (int) get_option( 'default_product_cat' );
	}

	/**
	 * Full-size URL of an attachment, or '' when there is none.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function image_url( int $attachment_id ): string {
		if ( ! $attachment_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		return $src ? (string) $src : '';
	}

	/**
	 * HTML to one line of plain text.
	 *
	 * @param string $html Markup.
	 */
	private static function plain( string $html ): string {
		// A space where one block ends and the next begins, so "question?</summary><p>Answer" keeps its gap.
		$html = (string) preg_replace( '#<(?:/?(?:p|div|li|ul|ol|dt|dd|summary|details|section|tr|td|th|h[1-6]|blockquote|figcaption)\b[^>]*|br\s*/?)>#i', ' $0', $html );
		$text = wp_strip_all_tags( strip_shortcodes( $html ), true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Shortens to MAX_LENGTH characters at a word boundary, ending with an ellipsis when cut.
	 *
	 * @param string $text Plain text.
	 */
	public static function trim( string $text ): string {
		$text = self::plain( $text );
		if ( mb_strlen( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, self::MAX_LENGTH - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > self::MAX_LENGTH / 2 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t,;:.–—-" ) . '…';
	}
}
