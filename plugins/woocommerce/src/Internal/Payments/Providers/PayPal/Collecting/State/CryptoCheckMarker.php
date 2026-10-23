<?php
/**
 * CryptoCheckMarker class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

/**
 * Whether PayPal last accepted a Pay with Crypto order for the platform merchant: the answer of the Pay with Crypto check.
 *
 * The partner's view of a platform merchant never lists the crypto capability, so a platform-connected store takes the
 * answer from an order PayPal accepted or refused instead. The answer counts only for the merchant and environment it was
 * recorded for: once the platform option names another, it reads as no answer, and nothing is written.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CryptoCheckMarker {

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options The option reader.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Whether the last answer for the platform merchant says PayPal accepts Pay with Crypto. No answer reads as false.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$marker = $this->current();

		return null !== $marker && true === $marker['available'];
	}

	/**
	 * The last answer for the platform merchant, or null when there is none, or it was recorded for another merchant or
	 * environment.
	 *
	 * @since 11.3.0
	 *
	 * @return array{available: bool, checked_at: int, environment: string, merchant_id: string}|null
	 */
	public function current(): ?array {
		$marker       = $this->options->pwc_check();
		$platform     = $this->platform();
		$recorded_for = array(
			'merchant_id' => $marker['merchant_id'] ?? '',
			'environment' => $marker['environment'] ?? '',
		);
		if ( null === $platform || $recorded_for !== $platform || ! is_bool( $marker['available'] ?? null ) || ! is_int( $marker['checked_at'] ?? null ) ) {
			return null;
		}

		return array(
			'available'   => $marker['available'],
			'checked_at'  => $marker['checked_at'],
			'environment' => $platform['environment'],
			'merchant_id' => $platform['merchant_id'],
		);
	}

	/**
	 * Record PayPal's answer for the platform merchant and environment.
	 *
	 * The answer is written only when the environment the request went to is the platform connection's: an answer from
	 * the other environment says nothing about this connection.
	 *
	 * @since 11.3.0
	 *
	 * @param bool   $available   Whether PayPal accepted a crypto order for the merchant.
	 * @param string $environment The environment the order request went to, as the transport serves it.
	 * @return bool False when the platform option names no merchant and environment, or another environment, so nothing
	 *              was written.
	 */
	public function record( bool $available, string $environment ): bool {
		$platform = $this->platform();
		if ( null === $platform || $environment !== $platform['environment'] ) {
			return false;
		}

		update_option(
			Options::PWC_CHECK,
			array(
				'available'   => $available,
				'checked_at'  => time(),
				'environment' => $platform['environment'],
				'merchant_id' => $platform['merchant_id'],
			),
			true
		);

		return true;
	}

	/**
	 * The platform merchant's ID, or an empty string when the platform option names none.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function platform_merchant_id(): string {
		$merchant_id = $this->options->platform()['merchant_id'] ?? '';

		return is_string( $merchant_id ) ? $merchant_id : '';
	}

	/**
	 * The platform connection's environment, or an empty string when the platform option names none.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function platform_environment(): string {
		$environment = $this->options->platform()['environment'] ?? '';

		return is_string( $environment ) ? $environment : '';
	}

	/**
	 * The platform option's merchant ID and environment, or null when either is missing.
	 *
	 * @return array{merchant_id: string, environment: string}|null
	 */
	private function platform(): ?array {
		$platform    = $this->options->platform();
		$merchant_id = $platform['merchant_id'] ?? null;
		$environment = $platform['environment'] ?? null;
		if ( ! is_string( $merchant_id ) || '' === $merchant_id || ! is_string( $environment ) || '' === $environment ) {
			return null;
		}

		return array(
			'merchant_id' => $merchant_id,
			'environment' => $environment,
		);
	}
}
