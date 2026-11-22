<?php
/**
 * Shop contact details: phone, WhatsApp and email, kept in WooCommerce settings.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the details shown on the Contact page and in the footer. The address is WooCommerce's own
 * store address; the theme reads all of it through Theme::contact_details().
 */
class ShopContact {

	public const PHONE    = 'bsn_shop_phone';
	public const WHATSAPP = 'bsn_shop_whatsapp';
	public const EMAIL    = 'bsn_shop_email';

	/**
	 * Hooks the settings fields and their sanitising.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_general_settings', array( self::class, 'add_fields' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::PHONE, array( self::class, 'sanitize_phone' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::WHATSAPP, array( self::class, 'sanitize_whatsapp' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::EMAIL, array( self::class, 'sanitize_email' ) );
	}

	/**
	 * Adds the three fields at the end of the store address block.
	 *
	 * @param mixed $settings WooCommerce general settings fields.
	 * @return mixed
	 */
	public static function add_fields( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$fields = array(
			array(
				'title'       => __( 'Shop phone', 'bookstore-nepal' ),
				'desc'        => __( 'Shown to customers on the Contact page and in the footer.', 'bookstore-nepal' ),
				'id'          => self::PHONE,
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '+977 1 4XXXXXX',
				'desc_tip'    => true,
			),
			array(
				'title'       => __( 'WhatsApp number', 'bookstore-nepal' ),
				'desc'        => __( 'Digits only, with the country code, e.g. 97798XXXXXXXX. Customers get a "Chat on WhatsApp" link.', 'bookstore-nepal' ),
				'id'          => self::WHATSAPP,
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '97798XXXXXXXX',
				'desc_tip'    => true,
			),
			array(
				'title'    => __( 'Contact email', 'bookstore-nepal' ),
				'desc'     => __( 'Shown to customers on the Contact page and in the footer. Leave empty to use the site admin email.', 'bookstore-nepal' ),
				'id'       => self::EMAIL,
				'type'     => 'email',
				'default'  => (string) get_option( 'admin_email' ),
				'desc_tip' => true,
			),
		);

		$out      = array();
		$inserted = false;
		foreach ( $settings as $field ) {
			if ( ! $inserted && is_array( $field ) && 'sectionend' === ( $field['type'] ?? '' ) && 'store_address' === ( $field['id'] ?? '' ) ) {
				$out      = array_merge( $out, $fields );
				$inserted = true;
			}
			$out[] = $field;
		}
		return $inserted ? $out : array_merge( $settings, $fields );
	}

	/**
	 * Keeps digits, spaces and the usual phone punctuation.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_phone( $value ): string {
		$value = preg_replace( '/[^0-9+()\-\s]/', '', is_scalar( $value ) ? (string) $value : '' );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Digits only, so the value drops straight into https://wa.me/<digits>.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_whatsapp( $value ): string {
		return (string) preg_replace( '/\D+/', '', is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * A valid email address, or an empty string.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_email( $value ): string {
		$email = sanitize_email( is_scalar( $value ) ? (string) $value : '' );
		return is_email( $email ) ? $email : '';
	}
}
