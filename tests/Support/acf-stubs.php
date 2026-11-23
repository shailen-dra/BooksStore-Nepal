<?php
/**
 * Minimal stand-ins for the ACF Pro functions the plugin uses, so tests run without ACF installed.
 * Values live where ACF keeps them: post meta by field name, or the option "options_{name}".
 *
 * @package BookstoreNepal
 */

if ( ! function_exists( 'get_field' ) ) {
	function get_field( $name, $post_id = false, $format = true ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		if ( 'option' === $post_id || 'options' === $post_id ) {
			$value = get_option( 'options_' . $name, null );
		} else {
			$value = get_post_meta( (int) $post_id, $name, true );
		}
		return ( null === $value || '' === $value ) ? null : $value;
	}
}

if ( ! function_exists( 'update_field' ) ) {
	function update_field( $name, $value, $post_id = false ) {
		if ( 'option' === $post_id || 'options' === $post_id ) {
			return update_option( 'options_' . $name, $value );
		}
		return (bool) update_post_meta( (int) $post_id, $name, $value );
	}
}

if ( ! function_exists( 'acf_add_local_field_group' ) ) {
	function acf_add_local_field_group( array $group ) {
		$GLOBALS['bsn_acf_registered']['groups'][] = $group;
		return true;
	}
}

if ( ! function_exists( 'acf_add_options_page' ) ) {
	function acf_add_options_page( array $page ) {
		$GLOBALS['bsn_acf_registered']['pages'][] = $page;
		return $page;
	}
}
