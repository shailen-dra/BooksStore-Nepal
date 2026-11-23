<?php
/**
 * Title: Page: Privacy policy
 * Slug: bookstore-nepal/page-privacy
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: What the shop collects, why, who sees it and a customer's rights; to be reviewed with the client before launch.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_privacy_text' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?></div>
<!-- /wp:group -->
