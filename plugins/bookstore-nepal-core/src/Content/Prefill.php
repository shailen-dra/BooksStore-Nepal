<?php
/**
 * Fills the wording boxes with the default wording, so the owner edits words instead of starting blank.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Content;

use BookstoreNepal\StorePages;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-fill: runs once per Fields::VERSION when ACF Pro is active, and on `wp bsn setup`.
 * Never overwrites a box that has a value.
 */
class Prefill {

	/** Option holding the Fields::VERSION pre-fill last ran for. */
	public const OPTION = 'bsn_content_prefilled';

	/**
	 * Hooks pre-fill onto ACF's init, after the field groups are registered.
	 */
	public static function register(): void {
		add_action( 'acf/init', array( self::class, 'maybe_run' ), 20 );
	}

	/**
	 * Runs pre-fill when ACF is active and it has not run for this field version yet.
	 */
	public static function maybe_run(): void {
		if ( ! Content::acf_active() || Fields::VERSION === get_option( self::OPTION ) ) {
			return;
		}
		// No defaults (theme inactive, or wp --skip-themes): leave it for a later request.
		if ( ! apply_filters( 'bsn_content_defaults', array() ) ) {
			return;
		}
		self::run();
		update_option( self::OPTION, Fields::VERSION, false );
	}

	/**
	 * Fills every empty box whose screen exists. A store page someone detached and rewrote in blocks
	 * has that text moved into its first box (block markers removed), and the page goes back to
	 * showing its boxes.
	 *
	 * @return int Number of boxes written.
	 */
	public static function run(): int {
		if ( ! Content::acf_active() ) {
			return 0;
		}
		// Pages may have been created since acf/init (e.g. by `wp bsn setup`): register again so every
		// group points at its page before anything is written.
		Fields::register_groups();
		$defaults = (array) apply_filters( 'bsn_content_defaults', array() );
		$written  = self::adopt_detached_pages();

		foreach ( Fields::schema() as $group ) {
			$post_id = Content::post_id_for( $group['location'] );
			if ( null === $post_id ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				$default = $defaults[ $field['name'] ] ?? null;
				if ( 'message' === $field['type'] || self::is_empty( $default ) || ! self::is_empty( get_field( $field['name'], $post_id, false ) ) ) {
					continue;
				}
				update_field( $field['name'], $default, $post_id );
				++$written;
			}
		}
		return $written;
	}

	/**
	 * Store pages rewritten in the block editor. A page with a single box has its text moved into that
	 * box (block markers removed) when the box is empty, and goes back to showing its box. A page with
	 * several boxes (or a filled box) is left exactly as it is: splitting its text between boxes would be
	 * guesswork. It keeps the block editor (see detached_pages()).
	 *
	 * Untouched pages holding our placeholder paragraph (the theme was inactive when they were made) are
	 * switched to their pattern block.
	 *
	 * @return int Number of boxes written.
	 */
	private static function adopt_detached_pages(): int {
		$written = 0;
		foreach ( Fields::schema() as $group ) {
			if ( ! str_starts_with( $group['location'], 'page:' ) ) {
				continue;
			}
			$key     = substr( $group['location'], 5 );
			$post_id = Content::post_id_for( $group['location'] );
			if ( ! is_int( $post_id ) ) {
				continue;
			}
			// Compared with the literal pattern block: this runs on acf/init, before the theme's patterns
			// are registered, when StorePages::content() would answer with its placeholder paragraph.
			$pattern = StorePages::pattern_block( $key );
			$content = trim( (string) get_post_field( 'post_content', $post_id ) );
			if ( ! Content::is_detached( $key ) ) {
				if ( $pattern !== $content ) {
					wp_update_post( array( 'ID' => $post_id, 'post_content' => $pattern ) );
				}
				continue;
			}
			$first = $group['fields'][0]['name'];
			if ( 1 !== count( $group['fields'] ) || ! self::is_empty( get_field( $first, $post_id, false ) ) ) {
				continue;
			}
			update_field( $first, Content::strip_block_comments( $content ), $post_id );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $pattern ) );
			++$written;
		}
		return $written;
	}

	/**
	 * Keys of store pages that were rewritten in the block editor and still show their own blocks.
	 *
	 * @return list<string>
	 */
	public static function detached_pages(): array {
		return array_values( array_filter( array_keys( StorePages::definitions() ), array( Content::class, 'is_detached' ) ) );
	}

	/**
	 * Whether a stored or default value counts as empty.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return null === $value || false === $value || '' === trim( (string) $value );
	}
}
