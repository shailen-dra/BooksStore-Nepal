<?php
use BookstoreNepal\Content\Fields;
use BookstoreNepal\StorePages;

/**
 * ACF registration of the wording field groups and the Shop settings page.
 */
class ContentFieldsTest extends WP_UnitTestCase {

	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['bsn_acf_registered'] = array(
			'groups' => array(),
			'pages'  => array(),
		);
		foreach ( StorePages::definitions() as $key => $def ) {
			$this->ids[ $key ] = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => StorePages::pattern_block( $key ) ) );
			update_option( $def['option'], $this->ids[ $key ] );
		}
	}

	private function fields_of( array $group ): array {
		return $group['fields'];
	}

	public function test_register_groups_adds_one_group_per_schema_entry_and_the_options_page(): void {
		Fields::register_groups();
		$this->assertCount( count( Fields::schema() ), $GLOBALS['bsn_acf_registered']['groups'] );
		$this->assertCount( 1, $GLOBALS['bsn_acf_registered']['pages'] );
		$page = $GLOBALS['bsn_acf_registered']['pages'][0];
		$this->assertSame( 'bsn-shop-settings', $page['menu_slug'] );
		$this->assertSame( 'Shop settings', $page['page_title'] );
		$this->assertSame( 'manage_woocommerce', $page['capability'] );
	}

	public function test_store_page_group_targets_that_page_id(): void {
		$this->assertSame(
			array( array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) $this->ids['about'] ) ) ),
			Fields::acf_group( 'about' )['location']
		);
	}

	public function test_home_group_targets_front_page_type(): void {
		$rule = Fields::acf_group( 'home_hero' )['location'][0][0];
		$this->assertSame( 'page_type', $rule['param'] );
		$this->assertSame( 'front_page', $rule['value'] );
	}

	public function test_settings_group_targets_options_page(): void {
		$rule = Fields::acf_group( 'settings' )['location'][0][0];
		$this->assertSame( 'options_page', $rule['param'] );
		$this->assertSame( 'bsn-shop-settings', $rule['value'] );
	}

	public function test_unresolved_store_page_group_still_registers_its_fields(): void {
		// ACF only reads and writes a field it knows; a fresh install creates the pages after acf/init.
		update_option( StorePages::definitions()['terms']['option'], 0 );
		Fields::register_groups();
		$groups = array_column( $GLOBALS['bsn_acf_registered']['groups'], null, 'key' );
		$this->assertArrayHasKey( 'group_bsn_terms', $groups );
		$this->assertSame( '0', $groups['group_bsn_terms']['location'][0][0]['value'], 'Shown on no screen until the page exists.' );
		$this->assertSame( (string) $this->ids['about'], $groups['group_bsn_about']['location'][0][0]['value'] );
	}

	public function test_wysiwyg_fields_use_basic_toolbar_without_media(): void {
		$seen = 0;
		foreach ( array_keys( Fields::schema() ) as $key ) {
			foreach ( $this->fields_of( Fields::acf_group( $key ) ) as $field ) {
				if ( 'wysiwyg' === $field['type'] ) {
					++$seen;
					$this->assertSame( 'basic', $field['toolbar'] );
					$this->assertSame( 0, $field['media_upload'] );
				}
			}
		}
		$this->assertGreaterThan( 0, $seen );
	}

	public function test_field_keys_are_stable(): void {
		$group = Fields::acf_group( 'faq' );
		$this->assertSame( 'group_bsn_faq', $group['key'] );
		$this->assertSame( 'field_bsn_faq_intro', $group['fields'][0]['key'] );
		$this->assertSame( 'field_bsn_faq_items', $group['fields'][1]['key'] );
		$this->assertSame( 'field_bsn_faq_items_question', $group['fields'][1]['sub_fields'][1]['key'] );
	}

	public function test_settings_page_hints_where_contact_details_live(): void {
		$messages = array_filter( Fields::acf_group( 'settings' )['fields'], static fn( $f ) => 'message' === $f['type'] );
		$this->assertStringContainsString( 'WooCommerce › Settings › General', reset( $messages )['message'] );
	}

	public function test_home_page_is_split_into_numbered_boxed_sections_in_page_order(): void {
		$keys = array( 'home_hero', 'home_ages', 'home_rows', 'home_gift', 'home_trust' );
		$this->assertSame( $keys, array_values( array_filter( array_keys( Fields::schema() ), static fn( $k ) => str_starts_with( $k, 'home_' ) ) ) );
		foreach ( $keys as $i => $key ) {
			$group = Fields::acf_group( $key );
			$this->assertStringStartsWith( ( $i + 1 ) . '. ', $group['title'] );
			$this->assertSame( 'default', $group['style'], 'A boxed panel with its own title and collapse arrow.' );
			$this->assertSame( $i, $group['menu_order'] );
			$this->assertSame( 'front_page', $group['location'][0][0]['value'] );
		}
	}

	public function test_related_boxes_sit_side_by_side(): void {
		$widths = array();
		foreach ( Fields::acf_group( 'home_hero' )['fields'] as $field ) {
			$widths[ $field['name'] ] = $field['wrapper']['width'] ?? '';
		}
		$this->assertSame( '50', $widths['bsn_hero_button_ages'] );
		$this->assertSame( '50', $widths['bsn_hero_button_gift'] );
		$this->assertSame( '33', $widths['bsn_hero_book_1'] );
		$this->assertSame( '', $widths['bsn_hero_lead'], 'Long text keeps the full width.' );
		$this->assertArrayNotHasKey( 'width', Fields::acf_group( 'home_hero' )['fields'][0], 'Schema width becomes ACF wrapper width.' );
	}

	public function test_each_slider_has_a_book_picker(): void {
		$fields = array_column( Fields::acf_group( 'home_rows' )['fields'], null, 'name' );
		foreach ( array( 'bsn_new_books', 'bsn_best_books' ) as $name ) {
			$this->assertSame( 'relationship', $fields[ $name ]['type'] );
			$this->assertSame( array( 'product' ), $fields[ $name ]['post_type'] );
			$this->assertSame( array( 'publish' ), $fields[ $name ]['post_status'] );
			$this->assertSame( 'id', $fields[ $name ]['return_format'] );
			$this->assertSame( array( 'search' ), $fields[ $name ]['filters'] );
			$this->assertSame( 12, $fields[ $name ]['max'] );
		}
	}

	public function test_shop_settings_points_to_the_home_page_form(): void {
		$front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$fields = array_column( Fields::acf_group( 'settings' )['fields'], null, 'name' );
		$hint   = $fields['bsn_settings_home_hint'];
		$this->assertSame( 'message', $hint['type'] );
		$this->assertStringContainsString( 'Book rows', $hint['message'] );
		$this->assertStringContainsString( esc_url( admin_url( 'post.php?post=' . $front . '&action=edit' ) ), $hint['message'] );
	}

	public function test_groups_hide_the_content_editor_and_register_on_acf_init(): void {
		$this->assertContains( 'the_content', Fields::acf_group( 'home_hero' )['hide_on_screen'] );
		Fields::register();
		$this->assertNotFalse( has_action( 'acf/init', array( Fields::class, 'register_groups' ) ) );
	}
}
