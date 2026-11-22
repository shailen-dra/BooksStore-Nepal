<?php
/**
 * Title: Trust strip
 * Slug: bookstore-nepal/trust-strip
 * Categories: bookstore-nepal
 * Description: Ways to pay and delivery coverage.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;

$bsn_card  = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="6" width="18" height="13" rx="3"></rect><path d="M16 12h2"></path><path d="M3 10h18"></path></svg>';
$bsn_items = array(
	array( $bsn_card, __( 'Pay with eSewa', 'bookstore-nepal' ) ),
	array( $bsn_card, __( 'Pay with Khalti', 'bookstore-nepal' ) ),
	array( '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="7" width="20" height="11" rx="2"></rect><circle cx="12" cy="12.5" r="2.5"></circle></svg>', __( 'Cash on delivery', 'bookstore-nepal' ) ),
	array( '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 7h11v9H2z"></path><path d="M13 10h4l3 3v3h-7z"></path><circle cx="6" cy="18" r="1.6"></circle><circle cx="17" cy="18" r="1.6"></circle></svg>', __( 'Delivery all over Nepal', 'bookstore-nepal' ) ),
);
?>
<!-- wp:group {"tagName":"section","align":"full","className":"bsn-trust","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull bsn-trust"><!-- wp:html -->
<ul class="bsn-trust__list alignwide" aria-label="<?php esc_attr_e( 'Ways to pay and delivery', 'bookstore-nepal' ); ?>">
<?php foreach ( $bsn_items as $bsn_item ) : ?>
<li><?php echo $bsn_item[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( $bsn_item[1] ); ?></li>
<?php endforeach; ?>
</ul>
<!-- /wp:html --></section>
<!-- /wp:group -->
