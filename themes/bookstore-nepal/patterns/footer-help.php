<?php
/**
 * Title: Footer help links
 * Slug: bookstore-nepal/footer-help
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Links to the delivery, returns, FAQ, order tracking and contact pages; only pages that are published.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

$bsn_links = array(
	'delivery' => __( 'Delivery information', 'bookstore-nepal' ),
	'returns'  => __( 'Returns & refunds', 'bookstore-nepal' ),
	'faq'      => __( 'FAQ', 'bookstore-nepal' ),
	'tracking' => __( 'Track your order', 'bookstore-nepal' ),
	'contact'  => __( 'Contact us', 'bookstore-nepal' ),
);
?>
<!-- wp:group {"className":"bsn-footer__col","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group bsn-footer__col"><!-- wp:paragraph {"className":"bsn-footer__heading"} -->
<p class="bsn-footer__heading"><strong><?php esc_html_e( 'Help', 'bookstore-nepal' ); ?></strong></p>
<!-- /wp:paragraph -->
<?php foreach ( $bsn_links as $bsn_key => $bsn_label ) : ?>
<?php $bsn_url = Theme::page_url( $bsn_key ); ?>
<?php if ( null !== $bsn_url ) : ?>
<!-- wp:paragraph -->
<p><a href="<?php echo esc_url( $bsn_url ); ?>"><?php echo esc_html( $bsn_label ); ?></a></p>
<!-- /wp:paragraph -->
<?php endif; ?>
<?php endforeach; ?>
</div>
<!-- /wp:group -->
