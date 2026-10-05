<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
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
	 * @testdox Missing plugin runtime returns no details.
	 */
	public function test_returns_empty_when_plugin_runtime_is_absent(): void {
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
	 * @testdox Missing plugin runtime falls back to the native API client when native owns the runtime.
	 */
	public function test_falls_back_to_native_api_client_when_plugin_runtime_is_absent(): void {
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

		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyRuntimeProxy( false ) );

		$sut = new WooPaymentsPaymentMethodDetailsService();
		$sut->init(
			$legacy_runtime,
			new class( $details ) extends WooPaymentsApiClient {
				/**
				 * Details to return.
				 *
				 * @var array<string,mixed>
				 */
				private array $details;

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
					return $this->details + array( 'requested_id' => $payment_method_id );
				}
			},
			new StaticNativeRuntimeArbiter( true )
		);

		$this->assertSame( $details + array( 'requested_id' => 'pm_123' ), $sut->get_payment_method_details( 'pm_123' ) );
	}

	/**
	 * @testdox Plugin runtime requests proxy to the WooPayments API client.
	 */
	public function test_proxies_plugin_api_client_when_plugin_runtime_is_active(): void {
		$details = array(
			'type' => 'card',
			'card' => array(
				'brand' => 'visa',
				'last4' => '4242',
			),
		);

		$this->mock_woopayments_api_client(
			new class( $details ) {
				/**
				 * Details to return.
				 *
				 * @var array<string,mixed>
				 */
				private array $details;

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
					return $this->details + array( 'requested_id' => $payment_method_id );
				}
			}
		);

		$this->assertSame( $details + array( 'requested_id' => 'pm_123' ), $this->sut->get_payment_method_details( 'pm_123' ) );
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
		$lines = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 'Error retrieving WooPayments payment method details for pm_123.' === $line[1] ) );
		if ( ! $expected ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$this->assertCount( 1, $lines );
		$this->assertSame( 'error', $logger->lines[ $lines[0] ][0] );
		$this->assertSame( array( 404, 'resource_missing' ), array( $logger->contexts[ $lines[0] ]['http_status'], $logger->contexts[ $lines[0] ]['error_code'] ) );
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
						return array( NativePaymentsRuntimeArbiter::PLUGIN_FILE );
					}

					return get_option( $option, $default_value );
				},
			)
		);
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();

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
