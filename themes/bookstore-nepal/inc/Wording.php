<?php
/**
 * Default wording for every box the shop owner can edit, and readers that prefer the owner's text.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * The theme owns the default wording; the core plugin stores and reads the owner's version (ACF Pro).
 */
final class Wording {

	/**
	 * Default wording keyed by field name (see BookstoreNepal\Content\Fields::schema()).
	 * Also handed to the plugin's pre-fill through the `bsn_content_defaults` filter.
	 *
	 * @param array $defaults Defaults from other filters (merged under ours).
	 * @return array<string,string|array>
	 */
	public static function defaults( $defaults = array() ): array {
		return array_merge( (array) $defaults, self::home(), self::settings() );
	}

	/**
	 * Plain text: the owner's wording, or the default. Escape at output.
	 *
	 * @param string $field Field name.
	 */
	public static function text( string $field ): string {
		$default = self::defaults()[ $field ] ?? '';
		$default = is_string( $default ) ? $default : '';
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::text( $field, $default ) : $default;
	}

	/**
	 * Rich text, already made safe (wp_kses_post).
	 *
	 * @param string $field Field name.
	 */
	public static function rich( string $field ): string {
		$default = self::defaults()[ $field ] ?? '';
		$default = is_string( $default ) ? $default : '';
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::rich( $field, $default ) : wp_kses_post( wpautop( $default ) );
	}

	/**
	 * Repeater rows (heading, question, answer); escape at output.
	 *
	 * @param string $field Field name.
	 * @return list<array<string,string>>
	 */
	public static function rows( string $field ): array {
		$default = self::defaults()[ $field ] ?? array();
		$default = is_array( $default ) ? $default : array();
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::rows( $field, $default ) : array_values( $default );
	}

	/**
	 * Home page.
	 *
	 * @return array<string,string>
	 */
	private static function home(): array {
		return array(
			'bsn_hero_pill'        => __( 'Delivery all over Nepal', 'bookstore-nepal' ),
			'bsn_hero_headline'    => __( 'Books that grow with your child', 'bookstore-nepal' ),
			'bsn_hero_lead'        => __( 'Picture books, stories and activity books in Nepali and English — chosen by age, delivered to your door.', 'bookstore-nepal' ),
			'bsn_hero_button_ages' => __( 'Shop by age', 'bookstore-nepal' ),
			'bsn_hero_button_gift' => __( 'Find a gift', 'bookstore-nepal' ),
			'bsn_hero_book_1'      => __( 'The Little Yak\'s Big Day', 'bookstore-nepal' ),
			'bsn_hero_book_2'      => 'हिमालको कथा',
			'bsn_hero_book_3'      => __( 'Count the Momos', 'bookstore-nepal' ),
			'bsn_ages_heading'     => __( 'Shop by age', 'bookstore-nepal' ),
			'bsn_age_0_3_title'    => __( 'Babies & toddlers', 'bookstore-nepal' ),
			'bsn_age_0_3_text'     => __( 'Board books, first words, touch and feel', 'bookstore-nepal' ),
			'bsn_age_4_7_title'    => __( 'Early readers', 'bookstore-nepal' ),
			'bsn_age_4_7_text'     => __( 'Picture stories, alphabet and activity books', 'bookstore-nepal' ),
			'bsn_age_8_12_title'   => __( 'Confident readers', 'bookstore-nepal' ),
			'bsn_age_8_12_text'    => __( 'Chapter books, adventures, how-things-work', 'bookstore-nepal' ),
			'bsn_new_heading'      => __( 'New arrivals', 'bookstore-nepal' ),
			'bsn_new_more'         => __( 'See all new books', 'bookstore-nepal' ),
			'bsn_best_heading'     => __( 'Best sellers', 'bookstore-nepal' ),
			'bsn_best_more'        => __( 'See all best sellers', 'bookstore-nepal' ),
			'bsn_featured_heading' => __( 'Our favourites', 'bookstore-nepal' ),
			'bsn_featured_more'    => __( 'See all books', 'bookstore-nepal' ),
			'bsn_gift_heading'     => __( 'Looking for a gift?', 'bookstore-nepal' ),
			'bsn_gift_text'        => __( 'Tell us the child’s age and your budget — we’ll show books they’ll love.', 'bookstore-nepal' ),
			'bsn_trust_delivery'   => __( 'Delivery all over Nepal', 'bookstore-nepal' ),
		);
	}

	/**
	 * Shop settings (footer).
	 *
	 * @return array<string,string>
	 */
	private static function settings(): array {
		return array(
			'bsn_footer_about'        => __( 'Children\'s books in Nepali and English, delivered all over Nepal.', 'bookstore-nepal' ),
			'bsn_footer_help_heading' => __( 'Help', 'bookstore-nepal' ),
		);
	}
}
