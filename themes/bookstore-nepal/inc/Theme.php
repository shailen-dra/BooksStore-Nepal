<?php
/**
 * Theme behaviour: assets, URLs used by patterns, product card helpers.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront helpers for the Bookstore Nepal child theme.
 */
final class Theme {

	public const VERSION = '0.1.10';

	/** Class on an empty group block where the plugin's book details render. */
	public const DETAILS_SLOT_CLASS = 'bsn-book-details-slot';

	/** Class on a core/post-terms block whose term links render as plain text. */
	public const PLAIN_TERMS_CLASS = 'bsn-plain-terms';

	/** Class on the product image block of a book card; its link is skipped because the title links too. */
	public const CARD_IMAGE_CLASS = 'bsn-card__image';

	/** Class on a product image block that should not get the age badge. */
	public const NO_BADGE_CLASS = 'bsn-no-badge';

	/** Attributes the shop can be filtered by (WooCommerce attribute slugs, without "pa_"). */
	public const FILTER_ATTRIBUTES = array( 'age-group', 'language', 'book-type' );

	/**
	 * Placeholder cover colours: [ background, text ]. Every pair is at least 4.5:1.
	 */
	public const COVER_COLOURS = array(
		array( '#2F6FB5', '#FFFFFF' ),
		array( '#F4B23E', '#1F2A44' ),
		array( '#E8746A', '#1F2A44' ),
		array( '#2F7A55', '#FFFFFF' ),
		array( '#7A5BC4', '#FFFFFF' ),
		array( '#FCE1E4', '#1F2A44' ),
		array( '#DCEBFA', '#1F2A44' ),
		array( '#1F2A44', '#F4C66B' ),
	);

	/**
	 * Delivery rates cache for the current request.
	 *
	 * @var array<string,float|null>|null
	 */
	private static ?array $rates = null;

	/**
	 * Hooks everything.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'bsn_content_defaults', array( Wording::class, 'defaults' ) );
		add_action( 'wp_head', array( self::class, 'preload_fonts' ), 1 );
		add_action( 'wp_head', array( self::class, 'print_fallback_icon' ), 99 );
		add_action( 'init', array( self::class, 'register_pattern_category' ) );
		add_action( 'wp', array( self::class, 'relocate_book_details' ) );
		add_action( 'after_switch_theme', array( self::class, 'ensure_front_page' ) );
		add_filter( 'render_block_woocommerce/product-image', array( self::class, 'filter_product_image' ), 10, 3 );
		add_filter( 'render_block_woocommerce/product-collection', array( self::class, 'filter_slider_region' ), 10, 2 );
		add_filter( 'render_block_core/post-terms', array( self::class, 'filter_plain_terms' ), 10, 2 );
		add_filter( 'render_block_core/group', array( self::class, 'filter_details_slot' ), 10, 2 );
		// Templates run do_shortcode before do_blocks, so shortcode blocks inside patterns need their own pass.
		add_filter( 'render_block_core/shortcode', 'do_shortcode', 10, 1 );
		add_filter( 'woocommerce_get_breadcrumb', array( self::class, 'filter_breadcrumb' ), 10 );
		add_filter( 'woocommerce_breadcrumb_defaults', array( self::class, 'breadcrumb_defaults' ) );
	}

	/**
	 * Theme stylesheet; WordPress inlines it when the page's inline-style budget allows.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'bsn-theme', get_stylesheet_uri(), array(), self::VERSION );
		wp_style_add_data( 'bsn-theme', 'path', get_stylesheet_directory() . '/style.css' );
		wp_enqueue_script( 'bsn-motion', get_stylesheet_directory_uri() . '/assets/js/motion.js', array(), self::VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_script( 'bsn-a11y', get_stylesheet_directory_uri() . '/assets/js/a11y.js', array(), self::VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		if ( is_front_page() ) {
			wp_enqueue_script( 'bsn-slider', get_stylesheet_directory_uri() . '/assets/js/slider.js', array(), self::VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		}
	}

	/**
	 * The book logo as the browser-tab icon until the owner sets a Site Icon in Appearance › Editor ›
	 * Site Identity; without one browsers ask for /favicon.ico and log a 404.
	 */
	public static function print_fallback_icon(): void {
		if ( has_site_icon() ) {
			return;
		}
		printf( "<link rel=\"icon\" href=\"%s\" type=\"image/svg+xml\" />\n", esc_url( get_stylesheet_directory_uri() . '/assets/icon.svg' ) );
	}

	/**
	 * Sliders by key: the key is the `bsn-slider--{key}` modifier class, which survives an editor re-save.
	 *
	 * @return array<string,string> Key => accessible name.
	 */
	public static function slider_labels(): array {
		return array(
			'new-arrivals' => __( 'New arrivals', 'bookstore-nepal' ),
			'best-sellers' => __( 'Best sellers', 'bookstore-nepal' ),
		);
	}

	/**
	 * Element ID of a slider's scrolling row.
	 *
	 * @param string $key Slider key.
	 */
	public static function slider_id( string $key ): string {
		return 'bsn-slider-' . $key;
	}

	/**
	 * Makes a slider's scrolling row a labelled, focusable region at render time.
	 *
	 * Keyed off the block's class (a declared attribute), and the attributes are not part of the
	 * saved markup, so neither the editor nor a re-save can lose or flag them.
	 *
	 * @param string $html  Rendered block.
	 * @param array  $block Parsed block.
	 */
	public static function filter_slider_region( $html, $block = array() ): string {
		$html = (string) $html;
		if ( ! str_contains( $html, 'bsn-slider--' ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'class_name' => 'bsn-slider' ) ) ) {
			return $html;
		}
		foreach ( self::slider_labels() as $key => $label ) {
			if ( $tags->has_class( 'bsn-slider--' . $key ) ) {
				$tags->set_attribute( 'id', self::slider_id( $key ) );
				$tags->set_attribute( 'role', 'region' );
				$tags->set_attribute( 'aria-label', $label );
				$tags->set_attribute( 'tabindex', '0' );
				return $tags->get_updated_html();
			}
		}
		return $html;
	}

	/**
	 * Previous/next buttons for a slider; hidden until slider.js shows them.
	 *
	 * @param string $row_id ID of the scrolling row.
	 */
	public static function slider_controls( string $row_id ): string {
		$icon  = '<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 20 20"><path d="%s" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		$items = array(
			'prev' => array( esc_attr__( 'Previous books', 'bookstore-nepal' ), 'M12.5 4 6.5 10l6 6' ),
			'next' => array( esc_attr__( 'Next books', 'bookstore-nepal' ), 'M7.5 4l6 6-6 6' ),
		);
		$html  = '<div class="bsn-slider-controls">';
		foreach ( $items as $dir => $item ) {
			$html .= '<button type="button" class="bsn-slider-btn" data-bsn-slider-' . $dir . ' aria-label="' . $item[0] . '" aria-controls="' . esc_attr( $row_id ) . '" hidden>' . sprintf( $icon, $item[1] ) . '</button>';
		}
		return $html . '</div>';
	}

	/**
	 * Preloads the Latin font files used above the fold.
	 */
	public static function preload_fonts(): void {
		foreach ( array( 'baloo2-latin.woff2', 'mukta-400-latin.woff2' ) as $file ) {
			printf(
				'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
				esc_url( get_stylesheet_directory_uri() . '/assets/fonts/' . $file )
			);
		}
	}

	/**
	 * Pattern category for the theme's patterns.
	 */
	public static function register_pattern_category(): void {
		register_block_pattern_category( 'bookstore-nepal', array( 'label' => __( 'Bookstore Nepal', 'bookstore-nepal' ) ) );
	}

	/**
	 * Product archive URL.
	 */
	public static function shop_url(): string {
		if ( class_exists( '\BookstoreNepal\GiftFinder' ) ) {
			return \BookstoreNepal\GiftFinder::shop_url();
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		return $shop ? $shop : home_url( '/' );
	}

	/**
	 * Shop filtered to one age group, via the gift finder's URL builder.
	 *
	 * @param string   $age_slug  Age group term slug.
	 * @param int|null $max_price Optional maximum price.
	 */
	public static function age_url( string $age_slug, ?int $max_price = null ): string {
		if ( class_exists( '\BookstoreNepal\GiftFinder' ) ) {
			return \BookstoreNepal\GiftFinder::url( $age_slug, $max_price );
		}
		return self::filter_url( 'age-group', $age_slug );
	}

	/**
	 * Shop filtered to one attribute term.
	 *
	 * @param string $attribute Attribute slug without "pa_".
	 * @param string $term_slug Term slug.
	 */
	public static function filter_url( string $attribute, string $term_slug ): string {
		return add_query_arg(
			array(
				'filter_' . $attribute     => $term_slug,
				'query_type_' . $attribute => 'or',
			),
			self::shop_url()
		);
	}

	/**
	 * Adds or removes one term from a URL's attribute filter, keeping other filters and dropping pagination.
	 *
	 * @param string $url       Current URL (absolute or root-relative).
	 * @param string $attribute Attribute slug without "pa_".
	 * @param string $term_slug Term slug to toggle.
	 */
	public static function toggle_filter_url( string $url, string $attribute, string $term_slug ): string {
		$key    = 'filter_' . $attribute;
		$values = self::active_filter_values( $url, $attribute );
		if ( in_array( $term_slug, $values, true ) ) {
			$values = array_values( array_diff( $values, array( $term_slug ) ) );
		} else {
			$values[] = $term_slug;
		}

		$url = self::strip_pagination( $url );
		$url = remove_query_arg( array( $key, 'query_type_' . $attribute, 'paged', 'product-page' ), $url );
		if ( $values ) {
			$url = add_query_arg(
				array(
					$key                       => implode( ',', $values ),
					'query_type_' . $attribute => 'or',
				),
				$url
			);
		}
		return $url;
	}

	/**
	 * Term slugs currently selected for an attribute in a URL.
	 *
	 * @param string $url       URL.
	 * @param string $attribute Attribute slug without "pa_".
	 * @return string[]
	 */
	public static function active_filter_values( string $url, string $attribute ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$raw = $query[ 'filter_' . $attribute ] ?? '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_title', explode( ',', $raw ) ) ) );
	}

	/**
	 * Removes a query arg and pagination from a URL.
	 *
	 * @param string $url URL.
	 * @param string $arg Query arg to remove.
	 */
	public static function remove_filter_arg( string $url, string $arg ): string {
		return remove_query_arg( array( $arg, 'paged', 'product-page' ), self::strip_pagination( $url ) );
	}

	/**
	 * Removes every shop filter (attributes and price) and pagination, keeping e.g. a search term.
	 *
	 * @param string $url URL.
	 */
	public static function clear_filters_url( string $url ): string {
		$args = array( 'min_price', 'max_price', 'paged', 'product-page' );
		foreach ( self::FILTER_ATTRIBUTES as $attribute ) {
			$args[] = 'filter_' . $attribute;
			$args[] = 'query_type_' . $attribute;
		}
		return remove_query_arg( $args, self::strip_pagination( $url ) );
	}

	/**
	 * Drops a /page/N/ path segment.
	 *
	 * @param string $url URL.
	 */
	private static function strip_pagination( string $url ): string {
		return (string) preg_replace( '#/page/\d+/?(?=\?|\#|$)#', '/', $url );
	}

	/**
	 * Permalink of a published page, or null.
	 *
	 * @param int $page_id Page ID.
	 */
	public static function published_page_url( int $page_id ): ?string {
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
			return null;
		}
		$link = get_permalink( $page_id );
		return $link ? $link : null;
	}

	/**
	 * "Track your order" page URL (created by `wp bsn setup`).
	 */
	public static function tracking_url(): ?string {
		return self::page_url( 'tracking' );
	}

	/**
	 * Option holding each store information page's ID. About, Contact, Delivery and FAQ are created by
	 * `wp bsn setup`; the other three are the pages WooCommerce and WordPress already point at.
	 */
	public const PAGE_OPTIONS = array(
		'about'    => 'bsn_page_about',
		'contact'  => 'bsn_page_contact',
		'delivery' => 'bsn_page_delivery',
		'faq'      => 'bsn_page_faq',
		'tracking' => 'bsn_tracking_page_id',
		'returns'  => 'woocommerce_refund_returns_page_id',
		'privacy'  => 'wp_page_for_privacy_policy',
		'terms'    => 'woocommerce_terms_page_id',
	);

	/**
	 * URL of a store information page, only while it is published.
	 *
	 * @param string $key One of the keys of PAGE_OPTIONS.
	 */
	public static function page_url( string $key ): ?string {
		$option = self::PAGE_OPTIONS[ $key ] ?? null;
		return null === $option ? null : self::published_page_url( (int) get_option( $option ) );
	}

	/**
	 * Shop contact details from WooCommerce settings. Empty values stay empty so patterns can show a
	 * placeholder for whatever is missing.
	 *
	 * @return array{phone: string, whatsapp: string, email: string, address: string[]}
	 */
	public static function contact_details(): array {
		$email    = trim( (string) get_option( 'bsn_shop_email' ) );
		$whatsapp = preg_replace( '/\D+/', '', (string) get_option( 'bsn_shop_whatsapp' ) );
		return array(
			'phone'    => trim( (string) get_option( 'bsn_shop_phone' ) ),
			// Only 977 plus a ten-digit mobile makes a working wa.me link; anything else shows the placeholder.
			'whatsapp' => 1 === preg_match( '/^9779\d{9}\z/', (string) $whatsapp ) ? (string) $whatsapp : '',
			'email'    => is_email( $email ) ? $email : '',
			'address'  => self::address_lines(),
		);
	}

	/**
	 * The WooCommerce store address as display lines; empty unless a street or town is set (the country
	 * alone is not an address).
	 *
	 * @return string[]
	 */
	private static function address_lines(): array {
		$street = trim( (string) get_option( 'woocommerce_store_address' ) );
		$more   = trim( (string) get_option( 'woocommerce_store_address_2' ) );
		$city   = trim( (string) get_option( 'woocommerce_store_city' ) );
		if ( '' === $street && '' === $city ) {
			return array();
		}
		$postcode = trim( (string) get_option( 'woocommerce_store_postcode' ) );
		$parts    = explode( ':', (string) get_option( 'woocommerce_default_country' ) );
		$country  = $parts[0];
		$state    = $parts[1] ?? '';
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states  = WC()->countries->get_states( $country );
			$state   = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
			$country = WC()->countries->get_countries()[ $country ] ?? $country;
		}
		$lines = array( $street, $more, trim( $city . ' ' . $postcode ), $state, $country );
		return array_values( array_filter( $lines, static fn( $line ) => '' !== trim( (string) $line ) ) );
	}

	/**
	 * Serialises simple page content into blocks, escaping every string, so patterns stay readable and
	 * translatable. Each item is an array: [ 'h2'|'h3'|'p'|'note', text ], [ 'p_html', trusted html ],
	 * [ 'ul'|'ol', string[] ] or [ 'qa', question, answer ].
	 *
	 * @param array<int,array> $items Content items.
	 */
	public static function blocks( array $items ): string {
		$out = array();
		foreach ( $items as $item ) {
			$type = $item[0];
			switch ( $type ) {
				case 'h2':
				case 'h3':
					$level = (int) substr( $type, 1 );
					$out[] = '<!-- wp:heading {"level":' . $level . '} --><' . $type . ' class="wp-block-heading">' . esc_html( $item[1] ) . '</' . $type . '><!-- /wp:heading -->';
					break;
				case 'p':
					$out[] = '<!-- wp:paragraph --><p>' . esc_html( $item[1] ) . '</p><!-- /wp:paragraph -->';
					break;
				case 'note':
					$out[] = '<!-- wp:paragraph {"className":"bsn-placeholder"} --><p class="bsn-placeholder">' . esc_html( $item[1] ) . '</p><!-- /wp:paragraph -->';
					break;
				case 'p_html':
					$out[] = '<!-- wp:paragraph --><p>' . wp_kses_post( $item[1] ) . '</p><!-- /wp:paragraph -->';
					break;
				case 'ul':
				case 'ol':
					$list = '';
					foreach ( $item[1] as $li ) {
						$list .= '<!-- wp:list-item --><li>' . wp_kses_post( $li ) . '</li><!-- /wp:list-item -->';
					}
					$attrs = 'ol' === $type ? ' {"ordered":true}' : '';
					$out[] = '<!-- wp:list' . $attrs . ' --><' . $type . ' class="wp-block-list">' . $list . '</' . $type . '><!-- /wp:list -->';
					break;
				case 'qa':
					$out[] = '<!-- wp:details {"className":"bsn-faq"} --><details class="wp-block-details bsn-faq"><summary>' . esc_html( $item[1] ) . '</summary><!-- wp:paragraph --><p>' . wp_kses_post( $item[2] ) . '</p><!-- /wp:paragraph --></details><!-- /wp:details -->';
					break;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Like blocks(), but plain HTML without the block markers: default text for the wording boxes.
	 *
	 * @param array<int,array> $items Content items, as for blocks().
	 */
	public static function html( array $items ): string {
		return trim( (string) preg_replace( '/<!--\s*\/?wp:[^>]*?-->\s*/', '', self::blocks( $items ) ) );
	}

	/**
	 * A link as safe HTML for use inside a sentence, or the plain label when the page is not published.
	 *
	 * @param string $key   Page key from PAGE_OPTIONS.
	 * @param string $label Link text.
	 */
	public static function page_link( string $key, string $label ): string {
		$url = self::page_url( $key );
		return null === $url ? esc_html( $label ) : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Current delivery rates from the plugin's shipping zones.
	 *
	 * @return array{valley: float|null, rest: float|null, free_over: float|null}
	 */
	public static function delivery_rates(): array {
		if ( null !== self::$rates ) {
			return self::$rates;
		}
		$rates = array(
			'valley'    => null,
			'rest'      => null,
			'free_over' => null,
		);
		if ( ! class_exists( '\WC_Shipping_Zones' ) || ! class_exists( '\BookstoreNepal\Shipping' ) ) {
			return $rates;
		}
		$zones = array(
			\BookstoreNepal\Shipping::ZONE_VALLEY => 'valley',
			\BookstoreNepal\Shipping::ZONE_REST   => 'rest',
		);
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$key = $zones[ $zone['zone_name'] ] ?? null;
			if ( null === $key ) {
				continue;
			}
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'yes' !== $method->enabled ) {
					continue;
				}
				if ( 'flat_rate' === $method->id && is_numeric( $method->get_option( 'cost' ) ) ) {
					$rates[ $key ] = (float) $method->get_option( 'cost' );
				}
				if ( 'free_shipping' === $method->id && is_numeric( $method->get_option( 'min_amount' ) ) && null === $rates['free_over'] ) {
					$rates['free_over'] = (float) $method->get_option( 'min_amount' );
				}
			}
		}
		self::$rates = $rates;
		return $rates;
	}

	/**
	 * Forgets cached rates (tests, or after rates change mid-request).
	 */
	public static function flush_rates(): void {
		self::$rates = null;
	}

	/**
	 * Payment methods checkout currently offers, in a fixed order: eSewa, Khalti, cash on delivery.
	 *
	 * A method counts when its gateway is installed and switched on in WooCommerce. eSewa and Khalti
	 * must also report themselves available (e.g. they need their keys and NPR). Cash on delivery's
	 * availability depends on the cart's delivery method, so for it being switched on is enough.
	 * Khalti is our own gateway (bsn_khalti); the third-party plugin's "khalti" gateway cannot take
	 * payments in the block checkout, so it is never advertised.
	 *
	 * @return array<string,string> Gateway id => label, e.g. [ 'esewa' => 'eSewa', 'cod' => 'Cash on delivery' ].
	 */
	public static function payment_methods(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		$labels   = array(
			'esewa'      => __( 'eSewa', 'bookstore-nepal' ),
			'bsn_khalti' => __( 'Khalti', 'bookstore-nepal' ),
			'cod'        => __( 'Cash on delivery', 'bookstore-nepal' ),
		);
		$gateways = WC()->payment_gateways()->payment_gateways();
		$methods  = array();
		foreach ( $labels as $id => $label ) {
			$gateway = $gateways[ $id ] ?? null;
			if ( ! $gateway instanceof \WC_Payment_Gateway || 'yes' !== $gateway->enabled ) {
				continue;
			}
			if ( 'cod' !== $id && ! $gateway->is_available() ) {
				continue;
			}
			$methods[ $id ] = $label;
		}
		return $methods;
	}

	/**
	 * Enabled payment methods as a phrase, e.g. "eSewa, Khalti and cash on delivery"; empty if none.
	 *
	 * @param string $conjunction 'and' or 'or'.
	 */
	public static function payment_list( string $conjunction = 'and' ): string {
		$names = array();
		foreach ( self::payment_methods() as $id => $label ) {
			$names[] = 'cod' === $id ? __( 'cash on delivery', 'bookstore-nepal' ) : $label;
		}
		if ( count( $names ) < 2 ) {
			return (string) ( $names[0] ?? '' );
		}
		$last = array_pop( $names );
		$head = implode( __( ', ', 'bookstore-nepal' ), $names );
		return 'or' === $conjunction
			/* translators: 1: comma-separated payment methods, 2: the last payment method. */
			? sprintf( __( '%1$s or %2$s', 'bookstore-nepal' ), $head, $last )
			/* translators: 1: comma-separated payment methods, 2: the last payment method. */
			: sprintf( __( '%1$s and %2$s', 'bookstore-nepal' ), $head, $last );
	}

	/**
	 * Formats an amount as "NPR 2,000".
	 *
	 * @param float $amount Amount in NPR.
	 */
	public static function npr( float $amount ): string {
		/* translators: %s: amount in Nepali rupees. */
		return sprintf( __( 'NPR %s', 'bookstore-nepal' ), number_format( $amount, 0, '.', ',' ) );
	}

	/**
	 * Age group term names for a product, e.g. "4–7".
	 *
	 * @param int $product_id Product ID.
	 */
	public static function age_label( int $product_id ): string {
		$names = wc_get_product_terms( $product_id, 'pa_age-group', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || ! $names ) {
			return '';
		}
		return implode( ', ', $names );
	}

	/**
	 * Coloured placeholder cover showing the book's title.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function cover_html( \WC_Product $product ): string {
		list( $bg, $ink ) = self::COVER_COLOURS[ $product->get_id() % count( self::COVER_COLOURS ) ];
		return sprintf(
			'<span class="bsn-cover" role="img" aria-label="%1$s" style="--bsn-cover-bg:%2$s;--bsn-cover-ink:%3$s"><span class="bsn-cover__title" aria-hidden="true">%4$s</span></span>',
			esc_attr( $product->get_name() ),
			esc_attr( $bg ),
			esc_attr( $ink ),
			esc_html( $product->get_name() )
		);
	}

	/**
	 * Swaps WooCommerce's grey placeholder for a coloured cover and adds the age badge.
	 *
	 * @param string         $html     Rendered block.
	 * @param array          $block    Parsed block.
	 * @param \WP_Block|null $instance Block instance (has the product ID in context).
	 */
	public static function filter_product_image( $html, $block, $instance = null ): string {
		$html = (string) $html;
		$id   = ( $instance instanceof \WP_Block && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : 0;
		if ( ! $id && isset( $block['attrs']['productId'] ) ) {
			$id = (int) $block['attrs']['productId'];
		}
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}

		if ( ! $product->get_image_id() ) {
			$cover = self::cover_html( $product );
			$html  = (string) preg_replace_callback(
				'#<img\b[^>]*\bwoocommerce-placeholder\b[^>]*>#',
				static function () use ( $cover ) {
					return $cover;
				},
				$html,
				1
			);
		}

		$class = (string) ( $block['attrs']['className'] ?? '' );
		if ( false !== strpos( $class, self::CARD_IMAGE_CLASS ) ) {
			// The card title links to the same page, so keep the image link out of the tab order and screen readers.
			$html = (string) preg_replace( '#<a\b(?![^>]*\btabindex=)([^>]*)>#', '<a$1 aria-hidden="true" tabindex="-1">', $html, 1 );
		}
		$age = self::age_label( $product->get_id() );
		if ( '' !== $age && false === strpos( $class, self::NO_BADGE_CLASS ) ) {
			/* translators: %s: age range, e.g. 4–7. */
			$badge = '<span class="bsn-age-badge">' . esc_html( sprintf( __( 'Ages %s', 'bookstore-nepal' ), $age ) ) . '</span>';
			// Before the link, not inside it, so it is read as text rather than as part of the link.
			$count = 0;
			$out   = preg_replace( '#<a\b#', str_replace( '$', '\$', $badge ) . '<a', $html, 1, $count );
			if ( ! $count ) {
				$out = preg_replace( '#^(\s*<div\b[^>]*>)#', '$1' . str_replace( '$', '\$', $badge ), $html, 1 );
			}
			$html = (string) $out;
		}
		return $html;
	}

	/**
	 * Renders term links as plain text for post-terms blocks marked with PLAIN_TERMS_CLASS.
	 *
	 * @param string $html  Rendered block.
	 * @param array  $block Parsed block.
	 */
	public static function filter_plain_terms( $html, $block ): string {
		$html = (string) $html;
		if ( false === strpos( (string) ( $block['attrs']['className'] ?? '' ), self::PLAIN_TERMS_CLASS ) ) {
			return $html;
		}
		return (string) preg_replace( '#<a\b[^>]*>(.*?)</a>#s', '<span>$1</span>', $html );
	}

	/**
	 * Prints the plugin's book details (author, age, language, pages) inside the slot group.
	 *
	 * @param string $html  Rendered block.
	 * @param array  $block Parsed block.
	 */
	public static function filter_details_slot( $html, $block ): string {
		$html = (string) $html;
		if ( false === strpos( (string) ( $block['attrs']['className'] ?? '' ), self::DETAILS_SLOT_CLASS ) ) {
			return $html;
		}
		if ( ! class_exists( '\BookstoreNepal\Catalog' ) ) {
			return '';
		}
		// render_details() reads the global product; borrow it and put back whatever was there.
		$had_product = array_key_exists( 'product', $GLOBALS );
		$previous    = $GLOBALS['product'] ?? null;
		if ( ! $previous instanceof \WC_Product ) {
			$GLOBALS['product'] = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		ob_start();
		try {
			\BookstoreNepal\Catalog::render_details();
		} finally {
			$details = trim( (string) ob_get_clean() );
			if ( $had_product ) {
				$GLOBALS['product'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			} else {
				unset( $GLOBALS['product'] );
			}
		}
		return '' === $details ? '' : '<div class="bsn-book-details-wrap">' . $details . '</div>';
	}

	/**
	 * On book pages the details render in the template's slot, not in the summary hook.
	 */
	public static function relocate_book_details(): void {
		if ( class_exists( '\BookstoreNepal\Catalog' ) && function_exists( 'is_product' ) && is_product() ) {
			remove_action( 'woocommerce_single_product_summary', array( \BookstoreNepal\Catalog::class, 'render_details' ), 25 );
		}
	}

	/**
	 * Book page breadcrumb: Home › Ages 4–7 › Title (instead of the product category).
	 *
	 * @param array $crumbs Pairs of [ label, url ].
	 * @return array
	 */
	public static function filter_breadcrumb( $crumbs ): array {
		$crumbs = (array) $crumbs;
		if ( ! function_exists( 'is_product' ) || ! is_product() || count( $crumbs ) < 2 ) {
			return $crumbs;
		}
		$terms = wc_get_product_terms( (int) get_queried_object_id(), 'pa_age-group', array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || ! $terms || ! $terms[0] instanceof \WP_Term ) {
			return $crumbs;
		}
		/* translators: %s: age range, e.g. 4–7. */
		$age_crumb = array( sprintf( __( 'Ages %s', 'bookstore-nepal' ), $terms[0]->name ), self::age_url( $terms[0]->slug ) );
		return array( $crumbs[0], $age_crumb, $crumbs[ count( $crumbs ) - 1 ] );
	}

	/**
	 * Breadcrumb separator per the design.
	 *
	 * @param array $defaults Breadcrumb defaults.
	 * @return array
	 */
	public static function breadcrumb_defaults( $defaults ): array {
		$defaults              = (array) $defaults;
		$defaults['delimiter'] = '<span class="bsn-crumb-sep" aria-hidden="true"> › </span>';
		return $defaults;
	}

	/**
	 * Makes a published "Home" page the static front page (rendered by templates/front-page.html).
	 *
	 * @return int Front page ID, or 0 on failure.
	 */
	public static function ensure_front_page(): int {
		$current = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $current && 'publish' === get_post_status( $current ) ) {
			return $current;
		}
		$page = get_page_by_path( 'home' );
		$id   = ( $page instanceof \WP_Post && 'publish' === $page->post_status ) ? $page->ID : 0;
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => __( 'Home', 'bookstore-nepal' ),
					'post_name'   => 'home',
				)
			);
		}
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $id );
		return (int) $id;
	}

	/**
	 * Inner blocks of a product card (cover, title, language · type, price, add to cart).
	 *
	 * @param int $heading_level Heading level for the book title.
	 */
	public static function card_markup( int $heading_level = 3 ): string {
		$image = serialize_block_attributes(
			array(
				'showSaleBadge'  => true,
				'saleBadgeAlign' => 'right',
				'imageSizing'    => 'thumbnail',
				'aspectRatio'    => '3/4',
				'scale'          => 'cover',
				'className'      => self::CARD_IMAGE_CLASS,
			)
		);
		$title = serialize_block_attributes(
			array(
				'level'                  => $heading_level,
				'isLink'                 => true,
				'className'              => 'bsn-card__title',
				'__woocommerceNamespace' => 'woocommerce/product-collection/product-title',
			)
		);
		return '<!-- wp:woocommerce/product-image ' . $image . ' /-->
<!-- wp:post-title ' . $title . ' /-->
<!-- wp:group {"className":"bsn-card__meta","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-card__meta"><!-- wp:post-terms {"term":"pa_language","className":"bsn-plain-terms"} /-->
<!-- wp:post-terms {"term":"pa_book-type","className":"bsn-plain-terms"} /--></div>
<!-- /wp:group -->
<!-- wp:group {"className":"bsn-card__buy","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-card__buy"><!-- wp:woocommerce/product-price {"className":"bsn-card__price"} /-->
<!-- wp:woocommerce/product-button {"className":"bsn-card__button"} /--></div>
<!-- /wp:group -->';
	}

	/**
	 * A Product Collection block using the shared card.
	 *
	 * @param array $args {
	 *     @type int    $query_id      Unique query ID on the page.
	 *     @type array  $query         Query attribute overrides.
	 *     @type string $collection    Collection name (optional).
	 *     @type int    $heading_level Card title heading level.
	 *     @type bool   $pagination    Whether to render pagination and the no-results message.
	 *     @type string $class         Extra class.
	 *     @type string $slider        Slider key (see slider_labels()); when set the row is a horizontal slider.
	 * }
	 */
	public static function collection_markup( array $args ): string {
		$args  = array_merge(
			array(
				'query_id'      => 0,
				'query'         => array(),
				'collection'    => '',
				'heading_level' => 3,
				'pagination'    => false,
				'class'         => '',
				'slider'        => '',
			),
			$args
		);
		$query = array_merge(
			array(
				'perPage'                       => 8,
				'pages'                         => 1,
				'offset'                        => 0,
				'postType'                      => 'product',
				'order'                         => 'desc',
				'orderBy'                       => 'date',
				'search'                        => '',
				'exclude'                       => array(),
				'inherit'                       => false,
				'taxQuery'                      => new \stdClass(),
				'isProductCollectionBlock'      => true,
				'featured'                      => false,
				'woocommerceOnSale'             => false,
				'woocommerceStockStatus'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'woocommerceAttributes'         => array(),
				'woocommerceHandPickedProducts' => array(),
			),
			$args['query']
		);
		$slider = isset( self::slider_labels()[ $args['slider'] ] ) ? (string) $args['slider'] : '';
		$class  = implode( ' ', array_filter( array( 'bsn-products', $args['class'], '' !== $slider ? 'bsn-slider bsn-slider--' . $slider : '' ) ) );
		$attrs = array(
			'queryId'              => (int) $args['query_id'],
			'query'                => $query,
			'tagName'              => 'div',
			'displayLayout'        => array(
				'type'          => 'flex',
				'columns'       => 4,
				'shrinkColumns' => true,
			),
			'dimensions'           => array( 'widthType' => 'fill' ),
			'queryContextIncludes' => array( 'collection' ),
			'align'                => 'wide',
			'className'            => $class,
		);
		if ( '' !== $args['collection'] ) {
			$attrs['collection'] = $args['collection'];
		}

		$inner = '<!-- wp:woocommerce/product-template -->' . "\n" . self::card_markup( (int) $args['heading_level'] ) . "\n" . '<!-- /wp:woocommerce/product-template -->';
		if ( $args['pagination'] ) {
			$inner .= '
<!-- wp:query-pagination {"className":"bsn-pagination","layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->
<!-- wp:woocommerce/product-collection-no-results -->
<!-- wp:group {"className":"bsn-no-results","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-no-results"><!-- wp:paragraph -->
<p>' . esc_html__( 'No books match these filters yet.', 'bookstore-nepal' ) . ' <a href="' . esc_url( self::shop_url() ) . '">' . esc_html__( 'See all books', 'bookstore-nepal' ) . '</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<!-- /wp:woocommerce/product-collection-no-results -->';
		}

		return '<!-- wp:woocommerce/product-collection ' . serialize_block_attributes( $attrs ) . ' -->
<div class="wp-block-woocommerce-product-collection alignwide ' . esc_attr( $class ) . '">' . $inner . '</div>
<!-- /wp:woocommerce/product-collection -->';
	}
}
