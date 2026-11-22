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

	private function silence_error_log(): void {
		$old = ini_get( 'error_log' );
		ini_set( 'error_log', sys_get_temp_dir() . '/bsn-test-error.log' ); // phpcs:ignore
		$this->silenced = $old;
	}

	public function tear_down(): void {
		if ( null !== $this->silenced ) {
			ini_set( 'error_log', (string) $this->silenced ); // phpcs:ignore
			$this->silenced = null;
		}
		parent::tear_down();
	}

	/** @var string|false|null */
	private $silenced = null;

	public function test_install_returns_empty_array_on_success(): void {
		$this->assertSame( array(), Catalog::install() );
	}

	public function test_install_reports_attribute_creation_failure(): void {
		$this->silence_error_log();
		// A taxonomy already registered without a DB row makes wc_create_attribute fail.
		register_taxonomy( 'pa_language', array( 'product' ) );
		$errors = Catalog::install();
		$this->assertNotEmpty( $errors );
		$this->assertContainsOnly( 'string', $errors );
		$this->assertStringContainsString( 'language', implode( ' ', $errors ) );
	}

	public function test_install_reports_term_insert_failure(): void {
		$this->silence_error_log();
		$fail = static fn( $term ) => 'Story' === $term ? new WP_Error( 'forced', 'forced failure' ) : $term;
		add_filter( 'pre_insert_term', $fail );
		$errors = Catalog::install();
		remove_filter( 'pre_insert_term', $fail );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Story', $errors[0] );
		$this->assertStringContainsString( 'forced failure', $errors[0] );
	}

	public function test_details_hook_registered_at_priority_25(): void {
		Catalog::register();
		$this->assertSame( 25, has_action( 'woocommerce_single_product_summary', array( Catalog::class, 'render_details' ) ) );
	}

	private function render_for( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		ob_start();
		Catalog::render_details();
		return ob_get_clean();
	}

	public function test_render_details_outputs_escaped_values(): void {
		Catalog::install();
		$product = new WC_Product_Simple();
		$product->set_name( 'B' );
		$product->update_meta_data( Catalog::META_AUTHOR, 'Devkota' );
		$product->update_meta_data( Catalog::META_PAGES, '32' );
		$id = $product->save();
		wp_set_object_terms( $id, array( '4-7' ), 'pa_age-group' );
		wp_set_object_terms( $id, array( 'nepali' ), 'pa_language' );
		$html = $this->render_for( wc_get_product( $id ) );
		foreach ( array( 'Devkota', '4–7', 'Nepali', '32' ) as $v ) {
			$this->assertStringContainsString( $v, $html );
		}
	}

	public function test_render_details_empty_when_no_values(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Empty' );
		$product->save();
		$this->assertSame( '', $this->render_for( $product ) );
	}

	private function book_by( string $title, string $author, string $type = 'product' ): int {
		if ( 'product' !== $type ) {
			$id = self::factory()->post->create( array( 'post_title' => $title, 'post_type' => $type ) );
			update_post_meta( $id, Catalog::META_AUTHOR, $author );
			return $id;
		}
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->update_meta_data( Catalog::META_AUTHOR, $author );
		return $product->save();
	}

	private function search_ids( string $term, $post_type = 'product' ): array {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				's'              => $term,
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		return array_map( 'intval', $query->posts );
	}

	public function test_product_search_matches_devanagari_author(): void {
		$id    = $this->book_by( 'मुनामदन', 'लक्ष्मीप्रसाद देवकोटा' );
		$other = $this->book_by( 'Other book', 'Someone Else' );
		$found = $this->search_ids( 'देवकोटा' );
		$this->assertContains( $id, $found );
		$this->assertNotContains( $other, $found );
	}

	public function test_product_search_matches_partial_and_full_author_name(): void {
		$id = $this->book_by( 'Mountain Tales', 'Aarav Sharma' );
		$this->assertContains( $id, $this->search_ids( 'Aarav Sharma' ) );
		$this->assertContains( $id, $this->search_ids( 'Sharma' ) );
		$this->assertContains( $id, $this->search_ids( 'aarav' ), 'Case-insensitive partial match.' );
		$this->assertContains( $id, wc_get_products( array( 's' => 'Sharma', 'return' => 'ids' ) ) );
	}

	public function test_product_search_still_matches_title_and_not_unrelated_authors(): void {
		$id = $this->book_by( 'Mountain Tales', 'Aarav Sharma' );
		$this->assertContains( $id, $this->search_ids( 'Mountain' ) );
		$this->assertSame( array(), $this->search_ids( 'Mira Rai' ) );
	}

	public function test_product_search_matches_author_alongside_other_words(): void {
		$poems   = $this->book_by( 'Poems for Children', 'Laxmi Prasad Devkota' );
		$stories = $this->book_by( 'Muna Madan', 'Laxmi Prasad Devkota' );
		$other   = $this->book_by( 'Poems of the Hills', 'Someone Else' );
		$found   = $this->search_ids( 'Devkota poems' );
		$this->assertContains( $poems, $found );
		$this->assertNotContains( $other, $found, 'Every word must match the author, title or description.' );
		$this->assertNotContains( $stories, $found );
		$this->assertContains( $poems, $this->search_ids( 'Laxmi Devkota' ), 'Author words need not be adjacent.' );
		$this->assertContains( $stories, $this->search_ids( 'Devkota -poems' ) );
		$this->assertNotContains( $poems, $this->search_ids( 'Devkota -poems' ), 'Excluded words still exclude.' );
	}

	public function test_product_search_matches_devanagari_author_alongside_other_words(): void {
		$poems = $this->book_by( 'बालबालिकाका कविता', 'लक्ष्मीप्रसाद देवकोटा' );
		$other = $this->book_by( 'पहाडका कविता', 'अर्को लेखक' );
		$found = $this->search_ids( 'देवकोटा कविता' );
		$this->assertContains( $poems, $found );
		$this->assertNotContains( $other, $found );
	}

	public function test_author_search_escapes_like_wildcards_and_quotes(): void {
		$id = $this->book_by( 'Irish Tales', "Siobhan O'Brien" );
		$this->assertContains( $id, $this->search_ids( "O'Brien" ) );
		$this->assertNotContains( $id, $this->search_ids( 'O_Brien' ), 'Underscore is literal, not a wildcard.' );
		$this->assertNotContains( $id, $this->search_ids( 'Siobhan%Brien' ), 'Percent is literal, not a wildcard.' );
	}

	public function test_author_search_only_applies_to_product_queries(): void {
		$post_id = $this->book_by( 'A blog post', 'Mira Rai', 'post' );
		$this->assertNotContains( $post_id, $this->search_ids( 'Mira Rai', 'post' ) );
	}

	public function test_author_search_not_applied_in_admin(): void {
		$id = $this->book_by( 'Mountain Tales', 'Mira Rai' );
		set_current_screen( 'edit-product' );
		try {
			$this->assertNotContains( $id, $this->search_ids( 'Mira Rai' ) );
		} finally {
			set_current_screen( 'front' );
		}
	}

	public function test_render_details_escapes_author(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'X' );
		$product->update_meta_data( Catalog::META_AUTHOR, '<script>alert(1)</script>' );
		$product->save();
		$html = $this->render_for( $product );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
