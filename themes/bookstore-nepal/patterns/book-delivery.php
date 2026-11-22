<?php
/**
 * Title: Book page delivery box
 * Slug: bookstore-nepal/book-delivery
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Current delivery charges and ways to pay.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_rates = Theme::delivery_rates();
?>
<!-- wp:group {"tagName":"section","className":"bsn-delivery","layout":{"type":"default"}} -->
<section class="wp-block-group bsn-delivery"><!-- wp:paragraph {"className":"bsn-delivery__title"} -->
<p class="bsn-delivery__title"><strong><?php esc_html_e( 'Delivery all over Nepal', 'bookstore-nepal' ); ?></strong></p>
<!-- /wp:paragraph -->
<?php if ( null !== $bsn_rates['valley'] && null !== $bsn_rates['rest'] ) : ?>
<!-- wp:paragraph -->
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: Kathmandu Valley delivery charge, 2: rest of Nepal delivery charge. */
			__( 'Inside Kathmandu Valley: %1$s · Rest of Nepal: %2$s', 'bookstore-nepal' ),
			Theme::npr( $bsn_rates['valley'] ),
			Theme::npr( $bsn_rates['rest'] )
		)
	);
	?>
</p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php if ( null !== $bsn_rates['free_over'] ) : ?>
<!-- wp:paragraph -->
<?php /* translators: %s: order total for free delivery, e.g. NPR 2,000. */ ?>
<p><?php echo esc_html( sprintf( __( 'Free delivery on orders over %s', 'bookstore-nepal' ), Theme::npr( $bsn_rates['free_over'] ) ) ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<!-- wp:paragraph {"className":"bsn-delivery__pay"} -->
<p class="bsn-delivery__pay"><?php esc_html_e( 'Pay with eSewa, Khalti or cash on delivery', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph --></section>
<!-- /wp:group -->
