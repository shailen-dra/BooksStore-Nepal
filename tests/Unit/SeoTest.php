<?php
use BookstoreNepal\Catalog;
use BookstoreNepal\Seo;

/**
 * Page descriptions, link-preview tags, archive canonicals, robots and sitemap filters.
 */
class SeoTest extends WP_UnitTestCase {
	use RestoresGlobalState;

	public function set_up(): void {
		parent::set_up();
		$this->save_attribute_taxonomies();
		$this->save_permalink_structure();
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		$this->restore_permalink_structure();
		$this->restore_attribute_taxonomies();
		parent::tear_down();
	}

	private function book( array $props = array(), string $age = '' ): WC_Product_Simple {
		Catalog::install();
		$product = new WC_Product_Simple();
		$product->set_name( $props['name'] ?? 'Rani and the River Kite' );
		$product->set_regular_price( '450' );
		$product->set_short_description( $props['short'] ?? '' );
		$product->set_description( $props['long'] ?? '' );
		$product->update_meta_data( Catalog::META_AUTHOR, $props['author'] ?? '' );
		$product->save();
		if ( '' !== $age ) {
			wp_set_object_terms( $product->get_id(), array( $age ), 'pa_age-group' );
		}
		return $product;
	}

	private function head(): string {
		ob_start();
		Seo::print_head();
		return (string) ob_get_clean();
	}

	public function test_product_description_prefers_short_description_as_plain_text(): void {
		$book = $this->book( array( 'short' => '<p>A <strong>kite</strong> &amp; a river.</p>', 'long' => 'Long text' ) );
		$this->assertSame( 'A kite & a river.', Seo::product_description( $book ) );
	}

	public function test_product_description_falls_back_to_long_description(): void {
		$book = $this->book( array( 'long' => '<p>The long story.</p>' ) );
		$this->assertSame( 'The long story.', Seo::product_description( $book ) );
	}

	public function test_product_description_is_built_from_details_when_empty(): void {
		$book = $this->book( array( 'author' => 'Devkota' ), '4-7' );
		$this->assertSame( 'Rani and the River Kite by Devkota — a children’s book for ages 4–7. Delivered all over Nepal.', Seo::product_description( $book ) );
	}

	public function test_trim_cuts_long_text_at_a_word_with_an_ellipsis(): void {
		$text = Seo::trim( str_repeat( 'Picture books for children. ', 12 ) );
		$this->assertLessThanOrEqual( Seo::MAX_LENGTH, mb_strlen( $text ) );
		$this->assertStringEndsWith( 'books…', $text, 'Cut at a whole word.' );
		$this->assertSame( 'Short text.', Seo::trim( "  Short\n text. " ) );
	}

	public function test_trim_counts_nepali_characters_not_bytes(): void {
		$text = str_repeat( 'हिमालको ', 18 ); // 144 characters, far more bytes.
		$this->assertSame( trim( $text ), Seo::trim( $text ) );
	}

	public function test_term_description_names_age_group_when_term_has_none(): void {
		Catalog::install();
		$term = get_term_by( 'slug', '4-7', 'pa_age-group' );
		$this->assertSame( 'Children’s books: ages 4–7. Delivered all over Nepal.', Seo::term_description( $term ) );
		wp_update_term( $term->term_id, 'pa_age-group', array( 'description' => 'Stories for early readers.' ) );
		$this->assertSame( 'Stories for early readers.', Seo::term_description( get_term( $term->term_id ) ) );
	}

	public function test_post_description_uses_excerpt_then_body_without_headings(): void {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:heading --><h2>Who we are</h2><!-- /wp:heading --><!-- wp:paragraph --><p>We are a family bookshop in Kathmandu.</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertSame( 'We are a family bookshop in Kathmandu.', Seo::post_description( $page ) );
		$page->post_excerpt = 'Hand-written summary.';
		$this->assertSame( 'Hand-written summary.', Seo::post_description( $page ) );
	}

	public function test_post_description_keeps_a_space_between_blocks(): void {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_excerpt' => '',
				'post_content' => '<details><summary>How long does delivery take?</summary><p>Two to five days.</p></details><ul><li>One</li><li>Two<br>lines</li></ul>',
			)
		);
		$this->assertSame( 'How long does delivery take? Two to five days. One Two lines', Seo::post_description( $page ) );
	}

	public function test_book_page_head_has_description_and_preview_tags(): void {
		$book = $this->book( array( 'short' => 'A "kite" story.' ) );
		$this->go_to( get_permalink( $book->get_id() ) );
		$head = $this->head();
		$this->assertStringContainsString( '<meta name="description" content="A &quot;kite&quot; story." />', $head );
		$this->assertStringContainsString( '<meta property="og:type" content="product" />', $head );
		$this->assertStringContainsString( '<meta property="og:url" content="' . get_permalink( $book->get_id() ) . '" />', $head );
		$this->assertStringContainsString( '<meta property="og:title" content="Rani and the River Kite', $head );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary" />', $head );
		$this->assertStringNotContainsString( 'rel="canonical"', $head, 'WordPress prints the canonical for single pages itself.' );
	}

	public function test_book_image_becomes_the_preview_image(): void {
		$book  = $this->book();
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $book->get_id() );
		$book->set_image_id( $image );
		$book->save();
		$this->go_to( get_permalink( $book->get_id() ) );
		$head = $this->head();
		$this->assertStringContainsString( '<meta property="og:image" content="' . wp_get_attachment_image_url( $image, 'full' ) . '" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $head );
	}

	public function test_front_page_head_falls_back_to_tagline(): void {
		update_option( 'blogdescription', 'Children’s books delivered all over Nepal' );
		$this->go_to( home_url( '/' ) );
		$head = $this->head();
		$this->assertStringContainsString( '<meta name="description" content="Children’s books delivered all over Nepal" />', $head );
		$this->assertStringContainsString( '<meta property="og:url" content="' . home_url( '/' ) . '" />', $head );
	}

	public function test_shop_canonical_drops_filters_and_keeps_page_number(): void {
		$this->book();
		update_option( 'woocommerce_shop_page_id', self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'shop' ) ) );
		flush_rewrite_rules();
		$shop = wc_get_page_permalink( 'shop' );
		$this->go_to( add_query_arg( array( 'filter_age-group' => '4-7', 'orderby' => 'price' ), $shop ) );
		$this->assertSame( $shop, Seo::archive_canonical() );
		$this->assertStringContainsString( '<link rel="canonical" href="' . $shop . '" />', $this->head() );
		set_query_var( 'paged', 2 );
		$this->assertSame( $shop . 'page/2/', Seo::archive_canonical() );
	}

	public function test_no_archive_canonical_on_single_pages(): void {
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		$this->assertNull( Seo::archive_canonical() );
	}

	public function test_default_category_and_author_pages_are_noindex(): void {
		$book    = $this->book();
		$default = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Placeholder category' ) );
		update_option( 'default_product_cat', $default );
		wp_set_object_terms( $book->get_id(), array( $default ), 'product_cat' );
		$this->go_to( get_term_link( $default, 'product_cat' ) );
		$this->assertTrue( Seo::filter_robots( array() )['noindex'] ?? false );

		$cat = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Picture books' ) );
		wp_set_object_terms( $book->get_id(), array( $cat ), 'product_cat' );
		$this->go_to( get_term_link( $cat, 'product_cat' ) );
		$this->assertArrayNotHasKey( 'noindex', Seo::filter_robots( array() ) );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $author ) );
		$this->go_to( get_author_posts_url( $author ) );
		$this->assertTrue( Seo::filter_robots( array() )['noindex'] ?? false );
	}

	public function test_sitemap_leaves_out_users_and_default_category(): void {
		$this->assertFalse( Seo::filter_sitemap_providers( 'provider', 'users' ) );
		$this->assertSame( 'provider', Seo::filter_sitemap_providers( 'provider', 'posts' ) );

		$default = 77;
		update_option( 'default_product_cat', $default );
		$args    = Seo::filter_sitemap_terms( array( 'exclude' => array( 5 ) ), 'product_cat' );
		$this->assertSame( array( 5, $default ), $args['exclude'] );
		$this->assertSame( array(), Seo::filter_sitemap_terms( array(), 'pa_age-group' ) );
	}

	public function test_hooks_are_registered_without_an_seo_plugin(): void {
		$this->assertFalse( Seo::seo_plugin_active() );
		$this->assertNotFalse( has_action( 'wp_head', array( Seo::class, 'print_head' ) ) );
		$this->assertNotFalse( has_filter( 'wp_robots', array( Seo::class, 'filter_robots' ) ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_add_provider', array( Seo::class, 'filter_sitemap_providers' ) ) );
	}
}
