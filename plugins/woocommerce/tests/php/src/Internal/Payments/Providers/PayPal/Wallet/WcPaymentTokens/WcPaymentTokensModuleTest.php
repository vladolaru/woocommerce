<?php
/**
 * Tests for the vaulting module's filters (ported from the extension's WcPaymentTokensModuleTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenVenmo;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WcPaymentTokensModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use WC_Payment_Token;

/**
 * The block checkout builds a saved-token label itself as "<brand> ending in <last4>" and falls back to "Saved token
 * for <gateway id>" when either piece is missing. Wallet tokens have no card number for last4, so the module passes the
 * account email through the last4 slot. That is deliberate and must not be turned into a real last-4 lookup. A
 * companion filter fixes the capitalisation WooCommerce would apply to the "paypal" brand.
 *
 * The filters are the real ones: the module is run once per test and the tests apply the hooks.
 *
 * @group paypal-wallet
 */
class WcPaymentTokensModuleTest extends WalletTestCase {

	/**
	 * The Context mock the token filter asks about PayPal continuation.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * Run the module against a container that serves the session handler and the context.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->context = $this->mock( Context::class );
		$this->context->shouldReceive( 'is_paypal_continuation' )->andReturn( false )->byDefault();

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'session.handler' )->andReturn( $this->mock( SessionHandler::class ) );
		$container->shouldReceive( 'get' )->with( 'button.helper.context' )->andReturn( $this->context );

		( new WcPaymentTokensModule() )->run( $container );
	}

	/**
	 * A list item as WooCommerce builds it before the module labels it.
	 *
	 * @return array
	 */
	private function base_item(): array {
		return array(
			'method' => array(
				'brand' => '',
				'last4' => '',
			),
		);
	}

	/**
	 * A PayPal token, with an account email when given.
	 *
	 * @param string $email The email.
	 * @return PaymentTokenPayPal
	 */
	private function paypal_token( string $email = '' ): PaymentTokenPayPal {
		$token = new PaymentTokenPayPal();
		if ( $email ) {
			$token->set_email( $email );
		}

		return $token;
	}

	/**
	 * A Venmo token, with an account email when given.
	 *
	 * @param string $email The email.
	 * @return PaymentTokenVenmo
	 */
	private function venmo_token( string $email = '' ): PaymentTokenVenmo {
		$token = new PaymentTokenVenmo();
		if ( $email ) {
			$token->set_email( $email );
		}

		return $token;
	}

	/**
	 * @testdox Should use the account email as last4 for a PayPal token with an email (wallet).
	 */
	public function test_paypal_token_with_email_uses_email_as_last4(): void {
		$result = apply_filters( 'woocommerce_payment_methods_list_item', $this->base_item(), $this->paypal_token( 'shopper@example.com' ) );

		$this->assertSame( 'PayPal', $result['method']['brand'] );
		$this->assertSame( 'shopper@example.com', $result['method']['last4'] );
	}

	/**
	 * @testdox Should leave last4 untouched for a PayPal token without an email (wallet).
	 */
	public function test_paypal_token_without_email_does_not_set_last4(): void {
		$item   = $this->base_item();
		$result = apply_filters( 'woocommerce_payment_methods_list_item', $item, $this->paypal_token() );

		$this->assertSame( 'PayPal', $result['method']['brand'] );
		$this->assertSame( $item['method']['last4'], $result['method']['last4'] );
	}

	/**
	 * @testdox Should use the account email as last4 for a Venmo token with an email (wallet).
	 */
	public function test_venmo_token_with_email_uses_email_as_last4(): void {
		$result = apply_filters( 'woocommerce_payment_methods_list_item', $this->base_item(), $this->venmo_token( 'venmo-shopper@example.com' ) );

		$this->assertSame( 'Venmo', $result['method']['brand'] );
		$this->assertSame( 'venmo-shopper@example.com', $result['method']['last4'] );
	}

	/**
	 * @testdox Should return the list item unchanged for a token type the module does not label (wallet).
	 */
	public function test_unrelated_token_type_is_returned_unchanged(): void {
		$token = new class() extends WC_Payment_Token {};

		$item = $this->base_item();

		$this->assertSame( $item, apply_filters( 'woocommerce_payment_methods_list_item', $item, $token ) );
	}

	/**
	 * @testdox Should return the original item when the arguments do not match the documented shape: $scenario (wallet).
	 * @dataProvider invalid_list_item_arguments_provider
	 *
	 * @param string $scenario      What is invalid.
	 * @param mixed  $item          The list item.
	 * @param mixed  $payment_token The token.
	 */
	public function test_invalid_arguments_are_returned_unchanged( string $scenario, $item, $payment_token ): void {
		$this->assertSame( $item, apply_filters( 'woocommerce_payment_methods_list_item', $item, $payment_token ), $scenario );
	}

	/**
	 * Invalid argument shapes.
	 *
	 * @return array
	 */
	public function invalid_list_item_arguments_provider(): array {
		return array(
			'item is not an array'           => array( 'item is not an array', 'not-an-array', new PaymentTokenPayPal() ),
			'second argument is not a token' => array(
				'second argument is not a token',
				array(
					'method' => array(
						'brand' => '',
						'last4' => '',
					),
				),
				// WooCommerce's own filter on this hook calls get_type() on the token before the module sees it.
				new class() {
					/**
					 * The type WooCommerce reads.
					 *
					 * @return string
					 */
					public function get_type(): string {
						return 'other';
					}
				},
			),
		);
	}

	/**
	 * @testdox Should add a PayPal entry to the credit card type labels without disturbing the existing ones (wallet).
	 */
	public function test_adds_paypal_label_while_preserving_existing_labels(): void {
		$result = apply_filters(
			'woocommerce_credit_card_type_labels',
			array(
				'visa'       => 'Visa',
				'mastercard' => 'MasterCard',
			)
		);

		$this->assertSame( 'PayPal', $result['paypal'] );
		$this->assertSame( 'Visa', $result['visa'] );
		$this->assertSame( 'MasterCard', $result['mastercard'] );
	}

	/**
	 * @testdox Should return a non-array value of the credit card type labels unchanged (wallet).
	 */
	public function test_card_type_labels_filter_returns_non_array_unchanged(): void {
		$this->assertSame( 'not-an-array', apply_filters( 'woocommerce_credit_card_type_labels', 'not-an-array' ) );
	}

	/**
	 * @testdox Should map the PayPal and Venmo token types to their classes and leave other types alone (wallet).
	 */
	public function test_token_class_filter_maps_paypal_and_venmo(): void {
		$this->assertSame( PaymentTokenPayPal::class, apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_PayPal', 'PayPal' ) );
		$this->assertSame( PaymentTokenVenmo::class, apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_Venmo', 'Venmo' ) );
		$this->assertSame( 'WC_Payment_Token_CC', apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_CC', 'CC' ) );
	}

	/**
	 * @testdox Should keep every saved token on the checkout outside PayPal continuation (wallet).
	 */
	public function test_saved_tokens_are_kept_on_the_checkout_outside_continuation(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$tokens = array( $this->paypal_token( 'shopper@example.com' ), $this->venmo_token() );

		$this->assertSame( $tokens, apply_filters( 'woocommerce_get_customer_payment_tokens', $tokens ) );
	}

	/**
	 * @testdox Should drop every saved token on the checkout during PayPal continuation (wallet).
	 */
	public function test_saved_tokens_are_dropped_on_the_checkout_during_continuation(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->context->shouldReceive( 'is_paypal_continuation' )->andReturn( true );

		$result = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $this->paypal_token( 'shopper@example.com' ) ) );

		$this->assertSame( array(), array_values( $result ) );
	}

	/**
	 * @testdox Should return a non-array token list unchanged (wallet).
	 */
	public function test_non_array_token_list_is_returned_unchanged(): void {
		$this->assertSame( 'not-an-array', apply_filters( 'woocommerce_get_customer_payment_tokens', 'not-an-array' ) );
	}
}
