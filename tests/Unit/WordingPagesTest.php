<?php
use BookstoreNepal\Content\Fields;
use BookstoreNepal\StorePages;
use BookstoreNepal\Theme\Wording;

require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Theme.php';
require_once dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/inc/Wording.php';

/**
 * Store pages read their wording from the editable fields and keep today's order.
 */
class WordingPagesTest extends WP_UnitTestCase {

	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( StorePages::definitions() as $key => $def ) {
			$this->ids[ $key ] = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $def['slug'] ) );
			update_option( $def['option'], $this->ids[ $key ] );
		}
	}

	private function render( string $key ): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/themes/bookstore-nepal/patterns/page-' . $key . '.php';
		$html = (string) ob_get_clean();
		return (string) preg_replace( '/\s+/', ' ', preg_replace( '/<!--(?!\s*wp:pattern).*?-->/s', '', $html ) );
	}

	public function test_defaults_cover_every_schema_field(): void {
		$names = array();
		foreach ( Fields::schema() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( 'message' !== $field['type'] ) {
					$names[] = $field['name'];
				}
			}
		}
		sort( $names );
		$keys = array_keys( Wording::defaults() );
		sort( $keys );
		$this->assertSame( $names, $keys );
	}

	public function test_store_pages_render_same_text_with_empty_fields(): void {
		$expect = array(
			'about'    => 'We are a bookshop for young readers.',
			'delivery' => 'We deliver to all 77 districts of Nepal',
			'returns'  => 'When you can send a book back',
			'privacy'  => 'Individual Privacy Act, 2075 (2018)',
			'terms'    => 'By placing an order with us',
			'contact'  => 'Opening hours',
			'faq'      => 'Choosing books',
		);
		foreach ( $expect as $key => $sentence ) {
			$this->assertStringContainsString( $sentence, $this->render( $key ), $key );
		}
	}

	public function test_delivery_keeps_rates_table_between_intro_and_times(): void {
		$html  = $this->render( 'delivery' );
		$order = array( 'Delivery charges', 'bookstore-nepal/delivery-rates', 'How long delivery takes', 'Following your order' );
		$last  = -1;
		foreach ( $order as $marker ) {
			$pos = strpos( $html, $marker );
			$this->assertNotFalse( $pos, $marker );
			$this->assertGreaterThan( $last, $pos, $marker );
			$last = $pos;
		}
	}

	public function test_contact_keeps_details_between_boxes(): void {
		$html = $this->render( 'contact' );
		$this->assertLessThan( strpos( $html, 'bsn-contact"' ), strpos( $html, 'Get in touch' ) );
		$this->assertLessThan( strpos( $html, 'Opening hours' ), strpos( $html, 'bsn-contact"' ) );
	}

	public function test_faq_answer_about_delivery_cost_has_no_rupee_figures(): void {
		$rows = Wording::defaults()['bsn_faq_items'];
		$row  = current( array_filter( $rows, static fn( $r ) => str_starts_with( $r['question'], 'How much does delivery cost' ) ) );
		$this->assertNotFalse( $row );
		$this->assertDoesNotMatchRegularExpression( '/(NPR|Rs\.?|रु)\s*[0-9]/u', $row['answer'] );
		$this->assertStringContainsString( (string) get_permalink( $this->ids['delivery'] ), $row['answer'] );
	}

	public function test_faq_rows_render_heading_once_before_its_question(): void {
		update_field(
			'bsn_faq_items',
			array(
				array( 'heading' => 'Ordering', 'question' => 'Q1', 'answer' => 'A1' ),
				array( 'heading' => '', 'question' => 'Q2', 'answer' => 'A2' ),
			),
			$this->ids['faq']
		);
		$html = $this->render( 'faq' );
		$this->assertSame( 1, substr_count( $html, '<h2 class="wp-block-heading">Ordering</h2>' ) );
		$this->assertLessThan( strpos( $html, '<details class="wp-block-details bsn-faq">' ), strpos( $html, 'Ordering</h2>' ) );
		$this->assertSame( 2, substr_count( $html, '<details class="wp-block-details bsn-faq">' ) );
		$this->assertStringContainsString( '<summary>Q2</summary>', $html );
	}

	public function test_faq_answers_are_cleaned_like_rich_boxes(): void {
		update_field( 'bsn_faq_items', array( array( 'heading' => '', 'question' => 'Q', 'answer' => '<p style="color:red">A</p><script>bad()</script>' ) ), $this->ids['faq'] );
		$html = $this->render( 'faq' );
		$this->assertStringContainsString( '>A</p>', $html );
		$this->assertStringNotContainsString( 'bad()', $html );
		$this->assertStringNotContainsString( 'color:red', $html );
	}

	public function test_client_text_replaces_default(): void {
		update_field( 'bsn_about_text', '<p>Our own words</p>', $this->ids['about'] );
		$html = $this->render( 'about' );
		$this->assertStringContainsString( 'Our own words', $html );
		$this->assertStringNotContainsString( 'We are a bookshop for young readers.', $html );
	}
}
