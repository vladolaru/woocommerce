<?php
/**
 * WooPaymentsPlatformConnectionService tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPlatformConnectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments platform connection readiness service.
 */
class WooPaymentsPlatformConnectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should report the cutover preflight failures of the platform transport: $_dataName.
	 * @dataProvider provide_cutover_preflight_states
	 *
	 * @param bool          $is_connected               Whether the store is connected.
	 * @param int           $connection_owner_id        Connection owner ID.
	 * @param bool          $connection_owner_connected Whether the connection owner has a user token.
	 * @param int|null      $blog_id                    WPCOM blog ID, or null when unavailable.
	 * @param array<string> $expected                   Expected failure codes.
	 */
	public function test_get_cutover_preflight_failures( bool $is_connected, int $connection_owner_id, bool $connection_owner_connected, ?int $blog_id, array $expected ): void {
		$sut          = new TestableWooPaymentsPlatformConnectionService();
		$sut->manager = $this->create_connection_manager( $is_connected, $connection_owner_id, $connection_owner_connected );
		$sut->blog_id = $blog_id;

		$this->assertSame( $expected, $sut->get_cutover_preflight_failures() );
	}

	/**
	 * Provide platform transport states: connected, owner ID, owner token, blog ID, expected failure codes.
	 *
	 * @return array<string,array{0:bool,1:int,2:bool,3:int|null,4:array<string>}>
	 */
	public function provide_cutover_preflight_states(): array {
		return array(
			'ready transport'               => array( true, 1, true, 123, array() ),
			'nothing ready, granular codes' => array(
				false,
				0,
				false,
				null,
				array(
					'wpcom_connection_unavailable',
					'wpcom_blog_id_unavailable',
					'wpcom_connection_owner_unavailable',
				),
			),
			'owner without a user token'    => array( true, 1, false, 123, array( 'wpcom_connection_owner_user_token_unavailable' ) ),
		);
	}

	/**
	 * @testdox Connection-owner status distinguishes a missing owner from a missing token and a deleted user.
	 */
	public function test_connection_owner_status_distinguishes_missing_owner_token_and_user(): void {
		$missing_owner          = new TestableWooPaymentsPlatformConnectionService();
		$missing_owner->manager = $this->create_connection_manager( true, 0, false );

		$without_token          = new TestableWooPaymentsPlatformConnectionService();
		$without_token->manager = $this->create_connection_manager( true, 1, false );

		$deleted_user          = new TestableWooPaymentsPlatformConnectionService();
		$deleted_user->manager = $this->create_connection_manager( true, 999999, true );

		$this->assertSame(
			array(
				'owner_id'             => 0,
				'owner_exists'         => false,
				'user_token_available' => false,
			),
			$missing_owner->get_cutover_connection_owner_user_token_status()
		);
		$this->assertSame(
			array(
				'owner_id'             => 1,
				'owner_exists'         => true,
				'user_token_available' => false,
			),
			$without_token->get_cutover_connection_owner_user_token_status()
		);
		$this->assertSame(
			array(
				'owner_id'             => 999999,
				'owner_exists'         => false,
				'user_token_available' => false,
			),
			$deleted_user->get_cutover_connection_owner_user_token_status()
		);
	}

	/**
	 * Create a Jetpack connection manager mock.
	 *
	 * @param bool $is_connected                Whether the store is connected.
	 * @param int  $connection_owner_id         Connection owner ID.
	 * @param bool $connection_owner_connected  Whether the connection owner has a user token.
	 * @return JetpackConnectionManager
	 */
	private function create_connection_manager( bool $is_connected, int $connection_owner_id, bool $connection_owner_connected ): JetpackConnectionManager {
		$manager = $this->getMockBuilder( JetpackConnectionManager::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_connected', 'get_connection_owner_id', 'is_user_connected' ) )
			->getMock();

		$manager
			->method( 'is_connected' )
			->willReturn( $is_connected );
		$manager
			->method( 'get_connection_owner_id' )
			->willReturn( $connection_owner_id );
		$manager
			->method( 'is_user_connected' )
			->willReturn( $connection_owner_connected );

		return $manager;
	}
}
