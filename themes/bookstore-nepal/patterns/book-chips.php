<?php
/**
 * Title: Book page chips
 * Slug: bookstore-nepal/book-chips
 * Categories: bookstore-nepal
 * Inserter: false
 * Description: Age group, language and book type of the current book.
 *
 * @package BookstoreNepal
 */

defined( 'ABSPATH' ) || exit;

$bsn_age = serialize_block_attributes(
	array(
		'term'      => 'pa_age-group',
		/* translators: Shown before a book's age range, e.g. "Ages 4–7". */
		'prefix'    => _x( 'Ages', 'before an age range', 'bookstore-nepal' ) . ' ',
		'className' => 'bsn-plain-terms bsn-pill bsn-pill--mint',
	)
);
?>
<!-- wp:group {"className":"bsn-book__chips","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group bsn-book__chips"><!-- wp:post-terms <?php echo $bsn_age; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block attributes JSON; the block escapes them when rendering. ?> /-->

<!-- wp:post-terms {"term":"pa_language","className":"bsn-plain-terms bsn-pill bsn-pill--sky"} /-->

<!-- wp:post-terms {"term":"pa_book-type","className":"bsn-plain-terms bsn-pill bsn-pill--rose"} /--></div>
<!-- /wp:group -->
