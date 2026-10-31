<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

/**
 * Seals envelopes the way hosted WooPay does for a store: AES-256-CBC under the key HKDF derives from the store's blog
 * token for a label (or the blog token itself), the HMAC-SHA256 over the IV and the ciphertext, each field base64. The
 * shape is client 11.2.0's test sealer (tests/unit/woopay/test-class-woopay-session.php:272-300, :1173-1200) and
 * `WooPay_Utilities::derive_key_for()` (includes/woopay/class-woopay-utilities.php:359-367).
 */
class WooPayEnvelopes {

	/**
	 * Blog token the tests connect the store with.
	 */
	public const BLOG_TOKEN = 'blog-token';

	/**
	 * Store the test blog token and ID, as a connected store has them.
	 */
	public static function connect_store(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		\Jetpack_Options::update_option( 'blog_token', self::BLOG_TOKEN );
	}

	/**
	 * Seal a payload.
	 *
	 * @param array<string,mixed> $payload    Payload.
	 * @param string|null         $purpose    HKDF label of the key, or null for the blog token itself.
	 * @param string              $blog_token Blog token the key comes from.
	 * @return array{data:string,iv:string,hash:string}
	 */
	public static function seal( array $payload, ?string $purpose, string $blog_token = self::BLOG_TOKEN ): array {
		$key        = null === $purpose ? $blog_token : hash_hkdf( 'sha256', $blog_token, 32, $purpose );
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( (string) wp_json_encode( $payload ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'Could not seal the WooPay envelope fixture.' );
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The envelope's wire encoding.
		return array(
			'data' => base64_encode( $ciphertext ),
			'iv'   => base64_encode( $iv ),
			'hash' => base64_encode( hash_hmac( 'sha256', $iv . $ciphertext, $key ) ),
		);
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Build the `X-WooPay-Attestation` vouch header WooPay seals onto a proxied checkout request: the envelope under the
	 * vouch key, bound to the sha256 of the request's Cart-Token, JSON then base64 (client 11.2.0
	 * tests/unit/woopay/test-class-woopay-session.php:272-300).
	 *
	 * @param string              $cart_token Cart-Token the vouch is bound to.
	 * @param array<string,mixed> $extra      Payload fields beside the timestamp and the cart binding, which they may replace.
	 * @return string
	 */
	public static function vouch_header( string $cart_token, array $extra = array() ): string {
		$payload = array_merge(
			array(
				'timestamp'  => time(),
				'cart_token' => hash( 'sha256', $cart_token ),
			),
			$extra
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The header's wire encoding.
		return base64_encode( (string) wp_json_encode( self::seal( $payload, 'woopay-vouch-v1' ) ) );
	}
}
