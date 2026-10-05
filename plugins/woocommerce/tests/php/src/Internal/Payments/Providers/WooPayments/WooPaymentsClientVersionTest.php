<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsClientVersion class.
 */
class WooPaymentsClientVersionTest extends WC_Unit_Test_Case {

	/**
	 * The platform's client-version parser, copied verbatim from the wpcom clone:
	 * wp-content/rest-api-plugins/endpoints/wcpay/core/class-wcpay-rest-request.php:143
	 * (WCPay_REST_Request::get_client_version(), :125-150; file last changed in wpcom 99851857510).
	 * Group 1 is the version every is_client_version_at_least() gate compares (same file, :82-89).
	 */
	private const PLATFORM_CLIENT_VERSION_PATTERN = '/^(?:WooCommerce Payments|WCPay|WooPayments|TransactGateway)\/(\d[\d\.]*(?<!\.))(-.*)?$/i';

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		Constants::clear_single_constant( 'WC_VERSION' );
		parent::tearDown();
	}

	/**
	 * @testdox Should declare the WCPay version the native runtime was verified against; a bump is a deliberate, reviewed change.
	 */
	public function test_version_is_the_verified_platform_contract(): void {
		$this->assertSame( '11.1.0', WooPaymentsClientVersion::VERSION, 'Bumping the declared client version requires reviewing every platform gate between the versions first — see the class docblock before changing this.' );
	}

	/**
	 * @testdox Should report the pinned client version followed by the running WooCommerce version: $wc_version.
	 * @dataProvider woocommerce_version_provider
	 *
	 * @param string $wc_version WooCommerce version constant value.
	 * @param string $expected   Expected user agent.
	 */
	public function test_user_agent_carries_the_running_woocommerce_version( string $wc_version, string $expected ): void {
		Constants::set_constant( 'WC_VERSION', $wc_version );

		$this->assertSame( $expected, WooPaymentsClientVersion::get_user_agent() );
	}

	/**
	 * WooCommerce version forms: a trunk build drops -dev, published pre-releases keep their tag.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function woocommerce_version_provider(): array {
		return array(
			'final release'             => array( '10.4.0', 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0' ),
			'trunk build'               => array( '10.4.0-dev', 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0' ),
			'release candidate'         => array( '10.4.0-rc.1', 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0-rc.1' ),
			'beta'                      => array( '10.4.0-beta.2', 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0-beta.2' ),
			'header-unsafe value'       => array( "10.4.0\r\nX-Injected: 1", 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0X-Injected1' ),
			'a -dev tag not at the end' => array( '10.4.0-dev.1', 'WooCommerce Payments/11.1.0-native-woocommerce/10.4.0-dev.1' ),
		);
	}

	/**
	 * @testdox Should stay parseable by the platform, whose version gates read the pinned 11.1.0: $wc_version.
	 * @dataProvider platform_parse_provider
	 *
	 * @param string|null $wc_version WooCommerce version constant value, or null for the real runtime value.
	 */
	public function test_platform_parser_reads_the_pinned_client_version( ?string $wc_version ): void {
		if ( null !== $wc_version ) {
			Constants::set_constant( 'WC_VERSION', $wc_version );
		}

		$user_agent = WooPaymentsClientVersion::get_user_agent();
		$matches    = array();

		$this->assertSame( 1, preg_match( self::PLATFORM_CLIENT_VERSION_PATTERN, $user_agent, $matches ), 'The platform must still recognize the client: ' . $user_agent );
		$this->assertSame( '11.1.0', $matches[1] );
		$this->assertTrue( version_compare( $matches[1], WooPaymentsClientVersion::VERSION, '>=' ), 'is_client_version_at_least( 11.1.0 ) must hold.' );
		$this->assertFalse( version_compare( $matches[1], '11.1.1', '>=' ), 'The WooCommerce version must not raise the gated client version.' );
		$this->assertMatchesRegularExpression( '/^[\x21-\x7E ]+$/', $user_agent, 'Only visible ASCII and spaces are valid in an HTTP header value.' );
	}

	/**
	 * WooCommerce versions the platform parser must handle.
	 *
	 * @return array<string,array{string|null}>
	 */
	public function platform_parse_provider(): array {
		return array(
			'running WooCommerce' => array( null ),
			'trunk build'         => array( '11.2.0-dev' ),
			'release candidate'   => array( '11.2.0-rc.1' ),
			'beta'                => array( '11.2.0-beta.1' ),
			'final release'       => array( '11.2.0' ),
		);
	}
}
