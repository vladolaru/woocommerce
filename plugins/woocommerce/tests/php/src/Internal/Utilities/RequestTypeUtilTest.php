<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Utilities;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the Internal\Utilities\RequestTypeUtil class.
 */
class RequestTypeUtilTest extends WC_Unit_Test_Case {

	/** Clear the request signals a case sets. */
	public function tearDown(): void {
		Constants::clear_single_constant( 'WP_CLI' );
		remove_filter( 'wp_doing_cron', '__return_true' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_REQUEST['action'] );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * @testdox A request with the signals of $_dataName is a $expected request.
	 * @dataProvider provide_request_signals
	 *
	 * @param array<string,bool> $signals  Request signals: cli, cron, as_runner, ajax, rest, admin.
	 * @param string             $expected Expected request type.
	 */
	public function test_get_request_type_picks_one_type_in_precedence_order( array $signals, string $expected ): void {
		if ( $signals['cli'] ?? false ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( $signals['cron'] ?? false ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		}
		if ( $signals['as_runner'] ?? false ) {
			$_REQUEST['action'] = 'as_async_request_queue_runner';
		}
		if ( $signals['ajax'] ?? false ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}
		if ( $signals['admin'] ?? false ) {
			set_current_screen( 'edit-page' );
		}
		$rest_checks = 0;

		$type = RequestTypeUtil::get_request_type(
			static function () use ( $signals, &$rest_checks ): bool {
				++$rest_checks;
				return $signals['rest'] ?? false;
			}
		);

		$this->assertSame( $expected, $type );
		$this->assertSame( 1, $rest_checks, 'The REST check runs once on every request.' );
	}

	/** @return array<string,array{array<string,bool>,string}> */
	public static function provide_request_signals(): array {
		$all = array(
			'cli'   => true,
			'cron'  => true,
			'ajax'  => true,
			'rest'  => true,
			'admin' => true,
		);

		return array(
			'WP-CLI'                                  => array( array( 'cli' => true ), RequestTypeUtil::CLI ),
			'cron'                                    => array( array( 'cron' => true ), RequestTypeUtil::CRON ),
			'AJAX'                                    => array( array( 'ajax' => true ), RequestTypeUtil::AJAX ),
			'REST'                                    => array( array( 'rest' => true ), RequestTypeUtil::REST ),
			'admin'                                   => array( array( 'admin' => true ), RequestTypeUtil::ADMIN ),
			'front'                                   => array( array(), RequestTypeUtil::FRONT ),
			'WP-CLI with every other signal'          => array( $all, RequestTypeUtil::CLI ),
			'cron with AJAX, REST and admin'          => array( array_merge( $all, array( 'cli' => false ) ), RequestTypeUtil::CRON ),
			'AJAX with REST and admin'                => array(
				array(
					'ajax'  => true,
					'rest'  => true,
					'admin' => true,
				),
				RequestTypeUtil::AJAX,
			),
			'REST with admin'                         => array(
				array(
					'rest'  => true,
					'admin' => true,
				),
				RequestTypeUtil::REST,
			),
			// admin-ajax.php sets both is_admin() and DOING_AJAX; WooCommerce::is_request( 'frontend' ) is true there too.
			'AJAX from wp-admin'                      => array(
				array(
					'ajax'  => true,
					'admin' => true,
				),
				RequestTypeUtil::AJAX,
			),
			// Action Scheduler's async runner posts to admin-ajax.php without DOING_CRON, which WooCommerce::is_request( 'cron' ) reads.
			'Action Scheduler async runner from AJAX' => array(
				array(
					'as_runner' => true,
					'ajax'      => true,
					'admin'     => true,
				),
				RequestTypeUtil::CRON,
			),
			'Action Scheduler async runner alone'     => array( array( 'as_runner' => true ), RequestTypeUtil::CRON ),
		);
	}
}
