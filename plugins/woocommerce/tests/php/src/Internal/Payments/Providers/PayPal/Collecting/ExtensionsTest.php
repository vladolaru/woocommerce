<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ConnectBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Package;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Closure;
use ReflectionFunction;
use RuntimeException;

/**
 * Tests for the collecting module's extensions of the wallet's services, over a real container: the wallet's modules
 * with the collecting module appended last, as the shell builds it for a store the platform serves.
 *
 * @group paypal-wallet
 */
class ExtensionsTest extends WalletTestCase {

	/**
	 * The Pay Later task's config service.
	 */
	private const PAY_LATER_TASK_CONFIG = 'wcgateway.settings.wc-tasks.pay-later-task-config';

	/**
	 * Boot the wallet's modules plus the collecting module and return the container.
	 *
	 * The collecting module is added whatever the store's state, so a store the platform does not serve shows that the
	 * extensions hand the wallet's own values through.
	 *
	 * @return ContainerInterface
	 */
	private function boot_container(): ContainerInterface {
		foreach ( PayPalWalletBootstrap::get_extension_constants() as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constants the shell defines before a boot.
			}
		}
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';
		// The SDK v6 module loads unless the store is flagged ineligible; load it for certain.
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled', '__return_true' );

		$modules   = ( require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/modules.php' )();
		$modules[] = new CollectingModule();

		$package = Package::new( WalletProperties::new() );
		foreach ( $modules as $module ) {
			$package->addModule( $module );
		}
		$package->boot();

		return $package->container();
	}

	/**
	 * Put the store in the collecting state.
	 *
	 * @param string $environment `sandbox` or `production`.
	 */
	private function set_collecting( string $environment = 'sandbox' ): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => $environment,
				'payee_bound' => false,
			)
		);
	}

	/**
	 * Store first-party credentials in the shared settings option, as a merchant connected through the wallet.
	 */
	private function set_first_party_connected(): void {
		$this->set_wallet_option(
			'woocommerce-ppcp-data-common',
			array(
				'merchant_connected' => true,
				'sandbox_merchant'   => false,
				'merchant_id'        => 'M1',
				'merchant_email'     => 'merchant@example.com',
				'client_id'          => 'client-id',
				'client_secret'      => 'client-secret',
			)
		);
	}

	/**
	 * Turn the wallet gateway on.
	 */
	private function enable_gateway(): void {
		$this->set_wallet_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'yes' ) );
	}

	/**
	 * @testdox Should serve a collecting store as connected, in the collecting environment, with its webhooks counted as registered.
	 */
	public function test_collecting_store_is_served_as_connected(): void {
		$this->set_collecting( 'sandbox' );

		$container = $this->boot_container();

		$this->assertTrue( $container->get( 'settings.flag.is-connected' ), 'A collecting store counts as connected' );
		$environment = $container->get( 'settings.environment' );
		$this->assertInstanceOf( Environment::class, $environment );
		$this->assertTrue( $environment->is_sandbox(), 'The environment is the collecting state\'s' );
		$this->assertTrue( $container->get( 'webhook.is-registered' ), 'The wallet must not auto-register merchant webhooks' );
	}

	/**
	 * @testdox Should use production for a collecting store in production.
	 */
	public function test_collecting_store_in_production(): void {
		$this->set_collecting( 'production' );

		$this->assertFalse( $this->boot_container()->get( 'settings.environment' )->is_sandbox() );
	}

	/**
	 * @testdox Should serve a platform-connected store as connected, in the platform option's environment.
	 */
	public function test_platform_connected_store_is_served_as_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'environment' => 'sandbox',
			)
		);

		$container = $this->boot_container();

		$this->assertTrue( $container->get( 'settings.flag.is-connected' ) );
		$this->assertTrue( $container->get( 'settings.environment' )->is_sandbox() );
		$this->assertTrue( $container->get( 'webhook.is-registered' ) );
	}

	/**
	 * @testdox Should hand the wallet's own values through when the platform does not serve the store.
	 */
	public function test_store_not_served_by_the_platform_keeps_the_wallet_values(): void {
		$container = $this->boot_container();

		$this->assertFalse( $container->get( 'settings.flag.is-connected' ) );
		$this->assertFalse( $container->get( 'settings.environment' )->is_sandbox() );
		$this->assertFalse( $container->get( 'webhook.is-registered' ) );
		$this->assertInstanceOf( ConnectBearer::class, $container->get( 'api.bearer' ) );
		$this->assertContains( self::PAY_LATER_TASK_CONFIG, $container->get( 'wcgateway.settings.wc-tasks.task-config-services' ) );
	}

	/**
	 * Task 4 replaces this with "api.bearer is the ContextBearer".
	 *
	 * @testdox Should build a bearer that is not the connect placeholder, and that asks PayPal for nothing without credentials.
	 */
	public function test_collecting_store_bearer_is_not_the_connect_placeholder(): void {
		$this->set_collecting();

		$bearer = $this->boot_container()->get( 'api.bearer' );

		$this->assertNotInstanceOf( ConnectBearer::class, $bearer, 'The connected flag picks the real bearer' );
		try {
			$bearer->bearer();
			$this->fail( 'A bearer with no merchant credentials must not produce a token' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( array(), $this->http_requests, 'No token request may leave without credentials' );
		}
	}

	/**
	 * @testdox Should keep the SDK v6 buttons off while the platform serves the store, even with the gateway on.
	 */
	public function test_collecting_store_keeps_sdk_v6_buttons_off(): void {
		$this->set_collecting();
		$this->enable_gateway();

		$container = $this->boot_container();

		$this->assertTrue( $container->has( 'sdk-v6.buttons-available' ), 'The SDK v6 module is loaded' );
		$this->assertFalse( $container->get( 'sdk-v6.buttons-available' ) );
	}

	/**
	 * @testdox Should leave the SDK v6 buttons on for a first-party connected store, even with a collecting option left behind.
	 */
	public function test_first_party_store_keeps_sdk_v6_buttons_on(): void {
		$this->set_first_party_connected();
		$this->set_collecting();
		$this->enable_gateway();

		$this->assertTrue( $this->boot_container()->get( 'sdk-v6.buttons-available' ) );
	}

	/**
	 * @testdox Should drop the Pay Later task while the platform serves the store.
	 */
	public function test_collecting_store_drops_the_pay_later_task(): void {
		$this->set_collecting();

		$container = $this->boot_container();

		$this->assertNotContains( self::PAY_LATER_TASK_CONFIG, $container->get( 'wcgateway.settings.wc-tasks.task-config-services' ) );
		$this->assertContains( 'wcgateway.settings.wc-tasks.working-capital-config', $container->get( 'wcgateway.settings.wc-tasks.task-config-services' ), 'Only the Pay Later task is dropped' );
	}

	/**
	 * @testdox Should report no saved PayPal and Venmo while the platform serves the store, without touching the stored setting.
	 */
	public function test_collecting_store_turns_vaulting_off_without_writing(): void {
		$this->set_collecting();
		$this->set_wallet_option( 'woocommerce-ppcp-data-settings', array( 'save_paypal_and_venmo' => true ) );

		$provider = $this->boot_container()->get( 'settings.settings-provider' );

		$this->assertInstanceOf( SettingsProvider::class, $provider );
		$this->assertFalse( $provider->save_paypal_and_venmo() );
		$this->assertFalse( $provider->can_save_vault_token() );
		$this->assertSame( array( 'save_paypal_and_venmo' => true ), get_option( 'woocommerce-ppcp-data-settings' ), 'The merchant\'s stored setting is unchanged' );
	}

	/**
	 * @testdox Should keep the stored saved PayPal and Venmo setting for a first-party connected store.
	 */
	public function test_first_party_store_keeps_vaulting(): void {
		$this->set_first_party_connected();
		$this->set_wallet_option( 'woocommerce-ppcp-data-settings', array( 'save_paypal_and_venmo' => true ) );

		$this->assertTrue( $this->boot_container()->get( 'settings.settings-provider' )->save_paypal_and_venmo() );
	}

	/**
	 * @testdox Should force the capture intent and hide saved PayPal and Venmo through the filters the collecting module adds on a collecting store.
	 */
	public function test_collecting_store_filters_intent_and_features(): void {
		$this->set_collecting();
		$this->boot_container();

		// phpcs:disable WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing the wallet's filters as its call sites do.
		$this->assertSame( 'CAPTURE', apply_filters( 'woocommerce_paypal_payments_order_intent', 'AUTHORIZE' ), 'OrderEndpoint::create() sends the upper-case value' );
		$this->assertSame( 'capture', apply_filters( 'woocommerce_paypal_payments_order_intent', 'authorize' ), 'SmartButton::intent() passes the lower-case value' );
		$features = apply_filters( 'woocommerce_paypal_payments_rest_common_merchant_features', array() );
		// phpcs:enable WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertArrayHasKey( 'save_paypal_and_venmo', $features );
		$this->assertFalse( $features['save_paypal_and_venmo']['enabled'] );
	}

	/**
	 * @testdox Should not auto-register merchant webhooks from admin_init on a collecting store, and write no PayPal option.
	 */
	public function test_collecting_store_skips_webhook_auto_registration(): void {
		$this->set_collecting();
		$this->boot_container();

		$writes   = array();
		$recorder = static function ( $option ) use ( &$writes ): void {
			if ( 0 === strpos( (string) $option, 'ppcp' ) || 0 === strpos( (string) $option, 'woocommerce-ppcp-' ) ) {
				$writes[] = $option;
			}
		};
		add_action( 'add_option', $recorder );
		add_action( 'update_option', $recorder );
		add_action( 'delete_option', $recorder );

		$callbacks = $this->callbacks_owned_by( 'admin_init', WebhookModule::class );
		$this->assertCount( 1, $callbacks, 'The webhook module adds one admin_init callback' );
		foreach ( $callbacks as $callback ) {
			$callback();
		}

		$this->assertFalse( get_transient( 'ppcp_webhook_auto_register_throttle' ), 'The callback returns before it throttles and builds the registrar' );
		$this->assertSame( array(), $writes, 'No PayPal option may be written' );
		$this->assertSame( array(), $this->http_requests, 'Nothing may be sent to PayPal' );
	}

	/**
	 * The callbacks on a hook whose closure was written in the given class.
	 *
	 * @param string $hook       The hook.
	 * @param string $class_name The class the closures belong to.
	 * @return Closure[]
	 */
	private function callbacks_owned_by( string $hook, string $class_name ): array {
		$owned = array();
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( ! $function instanceof Closure ) {
					continue;
				}
				$scope = ( new ReflectionFunction( $function ) )->getClosureScopeClass();
				if ( null !== $scope && $class_name === $scope->getName() ) {
					$owned[] = $function;
				}
			}
		}

		return $owned;
	}
}
