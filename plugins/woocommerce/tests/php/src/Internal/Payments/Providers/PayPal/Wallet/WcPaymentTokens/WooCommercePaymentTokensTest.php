<?php
/**
 * Tests for the WooCommerce payment token service (ported from the extension's WooCommercePaymentTokensTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenVenmo;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WcPaymentTokensModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WC_Payment_Tokens;

/**
 * The customer token lookup, the vaulted-token creators and the token factory.
 *
 * The PayPal customer ID lookup bails out early when neither usermeta key holds one: PayPal always rejects that
 * request with a 400.
 *
 * The saved tokens are real WooCommerce tokens; the module registers the token class mapping that WooCommerce needs to
 * load them back.
 *
 * @group paypal-wallet
 */
class WooCommercePaymentTokensTest extends WalletTestCase {

	/**
	 * The payment tokens endpoint mock.
	 *
	 * @var PaymentTokensEndpoint&MockInterface
	 */
	private $payment_tokens_endpoint;

	/**
	 * The System Under Test.
	 *
	 * @var WooCommercePaymentTokens
	 */
	private $sut;

	/**
	 * Build the service over a mocked endpoint and logger.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->payment_tokens_endpoint = $this->mock( PaymentTokensEndpoint::class );

		$logger = $this->mock( LoggerInterface::class );
		$logger->shouldIgnoreMissing();

		$this->sut = new WooCommercePaymentTokens( $this->payment_tokens_endpoint, $logger );
	}

	/**
	 * Register the token class mapping that WooCommerce needs to load the extension's tokens from the database.
	 */
	private function register_token_classes(): void {
		$container = $this->mock( \Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface::class );
		$container->shouldReceive( 'get' )
			->with( 'session.handler' )
			->andReturn( $this->mock( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler::class ) );
		$context = $this->mock( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context::class );
		$context->shouldReceive( 'is_paypal_continuation' )->andReturn( false );
		$container->shouldReceive( 'get' )->with( 'button.helper.context' )->andReturn( $context );

		( new WcPaymentTokensModule() )->run( $container );
	}

	/**
	 * Give a new user the PayPal customer ID under the given meta key.
	 *
	 * @param string $meta_key The meta key.
	 * @param string $value    The customer ID.
	 * @return int The user ID.
	 */
	private function user_with_customer_id( string $meta_key, string $value ): int {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, $meta_key, $value );

		return $user_id;
	}

	/**
	 * A customer token in the shape PaymentTokensEndpoint::payment_tokens_for_customer() returns.
	 *
	 * @param string $payment_source_name The name the token's payment source reports.
	 * @param string $id                  The vault token ID.
	 * @param object $properties          The payment source properties.
	 * @return array
	 */
	private function customer_token( string $payment_source_name, string $id = 'TOKEN-1', $properties = null ): array {
		$source = $this->mock( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource::class );
		$source->shouldReceive( 'name' )->andReturn( $payment_source_name );
		$source->shouldReceive( 'properties' )->andReturn( $properties ?? (object) array() );

		return array(
			'id'             => $id,
			'payment_source' => $source,
		);
	}

	/**
	 * @testdox Should return an empty array without calling the endpoint when no customer ID is stored (wallet).
	 */
	public function test_returns_empty_array_without_calling_the_endpoint_when_no_customer_id_is_stored(): void {
		$user_id = self::factory()->user->create();

		$this->payment_tokens_endpoint->shouldNotReceive( 'payment_tokens_for_customer' );

		$this->assertSame( array(), $this->sut->customer_tokens( $user_id ) );
	}

	/**
	 * @testdox Should use the current customer ID meta key when present (wallet).
	 */
	public function test_uses_the_current_customer_id_meta_key_when_present(): void {
		$user_id = $this->user_with_customer_id( '_ppcp_target_customer_id', 'CUST-1' );
		$tokens  = array( array( 'id' => 'TOKEN-1' ) );
		$this->payment_tokens_endpoint->shouldReceive( 'payment_tokens_for_customer' )->once()->with( 'CUST-1' )->andReturn( $tokens );

		$this->assertSame( $tokens, $this->sut->customer_tokens( $user_id ) );
	}

	/**
	 * @testdox Should fall back to the legacy customer ID meta key (wallet).
	 */
	public function test_falls_back_to_the_legacy_customer_id_meta_key(): void {
		$user_id = $this->user_with_customer_id( 'ppcp_customer_id', 'CUST-LEGACY' );
		$tokens  = array( array( 'id' => 'TOKEN-1' ) );
		$this->payment_tokens_endpoint->shouldReceive( 'payment_tokens_for_customer' )->once()->with( 'CUST-LEGACY' )->andReturn( $tokens );

		$this->assertSame( $tokens, $this->sut->customer_tokens( $user_id ) );
	}

	/**
	 * @testdox Should return an empty array when the endpoint fails (wallet).
	 */
	public function test_returns_empty_array_when_the_endpoint_fails(): void {
		$user_id = $this->user_with_customer_id( '_ppcp_target_customer_id', 'CUST-1' );
		$this->payment_tokens_endpoint->shouldReceive( 'payment_tokens_for_customer' )->andThrow( new RuntimeException( 'Bad Request' ) );

		$this->assertSame( array(), $this->sut->customer_tokens( $user_id ) );
	}

	/**
	 * @testdox Should report a PayPal or Venmo token only for those payment sources: $payment_source_name (wallet).
	 * @dataProvider payment_source_name_provider
	 *
	 * @param string $payment_source_name The payment source of the stored token.
	 * @param bool   $expected            Whether a PayPal or Venmo token is reported.
	 */
	public function test_has_paypal_or_venmo_token_detects_matching_payment_source( string $payment_source_name, bool $expected ): void {
		$user_id = $this->user_with_customer_id( '_ppcp_target_customer_id', 'CUST-1' );
		$this->payment_tokens_endpoint->shouldReceive( 'payment_tokens_for_customer' )
			->with( 'CUST-1' )
			->andReturn( array( $this->customer_token( $payment_source_name ) ) );

		$this->assertSame( $expected, $this->sut->has_paypal_or_venmo_token( $user_id ) );
	}

	/**
	 * Payment source names and whether they count.
	 *
	 * @return array
	 */
	public function payment_source_name_provider(): array {
		return array(
			'paypal token present' => array( 'paypal', true ),
			'venmo token present'  => array( 'venmo', true ),
			'only a card token'    => array( 'card', false ),
		);
	}

	/**
	 * @testdox Should report no PayPal or Venmo token without asking the endpoint when no customer ID is stored (wallet).
	 */
	public function test_has_paypal_or_venmo_token_returns_false_without_a_customer_id(): void {
		$user_id = self::factory()->user->create();

		$this->payment_tokens_endpoint->shouldNotReceive( 'payment_tokens_for_customer' );

		$this->assertFalse( $this->sut->has_paypal_or_venmo_token( $user_id ) );
	}

	/**
	 * @testdox Should save a PayPal token with its account email under the PayPal gateway (wallet).
	 */
	public function test_create_payment_token_paypal_saves_a_token_with_the_email(): void {
		$this->register_token_classes();
		$user_id = self::factory()->user->create();

		$id = $this->sut->create_payment_token_paypal( $user_id, 'VAULT-PP', 'shopper@example.com' );

		$this->assertGreaterThan( 0, $id );
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID );
		$this->assertCount( 1, $tokens );
		$token = reset( $tokens );
		$this->assertInstanceOf( PaymentTokenPayPal::class, $token );
		$this->assertSame( 'VAULT-PP', $token->get_token() );
		$this->assertSame( 'shopper@example.com', $token->get_email() );
	}

	/**
	 * @testdox Should save no PayPal token twice for the same vault ID and none for a guest (wallet).
	 */
	public function test_create_payment_token_paypal_skips_duplicates_and_guests(): void {
		$this->register_token_classes();
		$user_id = self::factory()->user->create();

		$this->assertGreaterThan( 0, $this->sut->create_payment_token_paypal( $user_id, 'VAULT-PP', '' ) );

		$this->assertSame( 0, $this->sut->create_payment_token_paypal( $user_id, 'VAULT-PP', '' ) );
		$this->assertSame( 0, $this->sut->create_payment_token_paypal( 0, 'VAULT-GUEST', '' ) );
		$this->assertCount( 1, WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID ) );
	}

	/**
	 * @testdox Should save a Venmo token with its account email under the PayPal gateway (wallet).
	 */
	public function test_create_payment_token_venmo_saves_a_token_with_the_email(): void {
		$this->register_token_classes();
		$user_id = self::factory()->user->create();

		$id = $this->sut->create_payment_token_venmo( $user_id, 'VAULT-VN', 'venmo@example.com' );

		$this->assertGreaterThan( 0, $id );
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID );
		$token  = reset( $tokens );
		$this->assertInstanceOf( PaymentTokenVenmo::class, $token );
		$this->assertSame( 'venmo@example.com', $token->get_email() );
	}

	/**
	 * @testdox Should create WooCommerce tokens only for the PayPal vault tokens of a customer (wallet).
	 */
	public function test_create_wc_tokens_creates_tokens_for_paypal_sources_only(): void {
		$this->register_token_classes();
		$user_id = self::factory()->user->create();

		$this->sut->create_wc_tokens(
			array(
				$this->customer_token( 'paypal', 'VAULT-PP', (object) array( 'email_address' => 'shopper@example.com' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$this->customer_token( 'venmo', 'VAULT-VN' ),
			),
			$user_id
		);

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID );
		$this->assertCount( 1, $tokens );
		$this->assertSame( 'VAULT-PP', reset( $tokens )->get_token() );
	}
}
