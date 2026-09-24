<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCachingEnvironment;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use WC_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the MultiCurrencyRestController class.
 */
class MultiCurrencyRestControllerTest extends WC_Unit_Test_Case {

	/**
	 * Controllers created during a test.
	 *
	 * @var MultiCurrencyRestController[]
	 */
	private array $controllers = array();

	/**
	 * Options touched by the controller.
	 *
	 * @var string[]
	 */
	private array $options = array(
		'wcpay_multi_currency_enabled_currencies',
		'wcpay_multi_currency_exchange_rate_eur',
		'wcpay_multi_currency_manual_rate_eur',
		'wcpay_multi_currency_price_rounding_eur',
		'wcpay_multi_currency_price_charm_eur',
		'wcpay_multi_currency_exchange_rate_gbp',
		'wcpay_multi_currency_manual_rate_gbp',
		'wcpay_multi_currency_price_rounding_gbp',
		'wcpay_multi_currency_price_charm_gbp',
		'wcpay_multi_currency_enable_auto_currency',
		'wcpay_multi_currency_enable_storefront_switcher',
		'wcpay_multi_currency_rendering_mode',
		'_wcpay_feature_mc_cache_optimized',
		'wcpay_multi_currency_cache_autodetect_done',
		'wcpay_multi_currency_cache_recommendation_dismissed',
		'wcpay_multi_currency_stored_customer_currencies',
		'wcpay_multi_currency_cached_currencies',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->controllers as $controller ) {
			remove_action( 'rest_api_init', array( $controller, 'handle_rest_api_init' ) );
		}

		foreach ( $this->options as $option ) {
			delete_option( $option );
		}

		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * @testdox Should not register REST hooks when plugin owns runtime.
	 */
	public function test_does_not_register_rest_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

		$sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $sut, 'handle_rest_api_init' ) ) );
	}

	/**
	 * @testdox Should register REST hooks and routes when core owns runtime.
	 */
	public function test_registers_rest_hooks_and_routes_when_core_owns_runtime(): void {
		$sut = $this->create_controller();

		$sut->register();
		$sut->register();
		/**
		 * Fires REST API route registration for the controller under test.
		 *
		 * @since 11.0.0
		 */
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertSame( 10, has_action( 'rest_api_init', array( $sut, 'handle_rest_api_init' ) ) );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/public/config', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/currencies', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/update-enabled-currencies', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/currencies/(?P<currency_code>[A-Za-z]{3})', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/get-settings', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/update-settings', $routes );
	}

	/**
	 * @testdox Should omit public config route when cache mode is inactive.
	 */
	public function test_omits_public_config_route_when_cache_mode_is_inactive(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, null, false );

		$sut->register();
		/**
		 * Fires REST API route registration for the controller under test.
		 *
		 * @since 11.0.0
		 */
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( '/wc/v3/payments/multi-currency/public/config', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/currencies', $routes );
	}

	/**
	 * @testdox Should require manage WooCommerce capability.
	 */
	public function test_check_permission_requires_manage_woocommerce(): void {
		$sut = $this->create_controller();

		wp_set_current_user( 0 );
		$this->assertFalse( $sut->check_permission() );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( $sut->check_permission() );
	}

	/**
	 * @testdox Should return the static currency catalog from the settings projection.
	 */
	public function test_returns_store_currencies_from_the_static_settings_catalog(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$sut = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
			$this->create_state_builder( array( 'USD', 'EUR' ), array( 'USD', 'EUR' ) )
		);

		$response = $sut->get_store_currencies();
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'available', 'enabled', 'default', 'automatic_rates' ), array_keys( $data ) );
		$this->assertSame( array_merge( array( 'USD' ), array_diff( array_keys( get_woocommerce_currencies() ), array( 'USD' ) ) ), array_keys( $data['available'] ) );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $data['enabled'] ) );
		$this->assertSame( 1.0, $data['default']['rate'] );
		$this->assertNull( $data['enabled']['GBP']['rate'] );
		$this->assertSame(
			array(
				'available' => false,
				'source'    => null,
			),
			$data['automatic_rates']
		);
	}

	/**
	 * @testdox Should reject automatic rate writes without a provider before changing any stored option.
	 */
	public function test_rejects_automatic_rate_writes_without_a_provider_before_mutating_options(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', 1.1 );
		update_option( 'wcpay_multi_currency_price_rounding_eur', 0.5 );
		update_option( 'wcpay_multi_currency_price_charm_eur', -0.1 );
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/currencies/EUR' );
		$request->set_param( 'currency_code', 'EUR' );
		$request->set_param( 'exchange_rate_type', 'automatic' );
		$request->set_param( 'manual_rate', 1.25 );
		$request->set_param( 'price_rounding', 1.0 );
		$request->set_param( 'price_charm', 0.99 );

		$response = $sut->update_single_currency_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woocommerce_multi_currency_automatic_rates_unavailable', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( 'manual', get_option( 'wcpay_multi_currency_exchange_rate_eur' ) );
		$this->assertSame( 1.1, get_option( 'wcpay_multi_currency_manual_rate_eur' ) );
		$this->assertSame( 0.5, get_option( 'wcpay_multi_currency_price_rounding_eur' ) );
		$this->assertSame( -0.1, get_option( 'wcpay_multi_currency_price_charm_eur' ) );
	}

	/**
	 * @testdox Should permit automatic settings during a registered provider outage.
	 */
	public function test_permits_automatic_rate_writes_when_a_registered_provider_is_unavailable(): void {
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, null, true, false );
		$request = $this->create_automatic_rate_request();

		$response = $sut->update_single_currency_settings( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'automatic', get_option( 'wcpay_multi_currency_exchange_rate_eur' ) );
	}

	/**
	 * @testdox Should activate a configured currency after a positive manual rate write without a provider.
	 */
	public function test_activates_a_configured_currency_after_a_positive_manual_rate_write_without_a_provider(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$builder = $this->create_real_state_builder();
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $builder );
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/currencies/EUR' );
		$request->set_param( 'currency_code', 'EUR' );
		$request->set_param( 'exchange_rate_type', 'manual' );
		$request->set_param( 'manual_rate', 1.23 );
		$request->set_param( 'price_rounding', 1.0 );
		$request->set_param( 'price_charm', 0.99 );

		$response = $sut->update_single_currency_settings( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1.23, $builder->build()->get_enabled_currencies()['EUR']->get_rate() );
	}

	/**
	 * @testdox Should update enabled currencies and remove removed currency settings.
	 */
	public function test_updates_enabled_currencies_and_removes_removed_currency_settings(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR', 'GBP' ) );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.72' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_price_rounding_gbp', '1.00' );
		update_option( 'wcpay_multi_currency_price_charm_gbp', '0.99' );
		$sut     = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
			$this->create_state_builder( array( 'USD', 'EUR', 'GBP' ), array( 'USD', 'EUR', 'GBP' ) )
		);
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-enabled-currencies' );
		$request->set_param( 'enabled', array( 'USD', 'EUR' ) );

		$response = $sut->update_enabled_currencies( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'USD', 'EUR' ), get_option( 'wcpay_multi_currency_enabled_currencies' ) );
		$this->assertFalse( get_option( 'wcpay_multi_currency_manual_rate_gbp' ) );
		$this->assertFalse( get_option( 'wcpay_multi_currency_exchange_rate_gbp' ) );
		$this->assertFalse( get_option( 'wcpay_multi_currency_price_rounding_gbp' ) );
		$this->assertFalse( get_option( 'wcpay_multi_currency_price_charm_gbp' ) );
	}

	/**
	 * @testdox Should reflect the updated enabled set through a real builder, proving reset() invalidates the memo.
	 */
	public function test_update_enabled_currencies_invalidates_real_builder_memo(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.91' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.78' );

		$builder = $this->create_real_state_builder();
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $builder );

		$this->assertSame(
			array( 'USD', 'EUR', 'GBP' ),
			array_keys( $builder->build()->get_enabled_currencies() ),
			'The real builder should start with both enabled currencies.'
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-enabled-currencies' );
		$request->set_param( 'enabled', array( 'USD', 'EUR' ) );

		$response = $sut->update_enabled_currencies( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array( 'USD', 'EUR' ),
			array_keys( $data['enabled'] ),
			'The response must reflect the new enabled set, which only happens if reset() invalidated the memoized state built during validation.'
		);
		$this->assertSame(
			array( 'USD', 'EUR' ),
			array_keys( $builder->build()->get_enabled_currencies() ),
			'A subsequent build() must also reflect the new enabled set.'
		);
	}

	/**
	 * @testdox Should persist and return every currency from the onboarding selection with the store default preserved.
	 */
	public function test_update_enabled_currencies_persists_complete_onboarding_selection(): void {
		foreach (
			array(
				'EUR' => '0.91',
				'GBP' => '0.78',
				'CAD' => '1.37',
				'AUD' => '1.52',
			) as $currency_code => $rate
		) {
			$currency_id = strtolower( $currency_code );
			update_option( 'wcpay_multi_currency_exchange_rate_' . $currency_id, 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_' . $currency_id, $rate );
		}

		$builder = $this->create_real_state_builder();
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE, $builder );
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-enabled-currencies' );
		// WooPayments 11.1.0 multi-currency-on-boarding.spec.ts:139 supplies the GBP/EUR/CAD/AUD selection; DECISIONS.md (2026-08-08) maps it to native settings persistence.
		$request->set_param( 'enabled', array( 'USD', 'GBP', 'EUR', 'CAD', 'AUD' ) );

		$response = $sut->update_enabled_currencies( $request );
		$data     = $response->get_data();
		$expected = array( 'AUD', 'CAD', 'EUR', 'GBP', 'USD' );
		$stored   = get_option( 'wcpay_multi_currency_enabled_currencies' );
		$returned = array_keys( $data['enabled'] );

		sort( $stored );
		sort( $returned );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $expected, $stored, 'The authoritative option should contain the submitted selection and the default currency.' );
		$this->assertSame( $expected, $returned, 'The REST response should acknowledge the complete persisted enabled set.' );
	}

	/**
	 * @testdox Should reject invalid enabled currency.
	 */
	public function test_rejects_invalid_enabled_currency(): void {
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-enabled-currencies' );
		$request->set_param( 'enabled', array( 'USD', 'XYZ' ) );

		$response = $sut->update_enabled_currencies( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertStringContainsString( 'XYZ', $response->get_error_message() );
	}

	/**
	 * @testdox Should read and update single currency settings.
	 */
	public function test_reads_and_updates_single_currency_settings(): void {
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/currencies/EUR' );
		$request->set_param( 'currency_code', 'EUR' );
		$request->set_param( 'exchange_rate_type', 'manual' );
		$request->set_param( 'manual_rate', 1.23 );
		$request->set_param( 'price_rounding', 1.0 );
		$request->set_param( 'price_charm', 0.99 );

		$response = $sut->update_single_currency_settings( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'manual', get_option( 'wcpay_multi_currency_exchange_rate_eur' ) );
		$this->assertSame( 1.23, get_option( 'wcpay_multi_currency_manual_rate_eur' ) );
		$this->assertSame( 1.0, get_option( 'wcpay_multi_currency_price_rounding_eur' ) );
		$this->assertSame( 0.99, get_option( 'wcpay_multi_currency_price_charm_eur' ) );
		$this->assertSame( 'manual', $data['exchange_rate_type'] );
		$this->assertSame( 1.23, $data['manual_rate'] );
	}

	/**
	 * @testdox Should reject invalid manual rate for single currency settings.
	 */
	public function test_rejects_invalid_manual_rate_for_single_currency_settings(): void {
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/currencies/EUR' );
		$request->set_param( 'currency_code', 'EUR' );
		$request->set_param( 'exchange_rate_type', 'manual' );
		$request->set_param( 'manual_rate', 0 );
		$request->set_param( 'price_rounding', 1.0 );
		$request->set_param( 'price_charm', 0.99 );

		$response = $sut->update_single_currency_settings( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertStringContainsString( 'Invalid manual currency rate', $response->get_error_message() );
	}

	/**
	 * @testdox Should read and update store settings.
	 */
	public function test_reads_and_updates_store_settings(): void {
		update_option( 'wcpay_multi_currency_rendering_mode', 'speed' );
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-settings' );
		$request->set_param( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$request->set_param( 'wcpay_multi_currency_enable_storefront_switcher', 'no' );
		$request->set_param( 'wcpay_multi_currency_rendering_mode', 'not-valid' );

		$response = $sut->update_settings( $request );
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_enable_auto_currency' ) );
		$this->assertSame( 'no', get_option( 'wcpay_multi_currency_enable_storefront_switcher' ) );
		$this->assertSame( 'speed', get_option( 'wcpay_multi_currency_rendering_mode' ) );
		$this->assertSame( 'yes', $data['wcpay_multi_currency_enable_auto_currency'] );
		$this->assertSame( 'no', $data['wcpay_multi_currency_enable_storefront_switcher'] );
		$this->assertSame( 'speed', $data['wcpay_multi_currency_rendering_mode'] );
	}

	/**
	 * @testdox Should project cache recommendation state and persist an explicit dismissal.
	 */
	public function test_projects_cache_recommendation_state_and_persists_an_explicit_dismissal(): void {
		update_option( '_wcpay_feature_mc_cache_optimized', '1' );
		update_option( 'wcpay_multi_currency_rendering_mode', 'speed' );
		$cache_rendering_service = $this->getMockBuilder( MultiCurrencyCacheRenderingService::class )
			->onlyMethods( array( 'maybe_auto_enable_cache_rendering_mode' ) )
			->getMock();
		$cache_rendering_service->expects( $this->never() )->method( 'maybe_auto_enable_cache_rendering_mode' );
		$cache_rendering_service->init( $this->create_active_caching_environment() );
		$sut     = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
			null,
			true,
			null,
			$cache_rendering_service
		);
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-settings' );
		$request->set_param( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$request->set_param( 'wcpay_multi_currency_enable_storefront_switcher', 'no' );
		$request->set_param( 'wcpay_multi_currency_cache_recommendation_dismissed', 'yes' );

		$get_data = $sut->get_settings()->get_data();
		$this->assertFalse( get_option( MultiCurrencyCacheRenderingService::AUTODETECT_DONE_OPTION ) );
		$update_data = $sut->update_settings( $request )->get_data();

		$this->assertTrue( $get_data['should_recommend_cache_mode'] );
		$this->assertFalse( $get_data['cache_recommendation_dismissed'] );
		$this->assertSame( 'yes', get_option( MultiCurrencyCacheRenderingService::DISMISSED_OPTION ) );
		$this->assertTrue( $update_data['cache_recommendation_dismissed'] );
		$this->assertFalse( $update_data['should_recommend_cache_mode'] );

		$request->set_param( 'wcpay_multi_currency_cache_recommendation_dismissed', 'no' );
		$restored_data = $sut->update_settings( $request )->get_data();

		$this->assertSame( 'no', get_option( MultiCurrencyCacheRenderingService::DISMISSED_OPTION ) );
		$this->assertFalse( $restored_data['cache_recommendation_dismissed'] );
		$this->assertTrue( $restored_data['should_recommend_cache_mode'] );
	}

	/**
	 * @testdox Should reject invalid cache recommendation dismissals.
	 *
	 * @dataProvider get_invalid_cache_recommendation_dismissals
	 * @param mixed $dismissal Invalid dismissal value.
	 */
	public function test_rejects_invalid_cache_recommendation_dismissals( $dismissal ): void {
		update_option( 'wcpay_multi_currency_cache_recommendation_dismissed', 'no' );
		$sut     = $this->create_controller();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/update-settings' );
		$request->set_param( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$request->set_param( 'wcpay_multi_currency_enable_storefront_switcher', 'no' );
		$request->set_param( 'wcpay_multi_currency_cache_recommendation_dismissed', $dismissal );

		$sut->update_settings( $request );

		$this->assertSame( 'no', get_option( 'wcpay_multi_currency_cache_recommendation_dismissed' ) );
	}

	/**
	 * Get invalid cache recommendation dismissal values.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function get_invalid_cache_recommendation_dismissals(): array {
		return array(
			'other string' => array( 'maybe' ),
			'array'        => array( array( 'yes' ) ),
			'object'       => array( new \stdClass() ),
			'boolean'      => array( true ),
			'number'       => array( 1 ),
		);
	}

	/**
	 * @testdox Should return public config with cache control header.
	 */
	public function test_returns_public_config_with_cache_control_header(): void {
		$sut = $this->create_controller();

		$response = $sut->get_public_config();
		$headers  = $response->get_headers();
		$data     = $response->get_data();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'private, max-age=300', $headers['Cache-Control'] );
		$this->assertSame( 'USD', $data['default_currency'] );
		$this->assertSame( 'EUR', $data['selected_currency'] );
	}

	/**
	 * Create a REST controller.
	 *
	 * @param string                                  $owner                   Runtime owner.
	 * @param MultiCurrencyStateBuilder|null          $state_builder           State builder.
	 * @param bool                                    $cache_optimized_mode    Whether cache mode is active.
	 * @param bool|null                               $provider_available      Whether a registered provider is available.
	 * @param MultiCurrencyCacheRenderingService|null $cache_rendering_service Cache rendering service.
	 * @return MultiCurrencyRestController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_CORE,
		?MultiCurrencyStateBuilder $state_builder = null,
		bool $cache_optimized_mode = true,
		?bool $provider_available = null,
		?MultiCurrencyCacheRenderingService $cache_rendering_service = null
	): MultiCurrencyRestController {
		$controller = new MultiCurrencyRestController();
		$controller->init(
			$this->create_arbiter( $owner ),
			wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ),
			wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class ),
			$cache_rendering_service
		);
		$state_builder = $state_builder ?? $this->create_state_builder();
		$controller->set_state_builder( $state_builder );
		$controller->set_settings_currency_catalog( $this->create_settings_currency_catalog( $state_builder, $provider_available ) );
		$controller->set_frontend_projection_service( $this->create_frontend_projection_service( $cache_optimized_mode ) );

		$this->controllers[] = $controller;

		return $controller;
	}

	/**
	 * Create an active deterministic cache environment.
	 *
	 * @return MultiCurrencyCachingEnvironment
	 */
	private function create_active_caching_environment(): MultiCurrencyCachingEnvironment {
		return new class() extends MultiCurrencyCachingEnvironment {
			/**
			 * Tell whether a constant is defined.
			 *
			 * @param string $name Constant name.
			 * @return bool Whether the constant is defined.
			 */
			protected function is_constant_defined( string $name ): bool {
				return 'LSCWP_V' === $name;
			}
		};
	}

	/**
	 * Create a static settings catalog for the controller.
	 *
	 * @param MultiCurrencyStateBuilder $state_builder      State builder.
	 * @param bool|null                 $provider_available Whether a registered provider is available.
	 * @return MultiCurrencySettingsCurrencyCatalog
	 */
	private function create_settings_currency_catalog( MultiCurrencyStateBuilder $state_builder, ?bool $provider_available ): MultiCurrencySettingsCurrencyCatalog {
		$registry = new CurrencyRateProviderRegistry();
		if ( null !== $provider_available ) {
			$registry->register( $this->create_rate_provider( $provider_available ) );
		}

		return new MultiCurrencySettingsCurrencyCatalog(
			$this->create_localization_service(),
			$state_builder,
			new MultiCurrencyRateService( $registry )
		);
	}

	/**
	 * Create a rate provider with the requested availability.
	 *
	 * @param bool $available Whether the provider is available.
	 * @return \Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider
	 */
	private function create_rate_provider( bool $available ): \Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider {
		return new class( $available ) implements \Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider {
			/** @var bool */
			private bool $available;

			/**
			 * @param bool $available Whether the provider is available.
			 */
			public function __construct( bool $available ) {
				$this->available = $available;
			}

			/** @return string */
			public function get_id(): string {
				return $this->available ? 'available' : 'outage';
			}

			/** @return bool */
			public function is_available(): bool {
				return $this->available;
			}

			/** @return string[] */
			public function get_supported_currencies(): array {
				return array();
			}

			/**
			 * @param string        $currency_from Source currency.
			 * @param string[]|null $currencies_to Target currencies.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return array();
			}
		};
	}

	/**
	 * Create an automatic-rate settings request.
	 *
	 * @return WP_REST_Request
	 */
	private function create_automatic_rate_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/multi-currency/currencies/EUR' );
		$request->set_param( 'currency_code', 'EUR' );
		$request->set_param( 'exchange_rate_type', 'automatic' );
		$request->set_param( 'price_rounding', 1.0 );
		$request->set_param( 'price_charm', 0.99 );

		return $request;
	}

	/**
	 * Create a static runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Tell whether core should register.
			 *
			 * @return bool
			 */
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_CORE === $this->owner;
			}
		};
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param string[] $available_codes Available currency codes.
	 * @param string[] $enabled_codes   Enabled currency codes.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder(
		array $available_codes = array( 'USD', 'EUR', 'GBP' ),
		array $enabled_codes = array( 'USD', 'EUR' )
	): MultiCurrencyStateBuilder {
		$localization = $this->create_localization_service();
		$available    = array();

		foreach ( $available_codes as $currency_code ) {
			$available[ $currency_code ] = new MultiCurrencyCurrency( $localization, $currency_code, $this->get_rate_for_currency( $currency_code ), 'USD' === $currency_code );
		}

		$enabled = array();
		foreach ( $enabled_codes as $currency_code ) {
			$enabled[ $currency_code ] = $available[ $currency_code ];
		}

		$state = new MultiCurrencyState( $available, $enabled, $available['USD'], $enabled['EUR'] ?? $available['USD'] );

		return new class( $state ) extends MultiCurrencyStateBuilder {
			/**
			 * State snapshot.
			 *
			 * @var MultiCurrencyState
			 */
			private MultiCurrencyState $state;

			/**
			 * Constructor.
			 *
			 * @param MultiCurrencyState $state State snapshot.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * Build the state.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}

			/**
			 * Keep the deterministic state unchanged when the controller invalidates production state.
			 */
			public function reset(): void {
				// This fixed-state test double has no cache or collaborators to invalidate.
			}
		};
	}

	/**
	 * Create a real state builder backed by genuine collaborators.
	 *
	 * Unlike create_state_builder(), this returns a production MultiCurrencyStateBuilder
	 * whose build() reads the real enabled-currencies option and memoizes the result, so
	 * the controller's reset() call site is exercised end-to-end.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_real_state_builder(): MultiCurrencyStateBuilder {
		return new MultiCurrencyStateBuilder(
			$this->create_localization_service(),
			new MultiCurrencyRateService( new CurrencyRateProviderRegistry() ),
			new MultiCurrencyDatabaseCache()
		);
	}

	/**
	 * Create a frontend projection service test double.
	 *
	 * @param bool $cache_optimized_mode Whether cache mode is active.
	 * @return MultiCurrencyFrontendProjectionService
	 */
	private function create_frontend_projection_service( bool $cache_optimized_mode ): MultiCurrencyFrontendProjectionService {
		return new class( $cache_optimized_mode ) extends MultiCurrencyFrontendProjectionService {
			/**
			 * Whether cache mode is active.
			 *
			 * @var bool
			 */
			private bool $cache_optimized_mode;

			/**
			 * Constructor.
			 *
			 * @param bool $cache_optimized_mode Whether cache mode is active.
			 */
			public function __construct( bool $cache_optimized_mode ) {
				$this->cache_optimized_mode = $cache_optimized_mode;
			}

			/**
			 * Tell whether cache mode is active.
			 *
			 * @return bool
			 */
			public function is_cache_optimized_mode(): bool {
				return $this->cache_optimized_mode;
			}

			/**
			 * Get single-currency settings from preserved option keys.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_single_currency_settings( string $currency_code ): array {
				$currency_id = strtolower( $currency_code );

				return array(
					'exchange_rate_type' => get_option( 'wcpay_multi_currency_exchange_rate_' . $currency_id, 'automatic' ),
					'manual_rate'        => get_option( 'wcpay_multi_currency_manual_rate_' . $currency_id, null ),
					'price_rounding'     => get_option( 'wcpay_multi_currency_price_rounding_' . $currency_id, null ),
					'price_charm'        => get_option( 'wcpay_multi_currency_price_charm_' . $currency_id, null ),
				);
			}

			/**
			 * Get store settings from preserved option keys.
			 *
			 * @return array<string,mixed>
			 */
			public function get_settings(): array {
				return array(
					'wcpay_multi_currency_enable_auto_currency' => get_option( 'wcpay_multi_currency_enable_auto_currency', 'no' ),
					'wcpay_multi_currency_enable_storefront_switcher' => get_option( 'wcpay_multi_currency_enable_storefront_switcher', 'no' ),
					'wcpay_multi_currency_rendering_mode' => get_option( 'wcpay_multi_currency_rendering_mode', 'speed' ),
				);
			}

			/**
			 * Get deterministic public config.
			 *
			 * @return array<string,mixed>
			 */
			public function get_public_config(): array {
				return array(
					'default_currency'    => 'USD',
					'selected_currency'   => 'EUR',
					'charm_only_products' => true,
					'currencies'          => array(
						'USD' => array( 'code' => 'USD' ),
						'EUR' => array( 'code' => 'EUR' ),
					),
				);
			}
		};
	}

	/**
	 * Create a localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization_service(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}

	/**
	 * Get a deterministic rate for a currency code.
	 *
	 * @param string $currency_code Currency code.
	 * @return float
	 */
	private function get_rate_for_currency( string $currency_code ): float {
		return array(
			'USD' => 1.0,
			'EUR' => 0.91,
			'GBP' => 0.78,
			'CAD' => 1.37,
			'AUD' => 1.52,
		)[ $currency_code ];
	}
}
