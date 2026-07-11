<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsGatewaySettingsSynchronizer class.
 */
class WooPaymentsGatewaySettingsSynchronizerTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION                = 'woocommerce_woocommerce_payments_settings';
	private const PAYMENT_REQUEST_PENDING_OPTION = 'woocommerce_woocommerce_payments_payment_request_projection_pending';

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( array( 'ideal', 'bancontact', 'apple_pay', 'google_pay', 'giropay', 'sofort' ) as $method_id ) {
			delete_option( 'woocommerce_woocommerce_payments_' . $method_id . '_settings' );
		}
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::PAYMENT_REQUEST_PENDING_OPTION );
		parent::tearDown();
	}

	/**
	 * @testdox Canonical method IDs project into split gateway settings and remove deprecated methods.
	 */
	public function test_persist_projects_canonical_settings_and_removes_deprecated_methods(): void {
		update_option(
			'woocommerce_woocommerce_payments_ideal_settings',
			array(
				'enabled' => 'no',
				'custom'  => 'preserved',
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_bancontact_settings',
			array( 'enabled' => 'yes' )
		);

		$synchronizer = new WooPaymentsGatewaySettingsSynchronizer();
		$result       = $synchronizer->persist(
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal', 'giropay', 'sofort', 'ideal' ),
			)
		);

		$this->assertSame( array( 'card', 'ideal' ), $result['settings']['upe_enabled_payment_method_ids'] );
		$this->assertSame( $result['settings'], get_option( self::SETTINGS_OPTION ) );
		$this->assertSame(
			array(
				'enabled'                        => 'yes',
				'custom'                         => 'preserved',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			),
			get_option( 'woocommerce_woocommerce_payments_ideal_settings' )
		);
		$this->assertSame(
			array(
				'enabled'                        => 'no',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
			),
			get_option( 'woocommerce_woocommerce_payments_bancontact_settings' )
		);
		$this->assertContains( 'woocommerce_woocommerce_payments_ideal_settings', $result['updated_split_options'] );
		$this->assertContains( 'woocommerce_woocommerce_payments_bancontact_settings', $result['updated_split_options'] );
	}

	/**
	 * @testdox Reapplying an already projected settings state is intrinsically idempotent.
	 */
	public function test_persist_is_intrinsically_idempotent(): void {
		$synchronizer = new WooPaymentsGatewaySettingsSynchronizer();
		$settings     = array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) );

		$first  = $synchronizer->persist( $settings );
		$second = $synchronizer->persist( $first['settings'] );

		$this->assertNotEmpty( $first['updated_split_options'] );
		$this->assertSame( array(), $second['updated_split_options'] );
		$this->assertSame( $first['settings'], $second['settings'] );
	}

	/**
	 * @testdox A rejected canonical write fails before split gateway projection starts.
	 */
	public function test_persist_reports_canonical_write_failure_before_projecting_split_settings(): void {
		$original_settings = array(
			'enabled'                        => 'no',
			'upe_enabled_payment_method_ids' => array( 'card' ),
		);
		update_option( self::SETTINGS_OPTION, $original_settings );
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . self::SETTINGS_OPTION, $reject_update, 10, 2 );

		try {
			$result = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
				array(
					'enabled'                        => 'yes',
					'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
				)
			);
		} finally {
			remove_filter( 'pre_update_option_' . self::SETTINGS_OPTION, $reject_update, 10 );
		}

		$this->assertArrayHasKey( 'persisted', $result );
		$this->assertFalse( $result['persisted'] );
		$this->assertSame( array( self::SETTINGS_OPTION ), $result['failed_option_names'] );
		$this->assertSame( $original_settings, get_option( self::SETTINGS_OPTION ) );
		$this->assertFalse( get_option( 'woocommerce_woocommerce_payments_ideal_settings', false ) );
	}

	/**
	 * @testdox A rejected split gateway write is returned as a verified projection failure.
	 */
	public function test_persist_reports_split_gateway_write_failure(): void {
		$settings       = array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) );
		$option_name    = 'woocommerce_woocommerce_payments_ideal_settings';
		$split_settings = array(
			'enabled' => 'no',
			'custom'  => 'preserved',
		);
		update_option( self::SETTINGS_OPTION, $settings );
		update_option( $option_name, $split_settings );
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $option_name, $reject_update, 10, 2 );

		try {
			$result = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist( $settings );
		} finally {
			remove_filter( 'pre_update_option_' . $option_name, $reject_update, 10 );
		}

		$this->assertArrayHasKey( 'persisted', $result );
		$this->assertFalse( $result['persisted'] );
		$this->assertSame( array( $option_name ), $result['failed_option_names'] );
		$this->assertNotContains( $option_name, $result['updated_split_options'] );
		$this->assertSame( $split_settings, get_option( $option_name ) );
	}

	/**
	 * @testdox Payment-request settings control Apple Pay and Google Pay split gateways independently of UPE methods.
	 */
	public function test_persist_projects_payment_request_to_wallet_split_gateways(): void {
		$synchronizer = new WooPaymentsGatewaySettingsSynchronizer();
		$enabled      = $synchronizer->persist(
			array(
				'payment_request'                => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->assertArrayNotHasKey( 'payment_request', $enabled['settings'] );
		$this->assertSame( array( 'card' ), $enabled['settings']['upe_enabled_payment_method_ids'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertContains( 'woocommerce_woocommerce_payments_apple_pay_settings', $enabled['updated_split_options'] );
		$this->assertContains( 'woocommerce_woocommerce_payments_google_pay_settings', $enabled['updated_split_options'] );

		$disabled = $synchronizer->persist(
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'apple_pay', 'google_pay' ),
			),
			false
		);

		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertContains( 'woocommerce_woocommerce_payments_apple_pay_settings', $disabled['updated_split_options'] );
		$this->assertContains( 'woocommerce_woocommerce_payments_google_pay_settings', $disabled['updated_split_options'] );
	}

	/**
	 * @testdox A partially failed legacy wallet projection retains its target and completes on retry.
	 */
	public function test_persist_retries_partially_failed_payment_request_projection(): void {
		$google_option = 'woocommerce_woocommerce_payments_google_pay_settings';
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $google_option, $reject_update, 10, 2 );

		try {
			$first = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
				array(
					'payment_request'                => 'yes',
					'upe_enabled_payment_method_ids' => array( 'card' ),
				)
			);
		} finally {
			remove_filter( 'pre_update_option_' . $google_option, $reject_update, 10 );
		}

		$this->assertFalse( $first['persisted'] );
		$this->assertSame( array( $google_option ), $first['failed_option_names'] );
		$stored_settings = get_option( self::SETTINGS_OPTION );
		$this->assertArrayNotHasKey( 'payment_request', $stored_settings );
		$this->assertSame( 'yes', get_option( self::PAYMENT_REQUEST_PENDING_OPTION ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertFalse( get_option( $google_option, false ) );

		$second = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
			$stored_settings
		);

		$this->assertTrue( $second['persisted'] );
		$this->assertSame( 'yes', get_option( $google_option )['enabled'] );
		$this->assertArrayNotHasKey( 'payment_request', get_option( self::SETTINGS_OPTION ) );
		$this->assertFalse( get_option( self::PAYMENT_REQUEST_PENDING_OPTION, false ) );
	}

	/**
	 * @testdox A completed wallet projection is not replayed after an unrelated split gateway failure.
	 */
	public function test_persist_clears_completed_payment_request_projection_before_retrying_unrelated_failure(): void {
		$giropay_option = 'woocommerce_woocommerce_payments_giropay_settings';
		update_option( $giropay_option, array( 'enabled' => 'yes' ) );

		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $giropay_option, $reject_update, 10, 2 );

		try {
			$first = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
				array(
					'payment_request'                => 'yes',
					'upe_enabled_payment_method_ids' => array( 'card' ),
				)
			);
		} finally {
			remove_filter( 'pre_update_option_' . $giropay_option, $reject_update, 10 );
		}

		$this->assertFalse( $first['persisted'] );
		$this->assertSame( array( $giropay_option ), $first['failed_option_names'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );

		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => 'no' ) );
		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => 'yes' ) );

		$second = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist( $first['settings'] );

		$this->assertTrue( $second['persisted'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertFalse( get_option( self::PAYMENT_REQUEST_PENDING_OPTION, false ) );
	}

	/**
	 * @testdox Split wallet options are authoritative, with the legacy switch used only before migration.
	 */
	public function test_payment_request_read_uses_split_options_before_legacy_setting(): void {
		$synchronizer = new WooPaymentsGatewaySettingsSynchronizer();

		$this->assertTrue( $synchronizer->is_payment_request_enabled( array( 'payment_request' => 'yes' ) ) );

		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => 'no' ) );
		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => 'yes' ) );
		$this->assertTrue( $synchronizer->is_payment_request_enabled( array( 'payment_request' => 'no' ) ) );

		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => 'no' ) );
		$this->assertFalse( $synchronizer->is_payment_request_enabled( array( 'payment_request' => 'yes' ) ) );
	}

	/**
	 * @testdox Persisting stale legacy wallet state preserves authoritative split gateway choices.
	 */
	public function test_persist_ignores_legacy_payment_request_when_split_options_exist(): void {
		update_option( 'woocommerce_woocommerce_payments_google_pay_settings', array( 'enabled' => 'yes' ) );
		update_option( 'woocommerce_woocommerce_payments_apple_pay_settings', array( 'enabled' => 'no' ) );

		$result = ( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
			array(
				'payment_request'                => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card' ),
			)
		);

		$this->assertArrayNotHasKey( 'payment_request', $result['settings'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
	}

	/**
	 * @testdox Unrelated canonical saves preserve existing wallet split state.
	 */
	public function test_persist_without_payment_request_update_preserves_wallet_split_state(): void {
		update_option(
			'woocommerce_woocommerce_payments_google_pay_settings',
			array(
				'enabled' => 'yes',
				'custom'  => 'google',
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_apple_pay_settings',
			array(
				'enabled' => 'no',
				'custom'  => 'apple',
			)
		);

		( new WooPaymentsGatewaySettingsSynchronizer() )->persist(
			array( 'upe_enabled_payment_method_ids' => array( 'card', 'apple_pay', 'google_pay' ) )
		);

		$this->assertSame(
			array(
				'enabled' => 'yes',
				'custom'  => 'google',
			),
			get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )
		);
		$this->assertSame(
			array(
				'enabled' => 'no',
				'custom'  => 'apple',
			),
			get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )
		);
	}
}
