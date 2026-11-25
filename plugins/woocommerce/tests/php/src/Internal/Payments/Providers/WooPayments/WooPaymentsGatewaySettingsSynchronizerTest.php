<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsGatewaySettingsSynchronizer class.
 */
class WooPaymentsGatewaySettingsSynchronizerTest extends WC_Unit_Test_Case {

	private const SETTINGS_OPTION                = 'woocommerce_woocommerce_payments_settings';
	private const PAYMENT_REQUEST_PENDING_OPTION = 'woocommerce_woopayments_payment_request_sync_pending';

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
	 * @testdox Canonical method IDs project into split gateway settings and remove discontinued methods.
	 */
	public function test_persist_projects_canonical_settings_and_removes_discontinued_methods(): void {
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

		$synchronizer = $this->create_synchronizer();
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
	 * Client 11.1.0 syncs the settings of every gateway in its gateway map (`includes/admin/class-wc-rest-payments-settings-controller.php:766-795`),
	 * built from the registered definitions (`includes/class-wc-payments.php:616-645`), which the availability filter does not change.
	 *
	 * @testdox Should project the split settings of a payment method the availability filter removes.
	 */
	public function test_persist_projects_a_method_the_availability_filter_removes(): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn( array $payment_method_ids ): array => array_values( array_diff( $payment_method_ids, array( 'sepa_debit' ) ) )
		);

		$this->create_synchronizer()->persist(
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'sepa_debit' ),
			)
		);

		$this->assertSame(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'sepa_debit' ),
			),
			get_option( 'woocommerce_woocommerce_payments_sepa_debit_settings' )
		);
	}

	/**
	 * @testdox Reapplying an already projected settings state is intrinsically idempotent.
	 */
	public function test_persist_is_intrinsically_idempotent(): void {
		$synchronizer = $this->create_synchronizer();
		$settings     = array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) );

		$first  = $synchronizer->persist( $settings );
		$second = $synchronizer->persist( $first['settings'] );

		$this->assertNotEmpty( $first['updated_split_options'] );
		$this->assertSame( array(), $second['updated_split_options'] );
		$this->assertSame( $first['settings'], $second['settings'] );
	}

	/**
	 * @testdox An externally drifted split gateway row is logged once and healed from canonical settings.
	 */
	public function test_persist_detects_logs_and_heals_split_gateway_drift(): void {
		$option_name = 'woocommerce_woocommerce_payments_ideal_settings';
		$settings    = array( 'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ) );
		$logger      = RecordingWcLogger::install();

		$synchronizer       = $this->create_synchronizer();
		$first              = $synchronizer->persist( $settings );
		$drifted            = get_option( $option_name );
		$drifted['enabled'] = 'no';
		$drifted['custom']  = 'preserved';
		update_option( $option_name, $drifted );

		$second = $synchronizer->persist( $first['settings'] );

		$this->assertCount( 1, $logger->lines, 'The drift is logged once.' );
		$this->assertSame( array( 'warning', 'woopayments' ), array( $logger->lines[0][0], $logger->lines[0][2] ) );
		$this->assertSame( 'split_gateway_settings_drift', $logger->contexts[0]['event'] ?? null );
		$this->assertSame( array( $option_name ), $logger->contexts[0]['drifted_option_names'] ?? null );
		$this->assertSame( array(), $logger->contexts[0]['failed_option_names'] ?? null );
		$this->assertTrue( $second['persisted'] );
		$this->assertSame( array( $option_name ), $second['updated_split_options'] );
		$this->assertSame(
			array(
				'enabled'                        => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
				'custom'                         => 'preserved',
			),
			get_option( $option_name )
		);
	}

	/**
	 * @testdox A rejected canonical write fails before the split gateway writes start.
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
			$result = $this->create_synchronizer()->persist(
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
	 * @testdox A rejected split gateway write is returned as a verified write failure.
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
			$result = $this->create_synchronizer()->persist( $settings );
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
		$synchronizer = $this->create_synchronizer();
		$enabled      = $synchronizer->persist(
			array( 'upe_enabled_payment_method_ids' => array( 'card' ) ),
			true
		);

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
	 * @testdox A partially failed wallet write retains its target and completes on retry.
	 */
	public function test_persist_retries_partially_failed_payment_request_write(): void {
		$google_option = 'woocommerce_woocommerce_payments_google_pay_settings';
		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $google_option, $reject_update, 10, 2 );

		try {
			$first = $this->create_synchronizer()->persist(
				array( 'upe_enabled_payment_method_ids' => array( 'card' ) ),
				true
			);
		} finally {
			remove_filter( 'pre_update_option_' . $google_option, $reject_update, 10 );
		}

		$this->assertFalse( $first['persisted'] );
		$this->assertSame( array( $google_option ), $first['failed_option_names'] );
		$stored_settings = get_option( self::SETTINGS_OPTION );
		$this->assertSame( 'yes', get_option( self::PAYMENT_REQUEST_PENDING_OPTION ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertFalse( get_option( $google_option, false ) );

		$second = $this->create_synchronizer()->persist(
			$stored_settings
		);

		$this->assertTrue( $second['persisted'] );
		$this->assertSame( 'yes', get_option( $google_option )['enabled'] );
		$this->assertFalse( get_option( self::PAYMENT_REQUEST_PENDING_OPTION, false ) );
	}

	/**
	 * @testdox A completed wallet write is not replayed after an unrelated split gateway failure.
	 */
	public function test_persist_clears_completed_payment_request_write_before_retrying_unrelated_failure(): void {
		$giropay_option = 'woocommerce_woocommerce_payments_giropay_settings';
		update_option( $giropay_option, array( 'enabled' => 'yes' ) );

		$reject_update = static fn( $value, $old_value ) => $old_value;
		add_filter( 'pre_update_option_' . $giropay_option, $reject_update, 10, 2 );

		try {
			$first = $this->create_synchronizer()->persist(
				array( 'upe_enabled_payment_method_ids' => array( 'card' ) ),
				true
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

		$second = $this->create_synchronizer()->persist( $first['settings'] );

		$this->assertTrue( $second['persisted'] );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_apple_pay_settings' )['enabled'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_google_pay_settings' )['enabled'] );
		$this->assertFalse( get_option( self::PAYMENT_REQUEST_PENDING_OPTION, false ) );
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

		$this->create_synchronizer()->persist(
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

	/**
	 * Create a synchronizer with the container's account service and registry, and no setup tier, so writes keep the tier.
	 *
	 * @return WooPaymentsGatewaySettingsSynchronizer
	 */
	private function create_synchronizer(): WooPaymentsGatewaySettingsSynchronizer {
		$synchronizer = new WooPaymentsGatewaySettingsSynchronizer();
		$synchronizer->init( wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( PaymentMethodRegistry::class ) );

		return $synchronizer;
	}
}
