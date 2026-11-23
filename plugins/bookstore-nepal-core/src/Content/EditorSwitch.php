<?php
/**
 * Keeps the block editor and the Site Editor out of the shop owner's way.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The Home page and store pages are edited only through their wording boxes, and Appearance › Editor
 * is hidden unless wp-config.php has `define( 'BSN_SITE_EDITOR', true );`.
 */
class EditorSwitch {

	/**
	 * Hooks everything.
	 */
	public static function register(): void {
		add_filter( 'use_block_editor_for_post', array( self::class, 'filter_block_editor' ), 10, 2 );
		add_action( 'load-post.php', array( self::class, 'hide_content_editor' ) );
		add_action( 'admin_menu', array( self::class, 'remove_site_editor_menu' ), 999 );
		add_action( 'load-site-editor.php', array( self::class, 'block_site_editor' ) );
	}

	/**
	 * No block editor for the wording pages.
	 *
	 * @param bool     $use  Whether to use the block editor.
	 * @param \WP_Post $post Post being edited.
	 */
	public static function filter_block_editor( $use, $post ): bool {
		if ( $post instanceof \WP_Post && in_array( (int) $post->ID, Content::managed_page_ids(), true ) ) {
			return false;
		}
		return (bool) $use;
	}

	/**
	 * On a wording page's edit screen, drop the classic content box too (it would show the page's
	 * internal pattern block); only the title and the wording boxes remain.
	 */
	public static function hide_content_editor(): void {
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen choice.
		if ( $id && in_array( $id, Content::managed_page_ids(), true ) ) {
			remove_post_type_support( 'page', 'editor' );
		}
	}

	/**
	 * Whether the Site Editor stays available (a developer switch in wp-config.php).
	 */
	public static function site_editor_allowed(): bool {
		return (bool) apply_filters( 'bsn_site_editor_allowed', defined( 'BSN_SITE_EDITOR' ) && BSN_SITE_EDITOR );
	}

	/**
	 * Removes Appearance › Editor from the menu.
	 */
	public static function remove_site_editor_menu(): void {
		if ( ! self::site_editor_allowed() ) {
			remove_submenu_page( 'themes.php', 'site-editor.php' );
		}
	}

	/**
	 * Sends anyone who opens the Site Editor directly to the dashboard.
	 */
	public static function block_site_editor(): void {
		if ( self::site_editor_allowed() ) {
			return;
		}
		wp_safe_redirect( admin_url() );
		exit;
	}
}
