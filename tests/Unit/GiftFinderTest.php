<?php
use BookstoreNepal\GiftFinder;

class GiftFinderTest extends WP_UnitTestCase {

	private function parts( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	public function test_url_with_age_and_budget(): void {
		$url = GiftFinder::url( '4-7', 1000 );
		$this->assertStringStartsWith( wc_get_page_permalink( 'shop' ), $url );
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
		$this->assertSame( wc_get_page_permalink( 'shop' ), GiftFinder::url( 'bogus', 500 ) );
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
		$this->assertSame( wc_get_page_permalink( 'shop' ), GiftFinder::target_from_request( array( 'bsn_gift_age' => 'x', 'bsn_gift_budget' => '500' ) ) );
		$this->assertNull( GiftFinder::target_from_request( array() ) );
	}
}
