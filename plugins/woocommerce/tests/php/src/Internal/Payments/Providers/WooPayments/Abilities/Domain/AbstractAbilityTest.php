<?php
/**
 * Exercise WooPayments abilities through WordPress and an isolated REST server.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\AbstractAbility;
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

	/** Reusing read metadata exposes writes publicly or ignores their two flags. */
	public function test_write_meta(): void {
		$definition = new class() extends AbstractAbility {
			/** Test-only ability name. */
			public static function get_name(): string {
				return 'test/write';
			}

			/** No domain-specific arguments. */
			protected static function get_own_args(): array {
				return array();
			}

			/**
			 * Expose the protected write metadata builder.
			 *
			 * @param bool $destructive Whether the write can destroy data.
			 * @param bool $idempotent  Whether repeating the write has the same effect.
			 * @return array Write metadata.
			 */
			public static function write_meta( bool $destructive, bool $idempotent ): array {
				return parent::get_write_meta( $destructive, $idempotent );
			}
		};
		foreach ( array( false, true ) as $destructive ) {
			foreach ( array( false, true ) as $idempotent ) {
				$expected = array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => $destructive,
						'idempotent'  => $idempotent,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false ),
				);
				$this->assertSame( $expected, $definition::write_meta( $destructive, $idempotent ) );
			}
		}
	}

	/** Requiring manage_options prevents shop managers from executing reads. */
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

	/** Allowing signed-in customers or guests dispatches private reads. */
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

	/** A direct controller call bypasses the backing route permission check. */
	public function test_route_permission(): void {
		$this->route_allowed = false;
		$ability             = $this->ability( 'get-account' );
		$this->assertTrue( $ability->check_permissions() );
		$this->assertSame( 'rest_forbidden', $ability->execute()->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** Routing the loan read to the overview returns the wrong body. */
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

	/** Applying the legacy empty-account guard to loans rejects no active loan. */
	public function test_empty_loan(): void {
		$this->bodies['capital/active_loan_summary'] = array();
		$this->assertSame( array(), $this->ability( 'get-active-loan-summary' )->execute() );
	}

	/** Unwrapping an error response as data loses the REST code, message and status. */
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

	/** A debug-gated provider logger loses REST failures when debug logging is off. */
	public function test_delegation_log(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->expects( $this->once() )->method( 'error' )->with( 'AbilitiesRegistrar delegation failed [GET /wc/v3/payments/accounts]: Unavailable (account_error)', array( 'source' => 'woopayments-abilities' ) );
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->bodies['accounts'] = new \WP_Error( 'account_error', 'Unavailable', array( 'status' => 503 ) );
		$this->assertWPError( $this->ability( 'get-account' )->execute() );
	}

	/** Returning scalar response data violates the delegate's array contract. */
	public function test_scalar_body(): void {
		add_filter( 'rest_pre_dispatch', static fn() => new \WP_REST_Response( 'scalar' ) );
		$this->assertSame( array(), $this->ability( 'get-account' )->execute() );
	}

	/** A default page size of ten changes both the request and the returned page. */
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

	/** Leaving order in the request drops the canonical direction parameter. */
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

	/** Overwriting pagesize, sort or direction with an alias changes the query. */
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

	/** Dropping the maximum page size reaches the list with 101 items requested. */
	public function test_oversized_page(): void {
		$result = $this->ability()->execute( array( 'per_page' => 101 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** Counting only the current page returns one instead of three pages. */
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

	/** A truthiness check treats a genuine zero record count as missing. */
	public function test_zero_count(): void {
		$this->bodies['authorizations/summary'] = array(
			'count' => 0,
			'total' => 0,
		);
		$result                                 = $this->ability()->execute();
		$this->assertSame( array(), $result['authorizations'] );
		$this->assertSame( 0, $result['total_pages'] );
	}

	/** An empty page beyond the end must not reset the total or requested page. */
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

	/** Reading a summary after a list error masks the failure or spends another request. */
	public function test_list_failure(): void {
		$this->bodies['authorizations'] = new \WP_Error( 'list_failed', 'List unavailable', array( 'status' => 503 ) );
		$error                          = $this->ability()->execute();
		$this->assertSame( 'list_failed', $error->get_error_code() );
		$this->assertSame( array( self::API . 'authorizations' ), array_column( $this->calls, 1 ) );
	}

	/** Falling back to one page hides the original summary error and its data. */
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

	/** Casting an unreadable or fractional count to an integer silently guesses the total. */
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
			$this->assertNotEmpty( $error->get_error_message() );
		}
		foreach ( array( 5, '5', 5.0 ) as $count ) {
			$this->bodies['authorizations/summary'] = array( 'count' => $count );
			$this->assertSame( 3, $this->ability()->execute( array( 'per_page' => 2 ) )['total_pages'] );
		}
	}
}
