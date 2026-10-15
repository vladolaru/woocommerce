<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOnboardingAdapter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments settings controller.
 */
class WooPaymentsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_all_replacements();
		unset( $_GET['woopayments-ref'], $_GET['page'], $_GET['tab'], $_GET['path'], $_GET['section'], $_GET['method'], $_GET['id'], $_GET['wcpay-connection-success'] );
		delete_transient( 'woopayments_referral_code' );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		remove_all_filters( 'wp_redirect' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @testdox A `woopayments-ref` link from a store manager stores the sanitized code and redirects to the plugin's onboarding URL.
	 *
	 * Source: plugin 11.1.0 `WC_Payments_Account::maybe_redirect_onboarding_referral()` (the `woopayments-ref` argument and
	 * `sanitize_text_field`) continuing with `WC_Payments_Redirect_Service::redirect_to_nox_flow( 'REFERRAL' )`.
	 */
	public function test_referral_link_redirects_store_managers_to_onboarding_with_the_referral_source(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['woopayments-ref'] = ' partner-<b>abc</b> ';
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
			}
		);
		// The URL `redirect_to_nox_flow( 'REFERRAL' )` builds in plugin 11.1.0.
		$expected = admin_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/onboarding',
					'from' => 'REFERRAL',
				),
				'admin.php'
			)
		);

		try {
			$this->create_controller( $this->create_native_onboarding_service() )->handle_referral_link();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: ' . $expected, $exception->getMessage() );
		}

		$this->assertSame( 'partner-abc', get_transient( 'woopayments_referral_code' ) );
	}

	/**
	 * @testdox A `woopayments-ref` link is ignored for users who cannot manage WooCommerce.
	 */
	public function test_referral_link_is_ignored_without_the_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$_GET['woopayments-ref'] = 'partner-abc';
		$service                 = $this->createMock( WooPaymentsService::class );
		$service->expects( $this->never() )->method( 'handle_onboarding_referral' );

		$this->create_controller( $service )->handle_referral_link();
	}

	/**
	 * @testdox A WooCommerce update drops the onboarding fields cache in every native state, and leaves the plugin's own cache alone.
	 *
	 * @testWith [true, 1]
	 *           [false, 0]
	 *
	 * @param bool $native_owner Whether native owns the runtime.
	 * @param int  $clears       Expected cache clears.
	 */
	public function test_woocommerce_update_clears_the_native_onboarding_fields_cache( bool $native_owner, int $clears ): void {
		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $native_owner );
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->expects( $this->exactly( $clears ) )->method( 'clear_onboarding_fields_cache' );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
		$adapter = new WooPaymentsOnboardingAdapter();
		$adapter->init( $this->createMock( WooPaymentsExtensionRuntime::class ), $arbiter );
		wc_get_container()->replace( WooPaymentsOnboardingAdapter::class, $adapter );
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();
		$registered = has_action( 'woocommerce_updated', array( $sut, 'clear_native_onboarding_fields_cache' ) );
		remove_action( 'woocommerce_updated', array( $sut, 'clear_native_onboarding_fields_cache' ) );

		// Called directly: the controller core registered at boot listens to the same hook.
		$sut->clear_native_onboarding_fields_cache();

		$this->assertSame( 10, $registered );
	}

	/**
	 * @testdox Should register the onboarding redirect only on the Settings page load hook.
	 */
	public function test_registers_onboarding_redirect_on_the_settings_page_load_hook(): void {
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();

		$this->assertSame( 10, has_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
		$this->assertSame( 10, has_action( 'load-woocommerce_page_wc-admin', array( $sut, 'maybe_redirect_to_onboarding' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $sut, 'maybe_redirect_to_onboarding' ) ) );

		remove_action( 'admin_init', array( $sut, 'handle_returns_from_wpcom' ) );
		remove_action( 'admin_init', array( $sut, 'handle_referral_link' ), 13 );
		remove_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) );
		remove_action( 'load-woocommerce_page_wc-admin', array( $sut, 'maybe_redirect_to_onboarding' ) );
	}

	/**
	 * Client 11.1.0 hooks maybe_activate_woopay() on admin_init at the default priority and acts only on a page that
	 * carries `wcpay-connection-success` (includes/class-wc-payments-account.php:121, 2231-2234).
	 *
	 * @testdox Should check the platform's WooPay default on admin_init only on the page that carries the connection success flag.
	 */
	public function test_checks_platform_woopay_default_only_on_the_connection_success_page(): void {
		$adapter = $this->createMock( WooPaymentsOnboardingAdapter::class );
		$adapter->expects( $this->once() )->method( 'maybe_activate_woopay_enabled_by_default' );
		wc_get_container()->replace( WooPaymentsOnboardingAdapter::class, $adapter );
		$sut = $this->create_controller( $this->createMock( WooPaymentsService::class ) );

		$sut->register();
		$registered_priority = has_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'admin_init', array( $sut, 'handle_returns_from_wpcom' ) );
		remove_action( 'admin_init', array( $sut, 'handle_referral_link' ), 13 );
		remove_action( 'admin_init', array( $sut, 'maybe_activate_woopay' ) );
		remove_action( 'load-woocommerce_page_wc-settings', array( $sut, 'maybe_redirect_to_onboarding' ) );

		$sut->maybe_activate_woopay();
		$_GET['wcpay-connection-success'] = '1';
		$sut->maybe_activate_woopay();

		$this->assertSame( 10, $registered_priority );
	}

	/**
	 * @testdox Should not reach the adapter for the onboarding redirect on unrelated admin requests.
	 * @dataProvider provider_unrelated_requests
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_does_not_resolve_the_redirect_on_unrelated_requests( array $request ): void {
		$adapter = $this->replace_adapter_with_redirect_counter();
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		$this->create_controller( $this->createMock( WooPaymentsService::class ) )->maybe_redirect_to_onboarding();

		$this->assertSame( 0, $adapter->redirects );
	}

	/**
	 * @testdox Should ask the adapter for the onboarding redirect once on the guarded request $request.
	 * @dataProvider provider_guarded_requests
	 *
	 * @param array<string,string> $request Query request.
	 */
	public function test_guarded_requests_reach_the_onboarding_redirect_once( array $request ): void {
		$adapter = $this->replace_adapter_with_redirect_counter();
		foreach ( $request as $key => $value ) {
			$_GET[ $key ] = $value;
		}

		$this->create_controller( $this->createMock( WooPaymentsService::class ) )->maybe_redirect_to_onboarding();

		$this->assertSame( 1, $adapter->redirects );
	}

	/**
	 * Requests for guarded WooPayments pages and the extension's links to them.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_guarded_requests(): array {
		return array(
			'transactions route'         => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/transactions',
				),
			),
			'settings route'             => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/woopayments/settings',
				),
			),
			'extension settings section' => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'woocommerce_payments',
				),
			),
			'WC Admin overview link'     => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/overview',
				),
			),
			'WC Admin onboarding link'   => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/onboarding',
				),
			),
		);
	}

	/**
	 * Requests that are not guarded native WooPayments routes.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function provider_unrelated_requests(): array {
		return array(
			'Payments settings main page'  => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
				),
			),
			'other settings tab'           => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'general',
					'path' => '/woopayments/overview',
				),
			),
			'offline payment method'       => array(
				array(
					'page' => 'wc-settings',
					'tab'  => 'checkout',
					'path' => '/offline/bacs',
				),
			),
			'other gateway section'        => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'bacs',
				),
			),
			'WC Home'                      => array(
				array(
					'page' => 'wc-admin',
				),
			),
			'wc-admin analytics'           => array(
				array(
					'page' => 'wc-admin',
					'path' => '/analytics/overview',
				),
			),
			'unknown legacy payments path' => array(
				array(
					'page' => 'wc-admin',
					'path' => '/payments/unknown',
				),
			),
		);
	}

	/**
	 * Replace the container's adapter with one that counts the onboarding redirect requests.
	 *
	 * @return WooPaymentsOnboardingAdapter&object{redirects:int}
	 */
	private function replace_adapter_with_redirect_counter(): WooPaymentsOnboardingAdapter {
		$adapter = new class() extends WooPaymentsOnboardingAdapter {
			/**
			 * Number of onboarding redirect requests.
			 *
			 * @var int
			 */
			public int $redirects = 0;

			/**
			 * Count the onboarding redirect request.
			 */
			public function maybe_redirect_to_onboarding(): void {
				++$this->redirects;
			}
		};
		wc_get_container()->replace( WooPaymentsOnboardingAdapter::class, $adapter );

		return $adapter;
	}

	/**
	 * Create a real WooPayments service for a store without an account, while native owns onboarding.
	 *
	 * @return WooPaymentsService
	 */
	private function create_native_onboarding_service(): WooPaymentsService {
		$extension_runtime = $this->createMock( WooPaymentsExtensionRuntime::class );
		$extension_runtime->method( 'is_loaded' )->willReturn( false );
		$onboarding_adapter = $this->createMock( WooPaymentsOnboardingAdapter::class );
		$onboarding_adapter->method( 'has_valid_account' )->willReturn( false );
		$onboarding_service = new WooPaymentsOnboardingService();
		$onboarding_service->init( $this->createMock( LegacyProxy::class ) );
		$onboarding_adapter->method( 'store_referral_code' )->willReturnCallback( array( $onboarding_service, 'store_referral_code' ) );

		$service = new WooPaymentsService();
		$service->init( $this->createMock( PaymentsProviders::class ), $this->createMock( LegacyProxy::class ) );

		// The service resolves its native collaborators on first use; set the doubles on the instance.
		$collaborators = array(
			'onboarding_adapter' => $onboarding_adapter,
			'extension_runtime'  => $extension_runtime,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}

		return $service;
	}

	/**
	 * Create the controller with a service double.
	 *
	 * @param WooPaymentsService $service WooPayments service double.
	 * @return WooPaymentsController
	 */
	private function create_controller( WooPaymentsService $service ): WooPaymentsController {
		$controller = new WooPaymentsController();
		$controller->init( $this->createMock( Payments::class ), $service );

		return $controller;
	}
}
