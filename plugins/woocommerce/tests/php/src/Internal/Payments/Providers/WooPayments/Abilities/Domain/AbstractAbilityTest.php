<?php
/**
 * Exercise WooPayments abilities through WordPress and an isolated REST server.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\GetAuthorizations;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;

/** Exercise the callbacks with no network or payment dependencies. */
class AbstractAbilityTest extends AbilityTestCase {
	private const API    = '/wc/v3/payments/';
	private const ROUTES = array(
		'get-account'                => 'accounts',
		'get-deposits-overview'      => 'deposits/overview-all',
		'get-active-loan-summary'    => 'capital/active_loan_summary',
		'get-authorizations'         => 'authorizations',
		'get-authorizations-summary' => 'authorizations/summary',
	);

	/**
	 * REST server active before this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server;
	/**
	 * Method, path and parameters observed by the backing routes.
	 *
	 * @var array
	 */
	private $calls = array();
	/**
	 * Response to return for each registered route.
	 *
	 * @var array
	 */
	private $bodies;
	/**
	 * Whether the test route grants permission.
	 *
	 * @var bool
	 */
	private $route_allowed = true;

	/** Give each backing route a distinct body and record the request it receives. */
	public function setUp(): void {
		parent::setUp();
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$this->original_server     = $GLOBALS['wp_rest_server'] ?? null;
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		foreach ( self::ROUTES as $suffix ) {
			$this->bodies[ $suffix ] = array( 'source' => $suffix );
			$server->register_route(
				'wc/v3',
				self::API . $suffix,
				array(
					array(
						'methods'             => 'GET',
						'permission_callback' => fn() => $this->route_allowed,
						'callback'            => function ( $request ) use ( $suffix ) {
							$this->calls[] = array( $request->get_method(), $request->get_route(), $request->get_params() );
							return $this->bodies[ $suffix ];
						},
					),
				)
			);
		}
		$this->bodies['authorizations']         = array( 'data' => array() );
		$this->bodies['authorizations/summary'] = array(
			'count' => 5,
			'total' => 9400,
		);
	}

	/** Restore the previously active REST server. */
	public function tearDown(): void {
		$GLOBALS['wp_rest_server'] = $this->original_server;
		parent::tearDown();
	}

	/**
	 * Return a registered ability, failing explicitly when its name is missing.
	 *
	 * @param string $slug Provider ability slug.
	 * @return \WP_Ability Registered ability.
	 */
	private function ability( string $slug = 'get-authorizations' ): \WP_Ability {
		$this->assertTrue( wp_has_ability( self::PREFIX . $slug ), $slug );
		return wp_get_ability( self::PREFIX . $slug );
	}

	/**
	 * Requiring manage_options prevents shop managers from executing reads.
	 *
	 * @testdox Administrators and shop managers can execute all five reads.
	 */
	public function test_manager_permissions(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			foreach ( self::ROUTES as $name => $suffix ) {
				$this->calls = array();
				$ability     = $this->ability( $name );
				$this->assertTrue( $ability->check_permissions() );
				$this->assertIsArray( $ability->execute() );
				$this->assertSame( self::API . $suffix, $this->calls[0][1] );
			}
		}
	}

	/**
	 * Allowing signed-in customers or guests dispatches private reads.
	 *
	 * @testdox Customers and guests are refused before a read reaches its route.
	 */
	public function test_customer_and_guest(): void {
		foreach ( array( self::factory()->user->create( array( 'role' => 'customer' ) ), 0 ) as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( self::ROUTES as $name => $suffix ) {
				$ability = $this->ability( $name );
				$this->assertFalse( $ability->check_permissions() );
				$this->assertSame( 'ability_invalid_permissions', $ability->execute()->get_error_code() );
			}
		}
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A direct controller call bypasses the backing route permission check.
	 *
	 * @testdox A backing route can refuse a caller who passes the ability permission check.
	 */
	public function test_route_permission(): void {
		$this->route_allowed = false;
		$ability             = $this->ability( 'get-account' );
		$this->assertTrue( $ability->check_permissions() );
		$this->assertSame( 'rest_forbidden', $ability->execute()->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Routing the loan read to the overview returns the wrong body.
	 *
	 * @testdox Each single read returns its own route body after one GET.
	 */
	public function test_single_routes(): void {
		foreach ( self::ROUTES as $name => $suffix ) {
			if ( 'get-authorizations' === $name ) {
				continue;
			}
			$this->calls = array();
			$this->assertSame( $this->bodies[ $suffix ], $this->ability( $name )->execute() );
			$this->assertSame( array( array( 'GET', self::API . $suffix, array() ) ), $this->calls );
		}
	}

	/**
	 * Applying the legacy empty-account guard to loans rejects no active loan.
	 *
	 * @testdox The loan summary preserves an empty response when no loan is active.
	 */
	public function test_empty_loan(): void {
		$this->bodies['capital/active_loan_summary'] = array();
		$this->assertSame( array(), $this->ability( 'get-active-loan-summary' )->execute() );
	}

	/**
	 * Unwrapping an error response as data loses the REST code, message and status.
	 *
	 * @testdox A delegated REST error retains its code, message and status.
	 */
	public function test_route_error(): void {
		$this->bodies['accounts'] = new \WP_REST_Response(
			array(
				'code'    => 'account_error',
				'message' => 'Unavailable',
				'data'    => array( 'status' => 503 ),
			),
			503
		);
		$error                    = $this->ability( 'get-account' )->execute();
		$this->assertWPError( $error );
		$this->assertSame( 'account_error', $error->get_error_code() );
		$this->assertSame( 'Unavailable', $error->get_error_message() );
		$this->assertSame( array( 'status' => 503 ), $error->get_error_data() );
	}

	/**
	 * A debug-gated provider logger loses REST failures when debug logging is off.
	 *
	 * @testdox Delegation errors are logged under woopayments-abilities with debug logging off.
	 */
	public function test_delegation_log(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->expects( $this->once() )->method( 'error' )->with( 'AbilitiesRegistrar delegation failed [GET /wc/v3/payments/accounts]: Unavailable (account_error)', array( 'source' => 'woopayments-abilities' ) );
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->bodies['accounts'] = new \WP_Error( 'account_error', 'Unavailable', array( 'status' => 503 ) );
		$this->assertWPError( $this->ability( 'get-account' )->execute() );
	}

	/**
	 * Returning scalar response data violates the delegate's array contract.
	 *
	 * @testdox A successful scalar response becomes an empty array.
	 */
	public function test_scalar_body(): void {
		add_filter( 'rest_pre_dispatch', static fn() => new \WP_REST_Response( 'scalar' ) );
		$this->assertSame( array(), $this->ability( 'get-account' )->execute() );
	}

	/**
	 * A default page size of ten changes both the request and the returned page.
	 *
	 * @testdox An omitted input requests page one with 25 records per page.
	 */
	public function test_paging_defaults(): void {
		$result = $this->ability()->execute();
		$this->assertSame(
			array(
				'page'     => 1,
				'pagesize' => 25,
			),
			$this->calls[0][2]
		);
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 25, $result['per_page'] );
	}

	/**
	 * Leaving order in the request drops the canonical direction parameter.
	 *
	 * @testdox The list route receives the requested page, size, sort field and direction under its own parameter names.
	 */
	public function test_paging_aliases(): void {
		$result = $this->ability()->execute(
			array(
				'page'     => 2,
				'per_page' => 2,
				'orderby'  => 'created',
				'order'    => 'asc',
			)
		);
		$this->assertSame( 2, $result['page'] );
		$this->assertEquals(
			array(
				'page'      => 2,
				'pagesize'  => 2,
				'sort'      => 'created',
				'direction' => 'asc',
			),
			$this->calls[0][2]
		);
	}

	/**
	 * Overwriting pagesize, sort or direction with an alias changes the query.
	 *
	 * @testdox Canonical route parameters take precedence over ability aliases.
	 */
	public function test_canonical_params_win(): void {
		$canonical = array(
			'page'      => 2,
			'pagesize'  => 3,
			'sort'      => 'created',
			'direction' => 'asc',
		);
		$result    = GetAuthorizations::execute(
			$canonical + array(
				'per_page' => 5,
				'orderby'  => 'amount',
				'order'    => 'desc',
			)
		);
		$this->assertEquals( $canonical, $this->calls[0][2] );
		$this->assertSame( 3, $result['per_page'] );
	}

	/**
	 * Dropping the maximum page size reaches the list with 101 items requested.
	 *
	 * @testdox WordPress rejects a page size above 100 before either route runs.
	 */
	public function test_oversized_page(): void {
		$result = $this->ability()->execute( array( 'per_page' => 101 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Counting only the current page returns one instead of three pages.
	 *
	 * @testdox Five records at two per page give three pages, with the current page read before the summary.
	 */
	public function test_total_pages(): void {
		$rows                           = array( array( 'id' => 'first' ), array( 'id' => 'second' ) );
		$this->bodies['authorizations'] = array( 'data' => $rows );
		$result                         = $this->ability()->execute( array( 'per_page' => 2 ) );
		$this->assertSame(
			array(
				array(
					'GET',
					self::API . 'authorizations',
					array(
						'page'     => 1,
						'pagesize' => 2,
					),
				),
				array( 'GET', self::API . 'authorizations/summary', array() ),
			),
			$this->calls
		);
		$this->assertSame( $rows, $result['authorizations'] );
		$this->assertSame( 3, $result['total_pages'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 2, $result['per_page'] );
	}

	/**
	 * Adding a page whenever the count is nonzero overcounts exact multiples of the page size.
	 *
	 * @testdox Four records at two per page give exactly two pages.
	 */
	public function test_exact_multiple_of_page_size(): void {
		$this->bodies['authorizations/summary']['count'] = 4;
		$result = $this->ability()->execute( array( 'per_page' => 2 ) );

		$this->assertSame( 2, $result['total_pages'] );
	}

	/**
	 * A truthiness check treats a genuine zero record count as missing.
	 *
	 * @testdox A summary with zero records gives zero pages.
	 */
	public function test_zero_count(): void {
		$this->bodies['authorizations/summary'] = array(
			'count' => 0,
			'total' => 0,
		);
		$result                                 = $this->ability()->execute();
		$this->assertSame( array(), $result['authorizations'] );
		$this->assertSame( 0, $result['total_pages'] );
	}

	/**
	 * An empty page beyond the end must not reset the total or requested page.
	 *
	 * @testdox A request beyond the last page keeps its page number and the summary page count.
	 */
	public function test_page_beyond_end(): void {
		$result = $this->ability()->execute(
			array(
				'page'     => 4,
				'per_page' => 2,
			)
		);
		$this->assertSame( array(), $result['authorizations'] );
		$this->assertSame( 4, $result['page'] );
		$this->assertSame( 3, $result['total_pages'] );
	}

	/**
	 * Reading a summary after a list error masks the failure or spends another request.
	 *
	 * @testdox A failed list read returns the route error without requesting the summary.
	 */
	public function test_list_failure(): void {
		$this->bodies['authorizations'] = new \WP_Error( 'list_failed', 'List unavailable', array( 'status' => 503 ) );
		$error                          = $this->ability()->execute();
		$this->assertSame( 'list_failed', $error->get_error_code() );
		$this->assertSame( array( self::API . 'authorizations' ), array_column( $this->calls, 1 ) );
	}

	/**
	 * Falling back to one page hides the original summary error and its data.
	 *
	 * @testdox A failed summary read returns the original route error and its data.
	 */
	public function test_summary_failure(): void {
		$data                                   = array(
			'status' => 503,
			'detail' => 'retry',
		);
		$this->bodies['authorizations']         = array( 'data' => array( array( 'id' => 'one' ), array( 'id' => 'two' ) ) );
		$this->bodies['authorizations/summary'] = new \WP_Error( 'summary_failed', 'Summary unavailable', $data );
		$error                                  = $this->ability()->execute( array( 'per_page' => 2 ) );
		$this->assertWPError( $error );
		$this->assertSame( 'summary_failed', $error->get_error_code() );
		$this->assertSame( 'Summary unavailable', $error->get_error_message() );
		$this->assertSame( $data, $error->get_error_data() );
	}

	/**
	 * Casting an unreadable or fractional count to an integer silently guesses the total.
	 *
	 * @testdox Only a non-negative integral record count can supply the total pages.
	 */
	public function test_count_contract(): void {
		foreach ( array( null, -1, 'unknown', 2.5, '1.5', false, array(), '' ) as $count ) {
			$this->bodies['authorizations/summary'] = array( 'total' => 9400 );
			if ( null !== $count ) {
				$this->bodies['authorizations/summary']['count'] = $count;
			}
			$error = $this->ability()->execute();
			$this->assertWPError( $error );
			$this->assertSame( 'woopayments_ability_count_unavailable', $error->get_error_code() );
			$this->assertSame( 502, $error->get_error_data()['status'] );
			$this->assertSame( 'The record count is unavailable.', $error->get_error_message() );
		}
		foreach ( array( 5, '5', 5.0 ) as $count ) {
			$this->bodies['authorizations/summary'] = array( 'count' => $count );
			$this->assertSame( 3, $this->ability()->execute( array( 'per_page' => 2 ) )['total_pages'] );
		}
	}
}
