<?php
/**
 * eSewa ePay v2 HMAC-SHA256 signatures.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Signs request fields and verifies callback payloads.
 */
class EsewaSignature {

	/**
	 * Signs the named fields: base64( HMAC-SHA256( "a=1,b=2,...", secret ) ).
	 *
	 * @param array    $fields Field values keyed by name.
	 * @param string[] $names  Field names to sign, in order.
	 * @param string   $secret Merchant secret key.
	 * @return string Base64 signature.
	 */
	public static function sign( array $fields, array $names, string $secret ): string {
		$pairs = array();
		foreach ( $names as $name ) {
			$value   = $fields[ $name ] ?? '';
			$pairs[] = $name . '=' . ( is_scalar( $value ) ? (string) $value : '' );
		}
		return base64_encode( hash_hmac( 'sha256', implode( ',', $pairs ), $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Verifies a decoded callback payload against its own signed_field_names.
	 *
	 * Every signed field must be present and scalar; comparison is constant-time.
	 *
	 * @param array  $payload Decoded callback data.
	 * @param string $secret  Merchant secret key.
	 * @return bool
	 */
	public static function verify( array $payload, string $secret ): bool {
		if ( '' === $secret ) {
			return false;
		}
		$signature = $payload['signature'] ?? null;
		$names     = $payload['signed_field_names'] ?? null;
		if ( ! is_string( $signature ) || '' === $signature || ! is_string( $names ) || '' === $names ) {
			return false;
		}
		$names = explode( ',', $names );
		foreach ( $names as $name ) {
			if ( '' === $name || ! array_key_exists( $name, $payload ) || ! is_scalar( $payload[ $name ] ) ) {
				return false;
			}
		}
		return hash_equals( self::sign( $payload, $names, $secret ), $signature );
	}
}
