<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\CryptoCheckCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCheck;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCheckBody;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AuthAssertion;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\LoggerBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use RuntimeException;
use WC_Order;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * Tests for the Pay with Crypto check: its body, the request it sends through the gateway's path, and the answer it
 * records.
 *
 * @group paypal-wallet
 */
class CryptoCheckTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The order creation requests the stub answered: one array( 'url', 'headers', 'body' ) per request, the body decoded.
	 *
	 * @var array<int, array{url: string, headers: array, body: mixed}>
	 */
	private array $creations = array();

	/**
	 * The logger the booted container's endpoints and listeners write to, under the module's redacting logger.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * Boot a container with a fake transport and a recording logger.
	 *
	 * @param array $transport_options The fake transport's options.
	 * @return ContainerInterface
	 */
	private function boot( array $transport_options = array() ): ContainerInterface {
		$this->logger = new RecordingLogger();

		return $this->boot_container(
			array( new TransportBindingModule( new FakePlatformTransport( $transport_options ) ) ),
			array( new LoggerBindingModule( $this->logger ) )
		);
	}

	/**
	 * The check from a container booted with a fake transport.
	 *
	 * @param array $transport_options The fake transport's options; a ready sandbox transport by default.
	 * @return CryptoCheck
	 */
	private function check( array $transport_options = array() ): CryptoCheck {
		return $this->boot( $transport_options )->get( 'collecting.crypto-check' );
	}

	/**
	 * Answer every order creation with the given status and body, record it, and report it through `http_api_debug` as
	 * WordPress does for a request it sent.
	 *
	 * @param int    $code     The HTTP status.
	 * @param array  $body     The JSON body.
	 * @param string $debug_id The paypal-debug-id header, or an empty string for none.
	 */
	private function answer_creations( int $code, array $body, string $debug_id = 'debug-1' ): void {
		$this->answer_in_turn( array( array( $code, $body, $debug_id ) ) );
	}

	/**
	 * Answer the order creations in turn, the last answer repeating: each an array( code, body, debug ID ), or a
	 * WP_Error, which WordPress reports as it does a failed transport. Record each creation.
	 *
	 * @param array $answers The answers.
	 */
	private function answer_in_turn( array $answers ): void {
		$this->stub_http(
			function ( $request, $url ) use ( $answers ) {
				if ( 1 !== preg_match( '#/v2/checkout/orders$#', (string) $url ) ) {
					return new \WP_Error( 'unrouted', 'Unrouted' );
				}
				$answer            = $answers[ min( count( $this->creations ), count( $answers ) - 1 ) ];
				$this->creations[] = array(
					'url'     => (string) $url,
					'headers' => (array) ( $request['headers'] ?? array() ),
					'body'    => json_decode( (string) ( $request['body'] ?? '' ), true ),
				);
				if ( $answer instanceof \WP_Error ) {
					$response = $answer;
				} else {
					$response            = $this->http_response( $answer[0], (string) wp_json_encode( $answer[1] ) );
					$response['headers'] = new CaseInsensitiveDictionary( '' === $answer[2] ? array() : array( 'paypal-debug-id' => $answer[2] ) );
				}
				do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $request, $url );

				return $response;
			}
		);
	}

	/**
	 * Assert that no output line and no log record carries a bearer token or the seller's assertion.
	 *
	 * @param array $lines The command's output lines.
	 */
	private function assert_no_secret( array $lines ): void {
		$assertion = AuthAssertion::header( 'client-' . PlatformTransport::APP_PLATFORM, 'M2' );
		$text      = wp_json_encode( $lines ) . "\n" . $this->logger->as_text();

		$this->assertStringNotContainsString( 'token-', $text, 'No bearer token' );
		foreach ( $assertion as $value ) {
			$this->assertStringNotContainsString( $value, $text, 'No assertion' );
		}
	}

	/**
	 * A 422 refusal for the payment source, as PayPal answered for a merchant without crypto.
	 *
	 * @return array
	 */
	private function refusal_body(): array {
		return array(
			'name'     => 'UNPROCESSABLE_ENTITY',
			'details'  => array(
				array(
					'field'       => '/payment_source',
					'issue'       => CryptoCreateAnswer::NOT_ENABLED_ISSUE,
					'description' => 'The \'API caller\' and/or \'payee\' is not setup to be able to process the selected payment source.',
				),
			),
			'debug_id' => 'body-debug',
		);
	}

	/**
	 * @testdox Should build the gateway's crypto body for USD 1.00, named as the check, with the store's country and name.
	 */
	public function test_the_body_is_the_gateways_without_an_order(): void {
		$this->set_platform_connected();
		// As WordPress stores a name typed with an ampersand.
		add_filter( 'pre_option_blogname', static fn() => 'Ada &amp; Co' );

		$body = $this->check()->body();

		$this->assertSame( 'ORDER_COMPLETE_ON_PAYMENT_APPROVAL', $body['processing_instruction'] );
		$this->assertSame( 'CAPTURE', $body['intent'] );
		$this->assertCount( 1, $body['purchase_units'] );
		$unit = $body['purchase_units'][0];
		$this->assertSame(
			array(
				'currency_code' => 'USD',
				'value'         => '1.00',
			),
			$unit['amount']
		);
		$this->assertSame( CryptoCheckBody::CHECK_ID, $unit['custom_id'] );
		$this->assertMatchesRegularExpression( '/^wc-paypal-wallet-crypto-check-\d{10}$/', $unit['invoice_id'] );
		$this->assertArrayNotHasKey( 'payee', $unit, 'Seam 10 adds the payee when the order is sent' );
		$crypto = $body['payment_source']['crypto'];
		$this->assertSame( WC()->countries->get_base_country(), $crypto['country_code'] );
		$this->assertSame( 'Ada & Co', $crypto['name']['given_name'] );
		$this->assertSame( 'Check', $crypto['name']['surname'] );
		$this->assertStringContainsString( 'order-received', $crypto['experience_context']['return_url'] );
		$this->assertStringContainsString( 'cancelled=true', $crypto['experience_context']['cancel_url'] );
	}

	/**
	 * @testdox Should send the check through the gateway's path, with the payee and the seller's assertion, and record an accepted order as available without creating a WooCommerce order.
	 */
	public function test_an_accepted_order_records_available(): void {
		$this->set_platform_connected();
		$check = $this->check();
		$this->answer_creations(
			200,
			array(
				'id'     => 'PP-CHECK-1',
				'status' => 'PAYER_ACTION_REQUIRED',
			)
		);
		$orders_before = wc_get_orders( array( 'return' => 'ids' ) );

		$result = $check->run();

		$this->assertSame( CryptoCreateAnswer::ACCEPTED, $result['outcome'] );
		$this->assertSame( 'PP-CHECK-1', $result['order_id'] );
		$this->assertSame( 'debug-1', $result['debug_id'] );
		$this->assertCount( 1, $this->creations );
		$this->assertSame( 'https://api.platform.fake.test/v2/checkout/orders', $this->creations[0]['url'] );
		$this->assertSame( 'M2', $this->creations[0]['body']['purchase_units'][0]['payee']['merchant_id'] ?? null, 'Seam 10 named the payee' );
		$this->assertTrue( isset( $this->creations[0]['headers']['PayPal-Auth-Assertion'] ), 'The signer added the seller\'s assertion' );
		$this->assertSame( 'Bearer token-' . PlatformTransport::APP_PLATFORM, $this->creations[0]['headers']['Authorization'] ?? null );
		$marker = get_option( Options::PWC_CHECK );
		$this->assertTrue( $marker['available'] );
		$this->assertSame( 'M2', $marker['merchant_id'] );
		$this->assertSame( 'sandbox', $marker['environment'] );
		$this->assertSame( $orders_before, wc_get_orders( array( 'return' => 'ids' ) ), 'No WooCommerce order' );
	}

	/**
	 * @testdox Should record a refusal for the payment source as not available, replacing an earlier "available".
	 */
	public function test_a_refusal_records_not_available(): void {
		$this->set_platform_connected();
		$this->set_wallet_option(
			Options::PWC_CHECK,
			array(
				'available'   => true,
				'checked_at'  => 1,
				'environment' => 'sandbox',
				'merchant_id' => 'M2',
			)
		);
		$check = $this->check();
		$this->answer_creations( 422, $this->refusal_body(), '' );

		$result = $check->run();

		$this->assertSame( CryptoCreateAnswer::REFUSED, $result['outcome'] );
		$this->assertSame( 'body-debug', $result['debug_id'] );
		$this->assertSame( '', $result['order_id'] );
		$this->assertFalse( get_option( Options::PWC_CHECK )['available'] );
	}

	/**
	 * @testdox Should leave the recorded answer as it is for any other answer: HTTP $code.
	 * @testWith [500, "INTERNAL_SERVER_ERROR", ""]
	 *           [422, "UNPROCESSABLE_ENTITY", "DUPLICATE_INVOICE_ID"]
	 *           [201, "", "CREATED"]
	 *
	 * @param int    $code   The HTTP status.
	 * @param string $name   The error name, or empty for an order.
	 * @param string $detail The issue, or the order status for an order.
	 */
	public function test_any_other_answer_keeps_the_marker( int $code, string $name, string $detail ): void {
		$this->set_platform_connected();
		$stored = array(
			'available'   => true,
			'checked_at'  => 1,
			'environment' => 'sandbox',
			'merchant_id' => 'M2',
		);
		$this->set_wallet_option( Options::PWC_CHECK, $stored );
		$check = $this->check();
		$this->answer_creations(
			$code,
			'' === $name ? array(
				'id'     => 'PP-CHECK-2',
				'status' => $detail,
			) : array(
				'name'    => $name,
				'details' => '' === $detail ? array() : array( array( 'issue' => $detail ) ),
			)
		);

		$result = $check->run();

		$this->assertSame( CryptoCreateAnswer::UNKNOWN, $result['outcome'] );
		$this->assertSame( $code, $result['http_status'] );
		$this->assertSame( $stored, get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should leave the recorded answer as it is when the request fails before PayPal answers.
	 */
	public function test_a_network_failure_keeps_the_marker(): void {
		$this->set_platform_connected();
		$check = $this->check();
		$this->stub_http( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$result = $check->run();

		$this->assertSame( CryptoCreateAnswer::UNKNOWN, $result['outcome'] );
		$this->assertSame( 'cURL error 28: timed out', $result['error'] );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should refuse to build or send the check on a store that is not platform connected: $state.
	 * @testWith ["collecting"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The store state.
	 */
	public function test_refuses_outside_the_platform_connected_state( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} elseif ( 'first_party' === $state ) {
			$this->set_first_party_connected();
		}
		$check = $this->check();
		$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );

		foreach ( array( 'body', 'run' ) as $method ) {
			try {
				$check->$method();
				$this->fail( "$method() should refuse" );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'The Pay with Crypto check runs only on a store that is platform connected to its PayPal merchant.', $exception->getMessage() );
			}
		}
		$this->assertSame( array(), $this->creations, 'Nothing is sent' );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should print and log no bearer token and no assertion: $scenario.
	 * @testWith ["accepted"]
	 *           ["refused"]
	 *           ["network failure"]
	 *
	 * @param string $scenario The answer.
	 */
	public function test_the_output_and_the_log_carry_no_secret( string $scenario ): void {
		$this->set_platform_connected();
		$check = $this->check();
		if ( 'accepted' === $scenario ) {
			$this->answer_creations(
				200,
				array(
					'id'     => 'PP-CHECK-1',
					'status' => 'PAYER_ACTION_REQUIRED',
				)
			);
		} elseif ( 'refused' === $scenario ) {
			$this->answer_creations( 422, $this->refusal_body() );
		} else {
			$this->answer_in_turn( array( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ) );
		}

		$lines = ( new CryptoCheckCommand( $check ) )->lines( false );

		$this->assertNotEmpty( $this->creations, 'The check sent its order' );
		$this->assertTrue( isset( $this->creations[0]['headers']['PayPal-Auth-Assertion'] ), 'The request carried the secrets the output must not' );
		if ( 'network failure' === $scenario ) {
			$this->assertStringContainsString( 'cURL error 28: timed out', end( $lines )[1], 'The transport\'s own message is reported' );
		}
		$this->assert_no_secret( $lines );
	}

	/**
	 * @testdox Should remove its http_api_debug listener after the request: $scenario.
	 * @testWith ["accepted"]
	 *           ["network failure"]
	 *
	 * @param string $scenario The answer.
	 */
	public function test_the_request_listener_is_removed( string $scenario ): void {
		$this->set_platform_connected();
		$check = $this->check();
		if ( 'accepted' === $scenario ) {
			$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );
		} else {
			$this->answer_in_turn( array( new \WP_Error( 'http_request_failed', 'timed out' ) ) );
		}
		$before = count( $GLOBALS['wp_filter']['http_api_debug']->callbacks[10] ?? array() );

		$check->run();

		$this->assertSame( $before, count( $GLOBALS['wp_filter']['http_api_debug']->callbacks[10] ?? array() ) );
	}

	/**
	 * @testdox Should read the retried request's answer after the wallet's auth retry: $scenario.
	 * @testWith ["a refusal", "refused"]
	 *           ["a network failure", "unknown"]
	 *
	 * @param string $scenario The retry's answer.
	 * @param string $expected The outcome.
	 */
	public function test_the_retry_answer_wins( string $scenario, string $expected ): void {
		$this->set_platform_connected();
		$this->set_wallet_option(
			Options::PWC_CHECK,
			array(
				'available'   => true,
				'checked_at'  => 1,
				'environment' => 'sandbox',
				'merchant_id' => 'M2',
			)
		);
		$check = $this->check();
		$this->answer_in_turn(
			array(
				array( 401, array( 'name' => 'AUTHENTICATION_FAILURE' ), 'debug-401' ),
				'a refusal' === $scenario ? array( 422, $this->refusal_body(), 'debug-422' ) : new \WP_Error( 'http_request_failed', 'timed out' ),
			)
		);

		$result = $check->run();

		$this->assertCount( 2, $this->creations, 'The wallet retried once' );
		$this->assertSame( $expected, $result['outcome'] );
		$this->assertSame( 'a refusal' !== $scenario, get_option( Options::PWC_CHECK )['available'] );
	}

	/**
	 * @testdox Should refuse to send while the transport serves another environment than the platform connection's.
	 */
	public function test_refuses_another_environment(): void {
		$this->set_platform_connected();
		$check = $this->check( array( 'environment' => 'production' ) );
		$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );

		try {
			$check->run();
			$this->fail( 'run() should refuse' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'The platform transport serves the production environment, but the platform connection was made in sandbox, so the check would not answer for it.', $exception->getMessage() );
		}
		$this->assertSame( array(), $this->creations, 'Nothing is sent' );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should record nothing for an answer about an order not made for the platform merchant: $scenario.
	 * @testWith ["another payee"]
	 *           ["no assertion"]
	 *
	 * @param string $scenario How the order differs.
	 */
	public function test_an_order_not_for_the_merchant_is_not_recorded( string $scenario ): void {
		$this->set_platform_connected();
		$check = $this->check();
		if ( 'another payee' === $scenario ) {
			// A third-party callback on the public body filter, after the module's payee.
			add_filter(
				'ppcp_create_order_request_body_data',
				static function ( $body ) {
					$body['purchase_units'][0]['payee'] = array( 'merchant_id' => 'OTHER' );
					return $body;
				},
				200
			);
		} else {
			add_filter(
				'ppcp_request_args',
				static function ( $args ) {
					unset( $args['headers']['PayPal-Auth-Assertion'] );
					return $args;
				},
				99
			);
		}
		$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );

		$result = $check->run();

		$this->assertCount( 1, $this->creations );
		$this->assertSame( CryptoCreateAnswer::UNKNOWN, $result['outcome'] );
		$this->assertFalse( $result['recorded'] );
		$this->assertSame( 'PayPal\'s answer could not be matched to an order for the platform merchant with the seller\'s assertion, so it is not recorded.', $result['error'] );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should report an answer it could not store, when the platform connection loses its environment meanwhile.
	 */
	public function test_reports_an_answer_it_could_not_store(): void {
		$this->set_platform_connected();
		$check = $this->check();
		$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );
		add_action(
			'http_api_debug',
			static function () {
				update_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
			},
			5
		);

		$result = $check->run();

		$this->assertSame( CryptoCreateAnswer::ACCEPTED, $result['outcome'] );
		$this->assertFalse( $result['recorded'] );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should never run on completing onboarding: complete() sends no crypto order.
	 */
	public function test_completing_onboarding_does_not_run_the_check(): void {
		$this->set_collecting();
		$container = $this->boot();
		$this->answer_creations( 200, array( 'status' => 'PAYER_ACTION_REQUIRED' ) );

		$container->get( 'collecting.state' )->complete( 'M2' );

		$this->assertTrue( $container->get( 'collecting.state' )->is_platform_connected(), 'Onboarding completed' );
		$this->assertSame( array(), $this->creations, 'No order was created' );
		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * Every key path of a nested array, lists by position.
	 *
	 * @param array  $data   The array.
	 * @param string $prefix The path so far.
	 * @return string[]
	 */
	private function key_paths( array $data, string $prefix = '' ): array {
		$paths = array();
		foreach ( $data as $key => $value ) {
			$path    = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$paths[] = $path;
			if ( is_array( $value ) ) {
				$paths = array_merge( $paths, $this->key_paths( $value, $path ) );
			}
		}
		sort( $paths );

		return $paths;
	}

	/**
	 * @testdox Should send the same body shape, with the same fixed values, as the Pay with Crypto gateway.
	 */
	public function test_the_body_matches_the_gateways(): void {
		$this->set_platform_connected();
		$container = $this->boot();
		$this->answer_creations(
			200,
			array(
				'id'     => 'PP-1',
				'status' => 'PAYER_ACTION_REQUIRED',
				'links'  => array(
					array(
						'rel'  => 'payer-action',
						'href' => 'https://www.sandbox.paypal.test/checkoutnow?token=PP-1',
					),
				),
			)
		);
		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( PWCGateway::ID );
		$order->set_billing_country( 'US' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_total( '10.00' );
		$order->save();

		$container->get( 'ppcp-local-apms.pwc.wc-gateway' )->process_payment( $order->get_id() );
		$container->get( 'collecting.crypto-check' )->run();
		wc_clear_notices();

		$this->assertCount( 2, $this->creations );
		list( $gateway, $check ) = array( $this->creations[0]['body'], $this->creations[1]['body'] );
		$this->assertInstanceOf( WC_Order::class, wc_get_order( $order->get_id() ) );
		$this->assertSame( $this->key_paths( $gateway ), $this->key_paths( $check ), 'The same keys, payee included' );
		foreach ( array( 'processing_instruction', 'intent' ) as $key ) {
			$this->assertSame( $gateway[ $key ], $check[ $key ] );
		}
		$this->assertSame( $gateway['purchase_units'][0]['amount']['currency_code'], $check['purchase_units'][0]['amount']['currency_code'] );
		$this->assertSame( $gateway['purchase_units'][0]['reference_id'], $check['purchase_units'][0]['reference_id'] );
		$this->assertSame( $gateway['purchase_units'][0]['payee'], $check['purchase_units'][0]['payee'] );
		$this->assertSame( $gateway['payment_source']['crypto']['experience_context']['locale'] ?? null, $check['payment_source']['crypto']['experience_context']['locale'] ?? null );
	}
}
