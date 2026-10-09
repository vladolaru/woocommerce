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
	 * Whether a reconcile is queued.
	 *
	 * @return bool
	 */
	private function reconcile_queued(): bool {
		return as_has_scheduled_action( Reconciler::HOOK, array(), Reconciler::GROUP );
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
		$this->assertSame( $owner, get_option( PayPalWalletBootstrap::LAST_OWNER_OPTION ) );
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
