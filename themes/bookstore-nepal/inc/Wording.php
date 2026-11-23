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
		return array_merge( (array) $defaults, self::home(), self::settings(), self::pages() );
	}

	/**
	 * Plain text: the owner's wording, or the default. Escape at output.
	 *
	 * @param string $field Field name.
	 */
	public static function text( string $field ): string {
		$default = self::default_of( $field );
		$default = is_string( $default ) ? $default : '';
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::text( $field, $default ) : $default;
	}

	/**
	 * Rich text, already made safe (clean()).
	 *
	 * @param string $field Field name.
	 */
	public static function rich( string $field ): string {
		$default = self::default_of( $field );
		$default = is_string( $default ) ? $default : '';
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::rich( $field, $default ) : self::clean( wpautop( $default ) );
	}

	/**
	 * Makes owner HTML safe for output (the plugin's cleaner when it is active).
	 *
	 * @param string $html HTML.
	 */
	public static function clean( string $html ): string {
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::clean_html( $html ) : wp_kses_post( $html );
	}

	/**
	 * Repeater rows (heading, question, answer); escape at output.
	 *
	 * @param string $field Field name.
	 * @return list<array<string,string>>
	 */
	public static function rows( string $field ): array {
		$default = self::default_of( $field );
		$default = is_array( $default ) ? $default : array();
		return class_exists( '\BookstoreNepal\Content\Content' ) ? \BookstoreNepal\Content\Content::rows( $field, $default ) : array_values( $default );
	}

	/**
	 * FAQ questions about paying, built from the enabled gateways on every view (never owner text).
	 *
	 * @return list<array{question:string,answer:string}>
	 */
	public static function payment_questions(): array {
		$methods = Theme::payment_methods();
		$out     = array();
		if ( isset( $methods['cod'] ) ) {
			$out[] = array(
				'question' => __( 'Can I pay when the books arrive?', 'bookstore-nepal' ),
				'answer'   => __( 'Yes. Choose cash on delivery at checkout and pay the rider in cash when your books arrive. Please keep your phone nearby so the rider can reach you.', 'bookstore-nepal' ),
			);
		}
		if ( isset( $methods['esewa'] ) ) {
			$out[] = array(
				'question' => __( 'How does paying with eSewa work?', 'bookstore-nepal' ),
				'answer'   => __( 'Choose eSewa at checkout and place your order. You are taken to eSewa to approve the payment, then brought back to our shop with your confirmation. We never see your eSewa PIN or password. If eSewa charged you but you did not see a confirmation, message us with your phone number and we will check.', 'bookstore-nepal' ),
			);
		}
		return $out;
	}

	/**
	 * One default; store-page defaults are only built when a store-page box is asked for.
	 *
	 * @param string $field Field name.
	 * @return string|array
	 */
	private static function default_of( string $field ) {
		$cheap = self::home() + self::settings();
		return $cheap[ $field ] ?? ( self::pages()[ $field ] ?? '' );
	}

	/**
	 * Store pages: rich boxes as HTML, FAQ rows as arrays (from inc/wording/<page>.php).
	 *
	 * @return array<string,string|array>
	 */
	private static function pages(): array {
		$out = array();
		foreach ( array( 'about', 'contact', 'delivery', 'returns', 'privacy', 'terms', 'faq' ) as $page ) {
			foreach ( (array) include __DIR__ . '/wording/' . $page . '.php' as $field => $items ) {
				$out[ $field ] = 'bsn_faq_items' === $field ? $items : Theme::html( $items );
			}
		}
		return $out;
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
