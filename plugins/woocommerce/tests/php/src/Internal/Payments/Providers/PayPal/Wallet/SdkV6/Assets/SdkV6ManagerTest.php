<?php
/**
 * Tests for the v6 SDK manager.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\DisabledFundingSources;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreatePaymentTokenForGuest;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint\CreateSetupToken;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\SdkV6Manager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\ButtonStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessagesEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessageStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelView;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery;
use Mockery\MockInterface;
use WC_Cart;
use WC_Helper_Product;

/**
 * The SDK bootstrap data, the page-loading rules and the message hooks of the v6 manager, over real WordPress and
 * WooCommerce: only the manager's collaborators and the cart, customer and countries objects are doubles.
 *
 * The four free-trial product cases of the extension test that drove WooCommerce Subscriptions' static classes through
 * Mockery aliases are not ported: core's suite loads neither the classes nor an alias-safe way to define them. The two
 * render-places cases that need only the answer use SdkV6ManagerFreeTrialStub.
 *
 * @group paypal-wallet
 */
class SdkV6ManagerTest extends WalletTestCase {

	/**
	 * The asset getter mock.
	 *
	 * @var AssetGetter&MockInterface
	 */
	private $asset_getter;

	/**
	 * The environment mock.
	 *
	 * @var Environment&MockInterface
	 */
	private $environment;

	/**
	 * The button style mapper mock.
	 *
	 * @var ButtonStyleMapper&MockInterface
	 */
	private $style_mapper;

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * The button context mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The cancellation view mock.
	 *
	 * @var CancelView&MockInterface
	 */
	private $cancel_view;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * The free trial helper mock.
	 *
	 * @var FreeTrialSubscriptionHelper&MockInterface
	 */
	private $free_trial_helper;

	/**
	 * The message style mapper mock.
	 *
	 * @var MessageStyleMapper&MockInterface
	 */
	private $message_style_mapper;

	/**
	 * The messages eligibility mock.
	 *
	 * @var MessagesEligibility&MockInterface
	 */
	private $messages_eligibility;

	/**
	 * The v5 disabled-funding helper mock.
	 *
	 * @var DisabledFundingSources&MockInterface
	 */
	private $disabled_funding_sources;

	/**
	 * The WooCommerce cart, customer and countries objects before the test.
	 *
	 * @var array
	 */
	private $original_wc = array();

	/**
	 * The WordPress query variables before the test.
	 *
	 * @var mixed
	 */
	private $original_query_vars;

	/**
	 * Build the collaborators with answers that keep every scenario neutral until it says otherwise.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_wc         = array(
			'cart'      => WC()->cart,
			'customer'  => WC()->customer,
			'countries' => WC()->countries,
		);
		$this->original_query_vars = $GLOBALS['wp']->query_vars ?? null;

		update_option( 'woocommerce_default_country', 'US:CA' );
		WC()->cart      = null;
		WC()->customer  = null;
		WC()->countries = null;

		$this->asset_getter    = $this->mock( AssetGetter::class );
		$this->environment     = $this->mock( Environment::class );
		$this->style_mapper    = $this->mock( ButtonStyleMapper::class );
		$this->settings_status = $this->mock( SettingsStatus::class );
		// script_data() asks for this on every resolved page context.
		$this->settings_status->shouldReceive( 'is_pay_later_button_enabled_for_location' )->andReturn( false )->byDefault();
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( false )->byDefault();
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->andReturn( false )->byDefault();
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled' )->andReturn( false )->byDefault();
		$this->context         = $this->mock( Context::class );
		$this->session_handler = $this->mock( SessionHandler::class );
		$this->session_handler->shouldReceive( 'order' )->andReturn( null )->byDefault();
		$this->cancel_view = $this->mock( CancelView::class );

		$this->subscription_helper = $this->mock( SubscriptionHelper::class );
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( false )->byDefault();
		$this->subscription_helper->shouldReceive( 'current_product_is_subscription' )->andReturn( false )->byDefault();
		$this->subscription_helper->shouldReceive( 'order_pay_contains_subscription' )->andReturn( false )->byDefault();
		$this->subscription_helper->shouldReceive( 'accept_manual_renewals' )->andReturn( false )->byDefault();

		$this->free_trial_helper = $this->mock( FreeTrialSubscriptionHelper::class );
		$this->free_trial_helper->shouldReceive( 'is_free_trial_cart' )->andReturn( false )->byDefault();
		$this->free_trial_helper->shouldReceive( 'cart_requires_vaulting' )->andReturn( false )->byDefault();

		$this->message_style_mapper = $this->mock( MessageStyleMapper::class );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->andReturn(
			array(
				'logoType'     => 'WORDMARK',
				'logoPosition' => 'LEFT',
				'textColor'    => 'BLACK',
				'fontSize'     => '',
			)
		)->byDefault();

		$this->messages_eligibility = $this->mock( MessagesEligibility::class );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->andReturn( false )->byDefault();
		$this->messages_eligibility->shouldReceive( 'is_hidden' )->andReturn( false )->byDefault();

		$this->disabled_funding_sources = $this->mock( DisabledFundingSources::class );
		$this->disabled_funding_sources->shouldReceive( 'get_sources_from_settings' )->andReturn( array() )->byDefault();

		$this->context->shouldReceive( 'location' )->andReturn( '' )->byDefault();
		$this->context->shouldReceive( 'context' )->andReturn( 'checkout' )->byDefault();
		$this->context->shouldReceive( 'is_paypal_continuation' )->andReturn( false )->byDefault();
		$this->environment->shouldReceive( 'is_sandbox' )->andReturn( false )->byDefault();
		$this->style_mapper->shouldReceive( 'styles_for_context' )->andReturn(
			array(
				'colorClass'   => 'paypal-gold',
				'borderRadius' => '24px',
			)
		)->byDefault();
	}

	/**
	 * Put WooCommerce, the query variables and the request back.
	 */
	public function tearDown(): void {
		try {
			WC()->cart      = $this->original_wc['cart'];
			WC()->customer  = $this->original_wc['customer'];
			WC()->countries = $this->original_wc['countries'];
			if ( isset( $GLOBALS['wp'] ) ) {
				$GLOBALS['wp']->query_vars = $this->original_query_vars;
			}
			unset( $GLOBALS['post'] );
			wp_deregister_script( 'wc-ppcp-sdk-v6-boot' );
			wp_dequeue_script( 'wc-ppcp-sdk-v6-boot' );
			wp_deregister_style( 'wc-ppcp-sdk-v6-gateway' );
			wp_dequeue_style( 'wc-ppcp-sdk-v6-gateway' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the manager.
	 *
	 * @param bool   $final_review_enabled Whether the final review step is on.
	 * @param string $class_name           The class to build.
	 * @param bool   $vaulting_enabled     Whether "Save PayPal and Venmo" is on.
	 * @param bool   $buttons_available    Whether the merchant is connected and the PayPal gateway is on.
	 * @return SdkV6Manager
	 */
	private function create_sut(
		bool $final_review_enabled = false,
		string $class_name = SdkV6Manager::class,
		bool $vaulting_enabled = false,
		bool $buttons_available = true
	): SdkV6Manager {
		return new $class_name(
			$this->asset_getter,
			'1.0.0',
			$this->environment,
			$this->style_mapper,
			$this->settings_status,
			$this->context,
			$this->session_handler,
			$this->cancel_view,
			$final_review_enabled,
			$vaulting_enabled,
			$this->subscription_helper,
			$this->free_trial_helper,
			$this->message_style_mapper,
			$this->messages_eligibility,
			$this->disabled_funding_sources,
			$buttons_available
		);
	}

	/**
	 * Resolve the page context and messaging location the way the real Context helper would.
	 *
	 * @param string $page_context The page context.
	 * @param string $location     The messaging location.
	 */
	private function stub_page( string $page_context, string $location = '' ): void {
		$this->context->shouldReceive( 'context' )->andReturn( $page_context );
		$this->context->shouldReceive( 'location' )->andReturn( $location );
	}

	/**
	 * Turn the smart buttons on or off for every location.
	 *
	 * @param bool $enabled Whether they are enabled.
	 */
	private function stub_buttons_everywhere( bool $enabled ): void {
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( $enabled );
	}

	/**
	 * Replace the WooCommerce cart by a double.
	 *
	 * @param array<string, mixed> $answers Method name to return value.
	 * @return MockInterface
	 */
	private function stub_cart( array $answers ): MockInterface {
		$cart = Mockery::mock( WC_Cart::class );
		foreach ( $answers as $method => $answer ) {
			if ( 'get_total' === $method ) {
				$cart->shouldReceive( 'get_total' )->with( 'edit' )->andReturn( $answer );
				continue;
			}
			$cart->shouldReceive( $method )->andReturn( $answer );
		}
		WC()->cart = $cart;

		return $cart;
	}

	/**
	 * Point the request at a pay-for-order page for a new order.
	 *
	 * @param string $total The order total.
	 * @return \WC_Order
	 */
	private function stub_pay_for_order_page( string $total ): \WC_Order {
		$order = wc_create_order();
		$order->set_total( $total );
		$order->save();

		$GLOBALS['wp']->query_vars['order-pay'] = $order->get_id();
		$_GET['key']                            = $order->get_order_key();

		return $order;
	}

	/**
	 * Make a real simple product the current product.
	 *
	 * @param array $props Product properties.
	 * @return \WC_Product
	 */
	private function stub_current_product( array $props = array() ): \WC_Product {
		$product = WC_Helper_Product::create_simple_product( true, $props );
		$this->go_to( get_permalink( $product->get_id() ) );

		return $product;
	}

	/**
	 * Read what enqueue()-less callers need: the SDK bootstrap data for the current stubs.
	 *
	 * @param SdkV6Manager|null $sut The manager, or a default one.
	 * @return array
	 */
	private function script_data( ?SdkV6Manager $sut = null ): array {
		return ( $sut ?? $this->create_sut() )->script_data();
	}

	/**
	 * @testdox Should gate the render places by whether the cart needs payment: $scenario.
	 * @dataProvider render_places_needs_payment_provider
	 *
	 * @param string    $scenario           The scenario name.
	 * @param bool|null $cart_needs_payment Whether the cart needs payment, null for no cart.
	 * @param array     $expected           The render places.
	 */
	public function test_determine_render_places_gated_by_cart_needs_payment( string $scenario, ?bool $cart_needs_payment, array $expected ): void {
		unset( $scenario );
		$this->context->shouldReceive( 'init_context' )->never();
		$this->stub_buttons_everywhere( true );
		if ( null !== $cart_needs_payment ) {
			$this->stub_cart( array( 'needs_payment' => $cart_needs_payment ) );
		}

		$this->assertSame( $expected, $this->create_sut()->determine_render_places() );
	}

	/**
	 * The mini-cart fragments of an AJAX add-to-cart are rendered after the product is added, so the wrapper decides
	 * from the cart at that moment.
	 *
	 * @testdox Should print the mini-cart wrapper only when the cart needs payment: $needs_payment.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $needs_payment Whether the cart needs payment when the mini-cart renders.
	 */
	public function test_render_mini_cart_wrapper_checks_the_cart_when_it_renders( bool $needs_payment ): void {
		$this->stub_cart( array( 'needs_payment' => $needs_payment ) );

		ob_start();
		$this->create_sut()->render_mini_cart_wrapper();
		$html = (string) ob_get_clean();

		$this->assertSame( $needs_payment, str_contains( $html, SdkV6Manager::MINI_CART_WRAPPER_ID ) );
	}

	/**
	 * Scenarios of the cart's payment need.
	 *
	 * @return array
	 */
	public function render_places_needs_payment_provider(): array {
		return array(
			'cart needing payment enables cart, checkout and mini-cart' => array(
				'needs payment',
				true,
				array(
					'product'   => true,
					'cart'      => true,
					'checkout'  => true,
					'pay-now'   => true,
					'mini-cart' => true,
				),
			),
			// The mini-cart hook stays registered: an AJAX add-to-cart fills the
			// cart after the hooks are placed, so the wrapper checks the cart itself.
			'zero-total cart suppresses cart and checkout, not the mini-cart hook' => array(
				'zero total',
				false,
				array(
					'product'   => true,
					'cart'      => false,
					'checkout'  => false,
					'pay-now'   => true,
					'mini-cart' => true,
				),
			),
			'no cart present is treated as needing payment'            => array(
				'no cart',
				null,
				array(
					'product'   => true,
					'cart'      => true,
					'checkout'  => true,
					'pay-now'   => true,
					'mini-cart' => true,
				),
			),
		);
	}

	/**
	 * @testdox Should keep the checkout on a free-trial cart that needs no payment only when it is a free trial: needs payment $needs_payment, free trial $is_free_trial_cart.
	 * @dataProvider free_trial_checkout_provider
	 *
	 * @param bool $needs_payment      Whether the cart needs payment.
	 * @param bool $is_free_trial_cart Whether the cart is a free trial.
	 * @param bool $expected_checkout  Whether the checkout renders.
	 */
	public function test_determine_render_places_checkout_on_free_trial_cart( bool $needs_payment, bool $is_free_trial_cart, bool $expected_checkout ): void {
		$this->stub_buttons_everywhere( true );
		$this->free_trial_helper->shouldReceive( 'is_free_trial_cart' )->andReturn( $is_free_trial_cart );
		$this->stub_cart( array( 'needs_payment' => $needs_payment ) );

		$result = $this->create_sut()->determine_render_places();

		$this->assertSame( $expected_checkout, $result['checkout'] );
		$this->assertSame( $needs_payment, $result['cart'] );
		$this->assertTrue( $result['mini-cart'], 'The mini-cart hook follows its location; its wrapper checks the cart' );
	}

	/**
	 * Cart payment need, free trial flag and the checkout answer.
	 *
	 * @return array
	 */
	public function free_trial_checkout_provider(): array {
		return array(
			'free-trial cart needing no payment still enables checkout' => array( false, true, true ),
			'zero-total non-free-trial cart keeps checkout suppressed'  => array( false, false, false ),
			'ordinary cart needing payment enables checkout'            => array( true, false, true ),
		);
	}

	/**
	 * Turning PayPal Wallet off on the Payments list, or a lost connection, must take it off every page, even though
	 * the saved button locations and Pay Later messaging stay on.
	 *
	 * @testdox Should not load the SDK when the PayPal gateway is off or the merchant is not connected, whatever the locations say.
	 */
	public function test_should_not_load_when_buttons_are_not_available(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->stub_buttons_everywhere( true );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->andReturn( true );

		$this->assertFalse( $this->create_sut( false, SdkV6Manager::class, false, false )->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not place any button wrapper when the PayPal gateway is off or the merchant is not connected.
	 */
	public function test_determine_render_places_empty_when_buttons_are_not_available(): void {
		$this->stub_buttons_everywhere( true );
		$this->stub_cart( array( 'needs_payment' => true ) );

		$this->assertSame(
			array(
				'product'   => false,
				'cart'      => false,
				'checkout'  => false,
				'pay-now'   => false,
				'mini-cart' => false,
			),
			$this->create_sut( false, SdkV6Manager::class, false, false )->determine_render_places()
		);
	}

	/**
	 * @testdox Should load the SDK sitewide when the mini-cart location is on, without the classic widget.
	 */
	public function test_should_load_sitewide_when_mini_cart_enabled_regardless_of_widget(): void {
		$this->stub_page( '' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'mini-cart' )->andReturn( true );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not load the SDK when the mini-cart is off and the page has no context.
	 */
	public function test_should_not_load_when_mini_cart_disabled_and_no_matching_page_context(): void {
		$this->stub_page( '', '' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'mini-cart' )->andReturn( false );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( '' )->andReturn( false );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not load the SDK when a subscription cart cannot be vaulted and manual renewals are off.
	 */
	public function test_should_not_load_when_subscription_in_cart_without_vaulting(): void {
		$this->stub_page( 'checkout' );
		$this->stub_buttons_everywhere( true );
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( true );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should load the SDK without vaulting when no subscription is present.
	 */
	public function test_should_load_without_vaulting_when_no_subscription_present(): void {
		$this->stub_page( 'checkout' );
		$this->stub_buttons_everywhere( true );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should load the SDK with vaulting on and a subscription in the cart.
	 */
	public function test_should_load_when_vaulting_with_subscription_in_cart(): void {
		$this->stub_page( 'checkout' );
		$this->stub_buttons_everywhere( true );
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( true );

		$this->assertTrue( $this->create_sut( false, SdkV6Manager::class, true )->should_load_on_current_page() );
	}

	/**
	 * @testdox Should load the SDK with manual renewals on, vaulting off and a subscription in the cart.
	 */
	public function test_should_load_when_manual_renewals_with_subscription_in_cart(): void {
		$this->stub_page( 'checkout' );
		$this->stub_buttons_everywhere( true );
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( true );
		$this->subscription_helper->shouldReceive( 'accept_manual_renewals' )->andReturn( true );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should load the SDK on a subscription page without vaulting when the subscription mode filter opts out.
	 */
	public function test_should_load_when_subscription_mode_filter_opts_out(): void {
		$this->stub_page( 'checkout' );
		$this->stub_buttons_everywhere( true );
		$this->subscription_helper->shouldReceive( 'cart_contains_subscription' )->andReturn( true );
		add_filter( 'woocommerce_paypal_payments_subscription_mode_disabled', '__return_true' );

		try {
			$loads = $this->create_sut()->should_load_on_current_page();
		} finally {
			remove_filter( 'woocommerce_paypal_payments_subscription_mode_disabled', '__return_true' );
		}

		$this->assertTrue( $loads );
	}

	/**
	 * @testdox Should render no v6 location when a subscription product cannot be vaulted and manual renewals are off.
	 */
	public function test_determine_render_places_empty_when_subscription_product_without_vaulting(): void {
		$this->context->shouldReceive( 'init_context' )->never();
		$this->stub_buttons_everywhere( true );
		$this->subscription_helper->shouldReceive( 'current_product_is_subscription' )->andReturn( true );

		$this->assertSame(
			array(
				'product'   => false,
				'cart'      => false,
				'checkout'  => false,
				'pay-now'   => false,
				'mini-cart' => false,
			),
			$this->create_sut()->determine_render_places()
		);
	}

	/**
	 * @testdox Should forward the pay-now order ID and key and disable shipping on the pay-for-order page.
	 */
	public function test_script_data_includes_pay_now_identifiers(): void {
		$order = $this->stub_pay_for_order_page( '49.99' );
		$this->stub_page( 'pay-now' );

		$data = $this->script_data();

		$this->assertSame(
			array(
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			$data['pay_now']
		);
		$this->assertSame( '49.99', $data['amount'] );
		$this->assertFalse( $data['shipping']['in_context']['pay-now'] );
	}

	/**
	 * @testdox Should not consult Pay Later messaging when deciding whether a block page loads the SDK: $page_context.
	 * @dataProvider block_context_provider
	 *
	 * @param string $page_context The block page context.
	 */
	public function test_should_load_on_current_page_in_block_contexts_never_consults_messaging_eligibility( string $page_context ): void {
		$this->stub_page( $page_context, $page_context );

		// A fresh mock: the default one answers every call, so it could not tell a consulted lookup from a skipped one.
		$this->messages_eligibility = $this->mock( MessagesEligibility::class );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->never();

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * Block page contexts and their messaging locations.
	 *
	 * @return array
	 */
	public function block_context_provider(): array {
		return array(
			'cart block'     => array( 'cart-block', 'cart' ),
			'checkout block' => array( 'checkout-block', 'checkout' ),
		);
	}

	/**
	 * @testdox Should load the SDK when the button location is enabled.
	 */
	public function test_should_load_on_current_page_true_when_button_location_enabled(): void {
		$this->stub_page( 'checkout' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'checkout' )->andReturn( true );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should load the SDK on a classic page only to render a Pay Later message.
	 */
	public function test_should_load_on_current_page_true_when_only_messaging_is_enabled_on_a_classic_page(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( 'checkout' )->andReturn( true );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not load the SDK through messaging on a block page, even when messaging is eligible: $page_context.
	 * @dataProvider block_context_provider
	 *
	 * @param string $page_context        The block page context.
	 * @param string $normalized_location The messaging location it normalizes to.
	 */
	public function test_should_load_on_current_page_false_in_block_contexts_even_when_messaging_enabled( string $page_context, string $normalized_location ): void {
		$this->stub_page( $page_context, $page_context );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( $normalized_location )->andReturn( true );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not load the SDK on "$location" because this module places no message there.
	 * @dataProvider unsupported_message_location_provider
	 *
	 * @param string $location The messaging location.
	 */
	public function test_should_load_on_current_page_false_when_messages_render_hook_is_null( string $location ): void {
		$this->stub_page( '', $location );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->andReturn( false );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->andReturn( true );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * Locations the module places no message on.
	 *
	 * @return array
	 */
	public function unsupported_message_location_provider(): array {
		return array(
			'shop page'   => array( 'shop' ),
			'home page'   => array( 'home' ),
			'no location' => array( '' ),
		);
	}

	/**
	 * @testdox Should load the SDK on "$location" when a Pay Later block sits on that page.
	 * @dataProvider unsupported_message_location_provider
	 *
	 * @param string $location The messaging location.
	 */
	public function test_should_load_on_current_page_true_when_pay_later_block_sits_on_an_unsupported_message_location( string $location ): void {
		$this->stub_page( '', $location );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'custom_placement' )->andReturn( true );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( 'custom_placement' )->andReturn( true );

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_content' => '<!-- wp:woocommerce-paypal-payments/paylater-messages /-->' ) ) ) );

		$this->assertTrue( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should not load the SDK on "$location" when no Pay Later block sits on the page.
	 * @dataProvider unsupported_message_location_provider
	 *
	 * @param string $location The messaging location.
	 */
	public function test_should_load_on_current_page_false_when_no_pay_later_block_sits_on_an_unsupported_message_location( string $location ): void {
		$this->stub_page( '', $location );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'custom_placement' )->andReturn( true );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( $location )->andReturn( false );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( '' )->andReturn( true );

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_content' => 'No block here.' ) ) ) );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * @testdox Should claim "$location" when v5 would render a message there and leave it alone otherwise: messaging $messaging_enabled.
	 * @dataProvider home_shop_messaging_claim_provider
	 *
	 * @param string $location          The messaging location.
	 * @param bool   $messaging_enabled Whether v5 messaging is enabled there.
	 * @param bool   $expected          Whether the SDK loads.
	 */
	public function test_should_load_on_current_page_claims_home_and_shop_where_v5_would_otherwise_render_a_message( string $location, bool $messaging_enabled, bool $expected ): void {
		$this->stub_page( '', $location );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->andReturn( false )->byDefault();
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( $location )->andReturn( $messaging_enabled );

		$this->assertSame( $expected, $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * Locations, messaging flag and the expectation.
	 *
	 * @return array
	 */
	public function home_shop_messaging_claim_provider(): array {
		return array(
			'home page is claimed when v5 has a message there' => array( 'home', true, true ),
			'shop page is claimed when v5 has a message there' => array( 'shop', true, true ),
			'home page is left alone when v5 has no message'   => array( 'home', false, false ),
			'shop page is left alone when v5 has no message'   => array( 'shop', false, false ),
		);
	}

	/**
	 * @testdox Should not load the SDK in wp-admin even when messaging is eligible.
	 */
	public function test_should_load_on_current_page_false_under_is_admin(): void {
		$original_screen = $GLOBALS['current_screen'] ?? null;
		set_current_screen( 'dashboard' );
		$this->stub_page( 'checkout', 'checkout' );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( 'checkout' )->andReturn( true );

		try {
			$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
		} finally {
			$GLOBALS['current_screen'] = $original_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		}
	}

	/**
	 * @testdox Should look messaging up under the normalized location $expected_location for $raw_location.
	 * @dataProvider block_location_normalization_provider
	 *
	 * @param string $raw_location      The page's location.
	 * @param string $expected_location The messaging settings location.
	 */
	public function test_messages_enabled_normalizes_block_locations_for_eligibility_lookup( string $raw_location, string $expected_location ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $raw_location );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->once()->with( $expected_location )->andReturn( true );

		$this->assertTrue( $this->create_sut()->messages_enabled() );
	}

	/**
	 * Raw and normalized locations.
	 *
	 * @return array
	 */
	public function block_location_normalization_provider(): array {
		return array(
			'checkout-block normalizes to checkout, not checkout-block-express' => array( 'checkout-block', 'checkout' ),
			'cart-block normalizes to cart'  => array( 'cart-block', 'cart' ),
			'pay-now normalizes to checkout' => array( 'pay-now', 'checkout' ),
		);
	}

	/**
	 * @testdox Should keep all seven message keys in the data even when messaging is disabled.
	 */
	public function test_script_data_messages_shape_includes_all_keys_even_when_disabled(): void {
		$this->stub_page( 'checkout', 'checkout' );

		$data = $this->script_data();

		$this->assertSame(
			array( 'enabled', 'wrapper', 'is_hidden', 'amount', 'page_type', 'style', 'use_cart_simulation' ),
			array_keys( $data['messages'] )
		);
		$this->assertFalse( $data['messages']['enabled'] );
	}

	/**
	 * @testdox Should default the message cart simulation flag to false.
	 */
	public function test_script_data_messages_use_cart_simulation_defaults_to_false(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->with( 'checkout' )->andReturn( array() );

		$data = $this->script_data();

		$this->assertFalse( $data['messages']['use_cart_simulation'] );
	}

	/**
	 * @testdox Should report the message cart simulation flag as a real boolean when a filter turns it on.
	 */
	public function test_script_data_messages_use_cart_simulation_true_when_filter_enables_it(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->with( 'checkout' )->andReturn( array() );
		add_filter( 'woocommerce_paypal_payments_sdk_v6_messages_use_cart_simulation', static fn() => 'yes' );

		$data = $this->script_data();

		$this->assertTrue( $data['messages']['use_cart_simulation'] );
	}

	/**
	 * @testdox Should price the Pay Later message from the product on a product page while the amount stays cart-first.
	 */
	public function test_messages_amount_is_product_first_on_product_page_even_with_non_empty_cart(): void {
		$this->stub_page( 'product', 'product' );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->andReturn( array() );
		$this->stub_current_product( array( 'regular_price' => '29.99' ) );
		$this->stub_cart(
			array(
				'is_empty'       => false,
				'get_total'      => '99.99',
				'needs_shipping' => false,
			)
		);

		$data = $this->script_data();

		$this->assertSame( '29.99', $data['messages']['amount'] );
		$this->assertSame( '99.99', $data['amount'] );
	}

	/**
	 * @testdox Should price the Pay Later message from the validated order total on the pay-for-order page.
	 */
	public function test_messages_amount_uses_validated_order_total_on_pay_now_page(): void {
		$this->stub_pay_for_order_page( '150.00' );
		$this->stub_page( 'pay-now', 'pay-now' );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->andReturn( array() );

		$data = $this->script_data();

		$this->assertSame( '150.00', $data['messages']['amount'] );
	}

	/**
	 * @testdox Should fall back to an empty message amount when no product, cart or order is available.
	 */
	public function test_messages_amount_falls_back_to_empty_string_when_nothing_is_available(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->message_style_mapper->shouldReceive( 'styles_for_location' )->andReturn( array() );

		$data = $this->script_data();

		$this->assertSame( '', $data['messages']['amount'] );
	}

	/**
	 * @testdox Should return the documented message render hook and priority for $location.
	 * @dataProvider default_messages_render_hook_provider
	 *
	 * @param string $location          The location.
	 * @param string $expected_name     The hook.
	 * @param int    $expected_priority The priority.
	 */
	public function test_messages_render_hook_returns_documented_defaults( string $location, string $expected_name, int $expected_priority ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );

		$this->assertSame(
			array(
				'name'     => $expected_name,
				'priority' => $expected_priority,
			),
			$this->create_sut()->messages_render_hook()
		);
	}

	/**
	 * Locations and their default hooks.
	 *
	 * @return array
	 */
	public function default_messages_render_hook_provider(): array {
		return array(
			'checkout' => array( 'checkout', 'woocommerce_review_order_before_payment', 10 ),
			'cart'     => array( 'cart', 'woocommerce_proceed_to_checkout', 19 ),
			'product'  => array( 'product', 'woocommerce_single_product_summary', 30 ),
			'pay-now'  => array( 'pay-now', 'woocommerce_pay_order_before_submit', 10 ),
		);
	}

	/**
	 * @testdox Should return no message render hook for "$location" so v5 keeps the page.
	 * @dataProvider unsupported_message_location_provider
	 *
	 * @param string $location The location.
	 */
	public function test_messages_render_hook_returns_null_for_pages_this_module_does_not_serve( string $location ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );

		$this->assertNull( $this->create_sut()->messages_render_hook() );
	}

	/**
	 * @testdox Should return no message render hook on the block location $location.
	 * @dataProvider block_context_provider
	 *
	 * @param string $location The block location.
	 */
	public function test_messages_render_hook_returns_null_for_block_locations( string $location ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );

		$this->assertNull( $this->create_sut()->messages_render_hook() );
	}

	/**
	 * @testdox Should let the per-location filters override the message hook and priority for $location.
	 * @dataProvider render_hook_filter_provider
	 *
	 * @param string $location       The location.
	 * @param string $filter_segment The filter name segment.
	 */
	public function test_messages_render_hook_is_overridden_by_per_location_filters( string $location, string $filter_segment ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );
		add_filter( "woocommerce_paypal_payments_{$filter_segment}_messages_renderer_hook", static fn() => 'custom_hook' );
		add_filter( "woocommerce_paypal_payments_{$filter_segment}_messages_renderer_priority", static fn() => 99 );

		$this->assertSame(
			array(
				'name'     => 'custom_hook',
				'priority' => 99,
			),
			$this->create_sut()->messages_render_hook()
		);
	}

	/**
	 * Locations and their filter segments.
	 *
	 * @return array
	 */
	public function render_hook_filter_provider(): array {
		return array(
			'checkout uses the checkout filter segment' => array( 'checkout', 'checkout' ),
			'cart uses the cart filter segment'         => array( 'cart', 'cart' ),
			'product uses the product filter segment'   => array( 'product', 'product' ),
			'pay-now uses the pay_order filter segment, not pay-now' => array( 'pay-now', 'pay_order' ),
		);
	}

	/**
	 * @testdox Should default the message hook to the relocated button hook for $location.
	 * @dataProvider relocated_button_hook_provider
	 *
	 * @param string $location          The location.
	 * @param string $relocation_filter The button relocation filter.
	 * @param string $relocated_hook    The relocated hook.
	 */
	public function test_messages_render_hook_default_uses_relocated_button_hook_first( string $location, string $relocation_filter, string $relocated_hook ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );
		add_filter(
			$relocation_filter,
			static function () use ( $relocated_hook ) {
				return $relocated_hook;
			}
		);

		$hook = $this->create_sut()->messages_render_hook();

		$this->assertSame( $relocated_hook, $hook['name'] );
	}

	/**
	 * Locations and their relocation filters.
	 *
	 * @return array
	 */
	public function relocated_button_hook_provider(): array {
		return array(
			'cart passes through the proceed-to-checkout button relocation filter first' => array(
				'cart',
				'woocommerce_paypal_payments_proceed_to_checkout_button_renderer_hook',
				'my_custom_proceed_hook',
			),
			'product passes through the single-product button relocation filter first'   => array(
				'product',
				'woocommerce_paypal_payments_single_product_renderer_hook',
				'my_custom_product_hook',
			),
		);
	}

	/**
	 * @testdox Should echo the message wrapper between the before and after actions for $location.
	 * @dataProvider render_message_wrapper_provider
	 *
	 * @param string $location       The location.
	 * @param string $action_segment The action name segment.
	 */
	public function test_render_message_wrapper_echoes_wrapper_between_before_and_after_actions( string $location, string $action_segment ): void {
		$this->context->shouldReceive( 'location' )->andReturn( $location );
		$order = array();
		add_action(
			"ppcp_before_{$action_segment}_message_wrapper",
			static function () use ( &$order ) {
				$order[] = 'before';
			}
		);
		add_action(
			"ppcp_after_{$action_segment}_message_wrapper",
			static function () use ( &$order ) {
				$order[] = 'after';
			}
		);

		ob_start();
		$this->create_sut()->render_message_wrapper();
		$output = ob_get_clean();

		$this->assertSame( '<div class="ppcp-messages"></div>', $output );
		$this->assertSame( array( 'before', 'after' ), $order );
	}

	/**
	 * Locations and their action segments.
	 *
	 * @return array
	 */
	public function render_message_wrapper_provider(): array {
		return array(
			'checkout'                       => array( 'checkout', 'checkout' ),
			'cart'                           => array( 'cart', 'cart' ),
			'product'                        => array( 'product', 'product' ),
			'pay-now uses pay_order segment' => array( 'pay-now', 'pay_order' ),
		);
	}

	/**
	 * @testdox Should register the bootstrap script and stylesheet with the asset data dependencies and versions.
	 */
	public function test_enqueue_registers_script_with_asset_data_dependencies_and_version(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'checkout' )->andReturn( true );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'mini-cart' )->andReturn( false );
		$this->asset_getter->shouldReceive( 'get_asset_url' )->with( 'boot.js' )->andReturn( 'https://example.com/assets/boot.js' );
		$this->asset_getter->shouldReceive( 'get_asset_data' )->with( 'boot.js', '1.0.0' )->andReturn(
			array(
				'dependencies' => array( 'wp-data' ),
				'version'      => 'deadbeef',
			)
		);
		$this->asset_getter->shouldReceive( 'get_asset_url' )->with( 'gateway.css' )->andReturn( 'https://example.com/assets/gateway.css' );
		$this->asset_getter->shouldReceive( 'get_asset_data' )->with( 'gateway.css', '1.0.0' )->andReturn(
			array(
				'dependencies' => array(),
				'version'      => 'cafebabe',
			)
		);

		$this->create_sut()->enqueue();

		$script = wp_scripts()->registered['wc-ppcp-sdk-v6-boot'] ?? null;
		$this->assertNotNull( $script, 'The bootstrap script must be registered' );
		$this->assertSame( 'https://example.com/assets/boot.js', $script->src );
		$this->assertSame( array( 'wp-data' ), $script->deps );
		$this->assertSame( 'deadbeef', $script->ver );
		$this->assertTrue( wp_script_is( 'wc-ppcp-sdk-v6-boot', 'enqueued' ) );
		$this->assertStringContainsString( 'wc_ppcp_sdk_v6', (string) wp_scripts()->get_data( 'wc-ppcp-sdk-v6-boot', 'data' ) );

		$style = wp_styles()->registered['wc-ppcp-sdk-v6-gateway'] ?? null;
		$this->assertNotNull( $style, 'The gateway stylesheet must be registered' );
		$this->assertSame( 'https://example.com/assets/gateway.css', $style->src );
		$this->assertSame( 'cafebabe', $style->ver );
	}

	/**
	 * @testdox Should register nothing when the SDK does not load on the page.
	 */
	public function test_enqueue_does_nothing_when_should_not_load_on_current_page(): void {
		$this->stub_page( '', '' );
		$this->messages_eligibility->shouldReceive( 'is_enabled_for_location' )->with( '' )->andReturn( false );

		$this->create_sut()->enqueue();

		$this->assertArrayNotHasKey( 'wc-ppcp-sdk-v6-boot', wp_scripts()->registered );
	}

	/**
	 * @testdox Should not load the SDK on a checkout page where no button, no message and no mini-cart asks for it.
	 */
	public function test_should_not_load_on_checkout_when_nothing_asks_for_the_sdk(): void {
		$this->stub_page( 'checkout', 'checkout' );
		$this->stub_buttons_everywhere( false );

		$this->assertFalse( $this->create_sut()->should_load_on_current_page() );
	}

	/**
	 * A block checkout can load the SDK for another surface (the mini-cart) while its own location is off; the block
	 * script must not register express buttons then.
	 *
	 * @testdox Should tell the page whether its own button location is on, apart from loading the SDK: $page_context on $enabled.
	 * @testWith ["checkout-block", true]
	 *           ["checkout-block", false]
	 *           ["cart-block", false]
	 *
	 * @param string $page_context The page context.
	 * @param bool   $enabled      Whether that location is on.
	 */
	public function test_script_data_carries_whether_the_page_location_is_on( string $page_context, bool $enabled ): void {
		$this->stub_page( $page_context );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( $page_context )->andReturn( $enabled );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'mini-cart' )->andReturn( true );

		$this->assertSame( $enabled, $this->script_data()['buttons_enabled'] );
	}

	/**
	 * The v5 rule decides whether Venmo is offered per location: the "Venmo" switch on the Payment methods tab, the
	 * location's styling choice and the woocommerce_paypal_payments_disabled_funding filter.
	 *
	 * @testdox Should offer Venmo per location as the v5 settings rule says: page $page_disabled, mini-cart $mini_cart_disabled.
	 * @testWith [false, false]
	 *           [true, false]
	 *           [false, true]
	 *
	 * @param bool $page_disabled      Whether the rule disables Venmo on the page's location.
	 * @param bool $mini_cart_disabled Whether the rule disables Venmo in the mini-cart.
	 */
	public function test_script_data_offers_venmo_per_location_by_the_v5_rule( bool $page_disabled, bool $mini_cart_disabled ): void {
		$this->stub_page( 'checkout-block' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( true );
		$this->disabled_funding_sources->shouldReceive( 'get_sources_from_settings' )->with( 'checkout-block' )->andReturn( $page_disabled ? array( 'venmo' ) : array() );
		$this->disabled_funding_sources->shouldReceive( 'get_sources_from_settings' )->with( 'mini-cart' )->andReturn( $mini_cart_disabled ? array( 'venmo' ) : array() );

		$this->assertSame(
			array(
				'checkout-block' => ! $page_disabled,
				'mini-cart'      => ! $mini_cart_disabled,
			),
			$this->script_data()['venmo_button']
		);
	}

	/**
	 * @testdox Should report no page location on a page without a page context.
	 */
	public function test_script_data_buttons_disabled_without_a_page_context(): void {
		$this->stub_page( '' );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( true );

		$this->assertFalse( $this->script_data()['buttons_enabled'] );
	}

	/**
	 * @testdox Should not carry a fastlane subtree in the data: the SDK loader no longer asks for that component.
	 */
	public function test_script_data_has_no_fastlane_subtree(): void {
		$this->stub_page( 'checkout' );

		$data = $this->script_data();

		$this->assertArrayNotHasKey( 'fastlane', $data );
	}

	/**
	 * @testdox Should mirror the free-trial helper in the data: free trial cart $is_free_trial_cart.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $is_free_trial_cart What the helper answers.
	 */
	public function test_script_data_reflects_free_trial_cart_state( bool $is_free_trial_cart ): void {
		$this->free_trial_helper->shouldReceive( 'is_free_trial_cart' )->andReturn( $is_free_trial_cart );

		$data = $this->script_data();

		$this->assertSame( $is_free_trial_cart, $data['is_free_trial_cart'] );
	}

	/**
	 * @testdox Should mirror cart_requires_vaulting apart from the free trial flag: requires vaulting $cart_requires_vaulting.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $cart_requires_vaulting What the helper answers.
	 */
	public function test_script_data_reflects_cart_requires_vaulting_independently_of_total( bool $cart_requires_vaulting ): void {
		$this->free_trial_helper->shouldReceive( 'cart_requires_vaulting' )->andReturn( $cart_requires_vaulting );
		$this->free_trial_helper->shouldReceive( 'is_free_trial_cart' )->andReturn( false );

		$data = $this->script_data();

		$this->assertSame( $cart_requires_vaulting, $data['cart_needs_vaulting'] );
	}

	/**
	 * @testdox Should mirror the buyer's login state in the data: logged in $is_logged_in.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $is_logged_in Whether the buyer is logged in.
	 */
	public function test_script_data_reflects_buyer_login_state( bool $is_logged_in ): void {
		if ( $is_logged_in ) {
			wp_set_current_user( self::factory()->user->create() );
		}

		$data = $this->script_data();

		$this->assertSame( $is_logged_in, $data['user']['is_logged'] );
	}

	/**
	 * @testdox Should carry the free-trial vault endpoints and nonces in the ajax data.
	 */
	public function test_script_data_includes_free_trial_vault_ajax_endpoints(): void {
		$data = $this->script_data();

		$this->assertSame( \WC_AJAX::get_endpoint( CreateSetupToken::ENDPOINT ), $data['ajax']['create_setup_token']['endpoint'] );
		$this->assertSame( wp_create_nonce( CreateSetupToken::nonce() ), $data['ajax']['create_setup_token']['nonce'] );
		$this->assertSame( \WC_AJAX::get_endpoint( CreatePaymentToken::ENDPOINT ), $data['ajax']['create_payment_token']['endpoint'] );
		$this->assertSame( wp_create_nonce( CreatePaymentToken::nonce() ), $data['ajax']['create_payment_token']['nonce'] );
		$this->assertSame( \WC_AJAX::get_endpoint( CreatePaymentTokenForGuest::ENDPOINT ), $data['ajax']['create_payment_token_for_guest']['endpoint'] );
		$this->assertSame( wp_create_nonce( CreatePaymentTokenForGuest::nonce() ), $data['ajax']['create_payment_token_for_guest']['nonce'] );
	}

	/**
	 * @testdox Should give the mini-cart a shorter button than the page context.
	 */
	public function test_script_data_button_styles_mini_cart_height_differs_from_page_context(): void {
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'mini-cart' )->andReturn( true );

		$data = $this->script_data();

		$this->assertSame( SdkV6Manager::PAYMENT_BUTTON_HEIGHT, $data['button_styles']['checkout']['height'] );
		$this->assertSame( SdkV6Manager::MINI_CART_BUTTON_HEIGHT, $data['button_styles']['mini-cart']['height'] );
	}

	/**
	 * @testdox Should report shipping per context: final review $final_review_enabled, $page_context, cart needs shipping $cart_needs_shipping, product $product_state.
	 * @dataProvider shipping_in_context_provider
	 *
	 * @param bool   $final_review_enabled Whether the final review step is on.
	 * @param string $page_context         The page context.
	 * @param bool   $cart_needs_shipping  Whether the cart needs shipping.
	 * @param string $product_state        The viewed product: none, virtual, downloadable or physical.
	 * @param array  $expected_in_context  The shipping.in_context entry.
	 */
	public function test_script_data_shipping_in_context_per_context(
		bool $final_review_enabled,
		string $page_context,
		bool $cart_needs_shipping,
		string $product_state,
		array $expected_in_context
	): void {
		$this->stub_page( $page_context );
		$this->stub_cart(
			array(
				'needs_shipping' => $cart_needs_shipping,
				'is_empty'       => true,
				// Incidental: script_data() prices the Pay Later message too and falls back to the cart total.
				'get_total'      => '10.00',
			)
		);
		if ( 'virtual' === $product_state ) {
			$this->stub_current_product( array( 'virtual' => true ) );
		} elseif ( 'downloadable' === $product_state ) {
			$product = $this->stub_current_product();
			$product->set_downloadable( true );
			$product->save();
		} elseif ( 'physical' === $product_state ) {
			$this->stub_current_product();
		}

		$data = $this->script_data( $this->create_sut( $final_review_enabled ) );

		$this->assertSame( $expected_in_context, $data['shipping']['in_context'] );
	}

	/**
	 * Scenarios of the shipping rule.
	 *
	 * @return array
	 */
	public function shipping_in_context_provider(): array {
		return array(
			'a final review page disables shipping everywhere'                      => array(
				true,
				'cart',
				true,
				'none',
				array(
					'cart'      => false,
					'mini-cart' => false,
				),
			),
			'classic checkout never collects shipping even when the cart needs it'  => array(
				false,
				'checkout',
				true,
				'none',
				array(
					'checkout'  => false,
					'mini-cart' => true,
				),
			),
			'classic checkout stays disabled when the cart needs no shipping'       => array(
				false,
				'checkout',
				false,
				'none',
				array(
					'checkout'  => false,
					'mini-cart' => false,
				),
			),
			'the pay-for-order page never collects shipping'                        => array(
				false,
				'pay-now',
				true,
				'none',
				array(
					'pay-now'   => false,
					'mini-cart' => true,
				),
			),
			'the pay-for-order page stays disabled when the cart needs none'        => array(
				false,
				'pay-now',
				false,
				'none',
				array(
					'pay-now'   => false,
					'mini-cart' => false,
				),
			),
			'cart page with a cart needing shipping enables shipping'               => array(
				false,
				'cart',
				true,
				'none',
				array(
					'cart'      => true,
					'mini-cart' => true,
				),
			),
			'cart page with a cart that needs no shipping disables shipping'        => array(
				false,
				'cart',
				false,
				'none',
				array(
					'cart'      => false,
					'mini-cart' => false,
				),
			),
			'product page with a physical product enables shipping, empty cart'     => array(
				false,
				'product',
				false,
				'physical',
				array(
					'product'   => true,
					'mini-cart' => false,
				),
			),
			'product page with a physical product and a cart needing shipping'      => array(
				false,
				'product',
				true,
				'physical',
				array(
					'product'   => true,
					'mini-cart' => true,
				),
			),
			'product page with a virtual product disables shipping for the product' => array(
				false,
				'product',
				true,
				'virtual',
				array(
					'product'   => false,
					'mini-cart' => true,
				),
			),
			'product page with a downloadable product disables it for the product'  => array(
				false,
				'product',
				true,
				'downloadable',
				array(
					'product'   => false,
					'mini-cart' => true,
				),
			),
			'product page with no resolvable product disables it for the product'   => array(
				false,
				'product',
				true,
				'none',
				array(
					'product'   => false,
					'mini-cart' => true,
				),
			),
			'cart-block context enables shipping without consulting the cart'       => array(
				false,
				'cart-block',
				false,
				'none',
				array(
					'cart-block' => true,
					'mini-cart'  => false,
				),
			),
			'checkout-block context enables shipping without consulting the cart'   => array(
				false,
				'checkout-block',
				false,
				'none',
				array(
					'checkout-block' => true,
					'mini-cart'      => false,
				),
			),
			'a final review page disables the block contexts too'                   => array(
				true,
				'checkout-block',
				true,
				'none',
				array(
					'checkout-block' => false,
					'mini-cart'      => false,
				),
			),
		);
	}

	/**
	 * @testdox Should carry the generic error label, and every label a non-empty string.
	 */
	public function test_script_data_includes_the_generic_error_label(): void {
		$this->stub_page( 'checkout-block' );

		$data = $this->script_data();

		$this->assertSame( array( 'generic_error' ), array_keys( $data['labels'] ) );
		foreach ( $data['labels'] as $label ) {
			$this->assertIsString( $label );
			$this->assertNotSame( '', $label );
		}
	}

	/**
	 * @testdox Should carry none of the Apple Pay, Google Pay or wallet shipping script data keys.
	 */
	public function test_script_data_omits_the_dropped_wallet_keys(): void {
		$this->stub_page( 'checkout-block' );

		$data = $this->script_data();

		foreach ( array( 'apple_pay', 'google_pay', 'merchant_country', 'button_height' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertArrayNotHasKey( 'countries', $data['shipping'] );
		$this->assertArrayNotHasKey( 'wallet_shipping', $data['ajax'] );
	}

	/**
	 * @testdox Should suppress the product location only for a genuine free-trial product: free trial $free_trial_product.
	 * @testWith [true, false]
	 *           [false, true]
	 *
	 * @param bool $free_trial_product Whether the viewed product is a free-trial subscription.
	 * @param bool $expected_product   Whether the product location renders.
	 */
	public function test_determine_render_places_product_gated_by_free_trial_product( bool $free_trial_product, bool $expected_product ): void {
		$this->context->shouldReceive( 'init_context' )->never();
		$this->stub_buttons_everywhere( true );
		$this->stub_cart( array( 'needs_payment' => true ) );

		$sut                     = $this->create_sut( false, SdkV6ManagerFreeTrialStub::class );
		$sut->free_trial_product = $free_trial_product;

		$this->assertSame( $expected_product, $sut->determine_render_places()['product'] );
	}

	/**
	 * @testdox Should keep the product location off when its own setting is off, whatever the free-trial answer.
	 */
	public function test_determine_render_places_product_false_when_location_disabled_regardless_of_free_trial_product(): void {
		$this->context->shouldReceive( 'init_context' )->never();
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->with( 'product' )->andReturn( false );
		$this->settings_status->shouldReceive( 'is_smart_button_enabled_for_location' )->andReturn( true );
		$this->stub_cart( array( 'needs_payment' => true ) );

		$sut                     = $this->create_sut( false, SdkV6ManagerFreeTrialStub::class );
		$sut->free_trial_product = false;

		$this->assertFalse( $sut->determine_render_places()['product'] );
	}

	/**
	 * @testdox Should confine the free-trial product guard to the product location.
	 */
	public function test_determine_render_places_free_trial_product_does_not_affect_other_locations(): void {
		$this->context->shouldReceive( 'init_context' )->never();
		$this->stub_buttons_everywhere( true );
		$this->stub_cart( array( 'needs_payment' => true ) );

		$sut                     = $this->create_sut( false, SdkV6ManagerFreeTrialStub::class );
		$sut->free_trial_product = true;
		$result                  = $sut->determine_render_places();

		$this->assertFalse( $result['product'] );
		$this->assertTrue( $result['checkout'] );
	}

	/**
	 * @testdox Should add the tax-inclusive message amount as a two-decimal string to a variation.
	 */
	public function test_add_variation_message_amount_adds_tax_inclusive_amount_as_string(): void {
		$variation = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '200' ) );

		$result = $this->create_sut()->add_variation_message_amount(
			array(
				'display_price' => 180.0,
				'sku'           => 'VAR-1',
			),
			null,
			$variation
		);

		$this->assertSame( '200.00', $result['ppcp_message_amount'] );
		$this->assertSame( 180.0, $result['display_price'] );
		$this->assertSame( 'VAR-1', $result['sku'] );
	}

	/**
	 * @testdox Should leave the variation data untouched when the variation is not a WC_Product: $scenario.
	 * @dataProvider non_product_variation_provider
	 *
	 * @param string $scenario  The scenario name.
	 * @param mixed  $variation The stand-in.
	 */
	public function test_add_variation_message_amount_leaves_data_untouched_without_a_wc_product_variation( string $scenario, $variation ): void {
		unset( $scenario );
		$data = array( 'display_price' => 180.0 );

		$this->assertSame( $data, $this->create_sut()->add_variation_message_amount( $data, null, $variation ) );
	}

	/**
	 * Values that are not products.
	 *
	 * @return array
	 */
	public function non_product_variation_provider(): array {
		return array(
			'null variation' => array( 'null variation', null ),
			'a plain stdClass standing in for the variation' => array( 'stdClass', new \stdClass() ),
		);
	}

	/**
	 * @testdox Should leave the variation data untouched when an earlier callback returned a non-array: $scenario.
	 * @dataProvider non_array_data_provider
	 *
	 * @param string $scenario The scenario name.
	 * @param mixed  $data     What the earlier callback returned.
	 */
	public function test_add_variation_message_amount_leaves_non_array_data_untouched( string $scenario, $data ): void {
		unset( $scenario );
		$variation = WC_Helper_Product::create_simple_product();

		$this->assertSame( $data, $this->create_sut()->add_variation_message_amount( $data, null, $variation ) );
	}

	/**
	 * Non-array values.
	 *
	 * @return array
	 */
	public function non_array_data_provider(): array {
		return array(
			'a string returned by an earlier callback' => array( 'a string', 'not-an-array' ),
			'null returned by an earlier callback'     => array( 'null', null ),
		);
	}

	/**
	 * @testdox Should leave the variation data untouched when messaging prices through cart simulation.
	 */
	public function test_add_variation_message_amount_leaves_data_untouched_under_cart_simulation(): void {
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_sdk_v6_messages_use_cart_simulation', true );
		$data  = array( 'display_price' => 180.0 );

		$result = $this->create_sut()->add_variation_message_amount( $data, null, WC_Helper_Product::create_simple_product() );

		$this->assertSame( $data, $result );
		$this->assertCount( 1, $calls );
	}
}
