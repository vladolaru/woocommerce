<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\LegacyAdminLinkHandler;
use WC_Unit_Test_Case;

/**
 * Tests for the native legacy admin link handler.
 */
class LegacyAdminLinkHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var LegacyAdminLinkHandler
	 */
	private LegacyAdminLinkHandler $sut;

	/**
	 * Recording API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = $this->create_api_client();
		$this->sut        = $this->create_handler( true );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'admin_init', array( $this->sut, 'handle_request' ) );
		remove_all_filters( 'allowed_redirect_hosts' );
		remove_all_filters( 'wp_redirect' );
		delete_transient( 'wcpay_stripe_onboarding_state' );
		unset( $_GET['wcpay-link-handler'], $_GET['type'], $_GET['return_url'], $_GET['nested'] );

		parent::tearDown();
	}

	/**
	 * @testdox The legacy admin link hook is registered only while native owns the runtime.
	 */
	public function test_registers_only_when_native_owns_runtime(): void {
		$this->sut->register();

		$this->assertNotFalse( has_action( 'admin_init', array( $this->sut, 'handle_request' ) ) );

		remove_action( 'admin_init', array( $this->sut, 'handle_request' ) );
		$this->sut = $this->create_handler( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'handle_request' ) ) );
	}

	/**
	 * @testdox The legacy admin link forwards sanitized query arguments and redirects to the returned provider URL.
	 */
	public function test_forwards_query_arguments_and_redirects_to_provider_url(): void {
		$this->api_client->response = array(
			'url'   => 'https://connect.stripe.com/setup/session',
			'state' => 'state_test',
		);
		$_GET                       = array(
			'wcpay-link-handler' => '',
			'type'               => 'complete_kyc_link',
			'return_url'         => 'https:\/\/example.com\/return',
			'nested'             => array( '<b>value</b>' ),
		);
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_redirect_host' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'wp_redirect intercepted: https://connect.stripe.com/setup/session', $exception->getMessage() );
		}

		$this->assertSame(
			array(
				'type'       => 'complete_kyc_link',
				'return_url' => 'https://example.com/return',
				'nested'     => array( 'value' ),
			),
			$this->api_client->last_args
		);
		$this->assertSame( 'state_test', get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * @testdox Link failures redirect to the native overview error notice.
	 * @dataProvider provider_failed_link_responses
	 *
	 * @param array<string,mixed>          $response  API response.
	 * @param WooPaymentsApiException|null $exception Optional API exception.
	 */
	public function test_redirects_to_overview_error_when_link_creation_fails( array $response, ?WooPaymentsApiException $exception ): void {
		$this->api_client->response  = $response;
		$this->api_client->exception = $exception;
		$_GET['wcpay-link-handler']  = '';
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->handle_request();
			$this->fail( 'Expected the redirect to be intercepted.' );
		} catch ( \RuntimeException $caught ) {
			$location = rawurldecode( $caught->getMessage() );
			$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $location );
			$this->assertStringContainsString( 'wcpay-server-link-error=1', $location );
		}

		$this->assertFalse( get_transient( 'wcpay_stripe_onboarding_state' ) );
	}

	/**
	 * Failed link responses.
	 *
	 * @return array<string,array{response:array<string,mixed>,exception:WooPaymentsApiException|null}>
	 */
	public function provider_failed_link_responses(): array {
		return array(
			'missing URL'   => array(
				'response'  => array( 'state' => 'state_without_url' ),
				'exception' => null,
			),
			'API exception' => array(
				'response'  => array(),
				'exception' => new WooPaymentsApiException( 'Link unavailable.', 'link_unavailable', 503 ),
			),
		);
	}

	/**
	 * @testdox The handler ignores requests without the capability, flag, or native ownership.
	 * @dataProvider provider_ignored_requests
	 *
	 * @param bool $native_owner Whether native owns the runtime.
	 * @param bool $has_flag     Whether the request has the handler flag.
	 * @param bool $has_access   Whether the current user has admin access.
	 */
	public function test_ignores_ineligible_requests( bool $native_owner, bool $has_flag, bool $has_access ): void {
		$this->sut = $this->create_handler( $native_owner );
		if ( $has_flag ) {
			$_GET['wcpay-link-handler'] = '';
		}
		if ( ! $has_access ) {
			wp_set_current_user( 0 );
		}

		$this->sut->handle_request();

		$this->assertSame( array(), $this->api_client->last_args );
	}

	/**
	 * Ignored request states.
	 *
	 * @return array<string,array{native_owner:bool,has_flag:bool,has_access:bool}>
	 */
	public function provider_ignored_requests(): array {
		return array(
			'plugin owns runtime' => array(
				'native_owner' => false,
				'has_flag'     => true,
				'has_access'   => true,
			),
			'missing flag'        => array(
				'native_owner' => true,
				'has_flag'     => false,
				'has_access'   => true,
			),
			'missing capability'  => array(
				'native_owner' => true,
				'has_flag'     => true,
				'has_access'   => false,
			),
		);
	}

	/**
	 * Add the Stripe redirect host for redirect tests.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_stripe_redirect_host( array $hosts ): array {
		$hosts[] = 'connect.stripe.com';

		return $hosts;
	}

	/**
	 * Intercept redirects so production exit paths do not stop the test runner.
	 *
	 * @param string $location Redirect target.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect( string $location ): void {
		throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
	}

	/**
	 * Create a native legacy admin link handler.
	 *
	 * @param bool $native_register Whether native should own hook registration.
	 * @return LegacyAdminLinkHandler
	 */
	private function create_handler( bool $native_register ): LegacyAdminLinkHandler {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$handler = new LegacyAdminLinkHandler();
		$handler->init( $arbiter, $this->api_client );

		return $handler;
	}

	/**
	 * Create a recording API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {

			/** @var array<string,mixed> */
			public array $response = array();

			/** @var WooPaymentsApiException|null */
			public ?WooPaymentsApiException $exception = null;

			/** @var array<string,mixed> */
			public array $last_args = array();

			/**
			 * Create an account link.
			 *
			 * @param array<string,mixed> $args Account-link arguments.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When configured.
			 */
			public function create_account_link( array $args ): array {
				$this->last_args = $args;
				if ( null !== $this->exception ) {
					throw $this->exception;
				}

				return $this->response;
			}
		};
	}
}
