<?php
/**
 * Title: Gift finder
 * Slug: bookstore-nepal/gift-finder
 * Categories: bookstore-nepal
 * Description: Age and budget form that shows matching books.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","anchor":"gift","className":"bsn-section bsn-gift","layout":{"type":"default"}} -->
<div id="gift" class="wp-block-group alignwide bsn-section bsn-gift"><!-- wp:group {"className":"bsn-gift__text","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-gift__text"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Looking for a gift?', 'bookstore-nepal' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'Tell us the child’s age and your budget — we’ll show books they’ll love.', 'bookstore-nepal' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:shortcode -->
[bsn_gift_finder]
<!-- /wp:shortcode --></div>
<!-- /wp:group -->
