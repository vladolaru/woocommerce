<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminUrl;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments admin page URLs in the extension's WC Admin path shape.
 */
class WooPaymentsAdminUrlTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should build the extension's WC Admin URL with the path and query, as stored notes carry it.
	 */
	public function test_builds_the_wc_admin_path_url(): void {
		$url = WooPaymentsAdminUrl::get_wc_admin_path_url(
			'/payments/transactions/details',
			array(
				'id' => 'pi_123',
			)
		);

		$this->assertSame( admin_url( 'admin.php?page=wc-admin&path=/payments/transactions/details&id=pi_123' ), $url );
	}
}
