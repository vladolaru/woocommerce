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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsDuplicateGatewayMethodsService();
		$this->sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsLogger::class ) );
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
}
