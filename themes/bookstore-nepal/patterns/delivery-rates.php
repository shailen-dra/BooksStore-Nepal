<?php
/**
 * Title: Delivery rates
 * Slug: bookstore-nepal/delivery-rates
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Live delivery charges and the free-delivery threshold, read from the shipping zones each time the page loads.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_rates = Theme::delivery_rates();
?>
<!-- wp:group {"tagName":"section","className":"bsn-delivery bsn-delivery--page","layout":{"type":"default"}} -->
<section class="wp-block-group bsn-delivery bsn-delivery--page"><!-- wp:list {"className":"bsn-rates"} -->
<ul class="wp-block-list bsn-rates"><?php if ( null !== $bsn_rates['valley'] ) : ?><!-- wp:list-item -->
<li><?php echo esc_html( sprintf( /* translators: %s: delivery charge, e.g. NPR 100. */ __( 'Inside Kathmandu Valley (Kathmandu, Lalitpur and Bhaktapur): %s', 'bookstore-nepal' ), Theme::npr( $bsn_rates['valley'] ) ) ); ?></li>
<!-- /wp:list-item --><?php endif; ?><?php if ( null !== $bsn_rates['rest'] ) : ?><!-- wp:list-item -->
<li><?php echo esc_html( sprintf( /* translators: %s: delivery charge, e.g. NPR 200. */ __( 'Everywhere else in Nepal (the other 74 districts): %s', 'bookstore-nepal' ), Theme::npr( $bsn_rates['rest'] ) ) ); ?></li>
<!-- /wp:list-item --><?php endif; ?><?php if ( null !== $bsn_rates['free_over'] ) : ?><!-- wp:list-item -->
<li><?php echo esc_html( sprintf( /* translators: %s: order total, e.g. NPR 2,000. */ __( 'Free delivery anywhere in Nepal on orders over %s', 'bookstore-nepal' ), Theme::npr( $bsn_rates['free_over'] ) ) ); ?></li>
<!-- /wp:list-item --><?php endif; ?></ul>
<!-- /wp:list --></section>
<!-- /wp:group -->
