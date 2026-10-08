<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyPaymentMethodsMap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsMultiCurrencyPaymentMethodsMap class.
 *
 * Expected values follow client 11.1.0 `includes/compat/multi-currency/class-wc-payments-currency-manager.php`:
 * `get_enabled_payment_method_currencies()` (:66-91) skips card, card_present and link, maps a domestic-only method to
 * the account currency, and `add_payment_method_currency_dependencies_script()` (:145-176) prints
 * `window.multiCurrencyPaymentMethodsMap` (currency => method => title) on the Multi-Currency settings page only.
 */
class WooPaymentsMultiCurrencyPaymentMethodsMapTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	/**
	 * Original gateway settings.
	 *
	 * @var mixed
	 */
	private $original_settings;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_settings = get_option( self::SETTINGS_OPTION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		update_option( self::SETTINGS_OPTION, $this->original_settings );
		remove_all_actions( 'admin_head' );
		unset( $GLOBALS['current_tab'], $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * @testdox Should map each currency to the enabled methods that need it, like the client.
	 */
	public function test_maps_currencies_to_enabled_methods_that_need_them(): void {
		$this->enable_methods( array( 'card', 'link', 'bancontact', 'ideal', 'klarna', 'affirm' ) );

		$this->assertSame(
			array(
				'USD' => array(
					'affirm' => 'Affirm',
					'klarna' => 'Klarna',
				),
				'EUR' => array(
					'bancontact' => 'Bancontact',
					'ideal'      => 'iDEAL | Wero',
				),
			),
			$this->create_sut()->get_currency_payment_methods_map()
		);
	}

	/**
	 * @testdox Should map a domestic-only method to the account country's currency, not the payout currency.
	 */
	public function test_maps_domestic_only_methods_to_the_account_country_currency(): void {
		$this->enable_methods( array( 'card', 'klarna' ) );

		// A German account paid out in US dollars: the client uses the domestic currency
		// (client 11.1.0 `includes/compat/multi-currency/class-wc-payments-currency-manager.php:67-82`).
		$this->assertSame(
			array( 'EUR' => array( 'klarna' => 'Klarna' ) ),
			$this->create_sut( true, 'DE' )->get_currency_payment_methods_map()
		);
	}

	/**
	 * @testdox Should print the map on the Multi-Currency settings page only.
	 */
	public function test_prints_the_map_on_the_multi_currency_settings_page(): void {
		$this->enable_methods( array( 'card', 'bancontact' ) );
		$sut = $this->create_sut();

		$this->assertSame( '', $this->capture_admin_head( $sut, 'checkout' ) );

		$markup = $this->capture_admin_head( $sut, 'wcpay_multi_currency' );
		$this->assertStringContainsString( 'window.multiCurrencyPaymentMethodsMap = {"EUR":{"bancontact":"Bancontact"}};', $markup );
		// The client's dialog shows each method's settings icon (payment-methods-map.tsx:21-33, from get_settings_icon_url()).
		$this->assertStringContainsString(
			'window.multiCurrencyPaymentMethodIcons = ' . wp_json_encode( array( 'bancontact' => WC()->plugin_url() . '/assets/images/payment-methods/bancontact-color.svg' ) ) . ';',
			$markup
		);
	}

	/**
	 * @testdox Should give iDEAL the iDEAL | Wero settings icon, like the client.
	 *
	 * Client 11.1.0 IdealDefinition::get_settings_icon_url() (:160-162) returns its iDEAL | Wero tile; core ships it as ideal-wero.svg.
	 */
	public function test_uses_the_ideal_wero_settings_icon(): void {
		$this->enable_methods( array( 'ideal' ) );

		$this->assertSame(
			array( 'ideal' => WC()->plugin_url() . '/assets/images/payment-methods/ideal-wero.svg' ),
			$this->create_sut()->get_payment_method_icons()
		);
	}

	/**
	 * @testdox Should print nothing when no enabled method needs a currency.
	 */
	public function test_prints_nothing_without_currency_dependencies(): void {
		$this->enable_methods( array( 'card', 'link' ) );

		$this->assertSame( '', $this->capture_admin_head( $this->create_sut(), 'wcpay_multi_currency' ) );
	}

	/**
	 * @testdox Should hook the admin head only in admin requests while native WooPayments owns the runtime.
	 */
	public function test_registers_admin_head_only_for_the_native_runtime_in_admin(): void {
		set_current_screen( 'woocommerce_page_wc-settings' );

		$native = $this->create_sut( true );
		$native->register();
		$this->assertSame( 10, has_action( 'admin_head', array( $native, 'handle_admin_head' ) ) );

		$plugin = $this->create_sut( false );
		$plugin->register();
		$this->assertFalse( has_action( 'admin_head', array( $plugin, 'handle_admin_head' ) ) );
	}

	/**
	 * Enable WooPayments payment methods in the gateway settings.
	 *
	 * @param string[] $payment_method_ids Payment method IDs.
	 */
	private function enable_methods( array $payment_method_ids ): void {
		update_option( self::SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => $payment_method_ids ) );
	}

	/**
	 * Run the admin head callback on a settings tab and capture its output.
	 *
	 * @param WooPaymentsMultiCurrencyPaymentMethodsMap $sut System under test.
	 * @param string                                    $tab Settings tab.
	 * @return string
	 */
	private function capture_admin_head( WooPaymentsMultiCurrencyPaymentMethodsMap $sut, string $tab ): string {
		set_current_screen( 'woocommerce_page_wc-settings' );
		$GLOBALS['current_tab'] = $tab; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		$sut->handle_admin_head();

		return (string) ob_get_clean();
	}

	/**
	 * Create the system under test with an account paid out in US dollars.
	 *
	 * @param bool   $native_owner    Whether native WooPayments owns the runtime.
	 * @param string $account_country Account country.
	 * @return WooPaymentsMultiCurrencyPaymentMethodsMap
	 */
	private function create_sut( bool $native_owner = true, string $account_country = 'US' ): WooPaymentsMultiCurrencyPaymentMethodsMap {
		$arbiter = new class( $native_owner ) extends WooPaymentsRuntimeArbiter {
			/**
			 * Whether native owns runtime.
			 *
			 * @var bool
			 */
			private bool $native_owner;

			/**
			 * Constructor.
			 *
			 * @param bool $native_owner Whether native owns runtime.
			 */
			public function __construct( bool $native_owner ) {
				$this->native_owner = $native_owner;
			}

			/**
			 * Tell whether native WooPayments should register.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return $this->native_owner;
			}
		};

		$account = new class( $account_country ) extends WooPaymentsAccountService {
			/**
			 * Account country.
			 *
			 * @var string
			 */
			private string $country;

			/**
			 * Constructor.
			 *
			 * @param string $country Account country.
			 */
			public function __construct( string $country ) {
				$this->country = $country;
			}

			/**
			 * Get the account country.
			 *
			 * @return string
			 */
			public function get_account_country(): string {
				return $this->country;
			}

			/**
			 * Get the cached account data: the one key of the platform account payload this test needs.
			 *
			 * The client reads the account country from the same key (client 11.1.0 includes/class-wc-payments-account.php:2731-2734).
			 *
			 * @param bool $force_refresh Unused.
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				unset( $force_refresh );

				return array( 'country' => $this->country );
			}

			/**
			 * Get the account default currency.
			 *
			 * @return string
			 */
			public function get_account_default_currency(): string {
				return 'usd';
			}
		};

		$sut = new WooPaymentsMultiCurrencyPaymentMethodsMap();
		$sut->init( $arbiter );
		$sut->set_account_service( $account );

		return $sut;
	}
}
