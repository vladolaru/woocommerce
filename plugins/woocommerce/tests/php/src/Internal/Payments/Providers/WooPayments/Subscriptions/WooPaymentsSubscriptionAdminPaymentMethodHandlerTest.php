<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments subscription admin payment-method handler.
 */
class WooPaymentsSubscriptionAdminPaymentMethodHandlerTest extends WC_Unit_Test_Case {

	/**
	 * Preserved Amazon Pay gateway ID.
	 */
	private const AMAZON_PAY_GATEWAY_ID = WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSubscriptionAdminPaymentMethodHandler
	 */
	private $sut;

	/**
	 * Native WooPayments token class-map controller.
	 *
	 * @var WooPaymentsTokenClassMapController
	 */
	private $token_class_map;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->token_class_map = new WooPaymentsTokenClassMapController();
		$this->token_class_map->register();
		$this->sut = new WooPaymentsSubscriptionAdminPaymentMethodHandler();
		$this->sut->init( wc_get_container()->get( WooPaymentsTokenService::class ) );
	}

	/**
	 * @testdox Should register the WCS admin payment-method hooks for WooPayments.
	 */
	public function test_register_hooks_registers_subscription_admin_callbacks(): void {
		$this->sut->register_hooks();

		$this->assertSame( 10, has_filter( 'woocommerce_subscription_payment_meta', array( $this->sut, 'add_subscription_payment_meta' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_subscription_validate_payment_meta', array( $this->sut, 'validate_subscription_payment_meta' ) ) );
		$this->assertSame( 10, has_action( 'wcs_save_other_payment_meta', array( $this->sut, 'save_meta_in_order_tokens' ) ) );
		$this->assertSame( 10, has_filter( 'wcs_copy_payment_meta_to_order', array( $this->sut, 'append_payment_meta' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_my_subscriptions_payment_method', array( $this->sut, 'maybe_render_subscription_payment_method' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_payment_method_to_display', array( $this->sut, 'maybe_render_subscription_payment_method' ) ) );
		$this->assertSame( 10, has_filter( 'wcs_view_subscription_actions', array( $this->sut, 'maybe_hide_change_payment_for_manual_subscriptions' ) ) );
		$this->assertSame( 100, has_filter( 'user_has_cap', array( $this->sut, 'maybe_hide_auto_renew_toggle_for_manual_subscriptions' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_note_old_payment_method_title', array( $this->sut, 'get_specific_old_payment_method_title' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscription_note_new_payment_method_title', array( $this->sut, 'get_specific_new_payment_method_title' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_order_data_after_billing_address', array( $this->sut, 'add_payment_method_select_to_subscription_edit' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscriptions_update_subscription_token', array( $this->sut, 'update_subscription_token' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( $this->sut, 'update_payment_method_for_subscriptions' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_wcpay_get_user_payment_tokens', array( $this->sut, 'ajax_get_user_payment_tokens' ) ) );
	}

	/**
	 * @testdox Should expose the saved $gateway_id token metadata for WCS admin payment-method edits, with the custom selector renderer.
	 * @dataProvider provider_reusable_gateway_tokens
	 *
	 * @param string $gateway_id Reusable gateway ID.
	 */
	public function test_add_subscription_payment_meta_returns_saved_token_metadata( string $gateway_id ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, $gateway_id );
		$token        = $this->create_reusable_token( $gateway_id, $user_id, 'pm_saved' );
		$subscription->add_payment_token( $token );
		$subscription->save();

		$payment_meta = $this->sut->add_subscription_payment_meta( array(), $subscription );

		$this->assertArrayHasKey( $gateway_id, $payment_meta );
		$this->assertSame(
			(string) $token->get_id(),
			$payment_meta[ $gateway_id ]['wc_order_tokens']['token']['value'],
			'The WCS admin meta should point at the active WooPayments token ID.'
		);
		$this->assertSame( 'Saved payment method', $payment_meta[ $gateway_id ]['wc_order_tokens']['token']['label'] );
		$this->assertSame(
			10,
			has_action(
				'woocommerce_subscription_payment_meta_input_' . $gateway_id . '_wc_order_tokens_token',
				array( $this->sut, 'render_custom_payment_meta_input' )
			),
			'The custom selector renderer should be registered for WCS payment meta.'
		);
	}

	/**
	 * The reusable WooPayments gateways: cards and the preserved Amazon Pay gateway.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_reusable_gateway_tokens(): array {
		return array(
			'card'       => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID ),
			'Amazon Pay' => array( self::AMAZON_PAY_GATEWAY_ID ),
		);
	}

	/**
	 * @testdox Should reject invalid WCS admin token metadata for WooPayments.
	 */
	public function test_validate_subscription_payment_meta_rejects_invalid_tokens(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id );
		$other_token  = $this->create_card_token( $user_id, 'other_gateway', 'pm_other' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'valid WooPayments saved payment method' );

		$this->sut->validate_subscription_payment_meta(
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			array(
				'wc_order_tokens' => array(
					'token' => array(
						'value' => (string) $other_token->get_id(),
					),
				),
			),
			$subscription
		);
	}

	/**
	 * @testdox Should attach the selected $gateway_id token and its payment method when WCS saves admin payment meta, keeping the existing customer.
	 * @dataProvider provider_reusable_gateway_tokens
	 *
	 * @param string $gateway_id Reusable gateway ID.
	 */
	public function test_save_meta_in_order_tokens_attaches_selected_token_and_provider_meta( string $gateway_id ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, $gateway_id );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_existing' );
		$subscription->save();
		$token = $this->create_reusable_token( $gateway_id, $user_id, 'pm_selected' );

		$this->sut->save_meta_in_order_tokens( $subscription, 'wc_order_tokens', 'token', (string) $token->get_id() );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription->get_payment_tokens() ) );
		$this->assertSame( 'pm_selected', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_existing', $subscription->get_meta( '_stripe_customer_id', true ) );
	}

	/**
	 * @testdox Should reappend a previously used token when an admin makes it active again.
	 */
	public function test_save_meta_in_order_tokens_reappends_previously_used_token(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id );
		$token_a      = $this->create_card_token( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_a' );
		$token_b      = $this->create_card_token( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_b' );

		$this->sut->save_meta_in_order_tokens( $subscription, 'wc_order_tokens', 'token', (string) $token_a->get_id() );
		$this->sut->save_meta_in_order_tokens( $subscription, 'wc_order_tokens', 'token', (string) $token_b->get_id() );
		$this->sut->save_meta_in_order_tokens( $subscription, 'wc_order_tokens', 'token', (string) $token_a->get_id() );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame(
			array( $token_a->get_id(), $token_b->get_id(), $token_a->get_id() ),
			array_values( array_map( 'absint', $subscription->get_payment_tokens() ) )
		);
		$this->assertSame( 'pm_a', $subscription->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Should copy Amazon Pay subscription token metadata to related renewal orders.
	 */
	public function test_append_payment_meta_accepts_amazon_pay_orders(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, self::AMAZON_PAY_GATEWAY_ID );
		$order        = $this->create_subscription_order( $user_id, self::AMAZON_PAY_GATEWAY_ID );
		$token        = $this->create_amazon_pay_token( $user_id, 'pm_amazon_renewal' );
		$subscription->add_payment_token( $token );
		$subscription->save();

		$payment_meta = $this->sut->append_payment_meta( array(), $order, $subscription );

		$this->assertArrayHasKey( 'wc_order_tokens', $payment_meta );
		$this->assertSame( (string) $token->get_id(), $payment_meta['wc_order_tokens']['token']['value'] );
	}

	/**
	 * @testdox When WCS changes the default payment token to a $gateway_id token, the subscription moves to that token's gateway and takes the token and its payment method.
	 * @dataProvider provider_reusable_gateway_tokens
	 *
	 * @param string $gateway_id Reusable gateway ID of the new token; the subscription starts on the card gateway.
	 */
	public function test_update_subscription_token_sets_the_token_gateway_token_and_payment_meta( string $gateway_id ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id );
		$token        = $this->create_reusable_token( $gateway_id, $user_id, 'pm_default' );

		$result = $this->sut->update_subscription_token( false, $subscription, $token );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( $gateway_id, $subscription->get_payment_method() );
		$this->assertContains( $token->get_id(), array_map( 'absint', $subscription->get_payment_tokens() ) );
		$this->assertSame( 'pm_default', $subscription->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Should render the $gateway_id token selector for WCS admin payment meta.
	 * @dataProvider provider_token_selectors
	 *
	 * @param string   $gateway_id Reusable gateway ID.
	 * @param string[] $expected   Fragments of the selector besides the field name and the token ID.
	 */
	public function test_render_custom_payment_meta_input_outputs_token_selector( string $gateway_id, array $expected ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, $gateway_id );
		$token        = $this->create_reusable_token( $gateway_id, $user_id, 'pm_rendered' );
		$field_id     = '_payment_method_meta[' . $gateway_id . '][wc_order_tokens][token]';

		ob_start();
		$this->sut->render_custom_payment_meta_input( $subscription, $field_id, (string) $token->get_id() );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . esc_attr( $field_id ) . '"', $output );
		$this->assertStringContainsString( 'value="' . esc_attr( (string) $token->get_id() ) . '"', $output );
		foreach ( $expected as $fragment ) {
			$this->assertStringContainsString( $fragment, $output );
		}
	}

	/**
	 * Token selectors per reusable gateway.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function provider_token_selectors(): array {
		return array(
			'card'       => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, array( 'class="wcpay-subscription-payment-method"', 'data-wcpay-pm-selector=', '4242' ) ),
			'Amazon Pay' => array( self::AMAZON_PAY_GATEWAY_ID, array( 'Amazon Pay', self::AMAZON_PAY_GATEWAY_ID ) ),
		);
	}

	/**
	 * @testdox With no stored token, WooCommerce Subscriptions passes a null value and the selector renders for a customer with $tokens.
	 *
	 * WCS passes null when the payment meta value is empty (`class-wcs-change-payment-method-admin.php:72`, `:78`), which is
	 * every subscription without a WooPayments token. Client 11.1.0 takes the value untyped and coerces it (trait:924-931),
	 * then lists the customer's tokens or shows "No payment methods found for customer" (trait:960-973). Review 36 F1: a `string` parameter made the Edit Subscription screen fatal.
	 *
	 * @testWith ["no saved token"]
	 *           ["a saved token"]
	 *
	 * @param string $tokens Which saved tokens the customer has.
	 */
	public function test_render_custom_payment_meta_input_accepts_the_null_value_wcs_passes( string $tokens ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, 'bacs' );
		$token        = null;
		if ( 'a saved token' === $tokens ) {
			$token = $this->create_card_token( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_listed' );
		}
		$this->sut->add_subscription_payment_meta( array(), $subscription );
		$field_id = '_payment_method_meta[' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '][wc_order_tokens][token]';

		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as WooCommerce Subscriptions fires it.
		do_action( 'woocommerce_subscription_payment_meta_input_' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_wc_order_tokens_token', $subscription, $field_id, null, array() );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . esc_attr( $field_id ) . '"', $output );
		$this->assertStringNotContainsString( 'Please select a payment method', $output );
		if ( null === $token ) {
			$this->assertMatchesRegularExpression( '/<option value="0"\s+selected=\'selected\'\s+disabled=\'disabled\'>\s*No payment methods found for customer/', $output );
			return;
		}
		$this->assertMatchesRegularExpression( '/<option value="' . $token->get_id() . '"\s+>\s*Visa ending in 4242/', $output );
	}

	/**
	 * @testdox Should display the saved $gateway_id token instead of "$title" for the subscription.
	 * @dataProvider provider_saved_token_displays
	 *
	 * @param string $gateway_id Reusable gateway ID.
	 * @param string $title      Payment method title WCS passes in.
	 */
	public function test_renders_subscription_payment_method_from_saved_token( string $gateway_id, string $title ): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id, $gateway_id );
		$token        = $this->create_reusable_token( $gateway_id, $user_id, 'pm_display' );
		$subscription->add_payment_token( $token );
		$subscription->save();

		$result = $this->sut->maybe_render_subscription_payment_method( $title, $subscription );

		$this->assertSame( $token->get_display_name(), $result );
	}

	/**
	 * Saved tokens shown for a subscription, with the title WCS passes in.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_saved_token_displays(): array {
		return array(
			'card'       => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'Card' ),
			'Amazon Pay' => array( self::AMAZON_PAY_GATEWAY_ID, 'Amazon Pay' ),
		);
	}

	/**
	 * @testdox Should hide unsupported manual subscription actions for preserved non-reusable WooPayments methods.
	 */
	public function test_hides_manual_subscription_actions_for_preserved_non_reusable_method(): void {
		$subscription = new class() extends WC_Order {
			/**
			 * Mock WCS manual renewal status.
			 *
			 * @return bool
			 */
			public function is_manual(): bool {
				return true;
			}

			/**
			 * Mock preserved original WooPayments payment method metadata.
			 *
			 * @param string $key     Meta key.
			 * @param bool   $single  Whether to return a single value.
			 * @param string $context View or edit context.
			 * @return mixed
			 */
			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				if ( '_wcpay_original_payment_method_id' === $key ) {
					return 'bancontact';
				}

				return parent::get_meta( $key, $single, $context );
			}
		};

		$result = $this->sut->maybe_hide_change_payment_for_manual_subscriptions(
			array(
				'change_payment_method' => array( 'url' => 'https://example.test/change' ),
				'cancel'                => array( 'url' => 'https://example.test/cancel' ),
			),
			$subscription
		);

		$this->assertArrayNotHasKey( 'change_payment_method', $result );
		$this->assertArrayHasKey( 'cancel', $result );
	}

	/**
	 * @testdox On a $label, the WCS update-payment decision is $expected.
	 * @dataProvider provider_change_payment_requests
	 *
	 * @param string      $label    Case label.
	 * @param string|null $posted   Token value posted with a WCS change-payment request, or null for no change request.
	 * @param bool        $expected Decision returned.
	 */
	public function test_update_payment_method_for_subscriptions_decides_by_the_posted_token( string $label, ?string $posted, bool $expected ): void {
		unset( $label );
		$subscription = $this->create_subscription_order( self::factory()->user->create() );
		if ( null !== $posted ) {
			$_POST = array(
				'_wcsnonce'             => wp_create_nonce( 'wcs_change_payment_method' ),
				'change_payment_method' => (string) $subscription->get_id(),
				'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' => $posted,
			);
		}

		$result = $this->sut->update_payment_method_for_subscriptions( true, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $subscription );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Update-payment requests: none, a saved token, and a new payment method WooPayments has to process first.
	 *
	 * @return array<string,array{0:string,1:?string,2:bool}>
	 */
	public function provider_change_payment_requests(): array {
		return array(
			'not a change request' => array( 'request that is not a change-payment request', null, true ),
			'saved token posted'   => array( 'change-payment request with a saved token', '123', true ),
			'new method posted'    => array( 'change-payment request with a new payment method', 'new', false ),
		);
	}

	/**
	 * @testdox A saved-card change notes the card the shopper posted, in the WooPayments extension's format, while the subscription still carries the old card last.
	 *
	 * Task 6.3 R3: WCS writes this note before the payment runs, so the last token is still the previous card; native named it as the new one.
	 */
	public function test_saved_card_change_names_the_posted_card(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id );
		$posted       = $this->create_card_token( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_posted' );
		$current      = new WC_Payment_Token_CC();
		$current->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$current->set_user_id( $user_id );
		$current->set_token( 'pm_current' );
		$current->set_card_type( 'mastercard' );
		$current->set_last4( '4444' );
		$current->set_expiry_month( '11' );
		$current->set_expiry_year( '2031' );
		$current->save();
		$subscription->add_payment_token( $posted );
		$subscription->add_payment_token( $current );
		$_POST = array(
			'_wcsnonce'             => wp_create_nonce( 'wcs_change_payment_method' ),
			'change_payment_method' => (string) $subscription->get_id(),
			'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' => (string) $posted->get_id(),
		);

		$title = $this->sut->get_specific_new_payment_method_title( 'Card', WooPaymentsPersistenceVocabulary::GATEWAY_ID, $subscription );

		$this->assertSame( 'Visa ending in 4242', $title, 'Client 11.1.0 names the posted token as brand label plus last four (trait-wc-payment-gateway-wcpay-subscriptions.php:154-170), no expiry.' );
	}

	/**
	 * @testdox A new-card change notes the gateway title with the new card's last four, as the WooPayments extension builds it from the payment method.
	 */
	public function test_new_card_change_names_the_gateway_title_and_last_four(): void {
		$user_id      = self::factory()->user->create();
		$subscription = $this->create_subscription_order( $user_id );
		$subscription->add_payment_token( $this->create_card_token( $user_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ) );
		$_POST = array(
			'_wcsnonce'             => wp_create_nonce( 'wcs_change_payment_method' ),
			'change_payment_method' => (string) $subscription->get_id(),
			'wc-' . WooPaymentsPersistenceVocabulary::GATEWAY_ID . '-payment-token' => 'new',
		);

		$title = $this->sut->get_specific_new_payment_method_title( 'Card', WooPaymentsPersistenceVocabulary::GATEWAY_ID, $subscription );

		$this->assertSame( 'Card ending in 4242', $title, 'Client 11.1.0 trait :1080-1083; live client subscription 26 note "to Card ending in 4242".' );
	}

	/**
	 * Create a subscription-like order for handler tests.
	 *
	 * @param int    $user_id    Customer user ID.
	 * @param string $gateway_id Payment gateway ID.
	 * @return WC_Order
	 */
	private function create_subscription_order( int $user_id, string $gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID ): WC_Order {
		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_payment_method( $gateway_id );
		$order->save();

		return $order;
	}

	/**
	 * Create a saved token for a reusable gateway: a card token, or an Amazon Pay token for the preserved Amazon Pay gateway.
	 *
	 * @param string $gateway_id        Reusable gateway ID.
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return \WC_Payment_Token
	 */
	private function create_reusable_token( string $gateway_id, int $user_id, string $payment_method_id ): \WC_Payment_Token {
		return self::AMAZON_PAY_GATEWAY_ID === $gateway_id
			? $this->create_amazon_pay_token( $user_id, $payment_method_id )
			: $this->create_card_token( $user_id, $gateway_id, $payment_method_id );
	}

	/**
	 * Create a saved card token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $gateway_id        Gateway ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $gateway_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Create a saved Amazon Pay token.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Provider payment method ID.
	 * @return WooPaymentsAmazonPayToken
	 */
	private function create_amazon_pay_token( int $user_id, string $payment_method_id ): WooPaymentsAmazonPayToken {
		$token = new WooPaymentsAmazonPayToken();
		$token->set_gateway_id( self::AMAZON_PAY_GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $payment_method_id );
		$token->set_email( 'buyer@example.com' );
		$token->save();

		return $token;
	}
}
