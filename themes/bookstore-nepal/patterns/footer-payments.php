<?php
/**
 * Title: Footer payment methods
 * Slug: bookstore-nepal/footer-payments
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: "We accept ..." line listing the payment methods checkout offers; omitted when none are enabled.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_pay = Theme::payment_list();
if ( '' === $bsn_pay ) {
	return;
}
?>
<!-- wp:group {"align":"wide","className":"bsn-footer__note","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide bsn-footer__note"><!-- wp:paragraph -->
<?php /* translators: %s: payment methods, e.g. eSewa and cash on delivery. */ ?>
<p><?php echo esc_html( sprintf( __( 'We accept %s.', 'bookstore-nepal' ), $bsn_pay ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
