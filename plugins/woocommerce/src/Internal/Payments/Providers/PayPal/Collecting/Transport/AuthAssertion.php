<?php
/**
 * AuthAssertion class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

/**
 * The PayPal-Auth-Assertion header: an unsigned JWT that names the app making a call and the seller it acts for.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class AuthAssertion {

	/**
	 * The header for calls an app makes on behalf of a seller.
	 *
	 * @since 11.3.0
	 *
	 * @param string $client_id   The client ID of the app making the call.
	 * @param string $merchant_id The seller's merchant ID.
	 * @return array<string, string>
	 */
	public static function header( string $client_id, string $merchant_id ): array {
		$header = self::encode( '{"alg":"none"}' );
		$claims = self::encode(
			(string) wp_json_encode(
				array(
					'iss'      => $client_id,
					'payer_id' => $merchant_id,
				),
				JSON_UNESCAPED_SLASHES
			)
		);

		return array( 'PayPal-Auth-Assertion' => $header . '.' . $claims . '.' );
	}

	/**
	 * Base64url-encode a string, without padding.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a JWT segment, not obfuscation.
	}
}
