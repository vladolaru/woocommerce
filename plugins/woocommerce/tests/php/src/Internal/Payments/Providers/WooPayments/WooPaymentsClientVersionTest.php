<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsClientVersion class.
 */
class WooPaymentsClientVersionTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should declare the WCPay version the native runtime was verified against; a bump is a deliberate, reviewed change.
	 */
	public function test_version_is_the_verified_platform_contract(): void {
		$this->assertSame( '10.8.0', WooPaymentsClientVersion::VERSION, 'Bumping the declared client version requires reviewing every platform gate between the versions first — see the class docblock before changing this.' );
	}

	/**
	 * @testdox Should keep the user-agent in the WooCommerce Payments/<version> shape the platform parser expects.
	 */
	public function test_user_agent_keeps_the_platform_parseable_shape(): void {
		$this->assertSame( 'WooCommerce Payments/' . WooPaymentsClientVersion::VERSION, WooPaymentsClientVersion::get_user_agent() );
		$this->assertMatchesRegularExpression( '/^WooCommerce Payments\/\d+\.\d+\.\d+$/', WooPaymentsClientVersion::get_user_agent() );
	}
}
