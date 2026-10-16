<?php
/**
 * WooPaymentsUserPreferenceFields tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\WCAdminUser;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsUserPreferenceFields;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the hidden list column preferences the native admin lists keep in user meta.
 */
class WooPaymentsUserPreferenceFieldsTest extends WC_REST_Unit_Test_Case {

	/**
	 * The hidden column keys (the WooPayments extension's WC_Payments::add_user_data_fields()) for the WooPayments lists.
	 */
	private const HIDDEN_COLUMN_KEYS = array(
		'wc_payments_transactions_hidden_columns',
		'wc_payments_transactions_blocked_hidden_columns',
		'wc_payments_transactions_uncaptured_hidden_columns',
		'wc_payments_payouts_hidden_columns',
		'wc_payments_disputes_hidden_columns',
		'wc_payments_documents_hidden_columns',
	);

	/**
	 * The system under test.
	 *
	 * @var WooPaymentsUserPreferenceFields
	 */
	private WooPaymentsUserPreferenceFields $sut;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsUserPreferenceFields();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_admin_get_user_data_fields', array( $this->sut, 'handle_woocommerce_admin_get_user_data_fields' ) );
		parent::tearDown();
	}

	/**
	 * @testdox Registers the hidden column keys as WooCommerce user data fields.
	 */
	public function test_registers_the_hidden_column_keys(): void {
		$this->assertEmpty( array_intersect( self::HIDDEN_COLUMN_KEYS, WCAdminUser::get_instance()->get_user_data_fields() ), 'Nothing is registered before register().' );

		$this->sut->register();
		$fields = WCAdminUser::get_instance()->get_user_data_fields();

		foreach ( self::HIDDEN_COLUMN_KEYS as $key ) {
			$this->assertContains( $key, $fields );
		}
		$this->assertContains( 'variable_product_tour_shown', $fields, 'Core fields must be kept.' );
	}

	/**
	 * @testdox Stores a hidden columns write through the users REST route in its user meta.
	 */
	public function test_users_route_writes_the_hidden_columns_user_meta(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->sut->register();

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_body_params( array( 'woocommerce_meta' => array( 'wc_payments_payouts_hidden_columns' => '["status"]' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '["status"]', get_user_meta( $user_id, 'woocommerce_admin_wc_payments_payouts_hidden_columns', true ) );
		$this->assertSame( '["status"]', $response->get_data()['woocommerce_meta']['wc_payments_payouts_hidden_columns'] );
	}
}
