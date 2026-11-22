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
		unset( $GLOBALS['product'] );
		Theme::flush_rates();
		parent::tear_down();
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
		$out  = Theme::filter_product_image( $html, array( 'attrs' => array( 'productId' => $id ) ) );
		$this->assertStringNotContainsString( 'woocommerce-placeholder', $out );
		$this->assertStringContainsString( 'class="bsn-cover" role="img" aria-label="हिमालको कथा"', $out );
		$this->assertStringContainsString( '<a href="/p"><span class="bsn-age-badge">Ages 8–12</span>', $out );
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
}
