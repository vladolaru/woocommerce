<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCheckBody;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoRefusalListener;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CryptoCheckMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * Tests for the listener that records Pay with Crypto as not available when PayPal refuses a checkout's crypto order for
 * the payment source.
 *
 * @group paypal-wallet
 */
class CryptoRefusalListenerTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The logger the listener writes to.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * A stored "available" answer for the platform merchant.
	 *
	 * @return array
	 */
	private function available(): array {
		return array(
			'available'   => true,
			'checked_at'  => 1,
			'environment' => 'sandbox',
			'merchant_id' => 'M2',
		);
	}

	/**
	 * The listener over the stored state and a ready fake transport.
	 *
	 * @param array $transport_options The fake transport's options.
	 * @return CryptoRefusalListener
	 */
	private function listener( array $transport_options = array() ): CryptoRefusalListener {
		$this->logger = new RecordingLogger();

		return new CryptoRefusalListener( new ConnectionState(), new FakePlatformTransport( $transport_options ), new CryptoCheckMarker( new Options() ), $this->logger );
	}

	/**
	 * The request arguments of a crypto order creation for the platform merchant, as WordPress reports them: the payee
	 * seam 10 names and the assertion the signer adds.
	 *
	 * @param string $custom_id The purchase unit's custom ID.
	 * @param string $payee     The payee merchant ID.
	 * @return array
	 */
	private function crypto_args( string $custom_id = '42', string $payee = 'M2' ): array {
		return array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization'         => 'Bearer token-platform',
				'PayPal-Auth-Assertion' => 'assertion',
			),
			'body'    => (string) wp_json_encode(
				array(
					'purchase_units' => array(
						array(
							'custom_id' => $custom_id,
							'payee'     => array( 'merchant_id' => $payee ),
						),
					),
					'payment_source' => array( 'crypto' => array( 'country_code' => 'US' ) ),
				)
			),
		);
	}

	/**
	 * A 422 answer with the given issue.
	 *
	 * @param string $issue The issue.
	 * @return array
	 */
	private function refusal( string $issue = CryptoCreateAnswer::NOT_ENABLED_ISSUE ): array {
		return $this->http_response(
			422,
			(string) wp_json_encode(
				array(
					'name'     => 'UNPROCESSABLE_ENTITY',
					'details'  => array( array( 'issue' => $issue ) ),
					'debug_id' => 'refusal-debug',
				)
			)
		);
	}

	/**
	 * @testdox Should record "not available" for a checkout's crypto order that PayPal refuses for the payment source on a platform-connected store.
	 */
	public function test_records_a_refusal(): void {
		$this->set_platform_connected();
		$this->set_wallet_option( Options::PWC_CHECK, $this->available() );

		$this->listener()->handle_http_api_debug( $this->refusal(), 'response', 'WpOrg\Requests\Requests', $this->crypto_args(), 'https://api.platform.fake.test/v2/checkout/orders' );

		$marker = get_option( Options::PWC_CHECK );
		$this->assertFalse( $marker['available'] );
		$this->assertSame( 'M2', $marker['merchant_id'] );
		$this->assertCount( 1, $this->logger->records );
		$this->assertStringContainsString( 'refusal-debug', $this->logger->records[0]['message'] );
	}

	/**
	 * @testdox Should leave the answer alone for anything but a refused crypto order creation of a platform-connected store: $scenario.
	 * @testWith ["another issue"]
	 *           ["accepted"]
	 *           ["not a crypto order"]
	 *           ["another host"]
	 *           ["a read"]
	 *           ["the check's own order"]
	 *           ["a request context"]
	 *           ["collecting"]
	 *           ["transport not ready"]
	 *           ["another payee"]
	 *           ["no assertion"]
	 *           ["another environment"]
	 *
	 * @param string $scenario The scenario.
	 */
	public function test_leaves_the_answer_alone( string $scenario ): void {
		if ( 'collecting' === $scenario ) {
			$this->set_collecting();
		} else {
			$this->set_platform_connected();
		}
		$this->set_wallet_option( Options::PWC_CHECK, $this->available() );
		$response = $this->refusal();
		$args     = $this->crypto_args();
		$url      = 'https://api.platform.fake.test/v2/checkout/orders';
		$context  = 'response';
		$options  = array();
		switch ( $scenario ) {
			case 'another issue':
				$response = $this->refusal( 'DUPLICATE_INVOICE_ID' );
				break;
			case 'accepted':
				$response = $this->http_response( 200, '{"id":"PP-1","status":"PAYER_ACTION_REQUIRED"}' );
				break;
			case 'not a crypto order':
				$args['body'] = '{"purchase_units":[{"custom_id":"42"}]}';
				break;
			case 'another host':
				$url = 'https://api.example.test/v2/checkout/orders';
				break;
			case 'a read':
				$args['method'] = 'GET';
				break;
			case "the check's own order":
				$args = $this->crypto_args( CryptoCheckBody::CHECK_ID );
				break;
			case 'a request context':
				$context = 'request';
				break;
			case 'transport not ready':
				$options = array( 'ready' => false );
				break;
			case 'another payee':
				$args = $this->crypto_args( '42', 'OTHER' );
				break;
			case 'no assertion':
				unset( $args['headers']['PayPal-Auth-Assertion'] );
				break;
			case 'another environment':
				$options = array( 'environment' => 'production' );
				break;
		}

		$this->listener( $options )->handle_http_api_debug( $response, $context, 'WpOrg\Requests\Requests', $args, $url );

		$this->assertSame( $this->available(), get_option( Options::PWC_CHECK ) );
		$this->assertSame( array(), $this->logger->records );
	}

	/**
	 * @testdox Should record "not available" when the Pay with Crypto gateway's own order is refused at checkout, through the module's listener.
	 */
	public function test_the_gateways_refused_order_records_not_available(): void {
		$this->set_platform_connected();
		$this->set_wallet_option( Options::PWC_CHECK, $this->available() );
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$this->use_own_wc_session();
		$this->stub_http(
			function ( $request, $url ) {
				$response = $this->refusal();
				do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $request, $url );

				return $response;
			}
		);
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( PWCGateway::ID );
		$order->set_billing_country( 'US' );
		$order->set_total( '10.00' );
		$order->save();

		$result = $container->get( 'ppcp-local-apms.pwc.wc-gateway' )->process_payment( $order->get_id() );
		wc_clear_notices();

		$this->assertSame( 'failure', $result['result'] );
		$this->assertInstanceOf( WC_Order::class, wc_get_order( $order->get_id() ) );
		$this->assertFalse( get_option( Options::PWC_CHECK )['available'] );
	}
}
