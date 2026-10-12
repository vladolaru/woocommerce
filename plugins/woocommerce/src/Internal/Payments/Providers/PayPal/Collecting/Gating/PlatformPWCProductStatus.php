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
 * The Pay with Crypto capability of a store the platform serves: on a platform-connected store, the Pay with Crypto check's
 * answer instead of the seller status.
 *
 * The seller status the platform reads is the partner's view of the merchant, which never lists a product the merchant
 * added on their own account, so it never shows the crypto capability (probe rerun 2). Once platform connected, the answer
 * is the stored check's (`CryptoCheckMarker`), and no answer means not available; the shared result cache is neither read
 * nor written then. A collecting store keeps the wallet's own check, which the collecting rule overrides.
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
	 * The check's answer on a platform-connected store, so the seller status is not asked; the wallet's cached answer
	 * otherwise.
	 *
	 * @since 11.3.0
	 *
	 * @param bool $skip_filters Whether to skip filters; passed on to the wallet's check.
	 * @return bool|null
	 */
	public function check_local_state( bool $skip_filters = false ): ?bool {
		if ( ConnectionState::PLATFORM_CONNECTED === $this->connection_state->resolve() ) {
			return $this->marker->is_available();
		}

		return parent::check_local_state( $skip_filters );
	}
}
