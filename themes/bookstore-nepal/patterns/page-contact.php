<?php
/**
 * Title: Page: Contact us
 * Slug: bookstore-nepal/page-contact
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Contact details, opening hours and a link to order tracking.
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Wording;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php echo Wording::rich( 'bsn_contact_intro' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?>

<!-- wp:group {"className":"bsn-contact","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-contact"><!-- wp:pattern {"slug":"bookstore-nepal/contact-details"} /--></div>
<!-- /wp:group -->

<?php echo Wording::rich( 'bsn_contact_after' ); // phpcs:ignore WordPress.Security.EscapeOutput -- made safe by wp_kses_post. ?></div>
<!-- /wp:group -->
