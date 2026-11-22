<?php
/**
 * Title: Header book search
 * Slug: bookstore-nepal/header-search
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Search box limited to books.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;

$bsn_search = serialize_block_attributes(
	array(
		'label'          => __( 'Search books', 'bookstore-nepal' ),
		'showLabel'      => false,
		'placeholder'    => __( 'Search by title, author or topic', 'bookstore-nepal' ),
		'buttonText'     => __( 'Search', 'bookstore-nepal' ),
		'buttonPosition' => 'button-outside',
		'buttonUseIcon'  => true,
		'query'          => array( 'post_type' => 'product' ),
		'className'      => 'bsn-search bsn-header__search',
	)
);
?>
<!-- wp:search <?php echo $bsn_search; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block attributes JSON; the search block escapes them when rendering. ?> /-->
