<?php
/**
 * UserPreferenceFields tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\WCAdminUser;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\UserPreferenceFields;
use WC_Unit_Test_Case;

/**
 * Tests for the hidden list column preferences the native admin lists keep in user meta.
 */
class UserPreferenceFieldsTest extends WC_Unit_Test_Case {

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
	 * @var UserPreferenceFields
	 */
	private UserPreferenceFields $sut;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new UserPreferenceFields();
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
}
