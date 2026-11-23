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
		$text = static fn( string $name, string $label, string $hint = '', string $width = '' ): array => self::field( $name, $label, 'text', $hint, $width );
		$textarea = static fn( string $name, string $label, string $hint = '', string $width = '' ): array => self::field( $name, $label, 'textarea', $hint, $width );
		$rich = static fn( string $name, string $label, string $hint = '', string $width = '' ): array => self::field( $name, $label, 'wysiwyg', $hint, $width );

		return array(
			'home_hero'  => array(
				'title'    => __( '1. Top of the page', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_hero_pill', __( 'Small label above the headline', 'bookstore-nepal' ), '', '35' ),
					$text( 'bsn_hero_headline', __( 'Headline', 'bookstore-nepal' ), __( 'The big words at the top of the home page.', 'bookstore-nepal' ), '65' ),
					$textarea( 'bsn_hero_lead', __( 'Text under the headline', 'bookstore-nepal' ) ),
					$text( 'bsn_hero_button_ages', __( 'First button', 'bookstore-nepal' ), __( 'Takes visitors to the "Shop by age" tiles.', 'bookstore-nepal' ), '50' ),
					$text( 'bsn_hero_button_gift', __( 'Second button', 'bookstore-nepal' ), __( 'Takes visitors to the gift finder.', 'bookstore-nepal' ), '50' ),
					$text( 'bsn_hero_book_1', __( 'Drawn book 1', 'bookstore-nepal' ), __( 'The three drawn books beside the headline. Keep the titles short.', 'bookstore-nepal' ), '33' ),
					$text( 'bsn_hero_book_2', __( 'Drawn book 2', 'bookstore-nepal' ), '', '33' ),
					$text( 'bsn_hero_book_3', __( 'Drawn book 3', 'bookstore-nepal' ), '', '33' ),
				),
			),
			'home_ages'  => array(
				'title'    => __( '2. Shop by age', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_ages_heading', __( 'Heading', 'bookstore-nepal' ) ),
					$text( 'bsn_age_0_3_title', __( 'Ages 0–3: name', 'bookstore-nepal' ), '', '40' ),
					$text( 'bsn_age_0_3_text', __( 'Ages 0–3: description', 'bookstore-nepal' ), '', '60' ),
					$text( 'bsn_age_4_7_title', __( 'Ages 4–7: name', 'bookstore-nepal' ), '', '40' ),
					$text( 'bsn_age_4_7_text', __( 'Ages 4–7: description', 'bookstore-nepal' ), '', '60' ),
					$text( 'bsn_age_8_12_title', __( 'Ages 8–12: name', 'bookstore-nepal' ), '', '40' ),
					$text( 'bsn_age_8_12_text', __( 'Ages 8–12: description', 'bookstore-nepal' ), '', '60' ),
				),
			),
			'home_rows'  => array(
				'title'    => __( '3. Book rows', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_new_heading', __( 'New arrivals: heading', 'bookstore-nepal' ), __( 'The newest books, filled in by themselves.', 'bookstore-nepal' ), '50' ),
					$text( 'bsn_new_more', __( 'New arrivals: "see all" link', 'bookstore-nepal' ), '', '50' ),
					self::picker( 'bsn_new_books', __( 'New arrivals: choose books (optional)', 'bookstore-nepal' ), __( 'Pick up to 12 books and drag them into the order you want. Leave empty to show the newest books automatically.', 'bookstore-nepal' ) ),
					$text( 'bsn_best_heading', __( 'Best sellers: heading', 'bookstore-nepal' ), __( 'The books that sold most, filled in by themselves.', 'bookstore-nepal' ), '50' ),
					$text( 'bsn_best_more', __( 'Best sellers: "see all" link', 'bookstore-nepal' ), '', '50' ),
					self::picker( 'bsn_best_books', __( 'Best sellers: choose books (optional)', 'bookstore-nepal' ), __( 'Pick up to 12 books and drag them into the order you want. Leave empty to show the best-selling books automatically. Books that are out of stock are hidden.', 'bookstore-nepal' ) ),
					$text( 'bsn_featured_heading', __( 'Favourites: heading', 'bookstore-nepal' ), __( 'The books starred as "featured" in Products.', 'bookstore-nepal' ), '50' ),
					$text( 'bsn_featured_more', __( 'Favourites: "see all" link', 'bookstore-nepal' ), '', '50' ),
				),
			),
			'home_gift'  => array(
				'title'    => __( '4. Gift finder', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_gift_heading', __( 'Heading', 'bookstore-nepal' ), '', '40' ),
					$textarea( 'bsn_gift_text', __( 'Text', 'bookstore-nepal' ), '', '60' ),
				),
			),
			'home_trust' => array(
				'title'    => __( '5. Dark strip near the bottom', 'bookstore-nepal' ),
				'location' => 'front_page',
				'fields'   => array(
					$text( 'bsn_trust_delivery', __( 'Delivery line', 'bookstore-nepal' ), __( 'The ways to pay in this strip update by themselves.', 'bookstore-nepal' ) ),
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
					array(
						'name'     => 'bsn_settings_home_hint',
						'label'    => __( 'Home page wording and slider books', 'bookstore-nepal' ),
						'type'     => 'message',
						'esc_html' => 0,
						'message'  => self::home_hint(),
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
			'style'          => 'default',
			'menu_order'     => (int) array_search( $key, array_keys( self::schema() ), true ),
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
		// A page rewritten in the block editor shows its own blocks, so its boxes would do nothing: hide them.
		$show = is_int( $id ) && ! Content::is_detached( substr( $location, 5 ) );
		return array( array( array( 'param' => 'page', 'operator' => '==', 'value' => (string) ( $show ? $id : 0 ) ) ) );
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
		if ( isset( $field['width'] ) ) {
			$field['wrapper'] = array( 'width' => $field['width'] );
			unset( $field['width'] );
		}
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
	 * Where the home page form is, with a link to it when the home page exists.
	 */
	private static function home_hint(): string {
		$text  = esc_html__( 'The home page wording, and the books shown in the New arrivals and Best sellers sliders, are in Pages › Home. The slider books are in section "3. Book rows".', 'bookstore-nepal' );
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( ! $front ) {
			return $text;
		}
		return $text . ' <a href="' . esc_url( admin_url( 'post.php?post=' . $front . '&action=edit' ) ) . '">' . esc_html__( 'Open the Home page form', 'bookstore-nepal' ) . '</a>';
	}

	/**
	 * A book picker for a slider: published products, searchable, up to 12, stored as IDs in order.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $hint  Instructions.
	 * @return array<string,mixed>
	 */
	private static function picker( string $name, string $label, string $hint ): array {
		return array(
			'name'          => $name,
			'label'         => $label,
			'type'          => 'relationship',
			'instructions'  => $hint,
			'post_type'     => array( 'product' ),
			'post_status'   => array( 'publish' ),
			'filters'       => array( 'search' ),
			'elements'      => array( 'featured_image' ),
			'return_format' => 'id',
			'max'           => 12,
		);
	}

	/**
	 * One field definition.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $type  ACF type.
	 * @param string $hint  Instructions.
	 * @param string $width Share of the row in percent, for boxes that sit side by side.
	 * @return array<string,string>
	 */
	private static function field( string $name, string $label, string $type, string $hint, string $width = '' ): array {
		$field = array(
			'name'  => $name,
			'label' => $label,
			'type'  => $type,
		);
		if ( '' !== $hint ) {
			$field['instructions'] = $hint;
		}
		if ( '' !== $width ) {
			$field['width'] = $width;
		}
		return $field;
	}
}
