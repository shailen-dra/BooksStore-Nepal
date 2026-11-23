<?php
/**
 * Title: Gift finder
 * Slug: bookstore-nepal/gift-finder
 * Categories: bookstore-nepal
 * Description: Age and budget form that shows matching books.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"align":"wide","anchor":"gift","className":"bsn-section bsn-gift","layout":{"type":"default"}} -->
<div id="gift" class="wp-block-group alignwide bsn-section bsn-gift"><!-- wp:group {"className":"bsn-gift__text","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-gift__text"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html( Wording::text( 'bsn_gift_heading' ) ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( Wording::text( 'bsn_gift_text' ) ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:shortcode -->
[bsn_gift_finder]
<!-- /wp:shortcode --></div>
<!-- /wp:group -->
