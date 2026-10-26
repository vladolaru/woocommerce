<?php
/**
 * PlatformConnectionService tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PlatformConnectionService;
use Jetpack_Options;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments platform connection readiness service.
 */
class PlatformConnectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * Clear the connection state the real Jetpack connection manager keeps.
	 */
	public function tearDown(): void {
		$this->reset_jetpack_connection();
		parent::tearDown();
	}

	/**
	 * @testdox Should report the cutover preflight failures of the platform transport: $_dataName.
	 * @dataProvider provide_cutover_preflight_states
	 *
	 * @param bool          $is_connected        Whether the store is connected.
	 * @param int           $connection_owner_id Connection owner ID.
	 * @param int|null      $blog_id             WPCOM blog ID, or null when unavailable.
	 * @param array<string> $expected            Expected failure codes.
	 */
	public function test_get_preflight_failures( bool $is_connected, int $connection_owner_id, ?int $blog_id, array $expected ): void {
		$sut          = new TestablePlatformConnectionService();
		$sut->manager = $this->create_connection_manager( $is_connected, $connection_owner_id );
		$http_client  = $this->createMock( HttpClient::class );
		$http_client->method( 'get_blog_id' )->willReturn( $blog_id );
		$sut->init( $http_client );

		$this->assertSame( $expected, $sut->get_preflight_failures() );
	}

	/**
	 * Provide platform transport states: connected, owner ID, blog ID, expected failure codes.
	 *
	 * @return array<string,array{0:bool,1:int,2:int|null,3:array<string>}>
	 */
	public function provide_cutover_preflight_states(): array {
		return array(
			'ready transport'               => array( true, 1, 123, array() ),
			'nothing ready, granular codes' => array(
				false,
				0,
				null,
				array(
					'wpcom_connection_unavailable',
					'wpcom_blog_id_unavailable',
					'wpcom_connection_owner_unavailable',
				),
			),
		);
	}

	/**
	 * Jetpack reports a deleted connection owner, and an owner whose user token was removed, as no owner while the blog
	 * token stays: the one state a connected site can reach where the connection owner is gone. Nothing is mocked; the
	 * states come from the Jetpack connection package WooCommerce vendors.
	 *
	 * @testdox A connected site whose connection owner is gone reports only the connection owner failure: $_dataName.
	 * @dataProvider provide_connection_owner_removals
	 *
	 * @param string $removal How the owner is removed.
	 */
	public function test_connected_site_without_a_connection_owner_reports_only_the_owner_failure( string $removal ): void {
		$manager = $this->connect_site_with_owner();

		$this->assertSame( array(), $this->create_service( $manager )->get_preflight_failures(), 'The connected site is ready before its owner is removed.' );

		$this->remove_connection_owner( $manager, $removal );

		$this->assertSame( array( 'wpcom_connection_owner_unavailable' ), $this->create_service( $manager )->get_preflight_failures() );
	}

	/**
	 * A site that also lost its blog token has no connection left, so it reports the connection failure beside the owner one.
	 *
	 * @testdox A site with no blog token also reports the connection failure when its owner is deleted.
	 */
	public function test_disconnected_site_without_a_connection_owner_also_reports_the_connection_failure(): void {
		$manager = $this->connect_site_with_owner();
		Jetpack_Options::delete_option( 'blog_token' );
		$this->remove_connection_owner( $manager, 'deleted' );

		$this->assertSame(
			array( 'wpcom_connection_unavailable', 'wpcom_connection_owner_unavailable' ),
			$this->create_service( $manager )->get_preflight_failures()
		);
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_connection_owner_removals(): array {
		return array(
			'the owner user is deleted'                   => array( 'deleted' ),
			'the owner user is deleted and Jetpack heard' => array( 'deleted_and_disconnected' ),
			'the owner keeps the user but loses its user token' => array( 'token_removed' ),
		);
	}

	/**
	 * Connect the site the way Jetpack stores it: a blog ID, a blog token, and a connection owner with a user token.
	 *
	 * @return JetpackConnectionManager
	 */
	private function connect_site_with_owner(): JetpackConnectionManager {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// automattic/jetpack-connection 6.19.12: is_connected() needs the blog ID and a blog token (src/class-manager.php:662-674),
		// and get_access_token() takes the stored blog token as is (src/class-tokens.php:403-407).
		Jetpack_Options::update_option( 'id', 123 );
		Jetpack_Options::update_option( 'blog_token', 'blogkey.blogsecret' );

		$manager = new JetpackConnectionManager( 'woocommerce' );
		// A user token is "<key>.<secret>.<user ID>" (src/class-tokens.php:393-402); update_user_token() with true also stores
		// the owner as master_user (src/class-tokens.php:273-286).
		$manager->get_tokens()->update_user_token( $owner_id, 'usersecret.userkey.' . $owner_id, true );
		$manager->reset_connection_status();

		return $manager;
	}

	/**
	 * Remove the connection owner the way a store loses one, without any request to WordPress.com.
	 *
	 * @param JetpackConnectionManager $manager Jetpack connection manager.
	 * @param string                   $removal 'deleted' deletes the user; 'deleted_and_disconnected' also does what Jetpack's
	 *                                          deleted_user handler does locally (removes the token and the owner option);
	 *                                          'token_removed' removes only the user token.
	 */
	private function remove_connection_owner( JetpackConnectionManager $manager, string $removal ): void {
		$owner_id = (int) Jetpack_Options::get_option( 'master_user' );
		$this->assertGreaterThan( 0, $owner_id );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'No request to WordPress.com from a test.' ) );

		if ( 'token_removed' !== $removal ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $owner_id );
		}
		// automattic/jetpack-connection 6.19.12: the deleted_user handler (src/class-manager.php:164) removes the user token
		// locally (src/class-tokens.php:494-509) and then the master_user option (src/class-manager.php:1052-1079), here
		// without its request to WordPress.com.
		if ( 'token_removed' === $removal || 'deleted_and_disconnected' === $removal ) {
			$manager->get_tokens()->disconnect_user( $owner_id );
		}
		if ( 'deleted_and_disconnected' === $removal ) {
			Jetpack_Options::delete_option( 'master_user' );
		}
		// get_connection_owner_id() returns an ID only when the owner has a user token and a WordPress user
		// (src/class-manager.php:830-839, 887-922); reset_connection_status() drops its memo (src/class-manager.php:685-688).
		$manager->reset_connection_status();
	}

	/**
	 * Create the service over a Jetpack connection manager and a WordPress.com blog ID.
	 *
	 * @param JetpackConnectionManager $manager Jetpack connection manager.
	 * @return TestablePlatformConnectionService
	 */
	private function create_service( JetpackConnectionManager $manager ): TestablePlatformConnectionService {
		$sut          = new TestablePlatformConnectionService();
		$sut->manager = $manager;
		$http_client  = $this->createMock( HttpClient::class );
		$http_client->method( 'get_blog_id' )->willReturn( 123 );
		$sut->init( $http_client );

		return $sut;
	}

	/**
	 * Forget the connection the real Jetpack manager stored for a test, and its memoized status.
	 */
	private function reset_jetpack_connection(): void {
		Jetpack_Options::delete_option( array( 'id', 'blog_token', 'user_tokens', 'master_user' ) );
		( new JetpackConnectionManager( 'woocommerce' ) )->reset_connection_status();
	}

	/**
	 * Create a Jetpack connection manager mock.
	 *
	 * @param bool $is_connected        Whether the store is connected.
	 * @param int  $connection_owner_id Connection owner ID.
	 * @return JetpackConnectionManager
	 */
	private function create_connection_manager( bool $is_connected, int $connection_owner_id ): JetpackConnectionManager {
		$manager = $this->getMockBuilder( JetpackConnectionManager::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_connected', 'get_connection_owner_id' ) )
			->getMock();

		$manager
			->method( 'is_connected' )
			->willReturn( $is_connected );
		$manager
			->method( 'get_connection_owner_id' )
			->willReturn( $connection_owner_id );

		return $manager;
	}
}
