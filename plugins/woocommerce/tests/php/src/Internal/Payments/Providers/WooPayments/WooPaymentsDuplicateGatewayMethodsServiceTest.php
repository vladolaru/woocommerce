<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicateGatewayMethodsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDuplicateGatewayMethodsService class.
 */
class WooPaymentsDuplicateGatewayMethodsServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDuplicateGatewayMethodsService
	 */
	private $sut;

	/**
	 * The gateway list callback added by mock_payment_gateways(), if any.
	 *
	 * @var callable|null
	 */
	private $gateways_callback = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsDuplicateGatewayMethodsService();
		$this->sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsLogger::class ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// The gateway list is a WooCommerce singleton that the hook snapshot does not rebuild.
			if ( null !== $this->gateways_callback ) {
				remove_action( 'wc_payment_gateways_initialized', $this->gateways_callback, 100 );
				WC()->payment_gateways()->payment_gateways = array();
				WC()->payment_gateways()->init();
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Client 11.1.0 stores the dismissals through its settings option route
	 * (includes/admin/class-wc-rest-payments-settings-option-controller.php:30) and hands them back to the settings page
	 * as stored (includes/admin/class-wc-payments-admin.php:1063), the Apple Pay / Google Pay cluster included.
	 *
	 * @testdox The dismissed duplicate notices read back as stored: $_dataName.
	 * @dataProvider provide_dismissals
	 *
	 * @param array<string,string[]>|null $stored   Stored dismissals, or null for none.
	 * @param array<string,string[]>      $expected Dismissals read back.
	 */
	public function test_dismissed_notices_read_back_as_stored( ?array $stored, array $expected ): void {
		if ( null !== $stored ) {
			update_option( 'wcpay_duplicate_payment_method_notices_dismissed', $stored );
		}

		$this->assertSame( $expected, $this->sut->get_dismissed_duplicate_payment_method_notices() );
	}

	/**
	 * Stored dismissals and what reads back.
	 *
	 * @return array<string,array{0:array<string,string[]>|null,1:array<string,string[]>}>
	 */
	public function provide_dismissals(): array {
		$dismissals = array(
			'card'                 => array( 'stripe' ),
			'apple_pay_google_pay' => array( 'woocommerce_payments', 'stripe' ),
		);

		return array(
			'none stored'                                 => array( null, array() ),
			'card and the Apple Pay / Google Pay cluster' => array( $dismissals, $dismissals ),
		);
	}

	/**
	 * @testdox Should cluster enabled gateways that offer a WooPayments payment method with WooPayments: $_dataName.
	 * @dataProvider provide_duplicated_payment_method_ids
	 *
	 * @param bool                                                        $wallets_on    Whether WooPayments has Apple Pay or Google Pay on.
	 * @param array<int,array{0:string,1:string,2?:array<string,string>}> $gateway_specs Gateway ID, enabled flag and options.
	 * @param array<string,string[]>                                      $expected      Clusters by payment method.
	 */
	public function test_duplicated_payment_method_ids( bool $wallets_on, array $gateway_specs, array $expected ): void {
		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( $wallets_on );
		$this->sut->init( $account_service, wc_get_container()->get( WooPaymentsLogger::class ) );
		$this->mock_payment_gateways(
			array_map(
				fn( array $spec ): object => $this->create_gateway( ...$spec ),
				$gateway_specs
			)
		);

		$duplicates = $this->sut->get_duplicated_payment_method_ids();

		$this->assertEqualsCanonicalizing( array_keys( $expected ), array_keys( $duplicates ), 'The clusters reported.' );
		foreach ( $expected as $payment_method_id => $gateway_ids ) {
			$this->assertEqualsCanonicalizing( $gateway_ids, $duplicates[ $payment_method_id ], "The $payment_method_id cluster." );
		}
	}

	/**
	 * Gateway lists and the clusters they give: wallet state, gateways, expected clusters.
	 *
	 * @return array<string,array{0:bool,1:array<int,array{0:string,1:string,2?:array<string,string>}>,2:array<string,string[]>}>
	 */
	public function provide_duplicated_payment_method_ids(): array {
		return array(
			'wallets on: keyword, Stripe payment request and express option gateways' => array(
				true,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'legacy_applepay_gateway', 'yes' ),
					array( 'stripe', 'yes', array( 'payment_request' => 'yes' ) ),
					// A third-party gateway offering wallets through the express_checkout_enabled option the client checks
					// (client 11.1.0 `includes/class-duplicates-detection-service.php:141`).
					array( 'acme_wallets', 'yes', array( 'express_checkout_enabled' => 'yes' ) ),
					array( 'acme_cards', 'yes', array( 'express_checkout_enabled' => 'no' ) ),
					array( 'disabled_googlepay_gateway', 'no' ),
				),
				array(
					'apple_pay_google_pay' => array( 'woocommerce_payments', 'legacy_applepay_gateway', 'stripe', 'acme_wallets' ),
					'card'                 => array( 'woocommerce_payments', 'stripe', 'acme_cards' ),
				),
			),
			'wallets off: a wallet keyword gateway alone' => array(
				false,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'legacy_googlepay_gateway', 'yes' ),
				),
				array(),
			),
			'wallets on: Stripe payment request and the express option off' => array(
				true,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'stripe', 'yes', array( 'payment_request' => 'no' ) ),
					array( 'wallet_without_express_option', 'yes' ),
					array( 'wallet_with_disabled_express_option', 'yes', array( 'express_checkout_enabled' => 'no' ) ),
				),
				array(
					'card' => array( 'woocommerce_payments', 'stripe' ),
				),
			),
			'split WooPayments gateway classified by its suffix' => array(
				false,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'woocommerce_payments_klarna', 'yes' ),
					array( 'legacy_klarna_gateway', 'yes' ),
				),
				array(
					'klarna' => array( 'woocommerce_payments_klarna', 'legacy_klarna_gateway' ),
				),
			),
			'no WooPayments gateway'                      => array(
				false,
				array(
					array( 'legacy_card_gateway', 'yes' ),
					array( 'another_card_gateway', 'yes' ),
				),
				array(),
			),
			// WooCommerce Stripe Gateway 11 stores its Apple Pay / Google Pay switch as `express_checkout`, not `payment_request`.
			'wallets on: Stripe express checkout option'  => array(
				true,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'stripe', 'yes', array( 'express_checkout' => 'yes' ) ),
				),
				array(
					'apple_pay_google_pay' => array( 'woocommerce_payments', 'stripe' ),
					'card'                 => array( 'woocommerce_payments', 'stripe' ),
				),
			),
			'wallets off: Stripe express checkout option' => array(
				false,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'stripe', 'yes', array( 'express_checkout' => 'yes' ) ),
				),
				array(
					'card' => array( 'woocommerce_payments', 'stripe' ),
				),
			),
			// WooPayments registers no separate Link gateway, so stripe_link has no WooPayments partner to cluster with.
			'Stripe sub-gateways by payment method, card only on the exact IDs' => array(
				false,
				array(
					array( 'woocommerce_payments', 'yes' ),
					array( 'woocommerce_payments_alipay', 'yes' ),
					array( 'woocommerce_payments_amazon_pay', 'yes' ),
					array( 'stripe', 'yes' ),
					array( 'stripe_alipay', 'yes' ),
					array( 'stripe_amazon_pay', 'yes' ),
					array( 'stripe_link', 'yes' ),
					array( 'stripe_boleto', 'yes' ),
				),
				array(
					'card'       => array( 'woocommerce_payments', 'stripe' ),
					'alipay'     => array( 'woocommerce_payments_alipay', 'stripe_alipay' ),
					'amazon_pay' => array( 'woocommerce_payments_amazon_pay', 'stripe_amazon_pay' ),
				),
			),
		);
	}

	/**
	 * @testdox Should fail closed when duplicate payment method gateway inspection fails.
	 */
	public function test_duplicated_payment_method_ids_fail_closed_when_gateway_inspection_fails(): void {
		$this->mock_payment_gateways(
			array(
				new class() {
					/**
					 * Tell whether a property exists.
					 *
					 * @param string $name Property name.
					 * @return bool
					 *
					 * @throws \RuntimeException When gateway inspection is not available.
					 */
					public function __isset( string $name ): bool {
						if ( 'enabled' === $name ) {
							throw new \RuntimeException( 'Gateway inspection unavailable' );
						}

						return false;
					}
				},
			)
		);

		$this->assertSame( array(), $this->sut->get_duplicated_payment_method_ids() );
	}

	/**
	 * Mock WooCommerce payment gateways.
	 *
	 * @param array<int,object> $gateways Gateways.
	 */
	private function mock_payment_gateways( array $gateways ): void {
		$this->gateways_callback = static function ( \WC_Payment_Gateways $wc_payment_gateways ) use ( $gateways ): void {
			$wc_payment_gateways->payment_gateways = array();
			$order                                 = 1000;
			foreach ( $gateways as $gateway ) {
				$wc_payment_gateways->payment_gateways[ $order++ ] = $gateway;
			}
		};
		add_action( 'wc_payment_gateways_initialized', $this->gateways_callback, 100 );

		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Create a lightweight gateway fixture.
	 *
	 * @param string               $id      Gateway ID.
	 * @param string               $enabled Gateway enabled state.
	 * @param array<string,string> $options Gateway options.
	 * @return object
	 */
	private function create_gateway( string $id, string $enabled, array $options = array() ): object {
		return new class( $id, $enabled, $options ) {
			/**
			 * Gateway ID.
			 *
			 * @var string
			 */
			public string $id;

			/**
			 * Gateway enabled state.
			 *
			 * @var string
			 */
			public string $enabled;

			/**
			 * Gateway options.
			 *
			 * @var array<string,string>
			 */
			private array $options;

			/**
			 * Initialize gateway fixture.
			 *
			 * @param string               $id      Gateway ID.
			 * @param string               $enabled Gateway enabled state.
			 * @param array<string,string> $options Gateway options.
			 */
			public function __construct(
				string $id,
				string $enabled,
				array $options
			) {
				$this->id      = $id;
				$this->enabled = $enabled;
				$this->options = $options;
			}

			/**
			 * Get a gateway option.
			 *
			 * @param string $key Option key.
			 * @return string
			 */
			public function get_option( string $key ): string {
				return $this->options[ $key ] ?? 'no';
			}
		};
	}
}
