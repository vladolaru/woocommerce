<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlyFraudWarningOrderService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlyFraudWarningsRestController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the route serving the Overview's payments with an actionable early fraud warning.
 *
 * Expected values come from client 11.3.0 (includes/admin/class-wc-rest-payments-early-fraud-warnings-controller.php and
 * includes/class-wc-payments-order-service.php:1714-1756).
 */
class WooPaymentsEarlyFraudWarningsRestControllerTest extends WC_REST_Unit_Test_Case {

	private const ROUTE = '/wc/v3/payments/early_fraud_warnings/active';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEarlyFraudWarningsRestController
	 */
	private $sut;

	/**
	 * Whether the account reads as test mode.
	 *
	 * @var bool
	 */
	private bool $test_mode = true;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account->method( 'is_test_mode_enabled' )->willReturnCallback( fn(): bool => $this->test_mode );
		$cache = new WooPaymentsDatabaseCache();
		$cache->init( new LegacyProxy() );
		$order_service = new WooPaymentsEarlyFraudWarningOrderService();
		$order_service->init( $account, $cache );
		$this->sut = new WooPaymentsEarlyFraudWarningsRestController();
		$this->sut->init( $account, $cache, $order_service );
		$this->sut->register_routes();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox A store manager gets each order with an actionable warning in the account's mode, with its order number, charge and warning time.
	 */
	public function test_manager_gets_the_order_with_an_actionable_warning(): void {
		$order = $this->create_warning_order( 1792314010 );

		$response = $this->get_active();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'order_id'     => $order->get_id(),
					'order_number' => (string) $order->get_id(),
					'charge_id'    => 'ch_' . $order->get_id(),
					'created'      => 1792314010,
				),
			),
			$response->get_data()
		);
	}

	/**
	 * @testdox A $_dataName user is refused with rest_forbidden and status $status.
	 *
	 * @testWith ["customer", "customer", 403]
	 *           ["logged-out", "", 401]
	 *
	 * @param string $label  Case.
	 * @param string $role   Role, empty for a logged-out request.
	 * @param int    $status Expected status.
	 */
	public function test_user_without_the_store_management_capability_is_refused( string $label, string $role, int $status ): void {
		unset( $label );
		$this->create_warning_order( 1792314010 );
		wp_set_current_user( '' === $role ? 0 : $this->factory->user->create( array( 'role' => $role ) ) );

		$response = $this->get_active();

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * @testdox The route is one of the connected store's controllers and registers through WooCommerce's REST server.
	 */
	public function test_route_registers_with_the_connected_controllers(): void {
		$this->assertContains( WooPaymentsEarlyFraudWarningsRestController::class, WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.

		try {
			wc_get_container()->replace( WooPaymentsEarlyFraudWarningsRestController::class, $this->sut );
			$this->sut->register();
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Starting the REST server.
			do_action( 'rest_api_init', $wp_rest_server );

			$this->assertArrayHasKey( self::ROUTE, $wp_rest_server->get_routes() );
		} finally {
			$wp_rest_server = $this->server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the test's server.
		}
	}

	/**
	 * @testdox The list is ordered by when the warning arrived, newest first, not by when the order was placed.
	 */
	public function test_lists_the_newest_warning_first(): void {
		$older_order_newer_warning = $this->create_warning_order( 2000 );
		$older_order_newer_warning->set_date_created( time() - DAY_IN_SECONDS );
		$older_order_newer_warning->save();
		$newer_order_older_warning = $this->create_warning_order( 1000 );

		$this->assertSame( array( $older_order_newer_warning->get_id(), $newer_order_older_warning->get_id() ), $this->get_listed_ids() );
	}

	/**
	 * @testdox In $_dataName mode, the list leaves out an order of the other mode and reads an order without a mode as live.
	 *
	 * @testWith ["test", true, ["test"]]
	 *           ["live", false, ["prod", "none"]]
	 *
	 * @param string            $label     Case.
	 * @param bool              $test_mode Whether the account reads as test mode.
	 * @param array<int,string> $listed    The order modes listed, newest warning first.
	 */
	public function test_lists_only_the_accounts_mode( string $label, bool $test_mode, array $listed ): void {
		unset( $label );
		$this->test_mode = $test_mode;
		$orders          = array(
			'test' => $this->create_warning_order( 3000, 'test' ),
			'prod' => $this->create_warning_order( 2000, 'prod' ),
			'none' => $this->create_warning_order( 1000, null ),
		);

		$this->assertSame( array_map( static fn( string $mode ): int => $orders[ $mode ]->get_id(), $listed ), $this->get_listed_ids() );
	}

	/**
	 * @testdox The list leaves out a fully refunded order and keeps a partly refunded or zero-total one.
	 */
	public function test_leaves_out_a_fully_refunded_order(): void {
		$full = $this->create_warning_order( 4000 );
		wc_create_refund(
			array(
				'order_id' => $full->get_id(),
				'amount'   => 10,
			)
		);
		$part = $this->create_warning_order( 3000 );
		wc_create_refund(
			array(
				'order_id' => $part->get_id(),
				'amount'   => 4,
			)
		);
		$zero = $this->create_warning_order( 2000, 'test', 0.0 );

		$this->assertSame( array( $part->get_id(), $zero->get_id() ), $this->get_listed_ids() );
	}

	/**
	 * @testdox The list leaves out an order whose index remains beside a warning that is no longer actionable.
	 */
	public function test_leaves_out_a_stale_index(): void {
		$stale = $this->create_warning_order( 2000 );
		$stale->update_meta_data( '_wcpay_early_fraud_warning', array_replace( $stale->get_meta( '_wcpay_early_fraud_warning', true ), array( 'efw_actionable' => false ) ) );
		$stale->save();
		$listed = $this->create_warning_order( 1000 );

		$this->assertSame( array( $listed->get_id() ), $this->get_listed_ids() );
	}

	/**
	 * @testdox The order number is the one WooCommerce shows, as a plugin that numbers orders sets it.
	 */
	public function test_uses_the_order_number_woocommerce_shows(): void {
		$order = $this->create_warning_order( 1000 );
		add_filter( 'woocommerce_order_number', static fn( $number ): string => 'INV-' . $number );

		$this->assertSame( 'INV-' . $order->get_id(), $this->get_active()->get_data()[0]['order_number'] );
	}

	/**
	 * @testdox An order the WooPayments extension 11.3.0 flagged before the switch is listed: the index, the warning and the mode keep its key strings and shape.
	 */
	public function test_lists_an_order_flagged_by_the_woopayments_extension(): void {
		$order = wc_create_order();
		$order->set_total( 25 );
		$order->update_meta_data( '_charge_id', 'ch_extension' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		// As client 11.3.0's set_early_fraud_warning_for_order() writes them (class-wc-payments-order-service.php:1667-1681).
		$order->update_meta_data(
			'_wcpay_early_fraud_warning',
			array(
				'efw_id'         => 'issfr_extension',
				'efw_actionable' => true,
				'efw_type'       => 'made_with_stolen_card',
				'created'        => 1792314010,
			)
		);
		$order->update_meta_data( '_wcpay_early_fraud_warning_actionable', 1792314010 );
		$order->save();

		$this->assertSame(
			array(
				array(
					'order_id'     => $order->get_id(),
					'order_number' => (string) $order->get_id(),
					'charge_id'    => 'ch_extension',
					'created'      => 1792314010,
				),
			),
			$this->get_active()->get_data()
		);
	}

	/**
	 * @testdox Each mode keeps its own cached list, and a second read is served from the cache without the order query.
	 */
	public function test_caches_one_list_per_mode(): void {
		$test_order = $this->create_warning_order( 2000, 'test' );
		$live_order = $this->create_warning_order( 1000, 'prod' );
		$queries    = 0;
		add_filter(
			'woocommerce_order_query_args',
			static function ( $args ) use ( &$queries ) {
				if ( is_array( $args ) && '_wcpay_early_fraud_warning_actionable' === ( $args['meta_key'] ?? null ) ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					++$queries;
				}
				return $args;
			}
		);

		$this->assertSame( array( $test_order->get_id() ), $this->get_listed_ids() );
		$this->create_warning_order( 3000, 'test' );
		$this->assertSame( array( $test_order->get_id() ), $this->get_listed_ids(), 'The second read serves the cached list.' );
		$this->test_mode = false;
		$this->assertSame( array( $live_order->get_id() ), $this->get_listed_ids(), 'Live mode never serves the test list.' );

		$this->assertSame( 2, $queries );
		$this->assertSame( array( $test_order->get_id() ), wp_list_pluck( get_option( 'wcpay_test_early_fraud_warning_orders_cache' )['data'], 'order_id' ) );
		$this->assertSame( array( $live_order->get_id() ), wp_list_pluck( get_option( 'wcpay_early_fraud_warning_orders_cache' )['data'], 'order_id' ) );
	}

	/**
	 * GET the route.
	 *
	 * @return WP_REST_Response
	 */
	private function get_active(): WP_REST_Response {
		return $this->server->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * GET the route and return the listed order IDs.
	 *
	 * @return array<int,int>
	 */
	private function get_listed_ids(): array {
		$response = $this->get_active();
		$this->assertSame( 200, $response->get_status() );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'order_id' ) );
	}

	/**
	 * Create an order holding an actionable warning, as the warning handler stores it.
	 *
	 * @param int         $created Warning time.
	 * @param string|null $mode    Order mode meta, null for an order without one.
	 * @param float       $total   Order total.
	 * @return WC_Order
	 */
	private function create_warning_order( int $created, ?string $mode = 'test', float $total = 10.0 ): WC_Order {
		$order = wc_create_order();
		$order->set_total( $total );
		$order->update_meta_data( '_charge_id', 'ch_' . $order->get_id() );
		if ( null !== $mode ) {
			$order->update_meta_data( '_wcpay_mode', $mode );
		}
		$order->update_meta_data(
			'_wcpay_early_fraud_warning',
			array(
				'efw_id'         => 'issfr_' . $order->get_id(),
				'efw_actionable' => true,
				'efw_type'       => 'made_with_stolen_card',
				'created'        => $created,
			)
		);
		$order->update_meta_data( '_wcpay_early_fraud_warning_actionable', $created );
		$order->save();

		return $order;
	}
}
