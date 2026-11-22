<?php
/**
 * Title: Contact details
 * Slug: bookstore-nepal/contact-details
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Shop phone, WhatsApp, email and address from WooCommerce settings; a bracketed placeholder shows for anything not set yet.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_contact = Theme::contact_details();
?>
<?php if ( '' !== $bsn_contact['phone'] ) : ?>
<!-- wp:paragraph {"className":"bsn-contact__line"} -->
<p class="bsn-contact__line"><?php echo esc_html__( 'Phone:', 'bookstore-nepal' ); ?> <a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $bsn_contact['phone'] ) ); ?>"><?php echo esc_html( $bsn_contact['phone'] ); ?></a></p>
<!-- /wp:paragraph -->
<?php else : ?>
<!-- wp:paragraph {"className":"bsn-placeholder"} -->
<p class="bsn-placeholder"><?php esc_html_e( 'Phone: [CLIENT PHONE – set in WooCommerce › Settings › General]', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( '' !== $bsn_contact['whatsapp'] ) : ?>
<!-- wp:paragraph {"className":"bsn-contact__line"} -->
<p class="bsn-contact__line"><?php echo esc_html__( 'WhatsApp:', 'bookstore-nepal' ); ?> <a href="<?php echo esc_url( 'https://wa.me/' . $bsn_contact['whatsapp'] ); ?>"><?php esc_html_e( 'Chat on WhatsApp', 'bookstore-nepal' ); ?></a></p>
<!-- /wp:paragraph -->
<?php else : ?>
<!-- wp:paragraph {"className":"bsn-placeholder"} -->
<p class="bsn-placeholder"><?php esc_html_e( 'WhatsApp: [CLIENT WHATSAPP – set in WooCommerce › Settings › General]', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( '' !== $bsn_contact['email'] ) : ?>
<!-- wp:paragraph {"className":"bsn-contact__line"} -->
<p class="bsn-contact__line"><?php echo esc_html__( 'Email:', 'bookstore-nepal' ); ?> <a href="<?php echo esc_url( 'mailto:' . $bsn_contact['email'] ); ?>"><?php echo esc_html( $bsn_contact['email'] ); ?></a></p>
<!-- /wp:paragraph -->
<?php else : ?>
<!-- wp:paragraph {"className":"bsn-placeholder"} -->
<p class="bsn-placeholder"><?php esc_html_e( 'Email: [CLIENT EMAIL – set in WooCommerce › Settings › General]', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( $bsn_contact['address'] ) : ?>
<!-- wp:paragraph {"className":"bsn-contact__address"} -->
<p class="bsn-contact__address"><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', $bsn_contact['address'] ) ), array( 'br' => array() ) ); ?></p>
<!-- /wp:paragraph -->
<?php else : ?>
<!-- wp:paragraph {"className":"bsn-placeholder"} -->
<p class="bsn-placeholder"><?php esc_html_e( '[CLIENT SHOP ADDRESS – set in WooCommerce › Settings › General]', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>
