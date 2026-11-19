<?php
use BookstoreNepal\Catalog;

class CatalogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The DB rolls back between tests but registered taxonomies do not.
		foreach ( array( 'age-group', 'language', 'book-type' ) as $slug ) {
			unregister_taxonomy( 'pa_' . $slug );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	}

	public function test_install_creates_three_global_attributes(): void {
		Catalog::install();
		$expected = array(
			'age-group' => array( '0–3', '4–7', '8–12' ),
			'language'  => array( 'Nepali', 'English' ),
			'book-type' => array( 'Picture book', 'Story', 'Activity', 'Educational' ),
		);
		foreach ( $expected as $slug => $terms ) {
			$this->assertGreaterThan( 0, wc_attribute_taxonomy_id_by_name( $slug ), $slug );
			$names = get_terms( array( 'taxonomy' => 'pa_' . $slug, 'hide_empty' => false, 'fields' => 'names' ) );
			sort( $names );
			sort( $terms );
			$this->assertSame( $terms, $names, $slug );
		}
		$this->assertNotWPError( get_term_by( 'slug', '0-3', 'pa_age-group' ) ?: new WP_Error( 'x' ) );
		$this->assertNotFalse( get_term_by( 'slug', '8-12', 'pa_age-group' ) );
	}

	public function test_install_is_idempotent(): void {
		Catalog::install();
		Catalog::install();
		$count = 0;
		foreach ( array( 'age-group', 'language', 'book-type' ) as $slug ) {
			$this->assertGreaterThan( 0, wc_attribute_taxonomy_id_by_name( $slug ) );
			$count += count( get_terms( array( 'taxonomy' => 'pa_' . $slug, 'hide_empty' => false, 'fields' => 'ids' ) ) );
		}
		$this->assertSame( 9, $count );
		$ours = array_filter(
			wc_get_attribute_taxonomies(),
			static fn( $a ) => in_array( $a->attribute_name, array( 'age-group', 'language', 'book-type' ), true )
		);
		$this->assertCount( 3, $ours );
	}

	public function test_page_count_and_author_saved(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Book' );
		$product->update_meta_data( Catalog::META_PAGES, 32 );
		$product->update_meta_data( Catalog::META_AUTHOR, 'लक्ष्मीप्रसाद देवकोटा' );
		$id = $product->save();
		$fresh = wc_get_product( $id );
		$this->assertSame( '32', (string) $fresh->get_meta( Catalog::META_PAGES ) );
		$this->assertSame( 'लक्ष्मीप्रसाद देवकोटा', $fresh->get_meta( Catalog::META_AUTHOR ) );
	}

	public function test_save_sanitizes_posted_fields(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$_POST[ Catalog::META_PAGES ]  = '-5';
		$_POST[ Catalog::META_AUTHOR ] = '<b>Name</b>';
		Catalog::save_fields( $id );
		$p = wc_get_product( $id );
		$this->assertSame( '', (string) $p->get_meta( Catalog::META_PAGES ) );
		$this->assertSame( 'Name', $p->get_meta( Catalog::META_AUTHOR ) );
		$_POST[ Catalog::META_PAGES ] = '48';
		Catalog::save_fields( $id );
		$this->assertSame( '48', (string) wc_get_product( $id )->get_meta( Catalog::META_PAGES ) );
		unset( $_POST[ Catalog::META_PAGES ], $_POST[ Catalog::META_AUTHOR ] );
	}

	public function test_devanagari_title_is_searchable(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'मुनामदन' );
		$product->set_status( 'publish' );
		$id = $product->save();
		$found = wc_get_products( array( 's' => 'मुनामदन', 'return' => 'ids' ) );
		$this->assertContains( $id, $found );
		$link = get_permalink( $id );
		$this->assertNotEmpty( $link );
		$this->assertTrue( mb_check_encoding( $link, 'UTF-8' ) );
	}
}
