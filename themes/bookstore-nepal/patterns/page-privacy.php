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

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:group {"className":"bsn-info","layout":{"type":"constrained"}} -->
<div class="wp-block-group bsn-info"><?php
echo Theme::blocks( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside Theme::blocks().
	array(
		array( 'p', __( 'This page explains what personal information we collect when you use our shop, why we collect it and what we do with it. We keep it to what we need, and we aim to handle it in line with Nepal\'s Individual Privacy Act, 2075 (2018).', 'bookstore-nepal' ) ),
		array( 'h2', __( 'What we collect', 'bookstore-nepal' ) ),
		array(
			'ul',
			array(
				esc_html__( 'Your name, phone number, email address and delivery address when you place an order.', 'bookstore-nepal' ),
				esc_html__( 'What you ordered and when, and any note you add to your order.', 'bookstore-nepal' ),
				esc_html__( 'Your messages, if you contact us by WhatsApp, phone or email.', 'bookstore-nepal' ),
				esc_html__( 'Basic information from cookies, described below.', 'bookstore-nepal' ),
			),
		),
		array( 'p', __( 'If you pay with eSewa, you pay on eSewa\'s own pages. We never see or keep your eSewa PIN, password or wallet details. eSewa tells us only whether the payment worked, with a payment reference.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Why we use it', 'bookstore-nepal' ) ),
		array(
			'ul',
			array(
				esc_html__( 'To prepare, deliver and take payment for your order.', 'bookstore-nepal' ),
				esc_html__( 'To contact you about your order, and so the rider can reach you about your delivery.', 'bookstore-nepal' ),
				esc_html__( 'To handle returns and refunds, and to answer your questions.', 'bookstore-nepal' ),
				esc_html__( 'To keep the business records we need, for example for accounts and tax.', 'bookstore-nepal' ),
			),
		),
		array( 'p', __( 'We do not sell your information.', 'bookstore-nepal' ) ),
		array( 'note', __( '[CLIENT: If you send newsletters or offers, say so here and explain how people can stop them. Otherwise write that you do not send marketing messages.]', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Who we share it with', 'bookstore-nepal' ) ),
		array(
			'ul',
			array(
				esc_html__( 'Couriers and riders, who get your name, phone number and delivery address so they can deliver your books.', 'bookstore-nepal' ),
				esc_html__( 'eSewa, when you pay with it, so the payment can be processed.', 'bookstore-nepal' ),
				esc_html__( 'The companies that host and run our website, who handle the information on our behalf. [CLIENT: Name your hosting provider.]', 'bookstore-nepal' ),
				esc_html__( 'The authorities, if the law requires us to.', 'bookstore-nepal' ),
			),
		),
		array( 'h2', __( 'Cookies', 'bookstore-nepal' ) ),
		array( 'p', __( 'We use a few cookies to make the shop work: to remember what is in your cart, to keep you signed in if you have an account, and to complete checkout. With each order, our shop software also records which page or link brought you to us, such as a search engine or a link someone shared. This is called order attribution, and it helps us see how people find the shop.', 'bookstore-nepal' ) ),
		array( 'p', __( 'You can block cookies in your browser settings, but the cart and checkout may then stop working.', 'bookstore-nepal' ) ),
		array( 'note', __( '[CLIENT: List any analytics or advertising tools you add to the site, and what they collect.]', 'bookstore-nepal' ) ),
		array( 'h2', __( 'How long we keep it', 'bookstore-nepal' ) ),
		array( 'p', __( 'We keep order records for as long as we need them for delivery, returns and our accounts, which is [CLIENT: number of years]. After that we delete them or remove your personal details.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Keeping it safe', 'bookstore-nepal' ) ),
		array( 'p', __( 'Your information is stored on a secure server, and only the people who need it to run the shop can see it.', 'bookstore-nepal' ) ),
		array( 'h2', __( 'Your rights', 'bookstore-nepal' ) ),
		array( 'p', __( 'You can ask us:', 'bookstore-nepal' ) ),
		array(
			'ul',
			array(
				esc_html__( 'what personal information we hold about you,', 'bookstore-nepal' ),
				esc_html__( 'to correct anything that is wrong,', 'bookstore-nepal' ),
				esc_html__( 'to delete your information, except records we must keep by law.', 'bookstore-nepal' ),
			),
		),
		array(
			'p_html',
			sprintf(
				/* translators: %s: link to the Contact page. */
				esc_html__( 'Just get in touch using the details on the %s page. We reply within [CLIENT: 30 days].', 'bookstore-nepal' ),
				Theme::page_link( 'contact', __( 'Contact us', 'bookstore-nepal' ) )
			),
		),
		array( 'h2', __( 'Changes to this page', 'bookstore-nepal' ) ),
		array( 'p', __( 'We may update this policy from time to time. The latest version is always on this page. Last updated: [CLIENT: date].', 'bookstore-nepal' ) ),
	)
);
?></div>
<!-- /wp:group -->
