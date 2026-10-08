<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Orders\PaymentInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderCardInfo class.
 */
class WooPaymentsOrderCardInfoTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wc_order_payment_card_info' );
		remove_all_filters( 'wcpay_dev_mode' );
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox The card info provider loads on every request once WooPayments is connected or active, as the client registers it everywhere.
	 */
	public function test_card_info_provider_loads_wherever_an_order_can_render(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();
		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron' ) as $request ) {
				$this->assertContains( WooPaymentsOrderCardInfo::class, $matrix[ $state ][ $request ], "{$state} {$request}" );
			}
		}
	}

	/**
	 * @testdox A paid order's card info comes from its stored payment method details, with no platform call (G1-5).
	 */
	public function test_card_info_reads_the_stored_details_without_a_platform_call(): void {
		$client = $this->create_recording_payment_method_client( array() );
		$this->register_card_info_service( $client );
		$order = $this->create_card_info_order( 'pm_stored' );
		// A charge's payment_method_details, as native order effects store them (WooPaymentsOrderEffects::compose_payment_method_display_effects()).
		$order->update_meta_data(
			'_wcpay_payment_method_details',
			wp_json_encode(
				array(
					'type' => 'card',
					'card' => array(
						'brand' => 'visa',
						'last4' => '4242',
					),
				)
			)
		);
		$order->save();

		$info = PaymentInfo::get_card_info( $order );

		$this->assertSame( 'visa', $info['brand'] );
		$this->assertSame( '4242', $info['last4'] );
		$this->assertSame( array(), $client->requested_ids, 'Stored details need no platform call.' );
	}

	/**
	 * @testdox Without stored details the payment method is fetched once and stored, so the next render makes no call.
	 */
	public function test_card_info_fetches_missing_details_once_and_stores_them(): void {
		// A Stripe PaymentMethod object (Stripe API PaymentMethod; client class-wc-payments-payment-method-service.php:78-93).
		$client = $this->create_recording_payment_method_client(
			array(
				'type' => 'card',
				'card' => array(
					'brand' => 'mastercard',
					'last4' => '4444',
				),
			)
		);
		$this->register_card_info_service( $client );
		$order = $this->create_card_info_order( 'pm_fetched' );

		$first  = PaymentInfo::get_card_info( wc_get_order( $order->get_id() ) );
		$second = PaymentInfo::get_card_info( wc_get_order( $order->get_id() ) );

		$this->assertSame( 'mastercard', $first['brand'] );
		$this->assertSame( '4444', $second['last4'] );
		$this->assertSame( array( 'pm_fetched' ), $client->requested_ids );
	}

	/**
	 * @testdox A $type terminal card shows its network when the client does ($network over $brand), with the network's icon.
	 * @testWith ["eftpos_au", "visa", "card_present"]
	 *           ["cartes_bancaires", "mastercard", "card_present"]
	 *           ["eftpos_au", "visa", "interac_present"]
	 *
	 * @param string $network Card network.
	 * @param string $brand   Card brand.
	 * @param string $type    Terminal payment method type.
	 */
	public function test_card_info_shows_the_terminal_network_and_its_icon( string $network, string $brand, string $type ): void {
		$this->register_card_info_service( $this->create_recording_payment_method_client( array() ) );
		$order = $this->create_card_info_order( 'pm_terminal' );
		// card_present details as a terminal charge carries them; the client reads brand, network, last4 and receipt
		// (class-wc-payments-payment-method-service.php:108-118, WC_Payments_Utils::get_terminal_card_display_brand()).
		$details = array(
			'type' => $type,
			$type  => array(
				'brand'   => $brand,
				'network' => $network,
				'last4'   => '0005',
				'receipt' => array(
					'account_type'               => 'credit',
					'dedicated_file_name'        => 'A0000000031010',
					'application_preferred_name' => 'Visa Credit',
				),
			),
		);
		$order->update_meta_data( '_wcpay_payment_method_details', wp_json_encode( $details ) );
		$order->save();

		$info = PaymentInfo::get_card_info( $order );

		$this->assertSame( $network, $info['brand'] );
		$this->assertSame( '0005', $info['last4'] );
		$this->assertSame( 'credit', $info['account_type'] );
		$this->assertSame( 'A0000000031010', $info['aid'] );
		$this->assertSame( 'Visa Credit', $info['app_name'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The icon is the base64 SVG core ships.
		$this->assertSame( base64_encode( file_get_contents( WC()->plugin_path() . "/assets/images/payment-methods/{$network}-color.svg" ) ), $info['icon'] );
	}

	/**
	 * @testdox A fetch that fails with a PHP Error still renders the order, with no card shown and no second fetch by core's fallback.
	 */
	public function test_card_info_survives_a_failing_fetch_without_a_second_one(): void {
		$client = new class() extends WooPaymentsApiClient {
			/**
			 * Payment method IDs the test double was asked for.
			 *
			 * @var string[]
			 */
			public array $requested_ids = array();

			/**
			 * Fail as a broken transport can, with a PHP Error.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 * @throws \TypeError For every payment method ID.
			 */
			public function get_payment_method( string $payment_method_id ): array {
				$this->requested_ids[] = $payment_method_id;
				if ( '' !== $payment_method_id ) {
					throw new \TypeError( 'Transport failure.' );
				}

				return array();
			}
		};
		$this->register_card_info_service( $client );
		$order = $this->create_card_info_order( 'pm_failing' );

		$info = PaymentInfo::get_card_info( $order );

		$this->assertSame( '', $info['brand'] );
		$this->assertSame( '', $info['last4'] );
		$this->assertSame( array( 'pm_failing' ), $client->requested_ids, 'Core\'s fallback must not fetch again.' );
	}

	/**
	 * @testdox A failed fetch writes the always-on payment-info line once, with logging off, as core's own fallback does (sweep row 220).
	 */
	public function test_failed_fetch_writes_one_payment_info_line_with_logging_off(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$client = new class() extends WooPaymentsApiClient {
			/**
			 * Fail as the platform does when it cannot answer.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException For every payment method ID.
			 */
			public function get_payment_method( string $payment_method_id ): array {
				if ( '' !== $payment_method_id ) {
					throw new WooPaymentsApiException( 'No such payment method: pm_gone', 'resource_missing', 404 );
				}

				return array();
			}
		};
		$this->register_card_info_service( $client );
		$logger = RecordingWcLogger::install();

		$info = PaymentInfo::get_card_info( $this->create_card_info_order( 'pm_gone' ) );

		$this->assertSame( '', $info['last4'] );
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'payment-info' === $line[2] ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( 'error', $lines[0][0] );
		$this->assertStringNotContainsString( 'No such payment method', $lines[0][1], 'The platform message stays out of the line.' );
	}

	/**
	 * @testdox A filtered order meta value that is not a string is ignored rather than breaking the render.
	 */
	public function test_card_info_ignores_non_string_filtered_meta(): void {
		$this->register_card_info_service( $this->create_recording_payment_method_client( array() ) );
		$order    = $this->create_card_info_order( 'pm_filtered' );
		$filtered = static fn() => new \stdClass();
		add_filter( 'woocommerce_order_get__payment_method_id', $filtered );
		add_filter( 'woocommerce_order_get__wcpay_payment_method_details', $filtered );

		try {
			$info = PaymentInfo::get_card_info( $order );
		} finally {
			remove_filter( 'woocommerce_order_get__payment_method_id', $filtered );
			remove_filter( 'woocommerce_order_get__wcpay_payment_method_details', $filtered );
		}

		$this->assertSame( '', $info['last4'] );
	}

	/**
	 * @testdox Card info values are sanitized as text, as the client returns them.
	 */
	public function test_card_info_values_are_sanitized(): void {
		$this->register_card_info_service( $this->create_recording_payment_method_client( array() ) );
		$order   = $this->create_card_info_order( 'pm_markup' );
		$details = array(
			'type' => 'card',
			'card' => array(
				'brand' => '<b>visa</b>',
				'last4' => '4242<script>',
			),
		);
		$order->update_meta_data( '_wcpay_payment_method_details', wp_json_encode( $details ) );
		$order->save();

		$info = PaymentInfo::get_card_info( $order );

		$this->assertSame( 'visa', $info['brand'] );
		$this->assertSame( '4242', $info['last4'] );
	}

	/**
	 * @testdox Nothing is registered while the WooPayments plugin owns the runtime.
	 */
	public function test_card_info_provider_registers_nothing_when_the_plugin_owns_the_runtime(): void {
		$card_info = new WooPaymentsOrderCardInfo();
		$card_info->init( new StaticWooPaymentsRuntimeArbiter( false ) );

		$card_info->register();

		$this->assertFalse( has_filter( 'wc_order_payment_card_info', array( $card_info, 'handle_order_payment_card_info' ) ) );
	}

	/**
	 * Build a native API client double that records the payment methods it is asked for.
	 *
	 * @param array<string,mixed> $details Details to return.
	 * @return WooPaymentsApiClient
	 */
	private function create_recording_payment_method_client( array $details ): WooPaymentsApiClient {
		return new class( $details ) extends WooPaymentsApiClient {
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
	}

	/**
	 * Register the card info provider, with a details service on the given API client.
	 *
	 * @param WooPaymentsApiClient $client API client double.
	 */
	private function register_card_info_service( WooPaymentsApiClient $client ): void {
		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyRuntimeProxy( false ) );
		$service = new WooPaymentsPaymentMethodDetailsService();
		$service->init( $legacy_runtime, $client, new StaticWooPaymentsRuntimeArbiter( true ) );
		// The card info provider and the PaymentInfo fallback both ask the container's service.
		wc_get_container()->replace( WooPaymentsPaymentMethodDetailsService::class, $service );
		$card_info = new WooPaymentsOrderCardInfo();
		$card_info->init( new StaticWooPaymentsRuntimeArbiter( true ) );
		$card_info->register();
	}

	/**
	 * Create a natively paid WooPayments order.
	 *
	 * @param string $payment_method_id Payment method ID stored on the order.
	 * @return WC_Order
	 */
	private function create_card_info_order( string $payment_method_id ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_payment_method_id', $payment_method_id );
		$order->save();

		return $order;
	}
}
