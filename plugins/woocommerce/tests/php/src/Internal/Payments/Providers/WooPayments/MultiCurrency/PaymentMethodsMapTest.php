<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\PaymentMethodsMap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentMethodsMap class.
 *
 * Expected values follow client 11.1.0 `includes/compat/multi-currency/class-wc-payments-currency-manager.php`:
 * `get_enabled_payment_method_currencies()` (:66-91) skips card, card_present and link, maps a domestic-only method to
 * the account currency, and `add_payment_method_currency_dependencies_script()` (:145-176) prints
 * `window.multiCurrencyPaymentMethodsMap` (currency => method => title) on the Multi-Currency settings page only.
 */
class PaymentMethodsMapTest extends WC_Unit_Test_Case {

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
		$this->reset_container_replacements();

		parent::tear_down();
	}

	/**
	 * @testdox Should map each currency to the enabled methods that need it, like the client: $_dataName.
	 * @dataProvider provide_currency_payment_methods_maps
	 *
	 * @param string[]                           $enabled_methods Enabled payment method IDs.
	 * @param string                             $account_country Account country, or '' for none.
	 * @param string                             $store_country   Store base country, or '' to keep the default.
	 * @param array<string,array<string,string>> $expected        Expected map: method titles keyed by method ID, keyed by currency.
	 */
	public function test_maps_currencies_to_enabled_methods_that_need_them( array $enabled_methods, string $account_country, string $store_country, array $expected ): void {
		$this->enable_methods( $enabled_methods );
		if ( '' !== $store_country ) {
			update_option( 'woocommerce_default_country', $store_country );
		}

		$this->assertSame( $expected, $this->create_sut( true, $account_country )->get_currency_payment_methods_map() );
	}

	/**
	 * Enabled methods, account and store countries, and the map the client builds for them.
	 *
	 * Client 11.1.0 `includes/compat/multi-currency/class-wc-payments-currency-manager.php:66-91` maps a domestic-only
	 * method to the account country's domestic currency (`:67-82`), else US dollars without an account country
	 * (`includes/class-wc-payment-gateway-wcpay.php:3507-3523`, `includes/class-wc-payments-account.php:2731-2734`), and
	 * titles each method for the account country, else US, never the store country (`:82`,
	 * `includes/class-wc-payment-gateway-wcpay.php:3604-3613`). It builds the map from the payment method map
	 * (`:67-70`), which holds Amazon Pay (`includes/payment-methods/Configs/Registry/PaymentMethodDefinitionRegistry.php:102-105`).
	 *
	 * @return array<string,array{0:string[],1:string,2:string,3:array<string,array<string,string>>}>
	 */
	public function provide_currency_payment_methods_maps(): array {
		return array(
			'mixed methods for a US account'             => array(
				array( 'card', 'link', 'bancontact', 'ideal', 'klarna', 'affirm' ),
				'US',
				'',
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
			),
			'domestic-only method for a German account paid out in US dollars' => array(
				array( 'card', 'klarna' ),
				'DE',
				'',
				array( 'EUR' => array( 'klarna' => 'Klarna' ) ),
			),
			'domestic-only method without an account country, German store' => array(
				array( 'card', 'klarna' ),
				'',
				'DE',
				array( 'USD' => array( 'klarna' => 'Klarna' ) ),
			),
			'title for a US account without an account country, British store' => array(
				array( 'card', 'afterpay_clearpay' ),
				'',
				'GB',
				array( 'USD' => array( 'afterpay_clearpay' => 'Cash App Afterpay' ) ),
			),
			'Amazon Pay and iDEAL for a British account' => array(
				array( 'card', 'ideal', 'amazon_pay' ),
				'GB',
				'',
				array(
					'EUR' => array(
						'ideal'      => 'iDEAL | Wero',
						'amazon_pay' => 'Amazon Pay',
					),
					'USD' => array( 'amazon_pay' => 'Amazon Pay' ),
					'AUD' => array( 'amazon_pay' => 'Amazon Pay' ),
					'GBP' => array( 'amazon_pay' => 'Amazon Pay' ),
					'DKK' => array( 'amazon_pay' => 'Amazon Pay' ),
					'HKD' => array( 'amazon_pay' => 'Amazon Pay' ),
					'JPY' => array( 'amazon_pay' => 'Amazon Pay' ),
					'NZD' => array( 'amazon_pay' => 'Amazon Pay' ),
					'NOK' => array( 'amazon_pay' => 'Amazon Pay' ),
					'SEK' => array( 'amazon_pay' => 'Amazon Pay' ),
					'CHF' => array( 'amazon_pay' => 'Amazon Pay' ),
					'ZAR' => array( 'amazon_pay' => 'Amazon Pay' ),
				),
			),
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
	public function test_registers_admin_head_only_for_the_builtin_runtime_in_admin(): void {
		set_current_screen( 'woocommerce_page_wc-settings' );

		$builtin = $this->create_sut( true );
		$builtin->register();
		$this->assertSame( 10, has_action( 'admin_head', array( $builtin, 'handle_admin_head' ) ) );

		$not_builtin = $this->create_sut( false );
		$not_builtin->register();
		$this->assertFalse( has_action( 'admin_head', array( $not_builtin, 'handle_admin_head' ) ) );
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
	 * @param PaymentMethodsMap $sut System under test.
	 * @param string            $tab Settings tab.
	 * @return string
	 */
	private function capture_admin_head( PaymentMethodsMap $sut, string $tab ): string {
		set_current_screen( 'woocommerce_page_wc-settings' );
		$GLOBALS['current_tab'] = $tab; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		$sut->handle_admin_head();

		return (string) ob_get_clean();
	}

	/**
	 * Create the system under test with an account paid out in US dollars.
	 *
	 * @param bool   $builtin_owner    Whether native WooPayments owns the runtime.
	 * @param string $account_country Account country.
	 * @return PaymentMethodsMap
	 */
	private function create_sut( bool $builtin_owner = true, string $account_country = 'US' ): PaymentMethodsMap {
		$arbiter = new class( $builtin_owner ) extends WooPaymentsRuntimeArbiter {
			/**
			 * Whether native owns runtime.
			 *
			 * @var bool
			 */
			private bool $builtin_owner;

			/**
			 * Constructor.
			 *
			 * @param bool $builtin_owner Whether native owns runtime.
			 */
			public function __construct( bool $builtin_owner ) {
				$this->builtin_owner = $builtin_owner;
			}

			/**
			 * Tell whether native WooPayments should register.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return $this->builtin_owner;
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

		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account->init( new LegacyProxy(), $database_cache );

		// The map resolves the account service from the container when the settings page needs it.
		wc_get_container()->replace( WooPaymentsAccountService::class, $account );
		$sut = new PaymentMethodsMap();
		$sut->init( $arbiter );

		return $sut;
	}
}
