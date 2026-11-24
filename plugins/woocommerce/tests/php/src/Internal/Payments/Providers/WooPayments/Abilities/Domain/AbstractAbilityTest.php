<?php
/**
 * Exercise WooPayments abilities through WordPress and an isolated REST server.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;

/** Exercise the callbacks with no network or payment dependencies. */
class AbstractAbilityTest extends AbilityTestCase {
	private const API    = '/wc/v3/payments/';
	private const ROUTES = array(
		'get-disputes'               => 'disputes',
		'get-disputes-summary'       => 'disputes/summary',
		'get-deposits'               => 'deposits',
		'get-deposits-summary'       => 'deposits/summary',
		'get-dispute'                => 'disputes/future_case',
		'get-charge'                 => 'charges/ch_sample',
		'get-payment-intent'         => 'payment_intents/pi_detail',
		'get-timeline'               => 'timeline/pi_history',
		'get-transactions'           => 'transactions',
		'get-transactions-summary'   => 'transactions/summary',
		'get-fraud-outcomes'         => 'transactions/fraud-outcomes',
		'get-fees-summary'           => 'reports/fees/summary',
		'get-balance'                => 'reports/balance',
		'get-account'                => 'accounts',
		'get-deposits-overview'      => 'deposits/overview-all',
		'get-active-loan-summary'    => 'capital/active_loan_summary',
		'get-authorizations'         => 'authorizations',
		'get-authorizations-summary' => 'authorizations/summary',
	);

	private const COLLECTION_KEYS = array(
		'get-disputes'       => 'disputes',
		'get-deposits'       => 'deposits',
		'get-authorizations' => 'authorizations',
		'get-transactions'   => 'transactions',
		'get-fraud-outcomes' => 'fraud_outcomes',
	);
	private const BALANCE         = array(
		'date_start' => '2026-10-01T00:00:00Z',
		'date_end'   => '2026-10-31T23:59:59Z',
		'currency'   => 'USD',
	);
	private const PAYOUT_FILTERS  = array(
		'match'             => 'all',
		'store_currency_is' => 'usd',
		'date_before'       => '2026-10-31',
		'date_after'        => '2026-10-01',
		'date_between'      => array( '2026-10-01', '2026-10-31' ),
		'status_is'         => 'paid',
		'status_is_not'     => 'failed',
	);
	private const DISPUTE_FILTERS = array(
		'status_is'     => 'needs_response',
		'status_is_not' => 'won',
		'search'        => array( 'case', 'sample' ),
	) + self::PAYOUT_FILTERS;

	private const ID_INPUTS = array(
		'get-dispute'        => array( 'dispute_id' => 'future_case' ),
		'get-charge'         => array( 'charge_id' => 'ch_sample' ),
		'get-payment-intent' => array( 'payment_intent_id' => 'pi_detail' ),
		'get-timeline'       => array( 'intention_id' => 'pi_history' ),
	);

	private const INPUTS = array(
		'get-disputes-summary'     => self::DISPUTE_FILTERS,
		'get-deposits-summary'     => self::PAYOUT_FILTERS,
		'get-balance'              => self::BALANCE,
		'get-fraud-outcomes'       => array( 'status' => 'block' ),
		'get-transactions-summary' => array(
			'date_after' => '2026-10-01',
			'search'     => array( 'sample' ),
			'deposit_id' => 'po_sample',
		),
		'get-fees-summary'         => array(
			'date_before'         => '2026-10-31',
			'date_after'          => '2026-10-01',
			'date_between'        => array( '2026-10-01', '2026-10-31' ),
			'match'               => 'all',
			'deposit_id'          => 'po_sample',
			'payment_method_type' => 'card',
		),
	) + self::ID_INPUTS;

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
	 * URL parameters captured by the last route.
	 *
	 * @var array
	 */
	private $captures;
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
		// The container keeps the tier instance across tests; drop a tier an earlier test read so the registrar reads the tier just stored.
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		add_filter( self::GATE, '__return_true' );
		$this->original_server     = $GLOBALS['wp_rest_server'] ?? null;
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		foreach ( array_merge( self::ROUTES, array( 'transactions/fraud-outcomes/summary' ) ) as $slug => $suffix ) {
			$route = $suffix;
			if ( isset( self::ID_INPUTS[ $slug ] ) ) {
				$route = dirname( $suffix ) . '/(?P<' . key( self::ID_INPUTS[ $slug ] ) . '>\w+)';
			}
			$this->bodies[ $suffix ] = array( 'source' => $suffix );
			$server->register_route(
				'wc/v3',
				self::API . $route,
				array(
					array(
						'methods'             => 'GET',
						'permission_callback' => fn() => $this->route_allowed,
						'callback'            => function ( $request ) use ( $suffix ) {
							$this->calls[] = array( $request->get_method(), $request->get_route(), $request->get_query_params() );
							$this->captures = $request->get_url_params();
							if ( 'transactions/fraud-outcomes/summary' === $suffix && ! $request->has_param( 'status' ) ) {
								return new \WP_Error( 'missing_status', 'Missing status', array( 'status' => 400 ) );
							}
							if ( in_array( $suffix, array( 'transactions/summary', 'disputes/summary', 'deposits/summary' ), true ) && ! $request->get_params() && isset( $this->bodies['unfiltered'] ) ) {
								return $this->bodies['unfiltered'];
							}
							return $this->bodies[ $suffix ];
						},
					),
				)
			);
		}
		foreach ( self::COLLECTION_KEYS as $slug => $key ) {
			$this->bodies[ self::ROUTES[ $slug ] ] = array( 'data' => array() );
		}
		$this->bodies['deposits']['total_count']             = 0;
		$this->bodies['disputes/summary']                    = array(
			'count'          => 5,
			'needs_response' => 2,
		);
		$this->bodies['deposits/summary']                    = array(
			'count'   => 5,
			'amounts' => array( 'usd' => 300 ),
		);
		$this->bodies['disputes/future_case']                = array(
			'order'  => 7,
			'charge' => 'ch_case',
		);
		$this->bodies['charges/ch_sample']                   = array(
			'order' => 8,
			'id'    => 'ch_sample',
		);
		$this->bodies['payment_intents/pi_detail']           = array(
			'charges' => array( array( 'order' => 9 ) ),
			'status'  => 'succeeded',
		);
		$this->bodies['timeline/pi_history']                 = array( array( 'type' => 'created' ), array( 'type' => 'manual_review' ), array( 'type' => 'succeeded' ) );
		$this->bodies['authorizations/summary']              = array(
			'count' => 5,
			'total' => 9400,
		);
		$this->bodies['reports/fees/summary']                = array(
			'count' => 2,
			'fees'  => 50,
			'net'   => 450,
		);
		$this->bodies['transactions/summary']                = array(
			'count' => 5,
			'total' => 9400,
		);
		$this->bodies['transactions/fraud-outcomes/summary'] = array(
			'count' => 7,
			'total' => 19000,
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
	 * @testdox Requiring site administration privileges keeps shop managers from reading payment records.
	 */
	public function test_manager_permissions(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			foreach ( self::ROUTES as $name => $suffix ) {
				$this->calls = array();
				$ability     = $this->ability( $name );
				$this->assertTrue( $ability->check_permissions( self::INPUTS[ $name ] ?? array() ) );
				$this->assertIsArray( $ability->execute( self::INPUTS[ $name ] ?? array() ) );
				$this->assertSame( self::API . $suffix, $this->calls[0][1] );
			}
		}
	}

	/**
	 * @testdox Letting a customer or guest read payment records sends a private request to the route.
	 * @testWith ["customer"]
	 *           ["guest"]
	 *
	 * @param string $role Caller role.
	 */
	public function test_customer_and_guest( string $role ): void {
		wp_set_current_user( 'guest' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
		foreach ( self::ROUTES as $name => $suffix ) {
			$ability = $this->ability( $name );
			$this->assertFalse( $ability->check_permissions( self::INPUTS[ $name ] ?? array() ) );
			$this->assertSame( 'ability_invalid_permissions', $ability->execute( self::INPUTS[ $name ] ?? array() )->get_error_code() );
		}
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @testdox Calling a read handler directly skips its own permission check.
	 * @testWith ["get-fees-summary"]
	 *           ["get-disputes"]
	 *           ["get-charge"]
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_route_permission( string $slug ): void {
		$this->route_allowed = false;
		$ability             = $this->ability( $slug );
		$input               = self::INPUTS[ $slug ] ?? array();
		$this->assertTrue( $ability->check_permissions( $input ) );
		$error = $ability->execute( $input );
		$this->assertWPError( $error );
		$this->assertSame( 'rest_forbidden', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Rejecting unfamiliar IDs or reading a charge from its order misses the requested record.
	 * Trimming nested intent fields or removing a review event loses details supplied by the route.
	 *
	 * @testdox Using the payout overview, sending an ID in the query or returning just a summary count changes the response.
	 * @dataProvider single_reads
	 *
	 * @param string $name   Ability slug.
	 * @param string $suffix Expected route.
	 */
	public function test_single_routes( string $name, string $suffix ): void {
		$input = self::INPUTS[ $name ] ?? array();
		$this->assertSame( $this->bodies[ $suffix ], $this->ability( $name )->execute( $input ) );
		if ( isset( self::ID_INPUTS[ $name ] ) ) {
			$this->assertSame( $input, $this->captures );
			$input = array();
		}
		$this->assertSame( array( array( 'GET', self::API . $suffix, $input ) ), $this->calls );
	}

	/** Route pairs for abilities that return a single response. */
	public static function single_reads(): iterable {
		foreach ( array_diff_key( self::ROUTES, self::COLLECTION_KEYS ) as $name => $suffix ) {
			yield $name => array( $name, $suffix );
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
	 * Leaving order unmapped loses direction. Forwarding sort changes the authorization summary query.
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
		$this->assertSame( array( 'GET', self::API . 'authorizations/summary', array() ), $this->calls[1] );
		$this->assertSame( 3, $result['total_pages'] );
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
		$result    = Domain\GetAuthorizations::execute(
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
	 * Set the filtered record count returned by the companion summary.
	 *
	 * @since 11.4.0
	 *
	 * @param string $route List route suffix.
	 * @param mixed  $count Count value, or null for an omitted count.
	 */
	private function set_count( string $route, $count ): void {
		$key = $route . '/summary';
		unset( $this->bodies[ $key ]['count'] );
		if ( null !== $count ) {
			$this->bodies[ $key ]['count'] = $count;
		}
	}

	/**
	 * A truthiness check treats a genuine zero record count as missing.
	 *
	 * @testdox Treating a zero count as missing discards a valid empty collection.
	 * @dataProvider collection_abilities
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_zero_count( string $slug ): void {
		$route = self::ROUTES[ $slug ];
		$input = self::INPUTS[ $slug ] ?? array();
		$this->set_count( $route, 0 );
		$result = $this->ability( $slug )->execute( $input );
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result[ self::COLLECTION_KEYS[ $slug ] ] );
		$this->assertSame( 0, $result['total_pages'] );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * An empty page beyond the end must not reset the total or requested page.
	 *
	 * @testdox Counting the empty slice loses the total on a later page.
	 * @dataProvider collection_abilities
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_page_beyond_end( string $slug ): void {
		$route = self::ROUTES[ $slug ];
		$input = self::INPUTS[ $slug ] ?? array();
		$this->set_count( $route, 5 );
		$result = $this->ability( $slug )->execute(
			$input + array(
				'page'     => 4,
				'per_page' => 2,
			)
		);
		$this->assertSame( array(), $result[ self::COLLECTION_KEYS[ $slug ] ] );
		$this->assertSame( 4, $result['page'] );
		$this->assertSame( 3, $result['total_pages'] );
	}

	/**
	 * @testdox Reading the summary after a failed list adds a request and can hide the original route error.
	 * @dataProvider collection_abilities
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_list_failure( string $slug ): void {
		$route                  = self::ROUTES[ $slug ];
		$input                  = self::INPUTS[ $slug ] ?? array();
		$this->bodies[ $route ] = new \WP_Error( 'list_failed', 'List unavailable', array( 'status' => 503 ) );
		$error                  = $this->ability( $slug )->execute( $input );
		$this->assertSame( 'List unavailable', $error->get_error_message() );
		$this->assertSame( array( 'status' => 503 ), $error->get_error_data() );
		$this->assertSame( 'list_failed', $error->get_error_code() );
		$this->assertSame( array( self::API . $route ), array_column( $this->calls, 1 ) );
	}

	/**
	 * @testdox Replacing a summary failure with a count error loses the original message and details.
	 * @dataProvider collection_abilities
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_summary_failure( string $slug ): void {
		$route                               = self::ROUTES[ $slug ];
		$input                               = self::INPUTS[ $slug ] ?? array();
		$data                                = array(
			'status' => 503,
			'detail' => 'retry',
		);
		$this->bodies[ $route ]              = array( 'data' => array( array( 'id' => 'one' ), array( 'id' => 'two' ) ) );
		$this->bodies[ $route . '/summary' ] = new \WP_Error( 'summary_failed', 'Summary unavailable', $data );
		$error                               = $this->ability( $slug )->execute( $input + array( 'per_page' => 2 ) );
		$this->assertWPError( $error );
		$this->assertSame( 'summary_failed', $error->get_error_code() );
		$this->assertSame( 'Summary unavailable', $error->get_error_message() );
		$this->assertSame( $data, $error->get_error_data() );
	}

	/**
	 * @testdox Casting an unreadable record count to an integer silently guesses the number of pages.
	 * @dataProvider collection_abilities
	 *
	 * @param string $slug Ability slug.
	 */
	public function test_count_contract( string $slug ): void {
		$route = self::ROUTES[ $slug ];
		$input = self::INPUTS[ $slug ] ?? array();
		foreach ( array( null, -1, (string) PHP_INT_MAX . '0', 'unknown', 2.5, '1.5', false, array(), '' ) as $count ) {
			$this->calls = array();
			$this->set_count( $route, $count );
			$error = $this->ability( $slug )->execute( $input );
			$this->assertWPError( $error );
			$this->assertCount( 2, $this->calls );
			$this->assertSame( 'woopayments_ability_count_unavailable', $error->get_error_code() );
			$this->assertSame( 502, $error->get_error_data()['status'] );
			$this->assertSame( 'The record count is unavailable.', $error->get_error_message() );
		}
		foreach ( array( 5, '5', 5.0 ) as $count ) {
			$this->set_count( $route, $count );
			$this->assertSame( 3, $this->ability( $slug )->execute( $input + array( 'per_page' => 2 ) )['total_pages'] );
		}
	}

	/** All paginated reads. */
	public static function collection_abilities(): iterable {
		foreach ( self::COLLECTION_KEYS as $slug => $key ) {
			yield $key => array( $slug );
		}
	}

	/**
	 * Counting the returned slice, dropping selectors, or sending list ordering to the summary changes the page count.
	 *
	 * @testdox Transaction pages use the full filtered count and keep enriched items in order.
	 * @testWith [1]
	 *           [2]
	 *
	 * @param int $page Requested page.
	 */
	public function test_filtered_transaction_pages( int $page ): void {
		$selectors                    = array(
			'match'                => 'all',
			'date_after'           => '2026-10-01',
			'date_before'          => '2026-10-31',
			'date_between'         => array( '2026-10-01', '2026-10-31' ),
			'type_is'              => 'charge',
			'source_device_is'     => 'mobile',
			'channel_is'           => 'online',
			'customer_country_is'  => 'US',
			'risk_level_is'        => 'normal',
			'store_currency_is'    => 'usd',
			'customer_currency_is' => 'eur',
			'search'               => array( 'sample' ),
			'deposit_id'           => 'po_sample',
		);
		$rows                         = array(
			array( 'order' => array( 'number' => '102' ) ),
			array( 'order' => array( 'number' => '101' ) ),
		);
		$this->bodies['transactions'] = array( 'data' => $rows );
		$this->bodies['unfiltered']   = array( 'count' => 40 );
		$result                       = $this->ability( 'get-transactions' )->execute(
			$selectors + array(
				'page'     => $page,
				'per_page' => 2,
				'orderby'  => 'date',
				'order'    => 'asc',
			)
		);
		$list_params                  = $selectors + array(
			'page'      => $page,
			'pagesize'  => 2,
			'sort'      => 'date',
			'direction' => 'asc',
		);
		$this->assertSame(
			array(
				array( 'GET', self::API . 'transactions', $list_params ),
				array( 'GET', self::API . 'transactions/summary', $selectors ),
			),
			$this->calls
		);
		$this->assertSame( $rows, $result['transactions'] );
		$this->assertSame( $page, $result['page'] );
		$this->assertSame( 2, $result['per_page'] );
		$this->assertSame( 3, $result['total_pages'] );
	}

	/**
	 * Counting the slice, using the ordinary summary or dropping the required status loses the matching fraud count.
	 *
	 * @testdox Fraud pages count all matching outcomes through the status-specific summary.
	 * @testWith ["block"]
	 *           ["review"]
	 *
	 * @param string $status Fraud outcome.
	 */
	public function test_filtered_fraud_pages( string $status ): void {
		$selectors                                   = array(
			'status'      => $status,
			'search'      => array( 'sample' ),
			'search_term' => 'sam',
		);
		$rows                                        = array( array( 'id' => 'first' ), array( 'id' => 'second' ) );
		$this->bodies['transactions/fraud-outcomes'] = array( 'data' => $rows );
		$result                                      = $this->ability( 'get-fraud-outcomes' )->execute( $selectors + array( 'per_page' => 2 ) );
		$this->assertIsArray( $result );
		$this->assertSame( $rows, $result['fraud_outcomes'] );
		$this->assertSame( 4, $result['total_pages'] );
		$this->assertSame(
			array(
				array(
					'GET',
					self::API . 'transactions/fraud-outcomes',
					$selectors + array(
						'page'     => 1,
						'pagesize' => 2,
					),
				),
				array( 'GET', self::API . 'transactions/fraud-outcomes/summary', $selectors ),
			),
			$this->calls
		);
	}

	/**
	 * Making fraud status optional or removing its enum allows invalid inputs through to REST.
	 *
	 * @testdox Missing, unknown and empty fraud statuses fail before dispatch.
	 * @testWith [null]
	 *           ["unknown"]
	 *           [""]
	 *
	 * @param string|null $status Fraud outcome, or null to omit it.
	 */
	public function test_invalid_fraud_input( ?string $status ): void {
		$error = $this->ability( 'get-fraud-outcomes' )->execute( null === $status ? array() : array( 'status' => $status ) );
		$this->assertWPError( $error );
		$this->assertSame( 'ability_invalid_input', $error->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * Relying on required alone allows empty balance strings through to REST.
	 *
	 * @testdox Each empty balance input receives its own missing-field error.
	 * @testWith ["date_start"]
	 *           ["date_end"]
	 *           ["currency"]
	 *
	 * @param string $field Empty balance input.
	 */
	public function test_empty_balance_field( string $field ): void {
		$error = $this->ability( 'get-balance' )->execute( array( $field => '' ) + self::BALANCE );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpay_missing_' . $field, $error->get_error_code() );
		$this->assertSame( "A $field is required to read the balance summary.", $error->get_error_message() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @testdox Making currency optional lets a balance request reach the callback without a currency.
	 */
	public function test_missing_balance_currency(): void {
		$input = self::BALANCE;
		unset( $input['currency'] );
		$error = $this->ability( 'get-balance' )->execute( $input );
		$this->assertWPError( $error );
		$this->assertSame( 'ability_invalid_input', $error->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @testdox Discarding a schema-valid object issues an unfiltered summary read.
	 */
	public function test_object_keeps_payout_filter(): void {
		$input = array( 'deposit_id' => 'po_sample' );
		$this->assertSame( $this->bodies['transactions/summary'], $this->ability( 'get-transactions-summary' )->execute( (object) $input ) );
		$this->assertSame( array( array( 'GET', self::API . 'transactions/summary', $input ) ), $this->calls );
	}

	/**
	 * Sending list ordering to a summary can change its count. Dropping order details loses enriched rows.
	 * Naming the payout collection payouts changes the response keys.
	 *
	 * @testdox Counting the current slice, dropping a selector or choosing the wrong count changes the filtered page.
	 * @testWith ["disputes"]
	 *           ["deposits"]
	 *
	 * @param string $route List route.
	 *
	 * @since 11.4.0
	 */
	public function test_filtered_pages( string $route ): void {
		$filters                    = 'disputes' === $route ? self::DISPUTE_FILTERS : self::PAYOUT_FILTERS;
		$rows                       = array( array( 'order' => array( 'number' => 12 ) ), array( 'order' => array( 'number' => 10 ) ) );
		$this->bodies[ $route ]     = array(
			'data'        => $rows,
			'total_count' => 'disputes' === $route ? 80 : count( $rows ),
		);
		$this->bodies['unfiltered'] = array( 'count' => 40 );

		$result         = $this->ability( 'get-' . $route )->execute(
			$filters + array(
				'page'     => 2,
				'per_page' => 2,
				'orderby'  => 'date',
				'order'    => 'asc',
			)
		);
		$expected_calls = array(
			array(
				'GET',
				self::API . $route,
				$filters + array(
					'page'      => 2,
					'pagesize'  => 2,
					'sort'      => 'date',
					'direction' => 'asc',
				),
			),
			array( 'GET', self::API . $route . '/summary', $filters ),
		);
		$this->assertSame( $expected_calls, $this->calls );
		$this->assertSame(
			array(
				$route        => $rows,
				'total_pages' => 3,
				'page'        => 2,
				'per_page'    => 2,
			),
			$result
		);
	}

	/**
	 * @testdox Taking the page count from the payout list's per-page total reports one page for forty matching payouts.
	 *
	 * @since 11.4.0
	 */
	public function test_payout_list_counts_only_the_current_page(): void {
		$filters                  = array( 'status_is' => 'paid' );
		$this->bodies['deposits'] = array(
			'data'        => array( array( 'id' => 'po_sample' ) ),
			'total_count' => 1,
		);
		$this->set_count( 'deposits', 40 );

		$result = $this->ability( 'get-deposits' )->execute( $filters + array( 'per_page' => 1 ) );

		$this->assertSame( 40, $result['total_pages'] );
		$this->assertSame( array( self::API . 'deposits', self::API . 'deposits/summary' ), array_column( $this->calls, 1 ) );
		$this->assertSame( array( 'GET', self::API . 'deposits/summary', $filters ), $this->calls[1] );
	}

	/**
	 * @testdox Adding a page for an exact multiple turns four payouts at two per page into three pages.
	 *
	 * @since 11.4.0
	 */
	public function test_payout_exact_multiple(): void {
		$this->set_count( 'deposits', 4 );
		$this->assertSame( 2, $this->ability( 'get-deposits' )->execute( array( 'per_page' => 2 ) )['total_pages'] );
	}

	/**
	 * Detail reads and their direct PHP callbacks.
	 *
	 * @since 11.4.0
	 */
	public static function detail_reads(): array {
		return array(
			'dispute sends an empty ID'          => array( 'get-dispute', Domain\GetDispute::class ),
			'charge sends string zero'           => array( 'get-charge', Domain\GetCharge::class ),
			'intent turns an integer into an ID' => array( 'get-payment-intent', Domain\GetPaymentIntent::class ),
			'timeline accepts an absent ID'      => array( 'get-timeline', Domain\GetTimeline::class ),
		);
	}

	/**
	 * @testdox An optional ID reaches the callback, while accepting an empty string, string zero or integer sends an invalid lookup.
	 * @dataProvider detail_reads
	 *
	 * @param string $slug       Ability slug.
	 * @param string $definition Concrete definition.
	 *
	 * @since 11.4.0
	 */
	public function test_missing_detail_id( string $slug, string $definition ): void {
		$ability = $this->ability( $slug );
		$field   = key( self::ID_INPUTS[ $slug ] );
		$this->assertSame( 'ability_invalid_input', $ability->execute( array() )->get_error_code() );
		foreach ( array( '', '0', 12 ) as $value ) {
			$input = array( $field => $value );
			$error = is_int( $value ) ? $definition::execute( $input ) : $ability->execute( $input );
			$this->assertWPError( $error );
			$this->assertSame( 'wcpay_missing_' . $field, $error->get_error_code() );
			$this->assertSame( "A non-empty `$field` is required.", $error->get_error_message() );
		}
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @testdox Sharing the detail field with the timeline sends the wrong input to its route.
	 *
	 * @since 11.4.0
	 */
	public function test_timeline_field(): void {
		$ability = $this->ability( 'get-timeline' );
		$error   = $ability->execute( array( 'payment_intent_id' => 'pi_history' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'ability_invalid_input', $error->get_error_code() );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( $this->bodies['timeline/pi_history'], $ability->execute( self::ID_INPUTS['get-timeline'] ) );
	}

	/**
	 * @testdox Appending the raw ID or encoding it twice changes the request path before matching.
	 *
	 * @since 11.4.0
	 */
	public function test_id_encoding(): void {
		$paths = array();
		add_filter(
			'rest_pre_dispatch',
			static function ( $result, $server, $request ) use ( &$paths ) {
				$paths[] = $request->get_route();
				return $result;
			},
			10,
			3
		);
		$error = $this->ability( 'get-charge' )->execute( array( 'charge_id' => 'two words' ) );
		$this->assertSame( array( self::API . 'charges/two%20words' ), $paths );
		$this->assertSame( 'rest_no_route', $error->get_error_code() );
	}

	/**
	 * @testdox A reserved route word is read as a dispute ID when the detail lookup accepts every string.
	 * @testWith ["summary"]
	 *           ["SuMmArY"]
	 *           ["two/segments"]
	 *           ["two words"]
	 *
	 * @param string $id Value outside the dispute detail route.
	 */
	public function test_invalid_dispute_id( string $id ): void {
		add_filter( 'rest_pre_dispatch', static fn() => new \WP_Error( 'unexpected_dispatch', 'An invalid dispute ID reached REST.' ) );
		$error = $this->ability( 'get-dispute' )->execute( array( 'dispute_id' => $id ) );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpay_invalid_dispute_id', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] ?? null, 'A caller input error must answer 400, not the 500 a missing status becomes.' );
	}

	/**
	 * @testdox Treating an unknown dispute as a missing ID loses the route error and its status.
	 *
	 * @since 11.4.0
	 */
	public function test_unknown_dispute(): void {
		$data                                 = array(
			'status' => 404,
			'detail' => 'lookup',
		);
		$this->bodies['disputes/future_case'] = new \WP_Error( 'unknown_case', 'Unknown case', $data );
		$error                                = $this->ability( 'get-dispute' )->execute( self::ID_INPUTS['get-dispute'] );
		$this->assertSame( 'unknown_case', $error->get_error_code() );
		$this->assertSame( 'Unknown case', $error->get_error_message() );
		$this->assertSame( $data, $error->get_error_data() );
	}

	/**
	 * @testdox Discarding PHP object input loses the payment intent ID or makes a filtered dispute summary unfiltered.
	 *
	 * @since 11.4.0
	 */
	public function test_detail_and_summary_object_inputs(): void {
		$input = self::ID_INPUTS['get-payment-intent'];
		$this->assertSame( $this->bodies['payment_intents/pi_detail'], $this->ability( 'get-payment-intent' )->execute( (object) $input ) );
		$this->assertSame( $input, $this->captures );
		$filter = array( 'status_is' => 'lost' );
		$this->assertSame( $this->bodies['disputes/summary'], $this->ability( 'get-disputes-summary' )->execute( (object) $filter ) );
		$this->assertSame( array( 'GET', self::API . 'disputes/summary', $filter ), $this->calls[1] );
	}
}
