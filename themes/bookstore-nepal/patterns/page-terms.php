<?php
/**
 * Title: Page: Terms & conditions
 * Slug: bookstore-nepal/page-terms
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Terms of sale; to be reviewed with the client before launch.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_terms_text' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by Wording::clean(). ?></div>
<!-- /wp:group -->
