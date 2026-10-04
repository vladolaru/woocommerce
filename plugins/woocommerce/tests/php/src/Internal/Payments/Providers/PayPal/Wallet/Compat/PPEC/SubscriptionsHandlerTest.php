<?php
/**
 * Tests for the PayPal Express Checkout subscriptions handler.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\BillingAgreementTokenConverter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\MockGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\PPECHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC\SubscriptionsHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\RenewalHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use stdClass;

/**
 * Registering the mock PayPal Express Checkout gateway, and the billing agreement as a token type.
 *
 * A prior fix scoped the gateway registration to certain contexts (a renewal in progress, My Account subscriptions, an
 * edited PPEC order) to keep the mock gateway off the Payments settings screen. That guard was a regression: the
 * `woocommerce_payment_gateways` filter runs exactly once, when the gateway list is built early in the request, long
 * before Action Scheduler runs `woocommerce_scheduled_subscription_payment`. With the registration gated on that
 * action the gateway was missing when a renewal ran, and PPEC renewals failed. The registration stays unconditional,
 * and MockGateway keeps itself off the settings screen (see MockGatewayTest).
 *
 * @group paypal-wallet
 */
class SubscriptionsHandlerTest extends WalletTestCase {

	/**
	 * The mock gateway the handler registers.
	 *
	 * @var MockGateway
	 */
	private $mock_gateway;

	/**
	 * The System Under Test.
	 *
	 * @var SubscriptionsHandler
	 */
	private $sut;

	/**
	 * Build the handler over the real mock gateway.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->mock_gateway = new MockGateway( 'PayPal (Legacy)' );
		$this->sut          = new SubscriptionsHandler(
			$this->mock( RenewalHandler::class ),
			$this->mock_gateway,
			$this->mock( BillingAgreementTokenConverter::class ),
			$this->mock( LoggerInterface::class )
		);
	}

	/**
	 * Given no ambient context at all (no renewal in progress, no admin screen, no endpoint), which is how a deleted
	 * context guard once prevented the registration, the gateway list still gets the mock gateway.
	 *
	 * @testdox Should register the gateway unconditionally with no context set up.
	 */
	public function test_registers_the_gateway_unconditionally_with_no_context_set_up(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this->sut, 'add_mock_ppec_gateway' ) );

		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( $this->mock_gateway, $gateways[ PPECHelper::PPEC_GATEWAY_ID ] );
	}

	/**
	 * @testdox Should not overwrite a gateway that something else already registered under the PPEC ID.
	 */
	public function test_does_not_overwrite_an_existing_gateway_entry(): void {
		$existing_gateway = new stdClass();

		$gateways = $this->sut->add_mock_ppec_gateway( array( PPECHelper::PPEC_GATEWAY_ID => $existing_gateway ) );

		$this->assertSame( $existing_gateway, $gateways[ PPECHelper::PPEC_GATEWAY_ID ] );
	}

	/**
	 * @testdox Should add the billing agreement to the valid token types once.
	 */
	public function test_adds_the_billing_agreement_token_type_once(): void {
		$types = $this->sut->add_billing_agreement_as_token_type( array( 'PAYMENT_METHOD_TOKEN' ) );

		$this->assertSame( array( 'PAYMENT_METHOD_TOKEN', 'BILLING_AGREEMENT' ), $types );
		$this->assertSame( $types, $this->sut->add_billing_agreement_as_token_type( $types ) );
	}
}
