<?php
/**
 * WooPaymentsSellingLocationsFraudSync class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Keeps the advanced fraud ruleset's country check in step with the store's selling locations.
 *
 * The client refreshes the rule on the classic general settings form save (client 11.1.0
 * `includes/class-wc-payment-gateway-wcpay.php:582`). Native watches the three selling-location options
 * instead, so REST and WP-CLI writes are covered too, and runs one refresh per request at shutdown.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSellingLocationsFraudSync implements RegisterHooksInterface {

	/**
	 * Options that define the store's selling locations.
	 *
	 * @var string[]
	 */
	private const SELLING_LOCATION_OPTIONS = array(
		'woocommerce_allowed_countries',
		'woocommerce_specific_allowed_countries',
		'woocommerce_all_except_countries',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Settings service, resolved only when a refresh runs so admin, REST and WP-CLI requests that change no selling location never build it.
	 *
	 * @var WooPaymentsSettingsService|null
	 */
	private ?WooPaymentsSettingsService $settings_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register the selling-location option hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		foreach ( self::SELLING_LOCATION_OPTIONS as $option ) {
			add_action( 'add_option_' . $option, array( $this, 'schedule_refresh' ), 10, 0 );
			add_action( 'update_option_' . $option, array( $this, 'schedule_refresh' ), 10, 0 );
		}
	}

	/**
	 * Schedule one fraud-rule refresh at shutdown, however many selling-location options this request writes.
	 *
	 * Writes made while WooCommerce installs or updates are ignored: they set defaults, not merchant choices. So are
	 * writes made while another site is switched in, since the refresh runs at shutdown in the request's own site.
	 *
	 * @internal
	 */
	public function schedule_refresh(): void {
		if ( $this->is_installing_or_updating() || ( is_multisite() && ms_is_switched() ) ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'refresh_fraud_rules' ) );
	}

	/**
	 * Refresh the fraud rules after this request's selling-location writes.
	 *
	 * @internal
	 */
	public function refresh_fraud_rules(): void {
		$this->get_settings_service()->update_fraud_rules_for_selling_locations();
	}

	/**
	 * Tell whether WordPress or WooCommerce is installing or updating.
	 *
	 * @return bool
	 */
	private function is_installing_or_updating(): bool {
		return wp_installing() || Constants::is_true( 'WC_INSTALLING' ) || Constants::is_true( 'WC_UPDATING' );
	}

	/**
	 * Get the settings service.
	 *
	 * @return WooPaymentsSettingsService
	 */
	private function get_settings_service(): WooPaymentsSettingsService {
		if ( null === $this->settings_service ) {
			$this->settings_service = wc_get_container()->get( WooPaymentsSettingsService::class );
		}

		return $this->settings_service;
	}
}
