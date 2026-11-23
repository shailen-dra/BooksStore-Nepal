<?php
/**
 * The client-editable wording: which boxes exist and which screen each one is on.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Field schema for the ACF Pro wording screens.
 */
class Fields {

	/** Bump when fields are added, so pre-fill runs again for the new ones. */
	public const VERSION = '1';

	/**
	 * Groups keyed by name. Location is `front_page`, `page:<StorePages key>` or `options`.
	 * In store-page groups the first field is the page's "first box".
	 *
	 * @return array<string,array{title:string,location:string,fields:list<array<string,mixed>>}>
	 */
	public static function schema(): array {
		$text     = static fn( string $name, string $label, string $hint = '' ): array => self::field( $name, $label, 'text', $hint );
		$textarea = static fn( string $name, string $label, string $hint = '' ): array => self::field( $name, $label, 'textarea', $hint );
		$rich     = static fn( string $name, string $label, string $hint = '' ): array => self::field( $name, $label, 'wysiwyg', $hint );

		return array(
			'home'     => array(
				'title'    => __( 'Home page wording', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_hero_pill', __( 'Small label above the headline', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_headline', __( 'Headline', 'bookstore-nepal' ), __( 'The big words at the top of the home page.', 'bookstore-nepal' ) ),
					$textarea( 'bsn_hero_lead', __( 'Text under the headline', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_button_ages', __( 'First button', 'bookstore-nepal' ), __( 'Takes visitors to the "Shop by age" tiles.', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_button_gift', __( 'Second button', 'bookstore-nepal' ), __( 'Takes visitors to the gift finder.', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_book_1', __( 'Picture book 1 title', 'bookstore-nepal' ), __( 'The three drawn books beside the headline. Keep the titles short.', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_book_2', __( 'Picture book 2 title', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_book_3', __( 'Picture book 3 title', 'bookstore-nepal' ) ),
					$text( 'bsn_ages_heading', __( '"Shop by age" heading', 'bookstore-nepal' ) ),
					$text( 'bsn_age_0_3_title', __( 'Ages 0–3: name', 'bookstore-nepal' ) ),
					$text( 'bsn_age_0_3_text', __( 'Ages 0–3: description', 'bookstore-nepal' ) ),
					$text( 'bsn_age_4_7_title', __( 'Ages 4–7: name', 'bookstore-nepal' ) ),
					$text( 'bsn_age_4_7_text', __( 'Ages 4–7: description', 'bookstore-nepal' ) ),
					$text( 'bsn_age_8_12_title', __( 'Ages 8–12: name', 'bookstore-nepal' ) ),
					$text( 'bsn_age_8_12_text', __( 'Ages 8–12: description', 'bookstore-nepal' ) ),
					$text( 'bsn_new_heading', __( 'New arrivals: heading', 'bookstore-nepal' ) ),
					$text( 'bsn_new_more', __( 'New arrivals: "see all" link', 'bookstore-nepal' ) ),
					$text( 'bsn_best_heading', __( 'Best sellers: heading', 'bookstore-nepal' ) ),
					$text( 'bsn_best_more', __( 'Best sellers: "see all" link', 'bookstore-nepal' ) ),
					$text( 'bsn_featured_heading', __( 'Favourites: heading', 'bookstore-nepal' ), __( 'The books shown here are the ones starred as "featured" in Products.', 'bookstore-nepal' ) ),
					$text( 'bsn_featured_more', __( 'Favourites: "see all" link', 'bookstore-nepal' ) ),
					$text( 'bsn_gift_heading', __( 'Gift finder: heading', 'bookstore-nepal' ) ),
					$textarea( 'bsn_gift_text', __( 'Gift finder: text', 'bookstore-nepal' ) ),
					$text( 'bsn_trust_delivery', __( 'Dark strip near the bottom: delivery line', 'bookstore-nepal' ), __( 'The ways to pay in this strip update by themselves.', 'bookstore-nepal' ) ),
				),
			),
			'about'    => array(
				'title'    => __( 'About us page', 'bookstore-nepal' ),
				'location' => 'page:about',
				'fields'   => array( $rich( 'bsn_about_text', __( 'Page text', 'bookstore-nepal' ) ) ),
			),
			'contact'  => array(
				'title'    => __( 'Contact page', 'bookstore-nepal' ),
				'location' => 'page:contact',
				'fields'   => array(
					$rich( 'bsn_contact_intro', __( 'Text above the contact details', 'bookstore-nepal' ), __( 'Phone, WhatsApp, email and address appear below this by themselves. Change them in WooCommerce › Settings › General.', 'bookstore-nepal' ) ),
					$rich( 'bsn_contact_after', __( 'Text below the contact details', 'bookstore-nepal' ) ),
				),
			),
			'delivery' => array(
				'title'    => __( 'Delivery information page', 'bookstore-nepal' ),
				'location' => 'page:delivery',
				'fields'   => array(
					$rich( 'bsn_delivery_intro', __( 'Text above the delivery charges', 'bookstore-nepal' ), __( 'The table of delivery charges appears below this by itself. Change the charges in WooCommerce › Settings › Shipping.', 'bookstore-nepal' ) ),
					$rich( 'bsn_delivery_times', __( 'Text below the delivery charges', 'bookstore-nepal' ), __( 'How long delivery takes. The ways to pay appear below this by themselves.', 'bookstore-nepal' ) ),
					$rich( 'bsn_delivery_after', __( 'Text at the end of the page', 'bookstore-nepal' ) ),
				),
			),
			'returns'  => array(
				'title'    => __( 'Returns & refunds page', 'bookstore-nepal' ),
				'location' => 'page:returns',
				'fields'   => array( $rich( 'bsn_returns_text', __( 'Page text', 'bookstore-nepal' ) ) ),
			),
			'privacy'  => array(
				'title'    => __( 'Privacy policy page', 'bookstore-nepal' ),
				'location' => 'page:privacy',
				'fields'   => array( $rich( 'bsn_privacy_text', __( 'Page text', 'bookstore-nepal' ) ) ),
			),
			'terms'    => array(
				'title'    => __( 'Terms & conditions page', 'bookstore-nepal' ),
				'location' => 'page:terms',
				'fields'   => array( $rich( 'bsn_terms_text', __( 'Page text', 'bookstore-nepal' ) ) ),
			),
			'faq'      => array(
				'title'    => __( 'Questions page', 'bookstore-nepal' ),
				'location' => 'page:faq',
				'fields'   => array(
					$rich( 'bsn_faq_intro', __( 'Text above the questions', 'bookstore-nepal' ) ),
					array(
						'name'         => 'bsn_faq_items',
						'label'        => __( 'Questions and answers', 'bookstore-nepal' ),
						'type'         => 'repeater',
						'instructions' => __( 'Drag to reorder. A question needs both a question and an answer to show.', 'bookstore-nepal' ),
						'sub_fields'   => array(
							$text( 'heading', __( 'Heading above this question (optional)', 'bookstore-nepal' ), __( 'Starts a new group, e.g. "Choosing books".', 'bookstore-nepal' ) ),
							$text( 'question', __( 'Question', 'bookstore-nepal' ) ),
							$rich( 'answer', __( 'Answer', 'bookstore-nepal' ) ),
						),
					),
				),
			),
			'settings' => array(
				'title'    => __( 'Shop settings', 'bookstore-nepal' ),
				'location' => 'options',
				'fields'   => array(
					array(
						'name'    => 'bsn_settings_contact_hint',
						'label'   => __( 'Phone, WhatsApp, email and address', 'bookstore-nepal' ),
						'type'    => 'message',
						'message' => __( 'These are set in WooCommerce › Settings › General, so they are typed in one place only.', 'bookstore-nepal' ),
					),
					$textarea( 'bsn_footer_about', __( 'Footer: line under the shop name', 'bookstore-nepal' ) ),
					$text( 'bsn_footer_help_heading', __( 'Footer: heading above the help links', 'bookstore-nepal' ) ),
				),
			),
		);
	}

	/** Menu slug of the Shop settings page. */
	public const OPTIONS_SLUG = 'bsn-shop-settings';

	/**
	 * Hooks registration onto ACF's init.
	 */
	public static function register(): void {
		add_action( 'acf/init', array( self::class, 'register_groups' ) );
	}

	/**
	 * Registers the Shop settings page and every field group whose screen exists.
	 */
	public static function register_groups(): void {
		if ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_page(
				array(
					'page_title' => __( 'Shop settings', 'bookstore-nepal' ),
					'menu_title' => __( 'Shop settings', 'bookstore-nepal' ),
					'menu_slug'  => self::OPTIONS_SLUG,
					'capability' => 'manage_woocommerce',
					'position'   => 56,
					'icon_url'   => 'dashicons-store',
					'redirect'   => false,
				)
			);
		}
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		// Every group is registered, even when its page does not exist yet: ACF only reads and writes
		// fields it knows (an unknown repeater is saved in a shape it cannot load back).
		foreach ( array_keys( self::schema() ) as $key ) {
			acf_add_local_field_group( self::acf_group( $key ) );
		}
	}

	/**
	 * The array handed to acf_add_local_field_group() for one group. A store page that is not set up
	 * yet gets a rule matching no screen (page 0).
	 *
	 * @param string $key Group key from schema().
	 * @return array<string,mixed>
	 */
	public static function acf_group( string $key ): array {
		$def = self::schema()[ $key ];
		return array(
			'key'            => 'group_bsn_' . $key,
			'title'          => $def['title'],
			'fields'         => array_map( static fn( array $f ): array => self::acf_field( $f, 'field_' ), $def['fields'] ),
			'location'       => self::acf_location( $def['location'] ),
			'position'       => 'acf_after_title',
			'style'          => 'seamless',
			'hide_on_screen' => array( 'the_content' ),
		);
	}

	/**
	 * ACF location rules for a schema location.
	 *
	 * @param string $location Schema location.
	 * @return array<int,array<int,array<string,string>>>
	 */
	private static function acf_location( string $location ): array {
		if ( 'front_page' === $location ) {
			return array( array( array( 'param' => 'page_type', 'operator' => '==', 'value' => 'front_page' ) ) );
		}
		if ( 'options' === $location ) {
			return array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => self::OPTIONS_SLUG ) ) );
		}
		$id = Content::post_id_for( $location );
		return array( array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) ( is_int( $id ) ? $id : 0 ) ) ) );
	}

	/**
	 * A schema field as ACF wants it, with a stable key.
	 *
	 * @param array  $f      Schema field.
	 * @param string $prefix Key prefix.
	 * @return array<string,mixed>
	 */
	private static function acf_field( array $f, string $prefix ): array {
		$field = $f + array( 'key' => $prefix . $f['name'] );
		if ( 'wysiwyg' === $f['type'] ) {
			$field += array(
				'tabs'         => 'visual',
				'toolbar'      => 'basic',
				'media_upload' => 0,
			);
		}
		if ( 'textarea' === $f['type'] ) {
			$field += array( 'rows' => 3, 'new_lines' => '' );
		}
		if ( 'repeater' === $f['type'] ) {
			$field['layout']       = 'block';
			$field['button_label'] = __( 'Add a question', 'bookstore-nepal' );
			$field['sub_fields']   = array_map( static fn( array $sub ): array => self::acf_field( $sub, $field['key'] . '_' ), $f['sub_fields'] );
		}
		return $field;
	}

	/**
	 * One field definition.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $type  ACF type.
	 * @param string $hint  Instructions.
	 * @return array<string,string>
	 */
	private static function field( string $name, string $label, string $type, string $hint ): array {
		$field = array(
			'name'  => $name,
			'label' => $label,
			'type'  => $type,
		);
		if ( '' !== $hint ) {
			$field['instructions'] = $hint;
		}
		return $field;
	}
}
