<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPay toggle round-trip through the WooPaymentsSettingsService contract.
 *
 * The sibling WooPaymentsSettingsServiceTest proves the raw persistence halves of the
 * mapping (is_woopay_enabled true => platform_checkout 'yes', false => 'no' plus the
 * disable-date rule) and the enabled read (platform_checkout 'yes' => is_woopay_enabled
 * true), but never asserts that the settings contract *echoes false* — neither in the
 * refreshed contract a disable save returns nor in a fresh get_settings() read. That
 * echo is exactly what the admin UI and the E2E smoke consume, so it is pinned here.
 *
 * Staged for integration: these methods are drop-in compatible with
 * WooPaymentsSettingsServiceTest (they only use the shared collaborator setup and the
 * gateway settings option) and should be folded into that class when this package lands.
 */
class WooPaymentsSettingsServiceWooPayToggleTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSettingsService
	 */
	private $sut;

	/**
	 * Original gateway settings option value, restored after each test.
	 *
	 * @var mixed
	 */
	private $original_gateway_settings;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_gateway_settings = get_option( 'woocommerce_woocommerce_payments_settings', null );

		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );

		$this->sut = new WooPaymentsSettingsService();
		$this->sut->init(
			$account_service,
			new RecordingSettingsApiClient(),
			new RecordingPmPromotionsService(),
			new RecordingWooPaySessionService()
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( null !== $this->original_gateway_settings ) {
			update_option( 'woocommerce_woocommerce_payments_settings', $this->original_gateway_settings );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_settings' );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should echo the disabled WooPay state through the refreshed contract and a fresh settings read.
	 */
	public function test_update_settings_echoes_disabled_woopay_state_through_settings_contract(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout' => 'yes',
			)
		);

		$result = $this->sut->update_settings( array( 'is_woopay_enabled' => false ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['is_woopay_enabled'], 'The refreshed contract returned by the disable save should report WooPay disabled.' );
		$this->assertSame( 'no', get_option( 'woocommerce_woocommerce_payments_settings' )['platform_checkout'] );

		$settings = $this->sut->get_settings();

		$this->assertIsArray( $settings );
		$this->assertFalse( $settings['is_woopay_enabled'], 'A fresh settings read after the disable save should report WooPay disabled.' );
	}

	/**
	 * @testdox Should echo the enabled WooPay state through the refreshed contract and a fresh settings read.
	 */
	public function test_update_settings_echoes_enabled_woopay_state_through_settings_contract(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'platform_checkout' => 'no',
			)
		);

		$result = $this->sut->update_settings( array( 'is_woopay_enabled' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_woopay_enabled'], 'The refreshed contract returned by the enable save should report WooPay enabled.' );
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_settings' )['platform_checkout'] );

		$settings = $this->sut->get_settings();

		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['is_woopay_enabled'], 'A fresh settings read after the enable save should report WooPay enabled.' );
	}
}
