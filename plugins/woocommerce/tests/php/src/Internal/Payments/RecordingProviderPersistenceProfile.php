<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;

/**
 * Test persistence profile for recording providers.
 */
class RecordingProviderPersistenceProfile extends WooPaymentsPersistenceProfile {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Provider ID.
	 */
	public function __construct( string $provider_id ) {
		$this->provider_id = $provider_id;
	}

	/**
	 * Get the provider gateway ID.
	 *
	 * @return string
	 */
	public function get_gateway_id(): string {
		return $this->provider_id;
	}

	/**
	 * Get the provider gateway ID prefix.
	 *
	 * @return string
	 */
	public function get_gateway_id_prefix(): string {
		return $this->provider_id . '_';
	}
}
