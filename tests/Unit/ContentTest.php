<?php
use BookstoreNepal\Content\Content;
use BookstoreNepal\Content\Fields;
use BookstoreNepal\StorePages;

/**
 * Safe readers for the client-editable wording (ACF fields; stubbed in tests/Support/acf-stubs.php).
 */
class ContentTest extends WP_UnitTestCase {

	private int $front = 0;

	public function set_up(): void {
		parent::set_up();
		$this->front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->front );
		foreach ( StorePages::definitions() as $def ) {
			delete_option( $def['option'] );
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'bsn_acf_active' );
		parent::tear_down();
	}

	private function store_page( string $key, string $status = 'publish' ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => $status, 'post_content' => StorePages::pattern_block( $key ) ) );
		update_option( StorePages::definitions()[ $key ]['option'], $id );
		return $id;
	}

	public function test_text_falls_back_when_empty_whitespace_or_acf_inactive(): void {
		$this->assertSame( 'Default', Content::text( 'bsn_hero_headline', 'Default' ) );
		update_field( 'bsn_hero_headline', '   ', $this->front );
		$this->assertSame( 'Default', Content::text( 'bsn_hero_headline', 'Default' ) );
		update_field( 'bsn_hero_headline', 'Hello', $this->front );
		$this->assertSame( 'Hello', Content::text( 'bsn_hero_headline', 'Default' ) );
		add_filter( 'bsn_acf_active', '__return_false' );
		$this->assertSame( 'Default', Content::text( 'bsn_hero_headline', 'Default' ) );
	}

	public function test_unknown_field_returns_default(): void {
		$this->assertSame( 'Default', Content::text( 'bsn_no_such_field', 'Default' ) );
	}

	public function test_missing_front_page_or_deleted_store_page_returns_default(): void {
		update_field( 'bsn_hero_headline', 'Hello', $this->front );
		update_option( 'show_on_front', 'posts' );
		$this->assertSame( 'Default', Content::text( 'bsn_hero_headline', 'Default' ) );

		$about = $this->store_page( 'about', 'trash' );
		update_field( 'bsn_about_text', '<p>Mine</p>', $about );
		$this->assertStringContainsString( 'Default', Content::rich( 'bsn_about_text', '<p>Default</p>' ) );
		update_option( StorePages::definitions()['about']['option'], 999999 );
		$this->assertStringContainsString( 'Default', Content::rich( 'bsn_about_text', '<p>Default</p>' ) );
	}

	public function test_rich_strips_dangerous_markup(): void {
		$about = $this->store_page( 'about' );
		update_field( 'bsn_about_text', '<p>Hi <strong>there</strong></p><script>x()</script><p onclick="y()">Ok</p><iframe src="//e.com"></iframe>', $about );
		$html = Content::rich( 'bsn_about_text', '' );
		$this->assertStringContainsString( '<strong>there</strong>', $html );
		$this->assertStringContainsString( 'Ok', $html );
		$this->assertStringNotContainsString( 'script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'iframe', $html );
	}

	public function test_clean_html_drops_script_and_style_with_their_contents(): void {
		$html = Content::clean_html( '<p>Keep</p><script>alert(1)</script><style>body{display:none}</style><p>Also</p>' );
		$this->assertStringContainsString( '<p>Keep</p>', $html );
		$this->assertStringContainsString( '<p>Also</p>', $html );
		$this->assertStringNotContainsString( 'alert', $html );
		$this->assertStringNotContainsString( 'display:none', $html );
	}

	public function test_clean_html_drops_inline_styles_but_keeps_other_attributes(): void {
		$html = Content::clean_html( '<p style="position:fixed;top:0">Hi <a href="https://example.com/" style="color:red">link</a> <span class="bsn-placeholder" style="font-size:40px">x</span></p>' );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringContainsString( 'href="https://example.com/"', $html );
		$this->assertStringContainsString( 'class="bsn-placeholder"', $html );
	}

	public function test_rich_uses_clean_html(): void {
		$about = $this->store_page( 'about' );
		update_field( 'bsn_about_text', '<p style="color:red">Hi</p><script>bad()</script>', $about );
		$html = Content::rich( 'bsn_about_text', '' );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( 'bad()', $html );
	}

	public function test_rich_adds_paragraphs_to_plain_lines(): void {
		$about = $this->store_page( 'about' );
		update_field( 'bsn_about_text', "One\n\nTwo", $about );
		$html = Content::rich( 'bsn_about_text', '' );
		$this->assertStringContainsString( '<p>One</p>', $html );
		$this->assertStringContainsString( '<p>Two</p>', $html );
	}

	public function test_rows_skip_incomplete_and_fall_back_when_none_usable(): void {
		$faq      = $this->store_page( 'faq' );
		$defaults = array( array( 'heading' => '', 'question' => 'DQ', 'answer' => 'DA' ) );
		update_field(
			'bsn_faq_items',
			array(
				array( 'heading' => '', 'question' => 'Q1', 'answer' => 'A1' ),
				array( 'heading' => '', 'question' => '', 'answer' => 'A2' ),
				array( 'heading' => '', 'question' => 'Q3', 'answer' => ' ' ),
			),
			$faq
		);
		$rows = Content::rows( 'bsn_faq_items', $defaults );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Q1', $rows[0]['question'] );

		update_field( 'bsn_faq_items', array( array( 'heading' => 'H', 'question' => '', 'answer' => '' ) ), $faq );
		$this->assertSame( $defaults, Content::rows( 'bsn_faq_items', $defaults ) );
	}

	public function test_schema_field_names_are_unique_prefixed_and_first_store_fields_match(): void {
		$names = array();
		foreach ( Fields::schema() as $group ) {
			foreach ( $group['fields'] as $field ) {
				$names[] = $field['name'];
				$this->assertStringStartsWith( 'bsn_', $field['name'] );
			}
		}
		$this->assertSame( array_unique( $names ), $names );
		$this->assertSame( 'bsn_about_text', Fields::schema()['about']['fields'][0]['name'] );
		$this->assertSame( 'bsn_faq_intro', Fields::schema()['faq']['fields'][0]['name'] );
	}

	public function test_managed_page_ids_lists_front_and_store_pages(): void {
		$about = $this->store_page( 'about' );
		$faq   = $this->store_page( 'faq' );
		$ids   = Content::managed_page_ids();
		sort( $ids );
		$expected = array( $this->front, $about, $faq );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}
}
