<?php
/**
 * Title: Footer about
 * Slug: bookstore-nepal/footer-about
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Store name and a one-line description.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-footer__col bsn-footer__about","layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group bsn-footer__col bsn-footer__about"><!-- wp:site-title {"level":0,"isLink":false,"className":"bsn-footer__brand"} /-->

<!-- wp:paragraph -->
<p><?php echo esc_html( Wording::text( 'bsn_footer_about' ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
