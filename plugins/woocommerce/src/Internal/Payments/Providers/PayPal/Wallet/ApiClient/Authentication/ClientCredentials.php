<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\NotFoundException;

class ClientCredentials {

	protected SettingsProvider $settings;

	public function __construct( SettingsProvider $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns encoded client credentials.
	 */
	public function credentials(): string {
		$merchant_data = $this->settings->merchant_data();
		$client_id     = $merchant_data->client_id;
		$client_secret = $merchant_data->client_secret;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
	}

	/**
	 * Whether the client ID or secret is missing.
	 */
	public function is_empty(): bool {
		$merchant_data = $this->settings->merchant_data();

		return '' === $merchant_data->client_id || '' === $merchant_data->client_secret;
	}
}
