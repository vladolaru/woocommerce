<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\MerchantRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Gateway settings dispatched through the core payment gateway routes.
 */
class WooPaymentsGatewayRestSettingsTest extends WC_Unit_Test_Case {

	private const ROUTE = '/wc/v3/payment_gateways/woocommerce_payments';

	/** @var string[] Platform requests in dispatch order. */
	private array $calls = array();

	/** @var array WooCommerce gateway list before registration. */
	private array $original_gateways;

	/** @var bool Connection reported by the test transport. */
	private bool $connected = true;

	/** @var array Account returned by the transport after expiry. */
	private array $account = array(
		'account_id' => 'acct_test',
		'is_live'    => true,
		'country'    => 'US',
		'fees'       => array(
			'card'   => array(),
			'klarna' => array(),
		),
	);

	/** Boot the active built-in provider with a recording platform transport. */
	public function setUp(): void {
		parent::setUp();
		$this->original_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Register the REST routes after setting the request's development mode.
	 *
	 * @param bool $dev_mode Whether the store runs in development mode.
	 */
	private function boot_routes( bool $dev_mode = false ): void {
		$container = wc_get_container();
		$container->reset_all_resolved();
		$transport = $this->createMock( HttpClient::class );
		$transport->method( 'is_connected' )->willReturnCallback( fn() => $this->connected );
		$transport->method( 'get_blog_id' )->willReturn( 123 );
		$transport->method( 'request' )->willReturnCallback(
			function ( $method, $path ) {
				$endpoint      = substr( wp_parse_url( $path, PHP_URL_PATH ), strlen( '/sites/123/wcpay/' ) );
				$this->calls[] = "$method $endpoint";
				$status        = 200;
				$body          = array();
				if ( 'accounts' === $endpoint ) {
					$body = $this->account;
				} elseif ( 'GET' === $method && 'fraud_ruleset' === $endpoint ) {
					$status = 404;
					$body   = array(
						'error' => array(
							'code'    => 'wcpay_fraud_ruleset_not_found',
							'message' => 'Missing ruleset.',
						),
					);
				} elseif ( 'payment_method_promotions' === $endpoint ) {
					$body = array( 'promotions' => array() );
				}
				return array(
					'response' => array( 'code' => $status ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( $body ),
				);
			}
		);
		add_filter( 'wcpay_dev_mode', static fn() => $dev_mode );
		add_filter(
			'pre_http_request',
			function ( $result, $args, $url ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( in_array( $path, array( '/wpcom/v2/wcpay/payment_methods/recommended', '/wpcom/v2/wcpay/incentives' ), true ) ) {
					$this->calls[] = $args['method'] . ' ' . $path;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '[]',
					);
				}
				return $result;
			},
			10,
			3
		);
		$container->replace( HttpClient::class, $transport );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'        => 'yes',
				'manual_capture' => 'no',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$container->get( WooPaymentsSetupTier::class )->invalidate();
		( new PaymentsBootstrap(
			static fn( $container, $type ) => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $type ),
			static fn( $container ) => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		) )->register( $container, RequestTypeUtil::REST );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		delete_transient( 'wcpay_pm_promotions' );
		delete_transient( 'wcpay_fraud_protection_settings' );
		$this->expire_account();
		$this->calls = array();
	}

	/** Restore request-scoped collaborators. */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_gateways;
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		$GLOBALS['wp_rest_server'] = null;
		parent::tearDown();
	}

	/** Give registration a stale entry for the next request. */
	private function expire_account(): void {
		wc_get_container()->get( WooPaymentsDatabaseCache::class )->write(
			WooPaymentsDatabaseCache::ACCOUNT_KEY,
			array(
				'data'    => array_merge(
					$this->account,
					array(
						'country' => 'GB',
						'fees'    => array( 'card' => array() ),
					)
				),
				'fetched' => time() - 2 * DAY_IN_SECONDS,
				'errored' => false,
			)
		);
	}

	/** Register gateways, then measure the settings route with the account expired again. */
	private function register_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		$this->expire_account();
		$this->calls = array();
	}

	/**
	 * Dispatch a decoded request as WC-CLI does.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Endpoint path.
	 * @param array  $params Request parameters.
	 */
	private function dispatch( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Only the save's store-setup call precedes core's gateway response.
	 *
	 * @testWith ["expired"]
	 *           ["missing"]
	 *           ["warm"]
	 * @param string $state Account cache state after registration.
	 */
	public function test_cold_core_save( string $state ): void {
		$this->boot_routes();
		$this->register_gateways();
		$cache = wc_get_container()->get( WooPaymentsDatabaseCache::class );
		if ( 'missing' === $state ) {
			$cache->delete( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		} elseif ( 'warm' === $state ) {
			$entry            = $cache->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
			$entry['fetched'] = time();
			$cache->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $entry );
		}
		$amazon_option = 'woocommerce_woocommerce_payments_amazon_pay_settings';
		$amazon_before = get_option( $amazon_option );
		$save_calls    = null;
		add_filter(
			'rest_dispatch_request',
			function ( $result ) use ( &$save_calls ) {
				$save_calls = $this->calls;
				return $result;
			},
			11
		);
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( 'manual_capture' => 'yes' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['manual_capture'] );
		$this->assertSame( array( 'POST accounts/store_setup' ), $save_calls );
		$this->assertSame( 'warm' === $state ? $save_calls : array( 'POST accounts/store_setup', 'GET accounts' ), $this->calls );
		$this->assertSame( 'yes', $response->get_data()['settings']['manual_capture']['value'] );
		if ( 'missing' === $state ) {
			$this->assertSame( $amazon_before, get_option( $amazon_option ) );
		}
		$this->expire_account();
		$this->calls = array();
		wc_get_container()->get( WooPaymentsAccountService::class )->get_cached_account_data();
		$this->assertSame( array( 'GET accounts' ), $this->calls );
	}

	/**
	 * Core reports the current setup status even when the stored account says the opposite.
	 *
	 * @testWith [true, false]
	 *           [false, true]
	 * @param bool $stored_enabled   Stored payments status.
	 * @param bool $platform_enabled Current payments status.
	 */
	public function test_setup_status_after_registration( bool $stored_enabled, bool $platform_enabled ): void {
		$this->boot_routes();
		$this->account['status']           = 'complete';
		$this->account['payments_enabled'] = $platform_enabled;
		$this->register_gateways();
		$cache                             = wc_get_container()->get( WooPaymentsDatabaseCache::class );
		$entry                             = $cache->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		$entry['data']['payments_enabled'] = $stored_enabled;
		$cache->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $entry );
		$account      = wc_get_container()->get( WooPaymentsAccountService::class );
		$gateway      = WC()->payment_gateways()->payment_gateways()['woocommerce_payments'];
		$stored_setup = $account->run_without_account_refresh( array( $gateway, 'needs_setup' ) );
		$this->assertSame( ! $stored_enabled, $stored_setup );
		$this->assertSame( array(), $this->calls );
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( 'manual_capture' => 'yes' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ! $platform_enabled, $response->get_data()['needs_setup'] );
		$this->assertSame( array( 'POST accounts/store_setup', 'GET accounts' ), $this->calls );
	}

	/**
	 * JSON, query and decoded CLI bodies all update the shared settings row.
	 *
	 * @testWith ["PUT", "v3", "json"]
	 *           ["PATCH", "v2", "query"]
	 *           ["POST", "v3", "body"]
	 * @param string $method HTTP method.
	 * @param string $version API version.
	 * @param string $source Request parameter source.
	 */
	public function test_toggle_capture( string $method, string $version, string $source ): void {
		$this->boot_routes();
		$this->register_gateways();
		foreach ( array( 'yes', 'no' ) as $value ) {
			$request = new WP_REST_Request( $method, str_replace( 'v3', $version, self::ROUTE ) );
			$params  = array( 'settings' => array( 'manual_capture' => $value ) );
			if ( 'json' === $source ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( $params ) );
			} elseif ( 'query' === $source ) {
				$request->set_query_params( $params );
			} else {
				$request->set_body_params( $params );
			}
			$this->calls = array();
			$response    = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $value, $response->get_data()['settings']['manual_capture']['value'] ?? null );
			$this->assertContains( 'POST accounts/store_setup', $this->calls );
			$this->assertSame( 'yes' === $value, wc_get_container()->get( WooPaymentsAccountService::class )->is_manual_capture_enabled() );
		}
	}

	/** Reading the card settings and submitting the same choices leaves the raw row alone. */
	public function test_gateway_settings_round_trip(): void {
		$this->boot_routes();
		$this->register_gateways();
		$before   = $this->stored_settings_bytes();
		$settings = $this->dispatch( 'GET', self::ROUTE )->get_data()['settings'];
		$express  = array( 'payment_request', 'woopay', 'amazon_pay' );
		$expected = array(
			'manual_capture'                    => 'no',
			'saved_cards'                       => 'yes',
			'test_mode'                         => 'no',
			'enable_logging'                    => 'no',
			'payment_request_button_type'       => 'default',
			'payment_request_button_theme'      => 'dark',
			'payment_request_button_size'       => 'medium',
			'express_checkout_product_methods'  => $express,
			'express_checkout_cart_methods'     => $express,
			'express_checkout_checkout_methods' => $express,
			'upe_enabled_payment_method_ids'    => array( 'card' ),
		);
		$values   = array_column( $settings, 'value', 'id' );
		$this->assertSame( $expected, $values );
		$this->assertSame( 'Only icon', $settings['payment_request_button_type']['options']['default'] );
		$this->assertSame( 'Clearpay', $settings['upe_enabled_payment_method_ids']['options']['afterpay_clearpay'] );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
		$this->calls = array();
		$this->assertSame( 200, $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => $values ) )->get_status() );
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Country branding uses the stored account after registration.
	 *
	 * @testWith [true, "Clearpay"]
	 *           [false, "Afterpay"]
	 * @param bool   $stored Whether there is a stored account.
	 * @param string $brand  Expected brand.
	 */
	public function test_gateway_country_labels( bool $stored, string $brand ): void {
		$this->boot_routes();
		$this->register_gateways();
		if ( ! $stored ) {
			wc_get_container()->get( WooPaymentsDatabaseCache::class )->delete( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		}
		$route   = self::ROUTE . '_afterpay_clearpay';
		$gateway = WC()->payment_gateways()->payment_gateways()['woocommerce_payments_afterpay_clearpay'];
		$this->assertSame( 'WooPayments (' . $brand . ')', $gateway->get_method_title() );
		$this->assertSame( 'Allow customers to pay over time with ' . $brand . '.', $gateway->get_method_description() );
		$this->assertSame( array(), $this->calls );
		$before = $this->stored_settings_bytes();
		$data   = $this->dispatch( 'GET', $route )->get_data();
		$this->assertSame( 'WooPayments (' . $brand . ')', $data['method_title'] );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
		$data = $this->dispatch(
			'PUT',
			$route,
			array(
				'enabled'  => true,
				'settings' => array( 'manual_capture' => 'yes' ),
			)
		)->get_data();
		$this->assertSame( 'WooPayments (' . $brand . ')', $data['method_title'] );
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
	}

	/**
	 * Invalid values and unavailable settings reject the entire write.
	 *
	 * @testWith [{"manual_capture":"maybe"}, "rest_setting_value_invalid"]
	 *           [{"manual_capture":"yes","payment_request_button_theme":"neon"}, "rest_setting_value_invalid"]
	 *           [{"account_statement_descriptor":"EXAMPLE"}, "woocommerce_rest_payment_gateway_setting_unsupported", "The customer bank statement can't be changed here. Change it in the WooPayments settings."]
	 *           [{"upe_enabled_payment_method_ids":["card","bitcoin"]}, "rest_setting_value_invalid"]
	 * @param array       $settings Posted gateway values.
	 * @param string      $code Expected REST error.
	 * @param string|null $message Expected gateway refusal message, when applicable.
	 */
	public function test_rejected_gateway_settings( array $settings, string $code, ?string $message = null ): void {
		$this->boot_routes();
		$this->register_gateways();
		$before   = $this->stored_settings_bytes();
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => $settings ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		if ( null !== $message ) {
			$this->assertSame( $message, $response->get_data()['message'] );
		}
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Core may refresh the country before the collection reaches the split gateway.
	 *
	 * @testWith ["expired", "Clearpay", "Afterpay"]
	 *           ["warm", "Clearpay", "Clearpay"]
	 *           ["missing", "Afterpay", "Afterpay"]
	 * @param string $state  Account cache state after registration.
	 * @param string $option Method option brand before the card response.
	 * @param string $brand   Split gateway brand after the card response.
	 */
	public function test_collection_labels_after_account_refresh( string $state, string $option, string $brand ): void {
		$this->boot_routes();
		$this->register_gateways();
		if ( 'missing' === $state ) {
			wc_get_container()->get( WooPaymentsDatabaseCache::class )->delete( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		} elseif ( 'warm' === $state ) {
			$cache            = wc_get_container()->get( WooPaymentsDatabaseCache::class );
			$entry            = $cache->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
			$entry['fetched'] = time();
			$cache->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $entry );
		}
		$gateways = array_column( $this->dispatch( 'GET', '/wc/v3/payment_gateways' )->get_data(), null, 'id' );
		$this->assertSame( $option, $gateways['woocommerce_payments']['settings']['upe_enabled_payment_method_ids']['options']['afterpay_clearpay'] ?? null );
		$this->assertSame( 'WooPayments (' . $brand . ')', $gateways['woocommerce_payments_afterpay_clearpay']['method_title'] );
		$this->assertSame( 'warm' === $state ? array() : array( 'GET accounts' ), $this->calls );
	}

	/**
	 * Give the account entry a stored token flag past its expiry, or remove it, with the platform answering the opposite.
	 *
	 * @param bool|null $stored_disabled Stored `ece_confirmation_tokens_disabled`, or null for no stored account.
	 */
	private function store_token_flag( ?bool $stored_disabled ): void {
		$this->account['ece_confirmation_tokens_disabled'] = false === $stored_disabled;
		$cache = wc_get_container()->get( WooPaymentsDatabaseCache::class );
		if ( null === $stored_disabled ) {
			$cache->delete( WooPaymentsDatabaseCache::ACCOUNT_KEY );
			return;
		}
		$entry = $cache->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		$entry['data']['ece_confirmation_tokens_disabled'] = $stored_disabled;
		$cache->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $entry );
	}

	/**
	 * Registering the gateways on an expired or missing account entry makes no account request.
	 *
	 * @testWith [false]
	 *           [true]
	 *           [null]
	 * @param bool|null $stored_disabled Stored `ece_confirmation_tokens_disabled`, or null for no stored account.
	 */
	public function test_gateway_registration_on_an_expired_account_makes_no_account_request( ?bool $stored_disabled ): void {
		$this->boot_routes();
		$this->store_token_flag( $stored_disabled );
		WC()->payment_gateways()->payment_gateways = array();
		$this->calls                               = array();
		WC()->payment_gateways()->init();
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * The token flag answers what is stored, as Amazon Pay's listing does, until an ordinary refresh brings the platform's flag.
	 *
	 * @testWith [false]
	 *           [true]
	 *           [null]
	 * @param bool|null $stored_disabled Stored `ece_confirmation_tokens_disabled`, or null for no stored account.
	 */
	public function test_token_flag_answers_the_stored_flag( ?bool $stored_disabled ): void {
		$this->boot_routes();
		$this->store_token_flag( $stored_disabled );
		$stored_enabled = false === $stored_disabled;
		$account        = wc_get_container()->get( WooPaymentsAccountService::class );
		$this->calls    = array();
		$this->assertSame( $stored_enabled, $account->is_ece_confirmation_tokens_enabled() );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
		$this->assertSame( $stored_enabled, isset( WC()->payment_gateways()->payment_gateways()['woocommerce_payments_amazon_pay'] ) );
		$this->assertSame( array(), $this->calls );

		$account->get_cached_account_data();
		$this->assertSame( array( 'GET accounts' ), $this->calls );
		$platform_enabled = empty( $this->account['ece_confirmation_tokens_disabled'] );
		$this->assertSame( $platform_enabled, $account->is_ece_confirmation_tokens_enabled() );
		$registered = wc_get_container()->get( PaymentMethodRegistry::class )->get_registered( $account );
		$this->assertSame( $platform_enabled, isset( $registered['amazon_pay'] ) );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
	}

	/** Checkout availability and icons can still refresh a stale account. */
	public function test_checkout_country_refresh(): void {
		$this->boot_routes();
		$this->account += array(
			'payments_enabled'     => true,
			'details_submitted'    => true,
			'test_publishable_key' => 'pk_test_example',
			'capabilities'         => array( 'afterpay_clearpay_payments' => 'active' ),
		);
		add_filter( 'wcpay_test_mode', '__return_true' );
		update_option( 'woocommerce_woocommerce_payments_afterpay_clearpay_settings', array( 'enabled' => 'yes' ) );
		$this->register_gateways();
		$gateway = WC()->payment_gateways()->payment_gateways()['woocommerce_payments_afterpay_clearpay'];
		$this->assertTrue( $gateway->is_available() );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
		$this->expire_account();
		$this->calls = array();
		$this->assertStringContainsString( 'afterpay-cashapp-logo.svg', $gateway->get_icon() );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
	}

	/** A disconnected store ignores the country left in its account cache. */
	public function test_disconnected_labels(): void {
		$this->boot_routes();
		$this->register_gateways();
		$this->connected = false;
		$gateway         = WC()->payment_gateways()->payment_gateways()['woocommerce_payments_afterpay_clearpay'];
		$this->assertSame( 'WooPayments (Afterpay)', $gateway->get_method_title() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Forced mode values are reported to the merchant and stay out of the row.
	 *
	 * @testWith [true, "test_mode", "Test mode can't be changed while WooPayments is in development mode."]
	 *           [false, "test_mode", "Test mode can't be turned off while you are using a test account."]
	 *           [true, "enable_logging", "Logging can't be turned off while WooPayments is in development mode."]
	 * @param bool   $dev Development mode, otherwise test-mode onboarding.
	 * @param string $key Locked gateway setting.
	 * @param string $message Expected refusal message.
	 */
	public function test_locked_modes( bool $dev, string $key, string $message ): void {
		$this->boot_routes( $dev );
		add_filter( 'wcpay_test_mode_onboarding', '__return_true' );
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'   => 'yes',
				'test_mode' => 'no',
			)
		);
		$this->register_gateways();
		$before = $this->stored_settings_bytes();
		$values = $this->dispatch( 'GET', self::ROUTE )->get_data()['settings'];
		$this->assertSame( 'yes', $values['test_mode']['value'] ?? null );
		$this->assertSame( $dev ? 'yes' : 'no', $values['enable_logging']['value'] );
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( $key => 'no' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_payment_gateway_setting_locked', $response->get_data()['code'] );
		$this->assertSame( $message, $response->get_data()['message'] );
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( 200, $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( $key => 'yes' ) ) )->get_status() );
		$this->assertSame( $before, $this->stored_settings_bytes() );
	}

	/** An ordinary gateway toggle cannot persist forced test mode or debug logging. */
	public function test_enable_preserves_stored_modes(): void {
		$this->boot_routes( true );
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'   => 'no',
				'test_mode' => 'no',
			)
		);
		$this->register_gateways();
		$this->dispatch( 'GET', self::ROUTE );
		$this->assertSame( 200, $this->dispatch( 'PUT', self::ROUTE, array( 'enabled' => true ) )->get_status() );
		$row = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		$this->assertSame( 'yes', $row['enabled'] );
		$this->assertSame( 'no', $row['test_mode'] );
		$this->assertArrayNotHasKey( 'enable_logging', $row );
	}

	/** Core preserves the service's manual capture write when it also enables the gateway. */
	public function test_enable_and_capture(): void {
		$this->boot_routes();
		update_option(
			WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
			array(
				'enabled'        => 'no',
				'manual_capture' => 'no',
			)
		);
		$this->register_gateways();
		$params = array(
			'enabled'  => true,
			'settings' => array( 'manual_capture' => 'yes' ),
		);
		$this->assertSame( 200, $this->dispatch( 'PUT', self::ROUTE, $params )->get_status() );
		$row = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		$this->assertSame( 'yes', $row['enabled'] );
		$this->assertSame( 'yes', $row['manual_capture'] );
	}

	/** A real extension-owned gateway keeps core's raw checkbox update and response. */
	public function test_extension_owned_gateway(): void {
		add_filter( 'option_active_plugins', static fn( $active ) => array_merge( $active, array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
		$this->boot_routes();
		// Exercise both callbacks even if another integration registered them on an extension-owned request.
		wc_get_container()->get( MerchantRestController::class )->register();
		$gateway                                   = new class() extends \WC_Payment_Gateway {
			/** Set up the extension's card gateway for core. */
			public function __construct() {
				$this->id = 'woocommerce_payments';
				$this->init_settings();
			}

			/** Core exposes the extension's own field definitions. */
			public function init_form_fields() {
				$this->form_fields = array(
					'manual_capture' => array(
						'title'   => 'Capture',
						'type'    => 'checkbox',
						'default' => 'no',
					),
				);
			}
		};
		WC()->payment_gateways()->payment_gateways = array( $gateway->id => $gateway );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->calls = array();
		$params      = array( 'settings' => array( 'manual_capture' => 'yes' ) );
		$response    = $this->dispatch( 'PUT', self::ROUTE, $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'manual_capture' ), array_keys( $response->get_data()['settings'] ) );
		$this->assertSame( 'yes', get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['manual_capture'] );
		$this->assertSame( array(), $this->calls );
	}

	/** The settings service prunes incompatible methods before core persists the final row. */
	public function test_capture_prunes_enabled_methods(): void {
		$this->boot_routes();
		$this->register_gateways();
		$params   = array(
			'manual_capture'                 => 'yes',
			'upe_enabled_payment_method_ids' => array( 'card', 'klarna' ),
		);
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => $params ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'card' ), $response->get_data()['settings']['upe_enabled_payment_method_ids']['value'] ?? null );
	}

	/** Known methods without account fees are refused without a settings write. */
	public function test_unavailable_method(): void {
		$this->boot_routes();
		$this->register_gateways();
		$before   = $this->stored_settings_bytes();
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( 'upe_enabled_payment_method_ids' => array( 'card', 'wechat_pay' ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( array( 'GET accounts' ), $this->calls );
	}

	/** Fresh fees decide which methods may be enabled. The service also synchronizes split gateways. */
	public function test_change_enabled_methods(): void {
		$this->boot_routes();
		$this->register_gateways();
		$methods = array( 'card', 'klarna' );
		$params  = array( 'upe_enabled_payment_method_ids' => $methods );
		$result  = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => $params ) );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 'GET accounts', $this->calls[0] );
		$this->assertCount( 1, array_keys( $this->calls, 'GET accounts', true ) );
		$this->assertSame( $methods, get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION )['upe_enabled_payment_method_ids'] ?? null );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_klarna_settings' )['enabled'] );
	}

	/** The screen save still returns its promotions and initializes a missing fraud ruleset. */
	public function test_screen_save_loads_screen_data(): void {
		$this->boot_routes();
		$this->register_gateways();
		$response = $this->dispatch( 'POST', '/wc/v3/payments/settings', array( 'is_manual_capture_enabled' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_manual_capture_enabled'] );
		$this->assertSame( 1, count( array_keys( $this->calls, 'GET accounts', true ) ) );
		$this->assertSame( 'GET accounts', $this->calls[0] );
		$this->assertContains( 'GET payment_method_promotions', $this->calls );
		$this->assertContains( 'GET fraud_ruleset', $this->calls );
		$this->assertContains( 'POST fraud_ruleset', $this->calls );
	}

	/** Read the actual persisted bytes, bypassing option filters and the object cache. */
	private function stored_settings_bytes(): string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION ) );
	}

	/**
	 * A denied write leaves persisted bytes and the platform untouched.
	 *
	 * @testWith [false, 401]
	 *           [true, 403]
	 * @param bool $logged_in Use a subscriber for the authenticated case.
	 * @param int  $status    Expected permission status.
	 */
	public function test_denied_write( bool $logged_in, int $status ): void {
		$this->boot_routes();
		$this->register_gateways();
		$this->assertArrayHasKey( 'woocommerce_payments', WC()->payment_gateways()->payment_gateways() );
		wp_set_current_user( $logged_in ? self::factory()->user->create( array( 'role' => 'subscriber' ) ) : 0 );
		$before   = $this->stored_settings_bytes();
		$response = $this->dispatch( 'PUT', self::ROUTE, array( 'settings' => array( 'manual_capture' => 'yes' ) ) );
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $before, $this->stored_settings_bytes() );
		$this->assertSame( array(), $this->calls );
	}
}
