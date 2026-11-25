<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use Automattic\WooCommerce\Internal\Orders\PaymentInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
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
	 * @testWith ["woocommerce_payments", ""]
	 *           ["bacs", "pm_other"]
	 * @param string $gateway Payment gateway on the order.
	 * @param string $method_id Payment method ID on the order.
	 */
	public function test_render_without_a_woopayments_method_skips_the_fresh_order_read( string $gateway, string $method_id ): void {
		$order = $this->create_card_info_order( $method_id );
		$order->set_payment_method( $gateway );
		$order->save();
		$client = $this->create_recording_payment_method_client( array() );
		$this->register_card_info_service( $client );
		$lifecycle = $this->createMock( OrderPaymentLifecycleService::class );
		$lifecycle->expects( $this->never() )->method( 'get_fresh_order_from_data_store' );
		$container = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( OrderPaymentLifecycleService::class, $lifecycle );

		$this->assertSame( '', PaymentInfo::get_card_info( $order )['last4'] );
		$this->assertSame( array(), $client->requested_ids );
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
	 * @testdox Stale order objects rendered for the same payment reuse the persisted card and leave one details row.
	 *
	 * @testWith ["missing"]
	 *           ["placeholder"]
	 *           ["charge"]
	 * @param string $stored What another writer saved after the email objects were read.
	 */
	public function test_stale_renders_share_one_persisted_card( string $stored ): void {
		$fetched = array(
			'type' => 'card',
			'card' => array(
				'brand' => 'visa',
				'last4' => '4242',
			),
		);
		$charge  = array(
			'type' => 'card',
			'card' => array(
				'brand' => 'mastercard',
				'last4' => '4444',
			),
		);
		$client  = $this->create_recording_payment_method_client( $fetched );
		$this->register_card_info_service( $client );
		$first = $this->create_card_info_order( 'pm_renewal' );
		$first->get_meta_data();
		$second = clone $first;
		if ( 'missing' !== $stored ) {
			$writer = new WC_Order( $first->get_id() );
			$writer->update_meta_data( '_wcpay_payment_method_details', 'charge' === $stored ? (string) wp_json_encode( $charge ) : '[]' );
			$writer->save_meta_data();
		}
		$expected = 'charge' === $stored ? $charge : $fetched;
		$orders   = 'charge' === $stored ? array( $first ) : array( $first, $second );
		$last4    = array_map( static fn( WC_Order $order ): string => PaymentInfo::get_card_info( $order )['last4'], $orders );

		global $wpdb;
		$count = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = '_wcpay_payment_method_details'", $first->get_id() ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wcpay_payment_method_details'", $first->get_id() ) );
		$this->assertSame( array( 'charge' === $stored ? 0 : 1, 1 ), array( count( $client->requested_ids ), (int) $count ), 'Platform fetches and stored details rows.' );
		$this->assertSame( array_fill( 0, count( $orders ), $expected['card']['last4'] ), $last4 );
		$this->assertEquals( $expected, json_decode( ( new WC_Order( $first->get_id() ) )->get_meta( '_wcpay_payment_method_details', true ), true ) );
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
	 * @testdox A $type terminal card shows its network when the WooPayments extension does ($network over $brand), with the network's icon.
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
		$client = new class() extends Client {
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
	 * @testdox A failed fetch writes the always-on payment-info line once, with logging off, as core's own fallback does.
	 */
	public function test_failed_fetch_writes_one_payment_info_line_with_logging_off(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$client = new class() extends Client {
			/**
			 * Fail as the platform does when it cannot answer.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 * @throws ApiException For every payment method ID.
			 */
			public function get_payment_method( string $payment_method_id ): array {
				if ( '' !== $payment_method_id ) {
					throw new ApiException( 'No such payment method: pm_gone', 'resource_missing', 404 );
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
	 * @testdox A failed fetch is remembered for the order: rendering it again (the second Failed order email) makes no fetch and no log line.
	 */
	public function test_a_failed_fetch_is_not_repeated_for_the_same_order_and_payment_method(): void {
		$client = $this->create_payment_method_client_failing_for( array( 'pm_platform' ) );
		$this->register_card_info_service( $client );
		$logger   = RecordingWcLogger::install();
		$order_id = $this->create_card_info_order( 'pm_platform' )->get_id();

		$first  = PaymentInfo::get_card_info( wc_get_order( $order_id ) );
		$second = PaymentInfo::get_card_info( wc_get_order( $order_id ) );

		$this->assertSame( array( 'pm_platform' ), $client->requested_ids, 'One fetch for both renders, by neither this class nor core\'s fallback.' );
		$this->assertCount( 1, array_filter( $logger->lines, static fn( array $line ): bool => 'payment-info' === $line[2] ) );
		$this->assertSame( '', $first['last4'] );
		$this->assertSame( '', $second['brand'] );
		$this->assertSame( '', $second['last4'] );
		$this->assertSame( '', wc_get_order( $order_id )->get_meta( '_wcpay_payment_method_details', true ), 'Nothing is stored on the order.' );
	}

	/**
	 * @testdox A failed fetch for one order does not hide the card on another order with the same payment method, as when a long-running process renders both.
	 */
	public function test_a_failed_fetch_does_not_stop_a_fetch_for_another_order_with_the_same_payment_method(): void {
		$client = $this->create_payment_method_client_failing_for( array( 'pm_shared' ), 1 );
		$this->register_card_info_service( $client );

		$first  = PaymentInfo::get_card_info( $this->create_card_info_order( 'pm_shared' ) );
		$second = PaymentInfo::get_card_info( $this->create_card_info_order( 'pm_shared' ) );

		$this->assertSame( array( 'pm_shared', 'pm_shared' ), $client->requested_ids );
		$this->assertSame( '', $first['last4'] );
		$this->assertSame( 'visa', $second['brand'] );
		$this->assertSame( '4242', $second['last4'] );
	}

	/**
	 * @testdox A failed fetch for an order's payment method does not stop the fetch for another payment method on the same order.
	 */
	public function test_a_failed_fetch_does_not_stop_a_fetch_for_another_payment_method_on_the_order(): void {
		$client = $this->create_payment_method_client_failing_for( array( 'pm_platform' ) );
		$this->register_card_info_service( $client );
		$order = $this->create_card_info_order( 'pm_platform' );
		PaymentInfo::get_card_info( $order );
		$order->update_meta_data( '_payment_method_id', 'pm_merchant' );
		$order->save();

		$info = PaymentInfo::get_card_info( wc_get_order( $order->get_id() ) );

		$this->assertSame( array( 'pm_platform', 'pm_merchant' ), $client->requested_ids );
		$this->assertSame( 'visa', $info['brand'] );
		$this->assertSame( '4242', $info['last4'] );
	}

	/**
	 * @testdox A failed fetch for an order with no ID is not remembered: rendering it again fetches again.
	 */
	public function test_a_failed_fetch_for_an_order_with_no_id_is_not_remembered(): void {
		$client = $this->create_payment_method_client_failing_for( array( 'pm_unsaved' ) );
		$this->register_card_info_service( $client );
		$order = new WC_Order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_payment_method_id', 'pm_unsaved' );

		PaymentInfo::get_card_info( $order );
		$info = PaymentInfo::get_card_info( $order );

		$this->assertSame( 0, $order->get_id() );
		$this->assertSame( array( 'pm_unsaved', 'pm_unsaved' ), $client->requested_ids );
		$this->assertSame( '', $info['last4'] );
	}

	/**
	 * @testdox Stored details are read before the failed fetch is remembered, so the order still shows its card once they are stored.
	 */
	public function test_stored_details_are_read_for_an_order_whose_fetch_failed(): void {
		$client = $this->create_payment_method_client_failing_for( array( 'pm_platform' ) );
		$this->register_card_info_service( $client );
		$order = $this->create_card_info_order( 'pm_platform' );
		PaymentInfo::get_card_info( $order );
		$order->update_meta_data(
			'_wcpay_payment_method_details',
			wp_json_encode(
				array(
					'type' => 'card',
					'card' => array(
						'brand' => 'mastercard',
						'last4' => '4444',
					),
				)
			)
		);
		$order->save();

		$info = PaymentInfo::get_card_info( wc_get_order( $order->get_id() ) );

		$this->assertSame( 'mastercard', $info['brand'] );
		$this->assertSame( '4444', $info['last4'] );
		$this->assertSame( array( 'pm_platform' ), $client->requested_ids );
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
	 * @testdox Card info values are sanitized as text, as the WooPayments extension returns them.
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
	 * Build a native API client double that records the payment methods it is asked for.
	 *
	 * @param array<string,mixed> $details Details to return.
	 * @return Client&object{requested_ids:string[]}
	 */
	private function create_recording_payment_method_client( array $details ): Client {
		return new class( $details ) extends Client {
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
	 * Build an API client double that records the payment methods it is asked for, fails for the listed ones as the
	 * platform does for a payment method another account owns, and returns a Visa card for the others.
	 *
	 * @param string[] $failing_ids   Payment method IDs the platform cannot read.
	 * @param int      $failures_left How many fetches of the listed IDs fail before the platform answers them.
	 * @return Client
	 */
	private function create_payment_method_client_failing_for( array $failing_ids, int $failures_left = PHP_INT_MAX ): Client {
		return new class( $failing_ids, $failures_left ) extends Client {
			/**
			 * Payment method IDs the platform cannot read.
			 *
			 * @var string[]
			 */
			private array $failing_ids;

			/**
			 * How many more fetches of the listed IDs fail.
			 *
			 * @var int
			 */
			private int $failures_left;

			/**
			 * Payment method IDs the test double was asked for.
			 *
			 * @var string[]
			 */
			public array $requested_ids = array();

			/**
			 * Constructor.
			 *
			 * @param string[] $failing_ids   Payment method IDs the platform cannot read.
			 * @param int      $failures_left How many fetches of the listed IDs fail.
			 */
			public function __construct( array $failing_ids, int $failures_left ) {
				$this->failing_ids   = $failing_ids;
				$this->failures_left = $failures_left;
			}

			/**
			 * Get a payment method.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 * @throws ApiException For a listed payment method ID.
			 */
			public function get_payment_method( string $payment_method_id ): array {
				$this->requested_ids[] = $payment_method_id;
				if ( in_array( $payment_method_id, $this->failing_ids, true ) && $this->failures_left > 0 ) {
					--$this->failures_left;
					throw new ApiException( 'No such PaymentMethod', 'resource_missing', 400 );
				}

				return array(
					'type' => 'card',
					'card' => array(
						'brand' => 'visa',
						'last4' => '4242',
					),
				);
			}
		};
	}

	/**
	 * Register the card info provider, with a details service on the given API client.
	 *
	 * @param Client $client API client double.
	 */
	private function register_card_info_service( Client $client ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => 'WC_Payments' !== ltrim( (string) $class_name, '\\' ) && class_exists( $class_name ),
			)
		);
		$extension_runtime = new WooPaymentsExtensionRuntime();
		$extension_runtime->init( wc_get_container()->get( LegacyProxy::class ) );
		$service = new WooPaymentsPaymentMethodDetailsService();
		$service->init( $extension_runtime, $client, new StaticWooPaymentsRuntimeArbiter( true ) );
		// The PaymentInfo fallback asks the container's service.
		wc_get_container()->replace( WooPaymentsPaymentMethodDetailsService::class, $service );
		$card_info = new WooPaymentsOrderCardInfo();
		$card_info->init( $service );
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
