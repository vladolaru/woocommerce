<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AuthAssertion;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PerAppBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\AbstractLogger;
use WP_Error;

/**
 * Tests for the direct platform transport, with the apps' credentials handed to the constructor and HTTP stubbed.
 *
 * @group paypal-wallet
 */
class DirectPlatformTransportTest extends WalletTestCase {

	private const SANDBOX_HOST = 'https://api-m.sandbox.paypal.com';

	private const PLATFORM_TOKEN_TRANSIENT = 'wc_paypal_wallet_bearer_platform_ppcp-bearer';

	private const MERCHANT_APP_TOKEN_TRANSIENT = 'wc_paypal_wallet_bearer_merchant_app_ppcp-bearer';

	private const PLATFORM_RATE_TRANSIENT = 'wc_paypal_wallet_rate_platform_bearer-circuit-state';

	private const MERCHANT_APP_RATE_TRANSIENT = 'wc_paypal_wallet_rate_merchant_app_bearer-circuit-state';

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
	 * The System Under Test.
	 *
	 * @var DirectPlatformTransport
	 */
	private DirectPlatformTransport $sut;

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * Every message and context the transport logged, as one string per record.
	 *
	 * @var string[]
	 */
	private array $logged = array();

	/**
	 * How many tokens each app was issued, by app.
	 *
	 * @var array<string, int>
	 */
	private array $issued = array();

	/**
	 * Build the transport over a recording logger and claim the token transients it writes.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->context = new OrderAppContext();
		$this->sut     = $this->build( array() );
		foreach ( array( self::PLATFORM_TOKEN_TRANSIENT, self::MERCHANT_APP_TOKEN_TRANSIENT, self::PLATFORM_RATE_TRANSIENT, self::MERCHANT_APP_RATE_TRANSIENT ) as $name ) {
			$this->set_wallet_transient( $name, 'claimed' );
			delete_transient( $name );
		}
	}

	/**
	 * Build a transport with the dummy credentials, some of them overridden.
	 *
	 * @param array $overrides Credential overrides.
	 * @return DirectPlatformTransport
	 */
	private function build( array $overrides ): DirectPlatformTransport {
		$logged = &$this->logged;
		$logger = new class( $logged ) extends AbstractLogger {
			/**
			 * The records.
			 *
			 * @var string[]
			 */
			private array $records;

			/**
			 * Constructor.
			 *
			 * @param string[] $records The records, by reference.
			 */
			public function __construct( array &$records ) {
				$this->records = &$records;
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed  $level   The level.
			 * @param string $message The message.
			 * @param array  $context The context.
			 */
			public function log( $level, $message, array $context = array() ): void {
				$this->records[] = $level . ' ' . $message . ' ' . wc_print_r( $context, true );
			}
		};

		return new DirectPlatformTransport( $overrides + self::CREDENTIALS, $logger, $this->context, new Options() );
	}

	/**
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'MERCHANT1' ) );
	}

	/**
	 * Answer the token endpoint with a numbered token per app and every other request through a router.
	 *
	 * @param array $routes Responses by "METHOD path" suffix; a list of responses answers one per call, the last repeating.
	 */
	private function stub_api( array $routes ): void {
		$counts = array();
		$this->stub_http(
			function ( $request, $url ) use ( $routes, &$counts ) {
				if ( false !== strpos( $url, 'v1/oauth2/token' ) ) {
					$app                  = 'Basic ' . base64_encode( 'platform-id:platform-secret' ) === $request['headers']['Authorization'] ? 'platform' : 'merchant_app'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Recognizes the app by its basic credentials.
					$this->issued[ $app ] = ( $this->issued[ $app ] ?? 0 ) + 1;
					return $this->http_response( 200, '{"access_token":"token-' . $app . '-' . $this->issued[ $app ] . '","expires_in":32400}' );
				}
				foreach ( $routes as $key => $response ) {
					list( $method, $suffix ) = explode( ' ', $key, 2 );
					if ( $method === $request['method'] && substr( $url, -strlen( $suffix ) ) === $suffix ) {
						$counts[ $key ] = $counts[ $key ] ?? 0;
						$single         = $response instanceof WP_Error || isset( $response['response'] );
						$index          = $single ? null : min( $counts[ $key ]++, count( $response ) - 1 );
						return null === $index ? $response : $response[ $index ];
					}
				}
				return new WP_Error( 'unrouted', "Unrouted $url" );
			}
		);
	}

	/**
	 * The recorded API requests, leaving out the token ones.
	 *
	 * @return array[]
	 */
	private function api_requests(): array {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false === strpos( $entry['url'], 'v1/oauth2/token' );
				}
			)
		);
	}

	/**
	 * @testdox Should be ready only with all six values set and non-empty.
	 */
	public function test_is_ready_needs_every_value(): void {
		$this->assertTrue( $this->sut->is_ready() );
		foreach ( array_keys( self::CREDENTIALS ) as $key ) {
			$this->assertFalse( $this->build( array( $key => 'sandbox' === $key ? null : '' ) )->is_ready(), "$key missing is not ready" );
		}
		$this->assertTrue( $this->build( array( 'sandbox' => false ) )->is_ready(), 'A production flag still counts as set' );
	}

	/**
	 * @testdox Should report the environment and host the sandbox flag selects, for both apps.
	 */
	public function test_environment_and_hosts(): void {
		$this->assertSame( 'sandbox', $this->sut->environment() );
		$this->assertSame( self::SANDBOX_HOST, $this->sut->host( PlatformTransport::APP_PLATFORM ) );
		$this->assertSame( self::SANDBOX_HOST, $this->sut->host( PlatformTransport::APP_MERCHANT_APP ) );

		$production = $this->build( array( 'sandbox' => false ) );
		$this->assertSame( 'production', $production->environment() );
		$this->assertSame( 'https://api-m.paypal.com', $production->host( PlatformTransport::APP_PLATFORM ) );
	}

	/**
	 * @testdox Should hand back the partner merchant ID and each app's client ID.
	 */
	public function test_partner_and_client_ids(): void {
		$this->assertSame( 'PARTNER1', $this->sut->partner_merchant_id() );
		$this->assertSame( 'platform-id', $this->sut->sdk_client_id( PlatformTransport::APP_PLATFORM ) );
		$this->assertSame( 'merchant-app-id', $this->sut->sdk_client_id( PlatformTransport::APP_MERCHANT_APP ) );
	}

	/**
	 * @testdox Should pick the merchant app while the platform option has no merchant ID, and the platform once it has.
	 */
	public function test_pick_order_app(): void {
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->sut->pick_order_app( 'x@example.com' ) );
		$this->set_wallet_option( Options::PLATFORM, array( 'tracking_id' => 'abc' ) );
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->sut->pick_order_app( 'x@example.com' ) );

		$this->set_platform_connected();

		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->pick_order_app( 'x@example.com' ) );
	}

	/**
	 * @testdox Should pick without any HTTP request or option write.
	 */
	public function test_pick_is_pure(): void {
		$this->sut->pick_order_app( 'x@example.com' );
		$this->set_platform_connected();
		$before = get_option( Options::PLATFORM );
		$this->sut->pick_order_app( 'x@example.com' );

		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( $before, get_option( Options::PLATFORM ) );
		$this->assertFalse( get_option( Options::COLLECTING ) );
		$this->assertFalse( get_option( Options::WEBHOOKS ) );
	}

	/**
	 * @testdox Should hand out one bearer per app, each issuing from its own credentials into its own token cache.
	 */
	public function test_bearers_are_per_app_with_their_own_caches(): void {
		$this->stub_api( array() );
		$platform     = $this->sut->bearer( PlatformTransport::APP_PLATFORM );
		$merchant_app = $this->sut->bearer( PlatformTransport::APP_MERCHANT_APP );

		$this->assertInstanceOf( PerAppBearer::class, $platform );
		$this->assertInstanceOf( PerAppBearer::class, $merchant_app );
		$this->assertNotSame( $platform, $merchant_app );
		$this->assertSame( $platform, $this->sut->bearer( PlatformTransport::APP_PLATFORM ), 'The bearer is built once per app' );

		$this->assertSame( 'token-platform-1', $platform->bearer()->token() );
		$this->assertSame( 'token-merchant_app-1', $merchant_app->bearer()->token() );
		$this->assertSame( 'token-platform-1', $platform->bearer()->token(), 'The second call reads the cached token' );
		$this->assertSame( array( 1, 1 ), array_values( $this->issued ) );

		$this->assertNotFalse( get_transient( self::PLATFORM_TOKEN_TRANSIENT ) );
		$this->assertNotFalse( get_transient( self::MERCHANT_APP_TOKEN_TRANSIENT ) );
		$this->assertFalse( get_transient( 'ppcp-bearer' ), 'The wallet\'s own token cache is never written' );
		$this->assertSame( 'Basic ' . base64_encode( 'platform-id:platform-secret' ), $this->http_requests[0]['request']['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds the expected header.
		$this->assertSame( 'Basic ' . base64_encode( 'merchant-app-id:merchant-app-secret' ), $this->http_requests[1]['request']['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds the expected header.
		$this->assertSame( self::SANDBOX_HOST . '/v1/oauth2/token?grant_type=client_credentials', $this->http_requests[0]['url'] );
	}

	/**
	 * @testdox Should keep each app's token request cool-down in its own cache.
	 */
	public function test_a_failed_token_request_pauses_only_its_app(): void {
		$this->stub_http( new WP_Error( 'down', 'down' ) );
		try {
			$this->sut->bearer( PlatformTransport::APP_PLATFORM )->bearer();
			$this->fail( 'A failed token request throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertNotFalse( get_transient( self::PLATFORM_RATE_TRANSIENT ) );
			$this->assertFalse( get_transient( self::MERCHANT_APP_RATE_TRANSIENT ) );
		}
	}

	/**
	 * @testdox Should forget the cached token of one app only.
	 */
	public function test_forget_deletes_one_apps_token(): void {
		$this->stub_api( array() );
		$this->sut->bearer( PlatformTransport::APP_PLATFORM )->bearer();
		$this->sut->bearer( PlatformTransport::APP_MERCHANT_APP )->bearer();

		$this->sut->bearer( PlatformTransport::APP_PLATFORM )->forget();

		$this->assertFalse( get_transient( self::PLATFORM_TOKEN_TRANSIENT ) );
		$this->assertNotFalse( get_transient( self::MERCHANT_APP_TOKEN_TRANSIENT ) );
		$this->assertSame( 'token-platform-2', $this->sut->bearer( PlatformTransport::APP_PLATFORM )->bearer()->token() );
	}

	/**
	 * @testdox Should send no assertion header while no seller is known, and the unsigned JWT of the calling app once one is.
	 */
	public function test_assertion_header(): void {
		$this->assertSame( array(), $this->sut->assertion_header( PlatformTransport::APP_PLATFORM ) );
		$this->assertSame( array(), $this->sut->assertion_header( PlatformTransport::APP_MERCHANT_APP ) );

		$this->set_platform_connected();

		$this->assertSame( AuthAssertion::header( 'platform-id', 'MERCHANT1' ), $this->sut->assertion_header( PlatformTransport::APP_PLATFORM ) );
		$this->assertSame( AuthAssertion::header( 'merchant-app-id', 'MERCHANT1' ), $this->sut->assertion_header( PlatformTransport::APP_MERCHANT_APP ) );
	}

	/**
	 * @testdox Should post a third-party referral for the tracking ID with no vaulting, signed by the platform, and return the action link.
	 */
	public function test_referral_link(): void {
		$this->stub_api(
			array(
				'POST /v2/customer/partner-referrals' => $this->http_response( 201, '{"links":[{"rel":"self","href":"https://example.com/self"},{"rel":"action_url","href":"https://example.com/onboard"}]}' ),
			)
		);

		$link = $this->sut->referral_link( 'track-1', 'https://shop.example.com/return' );

		$this->assertSame( 'https://example.com/onboard', $link );
		$requests = $this->api_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( self::SANDBOX_HOST . '/v2/customer/partner-referrals', $requests[0]['url'] );
		$this->assertSame( 'Bearer token-platform-1', $requests[0]['request']['headers']['Authorization'] );
		$body = json_decode( $requests[0]['request']['body'], true );
		$this->assertSame( 'track-1', $body['tracking_id'] );
		$this->assertSame( array( 'EXPRESS_CHECKOUT' ), $body['products'] );
		$this->assertSame( 'https://shop.example.com/return', $body['partner_config_override']['return_url'] );
		$this->assertSame( 'API_INTEGRATION', $body['operations'][0]['operation'] );
		$integration = $body['operations'][0]['api_integration_preference']['rest_api_integration'];
		$this->assertSame( 'THIRD_PARTY', $integration['integration_type'] );
		$this->assertSame( 'PAYPAL', $integration['integration_method'] );
		$this->assertSame( array( 'PAYMENT', 'REFUND', 'PARTNER_FEE', 'DELAY_FUNDS_DISBURSEMENT' ), $integration['third_party_details']['features'] );
		$this->assertStringNotContainsString( 'VAULT', $requests[0]['request']['body'], 'No vaulting product, capability or feature' );
		$this->assertArrayNotHasKey( 'PayPal-Auth-Assertion', $requests[0]['request']['headers'] );
	}

	/**
	 * @testdox Should throw the wallet's exception when the referral has no action link or PayPal refuses it.
	 * @testWith [201, "{\"links\":[]}"]
	 *           [400, "{\"name\":\"INVALID_REQUEST\",\"message\":\"bad\"}"]
	 *           [500, "not json"]
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 */
	public function test_referral_link_failures_throw_the_wallets_exception( int $status, string $body ): void {
		$this->stub_api( array( 'POST /v2/customer/partner-referrals' => $this->http_response( $status, $body ) ) );
		$this->expectException( RuntimeException::class );

		$this->sut->referral_link( 'track-1', 'https://shop.example.com/return' );
	}

	/**
	 * @testdox Should read the seller status by tracking ID and map receivable payments, a confirmed email and the third-party consent.
	 */
	public function test_seller_status_by_tracking_id(): void {
		$this->stub_api(
			array(
				'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response(
					200,
					'{"merchant_id":"M1","payments_receivable":true,"primary_email_confirmed":true,"oauth_integrations":[{"integration_type":"OAUTH_THIRD_PARTY","oauth_third_party":[{"partner_client_id":"p","scopes":["s"]}]}]}'
				),
			)
		);

		$status = $this->sut->seller_status( 'track-1' );

		$this->assertSame( array( 'M1', true, true, true ), array( $status->merchant_id(), $status->payments_receivable(), $status->primary_email_confirmed(), $status->consent_granted() ) );
		$this->assertTrue( $status->is_complete() );
		$this->assertCount( 1, $this->api_requests() );
		$this->assertSame( 'Bearer token-platform-1', $this->api_requests()[0]['request']['headers']['Authorization'] );
	}

	/**
	 * @testdox Should read the merchant's full status when the tracking lookup only names the merchant.
	 */
	public function test_seller_status_follows_up_by_merchant_id(): void {
		$this->stub_api(
			array(
				'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 200, '{"merchant_id":"M1","tracking_id":"track-1","links":[]}' ),
				'GET /v1/customer/partners/PARTNER1/merchant-integrations/M1'                  => $this->http_response( 200, '{"merchant_id":"M1","payments_receivable":true,"primary_email_confirmed":false,"oauth_integrations":[]}' ),
			)
		);

		$status = $this->sut->seller_status( 'track-1' );

		$this->assertSame( array( 'M1', true, false, false ), array( $status->merchant_id(), $status->payments_receivable(), $status->primary_email_confirmed(), $status->consent_granted() ) );
		$this->assertFalse( $status->is_complete() );
		$this->assertCount( 2, $this->api_requests() );
	}

	/**
	 * @testdox Should read an unknown tracking ID as a seller that has not signed up.
	 */
	public function test_seller_status_for_an_unknown_tracking_id(): void {
		$this->stub_api( array( 'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 404, '{"name":"RESOURCE_NOT_FOUND_ERROR"}' ) ) );

		$status = $this->sut->seller_status( 'track-1' );

		$this->assertSame( array( '', false, false, false ), array( $status->merchant_id(), $status->payments_receivable(), $status->primary_email_confirmed(), $status->consent_granted() ) );
	}

	/**
	 * @testdox Should throw the wallet's exception when the seller status request fails.
	 */
	public function test_seller_status_failure_throws_the_wallets_exception(): void {
		$this->stub_api( array( 'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 503, '' ) ) );
		$this->expectException( RuntimeException::class );

		$this->sut->seller_status( 'track-1' );
	}

	/**
	 * @testdox Should create a subscription for each app with its events, store the IDs, and create none on a second call.
	 */
	public function test_subscribe_webhooks_creates_each_missing_subscription_once(): void {
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => array( $this->webhook_created( 'WH-A' ), $this->webhook_created( 'WH-B' ) ) ) );

		$ids = $this->sut->subscribe_webhooks( 'https://shop.example.com/wp-json/paypal/v1/incoming' );

		$this->assertSame(
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			),
			$ids
		);
		$this->assertSame( $ids, get_option( Options::WEBHOOKS ) );
		$this->assertSame( $ids, $this->sut->webhook_subscriptions() );
		$requests = $this->api_requests();
		$this->assertCount( 2, $requests );
		$platform_body = json_decode( $requests[0]['request']['body'], true );
		$this->assertSame( 'https://shop.example.com/wp-json/paypal/v1/incoming', $platform_body['url'] );
		$this->assertSame( 'Bearer token-platform-1', $requests[0]['request']['headers']['Authorization'] );
		$this->assertSame(
			array( 'MERCHANT.ONBOARDING.COMPLETED', 'MERCHANT.PARTNER-CONSENT.REVOKED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.DENIED' ),
			array_column( $platform_body['event_types'], 'name' ),
			'PayPal accepts exact event names only, not a prefix wildcard'
		);
		$merchant_body = json_decode( $requests[1]['request']['body'], true );
		$this->assertSame( 'Bearer token-merchant_app-1', $requests[1]['request']['headers']['Authorization'] );
		$this->assertSame(
			array( 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.DENIED', 'CHECKOUT.ORDER.APPROVED' ),
			array_column( $merchant_body['event_types'], 'name' )
		);

		$this->assertSame( $ids, $this->sut->subscribe_webhooks( 'https://shop.example.com/wp-json/paypal/v1/incoming' ) );
		$this->assertCount( 2, $this->api_requests(), 'A second call creates nothing' );
	}

	/**
	 * @testdox Should create only the subscription that is missing, and keep the stored one.
	 */
	public function test_subscribe_webhooks_creates_only_the_missing_one(): void {
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => $this->webhook_created( 'WH-B' ) ) );

		$ids = $this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );

		$this->assertSame(
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			),
			$ids
		);
		$this->assertCount( 1, $this->api_requests() );
		$this->assertSame( 'Bearer token-merchant_app-1', $this->api_requests()[0]['request']['headers']['Authorization'] );
	}

	/**
	 * @testdox Should keep the subscription it created and throw the wallet's exception when the other one fails.
	 */
	public function test_subscribe_webhooks_keeps_what_it_created_when_a_later_one_fails(): void {
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => array( $this->webhook_created( 'WH-A' ), $this->http_response( 400, '{"name":"INVALID_REQUEST","message":"bad"}' ) ) ) );

		try {
			$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );
			$this->fail( 'The failed subscription throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( array( PlatformTransport::APP_PLATFORM => 'WH-A' ), get_option( Options::WEBHOOKS ) );
		}
	}

	/**
	 * @testdox Should still try the merchant app when the platform app fails, keep what succeeded, and rethrow the first failure.
	 */
	public function test_subscribe_webhooks_tries_every_app_when_an_earlier_one_fails(): void {
		$refusal = $this->http_response( 400, '{"name":"VALIDATION_ERROR","message":"Invalid data provided"}' );
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => array( $refusal, $this->webhook_created( 'WH-B' ) ) ) );

		try {
			$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );
			$this->fail( 'The failed subscription throws once every app was tried' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'VALIDATION_ERROR', $exception instanceof PayPalApiException ? $exception->name() : '', 'The platform app\'s failure is the one rethrown' );
		}

		$requests = $this->api_requests();
		$this->assertCount( 2, $requests, 'Both apps were tried' );
		$this->assertSame( 'Bearer token-merchant_app-1', $requests[1]['request']['headers']['Authorization'] );
		$this->assertSame( array( PlatformTransport::APP_MERCHANT_APP => 'WH-B' ), get_option( Options::WEBHOOKS ), 'Only the app that succeeded is stored' );
	}

	/**
	 * @testdox Should rethrow the first failure, store nothing, and try every app when all of them fail.
	 */
	public function test_subscribe_webhooks_rethrows_the_first_failure_when_every_app_fails(): void {
		$this->stub_api(
			array(
				'POST /v1/notifications/webhooks' => array(
					$this->http_response( 400, '{"name":"VALIDATION_ERROR","message":"first"}' ),
					$this->http_response( 400, '{"name":"INVALID_REQUEST","message":"second"}' ),
				),
			)
		);

		try {
			$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );
			$this->fail( 'The failed subscriptions throw' );
		} catch ( PayPalApiException $exception ) {
			$this->assertSame( 'VALIDATION_ERROR', $exception->name() );
		}

		$this->assertCount( 2, $this->api_requests(), 'Both apps were tried' );
		$this->assertFalse( get_option( Options::WEBHOOKS ), 'Nothing is stored when nothing succeeded' );
	}

	/**
	 * @testdox Should never write the wallet's own webhook option.
	 */
	public function test_subscribe_webhooks_does_not_touch_the_wallets_webhook_option(): void {
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => $this->webhook_created( 'WH-A' ) ) );

		$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );

		$this->assertFalse( get_option( 'ppcp-webhook' ) );
	}

	/**
	 * @testdox Should delete each stored subscription with its own app's bearer and then delete the option.
	 */
	public function test_unsubscribe_webhooks(): void {
		$this->set_wallet_option(
			Options::WEBHOOKS,
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			)
		);
		$this->stub_api(
			array(
				'DELETE /v1/notifications/webhooks/WH-A' => $this->http_response( 204, '' ),
				'DELETE /v1/notifications/webhooks/WH-B' => $this->http_response( 204, '' ),
			)
		);

		$this->sut->unsubscribe_webhooks();

		$requests = $this->api_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( self::SANDBOX_HOST . '/v1/notifications/webhooks/WH-A', $requests[0]['url'] );
		$this->assertSame( 'Bearer token-platform-1', $requests[0]['request']['headers']['Authorization'] );
		$this->assertSame( self::SANDBOX_HOST . '/v1/notifications/webhooks/WH-B', $requests[1]['url'] );
		$this->assertSame( 'Bearer token-merchant_app-1', $requests[1]['request']['headers']['Authorization'] );
		$this->assertFalse( get_option( Options::WEBHOOKS ) );
		$this->assertSame( array(), $this->sut->webhook_subscriptions() );
	}

	/**
	 * @testdox Should send nothing when it holds no subscriptions.
	 */
	public function test_unsubscribe_webhooks_with_none(): void {
		$this->stub_api( array() );

		$this->sut->unsubscribe_webhooks();

		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * @testdox Should keep the subscription PayPal could not delete, drop the others, and throw the wallet's exception.
	 */
	public function test_unsubscribe_webhooks_keeps_a_failed_deletion(): void {
		$this->set_wallet_option(
			Options::WEBHOOKS,
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			)
		);
		$this->stub_api(
			array(
				'DELETE /v1/notifications/webhooks/WH-A' => $this->http_response( 500, '{"name":"INTERNAL_SERVICE_ERROR","message":"x"}' ),
				'DELETE /v1/notifications/webhooks/WH-B' => $this->http_response( 204, '' ),
			)
		);

		try {
			$this->sut->unsubscribe_webhooks();
			$this->fail( 'The failed deletion throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( array( PlatformTransport::APP_PLATFORM => 'WH-A' ), get_option( Options::WEBHOOKS ) );
		}
	}

	/**
	 * @testdox Should treat a subscription PayPal no longer knows as deleted.
	 */
	public function test_unsubscribe_webhooks_treats_not_found_as_deleted(): void {
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api( array( 'DELETE /v1/notifications/webhooks/WH-A' => $this->http_response( 404, '{"name":"INVALID_RESOURCE_ID"}' ) ) );

		$this->sut->unsubscribe_webhooks();

		$this->assertFalse( get_option( Options::WEBHOOKS ) );
	}

	/**
	 * The headers of a delivery PayPal signed.
	 *
	 * @return array
	 */
	private function signed_headers(): array {
		return array(
			'paypal-auth-algo'         => 'SHA256withRSA',
			'PAYPAL-CERT-URL'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
			'Paypal-Transmission-Id'   => 'TX-1',
			'PAYPAL-TRANSMISSION-SIG'  => 'SIG-1',
			'PAYPAL-TRANSMISSION-TIME' => '2026-10-09T10:00:00Z',
			'Content-Type'             => 'application/json',
		);
	}

	/**
	 * @testdox Should post the delivery to the verification endpoint with the app's bearer and webhook ID, and accept a SUCCESS verdict.
	 */
	public function test_verify_webhook_succeeds(): void {
		$this->set_wallet_option(
			Options::WEBHOOKS,
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			)
		);
		$this->stub_api( array( 'POST /v1/notifications/verify-webhook-signature' => $this->http_response( 200, '{"verification_status":"SUCCESS"}' ) ) );
		$event = '{"id":"EV-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"CAP-1"}}';

		$this->assertTrue( $this->sut->verify_webhook( PlatformTransport::APP_MERCHANT_APP, $this->signed_headers(), $event ) );

		$requests = $this->api_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'Bearer token-merchant_app-1', $requests[0]['request']['headers']['Authorization'] );
		$this->assertSame(
			array(
				'transmission_id'   => 'TX-1',
				'transmission_time' => '2026-10-09T10:00:00Z',
				'cert_url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
				'auth_algo'         => 'SHA256withRSA',
				'transmission_sig'  => 'SIG-1',
				'webhook_id'        => 'WH-B',
				'webhook_event'     => json_decode( $event, true ),
			),
			json_decode( $requests[0]['request']['body'], true )
		);
	}

	/**
	 * @testdox Should refuse a delivery PayPal does not verify, one with a missing header or a bad body, and one for an app with no subscription.
	 */
	public function test_verify_webhook_refuses_what_it_cannot_verify(): void {
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api( array( 'POST /v1/notifications/verify-webhook-signature' => $this->http_response( 200, '{"verification_status":"FAILURE"}' ) ) );
		$event = '{"id":"EV-1"}';

		$this->assertFalse( $this->sut->verify_webhook( PlatformTransport::APP_PLATFORM, $this->signed_headers(), $event ), 'PayPal says FAILURE' );
		$requests_so_far = count( $this->http_requests );

		$headers = $this->signed_headers();
		unset( $headers['PAYPAL-TRANSMISSION-SIG'] );
		$this->assertFalse( $this->sut->verify_webhook( PlatformTransport::APP_PLATFORM, $headers, $event ), 'A header is missing' );
		$this->assertFalse( $this->sut->verify_webhook( PlatformTransport::APP_PLATFORM, $this->signed_headers(), 'not json' ), 'The body is not an event' );
		$this->assertFalse( $this->sut->verify_webhook( PlatformTransport::APP_MERCHANT_APP, $this->signed_headers(), $event ), 'The app holds no subscription' );
		$this->assertCount( $requests_so_far, $this->http_requests, 'None of those reach PayPal' );
	}

	/**
	 * @testdox Should throw the wallet's exception when the verification request fails.
	 */
	public function test_verify_webhook_failure_throws_the_wallets_exception(): void {
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api( array( 'POST /v1/notifications/verify-webhook-signature' => new WP_Error( 'down', 'down' ) ) );
		$this->expectException( RuntimeException::class );

		$this->sut->verify_webhook( PlatformTransport::APP_PLATFORM, $this->signed_headers(), '{"id":"EV-1"}' );
	}

	/**
	 * @testdox Should throw the wallet's exception for an app it does not know, from every method that takes one.
	 */
	public function test_unknown_app_throws_the_wallets_exception(): void {
		$calls  = array(
			function () {
				$this->sut->sdk_client_id( 'first_party' );
			},
			function () {
				$this->sut->bearer( 'first_party' );
			},
			function () {
				$this->sut->host( 'first_party' );
			},
			function () {
				$this->sut->assertion_header( 'first_party' );
			},
			function () {
				$this->sut->verify_webhook( 'first_party', array(), '{}' );
			},
		);
		$caught = 0;
		foreach ( $calls as $call ) {
			try {
				$call();
			} catch ( RuntimeException $exception ) {
				++$caught;
			}
		}

		$this->assertSame( count( $calls ), $caught, 'Every call threw the wallet\'s exception' );
	}

	/**
	 * @testdox Should throw the wallet's exception, not a PHP error, when the token response is not a token.
	 */
	public function test_a_malformed_token_response_throws_the_wallets_exception(): void {
		$this->stub_http( $this->http_response( 200, 'not json' ) );
		$this->expectException( RuntimeException::class );

		$this->sut->bearer( PlatformTransport::APP_PLATFORM )->bearer();
	}

	/**
	 * @testdox Should throw the wallet's exception when a transport call has no response at all.
	 */
	public function test_a_network_failure_throws_the_wallets_exception(): void {
		$this->stub_api( array( 'POST /v2/customer/partner-referrals' => new WP_Error( 'http_request_failed', 'cURL error 28' ) ) );
		$this->expectException( RuntimeException::class );

		$this->sut->referral_link( 'track-1', 'https://shop.example.com/return' );
	}

	/**
	 * @testdox Should never log a secret, a token or an authorization header, on success or failure.
	 */
	public function test_nothing_secret_reaches_the_log(): void {
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api(
			array(
				'POST /v2/customer/partner-referrals' => $this->http_response( 400, '{"name":"INVALID_REQUEST","message":"bad"}' ),
				'POST /v1/notifications/verify-webhook-signature' => new WP_Error( 'down', 'down' ),
				'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=t' => $this->http_response( 200, '{"merchant_id":"","payments_receivable":false}' ),
			)
		);
		foreach (
			array(
				function () {
					$this->sut->referral_link( 't', 'https://shop.example.com/return' );
				},
				function () {
					$this->sut->verify_webhook( PlatformTransport::APP_PLATFORM, $this->signed_headers(), '{"id":"EV-1"}' );
				},
				function () {
					$this->sut->seller_status( 't' );
				},
			) as $call
		) {
			try {
				$call();
			} catch ( RuntimeException $exception ) {
				unset( $exception );
			}
		}
		$this->stub_http( new WP_Error( 'down', 'down' ) );
		try {
			$this->sut->bearer( PlatformTransport::APP_MERCHANT_APP )->bearer();
		} catch ( RuntimeException $exception ) {
			unset( $exception );
		}

		$this->assertNotEmpty( $this->logged, 'The failures were logged' );
		$all = implode( "\n", $this->logged );
		foreach ( array( 'platform-secret', 'merchant-app-secret', 'token-platform', 'token-merchant_app', 'Bearer ', base64_encode( 'platform-id:platform-secret' ), base64_encode( 'merchant-app-id:merchant-app-secret' ) ) as $secret ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds the values that must not appear.
			$this->assertStringNotContainsString( $secret, $all );
		}
	}

	/**
	 * @testdox Should sign a transport call with the app it names and leave the order app context as it found it.
	 */
	public function test_a_call_enters_its_app_and_restores_the_context(): void {
		$seen = array();
		add_filter(
			'ppcp_request_args',
			function ( $args, $url ) use ( &$seen ) {
				if ( false === strpos( $url, 'oauth2' ) ) {
					$seen[] = $this->context->current();
				}
				return $args;
			},
			10,
			2
		);
		$this->stub_api( array( 'POST /v2/customer/partner-referrals' => $this->http_response( 201, '{"links":[{"rel":"action_url","href":"https://example.com/o"}]}' ) ) );

		$this->sut->referral_link( 't', 'https://shop.example.com/return' );
		$this->assertFalse( $this->context->is_entered(), 'Not entered before, not entered after' );

		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );
		$this->sut->referral_link( 't', 'https://shop.example.com/return' );

		$this->assertSame( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_PLATFORM ), $seen );
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->context->current(), 'An entered context is put back' );
	}

	/**
	 * @testdox Should adopt the webhook PayPal already holds for the URL when a create is refused as a duplicate.
	 */
	public function test_subscribe_webhooks_adopts_an_existing_subscription(): void {
		$url = 'https://shop.example.com/hook';
		$this->stub_api(
			array(
				'POST /v1/notifications/webhooks' => array( $this->webhook_created( 'WH-A' ), $this->http_response( 400, '{"name":"INVALID_REQUEST","details":[{"issue":"WEBHOOK_URL_ALREADY_EXISTS"}]}' ) ),
				'GET /v1/notifications/webhooks'  => $this->http_response( 200, '{"webhooks":[{"id":"WH-OTHER","url":"https://other.example.com/hook","event_types":[]},{"id":"WH-B","url":"' . $url . '","event_types":[]}]}' ),
			)
		);

		$ids = $this->sut->subscribe_webhooks( $url );

		$this->assertSame(
			array(
				PlatformTransport::APP_PLATFORM     => 'WH-A',
				PlatformTransport::APP_MERCHANT_APP => 'WH-B',
			),
			$ids
		);
		$this->assertSame( $ids, get_option( Options::WEBHOOKS ) );
		$requests = $this->api_requests();
		$this->assertSame( array( 'POST', 'POST', 'GET' ), array_column( array_column( $requests, 'request' ), 'method' ) );
		$this->assertSame( 'Bearer token-merchant_app-1', $requests[2]['request']['headers']['Authorization'], 'The list is read with the same app\'s bearer' );
	}

	/**
	 * @testdox Should adopt the webhook when the duplicate error names the issue at the top level.
	 */
	public function test_subscribe_webhooks_adopts_on_a_named_duplicate_error(): void {
		$url = 'https://shop.example.com/hook';
		$this->set_wallet_option( Options::WEBHOOKS, array( PlatformTransport::APP_PLATFORM => 'WH-A' ) );
		$this->stub_api(
			array(
				'POST /v1/notifications/webhooks' => $this->http_response( 400, '{"name":"WEBHOOK_URL_ALREADY_EXISTS","message":"exists"}' ),
				'GET /v1/notifications/webhooks'  => $this->http_response( 200, '{"webhooks":[{"id":"WH-B","url":"' . $url . '","event_types":[]}]}' ),
			)
		);

		$this->assertSame( 'WH-B', $this->sut->subscribe_webhooks( $url )[ PlatformTransport::APP_MERCHANT_APP ] );
	}

	/**
	 * @testdox Should throw the wallet's exception and store nothing when the duplicate has no match in the list.
	 */
	public function test_subscribe_webhooks_throws_when_no_listed_webhook_matches(): void {
		$this->stub_api(
			array(
				'POST /v1/notifications/webhooks' => $this->http_response( 400, '{"name":"WEBHOOK_URL_ALREADY_EXISTS","message":"exists"}' ),
				'GET /v1/notifications/webhooks'  => $this->http_response( 200, '{"webhooks":[{"id":"WH-OTHER","url":"https://other.example.com/hook","event_types":[]}]}' ),
			)
		);

		try {
			$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );
			$this->fail( 'A duplicate with no match throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( Options::WEBHOOKS ) );
		}
	}

	/**
	 * @testdox Should not list webhooks after any other refusal.
	 */
	public function test_subscribe_webhooks_lists_only_for_a_duplicate(): void {
		$this->stub_api( array( 'POST /v1/notifications/webhooks' => $this->http_response( 400, '{"name":"INVALID_REQUEST","message":"bad"}' ) ) );

		try {
			$this->sut->subscribe_webhooks( 'https://shop.example.com/hook' );
			$this->fail( 'The refusal throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( array( 'POST', 'POST' ), array_column( array_column( $this->api_requests(), 'request' ), 'method' ), 'Only the creates were sent, one per app' );
		}
	}

	/**
	 * @testdox Should throw the wallet's exception when the seller status answer is not a JSON object.
	 * @testWith ["not json"]
	 *           [""]
	 *           ["[1,2]"]
	 *
	 * @param string $body The body of a 200 answer.
	 */
	public function test_seller_status_with_a_non_object_answer_throws( string $body ): void {
		$this->stub_api( array( 'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 200, $body ) ) );
		$this->expectException( RuntimeException::class );

		$this->sut->seller_status( 'track-1' );
	}

	/**
	 * @testdox Should throw the wallet's exception when the merchant's own record is not a JSON object.
	 */
	public function test_seller_status_follow_up_with_a_non_object_answer_throws(): void {
		$this->stub_api(
			array(
				'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 200, '{"merchant_id":"M1"}' ),
				'GET /v1/customer/partners/PARTNER1/merchant-integrations/M1'                  => $this->http_response( 200, 'oops' ),
			)
		);
		$this->expectException( RuntimeException::class );

		$this->sut->seller_status( 'track-1' );
	}

	/**
	 * @testdox Should log the error name of a seller lookup that finds nobody, at debug level and without secrets.
	 */
	public function test_seller_status_not_found_logs_the_error_name(): void {
		$this->stub_api( array( 'GET /v1/customer/partners/PARTNER1/merchant-integrations?tracking_id=track-1' => $this->http_response( 404, '{"name":"INVALID_RESOURCE_ID"}' ) ) );

		$this->sut->seller_status( 'track-1' );

		$matching = array_filter(
			$this->logged,
			static function ( string $record ): bool {
				return 0 === strpos( $record, 'debug ' ) && false !== strpos( $record, 'seller lookup found no seller: INVALID_RESOURCE_ID' );
			}
		);
		$this->assertCount( 1, $matching );
		$this->assertStringNotContainsString( 'token-platform', implode( "\n", $this->logged ) );
	}

	/**
	 * A 201 response for a created webhook.
	 *
	 * @param string $id The webhook ID.
	 * @return array
	 */
	private function webhook_created( string $id ): array {
		return $this->http_response( 201, '{"id":"' . $id . '","url":"https://shop.example.com/hook","event_types":[{"name":"PAYMENT.CAPTURE.COMPLETED"}]}' );
	}
}
