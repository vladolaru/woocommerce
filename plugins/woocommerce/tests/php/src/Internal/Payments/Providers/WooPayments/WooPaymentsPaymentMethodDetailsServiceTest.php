<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use RuntimeException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPaymentMethodDetailsService class.
 */
class WooPaymentsPaymentMethodDetailsServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPaymentMethodDetailsService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsPaymentMethodDetailsService::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_legacy_proxy_mocks();
		parent::tearDown();
	}

	/**
	 * @testdox Empty payment method IDs return no details.
	 */
	public function test_returns_empty_when_payment_method_id_is_empty(): void {
		$this->assertSame( array(), $this->sut->get_payment_method_details( '' ) );
	}

	/**
	 * @testdox A missing WooPayments extension runtime returns no details.
	 */
	public function test_returns_empty_when_the_woopayments_extension_runtime_is_absent(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => function ( $class_name, $autoload = true ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return false;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);

		$this->assertSame( array(), $this->sut->get_payment_method_details( 'pm_123' ) );
	}

	/**
	 * @testdox A missing WooPayments extension runtime falls back to the API client when the built-in WooPayments owns the runtime.
	 */
	public function test_falls_back_to_the_api_client_when_the_woopayments_extension_runtime_is_absent(): void {
		// A Stripe PaymentMethod object (Stripe API PaymentMethod; the client reads type and card.brand/last4 at
		// class-wc-payments-payment-method-service.php:98-116, fetched through WC_Payments_API_Client::get_payment_method()).
		$details = array(
			'id'   => 'pm_native',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);

		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => 'WC_Payments' !== ltrim( (string) $class_name, '\\' ) && class_exists( $class_name ),
			)
		);
		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( wc_get_container()->get( LegacyProxy::class ) );

		$client = new class( $details ) extends Client {
				/**
				 * Details to return.
				 *
				 * @var array<string,mixed>
				 */
			private array $details;

				/**
				 * Payment method IDs the test double was asked for.
				 *
				 * @var string[]
				 */
			public array $requested_ids = array();

				/**
				 * Constructor.
				 *
				 * @param array<string,mixed> $details Details to return.
				 */
			public function __construct( array $details ) {
				$this->details = $details;
			}

				/**
				 * Get a payment method.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @return array<string,mixed>
				 */
			public function get_payment_method( string $payment_method_id ): array {
				$this->requested_ids[] = $payment_method_id;

				return $this->details;
			}
		};
		$sut    = new WooPaymentsPaymentMethodDetailsService();
		$sut->init( $extension_runtime, $client, new StaticWooPaymentsRuntimeArbiter( true ) );

		$this->assertSame( $details, $sut->get_payment_method_details( 'pm_123' ) );
		$this->assertSame( array( 'pm_123' ), $client->requested_ids );
	}

	/**
	 * @testdox WooPayments extension runtime requests proxy to its API client.
	 */
	public function test_proxies_the_woopayments_extension_api_client_when_its_runtime_is_active(): void {
		// The payment method shape the client's API client returns (class-wc-payments-payment-method-service.php:98-116 reads it).
		$details = array(
			'type' => 'card',
			'card' => array(
				'brand' => 'visa',
				'last4' => '4242',
			),
		);

		$client = new class( $details ) {
				/**
				 * Details to return.
				 *
				 * @var array<string,mixed>
				 */
			private array $details;

				/**
				 * Payment method IDs the test double was asked for.
				 *
				 * @var string[]
				 */
			public array $requested_ids = array();

				/**
				 * Constructor.
				 *
				 * @param array<string,mixed> $details Details to return.
				 */
			public function __construct( array $details ) {
				$this->details = $details;
			}

				/**
				 * Get a payment method.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @return array<string,mixed>
				 */
			public function get_payment_method( string $payment_method_id ): array {
				$this->requested_ids[] = $payment_method_id;

				return $this->details;
			}
		};
		$this->mock_woopayments_api_client( $client );

		$this->assertSame( $details, $this->sut->get_payment_method_details( 'pm_123' ) );
		$this->assertSame( array( 'pm_123' ), $client->requested_ids );
	}

	/**
	 * @testdox A client exception returns no details and is logged under woopayments only with debug logging $logging.
	 *
	 * The client's callers log a failed fetch through the gated Logger at error level (gw:5086); review 34 F3.
	 *
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $logging  Gateway `enable_logging` setting.
	 * @param bool   $expected Whether the line is written.
	 */
	public function test_logs_and_returns_empty_when_client_throws( string $logging, bool $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $logging ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger = RecordingWcLogger::install();

		$this->mock_woopayments_api_client(
			new class() {
				/**
				 * Get a payment method.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @throws RuntimeException Always thrown for this test double.
				 */
				public function get_payment_method( string $payment_method_id ) {
					unset( $payment_method_id );
					throw WooPaymentsPaymentMethodDetailsServiceTest::make_provider_error();
				}
			}
		);

		$this->assertSame( array(), $this->sut->get_payment_method_details( 'pm_123' ) );
		if ( ! $expected ) {
			$this->assertSame( array(), $logger->lines );
			return;
		}
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing', 'pm_123' ), array( $context['http_status'], $context['error_code'], $context['payment_method_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Mock WooPayments API client access.
	 *
	 * @param object $api_client API client.
	 */
	private function mock_woopayments_api_client( object $api_client ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => function ( $class_name, $autoload = true ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return true;
					}
					return class_exists( $class_name, $autoload );
				},
				'get_option'   => function ( $option, $default_value = false ) {
					if ( 'active_plugins' === $option ) {
						return array( WooPaymentsRuntimeArbiter::PLUGIN_FILE );
					}

					return get_option( $option, $default_value );
				},
			)
		);
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments' => array(
					'get_payments_api_client' => function () use ( $api_client ) {
						return $api_client;
					},
				),
			)
		);
	}
}
