<?php
/**
 * WooPaymentsStoreSetupReportController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\PluginUtil;
use Throwable;

/**
 * Keeps the platform's record of the store current. It sends the store setup snapshot every six hours, after a
 * settings save and after a WooCommerce update, the compatibility data when the account refreshes or the theme
 * changes, the site language as the account locale, and the business URL when the store launches. It also turns on
 * in Multi-Currency the currencies the enabled payment methods need.
 *
 * Its Action Scheduler hooks keep the WooPayments extension's names, so jobs the extension queued run after the switch.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsStoreSetupReportController implements RegisterHooksInterface {

	/**
	 * Action Scheduler hook of the store setup sync.
	 *
	 * @var string
	 */
	const STORE_SETUP_SYNC_ACTION = 'wcpay_store_setup_sync';

	/**
	 * Action Scheduler hook of the compatibility data update.
	 *
	 * @var string
	 */
	const UPDATE_COMPATIBILITY_DATA_ACTION = 'wcpay_update_compatibility_data';

	/**
	 * Action Scheduler service.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Duplicate gateway methods service.
	 *
	 * @var WooPaymentsDuplicateGatewayMethodsService
	 */
	private WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service;

	/**
	 * WooPay eligibility rules.
	 *
	 * @var WooPaymentsWooPayEligibility
	 */
	private WooPaymentsWooPayEligibility $woopay_eligibility;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsActionSchedulerService         $scheduler                         Action Scheduler service.
	 * @param WooPaymentsApiClient                      $api_client                        WooPayments API client.
	 * @param WooPaymentsAccountService                 $account_service                   WooPayments account service.
	 * @param WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service Duplicate gateway methods service.
	 * @param WooPaymentsWooPayEligibility              $woopay_eligibility                WooPay eligibility rules.
	 * @param WooPaymentsLogger                         $logger                            WooPayments logger.
	 */
	final public function init(
		WooPaymentsActionSchedulerService $scheduler,
		WooPaymentsApiClient $api_client,
		WooPaymentsAccountService $account_service,
		WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service,
		WooPaymentsWooPayEligibility $woopay_eligibility,
		WooPaymentsLogger $logger
	): void {
		$this->scheduler                         = $scheduler;
		$this->api_client                        = $api_client;
		$this->account_service                   = $account_service;
		$this->duplicate_gateway_methods_service = $duplicate_gateway_methods_service;
		$this->woopay_eligibility                = $woopay_eligibility;
		$this->logger                            = $logger;
	}

	/**
	 * Register the store setup report hooks.
	 */
	public function register() {
		add_action( self::STORE_SETUP_SYNC_ACTION, array( $this, 'handle_wcpay_store_setup_sync' ) );
		add_action( self::UPDATE_COMPATIBILITY_DATA_ACTION, array( $this, 'handle_wcpay_update_compatibility_data' ), 10, 0 );
		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'schedule_compatibility_data_update' ) );
		add_action( 'after_switch_theme', array( $this, 'schedule_compatibility_data_update' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'schedule_recurring_actions' ) );
		add_action( 'updated_option', array( $this, 'handle_site_language_update' ), 10, 3 );
		add_action( 'update_option_woocommerce_coming_soon', array( $this, 'handle_store_launch' ), 10, 2 );
		add_action( 'update_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( $this, 'maybe_add_missing_currencies' ) );
	}

	/**
	 * Auto-enable the Multi-Currency currencies required by enabled payment methods.
	 *
	 * Does what client 11.1.0's WC_Payments_Currency_Manager::maybe_add_missing_currencies() does (compat/multi-currency/class-wc-payments-currency-manager.php:96): without it, enabling a currency-restricted method such as iDEAL (EUR) leaves the required currency disabled, so the method is marked enabled but never offered at checkout.
	 */
	public function maybe_add_missing_currencies(): void {
		if ( '1' !== (string) get_option( '_wcpay_feature_customer_multi_currency', '1' ) ) {
			return;
		}

		$needed_codes = $this->get_enabled_payment_method_currency_codes();
		if ( empty( $needed_codes ) ) {
			return;
		}

		$state_builder = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create();
		$state         = $state_builder->build();
		$available     = $state->get_available_currencies();
		$enabled       = $state->get_enabled_currencies();

		$missing_codes = array();
		foreach ( $needed_codes as $currency_code ) {
			if ( isset( $available[ $currency_code ] ) && ! isset( $enabled[ $currency_code ] ) ) {
				$missing_codes[] = $currency_code;
			}
		}

		if ( empty( $missing_codes ) ) {
			return;
		}

		update_option( 'wcpay_multi_currency_enabled_currencies', array_values( array_unique( array_merge( array_keys( $enabled ), $missing_codes ) ) ) );
		$state_builder->reset();
	}

	/**
	 * Get the currency codes the enabled payment methods are restricted to, among the methods WooPayments registers on this store.
	 *
	 * A method with no currency restriction contributes nothing; a domestic-only method requires the account's domestic currency, the currency of the account's country, as in client 11.1.0 (compat/multi-currency/class-wc-payments-currency-manager.php:71, :81).
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_method_currency_codes(): array {
		$enabled_ids = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array() );
		$enabled_ids = is_array( $enabled_ids ) ? $enabled_ids : array();
		$registered  = wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class )->get_registered( $this->account_service );
		$country     = $this->account_service->get_account_country();
		$codes       = array();

		foreach ( $enabled_ids as $payment_method_id ) {
			if ( ! is_string( $payment_method_id ) || in_array( $payment_method_id, array( 'card', 'card_present', 'link' ), true ) ) {
				continue;
			}

			$definition = $registered[ strtolower( $payment_method_id ) ] ?? null;
			if ( null === $definition ) {
				continue;
			}

			if ( in_array( WooPaymentsPaymentMethodRegistry::DOMESTIC_TRANSACTIONS_ONLY, $definition->get_capabilities(), true ) ) {
				$codes[] = strtoupper( $this->account_service->get_account_domestic_currency() );
				continue;
			}

			$currencies = $definition->get_supported_currencies( '' !== $country ? $country : null );
			foreach ( $currencies as $currency_code ) {
				$codes[] = strtoupper( (string) $currency_code );
			}
		}

		return array_values( array_unique( array_filter( $codes ) ) );
	}

	/**
	 * Propagate a site-language change to the connected account's locale.
	 *
	 * Does what client 11.1.0's possibly_update_wcpay_account_locale() does (class-wc-payments-account.php:2652): platform-generated merchant emails and hosted pages render in the account locale, which otherwise never follows WPLANG.
	 *
	 * @param string $option_name Updated option name.
	 * @param mixed  $old_value   Previous option value.
	 * @param mixed  $new_value   New option value.
	 */
	public function handle_site_language_update( $option_name, $old_value, $new_value ): void {
		unset( $old_value );

		if ( 'WPLANG' !== $option_name || ! $this->account_service->has_account() ) {
			return;
		}

		try {
			$this->api_client->update_account( array( 'locale' => is_string( $new_value ) && '' !== $new_value ? $new_value : 'en_US' ) );
			$this->account_service->refresh_account_data();
		} catch ( WooPaymentsApiException $exception ) {
			$this->log_exception( 'Failed to propagate the site language to the WooPayments account locale.', $exception );
		}
	}

	/**
	 * Tell the platform when the store goes live, so Stripe runs its checks on the now-public site.
	 *
	 * Re-sends the account's business URL, as client 11.1.0's inform_stripe_when_store_goes_live() does
	 * (`includes/admin/class-wc-payments-admin.php:185`, `:197-222`). Client 11.1.0 watches the site visibility form only;
	 * the option hook also covers the Launch Your Store task and WP-CLI, and fires only when the value changes. Without a
	 * connected account there is no business URL to re-send.
	 *
	 * @internal
	 *
	 * @param mixed $old_value Previous coming-soon value.
	 * @param mixed $new_value New coming-soon value.
	 */
	public function handle_store_launch( $old_value, $new_value ): void {
		unset( $old_value );
		if ( 'no' !== $new_value ) {
			return;
		}

		$business_url = $this->account_service->get_cached_account_data()['business_profile']['url'] ?? '';
		if ( ! is_string( $business_url ) || '' === $business_url ) {
			return;
		}

		try {
			$this->api_client->update_account( array( 'business_url' => $business_url ) );
		} catch ( WooPaymentsApiException $exception ) {
			$this->log_exception( 'Failed to re-send the WooPayments business URL after the store went live.', $exception );
		}
	}

	/**
	 * Schedule the store setup sync every six hours.
	 *
	 * @internal
	 */
	public function schedule_recurring_actions(): void {
		$this->scheduler->schedule_recurring_job( self::STORE_SETUP_SYNC_ACTION, time() + wp_rand( 10, 60 ), 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Queue one store setup sync for a WooCommerce update, as the client syncs on its own update (client 11.1.0
	 * `includes/class-wc-payments-account.php:147`).
	 *
	 * The update sync carries its own argument, so the always-pending recurring sync does not absorb it.
	 */
	public function queue_store_setup_sync(): void {
		$this->scheduler->schedule_job( self::STORE_SETUP_SYNC_ACTION, array( 'woocommerce_updated' ) );
	}

	/**
	 * Send the current store setup state to the WooPayments API.
	 *
	 * @internal
	 */
	public function handle_wcpay_store_setup_sync(): void {
		if ( ! $this->api_client->is_available() ) {
			return;
		}

		try {
			$this->api_client->send_store_setup( $this->get_store_setup_details() );
		} catch ( Throwable $exception ) {
			$this->log_exception(
				'Failed to sync WooPayments store setup state.',
				$exception,
				array( 'action' => self::STORE_SETUP_SYNC_ACTION )
			);
		}
	}

	/**
	 * Schedule a debounced compatibility data update.
	 *
	 * @internal
	 */
	public function schedule_compatibility_data_update(): void {
		$this->scheduler->schedule_job( self::UPDATE_COMPATIBILITY_DATA_ACTION, array(), time() + 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Send the current compatibility data to the WooPayments API.
	 *
	 * @internal
	 */
	public function handle_wcpay_update_compatibility_data(): void {
		try {
			$this->api_client->update_compatibility_data( WooPaymentsCompatibilityData::get() );
		} catch ( Throwable $exception ) {
			$this->log_exception(
				'Failed to sync WooPayments compatibility data.',
				$exception,
				array( 'action' => self::UPDATE_COMPATIBILITY_DATA_ACTION )
			);
		}
	}

	/**
	 * Build the store setup snapshot sent to the platform.
	 *
	 * @return array<string,mixed>
	 */
	private function get_store_setup_details(): array {
		$settings                         = $this->account_service->get_gateway_settings();
		$payment_methods_available        = $this->get_payment_methods_available();
		$payment_methods_enabled          = $this->get_payment_methods_enabled();
		$payment_methods_disabled         = array_values( array_diff( $payment_methods_available, $payment_methods_enabled ) );
		$provider_capabilities_enabled    = $this->map_payment_methods_to_capabilities( $payment_methods_enabled );
		$provider_capabilities_disabled   = $this->map_payment_methods_to_capabilities( $payment_methods_disabled );
		$provider_capabilities_available  = array_values( array_unique( array_merge( $provider_capabilities_enabled, $provider_capabilities_disabled ) ) );
		$express_checkout_payment_methods = $settings['express_checkout_in_payment_methods'] ?? false;
		$payment_request_locations        = $this->get_express_checkout_method_locations( $settings, 'payment_request' );
		$woopay_locations                 = $this->get_express_checkout_method_locations( $settings, 'woopay' );

		return array(
			'gateway'                                     => array(
				'enabled'              => $this->is_setting_enabled( $settings, 'enabled' ),
				'test_mode'            => $this->account_service->is_test_mode_enabled(),
				'test_mode_onboarding' => $this->account_service->is_test_mode_onboarding_enabled(),
			),
			'payment_methods'                             => array(
				'available'  => $payment_methods_available,
				'enabled'    => $payment_methods_enabled,
				'disabled'   => $payment_methods_disabled,
				'duplicates' => $this->duplicate_gateway_methods_service->get_duplicated_payment_method_ids(),
			),
			'provider_capabilities'                       => array(
				'available' => $provider_capabilities_available,
				'enabled'   => $provider_capabilities_enabled,
				'disabled'  => $provider_capabilities_disabled,
			),
			'express_checkout_in_payment_methods_enabled' => $express_checkout_payment_methods,
			'saved_cards_enabled'                         => $this->is_setting_enabled( $settings, 'saved_cards' ) || $this->is_setting_enabled( $settings, 'saved_cards_enabled' ),
			'manual_capture_enabled'                      => $this->is_setting_enabled( $settings, 'manual_capture' ),
			'debug_log_enabled'                           => $this->is_setting_enabled( $settings, 'enable_logging' ),
			'payment_request'                             => array(
				'enabled'              => $this->account_service->is_payment_request_enabled(),
				'enabled_locations'    => $payment_request_locations,
				'button_type'          => $settings['payment_request_button_type'] ?? '',
				'button_size'          => $settings['payment_request_button_size'] ?? '',
				'button_theme'         => $settings['payment_request_button_theme'] ?? '',
				'button_border_radius' => $settings['payment_request_button_border_radius'] ?? '',
			),
			'woopay'                                      => array(
				'enabled'                 => $this->is_woopay_enabled( $settings ),
				'enabled_locations'       => $woopay_locations,
				'store_logo'              => $settings['platform_checkout_store_logo'] ?? '',
				'custom_message'          => $settings['platform_checkout_custom_message'] ?? '',
				'invalid_extension_found' => (bool) get_option( 'woopay_invalid_extension_found', false ),
			),
			// Whether Multi-Currency runs, as client 11.1.0 sends its own module's state (class-wc-payments-account.php:2976).
			'multi_currency_enabled'                      => wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->should_core_register(),
			'stripe_billing_enabled'                      => wc_get_container()->get( WooPaymentsStripeBillingModule::class )->is_stripe_billing_enabled(),
			// Client 11.1.0 sends its own version (class-wc-payments-account.php:2981): the version the user agent reports too.
			'plugin'                                      => array(
				'version'              => WooPaymentsClientVersion::VERSION,
				'activation_timestamp' => get_option( 'wcpay_activation_timestamp', null ),
			),
			'wp_setup'                                    => array(
				'name'           => get_bloginfo( 'name' ),
				'url'            => home_url(),
				'active_theme'   => $this->get_store_theme_details(),
				'active_plugins' => $this->get_store_active_plugins(),
				'version'        => get_bloginfo( 'version' ),
				'locale'         => get_locale(),
			),
			'wc_setup'                                    => array(
				'version'                     => defined( 'WC_VERSION' ) ? explode( '-', WC_VERSION, 2 )[0] : '',
				'store_id'                    => ( class_exists( '\WC_Install' ) && defined( '\WC_Install::STORE_ID_OPTION' ) ) ? get_option( \WC_Install::STORE_ID_OPTION, null ) : null,
				'currency'                    => get_woocommerce_currency(),
				'tracking_enabled'            => class_exists( '\WC_Site_Tracking' ) ? \WC_Site_Tracking::is_tracking_enabled() : false,
				'registered_payment_gateways' => $this->get_store_registered_gateway_ids(),
				'enabled_payment_gateways'    => $this->get_store_enabled_gateway_ids(),
				'wc_subscriptions_active'     => WooPaymentsSubscriptionMethodPolicy::is_woocommerce_subscriptions_active(),
				'wc_subscriptions_version'    => $this->get_subscriptions_version(),
			),
		);
	}

	/**
	 * Tell whether WooPay is on: the WooPay setting is on, the WooPay express checkout flag is on (on unless written
	 * off) and the account is eligible, as client 11.1.0 `WC_Payments_Features::is_woopay_enabled()` decides
	 * (class-wc-payments-features.php:54-60,193-230). Express checkout locations do not turn it on.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return bool
	 */
	private function is_woopay_enabled( array $settings ): bool {
		return $this->is_setting_enabled( $settings, 'platform_checkout' )
			&& '1' === (string) get_option( '_wcpay_feature_woopay_express_checkout', '1' )
			&& $this->woopay_eligibility->is_woopay_eligible();
	}

	/**
	 * Tell whether a yes/no gateway setting is enabled.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @param string              $key      Setting key.
	 * @return bool
	 */
	private function is_setting_enabled( array $settings, string $key ): bool {
		return isset( $settings[ $key ] ) && true === wc_string_to_bool( $settings[ $key ] );
	}

	/**
	 * Get the payment method IDs WooPayments offers on this store: the available methods the account has fees for.
	 *
	 * @return string[]
	 */
	private function get_payment_methods_available(): array {
		return $this->sanitize_string_list( wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class )->get_available_payment_method_ids_for_account( $this->account_service ) );
	}

	/**
	 * Get enabled payment method IDs as stored, card when none were ever stored, as client 11.1.0
	 * `get_upe_enabled_payment_method_ids()` reads them through WC_Settings_API (class-wc-payment-gateway-wcpay.php:4682-4689).
	 *
	 * @return string[]
	 */
	private function get_payment_methods_enabled(): array {
		$enabled = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );

		return $this->sanitize_string_list( '' === $enabled ? array( 'card' ) : $enabled );
	}

	/**
	 * Map WooPayments payment method IDs to Transact Platform capability keys.
	 *
	 * Its own map, not the payment method registry's: the registry has no definition for `giropay`, `sofort` or `jcb`,
	 * which an account can still have enabled. The map is client 11.1.0
	 * `includes/class-wc-payment-gateway-wcpay.php:304-326`, which the client's store setup report reads
	 * (`includes/class-wc-payments-account.php:2917`).
	 *
	 * @param string[] $payment_method_ids Payment method IDs.
	 * @return string[]
	 */
	private function map_payment_methods_to_capabilities( array $payment_method_ids ): array {
		$map          = array(
			'alipay'            => 'alipay_payments',
			'amazon_pay'        => 'amazon_pay_payments',
			'apple_pay'         => 'card_payments',
			'au_becs_debit'     => 'au_becs_debit_payments',
			'bancontact'        => 'bancontact_payments',
			'card'              => 'card_payments',
			'eps'               => 'eps_payments',
			'giropay'           => 'giropay_payments',
			'google_pay'        => 'card_payments',
			'grabpay'           => 'grabpay_payments',
			'ideal'             => 'ideal_payments',
			'jcb'               => 'jcb_payments',
			'klarna'            => 'klarna_payments',
			'link'              => 'link_payments',
			'multibanco'        => 'multibanco_payments',
			'p24'               => 'p24_payments',
			'sepa_debit'        => 'sepa_debit_payments',
			'sofort'            => 'sofort_payments',
			'wechat_pay'        => 'wechat_pay_payments',
			'affirm'            => 'affirm_payments',
			'afterpay_clearpay' => 'afterpay_clearpay_payments',
		);
		$capabilities = array();

		foreach ( $payment_method_ids as $payment_method_id ) {
			if ( isset( $map[ $payment_method_id ] ) ) {
				$capabilities[] = $map[ $payment_method_id ];
			}
		}

		return array_values( array_unique( $capabilities ) );
	}

	/**
	 * Get configured express-checkout button locations.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @param string              $method   Express checkout method ID.
	 * @return string[]
	 */
	private function get_express_checkout_method_locations( array $settings, string $method ): array {
		$enabled_locations = array();

		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$enabled_methods = $settings[ 'express_checkout_' . $location . '_methods' ] ?? array();
			if ( is_array( $enabled_methods ) && in_array( $method, $enabled_methods, true ) ) {
				$enabled_locations[] = $location;
			}
		}

		return $enabled_locations;
	}

	/**
	 * Sanitize a string list.
	 *
	 * @param mixed $values Raw values.
	 * @return string[]
	 */
	private function sanitize_string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$strings = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$strings[] = $value;
			}
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Get active theme details.
	 *
	 * @return array<string,mixed>
	 */
	private function get_store_theme_details(): array {
		$theme_data = wp_get_theme();

		return array(
			'name'        => $theme_data->get( 'Name' ),
			'version'     => $theme_data->get( 'Version' ),
			'child_theme' => is_child_theme(),
			'wc_support'  => current_theme_supports( 'woocommerce' ),
			'block_theme' => wp_is_block_theme(),
		);
	}

	/**
	 * Get active plugin details.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_store_active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		if ( empty( $all_plugins ) ) {
			return array();
		}

		// Network-active plugins count too, and a plugin is WooCommerce aware when its headers declare WooCommerce support.
		$plugin_util  = wc_get_container()->get( PluginUtil::class );
		$plugins_list = array();
		foreach ( $plugin_util->get_all_active_valid_plugins() as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$plugin_data                  = $all_plugins[ $plugin_file ];
				$plugins_list[ $plugin_file ] = array(
					'name'     => $plugin_data['Name'],
					'slug'     => dirname( $plugin_file ),
					'version'  => $plugin_data['Version'],
					'wc_aware' => $plugin_util->is_woocommerce_aware_plugin( $plugin_data ),
				);
			}
		}

		return array_values( $plugins_list );
	}

	/**
	 * Get registered gateway IDs.
	 *
	 * @return string[]
	 */
	private function get_store_registered_gateway_ids(): array {
		$payment_gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $payment_gateways ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $gateway ): ?string => isset( $gateway->id ) && is_string( $gateway->id ) ? $gateway->id : null,
						$payment_gateways
					)
				)
			)
		);
	}

	/**
	 * Get enabled gateway IDs.
	 *
	 * @return string[]
	 */
	private function get_store_enabled_gateway_ids(): array {
		$enabled_gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( isset( $gateway->id, $gateway->enabled ) && is_string( $gateway->id ) && 'yes' === $gateway->enabled ) {
				$enabled_gateways[] = $gateway->id;
			}
		}

		return array_values( array_unique( $enabled_gateways ) );
	}

	/**
	 * Get the version of the WooCommerce Subscriptions code loaded in this request, or null when it is not loaded.
	 *
	 * Client 11.1.0 `get_subscriptions_plugin_version()` (trait-wc-payments-subscriptions-utilities.php:129-131): an installed
	 * but inactive plugin reports no version.
	 *
	 * @return string|null
	 */
	private function get_subscriptions_version(): ?string {
		return class_exists( 'WC_Subscriptions' ) ? \WC_Subscriptions::$version : null;
	}

	/**
	 * Log a failed platform report without fataling the request.
	 *
	 * The exception's message is left out: the platform's errors carry its text. The class, code, trace and, for a
	 * platform error, its status and code are logged instead, whatever the logging setting.
	 *
	 * @param string              $message   Log message.
	 * @param Throwable           $exception Exception thrown.
	 * @param array<string,mixed> $context   Context added to the line, such as the Action Scheduler hook.
	 */
	private function log_exception( string $message, Throwable $exception, array $context = array() ): void {
		$this->logger->log_throwable_always( $message, $exception, $context );
	}
}
