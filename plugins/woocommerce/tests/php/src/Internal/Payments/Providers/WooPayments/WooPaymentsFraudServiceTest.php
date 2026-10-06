<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the WooPaymentsFraudService class.
 */
class WooPaymentsFraudServiceTest extends WC_Unit_Test_Case {

	/**
	 * Captured URLs of intercepted public fraud-services config requests.
	 *
	 * @var string[]
	 */
	private array $intercepted_request_urls = array();

	/**
	 * Session handler before the test replaced it.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Request URI before the test.
	 *
	 * @var mixed
	 */
	private $original_request_uri;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_session     = WC()->session;
		$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved verbatim to restore it after the test.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->intercepted_request_urls = array();
		WC()->session                   = $this->original_session;
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_session_store_id' );
		delete_transient( 'woocommerce_woopayments_public_fraud_services' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woocommerce_woopayments_fraud_services_config' );
		remove_all_filters( 'woocommerce_woopayments_fraud_service_config' );
		wp_set_current_user( 0 );
		unset( $_GET['page'], $_GET['tab'], $_GET['path'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Leave the admin screen the test set.
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Should print the Sift page tracker in the footer of a WooCommerce admin page, as the client does.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-fraud-service.php:83`, `:207-243`; the merchant's account id is the Sift user
	 * id in the admin (`:348-358`).
	 */
	public function test_prints_the_sift_tracker_on_a_woocommerce_admin_page(): void {
		$output = $this->render_admin_footer( array( 'page' => 'wc-admin' ) );

		$this->assertStringContainsString( "var src = 'https://cdn.sift.com/s.js';", $output );
		$this->assertStringContainsString( '_sift.push( [ \'_setAccount\', "prod_beacon" ] );', $output );
		$this->assertStringContainsString( '_sift.push( [ \'_setUserId\', "acct_fraud_service_test" ] );', $output );
		$this->assertStringContainsString( '_setSessionId', $output );
		$this->assertStringContainsString( "_sift.push( [ '_trackPageview' ] );", $output );
		$this->assertStringContainsString( 'if ( ! document.querySelector( \'[src="\' + src + \'"]\' ) ) {', $output );
	}

	/**
	 * @testdox Should print the Sift page tracker on the Payments settings pages, the WooPayments routes included.
	 *
	 * The client's dashboard pages load Sift through their own JS (client 11.1.0 `client/components/page/index.tsx:36-40`,
	 * `client/fraud-scripts/sift.js`), so the client covers them too; native has no JS loader and prints the tracker there.
	 * @testWith [{"page": "wc-settings", "tab": "checkout"}]
	 *           [{"page": "wc-settings", "tab": "checkout", "path": "/woopayments/overview"}]
	 *
	 * @param array<string,string> $query Admin page query.
	 */
	public function test_prints_the_sift_tracker_on_the_payments_settings_pages( array $query ): void {
		$output = $this->render_admin_footer( $query );

		$this->assertStringContainsString( '_sift.push( [ \'_setAccount\', "prod_beacon" ] );', $output );
	}

	/**
	 * @testdox Should print the filtered Sift values as JSON strings and load the Sift script once.
	 */
	public function test_prints_the_filtered_sift_values_and_loads_the_script(): void {
		add_filter(
			'woocommerce_woopayments_fraud_service_config',
			static function ( $config ) {
				$config['beacon_key'] = '</script><script>alert("x")</script>';
				$config['session_id'] = 'sess_known';
				return $config;
			}
		);

		$output = $this->render_admin_footer( array( 'page' => 'wc-admin' ) );

		$this->assertSame( 1, substr_count( $output, '</script>' ), 'A filtered value cannot close the script tag.' );
		$this->assertStringContainsString( '_sift.push( [ \'_setAccount\', "<\/script><script>alert(\"x\")<\/script>" ] );', $output );
		$this->assertStringContainsString( '_sift.push( [ \'_setSessionId\', "sess_known" ] );', $output );
		$this->assertStringContainsString( 'script.async = true;', $output );
		$this->assertStringContainsString( 'document.body.appendChild( script );', $output );
	}

	/**
	 * @testdox Should print no Sift tracker when a filter returns a field that is not a string.
	 * @testWith ["beacon_key"]
	 *           ["user_id"]
	 *           ["session_id"]
	 *
	 * @param string $field The filtered field.
	 */
	public function test_prints_no_sift_tracker_for_a_malformed_filtered_field( string $field ): void {
		add_filter(
			'woocommerce_woopayments_fraud_service_config',
			static function ( $config ) use ( $field ) {
				$config[ $field ] = new \stdClass();
				return $config;
			}
		);

		$this->assertSame( '', $this->render_admin_footer( array( 'page' => 'wc-admin' ) ) );
	}

	/**
	 * @testdox Should not print the Sift tracker on other admin pages, without Sift, or without a beacon key.
	 * @dataProvider provide_pages_without_the_admin_sift_tracker
	 *
	 * @param array<string,string> $query          Admin page query.
	 * @param array<string,mixed>  $fraud_services Account fraud services.
	 * @param string               $test_mode      The test_mode gateway setting.
	 */
	public function test_does_not_print_the_sift_tracker( array $query, array $fraud_services, string $test_mode ): void {
		$this->assertSame( '', $this->render_admin_footer( $query, $fraud_services, $test_mode ) );
	}

	/**
	 * Pages and configs without the admin Sift tracker.
	 *
	 * @return array<string,array{array<string,string>,array<string,mixed>,string}>
	 */
	public static function provide_pages_without_the_admin_sift_tracker(): array {
		$sift = array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) );

		return array(
			'not a WooCommerce page'                 => array( array(), $sift, 'no' ),
			'Sift not configured'                    => array( array( 'page' => 'wc-admin' ), array( 'stripe' => array() ), 'no' ),
			'test mode without a sandbox beacon key' => array( array( 'page' => 'wc-admin' ), $sift, 'yes' ),
		);
	}

	/**
	 * Render the admin footer scripts as the fraud service prints them.
	 *
	 * @param array<string,string> $query          Admin page query.
	 * @param array<string,mixed>  $fraud_services Account fraud services.
	 * @param string               $test_mode      The test_mode gateway setting.
	 * @return string
	 */
	private function render_admin_footer( array $query, array $fraud_services = array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ), string $test_mode = 'no' ): string {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => $test_mode ) );
		$this->seed_account_fraud_services( $fraud_services );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		if ( 'wc-settings' === ( $query['page'] ?? '' ) ) {
			// WooCommerce settings pages are connected admin pages, which the test request has not registered.
			add_filter( 'woocommerce_navigation_is_connected_page', '__return_true' );
		}
		$sut = $this->make_sut();
		$sut->register();
		$this->assertSame( 10, has_action( 'admin_print_footer_scripts', array( $sut, 'handle_admin_print_footer_scripts' ) ) );

		// Only this callback's output: other footer scripts (core's Tracks, for one) may be hooked by earlier tests.
		ob_start();
		$sut->handle_admin_print_footer_scripts();

		return trim( (string) ob_get_clean() );
	}

	/**
	 * @testdox Should swap in the sandbox beacon key and drop it from the sift config when test mode is enabled.
	 */
	public function test_prepares_sift_config_in_test_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services(
			array(
				'sift' => array(
					'beacon_key'         => 'prod_beacon',
					'sandbox_beacon_key' => 'sandbox_beacon',
				),
			)
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertArrayHasKey( 'sift', $config );
		$this->assertSame( 'sandbox_beacon', $config['sift']['beacon_key'] );
		$this->assertArrayNotHasKey( 'sandbox_beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should keep the production beacon key and drop the sandbox key when test mode is disabled.
	 */
	public function test_prepares_sift_config_in_live_mode(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'no' ) );
		$this->seed_account_fraud_services(
			array(
				'sift' => array(
					'beacon_key'         => 'prod_beacon',
					'sandbox_beacon_key' => 'sandbox_beacon',
				),
			)
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( 'prod_beacon', $config['sift']['beacon_key'] );
		$this->assertArrayNotHasKey( 'sandbox_beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should ship no beacon key at all in test mode when the platform sent no sandbox key.
	 */
	public function test_drops_beacon_key_in_test_mode_without_sandbox_key(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services(
			array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) )
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertArrayNotHasKey( 'beacon_key', $config['sift'] );
	}

	/**
	 * @testdox Should inject an empty sift user_id and a session_id entry for logged-out shoppers.
	 */
	public function test_injects_sift_identity_keys_for_logged_out_shopper(): void {
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( '', $config['sift']['user_id'] );
		$this->assertArrayHasKey( 'session_id', $config['sift'] );
	}

	/**
	 * @testdox Should use the shopper's WooPayments customer ID as the sift user_id on the front end.
	 */
	public function test_sift_user_id_is_customer_id_for_logged_in_shopper(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_sift_shopper' );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( 'cus_sift_shopper', $config['sift']['user_id'] );
	}

	/**
	 * @testdox Should derive the sift session_id from the persisted store ID and the WooCommerce session customer.
	 */
	public function test_sift_session_id_derives_from_persisted_store_id(): void {
		update_option( 'wcpay_session_store_id', 'store_abc' );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		WC()->initialize_session();

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame(
			'store_abc_' . (string) WC()->session->get_customer_id(),
			$config['sift']['session_id']
		);
	}

	/**
	 * @testdox Should fall back to the platform's public fraud-services config when the account payload has none, and cache it.
	 */
	public function test_falls_back_to_cached_public_config(): void {
		$this->intercept_public_config_request(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'sift' => array( 'beacon_key' => 'public_beacon' ) ) ),
			)
		);

		$sut    = $this->make_sut();
		$config = $sut->get_fraud_services_config();

		$this->assertSame( 'public_beacon', $config['sift']['beacon_key'] );
		$this->assertCount( 1, $this->intercepted_request_urls );
		$this->assertStringContainsString( 'wcpay/accounts/fraud_services', $this->intercepted_request_urls[0] );

		// A second read must come from the cache, not a second platform request.
		$sut->get_fraud_services_config();
		$this->assertCount( 1, $this->intercepted_request_urls );
	}

	/**
	 * @testdox Should default to a bare stripe service entry when neither account nor public config is available.
	 */
	public function test_defaults_to_stripe_service_when_nothing_available(): void {
		$this->intercept_public_config_request( new WP_Error( 'http_request_failed', 'no route to platform' ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array( 'stripe' => array() ), $config );
	}

	/**
	 * @testdox Should respect an explicitly empty account fraud-services list without falling back.
	 */
	public function test_respects_explicitly_empty_account_config(): void {
		$this->seed_account_fraud_services( array() );
		$this->intercept_public_config_request( new WP_Error( 'http_request_failed', 'must not be called' ) );

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array(), $config );
		$this->assertCount( 0, $this->intercepted_request_urls );
	}

	/**
	 * @testdox Should expose the whole prepared config through the fraud-services filter.
	 */
	public function test_whole_config_filter_applies_to_prepared_config(): void {
		$this->seed_account_fraud_services( array( 'stripe' => array() ) );

		add_filter(
			'woocommerce_woopayments_fraud_services_config',
			static function ( array $config ): array {
				$config['sift'] = array( 'beacon_key' => 'beacon_test' );
				return $config;
			}
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array(), $config['stripe'] );
		$this->assertSame( array( 'beacon_key' => 'beacon_test' ), $config['sift'] );
	}

	/**
	 * @testdox Should let the per-service filter disable a single service by returning null.
	 */
	public function test_per_service_filter_can_disable_a_service(): void {
		$this->seed_account_fraud_services(
			array(
				'stripe' => array(),
				'sift'   => array( 'beacon_key' => 'prod_beacon' ),
			)
		);

		add_filter(
			'woocommerce_woopayments_fraud_service_config',
			function ( $config, string $service_id ) {
				return 'sift' === $service_id ? null : $config;
			},
			10,
			2
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertNull( $config['sift'] );
		$this->assertSame( array(), $config['stripe'] );
	}

	/**
	 * @testdox Should apply a callback on the deprecated wcpay_prepare_fraud_config filter and emit a deprecation notice.
	 */
	public function test_legacy_per_service_filter_changes_config_with_deprecation_notice(): void {
		$this->setExpectedDeprecated( 'wcpay_prepare_fraud_config' );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		add_filter(
			'wcpay_prepare_fraud_config',
			static function ( $config, string $service_id ) {
				$config['legacy_service_id'] = $service_id;
				return $config;
			},
			10,
			2
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( 'sift', $config['sift']['legacy_service_id'], 'The legacy filter result must reach the served config.' );
		$this->assertSame( 'prod_beacon', $config['sift']['beacon_key'], 'The legacy filter must receive the prepared config.' );
	}

	/**
	 * @testdox Should fire the deprecated filter before the native filter, and pass its result to the native filter.
	 */
	public function test_legacy_per_service_filter_fires_before_native_filter(): void {
		$this->setExpectedDeprecated( 'wcpay_prepare_fraud_config' );
		$this->seed_account_fraud_services( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$calls = array();
		add_filter(
			'wcpay_prepare_fraud_config',
			static function ( $config ) use ( &$calls ) {
				$calls[]         = 'legacy';
				$config['order'] = array( 'legacy' );
				return $config;
			}
		);
		add_filter(
			'woocommerce_woopayments_fraud_service_config',
			static function ( $config ) use ( &$calls ) {
				$calls[]           = 'native';
				$config['order'][] = 'native';
				return $config;
			}
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array( 'legacy', 'native' ), $calls, 'The deprecated filter must fire first.' );
		$this->assertSame( array( 'legacy', 'native' ), $config['sift']['order'], 'The native filter must receive the deprecated filter result.' );
	}

	/**
	 * @testdox Should fall back to the unfiltered config when a deprecated filter callback returns an invalid value.
	 * @testWith ["not-an-array"]
	 *           [42]
	 *           [false]
	 *
	 * @param mixed $invalid Invalid value returned by the legacy callback.
	 */
	public function test_legacy_per_service_filter_invalid_return_falls_back( $invalid ): void {
		$this->setExpectedDeprecated( 'wcpay_prepare_fraud_config' );
		$this->seed_account_fraud_services( array( 'stripe' => array( 'publishable' => 'pk_test' ) ) );

		add_filter(
			'wcpay_prepare_fraud_config',
			static function () use ( $invalid ) {
				return $invalid;
			}
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertSame( array( 'stripe' => array( 'publishable' => 'pk_test' ) ), $config );
	}

	/**
	 * @testdox Should let a deprecated filter callback disable a service by returning null, as the plugin documented.
	 */
	public function test_legacy_per_service_filter_can_disable_a_service(): void {
		$this->setExpectedDeprecated( 'wcpay_prepare_fraud_config' );
		$this->seed_account_fraud_services(
			array(
				'stripe' => array(),
				'sift'   => array( 'beacon_key' => 'prod_beacon' ),
			)
		);

		add_filter(
			'wcpay_prepare_fraud_config',
			static function ( $config, string $service_id ) {
				return 'sift' === $service_id ? null : $config;
			},
			10,
			2
		);

		$config = $this->make_sut()->get_fraud_services_config();

		$this->assertArrayHasKey( 'sift', $config );
		$this->assertNull( $config['sift'] );
		$this->assertSame( array(), $config['stripe'] );
	}

	/**
	 * @testdox On the first request after a login with Sift enabled, the pre-login session is linked to the shopper's customer with the client's request.
	 *
	 * Client 11.1.0 link_session_if_user_just_logged_in() (class-wc-payments-fraud-service.php:150-197) sends
	 * link_session_to_customer( get_sift_session_id(), customer ) (class-wc-payments-session-service.php:71-94), which POSTs
	 * `tracking/link-session` with `session` and `customer` (api-client:1926-1935). Right after a login the Sift session ID is
	 * the one derived from the pre-login cookie customer (session-service:46-64, 103-121).
	 */
	public function test_links_pre_login_session_to_customer_after_login(): void {
		$http_client = $this->arrange_just_logged_in_shopper( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );

		$this->make_link_sut( $http_client )->link_session_if_user_just_logged_in();

		$this->assertSame( 1, $http_client->request_count );
		$this->assertSame( 'POST', $http_client->last_method );
		$this->assertSame( '/sites/123/wcpay/tracking/link-session', $http_client->last_path );
		$body = json_decode( (string) $http_client->last_body, true );
		$this->assertSame( 'store_abc_t_guest_before_login', $body['session'] );
		$this->assertSame( 'cus_link_shopper', $body['customer'] );
		$this->assertTrue( $body['test_mode'] );
	}

	/**
	 * @testdox No session link is requested when $_dataName.
	 *
	 * Client 11.1.0 class-wc-payments-fraud-service.php:151-188 returns before the request in each of these cases.
	 *
	 * @dataProvider session_link_skipped_provider
	 *
	 * @param string $condition Condition that must skip the link.
	 */
	public function test_does_not_link_session_when_a_condition_fails( string $condition ): void {
		$http_client = $this->arrange_just_logged_in_shopper( 'sift_off' === $condition ? array( 'stripe' => array() ) : array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );
		switch ( $condition ) {
			case 'no_login':
				WC()->session = new FraudServiceLoggedInSessionHandler( (string) get_current_user_id() );
				break;
			case 'no_customer':
				delete_user_option( get_current_user_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION );
				break;
			case 'ajax':
				add_filter( 'wp_doing_ajax', '__return_true' );
				break;
			case 'rest':
				$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wc/store/v1/cart';
				break;
			case 'not_connected':
				$http_client->blog_id = null;
				break;
		}

		// With debug logging on, a link refused later by the API client would still leave a tracking error line.
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'test_mode'      => 'yes',
				'enable_logging' => 'yes',
			)
		);
		$logger = RecordingWcLogger::install();

		$this->make_link_sut( $http_client )->link_session_if_user_just_logged_in();

		$this->assertSame( 0, $http_client->request_count );
		$this->assertSame( array(), array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], '[Tracking]' ) ) );
	}

	/**
	 * Conditions under which the client skips the session link.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function session_link_skipped_provider(): array {
		return array(
			'Sift is not enabled for the account' => array( 'sift_off' ),
			'the user did not just log in'        => array( 'no_login' ),
			'the user has no customer'            => array( 'no_customer' ),
			'the request is AJAX'                 => array( 'ajax' ),
			'the request is a REST request'       => array( 'rest' ),
			'the store is not connected'          => array( 'not_connected' ),
		);
	}

	/**
	 * @testdox A platform error while linking the session does not break the request and is logged only with debug logging on: $logging.
	 *
	 * Client 11.1.0 fraud-service:195 writes it through `Logger::log()`: info level, source `woopayments`, and only
	 * in dev mode or with the `enable_logging` setting on (`src/Internal/Logger.php:22,64-91`).
	 *
	 * @dataProvider debug_logging_states
	 *
	 * @param string $logging  Gateway `enable_logging` setting.
	 * @param bool   $expected Whether the line is written.
	 */
	public function test_logs_session_link_api_error_only_with_debug_logging( string $logging, bool $expected ): void {
		$http_client = $this->arrange_just_logged_in_shopper( array( 'sift' => array( 'beacon_key' => 'prod_beacon' ) ) );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'test_mode'      => 'yes',
				'enable_logging' => $logging,
			)
		);
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$http_client->response = array(
			'response' => array( 'code' => 500 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'code'    => 'wcpay_server_error',
					'message' => 'Link failed.',
				)
			),
		);

		$logger = RecordingWcLogger::install();

		$this->make_link_sut( $http_client )->link_session_if_user_just_logged_in();

		$this->assertGreaterThanOrEqual( 1, $http_client->request_count );
		$lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => '[Tracking] Error when linking session with user.' === $line[1] ) );
		if ( ! $expected ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$this->assertCount( 1, $lines );
		$this->assertSame( array( 'info', 'woopayments' ), array( $logger->lines[ $lines[0] ][0], $logger->lines[ $lines[0] ][2] ) );
		// The platform's message stays off this line; its status and code (not a listed one here) go in.
		$this->assertSame( array( 500, 'unknown_error' ), array( $logger->contexts[ $lines[0] ]['http_status'], $logger->contexts[ $lines[0] ]['error_code'] ) );
		$this->assertStringNotContainsString( 'Link failed', (string) wp_json_encode( $logger->contexts[ $lines[0] ] ) );
	}

	/** @return array<string,array{string,bool}> */
	public static function debug_logging_states(): array {
		return array(
			'off' => array( 'no', false ),
			'on'  => array( 'yes', true ),
		);
	}

	/**
	 * @testdox The session link runs on init, as the client hooks it.
	 */
	public function test_registers_session_link_on_init(): void {
		$sut = $this->make_sut();

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_action( 'init', array( $sut, 'link_session_if_user_just_logged_in' ) ) );
		remove_action( 'init', array( $sut, 'link_session_if_user_just_logged_in' ) );
	}

	/**
	 * Arrange a shopper whose session cookie still carries the pre-login guest customer.
	 *
	 * @param array<string,mixed> $fraud_services Account fraud-services config.
	 * @return FakeWooPaymentsHttpClient
	 */
	private function arrange_just_logged_in_shopper( array $fraud_services ): FakeWooPaymentsHttpClient {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option( 'wcpay_session_store_id', 'store_abc' );
		$this->seed_account_fraud_services( $fraud_services );
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_link_shopper' );
		// Keep this handler when the session service initializes the WooCommerce session.
		add_filter( 'woocommerce_session_handler', static fn() => FraudServiceLoggedInSessionHandler::class );
		WC()->session = new FraudServiceLoggedInSessionHandler( 't_guest_before_login' );

		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'success' => true ) ),
		);

		return $http_client;
	}

	/**
	 * Create the service under test with an API client on a fake transport.
	 *
	 * @param FakeWooPaymentsHttpClient $http_client Fake transport.
	 * @return WooPaymentsFraudService
	 */
	private function make_link_sut( FakeWooPaymentsHttpClient $http_client ): WooPaymentsFraudService {
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );

		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );
		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );
		$session_service  = new WooPaymentsSessionService();
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, $session_service );

		$sut = new WooPaymentsFraudService();
		$sut->init( $account_service, $customer_service, $session_service, $api_client );

		return $sut;
	}

	/**
	 * Seed the preserved account payload with a fraud-services config.
	 *
	 * Written through the account service's own cache writer so the cache
	 * envelope is valid and no account refresh fires mid-test.
	 *
	 * @param array<string,mixed> $fraud_services Fraud-services config to store on the account payload.
	 */
	private function seed_account_fraud_services( array $fraud_services ): void {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );
		$account_service->cache_account_data(
			array(
				'account_id'     => 'acct_fraud_service_test',
				'is_live'        => true,
				'fraud_services' => $fraud_services,
			)
		);
	}

	/**
	 * Block every platform request, answering only the public fraud-services
	 * config request with the given response.
	 *
	 * Unit tests must never reach the real platform: without this, the
	 * public-config fallback would fetch production's actual fraud config.
	 *
	 * @param array<string,mixed>|WP_Error $response Response (or error) the fraud-services request should receive.
	 */
	private function intercept_public_config_request( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, string $url ) use ( $response ) {
				if ( false !== strpos( $url, 'fraud_services' ) ) {
					$this->intercepted_request_urls[] = $url;

					return $response;
				}
				if ( false !== strpos( $url, 'public-api.wordpress.com' ) ) {
					return new WP_Error( 'blocked_in_test', 'Platform requests are blocked in unit tests.' );
				}

				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Create the service under test with real collaborators.
	 *
	 * @return WooPaymentsFraudService
	 */
	private function make_sut(): WooPaymentsFraudService {
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );

		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );

		$api_client = new WooPaymentsApiClient();

		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, new WooPaymentsSessionService() );

		$sut = new WooPaymentsFraudService();
		$sut->init( $account_service, $customer_service, new WooPaymentsSessionService(), $api_client );

		return $sut;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- Focused test double local to this test.

/**
 * Session handler whose cookie carries a fixed customer ID while the session belongs to the logged-in user.
 */
class FraudServiceLoggedInSessionHandler extends \WC_Session_Handler {
	/**
	 * Customer ID in the session cookie.
	 *
	 * @var string
	 */
	private string $cookie_customer_id;

	/**
	 * Constructor.
	 *
	 * @param string $cookie_customer_id Customer ID in the session cookie.
	 */
	public function __construct( string $cookie_customer_id ) {
		parent::__construct();
		$this->cookie_customer_id = $cookie_customer_id;
	}

	/**
	 * Get the session cookie.
	 *
	 * @return array<int,mixed>
	 */
	public function get_session_cookie() {
		return array( $this->cookie_customer_id, time() + HOUR_IN_SECONDS, time() + HOUR_IN_SECONDS / 2, 'hash' );
	}

	/**
	 * Get the session customer ID, the logged-in user.
	 *
	 * @return string
	 */
	public function get_customer_id() {
		return (string) get_current_user_id();
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName
