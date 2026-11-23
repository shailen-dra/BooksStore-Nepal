<?php
/**
 * Title: Page: Returns & refunds
 * Slug: bookstore-nepal/page-returns
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Returns and refunds policy; the client confirms the bracketed details.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_returns_text' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?></div>
<!-- /wp:group -->
