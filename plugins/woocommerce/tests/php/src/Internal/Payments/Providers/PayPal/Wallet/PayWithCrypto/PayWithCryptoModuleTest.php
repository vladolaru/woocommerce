<?php
/**
 * Tests for the Pay with Crypto module's hooks.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PayWithCryptoModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles\ContainerDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Customer;

/**
 * The module registers the gateway on a connected store whose PayPal method is on and whose eligibility check passes,
 * removes it from a non-USD shopper's list, registers the block method, and strips PayPal's token from the Pay with
 * Crypto order-received URL only.
 *
 * @group paypal-wallet
 */
class PayWithCryptoModuleTest extends WalletTestCase {

	/**
	 * The module the last run_module() call ran.
	 *
	 * @var PayWithCryptoModule
	 */
	private PayWithCryptoModule $module;

	/**
	 * The currency option before the test.
	 *
	 * @var mixed
	 */
	private $original_currency;

	/**
	 * The customer of the shop before the test, null when it had none.
	 *
	 * @var WC_Customer|null
	 */
	private ?WC_Customer $original_customer;

	/**
	 * The request URI before the test.
	 *
	 * @var mixed
	 */
	private $original_request_uri;

	/**
	 * The query variables of the main request before the test.
	 *
	 * @var array
	 */
	private array $original_query_vars;

	/**
	 * Remember the currency and the customer the test may replace.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_currency = get_option( 'woocommerce_currency' );
		$customer                = WC()->customer;

		$this->original_customer = $customer instanceof WC_Customer ? $customer : null;

		$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to be put back as it was.
		$this->original_query_vars  = $GLOBALS['wp']->query_vars ?? array();
	}

	/**
	 * Put back the currency, the customer and the request, and drop the flag filter.
	 */
	public function tearDown(): void {
		update_option( 'woocommerce_currency', $this->original_currency );
		WC()->customer = $this->original_customer;
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		$GLOBALS['wp']->query_vars = $this->original_query_vars;
		remove_all_filters( 'woocommerce.feature-flags.woocommerce_paypal_payments.pwc_enabled' );

		parent::tearDown();
	}

	/**
	 * Run the module over a container with the given connection and eligibility answers, after the theme setup.
	 *
	 * @param bool $connected Whether the store is connected.
	 * @param bool $eligible  The answer of the ppcp-pwc eligibility check.
	 * @return PWCGateway The gateway the container serves.
	 */
	private function run_module( bool $connected, bool $eligible ): PWCGateway {
		$gateway     = $this->mock( PWCGateway::class );
		$gateway->id = PWCGateway::ID;

		$provider = $this->mock( SettingsProvider::class );
		$provider->shouldReceive( 'is_method_enabled' )->with( 'ppcp-gateway' )->andReturn( true );

		$eligibilities = $this->mock( PaymentMethodsEligibilityService::class );
		$eligibilities->shouldReceive( 'get_eligibility_checks' )->andReturn( array( PWCGateway::ID => static fn(): bool => $eligible ) );

		$container = new ContainerDouble(
			array(
				'ppcp-local-apms.payment-methods' => array(
					'pwc' => array(
						'id'         => PWCGateway::ID,
						'countries'  => array(),
						'currencies' => array( 'USD' ),
					),
				),
				'ppcp-local-apms.pwc.wc-gateway'  => $gateway,
				'settings.flag.is-connected'      => $connected,
				'settings.settings-provider'      => $provider,
				'settings.service.payment_methods_eligibilities' => $eligibilities,
			)
		);

		$this->module = new PayWithCryptoModule();
		$this->module->run( $container );
		do_action( 'after_setup_theme' );

		return $gateway;
	}

	/**
	 * @testdox Should add the gateway to WooCommerce's list on a connected store whose check passes.
	 */
	public function test_adds_the_gateway_when_connected_and_eligible(): void {
		$gateway = $this->run_module( true, true );

		$this->assertContains( $gateway, apply_filters( 'woocommerce_payment_gateways', array() ) );
	}

	/**
	 * @testdox Should leave WooCommerce's list alone when the eligibility check fails.
	 */
	public function test_skips_the_gateway_when_not_eligible(): void {
		$this->run_module( true, false );

		$this->assertSame( array(), apply_filters( 'woocommerce_payment_gateways', array() ) );
	}

	/**
	 * @testdox Should register no gateway hook on a store that is not connected, outside a REST request.
	 */
	public function test_registers_nothing_when_not_connected(): void {
		$this->run_module( false, true );

		$this->assertSame( array(), apply_filters( 'woocommerce_payment_gateways', array() ) );
		$this->assertFalse( has_action( 'woocommerce_before_thankyou', array( $this->module, 'handle_cancelled_local_apm' ) ), 'The return-path handler is not registered' );
		$this->assertFalse( has_action( 'template_redirect', array( $this->module, 'handle_pwc_order_received_redirect' ) ), 'The order-received redirect is not registered' );
	}

	/**
	 * @testdox Should remove the gateway from a shopper's list when the store currency is not USD.
	 */
	public function test_removes_the_gateway_for_a_non_usd_store(): void {
		$this->run_module( true, true );
		update_option( 'woocommerce_currency', 'EUR' );
		WC()->customer = new WC_Customer( 0 );
		WC()->customer->set_billing_country( 'US' );

		$available = apply_filters(
			'woocommerce_available_payment_gateways',
			array(
				PWCGateway::ID => new \stdClass(),
				'other'        => new \stdClass(),
			)
		);

		$this->assertSame( array( 'other' ), array_keys( $available ) );
	}

	/**
	 * @testdox Should keep the gateway in a USD shopper's list.
	 */
	public function test_keeps_the_gateway_for_a_usd_store(): void {
		$this->run_module( true, true );
		update_option( 'woocommerce_currency', 'USD' );
		WC()->customer = new WC_Customer( 0 );
		WC()->customer->set_billing_country( 'US' );

		$available = apply_filters( 'woocommerce_available_payment_gateways', array( PWCGateway::ID => new \stdClass() ) );

		$this->assertSame( array( PWCGateway::ID ), array_keys( $available ) );
	}

	/**
	 * Both settings of the flag leave "card" alone and bring "pwc" along only from the list the SDK was given: with the
	 * flag on, the priority 11 filter strips "pwc" from the disable-funding list; with it off, the payment methods
	 * filter drops the Pay with Crypto entry (so the module adds no "pwc" of its own) and the "pwc" already in the
	 * list stays.
	 *
	 * @testdox Should strip pwc from the SDK's disable-funding list while the flag is on, and keep it while the flag is off.
	 * @testWith [true, "card"]
	 *           [false, "pwc,card"]
	 *
	 * @param bool   $flag     The pwc_enabled flag.
	 * @param string $expected The disable-funding value.
	 */
	public function test_disable_funding_follows_the_flag( bool $flag, string $expected ): void {
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.pwc_enabled', $flag ? '__return_true' : '__return_false' );
		$this->run_module( true, true );

		$data = apply_filters( 'woocommerce_paypal_payments_localized_script_data', array( 'url_params' => array( 'disable-funding' => 'pwc,card' ) ) );

		$this->assertSame( $expected, $data['url_params']['disable-funding'] );
	}

	/**
	 * @testdox Should drop the Pay with Crypto entry from the payment methods while the flag is off, and keep it while the flag is on.
	 * @testWith [true, 1]
	 *           [false, 0]
	 *
	 * @param bool $flag  The pwc_enabled flag.
	 * @param int  $count The number of payment methods left.
	 */
	public function test_payment_methods_follow_the_flag( bool $flag, int $count ): void {
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.pwc_enabled', $flag ? '__return_true' : '__return_false' );
		$this->run_module( false, true );

		$methods = apply_filters(
			'woocommerce_paypal_payments_local_apm_payment_methods', // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment -- The extension's filter.
			array( 'pwc' => array( 'id' => PWCGateway::ID ) )
		);

		$this->assertCount( $count, $methods );
	}

	/**
	 * Make the main request an order-received page for the order, with PayPal's token on the URL.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function request_order_received_with_token( \WC_Order $order ): void {
		$_SERVER['REQUEST_URI']                      = '/checkout/order-received/' . $order->get_id() . '/?token=PAYPAL-TOKEN';
		$GLOBALS['wp']->query_vars['order-received'] = (string) $order->get_id();
	}

	/**
	 * Run the order-received handler and say where it sent the shopper.
	 *
	 * @return string|null The redirect location, null when the handler did not redirect.
	 */
	private function redirect_target_of_order_received_handler(): ?string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . esc_url_raw( (string) $location ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);

		try {
			( new PayWithCryptoModule() )->handle_pwc_order_received_redirect();
		} catch ( \RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}

		return null;
	}

	/**
	 * @testdox Should send the shopper from a Pay with Crypto order-received URL carrying PayPal's token to the clean URL.
	 */
	public function test_strips_the_token_from_a_pwc_order_received_url(): void {
		$order = wc_create_order();
		$order->set_payment_method( PWCGateway::ID );
		$order->save();
		$this->request_order_received_with_token( $order );

		$this->assertSame( esc_url_raw( $order->get_checkout_order_received_url() ), $this->redirect_target_of_order_received_handler() );
	}

	/**
	 * @testdox Should leave the token of any other gateway's order-received URL alone.
	 */
	public function test_leaves_the_token_of_other_gateways_alone(): void {
		$order = wc_create_order();
		$order->set_payment_method( 'ppcp-gateway' );
		$order->save();
		$this->request_order_received_with_token( $order );

		$this->assertNull( $this->redirect_target_of_order_received_handler() );
	}

	/**
	 * @testdox Should fail an on-hold Pay with Crypto order when the shopper comes back from a cancelled payment.
	 */
	public function test_fails_an_on_hold_order_after_a_cancelled_payment(): void {
		$this->run_module( true, true );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$order = wc_create_order();
		$order->set_payment_method( PWCGateway::ID );
		$order->set_status( 'on-hold' );
		$order->save();
		$_SERVER['REQUEST_URI'] = '/checkout/order-received/' . $order->get_id() . '/?cancelled=true&key=' . $order->get_order_key();

		$this->module->handle_cancelled_local_apm( $order->get_id() );

		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should send the shopper of a store installed in a subdirectory to the order-received URL, without the cancelled argument and with the subdirectory once.
	 */
	public function test_redirects_a_cancelled_payment_to_the_order_received_url_on_a_subdirectory_install(): void {
		$this->run_module( true, true );
		$home = static fn(): string => 'http://example.org/shop';
		add_filter( 'option_home', $home );
		add_filter( 'option_siteurl', $home );
		$order = wc_create_order();
		$order->set_payment_method( PWCGateway::ID );
		$order->set_status( 'on-hold' );
		$order->save();
		$_SERVER['REQUEST_URI'] = '/shop/checkout/order-received/' . $order->get_id() . '/?cancelled=true&key=' . $order->get_order_key();
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( 'redirect:' . (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);

		$target = null;
		try {
			$this->module->handle_cancelled_local_apm( $order->get_id() );
		} catch ( \RuntimeException $e ) {
			$target = substr( $e->getMessage(), strlen( 'redirect:' ) );
		}

		$this->assertNotNull( $target, 'The handler redirects' );
		$this->assertSame( $order->get_checkout_order_received_url(), $target );
		$this->assertStringStartsWith( 'http://example.org/shop', $target );
		$this->assertSame( 1, substr_count( $target, '/shop' ), 'The subdirectory appears once' );
		$this->assertStringNotContainsString( 'cancelled', $target );
	}

	/**
	 * @testdox Should leave an on-hold Pay with Crypto order alone when the cancelled URL carries another order's key.
	 */
	public function test_keeps_the_order_when_the_key_does_not_match(): void {
		$this->run_module( true, true );
		$order = wc_create_order();
		$order->set_payment_method( PWCGateway::ID );
		$order->set_status( 'on-hold' );
		$order->save();
		$_SERVER['REQUEST_URI'] = '/checkout/order-received/' . $order->get_id() . '/?cancelled=true&key=wc_order_other';

		$this->module->handle_cancelled_local_apm( $order->get_id() );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}
}
