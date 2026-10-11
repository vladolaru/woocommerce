<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOverviewService;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the Overview shell `wpcom_reconnect_url`, which shows the "Reconnect WooPayments" task.
 *
 * Client 11.1.0 `class-wc-payments-admin.php:1035`: the URL is sent only when the server is connected and has no connection owner.
 */
class WooPaymentsOverviewServiceReconnectUrlTest extends WC_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		JetpackConnection::get_manager()->reset_connection_status();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			// The Jetpack connection manager memoizes the connection state in static properties.
			JetpackConnection::get_manager()->reset_connection_status();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should send the client's nonce-protected reconnect URL when the connection has no owner.
	 */
	public function test_sends_reconnect_url_when_connected_without_owner(): void {
		$sut = $this->create_sut( true, false );

		$url = $sut->get_overview()['wpcom_reconnect_url'];

		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( admin_url( 'admin.php?wcpay-reconnect-wpcom=1&_wpnonce=' . ( $query['_wpnonce'] ?? '' ) ), $url, 'The URL keeps the client shape: admin.php with only the flag and the nonce.' );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'] ?? '', 'wcpay-reconnect-wpcom' ) );
	}

	/**
	 * @testdox Should send no reconnect URL when connected=$is_connected and has owner=$has_owner.
	 *
	 * @testWith [true, true]
	 *           [false, false]
	 *           [false, true]
	 *
	 * @param bool $is_connected Whether the server connection works.
	 * @param bool $has_owner    Whether the connection has an owner.
	 */
	public function test_sends_no_reconnect_url_otherwise( bool $is_connected, bool $has_owner ): void {
		$sut = $this->create_sut( $is_connected, $has_owner );

		$this->assertSame( '', $sut->get_overview()['wpcom_reconnect_url'] );
	}

	/**
	 * @testdox Should send no reconnect URL for a site-level connection without an owner, because the client's connection check needs an owner.
	 */
	public function test_real_site_connection_without_owner_sends_no_url(): void {
		\Jetpack_Options::update_option( 'id', 12345 );
		\Jetpack_Options::update_option( 'blog_token', 'token-key.blog-token' );
		\Jetpack_Options::delete_option( 'master_user' );
		JetpackConnection::get_manager()->reset_connection_status();

		$this->assertTrue( JetpackConnection::get_manager()->is_connected(), 'The site-level connection works.' );
		$this->assertFalse( JetpackConnection::get_manager()->has_connected_owner(), 'The connection has no owner.' );

		$this->assertSame( '', $this->create_real_sut()->get_overview()['wpcom_reconnect_url'] );
	}

	/**
	 * Create the SUT with stubbed connection checks.
	 *
	 * @param bool $is_connected Whether the server connection works.
	 * @param bool $has_owner    Whether the connection has an owner.
	 * @return WooPaymentsOverviewService&MockObject
	 */
	private function create_sut( bool $is_connected, bool $has_owner ): WooPaymentsOverviewService {
		$sut = $this->getMockBuilder( WooPaymentsOverviewService::class )
			->onlyMethods( array( 'is_server_connected', 'has_server_connection_owner' ) )
			->getMock();
		$sut->method( 'is_server_connected' )->willReturn( $is_connected );
		$sut->method( 'has_server_connection_owner' )->willReturn( $has_owner );
		$sut->init( $this->create_account_service() );

		return $sut;
	}

	/**
	 * Create the SUT with the real Jetpack connection checks.
	 *
	 * @return WooPaymentsOverviewService
	 */
	private function create_real_sut(): WooPaymentsOverviewService {
		$sut = new WooPaymentsOverviewService();
		$sut->init( $this->create_account_service() );

		return $sut;
	}

	/**
	 * Create the native account service dependency.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$account_service = new WooPaymentsAccountService();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );

		return $account_service;
	}
}
