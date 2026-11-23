<?php
/**
 * Title: Page: Delivery information
 * Slug: bookstore-nepal/page-delivery
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Delivery charges (live), timing, payment on delivery and order steps.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;
use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;

$bsn_methods = Theme::payment_methods();
$bsn_pay     = array();
if ( isset( $bsn_methods['esewa'] ) ) {
	$bsn_pay[] = array( 'p', __( 'Pay online with eSewa when you check out. You are taken to eSewa to pay, and we never see your eSewa PIN or password.', 'bookstore-nepal' ) );
}
if ( isset( $bsn_methods['cod'] ) ) {
	$bsn_pay[] = array( 'p', __( 'Or choose cash on delivery and pay the rider in cash when your books arrive. Please keep the amount ready and your phone nearby so the rider can reach you.', 'bookstore-nepal' ) );
}
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_delivery_intro' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?>

<!-- wp:pattern {"slug":"bookstore-nepal/delivery-rates"} /-->

<?php echo Wording::rich( 'bsn_delivery_times' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?><?php
if ( $bsn_pay ) {
	echo Theme::blocks( array_merge( array( array( 'h2', __( 'Paying for your order', 'bookstore-nepal' ) ) ), $bsn_pay ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks().
}
?><?php echo Wording::rich( 'bsn_delivery_after' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?></div>
<!-- /wp:group -->
