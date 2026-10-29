<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AppearanceStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\StoreApiRequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\BlocksDataExtractor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Shared fixture for the WooPay session family tests: the account and tracking doubles and the WooPay services built
 * over them, the shopper surface reset between tests, and the Store API, signature, order and appearance helpers.
 */
trait WooPayServicesFixture {

	/**
	 * Original direct-checkout feature option.
	 *
	 * @var mixed
	 */
	private $original_direct_checkout_option;

	/**
	 * Original WooCommerce session object.
	 *
	 * @var object|null
	 */
	private $original_session;

	/**
	 * WooPay eligibility rules the last create_service() built.
	 *
	 * @var TestableWooPayEligibility|null
	 */
	private ?TestableWooPayEligibility $woopay_eligibility = null;

	/**
	 * WooPay checkout appearance store the last create_service() built.
	 *
	 * @var AppearanceStore|null
	 */
	private ?AppearanceStore $appearance_store = null;

	/**
	 * Handler of WooPay's Store API requests the last create_service() built.
	 *
	 * @var StoreApiRequestHandler|null
	 */
	private ?StoreApiRequestHandler $store_api_request_handler = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit fixture method, run through the test class.
		parent::setUp();
		$this->original_session                = WC()->session;
		$this->original_direct_checkout_option = get_option( '_wcpay_feature_woopay_direct_checkout', null );
		$this->reset_frontend_surface_state();
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( array $gateways ): array {
				$gateway = new class() extends \WC_Payment_Gateway {
					/**
					 * Build the available base-gateway fixture.
					 */
					public function __construct() {
						$this->id      = 'woocommerce_payments';
						$this->enabled = 'yes';
					}

					/**
					 * Process a fixture payment.
					 *
					 * @param int $order_id Order ID.
					 * @return array<string,mixed>
					 */
					public function process_payment( $order_id ) {
						unset( $order_id );

						return array();
					}
				};

				$gateways['woocommerce_payments'] = $gateway;

				return $gateways;
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit fixture method, run through the test class.
		$this->reset_container_replacements();
		WC()->session = $this->original_session;
		if ( null === $this->original_direct_checkout_option ) {
			delete_option( '_wcpay_feature_woopay_direct_checkout' );
		} else {
			update_option( '_wcpay_feature_woopay_direct_checkout', $this->original_direct_checkout_option );
		}
		wc_empty_cart();
		delete_option( 'wcpay_woopay_checkout_appearance' );
		delete_option( 'wcpay_styles_cache_version' );
		delete_option( 'woopay_enabled_adapted_extensions' );
		delete_option( 'wc_points_rewards_redeem_points_ratio' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		FakeWooPayPointsRewardsManager::$points = array();
		remove_theme_mod( 'custom_logo' );
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::delete_option(
				array(
					'blog_token',
					'id',
					'time_diff',
				)
			);
		}
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woocommerce_logger_log_message' );
		remove_all_filters( 'wcpay_dev_mode' );
		remove_all_filters( 'rest_pre_dispatch' );
		remove_all_filters( 'woocommerce_store_api_disable_nonce_check' );
		remove_all_filters( 'woocommerce_available_payment_gateways' );
		remove_all_filters( 'wcpay_woopay_enabled' );
		remove_all_filters( 'wcpay_woopay_button_is_product_supported' );
		remove_all_filters( 'wcpay_platform_checkout_button_are_cart_items_supported' );
		remove_all_filters( 'pre_option_woocommerce_enable_guest_checkout' );
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_CART_TOKEN'], $_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'], $_SERVER['HTTP_NONCE'], $_SERVER['HTTP_X_WOOPAY_ATTESTATION'], $_REQUEST['rest_route'] );
		remove_filter( 'woocommerce_gc_account_session_timeout_minutes', '__return_false' );
		remove_all_filters( 'wcpay_woopay_is_signed_with_blog_token' );
		wp_clear_scheduled_hook( 'woopay_restore_order_customer_id' );
		remove_all_filters( 'woocommerce_geolocate_ip' );
		delete_option( 'woocommerce_woocommerce_payments_woopay_available_countries' );
		$this->reset_real_blog_token_signed();
		$this->reset_frontend_surface_state();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'woocommerce_checkout_company_field' );
		delete_option( 'woocommerce_checkout_address_2_field' );
		delete_option( 'woocommerce_checkout_phone_field' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		wp_reset_postdata();
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Assert that no recorded WooPayments log line carries the session key, the shopper's email, the blog token or a WooPay URL.
	 *
	 * @param \ArrayObject $lines Recorded lines: level, message and context.
	 */
	private function assert_log_lines_leak_nothing( \ArrayObject $lines ): void {
		$this->assertNotSame( array(), $lines->getArrayCopy() );
		$logged = (string) wp_json_encode( $lines->getArrayCopy(), JSON_UNESCAPED_SLASHES );
		foreach ( array( 'session-key-abc123', 'platform_checkout_key', 'shopper@example.com', 'blog-token', 'pay.woo.com' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $logged );
		}
	}

	/**
	 * Answer every outgoing request with a WooPay HTTP response, in WP_Http::request()'s array shape.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private function answer_woopay_requests_with( int $status, string $body ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => $status,
					'message' => get_status_header_desc( $status ),
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);
	}

	/**
	 * Connect the store blog so the Jetpack-signed WooPay init request can be built.
	 */
	private function connect_woopay_blog(): void {
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::update_option( 'id', 12345 );
			\Jetpack_Options::update_option( 'blog_token', 'token-key.blog-token' );
			\Jetpack_Options::update_option( 'time_diff', 0 );
		}
	}

	/**
	 * Record the level, message and context of every line written under the WooPayments log source until the test ends,
	 * without the source and the per-request context the logger adds while logging is on (pinned in WooPaymentsLoggerTest).
	 *
	 * WC_Logger applies the message filter once per log handler, so a line can be recorded more than once.
	 *
	 * @return \ArrayObject<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private function record_woopayments_log_lines(): \ArrayObject {
		$lines = new \ArrayObject();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context ) use ( $lines ) {
				if ( 'woopayments' === ( $context['source'] ?? '' ) ) {
					unset( $context['source'], $context['WP_USER'], $context['HTTP_USER_AGENT'], $context['REQUEST_URI'], $context['DOING_AJAX'], $context['DOING_CRON'], $context['WP_CLI'], $context['WOOPAYMENTS_MODE'] );
					$lines[] = array( (string) $level, (string) $message, $context );
				}

				return $message;
			},
			10,
			3
		);

		return $lines;
	}

	/**
	 * Build a customer-service mock recording the get-or-create call.
	 *
	 * @param string   $customer_id       Customer id to return.
	 * @param int|null $captured_user_id  Captures the user id the service was asked about.
	 * @return WooPaymentsCustomerService
	 */
	private function create_recording_customer_service( string $customer_id, ?int &$captured_user_id ): WooPaymentsCustomerService {
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_user' )->willReturnCallback(
			function ( int $user_id ) use ( $customer_id, &$captured_user_id ): string {
				$captured_user_id = $user_id;

				return $customer_id;
			}
		);

		return $customer_service;
	}

	/**
	 * Simulate an inbound WooPay Store API request.
	 */
	private function simulate_woopay_store_api_request(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/checkout';
	}

	/**
	 * Insert a Store API session row the cart-token resolution can read.
	 *
	 * @param string               $session_key Session key (user id or guest hash).
	 * @param array<string,string> $customer    Customer session payload.
	 */
	private function insert_store_api_session( string $session_key, array $customer ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $session_key,
				'session_value'  => maybe_serialize( array( 'customer' => maybe_serialize( $customer ) ) ),
				'session_expiry' => time() + HOUR_IN_SECONDS,
			)
		);
	}

	/**
	 * Force the Jetpack blog-token signed check to report true.
	 *
	 * Rest_Authentication::is_signed_with_blog_token() reads a private singleton
	 * state that is false in tests; this drives it to a signed blog-token result.
	 */
	private function force_real_blog_token_signed(): void {
		if ( ! class_exists( \Automattic\Jetpack\Connection\Rest_Authentication::class ) ) {
			$this->markTestSkipped( 'Jetpack Rest_Authentication is unavailable.' );
		}

		$instance   = \Automattic\Jetpack\Connection\Rest_Authentication::init();
		$reflection = new \ReflectionClass( \Automattic\Jetpack\Connection\Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, true );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, 'blog' );
	}

	/**
	 * Reset the Jetpack blog-token signed check state forced during a test.
	 */
	private function reset_real_blog_token_signed(): void {
		if ( ! class_exists( \Automattic\Jetpack\Connection\Rest_Authentication::class ) ) {
			return;
		}

		$instance   = \Automattic\Jetpack\Connection\Rest_Authentication::init();
		$reflection = new \ReflectionClass( \Automattic\Jetpack\Connection\Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, null );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, null );
	}

	/**
	 * Create an encrypted identity envelope using the WooPayments Client 11.1.0 contract.
	 *
	 * @param string $json Identity JSON.
	 * @return array<string,string>
	 */
	private function create_encrypted_identity_envelope( string $json ): array {
		$blog_token = 'blog-token';
		$iv         = str_repeat( 'i', 16 );
		$data       = openssl_encrypt( $json, 'aes-256-cbc', $blog_token, OPENSSL_RAW_DATA, $iv );
		if ( false === $data ) {
			throw new \RuntimeException( 'Could not encrypt the WooPay identity fixture.' );
		}

		\Jetpack_Options::update_option( 'id', 12345 );
		\Jetpack_Options::update_option( 'blog_token', $blog_token );

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the WooPay identity fixture contract.
			'data' => base64_encode( $data ),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the WooPay identity fixture contract.
			'iv'   => base64_encode( $iv ),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the WooPay identity fixture contract.
			'hash' => base64_encode( hash_hmac( 'sha256', $iv . $data, $blog_token ) ),
		);
	}

	/**
	 * Open the store session returned by the service the way WooPay first tries to: the key HKDF derives from the blog
	 * token for woopay-session-v1, the HMAC over the IV and the ciphertext checked first (client 11.2.0
	 * tests/unit/woopay/test-class-woopay-utilities.php:297-349).
	 *
	 * @param array<string,mixed> $encrypted_session Encrypted store session.
	 * @return array<string,mixed>
	 */
	private function decrypt_store_session( array $encrypted_session ): array {
		$this->assertSame( '12345', $encrypted_session['blog_id'] );
		$this->assertIsArray( $encrypted_session['data'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the encrypted store-session fixture.
		$data = base64_decode( $encrypted_session['data']['session'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the encrypted store-session fixture.
		$iv = base64_decode( $encrypted_session['data']['iv'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the encrypted store-session fixture.
		$hash = base64_decode( $encrypted_session['data']['hash'], true );
		if ( false === $data || false === $iv || false === $hash ) {
			throw new \RuntimeException( 'Could not decode the encrypted store-session fixture.' );
		}

		$session_key = hash_hkdf( 'sha256', 'blog-token', 32, 'woopay-session-v1' );
		$this->assertSame( hash_hmac( 'sha256', $iv . $data, $session_key ), $hash, 'The HMAC covers the IV and the ciphertext, under the session key.' );

		$json = openssl_decrypt( $data, 'aes-256-cbc', $session_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $json ) {
			throw new \RuntimeException( 'Could not decrypt the store-session fixture.' );
		}
		$session = json_decode( $json, true );
		$this->assertIsArray( $session );

		return $session;
	}

	/**
	 * Place an order through the classic checkout's WC_Checkout::create_order(), as a checkout request does.
	 *
	 * @return int|\WP_Error The order ID.
	 */
	private function create_classic_checkout_order() {
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			return WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}
	}

	/**
	 * Assert that another request can claim the order payment lock, then release it.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $message Failure message.
	 */
	private function assert_order_payment_lock_is_free( \WC_Order $order, string $message ): void {
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $order, $vocabulary, 'attempt-key', 'checkout' );
		$this->assertIsString( $token, $message );
		$store->release( $order, $vocabulary, $token );
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param array<string,mixed>             $settings           Gateway settings.
	 * @param array<string,mixed>             $account_data       Account data.
	 * @param AdaptedExtensions|null          $adapted_extensions Adapted extensions registry.
	 * @param callable(string):void|null      $event_recorder     Optional account-service event recorder.
	 * @param WooPaymentsCustomerService|null $customer_service   Optional customer service.
	 * @param bool                            $test_mode          Whether the account reports test mode.
	 * @return SessionService
	 */
	private function create_service( array $settings = array(), array $account_data = array(), ?AdaptedExtensions $adapted_extensions = null, ?callable $event_recorder = null, ?WooPaymentsCustomerService $customer_service = null, bool $test_mode = true ): SessionService {
		$settings = array_merge(
			array(
				'enabled'                              => 'yes',
				'platform_checkout'                    => 'yes',
				'manual_capture'                       => 'no',
				'payment_request_button_type'          => 'default',
				'payment_request_button_theme'         => 'dark',
				'payment_request_button_size'          => 'medium',
				'payment_request_button_height'        => '44',
				'payment_request_button_border_radius' => '',
				'express_checkout_product_methods'     => array( 'woopay' ),
				'express_checkout_cart_methods'        => array( 'woopay' ),
				'express_checkout_checkout_methods'    => array( 'woopay' ),
			),
			$settings
		);
		// The cached account is the platform's GET accounts answer, compose_basic_account_info() in the WooPayments server
		// (endpoints/wcpay/class-accounts-controller.php:1074; account_id :1111, details_submitted :1122, capabilities :1125,
		// country :1158, platform_checkout_eligible :1162, platform_direct_checkout_eligible :1163, pre_check_save_my_info
		// :1189), read by client 11.1.0 at class-wc-payments-account.php:286, :291-292, :2733,
		// class-wc-payments-features.php:207, :239 and class-woopay-save-user.php:76. The platform keeps a store reporting a
		// client below 11.2.0 on WooPay's signed path, so the account carries platform_woopay_force_signed_requests true, which
		// client 11.2.0 reads from the stored account (class-wc-payments-features.php:226-233).
		$account_data = array_merge(
			array(
				'account_id'                            => 'acct_123',
				'details_submitted'                     => true,
				'capabilities'                          => array( 'card_payments' => 'active' ),
				'country'                               => 'US',
				'pre_check_save_my_info'                => false,
				'platform_checkout_eligible'            => true,
				'platform_woopay_force_signed_requests' => true,
			),
			$account_data
		);
		$account_data = array_filter( $account_data, static fn( $value ): bool => null !== $value );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'get_stored_account_data', 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'get_stored_account_data' )->willReturn( $account_data );

		$account_service->method( 'get_account_id' )->willReturnCallback(
			static function () use ( $account_data ): string {
				$account_id = $account_data['account_id'] ?? '';

				return is_scalar( $account_id ) ? (string) $account_id : '';
			}
		);
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( $account_data, $event_recorder ): array {
				if ( null !== $event_recorder ) {
					$event_recorder( 'account' );
				}

				return $account_data;
			}
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( $settings, $event_recorder ) {
				if ( null !== $event_recorder && 0 === strpos( $key, 'express_checkout_' ) ) {
					$event_recorder( 'location' );
				}

				return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
			}
		);

		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );

		$this->woopay_eligibility = new TestableWooPayEligibility();
		$this->woopay_eligibility->init( $account_service );

		$this->appearance_store = new AppearanceStore();
		$this->appearance_store->init( new WooPaymentsFrontendStylesService() );

		$adapted_extensions = $adapted_extensions ?? new AdaptedExtensions();

		$sut = new SessionService();
		$sut->init( $account_service, $this->woopay_eligibility, $this->appearance_store, $tracking_controller, wc_get_container()->get( BlocksDataExtractor::class ), $adapted_extensions, $customer_service ?? $this->create_customer_service_without_platform_customer(), wc_get_container()->get( WooPaymentsLogger::class ) );

		$this->store_api_request_handler = new StoreApiRequestHandler();
		$this->store_api_request_handler->init( $this->woopay_eligibility, $adapted_extensions, wc_get_container()->get( WooPaymentsLogger::class ), $sut );

		return $sut;
	}

	/**
	 * Create the WooPay appearance store under test, as create_service() builds it.
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Account data.
	 * @return AppearanceStore
	 */
	private function create_appearance_store( array $settings = array(), array $account_data = array() ): AppearanceStore {
		$this->create_service( $settings, $account_data );

		return $this->appearance_store;
	}

	/**
	 * Create the handler of WooPay's Store API requests under test, as create_service() builds it.
	 *
	 * @param array<string,mixed>    $settings           Gateway settings.
	 * @param array<string,mixed>    $account_data       Account data.
	 * @param AdaptedExtensions|null $adapted_extensions Adapted extensions registry.
	 * @return StoreApiRequestHandler
	 */
	private function create_handler( array $settings = array(), array $account_data = array(), ?AdaptedExtensions $adapted_extensions = null ): StoreApiRequestHandler {
		$this->create_service( $settings, $account_data, $adapted_extensions );

		return $this->store_api_request_handler;
	}

	/**
	 * Create the WooPay eligibility rules under test, over the same account double create_service() builds.
	 *
	 * @param array<string,mixed>             $settings           Gateway settings.
	 * @param array<string,mixed>             $account_data       Account data.
	 * @param AdaptedExtensions|null          $adapted_extensions Adapted extensions registry.
	 * @param callable(string):void|null      $event_recorder     Optional account-service event recorder.
	 * @param WooPaymentsCustomerService|null $customer_service   Optional customer service.
	 * @param bool                            $test_mode          Whether the account reports test mode.
	 * @return TestableWooPayEligibility
	 */
	private function create_eligibility( array $settings = array(), array $account_data = array(), ?AdaptedExtensions $adapted_extensions = null, ?callable $event_recorder = null, ?WooPaymentsCustomerService $customer_service = null, bool $test_mode = true ): TestableWooPayEligibility {
		$this->create_service( $settings, $account_data, $adapted_extensions, $event_recorder, $customer_service, $test_mode );

		return $this->woopay_eligibility;
	}

	/**
	 * Register the WooPay session controller's hooks for the given service, as the native bootstrap does.
	 *
	 * @param SessionService $service Session service.
	 * @return SessionController
	 */
	private function register_controller( SessionService $service ): SessionController {
		$controller = new SessionController();
		$controller->init( $service, $this->woopay_eligibility, $this->appearance_store, $this->store_api_request_handler );
		$controller->register();

		return $controller;
	}

	/**
	 * Create a persisted product for WooPay eligibility tests.
	 *
	 * @param string $class_name Product class name.
	 * @phpstan-param class-string<\WC_Product> $class_name
	 * @return \WC_Product
	 */
	private function create_woopay_product( string $class_name = \WC_Product_Simple::class ): \WC_Product {
		$product = new $class_name();
		$product->set_name( 'WooPay eligibility product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_stock_status( 'instock' );
		$product->save();

		return $product;
	}

	/**
	 * Set the current product used by the product-button eligibility checks.
	 *
	 * @param \WC_Product $product Product to expose globally.
	 */
	private function set_current_woopay_product( \WC_Product $product ): void {
		$GLOBALS['product'] = $product;
	}

	/**
	 * Set the current request to a product_page shortcode page.
	 *
	 * @param string $content Shortcode page content.
	 */
	private function set_current_woopay_shortcode_page( string $content ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		unset( $GLOBALS['product'] );
	}

	/**
	 * Register fake Mailchimp blocks integration class.
	 */
	private function register_fake_mailchimp_blocks_integration(): void {
		if ( ! class_exists( '\Mailchimp_Woocommerce_Newsletter_Blocks_Integration', false ) ) {
			class_alias( FakeWooPayMailchimpBlocksIntegration::class, 'Mailchimp_Woocommerce_Newsletter_Blocks_Integration' );
		}
	}

	/**
	 * Get a valid WooPay appearance payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_valid_appearance(): array {
		return array(
			'theme'     => 'stripe',
			'variables' => array(
				'colorText' => '#111111',
			),
			'rules'     => array(
				'.Input' => array(
					'borderRadius' => '4px',
				),
			),
		);
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return object
	 */
	private function create_session(): object {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}

	/**
	 * Create a customer service that answers no platform customer, for tests that do not look at it.
	 *
	 * @return WooPaymentsCustomerService
	 */
	private function create_customer_service_without_platform_customer(): WooPaymentsCustomerService {
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_user' )->willReturn( '' );

		return $customer_service;
	}
}
