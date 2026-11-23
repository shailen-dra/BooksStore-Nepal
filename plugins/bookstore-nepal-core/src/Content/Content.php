<?php
/**
 * Reads the client's wording safely: the default shows whenever a box is empty or ACF Pro is off.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Content;

use BookstoreNepal\StorePages;

defined( 'ABSPATH' ) || exit;

/**
 * Safe readers for the wording fields in Fields::schema().
 */
class Content {

	/**
	 * Whether ACF (Pro) is loaded. The `bsn_acf_active` filter lets tests switch it off.
	 */
	public static function acf_active(): bool {
		return function_exists( 'get_field' ) && function_exists( 'update_field' ) && (bool) apply_filters( 'bsn_acf_active', true );
	}

	/**
	 * Location of a field from the schema, or null for an unknown field.
	 *
	 * @param string $field Field name.
	 */
	public static function location_of( string $field ): ?string {
		foreach ( Fields::schema() as $group ) {
			foreach ( $group['fields'] as $def ) {
				if ( $def['name'] === $field ) {
					return $group['location'];
				}
			}
		}
		return null;
	}

	/**
	 * ACF post ID for a location, or null when its page is not set up.
	 *
	 * @param string $location `front_page`, `page:<key>` or `options`.
	 * @return int|string|null
	 */
	public static function post_id_for( string $location ) {
		if ( 'options' === $location ) {
			return 'option';
		}
		if ( 'front_page' === $location ) {
			if ( 'page' !== get_option( 'show_on_front' ) ) {
				return null;
			}
			return self::published_page( (int) get_option( 'page_on_front' ) );
		}
		if ( str_starts_with( $location, 'page:' ) ) {
			$defs = StorePages::definitions();
			$key  = substr( $location, 5 );
			return isset( $defs[ $key ] ) ? self::published_page( (int) get_option( $defs[ $key ]['option'] ) ) : null;
		}
		return null;
	}

	/**
	 * Plain text from a field, or the default.
	 *
	 * @param string $field   Field name.
	 * @param string $default Default wording.
	 */
	public static function text( string $field, string $default ): string {
		$value = self::raw( $field );
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $default;
	}

	/**
	 * Rich text from a field, or the default, always through wpautop and clean_html().
	 *
	 * @param string $field        Field name.
	 * @param string $default_html Default HTML.
	 */
	public static function rich( string $field, string $default_html ): string {
		$value = self::raw( $field );
		$html  = is_string( $value ) && '' !== trim( wp_strip_all_tags( $value ) ) ? $value : $default_html;
		return self::clean_html( wpautop( $html ) );
	}

	/**
	 * Safe owner HTML: script and style blocks go with their contents, inline styles are dropped (so
	 * text pasted from Word or a web page cannot change the layout), then wp_kses_post.
	 *
	 * @param string $html HTML.
	 */
	public static function clean_html( string $html ): string {
		$html    = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $allowed as $tag => $attrs ) {
			if ( is_array( $attrs ) ) {
				unset( $allowed[ $tag ]['style'] );
			}
		}
		return wp_kses( $html, $allowed );
	}

	/**
	 * Repeater rows with a question and an answer, or the default rows when none are usable.
	 *
	 * @param string $field        Field name.
	 * @param array  $default_rows Default rows.
	 * @return list<array<string,string>>
	 */
	public static function rows( string $field, array $default_rows ): array {
		// Formatted: unformatted repeater rows are keyed by ACF field keys, not sub-field names.
		$value = self::raw( $field, true );
		$rows  = array();
		foreach ( is_array( $value ) ? $value : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array_map( static fn( $v ) => is_string( $v ) ? trim( $v ) : '', $row );
			if ( '' !== ( $clean['question'] ?? '' ) && '' !== ( $clean['answer'] ?? '' ) ) {
				$rows[] = $clean + array( 'heading' => '' );
			}
		}
		return $rows ? $rows : array_values( $default_rows );
	}

	/**
	 * IDs of the pages whose wording is edited through fields: the front page and every store page.
	 *
	 * @return list<int>
	 */
	public static function managed_page_ids(): array {
		$ids = array();
		foreach ( array_merge( array( 'front_page' ), array_map( static fn( $k ) => 'page:' . $k, array_keys( StorePages::definitions() ) ) ) as $location ) {
			$id = self::post_id_for( $location );
			if ( is_int( $id ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Removes block markers (`<!-- wp:… -->`), keeping the HTML between them.
	 *
	 * @param string $html Block markup.
	 */
	public static function strip_block_comments( string $html ): string {
		return trim( (string) preg_replace( '/<!--\s*\/?wp:[^>]*?-->\s*/', '', $html ) );
	}

	/**
	 * Stored value of a field, or null.
	 *
	 * @param string $field  Field name.
	 * @param bool   $format Let ACF format the value (repeaters only; text stays raw and is escaped by the caller).
	 * @return mixed
	 */
	private static function raw( string $field, bool $format = false ) {
		if ( ! self::acf_active() ) {
			return null;
		}
		$location = self::location_of( $field );
		$post_id  = null === $location ? null : self::post_id_for( $location );
		return null === $post_id ? null : get_field( $field, $post_id, $format );
	}

	/**
	 * The ID when it is a published page, else null.
	 *
	 * @param int $id Post ID.
	 */
	private static function published_page( int $id ): ?int {
		return $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : null;
	}
}
