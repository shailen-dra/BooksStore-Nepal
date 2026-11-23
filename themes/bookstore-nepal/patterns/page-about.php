<?php
/**
 * Title: Page: About us
 * Slug: bookstore-nepal/page-about
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Who the shop is and what it sells; the page text is created from this pattern by wp bsn setup.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_about_text' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by Wording::clean(). ?></div>
<!-- /wp:group -->
