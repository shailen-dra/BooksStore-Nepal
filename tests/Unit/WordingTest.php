<?php
use BookstoreNepal\Content\Fields;
use BookstoreNepal\Theme\Theme;
use BookstoreNepal\Theme\Wording;

require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Theme.php';
require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Wording.php';

/**
 * Default wording and the home page / footer patterns reading the editable fields.
 */
class WordingTest extends WP_UnitTestCase {

	private int $front = 0;

	public function set_up(): void {
		parent::set_up();
		$this->front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->front );
	}

	private function render_pattern( string $slug ): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/patterns/' . $slug . '.php';
		return (string) ob_get_clean();
	}

	/** Field names of the given schema groups, without read-only message fields. */
	private function field_names( array $groups ): array {
		$names = array();
		foreach ( $groups as $key ) {
			foreach ( Fields::schema()[ $key ]['fields'] as $field ) {
				if ( ! in_array( $field['type'], array( 'message', 'relationship' ), true ) ) {
					$names[] = $field['name'];
				}
			}
		}
		return $names;
	}

	public function test_defaults_cover_home_and_settings_fields(): void {
		$missing = array_diff( $this->field_names( array( 'home_hero', 'home_ages', 'home_rows', 'home_gift', 'home_trust', 'settings' ) ), array_keys( Wording::defaults() ) );
		$this->assertSame( array(), array_values( $missing ) );
	}

	public function test_defaults_match_current_wording(): void {
		$d = Wording::defaults();
		$this->assertSame( 'Books that grow with your child', $d['bsn_hero_headline'] );
		$this->assertSame( 'Early readers', $d['bsn_age_4_7_title'] );
		$this->assertSame( 'See all best sellers', $d['bsn_best_more'] );
		$this->assertSame( "Children's books in Nepali and English, delivered all over Nepal.", $d['bsn_footer_about'] );
	}

	public function test_hero_renders_field_value_and_escapes_it(): void {
		update_field( 'bsn_hero_headline', 'Read <b>more</b>', $this->front );
		$html = $this->render_pattern( 'hero' );
		$this->assertStringContainsString( 'Read &lt;b&gt;more&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'Books that grow with your child', $html );
	}

	public function test_home_patterns_render_defaults_unchanged_when_fields_empty(): void {
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Books that grow with your child</h1>', $this->render_pattern( 'hero' ) );
		$this->assertStringContainsString( 'bsn-hero__book--2">हिमालको कथा<', $this->render_pattern( 'hero' ) );
		$ages = $this->render_pattern( 'shop-by-age' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Shop by age</h2>', $ages );
		$this->assertStringContainsString( 'bsn-age-tile--sky', $ages );
		$this->assertStringContainsString( '<strong>Confident readers</strong>', $ages );
		$this->assertStringContainsString( '>See all new books</a>', $this->render_pattern( 'new-arrivals' ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Best sellers</h2>', $this->render_pattern( 'best-sellers' ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Our favourites</h2>', $this->render_pattern( 'featured-books' ) );
		$this->assertStringContainsString( 'we’ll show books they’ll love.', $this->render_pattern( 'gift-finder' ) );
		$this->assertStringContainsString( 'Delivery all over Nepal', $this->render_pattern( 'trust-strip' ) );
		$this->assertStringContainsString( '<strong>Help</strong>', $this->render_pattern( 'footer-help' ) );
	}

	public function test_sliders_show_picked_books_in_order_else_fill_themselves(): void {
		foreach ( array( 'new-arrivals' => array( 'bsn_new_books', '"orderBy":"date"' ), 'best-sellers' => array( 'bsn_best_books', '"orderBy":"popularity"' ) ) as $slug => $case ) {
			$auto = $this->render_pattern( $slug );
			$this->assertStringContainsString( $case[1], $auto, $slug );
			$this->assertStringContainsString( '"woocommerceHandPickedProducts":[]', $auto, $slug );

			$a = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
			$b = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
			update_field( $case[0], array( (string) $b, (string) $a ), $this->front );
			$picked = $this->render_pattern( $slug );
			$this->assertStringContainsString( '"woocommerceHandPickedProducts":[' . $b . ',' . $a . ']', $picked, $slug );
			$this->assertStringContainsString( '"orderBy":"post__in"', $picked, $slug );
			$this->assertStringContainsString( '"perPage":2', $picked, $slug );
		}
	}

	public function test_footer_reads_shop_settings(): void {
		update_field( 'bsn_footer_about', 'Books & more', 'option' );
		$this->assertStringContainsString( 'Books &amp; more', $this->render_pattern( 'footer-about' ) );
	}

	public function test_html_strips_block_comments_keeps_markup(): void {
		$html = Theme::html( array( array( 'h2', 'A' ), array( 'p', 'B' ) ) );
		$this->assertStringNotContainsString( '<!--', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">A</h2>', $html );
		$this->assertStringContainsString( '<p>B</p>', $html );
	}

	public function test_defaults_reach_the_plugin_through_the_filter(): void {
		Theme::register();
		$this->assertSame( 'Books that grow with your child', apply_filters( 'bsn_content_defaults', array() )['bsn_hero_headline'] );
	}
}
