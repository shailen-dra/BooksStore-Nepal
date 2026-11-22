<?php
/**
 * Title: Footer legal links
 * Slug: bookstore-nepal/footer-legal
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Privacy policy, Terms and conditions and About us links; only pages that are published.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_links = array(
	'privacy' => __( 'Privacy policy', 'bookstore-nepal' ),
	'terms'   => __( 'Terms & conditions', 'bookstore-nepal' ),
	'about'   => __( 'About us', 'bookstore-nepal' ),
);
$bsn_items = array();
foreach ( $bsn_links as $bsn_key => $bsn_label ) {
	$bsn_url = Theme::page_url( $bsn_key );
	if ( null !== $bsn_url ) {
		$bsn_items[] = '<a href="' . esc_url( $bsn_url ) . '">' . esc_html( $bsn_label ) . '</a>';
	}
}
if ( ! $bsn_items ) {
	return;
}
?>
<!-- wp:group {"align":"wide","className":"bsn-footer__note bsn-footer__legal","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-footer__note bsn-footer__legal"><!-- wp:paragraph -->
<p><?php echo wp_kses( implode( ' · ', $bsn_items ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
