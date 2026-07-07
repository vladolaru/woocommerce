<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Payment_Tokens;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTokenClassMapController class.
 */
class WooPaymentsTokenClassMapControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_token_class' );
		parent::tearDown();
	}

	/**
	 * @testdox Should map preserved WooPayments token rows to native token classes.
	 */
	public function test_maps_preserved_woopayments_token_rows_to_native_classes(): void {
		$user_id  = $this->factory()->user->create();
		$token_id = $this->create_raw_payment_token(
			$user_id,
			'woocommerce_payments_sepa_debit',
			'pm_sepa_saved',
			'wcpay_sepa',
			array(
				'last4' => '6789',
			)
		);
		$sut      = new WooPaymentsTokenClassMapController();

		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register();

		$token = WC_Payment_Tokens::get( $token_id );

		$this->assertInstanceOf( WooPaymentsSepaToken::class, $token );
		$this->assertSame( '6789', $token->get_last4() );
		$this->assertSame( 'pm_sepa_saved', $token->get_token() );
		$this->assertSame( 'woocommerce_payments_sepa_debit', $token->get_gateway_id() );
	}

	/**
	 * @testdox Should expose preserved SEPA tokens in customer token queries for the SEPA gateway.
	 */
	public function test_exposes_preserved_sepa_tokens_in_customer_gateway_queries(): void {
		$user_id  = $this->factory()->user->create();
		$token_id = $this->create_raw_payment_token(
			$user_id,
			'woocommerce_payments_sepa_debit',
			'pm_sepa_saved',
			'wcpay_sepa',
			array(
				'last4' => '6789',
			)
		);
		$sut      = new WooPaymentsTokenClassMapController();

		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register();

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'woocommerce_payments_sepa_debit' );

		$this->assertArrayHasKey( $token_id, $tokens );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $tokens[ $token_id ] );
		$this->assertSame( '6789', $tokens[ $token_id ]->get_last4() );
	}

	/**
	 * @testdox Should map legacy WooPayments token class names to native token classes.
	 */
	public function test_maps_legacy_woopayments_token_class_names_to_native_classes(): void {
		$sut = new WooPaymentsTokenClassMapController();

		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the token class map filter directly.
		$this->assertSame( WooPaymentsSepaToken::class, apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_WCPay_SEPA', 'legacy-sepa' ) );
	}

	/**
	 * @testdox Should not register token class mapping when native does not own runtime.
	 */
	public function test_does_not_register_mapping_when_native_does_not_own_runtime(): void {
		$sut = new WooPaymentsTokenClassMapController();

		$sut->init( new StaticNativeRuntimeArbiter( false ) );
		$sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test asserts the controller does not register this class-map filter.
		$this->assertSame( 'WC_Payment_Token_wcpay_sepa', apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_wcpay_sepa', 'wcpay_sepa' ) );
	}

	/**
	 * Create a raw WooCommerce payment token row.
	 *
	 * @param int                  $user_id    User ID.
	 * @param string               $gateway_id Gateway ID.
	 * @param string               $token      Provider payment method ID.
	 * @param string               $type       Payment token type.
	 * @param array<string,string> $meta       Token metadata.
	 * @return int Token ID.
	 */
	private function create_raw_payment_token( int $user_id, string $gateway_id, string $token, string $type, array $meta ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_payment_tokens',
			array(
				'gateway_id' => $gateway_id,
				'token'      => $token,
				'user_id'    => $user_id,
				'type'       => $type,
				'is_default' => 0,
			),
			array( '%s', '%s', '%d', '%s', '%d' )
		);

		$token_id = (int) $wpdb->insert_id;

		foreach ( $meta as $meta_key => $meta_value ) {
			$wpdb->insert(
				$wpdb->prefix . 'woocommerce_payment_tokenmeta',
				array(
					'payment_token_id' => $token_id,
					'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test inserts payment token metadata directly.
					'meta_value'       => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test inserts payment token metadata directly.
				),
				array( '%d', '%s', '%s' )
			);
		}

		return $token_id;
	}
}
