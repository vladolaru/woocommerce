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

/**
 * The settings app's common route for a platform-connected store: the merchant details name the platform merchant.
 *
 * The wallet's merchant details come from its first-party connection, which a platform-connected store does not have,
 * so the "Connection status" block would read "Not Connected". Once platform connected, the details report the merchant
 * PayPal confirmed: connected, its merchant ID and environment, and the payee email as the account email. A collecting
 * store has no PayPal account yet and keeps the wallet's details.
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
	 * Constructor.
	 *
	 * @param GeneralSettings   $settings          The wallet's general settings.
	 * @param PartnersEndpoint  $partners_endpoint The partners endpoint.
	 * @param OnboardingNotices $notices           The pending onboarding notices.
	 * @param ConnectionState   $connection_state  The connection state.
	 * @param CollectingState   $state             The collecting state.
	 */
	public function __construct( GeneralSettings $settings, PartnersEndpoint $partners_endpoint, OnboardingNotices $notices, ConnectionState $connection_state, CollectingState $state ) {
		parent::__construct( $settings, $partners_endpoint, $notices );
		$this->connection_state = $connection_state;
		$this->state            = $state;
	}

	/**
	 * The merchant details, naming the platform merchant once the store is platform connected.
	 *
	 * The client ID and secret stay empty: the store has no app credentials of its own.
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

		return $extra_data;
	}
}
