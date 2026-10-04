<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerDataEraser;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCustomerDataEraser class.
 */
class WooPaymentsCustomerDataEraserTest extends WC_Unit_Test_Case {

	/**
	 * @testdox An erasure request through the eraser WooCommerce lists on every request removes the WooPayments customer IDs and the cached payment methods.
	 *
	 * Owner decision N-316: the eraser runs on every tier where the data can exist. An available store (disconnected, or
	 * run by the WooPayments plugin) keeps the customer-ID user options and the `_wcpay_payment_methods` user meta
	 * (client 11.1.0 `class-wc-payments-token-service.php:27`). WooCommerce adds the eraser whatever the native payments
	 * tier, with no tier check left to switch, so the eraser the test boot registered is the one an available store gets.
	 */
	public function test_erasure_through_the_listed_eraser_removes_customer_ids_and_cached_payment_methods(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-available@example.com' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, 'cus_deprecated' );
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_live' );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_test', true );
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'cus_live' => array( array( 'id' => 'pm_cached' ) ) ) );

		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( WooPaymentsCustomerDataEraser::ERASER_ID, $erasers );
		$result = call_user_func( $erasers[ WooPaymentsCustomerDataEraser::ERASER_ID ]['callback'], 'erase-available@example.com', 1 );

		$this->assertFalse( get_user_option( WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertFalse( get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertSame( array(), get_user_meta( $user_id, '_wcpay_payment_methods' ) );
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);
	}

	/**
	 * @testdox The eraser removes a cached payment-method list even when no customer ID is left.
	 */
	public function test_erase_removes_cached_payment_methods_alone(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-cache@example.com' ) );
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'cus_gone' => array() ) );

		$result = WooPaymentsCustomerDataEraser::erase( 'erase-cache@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array(), get_user_meta( $user_id, '_wcpay_payment_methods' ) );
	}

	/**
	 * @testdox Erasing personal data deletes network-scoped customer IDs too.
	 */
	public function test_erase_deletes_network_scoped_customer_ids(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'erase-network@example.com' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_network', true );

		$result = WooPaymentsCustomerDataEraser::erase( 'erase-network@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', get_user_meta( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, true ), 'Erasure must remove the network-wide copy as well.' );
	}

	/**
	 * @testdox Erasing personal data for an unknown email, or a user with nothing stored, reports nothing removed.
	 *
	 * @testWith ["nobody@example.com"]
	 *           ["clean@example.com"]
	 *
	 * @param string $email Email address being erased.
	 */
	public function test_erase_with_nothing_stored_reports_nothing_removed( string $email ): void {
		$this->factory->user->create( array( 'user_email' => 'clean@example.com' ) );

		$result = WooPaymentsCustomerDataEraser::erase( $email );

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);
	}
}
