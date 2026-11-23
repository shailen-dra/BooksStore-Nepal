<?php
/**
 * Default wording for the delivery page: box field name => content items for Theme::html().
 *
 * @package BookstoreNepal
 */

use BookstoreNepal\Theme\Theme;

defined( 'ABSPATH' ) || exit;

return array(
	'bsn_delivery_intro' => array(
			array( 'p', __( 'We deliver to all 77 districts of Nepal, straight to the address you give at checkout.', 'bookstore-nepal' ) ),
			array( 'h2', __( 'Delivery charges', 'bookstore-nepal' ) ),
	),
	'bsn_delivery_times' => array(
			array( 'p', __( 'The charge is worked out at checkout from the district you choose, so you always see the full total before you pay.', 'bookstore-nepal' ) ),
			array( 'h2', __( 'How long delivery takes', 'bookstore-nepal' ) ),
			array(
				'ul',
				array(
					esc_html__( 'Inside Kathmandu Valley: [CLIENT: 1–2 working days]', 'bookstore-nepal' ),
					esc_html__( 'Rest of Nepal: [CLIENT: 3–7 working days, depending on the district and the courier]', 'bookstore-nepal' ),
				),
			),
			array( 'p', __( 'Remote districts and public holidays can take a little longer. If your books are late, message us and we will chase the courier.', 'bookstore-nepal' ) ),
	),
	'bsn_delivery_after' => array(
			array( 'h2', __( 'Following your order', 'bookstore-nepal' ) ),
			array( 'p', __( 'Every order moves through these steps:', 'bookstore-nepal' ) ),
			array(
				'ol',
				array(
					esc_html__( 'Processing: we have your order and are getting it ready.', 'bookstore-nepal' ),
					esc_html__( 'Packed: your books are wrapped and ready to go.', 'bookstore-nepal' ),
					esc_html__( 'Shipped: your books are with the courier or rider.', 'bookstore-nepal' ),
					esc_html__( 'Delivered: your books have arrived.', 'bookstore-nepal' ),
				),
			),
			array(
				'p_html',
				sprintf(
					/* translators: %s: link to the Track your order page. */
					esc_html__( 'Check the current step any time on the %s page, with your order number and checkout email.', 'bookstore-nepal' ),
					Theme::page_link( 'tracking', __( 'Track your order', 'bookstore-nepal' ) )
				),
			),
			array( 'h2', __( 'Something wrong when your books arrive?', 'bookstore-nepal' ) ),
			array(
				'p_html',
				sprintf(
					/* translators: %s: link to the Returns and refunds page. */
					esc_html__( 'Please read our %s page and contact us straight away.', 'bookstore-nepal' ),
					Theme::page_link( 'returns', __( 'Returns & refunds', 'bookstore-nepal' ) )
				),
			),
	),
);
