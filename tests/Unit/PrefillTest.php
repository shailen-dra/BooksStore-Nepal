<?php
use BookstoreNepal\Content\Fields;
use BookstoreNepal\Content\Prefill;
use BookstoreNepal\StorePages;
use BookstoreNepal\Theme\Wording;

require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Theme.php';
require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Wording.php';

/**
 * Filling the wording boxes once with the default wording, never over the owner's edits.
 */
class PrefillTest extends WP_UnitTestCase {

	private int $front = 0;
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'bsn_content_defaults', array( Wording::class, 'defaults' ) );
		$this->front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->front );
		foreach ( StorePages::definitions() as $key => $def ) {
			$this->ids[ $key ] = self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def['title'],
					'post_content' => StorePages::content( $key, $def['title'] ),
				)
			);
			update_option( $def['option'], $this->ids[ $key ] );
		}
		delete_option( Prefill::OPTION );
	}

	public function tear_down(): void {
		remove_filter( 'bsn_content_defaults', array( Wording::class, 'defaults' ) );
		remove_all_filters( 'bsn_acf_active' );
		parent::tear_down();
	}

	public function test_run_fills_only_empty_fields(): void {
		update_field( 'bsn_hero_headline', 'Mine', $this->front );
		Prefill::run();
		$this->assertSame( 'Mine', get_field( 'bsn_hero_headline', $this->front ) );
		$this->assertSame( Wording::defaults()['bsn_hero_lead'], get_field( 'bsn_hero_lead', $this->front ) );
		$this->assertSame( Wording::defaults()['bsn_footer_about'], get_field( 'bsn_footer_about', 'option' ) );
		$this->assertCount( count( Wording::defaults()['bsn_faq_items'] ), get_field( 'bsn_faq_items', $this->ids['faq'] ) );
	}

	public function test_second_run_keeps_client_edits(): void {
		Prefill::run();
		update_field( 'bsn_about_text', '<p>Edited</p>', $this->ids['about'] );
		$this->assertSame( 0, Prefill::run() );
		$this->assertSame( '<p>Edited</p>', get_field( 'bsn_about_text', $this->ids['about'] ) );
	}

	public function test_detached_page_text_moves_into_first_box(): void {
		wp_update_post(
			array(
				'ID'           => $this->ids['about'],
				'post_content' => '<!-- wp:paragraph --><p>Our own story</p><!-- /wp:paragraph -->',
			)
		);
		Prefill::run();
		$saved = (string) get_field( 'bsn_about_text', $this->ids['about'] );
		$this->assertStringContainsString( '<p>Our own story</p>', $saved );
		$this->assertStringNotContainsString( '<!-- wp:', $saved );
		$this->assertSame( '<!-- wp:pattern {"slug":"bookstore-nepal/page-about"} /-->', get_post( $this->ids['about'] )->post_content );
	}

	public function test_pattern_block_pages_count_as_untouched_even_before_patterns_register(): void {
		// acf/init runs before the theme's patterns are registered, so StorePages::content() would
		// return its placeholder paragraph; a page holding its own pattern block is still untouched.
		$block = '<!-- wp:pattern {"slug":"bookstore-nepal/page-about"} /-->';
		wp_update_post( array( 'ID' => $this->ids['about'], 'post_content' => $block ) );
		Prefill::run();
		$this->assertSame( $block, get_post( $this->ids['about'] )->post_content );
		$this->assertStringContainsString( 'We are a bookshop for young readers.', (string) get_field( 'bsn_about_text', $this->ids['about'] ) );
	}

	public function test_placeholder_page_counts_as_untouched(): void {
		$placeholder = StorePages::content( 'terms', StorePages::definitions()['terms']['title'] );
		$this->assertStringContainsString( '[CLIENT:', $placeholder, 'Patterns are not registered in tests.' );
		wp_update_post( array( 'ID' => $this->ids['terms'], 'post_content' => $placeholder ) );
		Prefill::run();
		$this->assertStringNotContainsString( '[CLIENT: write', (string) get_field( 'bsn_terms_text', $this->ids['terms'] ) );
		$this->assertSame( '<!-- wp:pattern {"slug":"bookstore-nepal/page-terms"} /-->', get_post( $this->ids['terms'] )->post_content );
	}

	public function test_untouched_page_gets_default_not_its_pattern_block(): void {
		Prefill::run();
		$saved = (string) get_field( 'bsn_returns_text', $this->ids['returns'] );
		$this->assertStringContainsString( 'When you can send a book back', $saved );
		$this->assertStringNotContainsString( 'wp:pattern', $saved );
	}

	public function test_maybe_run_runs_once_per_version_and_not_without_acf(): void {
		add_filter( 'bsn_acf_active', '__return_false' );
		Prefill::maybe_run();
		$this->assertNull( get_field( 'bsn_hero_lead', $this->front ) );
		$this->assertFalse( get_option( Prefill::OPTION ) );

		remove_all_filters( 'bsn_acf_active' );
		Prefill::maybe_run();
		$this->assertNotNull( get_field( 'bsn_hero_lead', $this->front ) );
		$this->assertSame( Fields::VERSION, get_option( Prefill::OPTION ) );

		delete_post_meta( $this->front, 'bsn_hero_lead' );
		Prefill::maybe_run();
		$this->assertNull( get_field( 'bsn_hero_lead', $this->front ), 'Already ran for this version.' );
	}

	public function test_unresolved_locations_are_skipped(): void {
		update_option( 'show_on_front', 'posts' );
		Prefill::run();
		$this->assertNull( get_field( 'bsn_hero_lead', $this->front ) );
		$this->assertNotNull( get_field( 'bsn_about_text', $this->ids['about'] ) );
	}

	public function test_empty_defaults_are_not_written(): void {
		Prefill::run();
		$this->assertNull( get_field( 'bsn_faq_intro', $this->ids['faq'] ) );
	}

	public function test_run_registers_field_groups_for_pages_created_after_acf_init(): void {
		$GLOBALS['bsn_acf_registered'] = array( 'groups' => array(), 'pages' => array() );
		Prefill::run();
		$groups = array_column( $GLOBALS['bsn_acf_registered']['groups'], null, 'key' );
		$this->assertSame( (string) $this->ids['faq'], $groups['group_bsn_faq']['location'][0][0]['value'] );
	}

	public function test_registers_on_acf_init(): void {
		Prefill::register();
		$this->assertSame( 20, has_action( 'acf/init', array( Prefill::class, 'maybe_run' ) ) );
	}
}
