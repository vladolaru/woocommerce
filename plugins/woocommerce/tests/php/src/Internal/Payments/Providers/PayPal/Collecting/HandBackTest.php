<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for what the shell and the collecting state do when the store changes hands: the reconcile after a hand-back
 * from the extension, and the platform apps' cached tokens when the store leaves the platform.
 *
 * @group paypal-wallet
 */
class HandBackTest extends WalletTestCase {

	/**
	 * The platform apps' token and token rate-limit transients.
	 */
	private const TOKEN_TRANSIENTS = array(
		'wc_paypal_wallet_bearer_platform_ppcp-bearer',
		'wc_paypal_wallet_bearer_merchant_app_ppcp-bearer',
		'wc_paypal_wallet_rate_platform_bearer-circuit-state',
		'wc_paypal_wallet_rate_merchant_app_bearer-circuit-state',
	);

	/**
	 * A shell whose arbiter reports an owner.
	 *
	 * @param string $owner The runtime owner.
	 * @return PayPalWalletBootstrap
	 */
	private function bootstrap( string $owner ): PayPalWalletBootstrap {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'get_runtime_owner' ) )
			->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );

		$sut = new PayPalWalletBootstrap();
		$sut->init( $arbiter );

		return $sut;
	}

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'TRACK-OURS',
				'environment' => 'sandbox',
				'payee_bound' => true,
			)
		);
	}

	/**
	 * Store a token for each app.
	 */
	private function set_tokens(): void {
		foreach ( self::TOKEN_TRANSIENTS as $name ) {
			$this->set_wallet_transient( $name, 'cached' );
		}
	}

	/**
	 * The token transients still stored.
	 *
	 * @return string[]
	 */
	private function stored_tokens(): array {
		return array_values(
			array_filter(
				self::TOKEN_TRANSIENTS,
				static function ( string $name ): bool {
					return false !== get_transient( $name );
				}
			)
		);
	}

	/**
	 * Remove every queued run of the reconcile, so no action is left for the next test.
	 */
	public function tearDown(): void {
		as_unschedule_all_actions( Reconciler::HOOK, null, Reconciler::GROUP );
		parent::tearDown();
	}

	/**
	 * Whether a hand-back reconcile is queued.
	 *
	 * @return bool
	 */
	private function reconcile_queued(): bool {
		return as_has_scheduled_action( Reconciler::HOOK, Reconciler::HAND_BACK_ARGS, Reconciler::GROUP );
	}

	/**
	 * The IDs of the queued runs of the reconcile that are waiting.
	 *
	 * @param array|null $args Only the runs queued with these arguments, or every run when null.
	 * @return int[]
	 */
	private function pending_reconciles( ?array $args = null ): array {
		$query = array(
			'hook'   => Reconciler::HOOK,
			'group'  => Reconciler::GROUP,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		);
		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array_map( 'intval', (array) as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * @testdox Should queue one reconcile on the first request native owns after the extension did, for a store the platform serves.
	 */
	public function test_hand_back_queues_a_reconcile(): void {
		$this->set_collecting();
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_EXTENSION );

		$this->assertTrue( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner() );

		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION ) );
		$this->assertTrue( $this->reconcile_queued() );
		$this->assertFalse( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner(), 'The next request is not a hand-back' );
	}

	/**
	 * @testdox Should queue the hand-back reconcile next to the daily recurring one, which must not suppress it.
	 */
	public function test_hand_back_queues_a_reconcile_beside_the_daily_one(): void {
		$this->set_collecting();
		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, Reconciler::HOOK, array(), Reconciler::GROUP );
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_EXTENSION );

		$this->assertTrue( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner() );

		$this->assertCount( 1, $this->pending_reconciles( Reconciler::HAND_BACK_ARGS ), 'Exactly one hand-back run is queued' );
		$this->assertCount( 1, $this->pending_reconciles( array() ), 'The daily action is left alone' );
		$this->assertCount( 2, $this->pending_reconciles() );
	}

	/**
	 * @testdox Should queue only one hand-back reconcile when the store is handed back twice before the first one runs.
	 */
	public function test_second_hand_back_queues_no_second_reconcile(): void {
		$this->set_collecting();
		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, Reconciler::HOOK, array(), Reconciler::GROUP );

		foreach ( array( 1, 2 ) as $round ) {
			$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_EXTENSION );
			$this->assertTrue( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner(), "Hand-back $round" );
		}

		$this->assertCount( 1, $this->pending_reconciles( Reconciler::HAND_BACK_ARGS ) );
		$this->assertCount( 2, $this->pending_reconciles() );
	}

	/**
	 * @testdox Should leave a queued continuation alone and still queue the hand-back reconcile, and the reverse.
	 */
	public function test_hand_back_run_and_continuation_do_not_collide(): void {
		$this->set_collecting();
		as_enqueue_async_action( Reconciler::HOOK, array( 25, true ), Reconciler::GROUP );
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_EXTENSION );

		$this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner();

		$this->assertCount( 1, $this->pending_reconciles( Reconciler::HAND_BACK_ARGS ) );
		$this->assertCount( 1, $this->pending_reconciles( array( 25, true ) ) );
		$this->assertNotSame( Reconciler::HAND_BACK_ARGS, array( 0, true ), 'A continuation is never read as the hand-back run' );
	}

	/**
	 * @testdox Should queue nothing when the owner did not change from the extension to native, or the platform does not serve the store.
	 * @testWith ["native", "native", true]
	 *           ["none", "native", true]
	 *           ["native", "extension", true]
	 *           ["extension", "native", false]
	 *
	 * @param string $last       The last owner.
	 * @param string $owner      The owner now.
	 * @param bool   $collecting Whether the store collects.
	 */
	public function test_no_reconcile_without_a_hand_back( string $last, string $owner, bool $collecting ): void {
		if ( $collecting ) {
			$this->set_collecting();
		}
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, $last );

		$this->assertFalse( $this->bootstrap( $owner )->track_runtime_owner() );

		$this->assertFalse( $this->reconcile_queued() );
		$expected = $collecting ? $owner : $last;
		$this->assertSame( $expected, get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION ), 'The owner is recorded only where the platform serves the store' );
	}

	/**
	 * @testdox Should record the first owner it sees without queuing a reconcile.
	 */
	public function test_first_owner_is_recorded(): void {
		$this->set_collecting();
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, 'claimed' );
		delete_option( PayPalWalletBootstrap::LAST_OWNER_OPTION );

		$this->assertFalse( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner() );

		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION ) );
		$this->assertFalse( $this->reconcile_queued() );
	}

	/**
	 * @testdox Should read and write no owner row on a store the platform does not serve.
	 */
	public function test_tracks_nothing_without_a_platform_state(): void {
		wp_load_alloptions();
		wp_cache_delete( 'notoptions', 'options' );
		$queries = array();
		$record  = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $record );
		try {
			$this->assertFalse( $this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_NATIVE )->track_runtime_owner() );
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertFalse( get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, false ) );
		foreach ( $queries as $sql ) {
			$this->assertStringNotContainsString( PayPalWalletBootstrap::LAST_OWNER_OPTION, $sql );
		}
	}

	/**
	 * @testdox Should delete the owner row when the store leaves the platform, by $reason, and keep it while held orders keep the state: $held held.
	 * @testWith ["takeover", 0, false]
	 *           ["disabled", 0, false]
	 *           ["first_party", 0, false]
	 *           ["first_party", 2, false]
	 *           ["takeover", 2, true]
	 *           ["disabled", 2, true]
	 *
	 * @param string $reason The abandon reason.
	 * @param int    $held   How many orders are held.
	 * @param bool   $kept   Whether the owner row stays.
	 */
	public function test_abandon_deletes_the_owner_row( string $reason, int $held, bool $kept ): void {
		$this->set_collecting();
		$this->set_wallet_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, PayPalWalletRuntimeArbiter::OWNER_NATIVE );

		( new CollectingState( new Options(), new FixedHeldOrders( $held ) ) )->abandon( $reason );

		$this->assertSame( $kept ? PayPalWalletRuntimeArbiter::OWNER_NATIVE : false, get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION, false ) );
	}

	/**
	 * @testdox Should delete the platform apps' tokens when abandoning deletes the collecting state, and keep them when it does not.
	 */
	public function test_abandon_forgets_the_tokens_with_the_state(): void {
		$this->set_collecting();
		$this->set_tokens();

		( new CollectingState( new Options(), new FixedHeldOrders( 1 ) ) )->abandon( CollectingState::ABANDON_DISABLED );
		$this->assertSame( self::TOKEN_TRANSIENTS, $this->stored_tokens(), 'A held order keeps the state and its tokens' );

		( new CollectingState( new Options(), new FixedHeldOrders( 1 ) ) )->abandon( CollectingState::ABANDON_FIRST_PARTY );
		$this->assertFalse( get_option( Options::COLLECTING ) );
		$this->assertSame( array(), $this->stored_tokens() );
	}

	/**
	 * @testdox Should delete the platform apps' tokens when the extension takes over a store the platform serves.
	 */
	public function test_takeover_forgets_the_tokens(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M-CONNECTED',
				'tracking_id' => 'TRACK-OURS',
				'payee_email' => 'payee@example.com',
				'environment' => 'sandbox',
			)
		);
		$this->set_tokens();

		$this->bootstrap( PayPalWalletRuntimeArbiter::OWNER_EXTENSION )->on_plugin_activated( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );

		$this->assertSame( array(), $this->stored_tokens() );
	}
}
