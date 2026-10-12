<?php
/**
 * CryptoCreateAnswer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AssertedRefundSigner;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Exception;

/**
 * Reads PayPal's answer to the creation of a Pay with Crypto order: accepted, refused because the merchant cannot take
 * crypto, or anything else.
 *
 * Accepted is a 200 or 201 with the `PAYER_ACTION_REQUIRED` status (probe rerun 2 answered 200). Refused is a 422 with a
 * `details[].issue` of `NOT_ENABLED_FOR_PAYMENT_SOURCE`, the answer PayPal gave for a merchant without crypto (probe Step
 * 1b). Everything else, from a network error to another refusal, says nothing about the merchant.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class CryptoCreateAnswer {

	/**
	 * PayPal created the order and waits for the payer.
	 *
	 * @since 11.3.0
	 */
	public const ACCEPTED = 'accepted';

	/**
	 * PayPal refused the order because the merchant cannot take crypto.
	 *
	 * @since 11.3.0
	 */
	public const REFUSED = 'refused';

	/**
	 * Any other answer, or none.
	 *
	 * @since 11.3.0
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * The issue PayPal names when the merchant cannot take the payment source.
	 *
	 * @since 11.3.0
	 */
	public const NOT_ENABLED_ISSUE = 'NOT_ENABLED_FOR_PAYMENT_SOURCE';

	/**
	 * The path of an order creation under the API host, without its query string.
	 */
	private const CREATE_ORDER_PATH = '#^v2/checkout/orders/?$#';

	/**
	 * What PayPal's answer says about the merchant: accepted, refused or unknown.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $response The response as wp_remote_request() returns it, or anything else.
	 * @return string One of the class's outcome constants.
	 */
	public static function outcome( $response ): string {
		$body = self::body( $response );
		$code = self::status_code( $response );
		if ( in_array( $code, array( 200, 201 ), true ) && 'PAYER_ACTION_REQUIRED' === ( $body['status'] ?? null ) ) {
			return self::ACCEPTED;
		}
		if ( 422 === $code && is_array( $body['details'] ?? null ) ) {
			foreach ( $body['details'] as $detail ) {
				if ( is_array( $detail ) && self::NOT_ENABLED_ISSUE === ( $detail['issue'] ?? null ) ) {
					return self::REFUSED;
				}
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * The HTTP status of a response, or 0 when there is none.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $response The response.
	 * @return int
	 */
	public static function status_code( $response ): int {
		return is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
	}

	/**
	 * The ID of the order PayPal created, or an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $response The response.
	 * @return string
	 */
	public static function order_id( $response ): string {
		$id = self::body( $response )['id'] ?? '';

		return is_string( $id ) ? $id : '';
	}

	/**
	 * The status of the order PayPal created, or an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $response The response.
	 * @return string
	 */
	public static function order_status( $response ): string {
		$status = self::body( $response )['status'] ?? '';

		return is_string( $status ) ? $status : '';
	}

	/**
	 * PayPal's debug ID for the request: the `paypal-debug-id` header, else the body's `debug_id`, else an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $response The response.
	 * @return string
	 */
	public static function debug_id( $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		$header = wp_remote_retrieve_header( $response, 'paypal-debug-id' );
		if ( is_string( $header ) && '' !== $header ) {
			return $header;
		}
		$debug_id = self::body( $response )['debug_id'] ?? '';

		return is_string( $debug_id ) ? $debug_id : '';
	}

	/**
	 * Whether a request creates a Pay with Crypto order on one of the transport's API hosts: a POST to
	 * `v2/checkout/orders` (query string ignored) whose JSON body has the `crypto` payment source. The signer signs
	 * exactly these, through this method.
	 *
	 * @since 11.3.0
	 *
	 * @param string            $url       The request URL.
	 * @param array             $args      The request arguments.
	 * @param PlatformTransport $transport The platform transport.
	 * @return bool
	 */
	public static function is_crypto_create( string $url, array $args, PlatformTransport $transport ): bool {
		$method = $args['method'] ?? '';
		if ( ! is_string( $method ) || 'POST' !== strtoupper( $method ) || false === strpos( $url, 'v2/checkout/orders' ) || ! $transport->is_ready() ) {
			return false;
		}
		$body = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		if ( ! is_array( $body ) || ! is_array( $body['payment_source'] ?? null ) || ! array_key_exists( 'crypto', $body['payment_source'] ) ) {
			return false;
		}

		try {
			foreach ( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ) as $app ) {
				$host = trailingslashit( $transport->host( $app ) );
				if ( 0 === strpos( $url, $host ) ) {
					return 1 === preg_match( self::CREATE_ORDER_PATH, explode( '?', substr( $url, strlen( $host ) ), 2 )[0] );
				}
			}
		} catch ( Exception $exception ) {
			return false;
		}

		return false;
	}

	/**
	 * Whether a crypto order creation was made for the given merchant: its first purchase unit names the merchant as
	 * payee, and the request carries the seller's PayPal-Auth-Assertion, as seam 10 and the signer send it. A callback
	 * of the public body filter that names another payee, or a request the signer left alone, says nothing about the
	 * merchant.
	 *
	 * @since 11.3.0
	 *
	 * @param array  $args        The request arguments.
	 * @param string $merchant_id The platform merchant's ID.
	 * @return bool
	 */
	public static function is_for_merchant( array $args, string $merchant_id ): bool {
		$body  = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		$payee = is_array( $body ) ? ( $body['purchase_units'][0]['payee']['merchant_id'] ?? null ) : null;

		return '' !== $merchant_id
			&& $merchant_id === $payee
			&& is_array( $args['headers'] ?? null )
			&& AssertedRefundSigner::has_assertion( $args['headers'] );
	}

	/**
	 * The first purchase unit's `custom_id` of a request's JSON body, or an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @param array $args The request arguments.
	 * @return string
	 */
	public static function custom_id( array $args ): string {
		$body      = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		$custom_id = is_array( $body ) ? ( $body['purchase_units'][0]['custom_id'] ?? '' ) : '';

		return is_string( $custom_id ) ? $custom_id : '';
	}

	/**
	 * A response's JSON body as an array; anything else reads as an empty array.
	 *
	 * @param mixed $response The response.
	 * @return array
	 */
	private static function body( $response ): array {
		if ( ! is_array( $response ) ) {
			return array();
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}
}
