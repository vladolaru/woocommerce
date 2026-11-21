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

		$this->assertSame( $expected, RequestTypeUtil::get_request_type( $signals['rest'] ?? false ) );
	}

	/** @return array<string,array{array<string,bool>,string}> */
	public static function provide_request_signals(): array {
		$all = array(
			'cli'       => true,
			'cron'      => true,
			'as_runner' => true,
			'ajax'      => true,
			'rest'      => true,
			'admin'     => true,
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
			'Action Scheduler async runner over admin-ajax' => array(
				array(
					'as_runner' => true,
					'ajax'      => true,
					'admin'     => true,
				),
				RequestTypeUtil::CRON,
			),
			'Action Scheduler async runner over admin-ajax, with a REST answer' => array(
				array(
					'as_runner' => true,
					'ajax'      => true,
					'rest'      => true,
					'admin'     => true,
				),
				RequestTypeUtil::CRON,
			),
			// The runner's signal counts only together with the admin-ajax state the runner arrives with.
			'runner signal outside admin-ajax'        => array( array( 'as_runner' => true ), RequestTypeUtil::FRONT ),
			'runner signal on a REST request'         => array(
				array(
					'as_runner' => true,
					'rest'      => true,
				),
				RequestTypeUtil::REST,
			),
			'runner signal on a wp-admin page'        => array(
				array(
					'as_runner' => true,
					'admin'     => true,
				),
				RequestTypeUtil::ADMIN,
			),
			'runner signal on AJAX, outside wp-admin' => array(
				array(
					'as_runner' => true,
					'ajax'      => true,
				),
				RequestTypeUtil::AJAX,
			),
		);
	}
}
