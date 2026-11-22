<?php
use BookstoreNepal\GiftFinder;

class GiftFinderTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	/** @var array|null Product post type archive settings before enable_product_archive(). */
	private $saved_archive = null;

	public function set_up(): void {
		parent::set_up();
		$this->save_permalink_structure();
		$this->save_shortcodes();
	}

	public function tear_down(): void {
		if ( null !== $this->saved_archive ) {
			global $wp_post_types;
			$wp_post_types['product']->has_archive = $this->saved_archive['has_archive'];
			$wp_post_types['product']->rewrite     = $this->saved_archive['rewrite'];
			$this->saved_archive                   = null;
		}
		$this->restore_permalink_structure();
		$this->restore_shortcodes();
		parent::tear_down();
	}

	private function parts( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	public function test_url_with_age_and_budget(): void {
		$url = GiftFinder::url( '4-7', 1000 );
		$this->assertStringStartsWith( GiftFinder::shop_url(), $url );
		$this->assertSame(
			array(
				'filter_age-group'     => '4-7',
				'query_type_age-group' => 'or',
				'max_price'            => '1000',
			),
			$this->parts( $url )
		);
	}

	public function test_url_without_budget(): void {
		$q = $this->parts( GiftFinder::url( '8-12', null ) );
		$this->assertSame( '8-12', $q['filter_age-group'] );
		$this->assertArrayNotHasKey( 'max_price', $q );
	}

	public function test_invalid_age_falls_back_to_shop(): void {
		$this->assertSame( GiftFinder::shop_url(), GiftFinder::url( 'bogus', 500 ) );
	}

	public function test_shortcode_renders_all_age_options(): void {
		GiftFinder::register();
		$html = do_shortcode( '[bsn_gift_finder]' );
		foreach ( array( '0-3', '4-7', '8-12' ) as $slug ) {
			$this->assertStringContainsString( 'value="' . $slug . '"', $html );
		}
		$this->assertStringContainsString( 'Under NPR 1,000', $html );
		$this->assertStringContainsString( 'Any budget', $html );
		$this->assertStringContainsString( 'for="bsn_gift_age"', $html );
		$this->assertStringContainsString( 'Show gift ideas', $html );
	}

	public function test_target_from_request(): void {
		$t = GiftFinder::target_from_request( array( 'bsn_gift_age' => '4-7', 'bsn_gift_budget' => '500' ) );
		$this->assertSame( GiftFinder::url( '4-7', 500 ), $t );
		$this->assertSame( GiftFinder::url( '4-7', null ), GiftFinder::target_from_request( array( 'bsn_gift_age' => '4-7', 'bsn_gift_budget' => '999' ) ) );
		$this->assertSame( GiftFinder::shop_url(), GiftFinder::target_from_request( array( 'bsn_gift_age' => 'x', 'bsn_gift_budget' => '500' ) ) );
		$this->assertNull( GiftFinder::target_from_request( array() ) );
	}

	/**
	 * WooCommerce only enables the product archive when a shop page exists at init; emulate that.
	 */
	private function enable_product_archive(): void {
		global $wp_post_types;
		$this->saved_archive                   = array(
			'has_archive' => $wp_post_types['product']->has_archive,
			'rewrite'     => $wp_post_types['product']->rewrite,
		);
		$wp_post_types['product']->has_archive = 'shop';
		$wp_post_types['product']->rewrite     = array( 'slug' => 'shop', 'with_front' => false, 'feeds' => true, 'pages' => true, 'ep_mask' => EP_PERMALINK );
	}

	public function test_url_under_plain_permalinks(): void {
		$this->enable_product_archive();
		$this->set_permalink_structure( '' );
		$url = GiftFinder::url( '4-7', 500 );
		$q   = $this->parts( $url );
		$this->assertArrayNotHasKey( 'page_id', $q );
		$this->assertSame( 'product', $q['post_type'] );
		$this->assertSame( '4-7', $q['filter_age-group'] );
		$this->assertSame( 'or', $q['query_type_age-group'] );
		$this->assertSame( '500', $q['max_price'] );
	}

	public function test_url_under_pretty_permalinks(): void {
		$this->enable_product_archive();
		$this->set_permalink_structure( '/%postname%/' );
		$url = GiftFinder::url( '4-7', 500 );
		$this->assertSame( wp_parse_url( get_post_type_archive_link( 'product' ), PHP_URL_PATH ), wp_parse_url( $url, PHP_URL_PATH ) );
		$q = $this->parts( $url );
		$this->assertArrayNotHasKey( 'post_type', $q );
		$this->assertSame( '4-7', $q['filter_age-group'] );
		$this->assertSame( '500', $q['max_price'] );
	}
}
