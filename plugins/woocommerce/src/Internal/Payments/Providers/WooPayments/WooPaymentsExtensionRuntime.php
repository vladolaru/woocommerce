<?php
/**
 * WooPaymentsExtensionRuntime class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Reads the state of the WooPayments extension's runtime while it is active.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsExtensionRuntime {

	/**
	 * The onboarding `from` value used when the WooPayments extension does not declare its own.
	 */
	public const ADMIN_ONBOARDING_FROM_DEFAULT = 'WCADMIN_PAYMENT_SETTINGS';

	/**
	 * The onboarding `source` value used when the WooPayments extension does not declare its own.
	 */
	public const ADMIN_ONBOARDING_SOURCE_DEFAULT = 'wcadmin-settings-page';

	/**
	 * The WooPayments extension's constant that holds its onboarding `from` value.
	 */
	private const ADMIN_ONBOARDING_FROM_CONSTANT = 'WC_Payments_Onboarding_Service::FROM_WCADMIN_PAYMENTS_SETTINGS';

	/**
	 * The WooPayments extension's constant that holds its onboarding `source` value.
	 */
	private const ADMIN_ONBOARDING_SOURCE_CONSTANT = 'WC_Payments_Onboarding_Service::SOURCE_WCADMIN_SETTINGS_PAGE';

	/**
	 * The LegacyProxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Runtime arbiter; when set, the reads answer only while the WooPayments extension owns payments.
	 *
	 * @var WooPaymentsRuntimeArbiter|null
	 */
	private ?WooPaymentsRuntimeArbiter $arbiter = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy                    $legacy_proxy The LegacyProxy instance.
	 * @param WooPaymentsRuntimeArbiter|null $arbiter      Runtime arbiter. Without it, a loaded extension counts whoever owns payments.
	 */
	final public function init( LegacyProxy $legacy_proxy, ?WooPaymentsRuntimeArbiter $arbiter = null ): void {
		$this->legacy_proxy = $legacy_proxy;
		$this->arbiter      = $arbiter;
	}

	/**
	 * Tell whether the WooPayments extension is loaded and, with an arbiter, owns payments.
	 *
	 * @return bool
	 */
	public function is_loaded(): bool {
		if ( null !== $this->arbiter && ! $this->arbiter->is_extension_owner() ) {
			return false;
		}

		return (bool) $this->legacy_proxy->call_function( 'class_exists', 'WC_Payments' );
	}

	/**
	 * Get the WooPayments extension's gateway.
	 *
	 * @return object|null
	 */
	public function get_gateway(): ?object {
		return $this->get_wc_payments_service( 'get_gateway' );
	}

	/**
	 * Get the WooPayments extension's account service.
	 *
	 * @return object|null
	 */
	public function get_account_service(): ?object {
		return $this->get_wc_payments_service( 'get_account_service' );
	}

	/**
	 * Get the WooPayments extension's API client.
	 *
	 * @return object|null
	 */
	public function get_payments_api_client(): ?object {
		return $this->get_wc_payments_service( 'get_payments_api_client' );
	}

	/**
	 * Get the WooPayments extension's account connect URL.
	 *
	 * @param string $source Source context.
	 * @return string|null
	 */
	public function get_account_connect_url( string $source = '' ): ?string {
		if ( '' === $source ) {
			return $this->get_account_static_url( 'get_connect_url' );
		}

		return $this->get_account_static_url( 'get_connect_url', $source );
	}

	/**
	 * Tell whether the WooPayments extension is in test mode.
	 *
	 * @return bool|null
	 */
	public function is_test_mode(): ?bool {
		return $this->call_mode_boolean_method( 'is_test' );
	}

	/**
	 * Tell whether the WooPayments extension is in development mode.
	 *
	 * @return bool|null
	 */
	public function is_dev_mode(): ?bool {
		return $this->call_mode_boolean_method( 'is_dev' );
	}

	/**
	 * Tell whether the WooPayments extension is in test-mode onboarding.
	 *
	 * @return bool|null
	 */
	public function is_test_mode_onboarding(): ?bool {
		return $this->call_mode_boolean_method( 'is_test_mode_onboarding' );
	}

	/**
	 * Get the WooPayments extension's account status data.
	 *
	 * @return array|null
	 */
	public function get_account_status_data(): ?array {
		$account_service = $this->get_account_service();
		if ( ! is_object( $account_service ) ) {
			return null;
		}

		try {
			$account_status_data_reader = array( $account_service, 'get_account_status_data' );
			if ( ! $this->legacy_proxy->call_function( 'method_exists', $account_service, 'get_account_status_data' ) ||
				! $this->legacy_proxy->call_function( 'is_callable', $account_status_data_reader ) ||
				! is_callable( $account_status_data_reader ) ) {
				return null;
			}

			$account_status_data = $account_status_data_reader();
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $account_status_data ) ? $account_status_data : null;
	}

	/**
	 * Tell whether the account cache holds a live account.
	 *
	 * @return bool
	 */
	public function has_live_cached_account_data(): bool {
		$account_data = $this->get_cached_account_data();

		if ( empty( $account_data['account_id'] ) || ! empty( $account_data['is_test_drive'] ) ) {
			return false;
		}

		return ! isset( $account_data['is_live'] ) || (bool) $account_data['is_live'];
	}

	/**
	 * Tell whether the account cache holds an onboarded account.
	 *
	 * @return bool
	 */
	public function is_account_onboarded_from_cache(): bool {
		$account_data = $this->get_cached_account_data();
		if ( empty( $account_data['account_id'] ) || empty( $account_data['details_submitted'] ) ) {
			return false;
		}

		return filter_var( $account_data['details_submitted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}

	/**
	 * Get the countries the WooPayments extension supports.
	 *
	 * @return array|null
	 */
	public function get_supported_countries(): ?array {
		foreach ( array( 'WC_Payments_Utils', '\WC_Payments_Utils' ) as $class_name ) {
			try {
				if ( ! $this->legacy_proxy->call_function( 'class_exists', $class_name ) ||
					! $this->legacy_proxy->call_function( 'is_callable', "{$class_name}::supported_countries" ) ) {
					continue;
				}

				$supported_countries = $this->legacy_proxy->call_static( $class_name, 'supported_countries' );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( is_array( $supported_countries ) ) {
				return $supported_countries;
			}
		}

		return null;
	}

	/**
	 * Get the WooPayments extension's onboarding `from` and `source` values for the Payments settings.
	 *
	 * @return array{from:string,source:string}
	 */
	public function get_admin_onboarding_context(): array {
		return array(
			'from'   => $this->get_constant_value( self::ADMIN_ONBOARDING_FROM_CONSTANT, self::ADMIN_ONBOARDING_FROM_DEFAULT ),
			'source' => $this->get_constant_value( self::ADMIN_ONBOARDING_SOURCE_CONSTANT, self::ADMIN_ONBOARDING_SOURCE_DEFAULT ),
		);
	}

	/**
	 * Tell whether the installed WooPayments extension version is less than a minimum version.
	 *
	 * @param string $minimum_version Minimum required version.
	 * @return bool|null
	 */
	public function is_extension_version_less_than( string $minimum_version ): ?bool {
		if ( ! Constants::is_defined( 'WCPAY_VERSION_NUMBER' ) ) {
			return null;
		}

		$version = Constants::get_constant( 'WCPAY_VERSION_NUMBER' );
		if ( ! is_scalar( $version ) ) {
			return null;
		}

		return version_compare( (string) $version, $minimum_version, '<' );
	}

	/**
	 * Reset the WooPayments extension's onboarding test-mode option, when the extension declares the option's name.
	 *
	 * @return void
	 */
	public function reset_onboarding_test_mode_option(): void {
		if ( ! $this->legacy_proxy->call_function( 'class_exists', 'WC_Payments_Onboarding_Service' ) ||
			! Constants::is_defined( 'WC_Payments_Onboarding_Service::TEST_MODE_OPTION' ) ) {
			return;
		}

		$this->legacy_proxy->call_function(
			'update_option',
			Constants::get_constant( 'WC_Payments_Onboarding_Service::TEST_MODE_OPTION' ),
			'no'
		);
	}

	/**
	 * Get one of the WooPayments extension's services through its `WC_Payments` static accessor.
	 *
	 * @param string $method_name WC_Payments static accessor.
	 * @return object|null
	 */
	private function get_wc_payments_service( string $method_name ): ?object {
		if ( ! $this->is_loaded() ) {
			return null;
		}

		try {
			$service = $this->legacy_proxy->call_static( 'WC_Payments', $method_name );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $service ) ? $service : null;
	}

	/**
	 * Get the WooPayments extension's mode service.
	 *
	 * @return object|null
	 */
	private function get_mode_service(): ?object {
		return $this->get_wc_payments_service( 'mode' );
	}

	/**
	 * Call a boolean method on the WooPayments extension's mode service.
	 *
	 * @param string $method_name Mode service method name.
	 * @return bool|null
	 */
	private function call_mode_boolean_method( string $method_name ): ?bool {
		$mode_service = $this->get_mode_service();
		if ( ! is_object( $mode_service ) ) {
			return null;
		}

		try {
			if ( ! $this->legacy_proxy->call_function( 'method_exists', $mode_service, $method_name ) ||
				! $this->legacy_proxy->call_function( 'is_callable', array( $mode_service, $method_name ) ) ) {
				return null;
			}

			return (bool) $mode_service->{$method_name}();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get a WooPayments extension account URL through `WC_Payments_Account`'s static helper.
	 *
	 * @param string $method_name WC_Payments_Account static URL accessor.
	 * @param mixed  ...$args     Method arguments.
	 * @return string|null
	 */
	private function get_account_static_url( string $method_name, ...$args ): ?string {
		if ( ! $this->is_loaded() ) {
			return null;
		}

		try {
			$is_callable = $this->legacy_proxy->call_function( 'is_callable', "WC_Payments_Account::{$method_name}" ) ||
				$this->legacy_proxy->call_function( 'is_callable', "\\WC_Payments_Account::{$method_name}" );
			if ( ! $is_callable ) {
				return null;
			}

			$url = $this->legacy_proxy->call_static( 'WC_Payments_Account', $method_name, ...$args );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_scalar( $url ) ? (string) $url : null;
	}

	/**
	 * Get a scalar constant value with fallback.
	 *
	 * @param string $constant_name Constant name.
	 * @param string $fallback      Fallback value.
	 * @return string
	 */
	private function get_constant_value( string $constant_name, string $fallback ): string {
		if ( ! Constants::is_defined( $constant_name ) ) {
			return $fallback;
		}

		$value = Constants::get_constant( $constant_name );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Get the account data from the account cache, empty when there is none.
	 *
	 * @return array<string,mixed>
	 */
	private function get_cached_account_data(): array {
		$account_data = $this->legacy_proxy->call_function( 'get_option', WooPaymentsDatabaseCache::ACCOUNT_KEY, array() );

		if ( ! is_array( $account_data ) || empty( $account_data['data'] ) || ! is_array( $account_data['data'] ) ) {
			return array();
		}

		return $account_data['data'];
	}
}
