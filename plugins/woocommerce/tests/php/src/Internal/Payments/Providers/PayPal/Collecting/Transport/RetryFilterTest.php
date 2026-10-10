<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\BearerRetryFilter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles\ContainerDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use WP_Error;

/**
 * Tests for the transport-aware retry filter that re-signs a request after an authentication failure.
 *
 * @group paypal-wallet
 */
class RetryFilterTest extends WalletTestCase {

	/**
	 * The dummy credentials.
	 */
	private const CREDENTIALS = array(
		'platform_client_id'         => 'platform-id',
		'platform_client_secret'     => 'platform-secret',
		'partner_merchant_id'        => 'PARTNER1',
		'merchant_app_client_id'     => 'merchant-app-id',
		'merchant_app_client_secret' => 'merchant-app-secret',
		'sandbox'                    => true,
	);

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * Tokens issued so far, by app.
	 *
	 * @var array<string, int>
	 */
	private array $issued = array();

	/**
	 * Put the store in the collecting state and claim the token transients.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$this->context = new OrderAppContext();
		foreach ( array( 'wc_paypal_wallet_bearer_platform_ppcp-bearer', 'wc_paypal_wallet_bearer_merchant_app_ppcp-bearer', 'wc_paypal_wallet_rate_platform_bearer-circuit-state' ) as $name ) {
			$this->set_wallet_transient( $name, 'claimed' );
			delete_transient( $name );
		}
	}

	/**
	 * Build a direct transport over the dummy credentials.
	 *
	 * @return DirectPlatformTransport
	 */
	private function transport(): DirectPlatformTransport {
		return new DirectPlatformTransport( self::CREDENTIALS, new NullLogger(), $this->context, new Options() );
	}

	/**
	 * Build the filter over a transport.
	 *
	 * @param PlatformTransport $transport The transport.
	 * @return BearerRetryFilter
	 */
	private function build_sut( PlatformTransport $transport ): BearerRetryFilter {
		return new BearerRetryFilter( new ConnectionState(), $transport, $this->context, new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), new NullLogger() );
	}

	/**
	 * Stub the token endpoint with numbered tokens per app and the referral endpoint with the given statuses in turn.
	 *
	 * @param int[] $referral_statuses The status each referral request gets, in turn.
	 */
	private function stub_tokens_and_referrals( array $referral_statuses ): void {
		$referrals = 0;
		$this->stub_http(
			function ( $request, $url ) use ( &$referrals, $referral_statuses ) {
				if ( false !== strpos( $url, 'v1/oauth2/token' ) ) {
					$app                  = 'Basic ' . base64_encode( 'platform-id:platform-secret' ) === $request['headers']['Authorization'] ? 'platform' : 'merchant_app'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Recognizes the app by its basic credentials.
					$this->issued[ $app ] = ( $this->issued[ $app ] ?? 0 ) + 1;
					return $this->http_response( 200, '{"access_token":"token-' . $app . '-' . $this->issued[ $app ] . '","expires_in":32400}' );
				}
				$status = $referral_statuses[ min( $referrals++, count( $referral_statuses ) - 1 ) ];
				return $this->http_response( $status, 201 === $status ? '{"links":[{"rel":"action_url","href":"https://example.com/onboard"}]}' : '{"name":"AUTHENTICATION_FAILURE"}' );
			}
		);
	}

	/**
	 * The callbacks of the retry hook at a priority that are this filter.
	 *
	 * @param int $priority The priority.
	 * @return int How many.
	 */
	private function count_at_priority( int $priority ): int {
		$count = 0;
		foreach ( $GLOBALS['wp_filter']['ppcp_retry_request_args']->callbacks[ $priority ] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof BearerRetryFilter ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @testdox Should add the filter at priority 20 when the module runs for a platform-served store.
	 */
	public function test_run_adds_the_filter_at_priority_20(): void {
		$container = new ContainerDouble(
			array(
				'collecting.connection-state'    => new ConnectionState(),
				'collecting.transport'           => $this->transport(),
				'collecting.order-app-context'   => $this->context,
				'collecting.state'               => new CollectingState( new Options(), new FixedHeldOrders( 0 ) ),
				'woocommerce.logger.woocommerce' => new NullLogger(),
			)
		);

		( new CollectingModule() )->run( $container );

		$this->assertSame( 1, $this->count_at_priority( 20 ) );
		$this->assertSame( 0, $this->count_at_priority( 10 ) );
		foreach ( $GLOBALS['wp_filter']['ppcp_retry_request_args']->callbacks[20] as $callback ) {
			$this->assertSame( 2, $callback['accepted_args'], 'The filter receives the URL' );
		}
	}

	/**
	 * @testdox Should not add the filter when the platform does not serve the store.
	 */
	public function test_run_adds_no_filter_when_not_served(): void {
		delete_option( Options::COLLECTING );
		$before = $this->count_at_priority( 20 );

		( new CollectingModule() )->run( new ContainerDouble( array( 'collecting.connection-state' => new ConnectionState() ) ) );

		$this->assertSame( $before, $this->count_at_priority( 20 ) );
	}

	/**
	 * @testdox Should drop the current app's cached token and sign the retry with a fresh one of the same app.
	 */
	public function test_retry_uses_the_fresh_token_of_the_current_app(): void {
		$transport = $this->transport();
		$this->stub_tokens_and_referrals( array( 401, 201 ) );
		add_filter( 'ppcp_retry_request_args', array( $this->build_sut( $transport ), 'handle_ppcp_retry_request_args' ), 20, 2 );

		$link = $transport->referral_link( 'track-1', 'https://shop.example.com/return' );

		$this->assertSame( 'https://example.com/onboard', $link );
		$api = array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false === strpos( $entry['url'], 'v1/oauth2/token' );
				}
			)
		);
		$this->assertCount( 2, $api );
		$this->assertSame( 'Bearer token-platform-1', $api[0]['request']['headers']['Authorization'] );
		$this->assertSame( 'Bearer token-platform-2', $api[1]['request']['headers']['Authorization'], 'The retry carries the second token' );
		$this->assertSame( array( 'platform' => 2 ), $this->issued, 'Only the platform app was asked for a new token' );
	}

	/**
	 * @testdox Should leave a retry that carries the seller's assertion to the refund signer, asking the order's app for no token.
	 */
	public function test_retry_leaves_an_asserted_refund_to_the_signer(): void {
		$transport = $this->transport();
		$this->stub_tokens_and_referrals( array( 201 ) );
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );
		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization'         => 'Bearer token-merchant_app-0',
				'PayPal-Auth-Assertion' => 'eyJhbGciOiJub25lIn0.e30.',
			),
		);

		$retry = $this->build_sut( $transport )->handle_ppcp_retry_request_args( $args, 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1/refund' );

		$this->assertSame( $args, $retry );
		$this->assertSame( array(), $this->issued, 'No token of the order\'s app is dropped or issued' );
	}

	/**
	 * @testdox Should leave the other app's cached token alone.
	 */
	public function test_retry_keeps_the_other_apps_token(): void {
		$transport = $this->transport();
		$this->stub_tokens_and_referrals( array( 401, 201 ) );
		$transport->bearer( PlatformTransport::APP_MERCHANT_APP )->bearer();
		add_filter( 'ppcp_retry_request_args', array( $this->build_sut( $transport ), 'handle_ppcp_retry_request_args' ), 20, 2 );

		$transport->referral_link( 'track-1', 'https://shop.example.com/return' );

		$this->assertNotFalse( get_transient( 'wc_paypal_wallet_bearer_merchant_app_ppcp-bearer' ) );
		$this->assertSame( 1, $this->issued['merchant_app'] );
	}

	/**
	 * @testdox Should re-sign with the app the order context entered.
	 */
	public function test_retry_follows_the_entered_app(): void {
		$transport = $this->transport();
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );
		$sut  = $this->build_sut( $transport );
		$args = array( 'headers' => array( 'Authorization' => 'Bearer stale' ) );
		$this->stub_tokens_and_referrals( array( 201 ) );

		$result = $sut->handle_ppcp_retry_request_args( $args, 'https://api-m.sandbox.paypal.com/v2/x' );

		$this->assertSame( 'Bearer token-merchant_app-1', $result['headers']['Authorization'] );
	}

	/**
	 * @testdox Should leave a request that is not Bearer-signed alone.
	 */
	public function test_retry_ignores_other_authorization(): void {
		$this->stub_tokens_and_referrals( array( 201 ) );
		$sut = $this->build_sut( $this->transport() );

		$this->assertSame( array( 'headers' => array( 'Authorization' => 'Basic abc' ) ), $sut->handle_ppcp_retry_request_args( array( 'headers' => array( 'Authorization' => 'Basic abc' ) ), 'https://api-m.sandbox.paypal.com/v2/x' ) );
		$this->assertSame( array(), $sut->handle_ppcp_retry_request_args( array(), 'https://api-m.sandbox.paypal.com/v2/x' ) );
		$this->assertSame( array(), $this->issued, 'No token was requested' );
	}

	/**
	 * @testdox Should return the request untouched when the refresh fails, or the transport is not ready.
	 */
	public function test_retry_survives_a_failed_refresh(): void {
		$args = array( 'headers' => array( 'Authorization' => 'Bearer stale' ) );
		$this->stub_http( new WP_Error( 'down', 'down' ) );

		$this->assertSame( $args, $this->build_sut( $this->transport() )->handle_ppcp_retry_request_args( $args, 'https://api-m.sandbox.paypal.com/v2/x' ), 'A failed token request' );

		$not_ready = new NotReadyTransport( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$this->assertSame( $args, $this->build_sut( $not_ready )->handle_ppcp_retry_request_args( $args, 'https://api-m.sandbox.paypal.com/v2/x' ), 'A transport that is not ready' );
	}

	/**
	 * @testdox Should pass anything that is not an argument array through.
	 */
	public function test_retry_passes_a_non_array_through(): void {
		$this->assertSame( 'x', $this->build_sut( $this->transport() )->handle_ppcp_retry_request_args( 'x', 'https://api-m.sandbox.paypal.com/v2/x' ) );
	}

	/**
	 * @testdox Should leave the request untouched once the platform no longer serves the store.
	 */
	public function test_retry_does_nothing_when_the_store_is_no_longer_served(): void {
		$sut = $this->build_sut( $this->transport() );
		delete_option( Options::COLLECTING );
		$args = array( 'headers' => array( 'Authorization' => 'Bearer stale' ) );

		$this->assertSame( $args, $sut->handle_ppcp_retry_request_args( $args, 'https://api-m.sandbox.paypal.com/v2/x' ) );
	}

	/**
	 * @testdox Should leave a request to another host untouched and ask for no token, so a platform token never goes to it.
	 * @testWith ["https://example.com/v2/x"]
	 *           ["https://api-m.sandbox.paypal.com.evil.test/v2/x"]
	 *           ["http://api-m.sandbox.paypal.com/v2/x"]
	 *           [""]
	 *           [null]
	 *
	 * @param string|null $url The request URL.
	 */
	public function test_retry_ignores_other_hosts( ?string $url ): void {
		$this->stub_tokens_and_referrals( array( 201 ) );
		$args = array( 'headers' => array( 'Authorization' => 'Bearer stale' ) );

		$this->assertSame( $args, $this->build_sut( $this->transport() )->handle_ppcp_retry_request_args( $args, $url ) );
		$this->assertSame( array(), $this->issued, 'No token was requested' );
	}

	/**
	 * @testdox Should leave a request alone when no URL is passed.
	 */
	public function test_retry_ignores_a_call_without_a_url(): void {
		$this->stub_tokens_and_referrals( array( 201 ) );
		$args = array( 'headers' => array( 'Authorization' => 'Bearer stale' ) );

		$this->assertSame( $args, $this->build_sut( $this->transport() )->handle_ppcp_retry_request_args( $args ) );
	}
}
