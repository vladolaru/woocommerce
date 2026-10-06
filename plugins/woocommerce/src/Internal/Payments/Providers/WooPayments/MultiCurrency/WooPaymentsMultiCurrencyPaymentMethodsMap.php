<?php
/**
 * WooPaymentsMultiCurrencyPaymentMethodsMap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsProjectionService;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Tells the Multi-Currency settings page which currencies the enabled WooPayments methods need, so it asks before one is removed.
 *
 * Mirrors client 11.1.0 WC_Payments_Currency_Manager::get_enabled_payment_method_currencies() and
 * add_payment_method_currency_dependencies_script() (includes/compat/multi-currency/class-wc-payments-currency-manager.php:66-91, 145-176).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsMultiCurrencyPaymentMethodsMap implements RegisterHooksInterface {

	/**
	 * Methods the client never lists: they take any currency.
	 */
	private const METHODS_WITHOUT_CURRENCY_NEEDS = array( 'card', 'card_present', 'link' );

	/**
	 * Native Payments runtime arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Account service, resolved when the settings page needs it.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Native Payments runtime arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Set the account service.
	 *
	 * @internal Used by tests.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 */
	public function set_account_service( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Hook the settings page script in admin requests while native WooPayments owns the runtime.
	 */
	public function register(): void {
		if ( ! is_admin() || ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'admin_head', array( $this, 'handle_admin_head' ) ) ) {
			add_action( 'admin_head', array( $this, 'handle_admin_head' ) );
		}
	}

	/**
	 * Print the client's window.multiCurrencyPaymentMethodsMap on the Multi-Currency settings page.
	 *
	 * @internal
	 */
	public function handle_admin_head(): void {
		global $current_tab;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! MultiCurrencySettingsProjectionService::is_multi_currency_settings_page( is_admin(), is_string( $current_tab ) ? $current_tab : null, $screen ? $screen->base : null ) ) {
			return;
		}

		$map        = $this->get_currency_payment_methods_map();
		$map_json   = empty( $map ) ? false : wp_json_encode( $map );
		$icons_json = wp_json_encode( $this->get_payment_method_icons() );
		if ( false === $map_json || false === $icons_json ) {
			return;
		}

		// The client reads each method's settings icon from its own script data (payment-methods-map.tsx:21-33); here it travels with the map.
		wp_print_inline_script_tag( 'window.multiCurrencyPaymentMethodsMap = ' . $map_json . ";\nwindow.multiCurrencyPaymentMethodIcons = " . $icons_json . ';' );
	}

	/**
	 * Get the settings icon URL of each enabled method that needs a currency.
	 *
	 * @return array<string,string> Icon URLs keyed by method ID.
	 */
	public function get_payment_method_icons(): array {
		$country = $this->get_account_service()->get_account_country();
		$icons   = array();

		foreach ( $this->get_currency_dependent_definitions() as $definition ) {
			$path = $definition->get_settings_icon_asset_path( '' !== $country ? $country : null );
			if ( '' !== $path ) {
				$icons[ $definition->get_id() ] = WC()->plugin_url() . '/' . ltrim( $path, '/' );
			}
		}

		return $icons;
	}

	/**
	 * Get the enabled payment methods that need each currency.
	 *
	 * A domestic-only method needs the account country's currency, as the client's currency manager maps it (client 11.1.0
	 * `includes/compat/multi-currency/class-wc-payments-currency-manager.php:67-82`); others need their supported currencies.
	 *
	 * @return array<string,array<string,string>> Method titles keyed by method ID, keyed by currency code.
	 */
	public function get_currency_payment_methods_map(): array {
		$account_service = $this->get_account_service();
		$country         = $account_service->get_account_country();
		$country         = '' !== $country ? $country : null;
		$map             = array();

		foreach ( $this->get_currency_dependent_definitions() as $definition ) {
			$payment_method_id = $definition->get_id();
			$currencies        = in_array( WooPaymentsPaymentMethodRegistry::DOMESTIC_TRANSACTIONS_ONLY, $definition->get_capabilities(), true )
				? array( $account_service->get_account_domestic_currency() )
				: $definition->get_supported_currencies( $country );

			foreach ( $currencies as $currency_code ) {
				$map[ strtoupper( (string) $currency_code ) ][ $payment_method_id ] = $definition->get_title( $country );
			}
		}

		return $map;
	}

	/**
	 * Get the definitions of the enabled methods other than card, card_present and link, in registry order.
	 *
	 * @return WooPaymentsPaymentMethodDefinition[]
	 */
	private function get_currency_dependent_definitions(): array {
		$settings    = get_option( 'woocommerce_' . WooPaymentsPersistenceProfile::GATEWAY_ID . '_settings', array() );
		$enabled_ids = is_array( $settings ) && is_array( $settings['upe_enabled_payment_method_ids'] ?? null ) ? $settings['upe_enabled_payment_method_ids'] : array();
		if ( empty( $enabled_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class )->get_all(),
				static function ( WooPaymentsPaymentMethodDefinition $definition ) use ( $enabled_ids ): bool {
					return in_array( $definition->get_id(), $enabled_ids, true )
						&& ! in_array( $definition->get_id(), self::METHODS_WITHOUT_CURRENCY_NEEDS, true );
				}
			)
		);
	}

	/**
	 * Get the account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}
}
