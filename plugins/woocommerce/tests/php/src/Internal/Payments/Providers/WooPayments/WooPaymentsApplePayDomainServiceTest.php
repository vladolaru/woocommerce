<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments Apple Pay domain registration service.
 */
class WooPaymentsApplePayDomainServiceTest extends WC_Unit_Test_Case {

	/**
	 * Option name for native WooPayments gateway settings.
	 */
	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const APPLE_PAY_SETTINGS_OPTION = 'woocommerce_woocommerce_payments_apple_pay_settings';

	/**
	 * Option name for stored Apple Pay domain registration errors.
	 */
	private const ERROR_OPTION = 'wcpay_apple_pay_domain_error';

	/**
	 * Retry action hook.
	 */
	private const RETRY_ACTION = 'wcpay_register_apple_pay_domain';

	/**
	 * Site host expected during tests.
	 *
	 * @var string
	 */
	private string $expected_domain;

	/**
	 * Recording native API client.
	 *
	 * @var RecordingApplePayDomainApiClient
	 */
	private RecordingApplePayDomainApiClient $api_client;

	/**
	 * Recording scheduler.
	 *
	 * @var RecordingApplePayDomainScheduler
	 */
	private RecordingApplePayDomainScheduler $scheduler;

	/**
	 * System under test.
	 *
	 * @var WooPaymentsApplePayDomainService|null
	 */
	private $service = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->expected_domain = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
		$this->api_client      = new RecordingApplePayDomainApiClient();
		$this->scheduler       = new RecordingApplePayDomainScheduler();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::APPLE_PAY_SETTINGS_OPTION );
		delete_option( self::ERROR_OPTION );

		if ( null !== $this->service ) {
			remove_action( 'admin_init', array( $this->service, 'verify_domain_on_domain_name_change' ) );
			remove_action( 'admin_notices', array( $this->service, 'display_error_notice' ) );
			remove_action( 'woocommerce_woocommerce_payments_admin_notices', array( $this->service, 'display_error_notice' ) );
			remove_action( 'update_option_home', array( $this->service, 'verify_domain_on_site_url_change' ) );
			remove_action( 'update_option_siteurl', array( $this->service, 'verify_domain_on_site_url_change' ) );
			remove_action( 'update_option_' . self::SETTINGS_OPTION, array( $this->service, 'verify_domain_on_updated_gateway_settings' ) );
			remove_action( 'add_option_' . self::SETTINGS_OPTION, array( $this->service, 'verify_domain_on_new_gateway_settings' ) );
			remove_action( 'update_option_' . self::APPLE_PAY_SETTINGS_OPTION, array( $this->service, 'verify_domain_on_updated_apple_pay_settings' ) );
			remove_action( 'add_option_' . self::APPLE_PAY_SETTINGS_OPTION, array( $this->service, 'verify_domain_on_new_apple_pay_settings' ) );
			remove_action( self::RETRY_ACTION, array( $this->service, 'handle_domain_registration_retry' ) );
			remove_action( 'shutdown', array( $this->service, 'flush_registration_events' ) );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should register Apple Pay domain when the split Apple Pay gateway becomes enabled.
	 */
	public function test_verify_domain_on_express_checkout_enable_registers_domain(): void {
		$this->service = $this->create_service();
		$this->set_gateway_settings( array( 'enabled' => 'yes' ) );

		$this->service->verify_domain_on_updated_apple_pay_settings(
			array( 'enabled' => 'no' ),
			array( 'enabled' => 'yes' )
		);

		$this->assertSame( array( $this->expected_domain ), $this->api_client->registered_domains );
		$stored = get_option( self::SETTINGS_OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( $this->expected_domain, $stored['apple_pay_verified_domain'] );
		$this->assertSame( 'yes', $stored['apple_pay_domain_set'] );
		$this->assertFalse( get_option( self::ERROR_OPTION ) );
	}

	/**
	 * @testdox Should re-register Apple Pay domain when the site URL host changes.
	 */
	public function test_site_url_change_registers_domain_when_configured(): void {
		$this->service = $this->create_service();
		$this->set_gateway_settings(
			array(
				'enabled'                           => 'yes',
				'apple_pay_verified_domain'         => 'old.example',
				'express_checkout_product_methods'  => array(),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		);

		$this->service->verify_domain_on_site_url_change( 'https://old.example', get_site_url() );

		$this->assertSame( array( $this->expected_domain ), $this->api_client->registered_domains );
	}

	/**
	 * @testdox Should store failure details and schedule a retry when domain registration fails.
	 */
	public function test_register_domain_stores_error_and_schedules_retry_on_failure(): void {
		$error_message              = 'Domain verification failed: invalid domain';
		$this->api_client->response = array(
			'id'        => 'domain_123',
			'apple_pay' => array(
				'status'         => 'failed',
				'status_details' => array( 'error_message' => $error_message ),
			),
		);
		$this->service              = $this->create_service();
		$this->set_gateway_settings(
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		);

		$this->service->register_domain();

		$stored = get_option( self::SETTINGS_OPTION );
		$this->assertIsArray( $stored );
		$this->assertSame( $this->expected_domain, $stored['apple_pay_verified_domain'] );
		$this->assertSame( 'no', $stored['apple_pay_domain_set'] );
		$this->assertSame( $error_message, get_option( self::ERROR_OPTION ) );
		$this->assertCount( 1, $this->scheduler->scheduled_jobs );
		$this->assertSame( self::RETRY_ACTION, $this->scheduler->scheduled_jobs[0]['hook'] );
	}

	/**
	 * @testdox Should flush one last-write-wins Apple Pay event per event name.
	 */
	public function test_registration_event_queue_is_last_write_wins_and_flushes_once(): void {
		$recorded_events = array();
		$tracker         = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'record_user_event' ) )
			->getMock();
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_events ): bool {
				unset( $properties['record_event_data'] );
				$recorded_events[] = array( $event_name, $properties );
				return true;
			}
		);

		$this->service = $this->create_service( true, false, $tracker );
		$this->service->register();
		$this->service->register();
		$this->assertSame( 10, has_action( 'shutdown', array( $this->service, 'flush_registration_events' ) ) );
		$this->service->register_domain();
		$this->service->register_domain();

		$first_error_message        = 'Domain verification failed: first reason';
		$this->api_client->response = array(
			'id'        => 'domain_123',
			'apple_pay' => array(
				'status'         => 'failed',
				'status_details' => array( 'error_message' => $first_error_message ),
			),
		);
		$this->service->register_domain();
		$last_error_message         = 'Domain verification failed: last reason';
		$this->api_client->response = array(
			'id'        => 'domain_123',
			'apple_pay' => array(
				'status'         => 'failed',
				'status_details' => array( 'error_message' => $last_error_message ),
			),
		);
		$this->service->register_domain();
		$this->assertSame( array(), $recorded_events );

		$this->service->flush_registration_events();
		$this->service->flush_registration_events();

		$this->assertSame(
			array(
				array(
					'apple_pay_domain_registration_success',
					array(
						'domain' => $this->expected_domain,
						'mode'   => 'live',
					),
				),
				array(
					'apple_pay_domain_registration_failure',
					array(
						'domain' => $this->expected_domain,
						'reason' => $last_error_message,
						'mode'   => 'live',
					),
				),
			),
			$recorded_events
		);
	}

	/**
	 * @testdox Should preserve the oracle dev mode value in Apple Pay domain events.
	 */
	public function test_register_domain_tracks_dev_mode_value(): void {
		$recorded_properties = array();
		$tracker             = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'record_user_event' ) )
			->getMock();
		$tracker->method( 'record_user_event' )->willReturnCallback(
			static function ( string $event_name, array $properties ) use ( &$recorded_properties ): bool {
				$recorded_properties = $properties;
				return true;
			}
		);

		$this->service = $this->create_service( true, false, $tracker, true );
		$this->service->register_domain();
		$this->service->flush_registration_events();

		$this->assertSame( 'dev', $recorded_properties['mode'] ?? null );
	}

	/**
	 * @testdox Should preserve successful registration when dev-mode telemetry lookup throws.
	 */
	public function test_successful_registration_contains_throwing_dev_mode_telemetry(): void {
		$tracker = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'record_user_event' ) )
			->getMock();
		$tracker->expects( $this->never() )->method( 'record_user_event' );
		$this->service = $this->create_service( true, false, $tracker, false, 'is_dev_mode_enabled' );

		$result = $this->service->register_domain();
		$stored = get_option( self::SETTINGS_OPTION );

		$this->assertTrue( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( $this->expected_domain, $stored['apple_pay_verified_domain'] );
		$this->assertSame( 'yes', $stored['apple_pay_domain_set'] );
		$this->assertFalse( get_option( self::ERROR_OPTION ) );
		$this->assertSame( array(), $this->scheduler->scheduled_jobs );
	}

	/**
	 * @testdox Should preserve failed registration and retry when mode telemetry lookup throws.
	 */
	public function test_failed_registration_contains_throwing_mode_telemetry(): void {
		$error_message              = 'Domain verification failed before telemetry';
		$this->api_client->response = array(
			'id'        => 'domain_123',
			'apple_pay' => array(
				'status'         => 'failed',
				'status_details' => array( 'error_message' => $error_message ),
			),
		);
		$tracker                    = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'record_user_event' ) )
			->getMock();
		$tracker->expects( $this->never() )->method( 'record_user_event' );
		$this->service = $this->create_service( true, false, $tracker, false, 'get_mode' );

		$result = $this->service->register_domain();
		$stored = get_option( self::SETTINGS_OPTION );

		$this->assertFalse( $result );
		$this->assertIsArray( $stored );
		$this->assertSame( $this->expected_domain, $stored['apple_pay_verified_domain'] );
		$this->assertSame( 'no', $stored['apple_pay_domain_set'] );
		$this->assertSame( $error_message, get_option( self::ERROR_OPTION ) );
		$this->assertCount( 1, $this->scheduler->scheduled_jobs );
		$this->assertSame( self::RETRY_ACTION, $this->scheduler->scheduled_jobs[0]['hook'] );
	}

	/**
	 * @testdox Should display and clear the Apple Pay domain failure notice for live accounts.
	 */
	public function test_display_error_notice_reuses_extension_copy_and_clears_stored_error(): void {
		$this->service = $this->create_service( true, true );
		$this->set_gateway_settings(
			array(
				'enabled'                           => 'yes',
				'apple_pay_domain_set'              => 'no',
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		);
		update_option( self::ERROR_OPTION, 'Test error message' );

		ob_start();
		$this->service->display_error_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Express checkouts:', $output );
		$this->assertStringContainsString( 'Apple Pay domain verification failed with the following error:', $output );
		$this->assertStringContainsString( 'Test error message', $output );
		$this->assertStringContainsString( 'https://woocommerce.com/document/woopayments/payment-methods/apple-pay/#button-does-not-appear', $output );
		$this->assertStringContainsString( 'target="_blank"', $output );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output );
		$this->assertFalse( get_option( self::ERROR_OPTION ) );
	}

	/**
	 * @testdox Should show the Apple Pay domain notice on the WooPayments settings route merchants open: $path.
	 *
	 * @dataProvider provide_woopayments_settings_routes
	 *
	 * @param string $path The `path` query arg of the settings URL.
	 */
	public function test_error_notice_renders_on_the_woopayments_settings_route( string $path ): void {
		$this->arrange_failed_domain_verification();

		$output = $this->render_payments_settings_page(
			array(
				'page' => 'wc-settings',
				'tab'  => 'checkout',
				'path' => $path,
				'from' => 'PAYMENTS_SETTINGS',
			)
		);

		$this->assertStringContainsString( 'Test error message', $output );
		$this->assertFalse( get_option( self::ERROR_OPTION ), 'The detailed error is shown once, as in the client.' );
	}

	/**
	 * Settings routes where client 11.1.0 fired its WooPayments settings notices.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_woopayments_settings_routes(): array {
		return array(
			'settings'                   => array( '/woopayments/settings' ),
			'express checkout sub-route' => array( '/woopayments/settings/express-checkout/payment_request' ),
		);
	}

	/**
	 * @testdox Should not show the Apple Pay domain notice outside the WooPayments settings route: $label.
	 *
	 * @dataProvider provide_other_admin_screens
	 *
	 * @param string              $label Screen label.
	 * @param array<string,mixed> $query The screen's query args.
	 */
	public function test_error_notice_does_not_render_on_other_admin_screens( string $label, array $query ): void {
		unset( $label );
		$this->arrange_failed_domain_verification();

		$previous_screen = $GLOBALS['current_screen'] ?? null;
		set_current_screen( 'woocommerce_page_wc-settings' );
		try {
			$output = $this->render_payments_settings_page( $query );
			ob_start();
			do_action( 'admin_notices' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
			$output .= (string) ob_get_clean();
		} finally {
			$GLOBALS['current_screen'] = $previous_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$this->assertStringNotContainsString( 'apple-pay-message', $output );
		$this->assertSame( 'Test error message', get_option( self::ERROR_OPTION ), 'The error stays stored until the settings route shows it.' );
	}

	/**
	 * Admin screens that are not the WooPayments settings route.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public function provide_other_admin_screens(): array {
		return array(
			'payments list'    => array(
				'payments list',
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'fraud protection' => array(
				'fraud protection',
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/settings/fraud-protection',
				),
			),
			'overview'         => array(
				'overview',
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/overview',
				),
			),
		);
	}

	/**
	 * @testdox Should leave the settings bootstrap empty after the server notice showed the error on a full page load.
	 */
	public function test_settings_bootstrap_is_empty_after_the_server_notice_rendered(): void {
		$this->arrange_failed_domain_verification();

		$output = $this->render_payments_settings_page(
			array(
				'page' => 'wc-settings',
				'tab'  => 'checkout',
				'path' => '/woopayments/settings',
			)
		);

		$this->assertStringContainsString( 'Test error message', $output );
		$this->assertNull( $this->service->get_error_notice_for_settings_bootstrap(), 'The footer bootstrap must not repeat the notice the page already shows.' );
	}

	/**
	 * @testdox Should keep the detailed error when the settings bootstrap carries it, until a notice shows it.
	 */
	public function test_settings_bootstrap_keeps_the_error_until_a_notice_shows_it(): void {
		$this->arrange_failed_domain_verification();

		$notice = $this->service->get_error_notice_for_settings_bootstrap();

		$this->assertSame( 'Test error message', $notice['error'] ?? null );
		$this->assertSame( 'Test error message', get_option( self::ERROR_OPTION ) );

		update_option( self::ERROR_OPTION, 'Newer error from a retry' );
		$this->assertFalse( $this->service->clear_displayed_error_notice_if_unchanged( $notice['errorId'] ?? '' ) );
		$this->assertSame( 'Newer error from a retry', get_option( self::ERROR_OPTION ), 'An error replaced after the page load stays for the next display.' );

		$this->assertTrue( $this->service->clear_displayed_error_notice_if_unchanged( hash( 'sha256', 'Newer error from a retry' ) ) );
		$this->assertFalse( get_option( self::ERROR_OPTION ) );
	}

	/**
	 * @testdox Should deliver the generic domain failure to the settings bootstrap when no detailed error is stored.
	 */
	public function test_settings_bootstrap_carries_the_generic_domain_failure(): void {
		$this->arrange_failed_domain_verification();
		delete_option( self::ERROR_OPTION );

		$notice = $this->service->get_error_notice_for_settings_bootstrap();

		$this->assertSame( '', $notice['error'] ?? null );
	}

	/**
	 * @testdox Should not attach mutating hooks when the native runtime is dormant.
	 */
	public function test_register_is_noop_when_native_runtime_is_dormant(): void {
		$this->service = $this->create_service( false );
		$this->service->register();

		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled'                           => 'yes',
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		);
		/**
		 * Fires a pending Apple Pay domain registration retry.
		 *
		 * @since 11.0.0
		 */
		do_action( self::RETRY_ACTION );

		$this->assertSame( array(), $this->api_client->registered_domains );
		$this->assertFalse( has_action( self::RETRY_ACTION, array( $this->service, 'handle_domain_registration_retry' ) ) );
	}

	/**
	 * Create the service under test.
	 *
	 * @param bool                                       $native_register Whether the native runtime owns registration.
	 * @param bool                                       $live_account    Whether the account should be treated as live.
	 * @param WooPaymentsFrontendTrackingController|null $tracker         Optional tracking controller.
	 * @param bool                                       $dev_mode        Whether WooPayments dev mode is active.
	 * @param string|null                                $throwing_mode_method Account mode method that should throw.
	 * @return WooPaymentsApplePayDomainService
	 */
	private function create_service( bool $native_register = true, bool $live_account = false, ?WooPaymentsFrontendTrackingController $tracker = null, bool $dev_mode = false, ?string $throwing_mode_method = null ): WooPaymentsApplePayDomainService {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_live_account', 'get_mode', 'is_dev_mode_enabled', 'is_payment_request_method_enabled' ) )
			->getMock();
		$account_service->method( 'has_live_account' )->willReturn( $live_account );
		if ( 'get_mode' === $throwing_mode_method ) {
			$account_service->method( 'get_mode' )->willThrowException( new \RuntimeException( 'Mode lookup failed.' ) );
		} else {
			$account_service->method( 'get_mode' )->willReturn( 'live' );
		}
		if ( 'is_dev_mode_enabled' === $throwing_mode_method ) {
			$account_service->method( 'is_dev_mode_enabled' )->willThrowException( new \RuntimeException( 'Dev mode lookup failed.' ) );
		} else {
			$account_service->method( 'is_dev_mode_enabled' )->willReturn( $dev_mode );
		}
		$account_service->method( 'is_payment_request_method_enabled' )->willReturnCallback(
			static function ( string $method_id ): bool {
				$settings = get_option( self::APPLE_PAY_SETTINGS_OPTION, array() );

				return 'apple_pay' === $method_id && is_array( $settings ) && 'yes' === ( $settings['enabled'] ?? 'no' );
			}
		);

		$service = new WooPaymentsApplePayDomainService();
		$service->init( $arbiter, $this->api_client, $account_service, $this->scheduler, $tracker );

		return $service;
	}

	/**
	 * Persist gateway settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function set_gateway_settings( array $settings ): void {
		update_option( self::SETTINGS_OPTION, $settings );
		update_option( self::APPLE_PAY_SETTINGS_OPTION, array( 'enabled' => 'yes' ) );
	}

	/**
	 * Register a live-account service with a stored domain verification error.
	 */
	private function arrange_failed_domain_verification(): void {
		$this->service = $this->create_service( true, true );
		$this->set_gateway_settings(
			array(
				'enabled'                           => 'yes',
				'apple_pay_domain_set'              => 'no',
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			)
		);
		update_option( self::ERROR_OPTION, 'Test error message' );
		$this->service->register();
	}

	/**
	 * Render the Settings > Payments page for a request with the given query args.
	 *
	 * @param array<string,mixed> $query Request query args.
	 * @return string
	 */
	private function render_payments_settings_page( array $query ): string {
		global $current_section;

		require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
		require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-payment-gateways.php';

		$previous_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$previous_section = $current_section;
		$_GET             = $query; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_section  = isset( $query['section'] ) ? (string) $query['section'] : '';

		$buffer_level = ob_get_level();
		try {
			$page = new \WC_Settings_Payment_Gateways();
			ob_start();
			$page->output();

			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET            = $previous_get;
			$current_section = $previous_section;
		}
	}
}
