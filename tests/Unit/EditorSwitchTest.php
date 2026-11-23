<?php
use BookstoreNepal\Content\EditorSwitch;
use BookstoreNepal\StorePages;

/**
 * The block editor stays away from the wording pages, and the Site Editor is hidden.
 */
class EditorSwitchTest extends WP_UnitTestCase {

	private int $front = 0;
	private int $about = 0;

	public function set_up(): void {
		parent::set_up();
		$this->front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->front );
		$this->about = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( StorePages::definitions()['about']['option'], $this->about );
	}

	public function tear_down(): void {
		remove_all_filters( 'bsn_site_editor_allowed' );
		unset( $GLOBALS['submenu'] );
		add_post_type_support( 'page', 'editor' );
		parent::tear_down();
	}

	public function test_block_editor_off_only_for_managed_pages(): void {
		$other   = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$product = self::factory()->post->create_and_get( array( 'post_type' => 'product' ) );
		$this->assertFalse( EditorSwitch::filter_block_editor( true, get_post( $this->front ) ) );
		$this->assertFalse( EditorSwitch::filter_block_editor( true, get_post( $this->about ) ) );
		$this->assertTrue( EditorSwitch::filter_block_editor( true, $other ) );
		$this->assertFalse( EditorSwitch::filter_block_editor( false, $product ), 'Leaves other decisions alone.' );
	}

	public function test_content_editor_hidden_only_when_editing_a_managed_page(): void {
		$_GET['post'] = (string) get_option( 'page_on_front' );
		$other        = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$_GET['post'] = (string) $other;
		EditorSwitch::hide_content_editor();
		$this->assertTrue( post_type_supports( 'page', 'editor' ) );
		$_GET['post'] = (string) $this->about;
		EditorSwitch::hide_content_editor();
		$this->assertFalse( post_type_supports( 'page', 'editor' ) );
		unset( $_GET['post'] );
	}

	public function test_site_editor_menu_removed_unless_allowed(): void {
		$GLOBALS['submenu'] = array( 'themes.php' => array( array( 'Editor', 'edit_theme_options', 'site-editor.php' ), array( 'Themes', 'switch_themes', 'themes.php' ) ) );
		EditorSwitch::remove_site_editor_menu();
		$this->assertSame( array( 'themes.php' ), array_values( wp_list_pluck( $GLOBALS['submenu']['themes.php'], 2 ) ) );

		$GLOBALS['submenu'] = array( 'themes.php' => array( array( 'Editor', 'edit_theme_options', 'site-editor.php' ) ) );
		add_filter( 'bsn_site_editor_allowed', '__return_true' );
		$this->assertTrue( EditorSwitch::site_editor_allowed() );
		EditorSwitch::remove_site_editor_menu();
		$this->assertCount( 1, $GLOBALS['submenu']['themes.php'] );
	}

	public function test_site_editor_not_allowed_by_default(): void {
		$this->assertFalse( EditorSwitch::site_editor_allowed() );
	}

	public function test_hooks_registered(): void {
		EditorSwitch::register();
		$this->assertNotFalse( has_filter( 'use_block_editor_for_post', array( EditorSwitch::class, 'filter_block_editor' ) ) );
		$this->assertNotFalse( has_action( 'load-post.php', array( EditorSwitch::class, 'hide_content_editor' ) ) );
		$this->assertSame( 999, has_action( 'admin_menu', array( EditorSwitch::class, 'remove_site_editor_menu' ) ) );
		$this->assertNotFalse( has_action( 'load-site-editor.php', array( EditorSwitch::class, 'block_site_editor' ) ) );
	}
}
