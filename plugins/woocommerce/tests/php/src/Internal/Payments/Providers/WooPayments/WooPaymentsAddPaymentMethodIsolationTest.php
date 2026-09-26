<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use WC_Form_Handler;
use WC_Payment_Gateway;
use WC_Rate_Limiter;
use WC_Unit_Test_Case;

/**
 * Tests that the native WooPayments add-payment-method error path stays scoped to the WooPayments gateway.
 *
 * WooCommerce dispatches the My Account add-payment-method form to the selected gateway only, so a
 * WooPayments-specific error must never surface when the shopper picked a different gateway. Nothing in the
 * native runtime currently hooks the shared validation filter, and this locks that in.
 */
class WooPaymentsAddPaymentMethodIsolationTest extends WC_Unit_Test_Case {

	/**
	 * The WooPayments-specific message the native gateway emits when no setup intent was submitted.
	 */
	private const WOOPAYMENTS_ERROR = 'A WooPayments payment method was not provided.';

	/**
	 * ID of the stand-in non-WooPayments gateway registered for these tests.
	 */
	private const OTHER_GATEWAY_ID = 'isolation_test_other_gateway';

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		wc_clear_notices();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		remove_all_filters( 'woocommerce_available_payment_gateways' );
		wc_clear_notices();
		unset( $_POST['woocommerce_add_payment_method'], $_POST['payment_method'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * @testdox Should not surface a WooPayments add-payment-method error when another gateway was selected.
	 */
	public function test_other_gateway_selection_does_not_surface_a_woopayments_error(): void {
		$other_gateway = $this->register_other_gateway();

		$this->submit_add_payment_method_form( self::OTHER_GATEWAY_ID );

		$this->assertTrue(
			$other_gateway->was_called,
			'WooCommerce must dispatch the add-payment-method form to the selected non-WooPayments gateway.'
		);

		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$this->assertStringNotContainsString(
				'WooPayments',
				(string) ( $notice['notice'] ?? '' ),
				'Selecting another gateway must not surface a WooPayments-specific add-payment-method error.'
			);
		}
	}

	/**
	 * @testdox Should surface the WooPayments error only from the WooPayments gateway itself, proving the isolation assertion is not vacuous.
	 */
	public function test_woopayments_gateway_still_reports_its_own_missing_setup_intent(): void {
		$gateway = new NativeWooPaymentsGateway();

		$result = $gateway->add_payment_method();

		$this->assertSame( 'error', $result['result'], 'A missing setup intent must fail the WooPayments add-payment-method attempt.' );

		$messages = array_map(
			static fn( $notice ) => (string) ( $notice['notice'] ?? '' ),
			wc_get_notices( 'error' )
		);

		$this->assertContains(
			self::WOOPAYMENTS_ERROR,
			$messages,
			'The WooPayments-specific error must be reachable through the WooPayments gateway, otherwise the isolation test proves nothing.'
		);
	}

	/**
	 * Register a stand-in non-WooPayments gateway that supports adding payment methods.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function register_other_gateway(): WC_Payment_Gateway {
		$gateway = new class() extends WC_Payment_Gateway {
			/**
			 * Whether add_payment_method() was invoked.
			 *
			 * @var bool
			 */
			public bool $was_called = false;

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id       = WooPaymentsAddPaymentMethodIsolationTest::other_gateway_id();
				$this->supports = array( 'products', 'add_payment_method', 'tokenization' );
				$this->enabled  = 'yes';
			}

			/**
			 * Record the call and succeed without a redirect, so the form handler does not exit.
			 *
			 * @return array<string,string>
			 */
			public function add_payment_method() {
				$this->was_called = true;

				return array( 'result' => 'success' );
			}
		};

		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( $gateways ) use ( $gateway ) {
				$gateways[ $gateway->id ] = $gateway;

				return $gateways;
			}
		);

		return $gateway;
	}

	/**
	 * Expose the stand-in gateway ID to the anonymous gateway class.
	 *
	 * @return string
	 */
	public static function other_gateway_id(): string {
		return self::OTHER_GATEWAY_ID;
	}

	/**
	 * Submit the My Account add-payment-method form for a given gateway.
	 *
	 * @param string $gateway_id Selected gateway ID.
	 */
	private function submit_add_payment_method_form( string $gateway_id ): void {
		// The shared rate limiter is keyed per user and would short-circuit a second submission.
		WC_Rate_Limiter::set_rate_limit( 'add_payment_method_' . get_current_user_id(), -1 );

		$nonce = wp_create_nonce( 'woocommerce-add-payment-method' );

		$_POST['woocommerce_add_payment_method'] = '1';
		$_POST['payment_method']                 = $gateway_id;
		$_POST['_wpnonce']                       = $nonce;
		$_REQUEST['_wpnonce']                    = $nonce;

		$buffer_level = ob_get_level();

		WC_Form_Handler::add_payment_method_action();

		// add_payment_method_action() opens an output buffer and only closes it on the redirect path, so a
		// gateway that succeeds without a redirect leaves it open. Close what the call left behind.
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
	}
}
