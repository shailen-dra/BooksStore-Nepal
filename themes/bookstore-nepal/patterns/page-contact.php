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

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php
echo Theme::blocks( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks().
	array(
		array( 'p', __( 'We are happy to help with an order, a gift idea or a question about a book. WhatsApp is the fastest way to reach us.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Get in touch', 'bookstore-nepal' ) ),
	)
);
?>

<!-- wp:group {"className":"bsn-contact","layout":{"type":"default"}} -->
<div class="wp-block-group bsn-contact"><!-- wp:pattern {"slug":"bookstore-nepal/contact-details"} /--></div>
<!-- /wp:group -->

<?php
echo Theme::blocks( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks().
	array(
		array( 'h2', __( 'Opening hours', 'bookstore-nepal' ) ),
		array( 'note', __( '[CLIENT: Add the days and hours when someone answers messages and calls, e.g. Sunday to Friday, 10:00 to 18:00 Nepal time.]', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Already placed an order?', 'bookstore-nepal' ) ),
		array(
			'p_html',
			sprintf(
				/* translators: %s: link to the Track your order page. */
				esc_html__( 'See where your books are on the %s page. You will need your order number and the email address you used at checkout. If you need to change something, message us on WhatsApp as soon as you can.', 'bookstore-nepal' ),
				Theme::page_link( 'tracking', __( 'Track your order', 'bookstore-nepal' ) )
			),
		),
	)
);
?></div>
<!-- /wp:group -->
