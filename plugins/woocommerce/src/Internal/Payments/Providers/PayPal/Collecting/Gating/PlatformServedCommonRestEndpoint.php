<?php
/**
 * PlatformServedCommonRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\CommonRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\OnboardingNotices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;
use Exception;

/**
 * The settings app's common route for a platform-connected store: the merchant details name the platform merchant.
 *
 * The wallet's merchant details come from its first-party connection, which a platform-connected store does not have,
 * so the "Connection status" block would read "Not Connected". Once platform connected, the details report the merchant
 * PayPal confirmed: connected, its merchant ID and environment, the payee email as the account email, and its seller
 * type. A collecting store has no PayPal account yet and keeps the wallet's details.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedCommonRestEndpoint extends CommonRestEndpoint {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The wallet's seller type resolver.
	 *
	 * @var SellerTypeResolver
	 */
	private SellerTypeResolver $seller_type_resolver;

	/**
	 * Constructor.
	 *
	 * @param GeneralSettings    $settings             The wallet's general settings.
	 * @param PartnersEndpoint   $partners_endpoint    The partners endpoint.
	 * @param OnboardingNotices  $notices              The pending onboarding notices.
	 * @param ConnectionState    $connection_state     The connection state.
	 * @param CollectingState    $state                The collecting state.
	 * @param SellerTypeResolver $seller_type_resolver The wallet's seller type resolver.
	 */
	public function __construct( GeneralSettings $settings, PartnersEndpoint $partners_endpoint, OnboardingNotices $notices, ConnectionState $connection_state, CollectingState $state, SellerTypeResolver $seller_type_resolver ) {
		parent::__construct( $settings, $partners_endpoint, $notices );
		$this->connection_state     = $connection_state;
		$this->state                = $state;
		$this->seller_type_resolver = $seller_type_resolver;
	}

	/**
	 * The merchant details, naming the platform merchant once the store is platform connected.
	 *
	 * The client ID and secret stay empty: the store has no app credentials of its own. The seller type is resolved from
	 * the platform merchant's seller status, as the wallet resolves it for a first-party merchant, so the settings app
	 * shows the Alternative Payment Methods card to a business account.
	 *
	 * @param array $extra_data Initial extra_data collection.
	 *
	 * @return array Updated extra_data collection.
	 */
	protected function add_merchant_info( array $extra_data ): array {
		$extra_data = parent::add_merchant_info( $extra_data );
		if ( ConnectionState::PLATFORM_CONNECTED !== $this->connection_state->resolve() || ! is_array( $extra_data['merchant'] ?? null ) ) {
			return $extra_data;
		}

		$extra_data['merchant'] = array_merge(
			$extra_data['merchant'],
			array(
				'isConnected'  => true,
				'isSandbox'    => CollectingState::ENVIRONMENT_SANDBOX === $this->state->environment(),
				'id'           => $this->state->merchant_id(),
				'email'        => $this->state->payee_email(),
				'clientId'     => '',
				'clientSecret' => '',
			)
		);

		$seller_type = $this->platform_seller_type();
		if ( null !== $seller_type ) {
			$extra_data['merchant']['sellerType'] = $seller_type;
		}

		return $extra_data;
	}

	/**
	 * The platform merchant's seller type, from the wallet's seller status read, or null when the read fails.
	 *
	 * The read goes through the wallet's partners endpoint, whose cache keeps a seller status for ten minutes and backs off
	 * for an hour after a failure, so a settings load asks PayPal at most once per expiry, as the store's other admin
	 * requests already do. A failed read keeps the wallet's value, `unknown`.
	 *
	 * @return string|null
	 */
	private function platform_seller_type(): ?string {
		try {
			return $this->seller_type_resolver->resolve( $this->partners_endpoint->seller_status() );
		} catch ( Exception $exception ) {
			return null;
		}
	}
}
