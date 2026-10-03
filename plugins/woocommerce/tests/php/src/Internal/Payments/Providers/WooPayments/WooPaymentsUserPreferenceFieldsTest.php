<?php
/**
 * WooPaymentsUserPreferenceFields tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\WCAdminUser;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsUserPreferenceFields;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the hidden list column preferences the native admin lists keep in user meta.
 */
class WooPaymentsUserPreferenceFieldsTest extends WC_REST_Unit_Test_Case {

	/**
	 * The client's keys (WooPayments 11.1.0 WC_Payments::add_user_data_fields()) for the native lists.
	 */
	private const CLIENT_KEYS = array(
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
	 * @testdox Registers the client's hidden column keys as WooCommerce user data fields.
	 */
	public function test_registers_client_hidden_column_keys(): void {
		$this->assertEmpty( array_intersect( self::CLIENT_KEYS, WCAdminUser::get_instance()->get_user_data_fields() ), 'Nothing is registered before register().' );

		$this->sut->register();
		$fields = WCAdminUser::get_instance()->get_user_data_fields();

		foreach ( self::CLIENT_KEYS as $key ) {
			$this->assertContains( $key, $fields );
		}
		$this->assertContains( 'variable_product_tour_shown', $fields, 'Core fields must be kept.' );
	}

	/**
	 * @testdox Stores a hidden columns write through the users REST route in the client's user meta.
	 */
	public function test_users_route_writes_the_client_user_meta(): void {
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

	/**
	 * @testdox Loads only on connected and active admin and REST requests, never on dormant tiers.
	 */
	public function test_bootstrap_matrix_bounds_registration_to_connected_admin_and_rest(): void {
		foreach ( WooPaymentsProvider::get_bootstrap_root_matrix() as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				$expected = in_array( $state, array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true )
					&& in_array( $request_type, array( 'admin', 'rest' ), true );

				$this->assertSame( $expected, in_array( WooPaymentsUserPreferenceFields::class, $roots, true ), $state . ' ' . $request_type );
			}
		}
		$this->assertArrayHasKey( 'rest', WooPaymentsProvider::get_bootstrap_root_matrix()[ NativePaymentsState::CONNECTED ] );
	}
}
