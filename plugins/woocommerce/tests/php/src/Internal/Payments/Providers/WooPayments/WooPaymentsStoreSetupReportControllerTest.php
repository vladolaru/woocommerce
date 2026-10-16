<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreSetupReportController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsStoreSetupReportController class.
 */
class WooPaymentsStoreSetupReportControllerTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Created controllers whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsStoreSetupReportController[]
	 */
	private array $services = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->services as $service ) {
			$this->remove_report_hooks( $service );
		}

		delete_option( 'WPLANG' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );
		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars( array() );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION, null, WooPaymentsActionSchedulerService::GROUP_ID );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Store setup report hooks are registered.
	 */
	public function test_registers_the_store_setup_report_hooks(): void {
		$service = $this->create_service();

		$service->register();

		$this->assertSame( 10, has_action( 'wcpay_store_setup_sync', array( $service, 'handle_wcpay_store_setup_sync' ) ) );
		// Native never fires the plugin's update hook (no plugin version); a WooCommerce update queues the sync instead.
		$this->assertFalse( has_action( 'woocommerce_woocommerce_payments_updated', array( $service, 'handle_wcpay_store_setup_sync' ) ) );
		$this->assertSame( 10, has_action( 'wcpay_update_compatibility_data', array( $service, 'handle_wcpay_update_compatibility_data' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $service, 'schedule_compatibility_data_update' ) ) );
		$this->assertSame( 10, has_action( 'after_switch_theme', array( $service, 'schedule_compatibility_data_update' ) ) );
	}

	/**
	 * @testdox Store setup sync sends a WooPayments-compatible snapshot through the API client.
	 */
	public function test_store_setup_sync_sends_store_snapshot(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                           => 'yes',
				'test_mode'                         => 'yes',
				'upe_enabled_payment_method_ids'    => array( 'card' ),
				'manual_capture'                    => 'yes',
				'enable_logging'                    => 'yes',
				'saved_cards'                       => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request', 'woopay' ),
				'express_checkout_cart_methods'     => array( 'payment_request' ),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);

		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )
			->method( 'send_store_setup' )
			->with(
				$this->callback(
					function ( array $snapshot ): bool {
						return true === $snapshot['gateway']['enabled']
							&& true === $snapshot['gateway']['test_mode']
							&& array( 'card', 'ideal', 'apple_pay', 'google_pay' ) === $snapshot['payment_methods']['available']
							&& array( 'card' ) === $snapshot['payment_methods']['enabled']
							&& array( 'ideal', 'apple_pay', 'google_pay' ) === $snapshot['payment_methods']['disabled']
							&& in_array( 'card_payments', $snapshot['provider_capabilities']['enabled'], true )
							&& in_array( 'ideal_payments', $snapshot['provider_capabilities']['disabled'], true )
							&& true === $snapshot['manual_capture_enabled']
							&& true === $snapshot['debug_log_enabled']
							&& true === $snapshot['payment_request']['enabled']
							&& array( 'product', 'cart' ) === $snapshot['payment_request']['enabled_locations']
							&& array( 'product', 'checkout' ) === $snapshot['woopay']['enabled_locations'];
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'fees' => array(
					'card'  => array(),
					'ideal' => array(),
				),
			)
		);
		$this->create_service( new RecordingActionSchedulerService(), $api_client, $account_service )->handle_wcpay_store_setup_sync();
	}

	/**
	 * @testdox Should report the Stripe Billing toggle in the store setup snapshot while WooCommerce Subscriptions is active (the WooPayments extension `class-wc-payments-account.php:2977`).
	 * @testWith ["1", true]
	 *           ["0", false]
	 *
	 * @param string $toggle   Stripe Billing toggle option value.
	 * @param bool   $expected Reported `stripe_billing_enabled`.
	 */
	public function test_store_setup_sync_reports_stripe_billing( string $toggle, bool $expected ): void {
		// The module asks LegacyProxy whether WooCommerce Subscriptions is active; the mock is reset after every test.
		wc_get_container()->get( LegacyProxy::class )->register_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		update_option( WooPaymentsStripeBillingModule::TOGGLE_OPTION, $toggle );
		$module = new WooPaymentsStripeBillingModule();
		$module->register();
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );

		$snapshots  = array();
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'send_store_setup' )->willReturnCallback(
			static function ( array $snapshot ) use ( &$snapshots ): array {
				$snapshots[] = $snapshot;
				return array( 'result' => 'success' );
			}
		);

		$this->create_service( new RecordingActionSchedulerService(), $api_client )->handle_wcpay_store_setup_sync();

		$this->assertCount( 1, $snapshots );
		$this->assertSame( $expected, $snapshots[0]['stripe_billing_enabled'] );
	}

	/**
	 * @testdox Should report WooCommerce Subscriptions as active when its class is loaded, whatever folder holds it (the WooPayments extension `class-wc-payments-account.php:3001`).
	 */
	public function test_store_setup_sync_reports_subscriptions_active_by_its_loaded_class(): void {
		// WooCommerce Subscriptions as WooCommerce.com installs it, outside the woocommerce-subscriptions folder.
		update_option( 'active_plugins', array( 'woocommerce-com-woocommerce-subscriptions/woocommerce-subscriptions.php' ) );
		wc_get_container()->get( LegacyProxy::class )->register_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);

		$snapshot = $this->send_store_setup_snapshot();

		$this->assertTrue( $snapshot['wc_setup']['wc_subscriptions_active'] );
	}

	/**
	 * @testdox Should report the WooPayments version the store reports everywhere else as the store setup plugin version, not WooCommerce's.
	 *
	 * Client 11.1.0 sends its own version there (class-wc-payments-account.php:2981); the user agent reports the same
	 * WooPaymentsClientVersion, and WooCommerce's version goes under wc_setup.
	 */
	public function test_store_setup_sync_reports_the_woopayments_version_as_the_plugin_version(): void {
		$snapshot = $this->send_store_setup_snapshot();

		$this->assertSame( WooPaymentsClientVersion::VERSION, $snapshot['plugin']['version'] );
	}

	/**
	 * @testdox Should report no WooCommerce Subscriptions version while the plugin is installed but not loaded (the WooPayments extension `class-wc-payments-account.php:3002`).
	 */
	public function test_store_setup_sync_reports_no_subscriptions_version_while_not_loaded(): void {
		update_option( 'active_plugins', array() );
		// get_plugins() answers from this cache, so the installed plugin needs no file on disk.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'woocommerce-subscriptions/woocommerce-subscriptions.php' => array(
						'Name'    => 'WooCommerce Subscriptions',
						'Version' => '7.1.0',
					),
				),
			),
			'plugins'
		);

		try {
			$snapshot = $this->send_store_setup_snapshot();
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}

		$this->assertFalse( $snapshot['wc_setup']['wc_subscriptions_active'] );
		$this->assertNull( $snapshot['wc_setup']['wc_subscriptions_version'] );
	}

	/**
	 * @testdox Should report the loaded WooCommerce Subscriptions class's version (the WooPayments extension `trait-wc-payments-subscriptions-utilities.php:129-131`, sent at `class-wc-payments-account.php:3002`).
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_setup_sync_reports_the_loaded_subscriptions_version(): void {
		// The global WC_Subscriptions class cannot be unloaded, so this test runs in its own process.
		require_once __DIR__ . '/Fixtures/LateLoadedSubscriptions.php';
		class_alias( Fixtures\LateLoadedSubscriptions::class, 'WC_Subscriptions' );

		$snapshot = $this->send_store_setup_snapshot();

		$this->assertTrue( $snapshot['wc_setup']['wc_subscriptions_active'] );
		$this->assertSame( Fixtures\LateLoadedSubscriptions::$version, $snapshot['wc_setup']['wc_subscriptions_version'] );
	}

	/**
	 * @testdox Should report the available payment methods as the registered methods the account has fees for (the WooPayments extension `class-wc-payment-gateway-wcpay.php:4848-4879`, sent at `class-wc-payments-account.php:2908-2909`).
	 */
	public function test_store_setup_sync_reports_available_methods_from_account_fees(): void {
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'country' => 'US',
				'fees'    => array(
					'card'       => array(),
					'bancontact' => array(),
					'link'       => array(),
				),
			)
		);

		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
			),
			$account_service
		);

		// Registry order; Apple Pay and Google Pay are charged at card fees, so they count while card has fees.
		$this->assertSame( array( 'card', 'bancontact', 'link', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['available'] );
		$this->assertSame( array( 'card', 'link' ), $snapshot['payment_methods']['enabled'] );
		$this->assertSame( array( 'bancontact', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['disabled'] );
		$this->assertSame( array( 'bancontact_payments', 'card_payments' ), $snapshot['provider_capabilities']['disabled'] );
	}

	/**
	 * @testdox Should report Amazon Pay as available only while its feature flag is on (the WooPayments extension `PaymentMethodDefinitionRegistry.php:102-104` registers it only then).
	 * @testWith ["1", true]
	 *           ["0", false]
	 *
	 * @param string $flag     The Amazon Pay feature flag option value.
	 * @param bool   $expected Whether Amazon Pay is reported as available.
	 */
	public function test_store_setup_sync_reports_amazon_pay_only_while_its_feature_is_on( string $flag, bool $expected ): void {
		update_option( '_wcpay_feature_amazon_pay', $flag );
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service(
			array(
				'fees' => array(
					'card'       => array(),
					'amazon_pay' => array(),
				),
			)
		);

		try {
			$snapshot = $this->send_store_setup_snapshot( array( 'enabled' => 'yes' ), $account_service );
		} finally {
			delete_option( '_wcpay_feature_amazon_pay' );
		}

		$this->assertSame( $expected, in_array( 'amazon_pay', $snapshot['payment_methods']['available'], true ) );
	}

	/**
	 * @testdox Should report a stored empty list of enabled payment methods as empty (the WooPayments extension `get_upe_enabled_payment_method_ids()`, `class-wc-payment-gateway-wcpay.php:4682-4689`, sent at `class-wc-payments-account.php:2909`).
	 */
	public function test_store_setup_sync_reports_a_stored_empty_enabled_list_as_empty(): void {
		// The account's `fees` is keyed by payment method ID, as in the recorded Fixtures/platform-test-drive-account.json `account.fees`.
		$account_service = $this->create_account_service( array( 'fees' => array( 'card' => array() ) ) );

		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array(),
			),
			$account_service
		);

		$this->assertSame( array(), $snapshot['payment_methods']['enabled'] );
		$this->assertSame( array( 'card', 'apple_pay', 'google_pay' ), $snapshot['payment_methods']['disabled'] );
	}

	/**
	 * A store onboarded natively stores only the keys onboarding writes until the merchant saves the settings page; every
	 * other setting reads as its form default, as WC_Settings_API::get_option() answers a key that was never saved.
	 *
	 * @testdox Should report the settings an onboarded store never saved from their defaults.
	 */
	public function test_store_setup_sync_reports_unsaved_settings_from_their_defaults(): void {
		$snapshot = $this->send_store_setup_snapshot(
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);

		$this->assertTrue( $snapshot['saved_cards_enabled'] );
		$this->assertSame( array( 'product', 'cart', 'checkout' ), $snapshot['payment_request']['enabled_locations'] );
		$this->assertSame( array( 'product', 'cart', 'checkout' ), $snapshot['woopay']['enabled_locations'] );
		$this->assertSame(
			array( 'default', 'medium', 'dark' ),
			array( $snapshot['payment_request']['button_type'], $snapshot['payment_request']['button_size'], $snapshot['payment_request']['button_theme'] )
		);
	}

	/**
	 * @testdox Should report WooPay as enabled only when the account is eligible, the WooPay setting is on and the WooPay express checkout flag is on: $_dataName.
	 * @dataProvider provider_store_setup_sync_woopay_enabled
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Cached account data.
	 * @param string|null         $flag         WooPay express checkout flag option, or null to leave it never written.
	 * @param bool                $expected     Reported `woopay.enabled`.
	 */
	public function test_store_setup_sync_reports_woopay_enabled_by_the_feature_check( array $settings, array $account_data, ?string $flag, bool $expected ): void {
		delete_option( '_wcpay_feature_woopay_express_checkout' );
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_woopay_express_checkout', $flag );
		}

		$account_service    = $this->create_account_service( $account_data );
		$woopay_eligibility = $this->create_woopay_eligibility( $account_service );

		try {
			$snapshot = $this->send_store_setup_snapshot( $settings, $account_service, $woopay_eligibility );
		} finally {
			delete_option( '_wcpay_feature_woopay_express_checkout' );
		}

		$this->assertSame( $expected, $snapshot['woopay']['enabled'] );
	}

	/**
	 * WooPay settings, account eligibility and flag states, with the `woopay.enabled` each should report.
	 *
	 * @return array<string,array{array<string,mixed>,array<string,mixed>,string|null,bool}>
	 */
	public static function provider_store_setup_sync_woopay_enabled(): array {
		$listed_everywhere = array(
			'enabled'                           => 'yes',
			'express_checkout_product_methods'  => array( 'woopay' ),
			'express_checkout_cart_methods'     => array( 'woopay' ),
			'express_checkout_checkout_methods' => array( 'woopay' ),
		);
		$woopay_on         = array_merge( $listed_everywhere, array( 'platform_checkout' => 'yes' ) );
		// The account cache field the WooPay eligibility check reads (client 11.1.0 includes/class-wc-payments-features.php:206-209).
		$eligible = array( 'platform_checkout_eligible' => true );

		return array(
			'listed at every location, setting off' => array( $listed_everywhere, $eligible, null, false ),
			'setting on, account not eligible'      => array( $woopay_on, array(), null, false ),
			'setting on, eligible, flag off'        => array( $woopay_on, $eligible, '0', false ),
			'setting on, eligible, flag on'         => array( $woopay_on, $eligible, '1', true ),
			'setting on, eligible, flag never set'  => array( $woopay_on, $eligible, null, true ),
		);
	}

	/**
	 * @testdox Should report whether each active plugin declares WooCommerce support in its headers.
	 */
	public function test_store_setup_sync_reports_woocommerce_awareness_of_active_plugins(): void {
		// WordPress keeps an active plugin only when its file exists. hello.php ships with the test install; the sample
		// plugin is there only after another test copied it, so this test adds it when missing and removes what it added.
		$sample_file       = WP_PLUGIN_DIR . '/sample-woo-plugin.php';
		$added_sample_file = ! file_exists( $sample_file );
		if ( $added_sample_file ) {
			copy( \WC_Unit_Tests_Bootstrap::instance()->tests_dir . '/data/sample-woo-plugin.php', $sample_file );
		}
		update_option( 'active_plugins', array( 'hello.php', 'sample-woo-plugin.php' ) );
		// get_plugins() answers from this cache, so the headers are the test's own: WordPress keeps it as the 'plugins' entry
		// of the 'plugins' cache group, keyed by plugin folder, '' for the plugins root (wp-admin/includes/plugin.php
		// get_plugins()). Core's PluginUtil::is_woocommerce_aware_plugin() reads the 'WC tested up to' header.
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'hello.php'             => array(
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					),
					'sample-woo-plugin.php' => array(
						'Name'            => 'Sample Woo Plugin',
						'Version'         => '1.0.0',
						'WC tested up to' => '11.2',
					),
				),
			),
			'plugins'
		);

		try {
			$snapshot = $this->send_store_setup_snapshot();
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
			if ( $added_sample_file ) {
				wp_delete_file( $sample_file );
			}
		}

		$this->assertSame(
			array(
				array(
					'name'     => 'Hello Dolly',
					'slug'     => '.',
					'version'  => '1.7.2',
					'wc_aware' => false,
				),
				array(
					'name'     => 'Sample Woo Plugin',
					'slug'     => '.',
					'version'  => '1.0.0',
					'wc_aware' => true,
				),
			),
			$snapshot['wp_setup']['active_plugins']
		);
	}

	/**
	 * Run the store setup sync and return the one snapshot it sends to the platform.
	 *
	 * @param array<string,mixed>                            $settings                          Gateway settings to store first.
	 * @param WooPaymentsAccountService|null                 $account_service                   Account service, or the default double.
	 * @param WooPaymentsWooPayEligibility|null              $woopay_eligibility                WooPay eligibility rules, or the container's.
	 * @param WooPaymentsDuplicateGatewayMethodsService|null $duplicate_gateway_methods_service Duplicate gateway methods service, or the container's.
	 * @return array<string,mixed>
	 */
	private function send_store_setup_snapshot( array $settings = array( 'enabled' => 'yes' ), ?WooPaymentsAccountService $account_service = null, ?WooPaymentsWooPayEligibility $woopay_eligibility = null, ?WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service = null ): array {
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );

		$snapshots  = array();
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'send_store_setup' )->willReturnCallback(
			static function ( array $snapshot ) use ( &$snapshots ): array {
				$snapshots[] = $snapshot;
				return array( 'result' => 'success' );
			}
		);

		$this->create_service( new RecordingActionSchedulerService(), $api_client, $account_service, $woopay_eligibility, $duplicate_gateway_methods_service )->handle_wcpay_store_setup_sync();

		$this->assertCount( 1, $snapshots, 'The store setup sync sends one snapshot.' );

		return $snapshots[0];
	}

	/**
	 * @testdox Should report real duplicate gateways and the Multi-Currency flag in the store setup snapshot: $_dataName.
	 * @dataProvider provider_store_setup_sync_multi_currency_flag
	 *
	 * @param array<string,string>   $flag_options        Multi-Currency flag option to write; none leaves it never written.
	 * @param array<string,string[]> $duplicates          Duplicate gateways found, by payment method.
	 * @param bool                   $expected_mc_enabled The Multi-Currency flag the snapshot reports.
	 */
	public function test_store_setup_sync_reports_duplicates_and_multi_currency( array $flag_options, array $duplicates, bool $expected_mc_enabled ): void {
		delete_option( '_wcpay_feature_customer_multi_currency' );
		foreach ( $flag_options as $option => $value ) {
			update_option( $option, $value );
		}

		$duplicate_gateway_methods_service = $this->getMockBuilder( WooPaymentsDuplicateGatewayMethodsService::class )
			->onlyMethods( array( 'get_duplicated_payment_method_ids' ) )
			->getMock();
		$duplicate_gateway_methods_service->method( 'get_duplicated_payment_method_ids' )
			->willReturn( $duplicates );

		$snapshot = $this->send_store_setup_snapshot( array( 'enabled' => 'yes' ), null, null, $duplicate_gateway_methods_service );

		$this->assertSame( $duplicates, $snapshot['payment_methods']['duplicates'] );
		$this->assertSame( $expected_mc_enabled, $snapshot['multi_currency_enabled'] );
		$this->assertFalse( $snapshot['stripe_billing_enabled'] );
	}

	/**
	 * Multi-Currency flag states and duplicate gateways, with the flag the snapshot should report.
	 *
	 * @return array<string,array{array<string,string>,array<string,string[]>,bool}>
	 */
	public static function provider_store_setup_sync_multi_currency_flag(): array {
		return array(
			'flag off, one duplicate'           => array(
				array( '_wcpay_feature_customer_multi_currency' => '0' ),
				array( 'card' => array( 'woocommerce_payments', 'stripe' ) ),
				false,
			),
			// The WooPayments extension defaults the flag to enabled.
			'flag never written, no duplicates' => array( array(), array(), true ),
		);
	}

	/**
	 * @testdox Should send the account locale to the platform when the site language changes: $_dataName.
	 * @dataProvider provider_site_language_change
	 *
	 * @param string                         $old_language     WPLANG before the change.
	 * @param string                         $new_language     WPLANG written.
	 * @param array<string,mixed>            $account_data     Cached account data.
	 * @param array<int,array<string,mixed>> $expected_updates Account updates sent to the platform.
	 */
	public function test_site_language_change_updates_the_account_locale( string $old_language, string $new_language, array $account_data, array $expected_updates ): void {
		update_option( 'WPLANG', $old_language );
		$updates = array();
		$service = $this->create_service( new RecordingActionSchedulerService(), $this->create_account_update_recorder( $updates ), $this->create_account_service( $account_data ) );
		$service->register();

		update_option( 'WPLANG', $new_language );

		$this->assertSame( $expected_updates, $updates );
	}

	/**
	 * Site language changes, with the account updates each should send.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,array<int,array<string,mixed>>}>
	 */
	public static function provider_site_language_change(): array {
		$account = array( 'account_id' => 'acct_native_test' );

		return array(
			'a new language is sent'             => array( '', 'de_DE', $account, array( array( 'locale' => 'de_DE' ) ) ),
			'a reset to the default sends en_US' => array( 'de_DE', '', $account, array( array( 'locale' => 'en_US' ) ) ),
			'nothing is sent without an account' => array( '', 'de_DE', array(), array() ),
		);
	}

	/**
	 * @testdox Should re-send the account's business URL once only when the store goes live: $_dataName.
	 * @dataProvider provider_store_launch
	 *
	 * @param string                         $old_value        Coming-soon value before the write.
	 * @param string                         $new_value        Coming-soon value written.
	 * @param array<string,mixed>            $account_data     Cached account data.
	 * @param array<int,array<string,mixed>> $expected_updates Account updates sent to the platform.
	 */
	public function test_store_launch_resends_the_business_url_only_when_the_store_goes_live( string $old_value, string $new_value, array $account_data, array $expected_updates ): void {
		update_option( 'woocommerce_coming_soon', $old_value );
		$updates = array();
		$service = $this->create_service( new RecordingActionSchedulerService(), $this->create_account_update_recorder( $updates ), $this->create_account_service( $account_data ) );
		$service->register();

		update_option( 'woocommerce_coming_soon', $new_value );

		$this->assertSame( $expected_updates, $updates );
	}

	/**
	 * Coming-soon changes and accounts, with the account updates each should send.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,array<int,array<string,mixed>>}>
	 */
	public static function provider_store_launch(): array {
		$account_with_url = self::get_account_with_business_url();

		return array(
			'the store goes live'    => array( 'yes', 'no', $account_with_url, array( array( 'business_url' => 'https://shop.example.com' ) ) ),
			'the store goes private' => array( 'no', 'yes', $account_with_url, array() ),
			'no connected account'   => array( 'yes', 'no', array(), array() ),
			'no business URL'        => array( 'yes', 'no', array( 'account_id' => 'acct_native_test' ), array() ),
		);
	}

	/**
	 * Create an API client that records each account update it is asked to send.
	 *
	 * @param array<int,array<string,mixed>> $updates Receives each update body.
	 * @return WooPaymentsApiClient
	 */
	private function create_account_update_recorder( array &$updates ): WooPaymentsApiClient {
		$api_client = $this->create_api_client( array( 'update_account' ) );
		$api_client->method( 'update_account' )->willReturnCallback(
			static function ( array $body ) use ( &$updates ): array {
				$updates[] = $body;
				return array();
			}
		);

		return $api_client;
	}

	/**
	 * Account data with a business URL, in the shape the client reads it (client 11.1.0
	 * `includes/class-wc-payments-account.php:459-462`, `business_profile.url`).
	 *
	 * @return array<string,mixed>
	 */
	private static function get_account_with_business_url(): array {
		return array(
			'account_id'       => 'acct_native_test',
			'business_profile' => array( 'url' => 'https://shop.example.com' ),
		);
	}

	/**
	 * Client 11.1.0 adds the currencies of the methods in its payment method map (`includes/compat/multi-currency/class-wc-payments-currency-manager.php:67-70,96-135`),
	 * which holds Amazon Pay only while its feature is on (`includes/payment-methods/Configs/Registry/PaymentMethodDefinitionRegistry.php:102-105`).
	 * A domestic-only method requires the account's domestic currency, the currency of the account's country, not the account's default currency (`:71`, `:81`).
	 *
	 * @testdox Should auto-enable the currencies a newly enabled payment method requires: $_dataName.
	 * @dataProvider provider_settings_save_currency_auto_add
	 *
	 * @param array<string,string> $feature_flags        Feature flag options to write.
	 * @param string               $country              Account country.
	 * @param string               $default_currency     Account default currency.
	 * @param array<string,float>  $rates                Cached currency rates.
	 * @param string               $method               Payment method enabled beside card.
	 * @param string[]             $expected_enabled     Currencies that must be enabled after the save.
	 * @param string[]             $expected_not_enabled Currencies that must not be enabled after the save.
	 */
	public function test_settings_save_auto_adds_the_currencies_a_method_requires( array $feature_flags, string $country, string $default_currency, array $rates, string $method, array $expected_enabled, array $expected_not_enabled ): void {
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		foreach ( $feature_flags as $option => $value ) {
			update_option( $option, $value );
		}
		$this->register_unavailable_rate_provider();
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => $rates,
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );

		// The account fields follow the recorded Fixtures/platform-test-drive-account.json `account` (`account_id`, `country`, `store_currencies.default`).
		$account_service = $this->create_account_service(
			array(
				'account_id'       => 'acct_native_test',
				'country'          => $country,
				'store_currencies' => array( 'default' => $default_currency ),
			)
		);
		$service         = $this->create_service( new RecordingActionSchedulerService(), null, $account_service );
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', $method ) ) );

		// A save that needs no currency leaves the option unwritten.
		$enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$this->assertSame( $expected_enabled, array_values( array_intersect( $expected_enabled, $enabled_currencies ) ), 'The method\'s required currencies must be auto-enabled.' );
		$this->assertSame( array(), array_values( array_intersect( $expected_not_enabled, $enabled_currencies ) ), 'A currency the method does not require must stay disabled.' );
	}

	/**
	 * Accounts and newly enabled methods, with the currencies the save should and should not enable.
	 *
	 * @return array<string,array{array<string,string>,string,string,array<string,float>,string,string[],string[]}>
	 */
	public static function provider_settings_save_currency_auto_add(): array {
		return array(
			'iDEAL on a US account'                     => array( array(), 'US', 'usd', array( 'eur' => 0.9 ), 'ideal', array( 'EUR' ), array() ),
			// Amazon Pay's supported currencies on a GB account include EUR (client 11.1.0 `includes/payment-methods/Configs/Definitions/AmazonPayDefinition.php:106-128`).
			'Amazon Pay with its feature on'            => array( array( '_wcpay_feature_amazon_pay' => '1' ), 'GB', 'gbp', array( 'eur' => 0.9 ), 'amazon_pay', array( 'EUR' ), array() ),
			'Amazon Pay with its feature off'           => array( array( '_wcpay_feature_amazon_pay' => '0' ), 'GB', 'gbp', array( 'eur' => 0.9 ), 'amazon_pay', array(), array( 'EUR' ) ),
			'Affirm on a CA account with a EUR default' => array(
				array(),
				'CA',
				'eur',
				array(
					'cad' => 1.37,
					'eur' => 0.9,
				),
				'affirm',
				array( 'CAD' ),
				array( 'EUR' ),
			),
		);
	}

	/**
	 * Register an unavailable automatic-rate provider so the test exercises the production cache-fallback path.
	 */
	private function register_unavailable_rate_provider(): void {
		$provider = $this->createMock( CurrencyRateProvider::class );
		$provider->method( 'get_id' )->willReturn( 'test-outage' );
		$provider->method( 'is_available' )->willReturn( false );

		$registrar = $this->createMock( CurrencyRateProviderRegistrarInterface::class );
		$registrar->method( 'register' )->willReturnCallback(
			static function ( CurrencyRateProviderRegistry $registry ) use ( $provider ): void {
				$registry->register( $provider );
			}
		);

		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars(
			array( $registrar )
		);
	}

	/**
	 * @testdox Should leave the enabled currencies untouched when Multi-Currency is disabled.
	 */
	public function test_settings_save_does_not_touch_currencies_when_multi_currency_disabled(): void {
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array( 'eur' => 0.9 ),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );

		$service = $this->create_service( new RecordingActionSchedulerService(), null, $this->create_account_service( array( 'account_id' => 'acct_native_test' ) ) );
		$service->register();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) ) );

		$this->assertFalse( get_option( 'wcpay_multi_currency_enabled_currencies' ), 'Disabled Multi-Currency must not gain enabled currencies.' );
	}

	/**
	 * @testdox Recurring store setup sync is scheduled once in the WooPayments group.
	 */
	public function test_schedule_recurring_actions_schedules_store_setup_sync_once(): void {
		$service = $this->create_service();

		$service->schedule_recurring_actions();
		$service->schedule_recurring_actions();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
					'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
	}

	/**
	 * @testdox A WooCommerce update queues one store setup sync beside the recurring one, and that sync sends the snapshot.
	 */
	public function test_queue_store_setup_sync_adds_one_update_sync(): void {
		$api_client = $this->create_api_client( array( 'is_available', 'send_store_setup' ) );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->expects( $this->once() )->method( 'send_store_setup' );
		$service = $this->create_service( new WooPaymentsActionSchedulerService(), $api_client );
		$service->register();
		$service->schedule_recurring_actions();

		$service->queue_store_setup_sync();
		$service->queue_store_setup_sync();

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
				'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 2, $pending, 'The recurring sync and one sync for the update, however often the update asks.' );
		$update_sync = as_get_scheduled_actions(
			array(
				'hook'   => WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
				'args'   => array( 'woocommerce_updated' ),
				'group'  => WooPaymentsActionSchedulerService::GROUP_ID,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $update_sync );
		$update_sync = array_values( $update_sync );

		\ActionScheduler::store()->fetch_action( (string) $update_sync[0] )->execute();
	}

	/**
	 * @testdox Compatibility updates are scheduled two minutes out through the WooPayments extension's Action Scheduler hook.
	 */
	public function test_schedule_compatibility_data_update_schedules_delayed_job(): void {
		$scheduler = new RecordingActionSchedulerService();
		$service   = $this->create_service( $scheduler );
		$before    = time();

		$service->schedule_compatibility_data_update();

		$this->assertSame( 'wcpay_update_compatibility_data', $scheduler->scheduled_jobs[0]['hook'] );
		$this->assertSame( array(), $scheduler->scheduled_jobs[0]['args'] );
		// Two minutes out, as the client schedules it (class-compatibility-service.php:54-57).
		$this->assertGreaterThanOrEqual( $before + 2 * MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + 2 * MINUTE_IN_SECONDS, $scheduler->scheduled_jobs[0]['timestamp'] );
	}

	/**
	 * @testdox Compatibility hook sends the WooPayments-compatible payload through the API client.
	 */
	public function test_update_compatibility_data_sends_payload(): void {
		$api_client = $this->create_api_client( array( 'update_compatibility_data' ) );
		$api_client->expects( $this->once() )
			->method( 'update_compatibility_data' )
			->with(
				$this->callback(
					function ( array $payload ): bool {
						return isset(
							$payload['woopayments_version'],
							$payload['woocommerce_version'],
							$payload['woocommerce_permalinks'],
							$payload['woocommerce_shop'],
							$payload['woocommerce_cart'],
							$payload['woocommerce_checkout'],
							$payload['blog_theme'],
							$payload['active_plugins'],
							$payload['post_types_count']
						);
					}
				)
			)
			->willReturn( array( 'result' => 'success' ) );

		$this->create_service( new RecordingActionSchedulerService(), $api_client )->handle_wcpay_update_compatibility_data();
	}

	/**
	 * @testdox A platform error in the $job job is logged with its status and code, never its message.
	 * @testWith ["site language"]
	 *           ["store setup sync"]
	 *           ["compatibility data update"]
	 *           ["store launch"]
	 *
	 * Client 11.1.0 appends the platform's message to these lines. The Fee details job's line is covered in WooPaymentsFeeDetailsNoteControllerTest.
	 *
	 * @param string $job Operational job whose platform call fails.
	 */
	public function test_job_platform_error_log_leaves_out_platform_text( string $job ): void {
		$methods    = array(
			'site language'             => array( 'update_account' ),
			'store setup sync'          => array( 'is_available', 'send_store_setup' ),
			'compatibility data update' => array( 'update_compatibility_data' ),
			'store launch'              => array( 'update_account' ),
		);
		$api_client = $this->create_api_client( $methods[ $job ] );
		if ( 'store setup sync' === $job ) {
			$api_client->method( 'is_available' )->willReturn( true );
		}
		$api_client->method( end( $methods[ $job ] ) )->willThrowException( self::make_provider_error() );
		$service = $this->create_service( new RecordingActionSchedulerService(), $api_client, $this->create_account_service( self::get_account_with_business_url() ) );
		$logger  = RecordingWcLogger::install();

		switch ( $job ) {
			case 'site language':
				$service->handle_site_language_update( 'WPLANG', '', 'de_DE' );
				break;
			case 'store setup sync':
				$service->handle_wcpay_store_setup_sync();
				break;
			case 'compatibility data update':
				$service->handle_wcpay_update_compatibility_data();
				break;
			case 'store launch':
				$service->handle_store_launch( 'yes', 'no' );
				break;
		}

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Create a store setup report controller.
	 *
	 * @param WooPaymentsActionSchedulerService|null         $scheduler          Scheduler service.
	 * @param WooPaymentsApiClient|null                      $api_client         API client.
	 * @param WooPaymentsAccountService|null                 $account_service    Account service.
	 * @param WooPaymentsWooPayEligibility|null              $woopay_eligibility WooPay eligibility rules, or the container's.
	 * @param WooPaymentsDuplicateGatewayMethodsService|null $duplicate_gateway_methods_service Duplicate gateway methods service, or the container's.
	 * @return WooPaymentsStoreSetupReportController
	 */
	private function create_service(
		?WooPaymentsActionSchedulerService $scheduler = null,
		?WooPaymentsApiClient $api_client = null,
		?WooPaymentsAccountService $account_service = null,
		?WooPaymentsWooPayEligibility $woopay_eligibility = null,
		?WooPaymentsDuplicateGatewayMethodsService $duplicate_gateway_methods_service = null
	): WooPaymentsStoreSetupReportController {
		$service = new WooPaymentsStoreSetupReportController();
		$service->init(
			$scheduler ?? new RecordingActionSchedulerService(),
			$api_client ?? $this->create_api_client( array() ),
			$account_service ?? $this->create_account_service(),
			$duplicate_gateway_methods_service ?? wc_get_container()->get( WooPaymentsDuplicateGatewayMethodsService::class ),
			$woopay_eligibility ?? wc_get_container()->get( WooPaymentsWooPayEligibility::class )
		);

		$this->services[] = $service;

		return $service;
	}

	/**
	 * Create a WooPayments API client mock.
	 *
	 * @param string[] $methods Mocked method names.
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client( array $methods ): WooPaymentsApiClient {
		$builder = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor();

		if ( ! empty( $methods ) ) {
			$builder->onlyMethods( $methods );
		}

		return $builder->getMock();
	}

	/**
	 * Create an account service mock.
	 *
	 * @param array<string,mixed> $account_data         Account data returned by the mock.
	 * @param bool                $test_mode            Whether test mode is enabled.
	 * @param bool                $can_process_payments Whether the account can process payments.
	 * @param bool                $test_account         Whether the account is a test-drive account.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( array $account_data = array(), bool $test_mode = true, bool $can_process_payments = true, bool $test_account = false ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_cached_account_data', 'can_process_payments', 'has_test_account', 'is_payment_request_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'can_process_payments' )->willReturn( $can_process_payments );
		$account_service->method( 'has_test_account' )->willReturn( $test_account );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( true );
		// Gateway settings are read through the real get_gateway_setting(), which needs the proxy.
		$database_cache = new WooPaymentsDatabaseCache();
		$database_cache->init( wc_get_container()->get( LegacyProxy::class ) );
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), $database_cache );

		return $account_service;
	}

	/**
	 * Remove the hooks a controller registered.
	 *
	 * @param WooPaymentsStoreSetupReportController $service Controller instance.
	 */
	private function remove_report_hooks( WooPaymentsStoreSetupReportController $service ): void {
		remove_action( 'wcpay_store_setup_sync', array( $service, 'handle_wcpay_store_setup_sync' ) );
		remove_action( 'wcpay_update_compatibility_data', array( $service, 'handle_wcpay_update_compatibility_data' ) );
		remove_action( 'woocommerce_payments_account_refreshed', array( $service, 'schedule_compatibility_data_update' ) );
		remove_action( 'after_switch_theme', array( $service, 'schedule_compatibility_data_update' ) );
		remove_action( 'action_scheduler_ensure_recurring_actions', array( $service, 'schedule_recurring_actions' ) );
		remove_action( 'updated_option', array( $service, 'handle_site_language_update' ) );
		remove_action( 'update_option_woocommerce_coming_soon', array( $service, 'handle_store_launch' ) );
		remove_action( 'update_option_woocommerce_woocommerce_payments_settings', array( $service, 'maybe_add_missing_currencies' ) );
	}

	/**
	 * Create the WooPay eligibility rules over an account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsWooPayEligibility
	 */
	private function create_woopay_eligibility( WooPaymentsAccountService $account_service ): WooPaymentsWooPayEligibility {
		$woopay_eligibility = new WooPaymentsWooPayEligibility();
		$woopay_eligibility->init( $account_service );

		return $woopay_eligibility;
	}
}
