<?php
use BookstoreNepal\Catalog;
use BookstoreNepal\GiftFinder;
use BookstoreNepal\Shipping;
use BookstoreNepal\Theme\Theme;

require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Theme.php';

/**
 * The child theme's helpers and filters. The theme is not activated; its class is loaded directly.
 */
class ThemeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The DB rolls back between tests but registered taxonomies do not.
		foreach ( array( 'age-group', 'language', 'book-type' ) as $slug ) {
			unregister_taxonomy( 'pa_' . $slug );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		Theme::flush_rates();
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_fake_khalti' ) );
		unset( $GLOBALS['product'] );
		Theme::flush_rates();
		parent::tear_down();
		if ( $this->gateways_changed ) {
			// Reload gateways from the rolled-back settings so later tests see the original state.
			$this->fake_khalti      = null;
			$this->gateways_changed = false;
			WC()->payment_gateways()->init();
		}
	}

	private function query( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	private function book( string $name, array $terms ): int {
		Catalog::install();
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '450' );
		$product->update_meta_data( Catalog::META_AUTHOR, 'Devkota' );
		$id = $product->save();
		foreach ( $terms as $taxonomy => $slug ) {
			wp_set_object_terms( $id, array( $slug ), $taxonomy );
		}
		return $id;
	}

	public function test_filter_url_adds_attribute_filter_to_shop(): void {
		$url = Theme::filter_url( 'language', 'nepali' );
		$this->assertStringStartsWith( GiftFinder::shop_url(), $url );
		$q = $this->query( $url );
		$this->assertSame( 'nepali', $q['filter_language'] );
		$this->assertSame( 'or', $q['query_type_language'] );
	}

	public function test_age_url_delegates_to_gift_finder(): void {
		$this->assertSame( GiftFinder::url( '4-7', 1000 ), Theme::age_url( '4-7', 1000 ) );
	}

	public function test_toggle_adds_term_keeps_other_filters_and_drops_pagination(): void {
		$url = Theme::toggle_filter_url( '/shop/page/3/?filter_age-group=4-7&query_type_age-group=or&max_price=500&paged=3', 'age-group', '8-12' );
		$this->assertStringStartsWith( '/shop/?', $url );
		$this->assertSame(
			array(
				'max_price'            => '500',
				'filter_age-group'     => '4-7,8-12',
				'query_type_age-group' => 'or',
			),
			$this->query( $url )
		);
	}

	public function test_toggle_removes_selected_term(): void {
		$url = Theme::toggle_filter_url( '/shop/?filter_age-group=4-7,8-12&query_type_age-group=or', 'age-group', '4-7' );
		$this->assertSame( '8-12', $this->query( $url )['filter_age-group'] );
	}

	public function test_toggle_off_last_term_removes_filter_keys(): void {
		$url = Theme::toggle_filter_url( '/shop/?filter_language=nepali&query_type_language=or&filter_age-group=0-3', 'language', 'nepali' );
		$this->assertSame( array( 'filter_age-group' => '0-3' ), $this->query( $url ) );
	}

	public function test_active_filter_values(): void {
		$this->assertSame( array( '4-7', '8-12' ), Theme::active_filter_values( '/shop/?filter_age-group=4-7,8-12', 'age-group' ) );
		$this->assertSame( array( 'nepali' ), Theme::active_filter_values( '/shop/?filter_language=Nepali,,', 'language' ) );
		$this->assertSame( array(), Theme::active_filter_values( '/shop/?filter_language=', 'language' ) );
		$this->assertSame( array(), Theme::active_filter_values( '/shop/?filter_language[]=x', 'language' ) );
		$this->assertSame( array(), Theme::active_filter_values( '/shop/', 'language' ) );
	}

	public function test_remove_filter_arg_drops_arg_and_pagination(): void {
		$url = Theme::remove_filter_arg( 'http://example.org/shop/page/2/?max_price=500&filter_language=english&product-page=2', 'max_price' );
		$this->assertSame( 'http://example.org/shop/?filter_language=english', $url );
	}

	public function test_clear_filters_url_keeps_search(): void {
		$url = Theme::clear_filters_url( '/page/2/?s=yak&post_type=product&filter_language=english&query_type_language=or&filter_book-type=story&min_price=1&max_price=500' );
		$this->assertSame( '/?s=yak&post_type=product', $url );
	}

	public function test_plain_terms_unlinks_only_marked_blocks(): void {
		$html  = '<div class="wp-block-post-terms"><a href="/x" rel="tag">Nepali</a></div>';
		$plain = Theme::filter_plain_terms( $html, array( 'attrs' => array( 'className' => 'bsn-plain-terms' ) ) );
		$this->assertSame( '<div class="wp-block-post-terms"><span>Nepali</span></div>', $plain );
		$this->assertSame( $html, Theme::filter_plain_terms( $html, array( 'attrs' => array() ) ) );
	}

	public function test_product_image_gets_cover_and_age_badge(): void {
		$id   = $this->book( 'हिमालको कथा', array( 'pa_age-group' => '8-12' ) );
		$html = '<div class="wc-block-components-product-image"><a href="/p"><img src="p.webp" class="woocommerce-placeholder wp-post-image" alt=""></a></div>';
		$out  = Theme::filter_product_image( $html, array( 'attrs' => array( 'productId' => $id, 'className' => Theme::CARD_IMAGE_CLASS ) ) );
		$this->assertStringNotContainsString( 'woocommerce-placeholder', $out );
		$this->assertStringContainsString( 'class="bsn-cover" role="img" aria-label="हिमालको कथा"', $out );
		// The badge sits outside the link so it is read as text, not as part of a second link name.
		$this->assertStringContainsString( '<div class="wc-block-components-product-image"><span class="bsn-age-badge">Ages 8–12</span><a href="/p" aria-hidden="true" tabindex="-1">', $out );
	}

	public function test_card_has_one_link_per_book(): void {
		Theme::register();
		$id   = $this->book( 'Yak', array( 'pa_age-group' => '4-7' ) );
		$html = '';
		// The title block reads the global post, as inside a product collection loop.
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		try {
			foreach ( parse_blocks( Theme::card_markup() ) as $block ) {
				if ( in_array( $block['blockName'], array( 'woocommerce/product-image', 'core/post-title' ), true ) ) {
					$html .= ( new WP_Block( $block, array( 'postId' => $id, 'postType' => 'product' ) ) )->render();
				}
			}
		} finally {
			$GLOBALS['post'] = $previous;
			wp_reset_postdata();
		}
		preg_match_all( '#<a\b[^>]*>#', $html, $links );
		$this->assertCount( 2, $links[0], $html );
		$focusable = array_filter( $links[0], static fn( $a ) => false === strpos( $a, 'tabindex="-1"' ) );
		$this->assertCount( 1, $focusable, 'Only the title link is a tab stop.' );
		$this->assertStringContainsString( 'aria-hidden="true"', implode( '', array_diff( $links[0], $focusable ) ) );
		$this->assertStringContainsString( 'Ages 4–7', $html, 'Badge still shown.' );
		$this->assertDoesNotMatchRegularExpression( '#<a\b[^>]*>\s*<span class="bsn-age-badge">#', $html );
	}

	public function test_product_image_link_outside_cards_stays_focusable(): void {
		$id   = $this->book( 'Yak', array( 'pa_age-group' => '4-7' ) );
		$html = '<div class="wc-block-components-product-image"><a href="/p"><img src="p.webp" alt="Yak"></a></div>';
		$out  = Theme::filter_product_image( $html, array( 'attrs' => array( 'productId' => $id ) ) );
		$this->assertStringContainsString( '<span class="bsn-age-badge">Ages 4–7</span><a href="/p">', $out );
		$this->assertStringNotContainsString( 'tabindex', $out );
	}

	public function test_product_image_without_badge_class_or_product(): void {
		$id   = $this->book( 'B', array( 'pa_age-group' => '4-7' ) );
		$html = '<div><img class="woocommerce-placeholder"></div>';
		$out  = Theme::filter_product_image( $html, array( 'attrs' => array( 'productId' => $id, 'className' => 'bsn-no-badge' ) ) );
		$this->assertStringNotContainsString( 'bsn-age-badge', $out );
		$this->assertStringContainsString( 'bsn-cover', $out );
		$this->assertSame( $html, Theme::filter_product_image( $html, array( 'attrs' => array() ) ) );
	}

	public function test_details_slot_renders_book_details(): void {
		$id = $this->book( 'B', array( 'pa_language' => 'nepali' ) );
		$this->go_to( get_permalink( $id ) );
		$out = Theme::filter_details_slot( '<div class="wp-block-group bsn-book-details-slot"></div>', array( 'attrs' => array( 'className' => 'bsn-book-details-slot' ) ) );
		$this->assertStringStartsWith( '<div class="bsn-book-details-wrap"><ul class="bsn-book-details">', $out );
		$this->assertStringContainsString( 'Devkota', $out );
		$this->assertStringContainsString( 'Nepali', $out );
		$this->assertSame( '<div>x</div>', Theme::filter_details_slot( '<div>x</div>', array( 'attrs' => array() ) ) );
	}

	public function test_details_slot_restores_global_product(): void {
		$id = $this->book( 'B', array( 'pa_language' => 'nepali' ) );
		$this->go_to( get_permalink( $id ) );
		$slot = array( 'attrs' => array( 'className' => 'bsn-book-details-slot' ) );

		unset( $GLOBALS['product'] );
		$this->assertStringContainsString( 'Nepali', Theme::filter_details_slot( '', $slot ) );
		$this->assertArrayNotHasKey( 'product', $GLOBALS );

		$GLOBALS['product'] = 'not a product';
		Theme::filter_details_slot( '', $slot );
		$this->assertSame( 'not a product', $GLOBALS['product'] );
	}

	public function test_breadcrumb_on_book_page_uses_age_group(): void {
		$id = $this->book( 'Yak', array( 'pa_age-group' => '4-7' ) );
		$this->go_to( get_permalink( $id ) );
		$crumbs = array( array( 'Home', 'http://example.org' ), array( 'Uncategorized', 'http://example.org/c' ), array( 'Yak', '' ) );
		$this->assertSame(
			array( array( 'Home', 'http://example.org' ), array( 'Ages 4–7', GiftFinder::url( '4-7', null ) ), array( 'Yak', '' ) ),
			Theme::filter_breadcrumb( $crumbs )
		);
	}

	public function test_breadcrumb_unchanged_elsewhere_or_without_age(): void {
		$crumbs = array( array( 'Home', 'http://example.org' ), array( 'Shop', '' ) );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( $crumbs, Theme::filter_breadcrumb( $crumbs ) );
		$id = $this->book( 'No age', array() );
		$this->go_to( get_permalink( $id ) );
		$crumbs = array( array( 'Home', 'http://example.org' ), array( 'Cat', 'u' ), array( 'No age', '' ) );
		$this->assertSame( $crumbs, Theme::filter_breadcrumb( $crumbs ) );
	}

	public function test_ensure_front_page_creates_home_once(): void {
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		$id = Theme::ensure_front_page();
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'home', get_post_field( 'post_name', $id ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( $id, (int) get_option( 'page_on_front' ) );
		$this->assertSame( $id, Theme::ensure_front_page() );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'page', 'name' => 'home', 'post_status' => 'any' ) ) );
	}

	public function test_ensure_front_page_reuses_published_home_and_keeps_existing_front_page(): void {
		update_option( 'show_on_front', 'posts' );
		$home = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'home', 'post_title' => 'Home' ) );
		$this->assertSame( $home, Theme::ensure_front_page() );

		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Welcome' ) );
		update_option( 'page_on_front', $other );
		$this->assertSame( $other, Theme::ensure_front_page() );
	}

	public function test_delivery_rates_read_plugin_zones(): void {
		Shipping::install( array( 'valley' => 120, 'rest' => 250, 'free_over' => 3000 ) );
		$this->assertSame(
			array(
				'valley'    => 120.0,
				'rest'      => 250.0,
				'free_over' => 3000.0,
			),
			Theme::delivery_rates()
		);
		$this->assertSame( 'NPR 3,000', Theme::npr( Theme::delivery_rates()['free_over'] ) );
	}

	public function test_tracking_url_only_for_published_page(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		update_option( 'bsn_tracking_page_id', $page );
		$this->assertNull( Theme::tracking_url() );
		wp_publish_post( $page );
		$this->assertSame( get_permalink( $page ), Theme::tracking_url() );
	}

	public function test_shortcode_blocks_render_inside_patterns(): void {
		Theme::register();
		GiftFinder::register();
		$html = do_blocks( '<!-- wp:shortcode -->[bsn_gift_finder]<!-- /wp:shortcode -->' );
		$this->assertStringContainsString( '<form class="bsn-gift-finder"', $html );
	}

	/** @var WC_Payment_Gateway|null Stand-in for the Khalti plugin's gateway. */
	private $fake_khalti = null;

	/** @var bool Whether a test reloaded the payment gateways. */
	private $gateways_changed = false;

	public function add_fake_khalti( $gateways ) {
		$gateways[] = $this->fake_khalti;
		return $gateways;
	}

	/**
	 * Configures gateways and reloads them, as on a fresh request.
	 *
	 * @param array       $enabled Gateway id => 'yes'|'no' for esewa and cod.
	 * @param string|null $khalti  'yes'|'no' to register a Khalti gateway, null for not installed.
	 */
	private function payments( array $enabled, ?string $khalti = null ): void {
		$this->gateways_changed = true;
		update_option( 'woocommerce_currency', 'NPR' );
		update_option( 'woocommerce_esewa_settings', array( 'enabled' => $enabled['esewa'] ?? 'no', 'testmode' => 'yes' ) );
		update_option( 'woocommerce_cod_settings', array( 'enabled' => $enabled['cod'] ?? 'no', 'enable_for_methods' => array(), 'enable_for_virtual' => 'no' ) );
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_fake_khalti' ) );
		$this->fake_khalti = null;
		if ( null !== $khalti ) {
			update_option( 'woocommerce_khalti_settings', array( 'enabled' => $khalti ) );
			$this->fake_khalti = new class() extends WC_Payment_Gateway {
				public function __construct() {
					$this->id    = 'khalti';
					$this->title = 'Khalti';
					$this->init_settings();
					$this->enabled = $this->get_option( 'enabled', 'no' );
				}
			};
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_fake_khalti' ) );
		}
		WC()->payment_gateways()->init();
	}

	private function render_pattern( string $slug ): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/patterns/' . $slug . '.php';
		return (string) ob_get_clean();
	}

	public function test_payment_methods_lists_enabled_gateways_in_fixed_order(): void {
		$this->payments( array( 'cod' => 'yes', 'esewa' => 'yes' ), 'yes' );
		$this->assertSame(
			array(
				'esewa'  => 'eSewa',
				'khalti' => 'Khalti',
				'cod'    => 'Cash on delivery',
			),
			Theme::payment_methods()
		);
		$this->assertSame( 'eSewa, Khalti and cash on delivery', Theme::payment_list() );
		$this->assertSame( 'eSewa, Khalti or cash on delivery', Theme::payment_list( 'or' ) );
	}

	public function test_payment_methods_hide_disabled_or_missing_gateways(): void {
		$this->payments( array( 'cod' => 'yes', 'esewa' => 'yes' ), 'no' );
		$this->assertSame( array( 'esewa', 'cod' ), array_keys( Theme::payment_methods() ) );
		$this->assertSame( 'eSewa or cash on delivery', Theme::payment_list( 'or' ) );

		$this->payments( array( 'cod' => 'yes' ) );
		$this->assertSame( array( 'cod' ), array_keys( Theme::payment_methods() ) );
		$this->assertSame( 'cash on delivery', Theme::payment_list() );
	}

	public function test_payment_methods_skip_esewa_that_cannot_take_payments(): void {
		$this->payments( array( 'cod' => 'yes', 'esewa' => 'yes' ) );
		update_option( 'woocommerce_esewa_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'product_code' => '', 'secret_key' => '' ) );
		WC()->payment_gateways()->init();
		$this->assertSame( array( 'cod' ), array_keys( Theme::payment_methods() ) );
	}

	public function test_payment_methods_empty_when_none_enabled(): void {
		$this->payments( array() );
		$this->assertSame( array(), Theme::payment_methods() );
		$this->assertSame( '', Theme::payment_list() );
	}

	public function test_payment_copy_in_patterns_follows_enabled_gateways(): void {
		$this->payments( array( 'cod' => 'yes', 'esewa' => 'yes' ), 'no' );
		foreach ( array( 'trust-strip', 'book-delivery', 'footer-payments' ) as $slug ) {
			$html = $this->render_pattern( $slug );
			$this->assertStringNotContainsString( 'Khalti', $html, $slug );
			$this->assertStringContainsString( 'eSewa', $html, $slug );
		}
		$this->assertStringContainsString( 'We accept eSewa and cash on delivery.', $this->render_pattern( 'footer-payments' ) );
		$this->assertStringContainsString( 'Pay with eSewa or cash on delivery', $this->render_pattern( 'book-delivery' ) );
		$this->assertStringContainsString( 'Delivery all over Nepal', $this->render_pattern( 'trust-strip' ) );

		$this->payments( array() );
		$this->assertStringNotContainsString( 'We accept', $this->render_pattern( 'footer-payments' ) );
		$this->assertStringNotContainsString( 'Pay with', $this->render_pattern( 'book-delivery' ) );
		$this->assertStringNotContainsString( 'Pay with', $this->render_pattern( 'trust-strip' ) );
		$this->assertStringNotContainsString( 'Cash on delivery', $this->render_pattern( 'trust-strip' ) );
	}

	public function test_translations_with_quotes_keep_pattern_block_attributes_valid(): void {
		$tricky = static fn( $translation, $text, $domain ) => 'bookstore-nepal' === $domain ? $text . ' "x" \\ y' : $translation;
		add_filter( 'gettext', $tricky, 10, 3 );
		$attrs = array();
		foreach ( array( 'hero', 'header-menu' ) as $slug ) {
			foreach ( parse_blocks( $this->render_pattern( $slug ) ) as $block ) {
				$this->collect_attrs( $block, $attrs );
			}
		}
		remove_filter( 'gettext', $tricky, 10 );
		$this->assertSame( 'Search books "x" \\ y', $attrs['core/search']['label'] );
		$this->assertSame( 'Search by title, author or topic "x" \\ y', $attrs['core/search']['placeholder'] );
		$this->assertSame( 'Main "x" \\ y', $attrs['core/navigation']['ariaLabel'] );
	}

	/**
	 * Block name => attributes, for a block and its inner blocks (first of each name wins).
	 */
	private function collect_attrs( array $block, array &$attrs ): void {
		if ( $block['blockName'] && ! isset( $attrs[ $block['blockName'] ] ) ) {
			$attrs[ $block['blockName'] ] = $block['attrs'];
		}
		foreach ( $block['innerBlocks'] as $inner ) {
			$this->collect_attrs( $inner, $attrs );
		}
	}

	public function test_template_copy_is_translatable(): void {
		$theme = dirname( __DIR__, 2 ) . '/themes/bookstore-nepal';
		$files = array( 'templates/single-product.html', 'parts/footer.html', 'parts/header.html' );
		$html  = implode( "\n", array_map( static fn( $f ) => (string) file_get_contents( $theme . '/' . $f ), $files ) );
		foreach ( array( 'About this book', 'Contact us', 'Children', '"prefix":"Ages', 'Search books', 'Search by title' ) as $english ) {
			$this->assertStringNotContainsString( $english, $html, 'Hard-coded copy belongs in a pattern: ' . $english );
		}

		$mark   = static fn( $translation, $text, $domain ) => 'bookstore-nepal' === $domain ? '[t]' . $text : $translation;
		$mark_x  = static fn( $translation, $text, $context, $domain ) => $mark( $translation, $text, $domain );
		add_filter( 'gettext', $mark, 10, 3 );
		add_filter( 'gettext_with_context', $mark_x, 10, 4 );
		$expected = array(
			'book-chips'             => '"prefix":"[t]Ages "',
			'book-about'             => '[t]About this book',
			'footer-about'           => '[t]Children&#039;s books in Nepali and English',
			'footer-contact-heading' => '[t]Contact us',
			'header-search'          => '"placeholder":"[t]Search by title, author or topic"',
		);
		$rendered = array();
		foreach ( array_keys( $expected ) as $slug ) {
			$rendered[ $slug ] = $this->render_pattern( $slug );
		}
		remove_filter( 'gettext', $mark, 10 );
		remove_filter( 'gettext_with_context', $mark_x, 10 );
		foreach ( $expected as $slug => $needle ) {
			$this->assertStringContainsString( $needle, $rendered[ $slug ], $slug );
			$this->assertStringContainsString( '<!-- wp:pattern {"slug":"bookstore-nepal/' . $slug . '"} /-->', $html, $slug );
		}
	}

	public function test_footer_uses_payments_pattern_not_static_copy(): void {
		$footer = (string) file_get_contents( dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/parts/footer.html' );
		$this->assertStringContainsString( '<!-- wp:pattern {"slug":"bookstore-nepal/footer-payments"} /-->', $footer );
		$this->assertStringNotContainsString( 'Khalti', $footer );
	}
}
