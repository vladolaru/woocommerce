<?php
/**
 * Recorded answer for the platform's public fraud-services endpoint.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Answers `GET wpcom/v2/wcpay/accounts/fraud_services` with the response recorded from the local WPCOM.
 *
 * Native tests may not reach the network, and the payment fields fetch this public config when the account data
 * carries none. The hooks restore at the end of each test removes the answer.
 */
final class RecordedPublicFraudServices {

	private const FIXTURE = __DIR__ . '/rec-t63-public-fraud-services.json';

	/**
	 * Answer the public fraud-services request for the rest of the current test.
	 */
	public static function answer(): void {
		add_filter( 'pre_http_request', array( self::class, 'respond' ), 10, 3 );
	}

	/**
	 * Return the recorded response for the public fraud-services URL; leave every other request alone.
	 *
	 * @param mixed  $response Response from earlier callbacks, or false.
	 * @param mixed  $args     Request arguments.
	 * @param string $url      Request URL.
	 * @return mixed
	 */
	public static function respond( $response, $args, $url ) {
		unset( $args );
		$entry = json_decode( (string) file_get_contents( self::FIXTURE ), true )['entries'][0]; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( false !== $response || substr( $path, -strlen( $entry['request']['path'] ) ) !== $entry['request']['path'] ) {
			return $response;
		}

		return array(
			'headers'  => array( 'content-type' => $entry['response']['content_type'] ),
			'body'     => $entry['response']['body'],
			'response' => array(
				'code'    => $entry['response']['status'],
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
