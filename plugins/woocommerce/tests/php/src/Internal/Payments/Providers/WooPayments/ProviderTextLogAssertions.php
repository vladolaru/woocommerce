<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;

/**
 * Checks that a log line keeps platform, Stripe and transport text out, whatever a failure carried.
 */
trait ProviderTextLogAssertions {

	/**
	 * Text a platform error carries in its message here, none of which may reach a log line.
	 *
	 * @var string[]
	 */
	private static array $provider_leak_fragments = array( 'No such customer', 'shopper@example.com', 'pay.example.test', 'sk_test_leak123' );

	/**
	 * Build the platform error the transport throws for an error envelope whose message holds an email, a URL and a key.
	 *
	 * Client 11.1.0 check_response_for_errors() (class-wc-payments-api-client.php) reads the envelope's error.code,
	 * error.message, error.type and error.decline_code (:2852-2871) and throws API_Exception( 'Error: ' . message, code,
	 * HTTP status, type, decline code ) (:2906-2909, :2928). Its mapped variants, the top-level amount_too_small shape
	 * (:2845-2851) and the mixed-currencies message (:2876-2892), go through the real mapping in the Stripe Billing tests.
	 *
	 * @param string $code         Envelope error.code.
	 * @param int    $status       HTTP status.
	 * @param string $decline_code Envelope error.decline_code.
	 * @return WooPaymentsApiException
	 */
	public static function make_provider_error( string $code = 'resource_missing', int $status = 404, string $decline_code = '' ): WooPaymentsApiException {
		return new WooPaymentsApiException(
			"Error: No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123",
			$code,
			$status,
			'invalid_request_error',
			$decline_code
		);
	}

	/**
	 * Turn WooPayments debug logging on, so gated lines are written.
	 */
	private static function enable_woopayments_debug_logging(): void {
		$settings                   = get_option( 'woocommerce_woocommerce_payments_settings', array() );
		$settings                   = is_array( $settings ) ? $settings : array();
		$settings['enable_logging'] = 'yes';
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );
	}

	/**
	 * Assert something was logged and no recorded message or context holds the platform error's text.
	 *
	 * @param RecordingWcLogger $logger Recording logger.
	 */
	private function assert_log_holds_no_provider_text( RecordingWcLogger $logger ): void {
		$this->assertNotSame( array(), $logger->lines, 'The failure is logged.' );
		foreach ( $logger->lines as $index => $line ) {
			$written = $line[1] . ' ' . (string) wp_json_encode( $logger->contexts[ $index ] );
			foreach ( self::$provider_leak_fragments as $fragment ) {
				$this->assertStringNotContainsString( $fragment, $written, 'A log line carries platform text: ' . $written );
			}
		}
	}

	/**
	 * Get the context of the one recorded line with this message.
	 *
	 * @param RecordingWcLogger $logger  Recording logger.
	 * @param string            $message Exact message.
	 * @return array<string,mixed>
	 */
	private function get_logged_context( RecordingWcLogger $logger, string $message ): array {
		$found = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => $message === $line[1] ) );
		$this->assertCount( 1, $found, 'One line reads: ' . $message . '. Recorded: ' . (string) wp_json_encode( array_column( $logger->lines, 1 ) ) );

		return $logger->contexts[ $found[0] ];
	}
}
