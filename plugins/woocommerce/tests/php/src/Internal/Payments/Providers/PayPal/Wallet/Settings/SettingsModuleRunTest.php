<?php
/**
 * Tests for what SettingsModule::run() registers.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\AuthenticationRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\CommonRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\FeaturesRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\LoginLinkRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\OnboardingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\PayLaterMessagingEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\PaymentRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\RefreshFeatureStatusEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\SettingsRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\StylingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\TodosRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\WebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience\PathRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\GatewayRedirectService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\LoadingScreenService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\ScriptDataHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\SettingsModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles\ContainerDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;

/**
 * SettingsModule::run() only registers hooks, so the tests run it over a container that serves the services the hooks
 * ask for, then fire the hooks the way WordPress does: the REST routes of the settings app are registered on
 * rest_api_init, its loading screen is added on admin_head and its scripts are loaded on admin_enqueue_scripts, and
 * both only on the PayPal settings page.
 *
 * The REST endpoints are the real ones, over mocks of their own collaborators. The hooks the module adds are removed
 * again by the test case, and the admin hooks that exist before run() are cleared first so that firing one runs only
 * what the module added.
 *
 * @group paypal-wallet
 */
class SettingsModuleRunTest extends WalletTestCase {

	/**
	 * The REST server the test replaced, put back on tearDown.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_rest_server;

	/**
	 * Remember the REST server.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_rest_server = $GLOBALS['wp_rest_server'] ?? null;
	}

	/**
	 * Put back the REST server.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wp_rest_server'] = $this->original_rest_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The REST endpoint services of the module, each a real endpoint over mocks of what its constructor asks for.
	 *
	 * @return array<string, object>
	 */
	private function rest_endpoint_services(): array {
		$classes = array(
			'settings.rest.onboarding'             => OnboardingRestEndpoint::class,
			'settings.rest.common'                 => CommonRestEndpoint::class,
			'settings.rest.authentication'         => AuthenticationRestEndpoint::class,
			'settings.rest.login_link'             => LoginLinkRestEndpoint::class,
			'settings.rest.webhooks'               => WebhookSettingsEndpoint::class,
			'settings.rest.refresh_feature_status' => RefreshFeatureStatusEndpoint::class,
			'settings.rest.payment'                => PaymentRestEndpoint::class,
			'settings.rest.settings'               => SettingsRestEndpoint::class,
			'settings.rest.styling'                => StylingRestEndpoint::class,
			'settings.rest.todos'                  => TodosRestEndpoint::class,
			'settings.rest.pay_later_messaging'    => PayLaterMessagingEndpoint::class,
			'settings.rest.features'               => FeaturesRestEndpoint::class,
		);

		// Every constructor parameter of these endpoints is a class-typed collaborator, so each gets a mock.
		$services = array();
		foreach ( $classes as $id => $class_name ) {
			$arguments = array();
			foreach ( ( new ReflectionClass( $class_name ) )->getConstructor()->getParameters() as $parameter ) {
				$arguments[] = $parameter->getType()->isBuiltin() ? null : $this->mock( $parameter->getType()->getName() );
			}
			$services[ $id ] = new $class_name( ...$arguments );
		}

		return $services;
	}

	/**
	 * The services run() asks for while it registers its hooks, and the REST endpoints.
	 *
	 * @param array<string, mixed> $more Services for the hooks a test fires, by ID.
	 * @return ContainerDouble
	 */
	private function container( array $more = array() ): ContainerDouble {
		return new ContainerDouble(
			array_merge(
				array(
					'settings.services.loading-screen-service' => new LoadingScreenService(),
					'settings.service.gateway-redirect' => new GatewayRedirectService(),
				),
				$this->rest_endpoint_services(),
				$more
			)
		);
	}

	/**
	 * Run the module over the container with no admin hook of another plugin in the way.
	 *
	 * @param ContainerDouble $container The container.
	 */
	private function run_module( ContainerDouble $container ): void {
		remove_all_actions( 'admin_head' );
		remove_all_actions( 'admin_enqueue_scripts' );
		remove_all_actions( 'rest_api_init' );

		$this->assertTrue( ( new SettingsModule() )->run( $container ) );
	}

	/**
	 * @testdox Should register the routes of every settings endpoint under wc/v3/wc_paypal when the REST API starts.
	 */
	public function test_run_registers_the_settings_rest_routes_when_the_rest_api_starts(): void {
		$this->run_module( $this->container() );

		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the REST server.
		$this->assertSame( array(), $server->get_routes( 'wc/v3/wc_paypal' ), 'No route should exist before the REST API starts' );

		do_action( 'rest_api_init', $server );

		$routes = array_keys( $server->get_routes( 'wc/v3/wc_paypal' ) );
		sort( $routes );

		$expected = array(
			'/wc/v3/wc_paypal', // The index of the namespace, which WordPress adds.
			'/wc/v3/wc_paypal/authenticate/direct',
			'/wc/v3/wc_paypal/authenticate/disconnect',
			'/wc/v3/wc_paypal/authenticate/oauth',
			'/wc/v3/wc_paypal/common',
			'/wc/v3/wc_paypal/common/merchant',
			'/wc/v3/wc_paypal/common/seller-account',
			'/wc/v3/wc_paypal/features',
			'/wc/v3/wc_paypal/login_link',
			'/wc/v3/wc_paypal/onboarding',
			'/wc/v3/wc_paypal/pay_later_messaging',
			'/wc/v3/wc_paypal/payment',
			'/wc/v3/wc_paypal/refresh-features',
			'/wc/v3/wc_paypal/settings',
			'/wc/v3/wc_paypal/styling',
			'/wc/v3/wc_paypal/todos',
			'/wc/v3/wc_paypal/todos/complete',
			'/wc/v3/wc_paypal/todos/reset',
			'/wc/v3/wc_paypal/webhooks',
			'/wc/v3/wc_paypal/webhooks/simulate',
		);
		sort( $expected );
		$this->assertSame( $expected, $routes );
	}

	/**
	 * @testdox Should let only a user who can manage WooCommerce use the settings routes: a shop manager and an administrator may, a subscriber and a visitor may not.
	 */
	public function test_the_registered_routes_check_the_manage_woocommerce_capability(): void {
		$this->run_module( $this->container() );

		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the REST server.
		do_action( 'rest_api_init', $server );

		$users = array(
			'subscriber'    => array( self::factory()->user->create( array( 'role' => 'subscriber' ) ), false ),
			'shop manager'  => array( self::factory()->user->create( array( 'role' => 'shop_manager' ) ), true ),
			'administrator' => array( self::factory()->user->create( array( 'role' => 'administrator' ) ), true ),
			'visitor'       => array( 0, false ),
		);

		$checked = 0;
		foreach ( $server->get_routes( 'wc/v3/wc_paypal' ) as $route => $handlers ) {
			if ( '/wc/v3/wc_paypal' === $route ) {
				continue; // The index of the namespace, which WordPress adds.
			}
			foreach ( $handlers as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], "The route $route should check a permission" );
				foreach ( $users as $who => $user ) {
					wp_set_current_user( $user[0] );
					$this->assertSame( $user[1], call_user_func( $handler['permission_callback'] ), "The route $route should " . ( $user[1] ? 'admit' : 'refuse' ) . " a $who" );
				}
				++$checked;
			}
		}
		$this->assertGreaterThan( 0, $checked, 'At least one route should have been checked' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/wc_paypal/onboarding' ) )->get_status(), 'A visitor should be refused' );
	}

	/**
	 * @testdox Should add the loading screen styles on the PayPal settings page and nowhere else.
	 *
	 * @dataProvider data_settings_page_requests
	 *
	 * @param array<string, string> $query     The query arguments of the admin request.
	 * @param bool                  $is_loaded Whether the loading screen is expected.
	 */
	public function test_run_adds_the_loading_screen_on_the_paypal_settings_page_only( array $query, bool $is_loaded ): void {
		$this->simulate_admin_request( $query );
		$this->run_module( $this->container() );

		ob_start();
		do_action( 'admin_head' );
		$output = (string) ob_get_clean();

		if ( $is_loaded ) {
			$this->assertStringContainsString( '#ppcp-settings-container', $output, 'The loading screen should hide the WooCommerce settings form' );
		} else {
			$this->assertSame( '', $output, 'The loading screen should not appear' );
		}
	}

	/**
	 * Admin requests and whether they are the PayPal settings page.
	 *
	 * @return array<string, array{array<string, string>, bool}>
	 */
	public function data_settings_page_requests(): array {
		return array(
			'the PayPal settings section' => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'ppcp-gateway',
				),
				true,
			),
			'another payment gateway'     => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => 'bacs',
				),
				false,
			),
			'another settings tab'        => array(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'shipping',
					'section' => 'ppcp-gateway',
				),
				false,
			),
		);
	}

	/**
	 * @testdox Should not add the loading screen outside the admin.
	 */
	public function test_run_adds_no_loading_screen_outside_the_admin(): void {
		$this->run_module( $this->container() );

		$this->assertFalse( has_action( 'admin_head' ), 'No admin_head hook should be added to a front-end request' );
	}

	/**
	 * @testdox Should redirect the old gateway settings links by listening on admin_init.
	 */
	public function test_run_registers_the_gateway_redirect_on_admin_init(): void {
		$redirect = new GatewayRedirectService();
		$this->run_module( $this->container( array( 'settings.service.gateway-redirect' => $redirect ) ) );

		$this->assertNotFalse( has_action( 'admin_init', array( $redirect, 'handle_redirects' ) ) );
	}

	/**
	 * @testdox Should initialize the branded-only flags and then load the settings app scripts on the PayPal settings page.
	 */
	public function test_admin_enqueue_scripts_loads_the_settings_app_on_the_plugin_settings_page(): void {
		$installation_path = 'direct';

		$general_settings = $this->mock( GeneralSettings::class );
		$general_settings->shouldReceive( 'get_installation_path' )->andReturn( $installation_path );

		$path_repository = $this->mock( PathRepository::class );
		$path_repository->shouldReceive( 'persist' )->once()->ordered();

		$partner_attribution = $this->mock( PartnerAttribution::class );
		$partner_attribution->shouldReceive( 'initialize_bn_code' )->once()->ordered()->with( $installation_path );

		$script_data_handler = $this->mock( ScriptDataHandler::class );
		$script_data_handler->shouldReceive( 'localize_scripts' )->once()->ordered()->with( 'woocommerce_page_wc-settings' );

		$this->run_module(
			$this->container(
				array(
					'wcgateway.is-plugin-settings-page'    => true,
					'settings.service.branded-experience.path-repository' => $path_repository,
					'api.helper.partner-attribution'       => $partner_attribution,
					'settings.data.general'                => $general_settings,
					'settings.service.script-data-handler' => $script_data_handler,
				)
			)
		);

		do_action( 'admin_enqueue_scripts', 'woocommerce_page_wc-settings' );
	}

	/**
	 * Nothing but the page check is served: asking the container for the script handler or the branded-only services
	 * would throw, so the test also checks that the check is the only service the hook asked for.
	 *
	 * @testdox Should load nothing on a page that is not the PayPal settings page.
	 */
	public function test_admin_enqueue_scripts_loads_nothing_on_another_page(): void {
		$container = $this->container( array( 'wcgateway.is-plugin-settings-page' => false ) );
		$this->run_module( $container );
		$requested_by_run = count( $container->requested() );

		do_action( 'admin_enqueue_scripts', 'edit.php' );

		$this->assertSame(
			array( 'wcgateway.is-plugin-settings-page' ),
			array_slice( $container->requested(), $requested_by_run ),
			'Only the page check should be asked for'
		);
	}
}
