<?php
/**
 * PlatformPWCProductStatus class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CryptoCheckMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PWCProductStatus;

/**
 * The Pay with Crypto capability of a store the platform serves: no while the store collects, and the Pay with Crypto
 * check's answer instead of the seller status once platform connected.
 *
 * A collecting store has no PayPal account, so it cannot hold the capability. The seller status the platform reads is the
 * partner's view of the merchant, which never lists a product the merchant added on their own account, so it never shows
 * the crypto capability (probe rerun 2). Once platform connected, the answer is the stored check's (`CryptoCheckMarker`),
 * and no answer means not available. In both states `is_active()` neither reads nor writes the shared result cache.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformPWCProductStatus extends PWCProductStatus {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The Pay with Crypto check's answer.
	 *
	 * @var CryptoCheckMarker
	 */
	private CryptoCheckMarker $marker;

	/**
	 * Constructor.
	 *
	 * @param bool                     $is_connected         Whether the merchant onboarding was completed.
	 * @param PartnersEndpoint         $partners_endpoint    The partners endpoint.
	 * @param FailureRegistry          $api_failure_registry The API failure registry.
	 * @param ProductStatusResultCache $result_cache         The result cache.
	 * @param ConnectionState          $connection_state     The connection state.
	 * @param CryptoCheckMarker        $marker               The Pay with Crypto check's answer.
	 */
	public function __construct(
		bool $is_connected,
		PartnersEndpoint $partners_endpoint,
		FailureRegistry $api_failure_registry,
		ProductStatusResultCache $result_cache,
		ConnectionState $connection_state,
		CryptoCheckMarker $marker
	) {
		parent::__construct( $is_connected, $partners_endpoint, $api_failure_registry, $result_cache );
		$this->connection_state = $connection_state;
		$this->marker           = $marker;
	}

	/**
	 * No on a collecting store, the check's answer on a platform-connected one, so the seller status is not asked and the
	 * shared cache, where a stale "yes" may sit, is not read; the wallet's own answer otherwise.
	 *
	 * @since 11.3.0
	 *
	 * @param bool $skip_filters Whether to skip filters; passed on to the wallet's check.
	 * @return bool|null
	 */
	public function check_local_state( bool $skip_filters = false ): ?bool {
		$state = $this->connection_state->resolve();
		if ( ConnectionState::COLLECTING === $state ) {
			return false;
		}
		if ( ConnectionState::PLATFORM_CONNECTED === $state ) {
			return $this->marker->is_available();
		}

		return parent::check_local_state( $skip_filters );
	}
}
