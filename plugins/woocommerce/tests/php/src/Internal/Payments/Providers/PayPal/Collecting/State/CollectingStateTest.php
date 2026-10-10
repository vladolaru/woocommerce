<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\GatewaySwitch;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException as WalletRuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Error;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tests for the CollectingState class.
 *
 * @group paypal-wallet
 */
class CollectingStateTest extends WalletTestCase {

	/**
	 * The wallet gateway's settings row.
	 */
	private const GATEWAY_SETTINGS = 'woocommerce_ppcp-gateway_settings';

	/**
	 * The System Under Test.
	 *
	 * @var CollectingState
	 */
	private $sut;

	/**
	 * Build the SUT with no held orders.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = $this->state_with_held_orders( 0 );
	}

	/**
	 * Build a state whose held-orders count answers the given number.
	 *
	 * @param int $held The number of held orders.
	 *
	 * @return CollectingState
	 */
	private function state_with_held_orders( int $held ): CollectingState {
		return new CollectingState( new Options(), new FixedHeldOrders( $held ) );
	}

	/**
	 * Store the gateway row turned off, with extra settings, without the shell's disable listener, which would abandon
	 * the state over the real held-orders query; the tests that need the abandon call it themselves.
	 *
	 * @param array $extra Settings to store beside `enabled`.
	 */
	private function turn_the_gateway_off( array $extra = array() ): void {
		remove_all_actions( 'update_option_' . self::GATEWAY_SETTINGS );
		$settings            = array_merge( (array) get_option( self::GATEWAY_SETTINGS, array() ), $extra );
		$settings['enabled'] = 'no';
		update_option( self::GATEWAY_SETTINGS, $settings );
	}

	/**
	 * @testdox Should write exactly the payee, a 32-character hex tracking ID, the environment and an unbound flag on enter.
	 */
	public function test_enter_writes_the_collecting_option(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$data = get_option( Options::COLLECTING );
		$this->assertSame( array( 'payee_email', 'tracking_id', 'environment', 'payee_bound' ), array_keys( $data ), 'Only the four keys are written' );
		$this->assertSame( 'payee@example.com', $data['payee_email'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $data['tracking_id'] );
		$this->assertSame( 'sandbox', $data['environment'] );
		$this->assertFalse( $data['payee_bound'] );
		$this->assertTrue( $this->sut->is_collecting() );
		$this->assertFalse( $this->sut->is_platform_connected() );
		$this->assertSame( 'payee@example.com', $this->sut->payee_email() );
		$this->assertSame( $data['tracking_id'], $this->sut->tracking_id() );
		$this->assertSame( 'sandbox', $this->sut->environment() );
	}

	/**
	 * @testdox Should turn the wallet gateway on through its settings API on enter, writing the gateway's own defaults.
	 */
	public function test_enter_turns_the_gateway_on_with_its_defaults(): void {
		$this->assertFalse( get_option( self::GATEWAY_SETTINGS ), 'The store starts with no gateway row' );

		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$this->assertSame(
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			),
			get_option( self::GATEWAY_SETTINGS ),
			'The row holds the gateway\'s form defaults with the gateway on'
		);
		$this->assertTrue( $this->sut->is_collecting(), 'Turning the gateway on does not leave the state again' );
	}

	/**
	 * @testdox Should turn a gateway the merchant had turned off back on at enter, keeping its other settings.
	 */
	public function test_enter_turns_a_disabled_gateway_on_and_keeps_its_settings(): void {
		$this->set_wallet_option(
			self::GATEWAY_SETTINGS,
			array(
				'enabled' => 'no',
				'ppcp'    => '',
				'title'   => 'Kept',
			)
		);

		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$settings = get_option( self::GATEWAY_SETTINGS );
		$this->assertSame( 'yes', $settings['enabled'] );
		$this->assertSame( 'Kept', $settings['title'] );
		$this->assertTrue( $this->sut->is_collecting() );
	}

	/**
	 * @testdox Should leave the gateway row alone when enter refuses its input or the store.
	 */
	public function test_refused_enter_leaves_the_gateway_alone(): void {
		try {
			$this->sut->enter( 'not-an-email', 'sandbox' );
			$this->fail( 'enter() must refuse an invalid email' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertFalse( get_option( self::GATEWAY_SETTINGS ) );
		}

		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		try {
			$this->sut->enter( 'payee@example.com', 'sandbox' );
			$this->fail( 'enter() must refuse a platform-connected store' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( self::GATEWAY_SETTINGS ) );
		}
	}

	/**
	 * @testdox Should leave the store as it was, log the cause and throw the wallet's exception when the gateway cannot be turned on.
	 */
	public function test_enter_writes_nothing_when_the_gateway_cannot_be_turned_on(): void {
		$failing = new class() extends GatewaySwitch {
			/**
			 * Fail as a change to the gateway class would.
			 */
			protected function save_enabled(): void {
				throw new Error( 'init_form_fields() drifted' );
			}
		};
		$sut     = new CollectingState( new Options(), new FixedHeldOrders( 0 ), $failing );
		$logged  = $this->spy_filter( 'woocommerce_logger_log_message' );

		try {
			$sut->enter( 'payee@example.com', 'sandbox' );
			$this->fail( 'enter() must fail when the gateway cannot be turned on' );
		} catch ( WalletRuntimeException $exception ) {
			$this->assertInstanceOf( RuntimeException::class, $exception, 'The collect command catches it and reports the message' );
			$this->assertStringContainsString( 'Could not turn the PayPal gateway on', $exception->getMessage() );
			$this->assertInstanceOf( Error::class, $exception->getPrevious() );
		}

		$this->assertFalse( get_option( Options::COLLECTING ), 'The store is not left half-entered' );
		$this->assertFalse( $sut->is_collecting() );
		$this->assertFalse( get_option( self::GATEWAY_SETTINGS ) );
		$messages = array_column( $logged->getArrayCopy(), 0 );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				static function ( $message ): bool {
					return is_string( $message ) && false !== strpos( $message, 'Could not turn the PayPal gateway on: Error: init_form_fields() drifted' );
				}
			),
			'The cause is logged'
		);
	}

	/**
	 * @testdox Should generate a fresh tracking ID when entering with a different payee or environment.
	 */
	public function test_enter_generates_a_fresh_tracking_id(): void {
		$this->sut->enter( 'payee@example.com', 'production' );
		$first = $this->sut->tracking_id();
		$this->sut->enter( 'other@example.com', 'production' );
		$second = $this->sut->tracking_id();
		$this->sut->enter( 'other@example.com', 'sandbox' );

		$this->assertNotSame( $first, $second );
		$this->assertNotSame( $second, $this->sut->tracking_id() );
	}

	/**
	 * @testdox Should keep the tracking ID when entering again with the same unbound payee, so a referral already opened still matches.
	 */
	public function test_enter_keeps_the_tracking_id_for_the_same_payee(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$first = $this->sut->tracking_id();

		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$this->assertSame( $first, $this->sut->tracking_id() );
	}

	/**
	 * @testdox Should refuse to enter the same bound payee in another environment and write nothing.
	 */
	public function test_enter_refuses_the_same_bound_payee_in_another_environment(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$before = get_option( Options::COLLECTING );

		try {
			$this->sut->enter( 'payee@example.com', 'production' );
			$this->fail( 'A bound payee cannot move to another environment' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( $before, get_option( Options::COLLECTING ) );
		}
	}

	/**
	 * @testdox Should refuse an invalid email or environment on enter and write nothing.
	 */
	public function test_enter_validates_its_input(): void {
		foreach ( array( array( 'not-an-email', 'sandbox' ), array( 'payee@example.com', 'staging' ) ) as $args ) {
			try {
				$this->sut->enter( ...$args );
				$this->fail( 'enter() must refuse ' . implode( ' / ', $args ) );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertFalse( get_option( Options::COLLECTING ), 'Nothing is written for invalid input' );
			}
		}
	}

	/**
	 * @testdox Should refuse to enter with a different payee once the payee is bound.
	 */
	public function test_enter_refuses_to_replace_a_bound_payee(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$tracking_id = $this->sut->tracking_id();

		$this->expectException( RuntimeException::class );
		try {
			$this->sut->enter( 'other@example.com', 'sandbox' );
		} finally {
			$this->assertSame( $tracking_id, $this->sut->tracking_id(), 'The bound state must be untouched' );
		}
	}

	/**
	 * @testdox Should write nothing to the collecting option when entering the same payee again after it is bound.
	 */
	public function test_enter_with_the_same_bound_payee_keeps_the_collecting_option(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$before  = get_option( Options::COLLECTING );
		$updates = $this->spy_filter( 'pre_update_option_' . Options::COLLECTING );

		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$this->assertSame( $before, get_option( Options::COLLECTING ), 'The bound state keeps its tracking ID and flag' );
		$this->assertCount( 0, $updates, 'A repeated enter must not write the collecting option' );
	}

	/**
	 * @testdox Should turn a disabled gateway back on, keeping its other settings, when entering the same bound payee again (K8).
	 */
	public function test_enter_with_the_same_bound_payee_turns_the_gateway_back_on(): void {
		$held = $this->state_with_held_orders( 1 );
		$held->enter( 'payee@example.com', 'sandbox' );
		$held->bind_payee();
		$this->turn_the_gateway_off( array( 'title' => 'Kept' ) );
		$held->abandon( CollectingState::ABANDON_DISABLED );
		$before = get_option( Options::COLLECTING );
		$this->assertTrue( $held->is_collecting(), 'Precondition: the held order keeps the collecting option' );

		$held->enter( 'payee@example.com', 'sandbox' );

		$after = get_option( self::GATEWAY_SETTINGS );
		$this->assertSame( 'yes', $after['enabled'], 'Re-entering the bound payee resumes collecting' );
		$this->assertSame( 'Kept', $after['title'] );
		$this->assertSame( $before, get_option( Options::COLLECTING ), 'Same payee, tracking ID and bound flag' );
	}

	/**
	 * @testdox Should write no gateway row when entering the same bound payee while the gateway is already on.
	 */
	public function test_enter_with_the_same_bound_payee_is_idempotent_for_an_enabled_gateway(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$before  = get_option( self::GATEWAY_SETTINGS );
		$updated = $this->spy_filter( 'update_option_' . self::GATEWAY_SETTINGS );

		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$this->assertSame( $before, get_option( self::GATEWAY_SETTINGS ) );
		$this->assertCount( 0, $updated, 'An unchanged row is not written' );
	}

	/**
	 * @testdox Should leave a disabled gateway off when a different payee is refused while the payee is bound.
	 */
	public function test_refused_bound_enter_leaves_a_disabled_gateway_off(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$this->turn_the_gateway_off();

		foreach ( array( array( 'other@example.com', 'sandbox' ), array( 'payee@example.com', 'production' ) ) as $args ) {
			try {
				$this->sut->enter( ...$args );
				$this->fail( 'enter() must refuse ' . implode( ' / ', $args ) );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'no', get_option( self::GATEWAY_SETTINGS )['enabled'], 'A refused enter does not turn the gateway on' );
			}
		}
	}

	/**
	 * @testdox Should throw and leave the collecting option as it was when the gateway cannot be turned back on for the same bound payee.
	 */
	public function test_enter_with_the_same_bound_payee_throws_when_the_gateway_cannot_be_turned_on(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->bind_payee();
		$before  = get_option( Options::COLLECTING );
		$failing = new class() extends GatewaySwitch {
			/**
			 * Fail as a change to the gateway class would.
			 */
			protected function save_enabled(): void {
				throw new Error( 'init_form_fields() drifted' );
			}
		};
		$sut     = new CollectingState( new Options(), new FixedHeldOrders( 1 ), $failing );

		$this->expectException( WalletRuntimeException::class );
		try {
			$sut->enter( 'payee@example.com', 'sandbox' );
		} finally {
			$this->assertSame( $before, get_option( Options::COLLECTING ) );
		}
	}

	/**
	 * @testdox Should refuse to enter on a platform-connected store and write no collecting option.
	 */
	public function test_enter_refuses_a_platform_connected_store(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'payee_email' => 'old@example.com',
				'tracking_id' => 't',
			)
		);

		try {
			$this->sut->enter( 'payee@example.com', 'sandbox' );
			$this->fail( 'enter() must refuse a platform-connected store' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( Options::COLLECTING ) );
			$this->assertSame( 'old@example.com', $this->sut->payee_email(), 'The platform payee still answers' );
		}
	}

	/**
	 * @testdox Should store the collecting, platform and first-order options as autoloaded.
	 */
	public function test_state_options_are_autoloaded(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->assertContains( $this->stored_autoload( Options::COLLECTING ), wp_autoload_values_to_autoload(), 'The collecting option is read on every request' );

		$this->assertTrue( $this->sut->claim_first_order( 17 ) );
		$this->assertContains( $this->stored_autoload( Options::FIRST_ORDER ), wp_autoload_values_to_autoload(), 'The first-order option is checked on every request, so an absent row must cost no query' );
		$this->assertSame( 17, ( new Options() )->first_order_id(), 'The autoloaded set answers' );
		$this->assertTrue( ( new Options() )->has_autoloaded( Options::FIRST_ORDER ) );
		$this->assertFalse( ( new Options() )->has_autoloaded( Options::NOTE_STATE ) );

		$this->sut->complete( 'M2' );
		$this->assertContains( $this->stored_autoload( Options::PLATFORM ), wp_autoload_values_to_autoload(), 'The platform option is read on every request' );
	}

	/**
	 * The stored autoload value of an option row.
	 *
	 * @param string $name The option name.
	 *
	 * @return string
	 */
	private function stored_autoload( string $name ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the stored flag.
	}

	/**
	 * @testdox Should refuse a payee change when the payee gets bound while the change is being validated.
	 */
	public function test_set_payee_email_rereads_the_bound_flag_before_writing(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$binder = $this->sut;
		add_filter(
			'sanitize_email',
			static function ( $email ) use ( $binder ) {
				$binder->bind_payee();
				return $email;
			}
		);

		try {
			$this->sut->set_payee_email( 'new@example.com' );
			$this->fail( 'The change must be refused once the payee is bound' );
		} catch ( RuntimeException $exception ) {
			remove_all_filters( 'sanitize_email' );
			$this->assertSame( 'payee@example.com', $this->sut->payee_email(), 'The bind must not be overwritten by a stale write' );
			$this->assertFalse( $this->sut->can_change_payee_email() );
		}
	}

	/**
	 * @testdox Should change the payee email until the payee is bound, then throw.
	 */
	public function test_set_payee_email_throws_once_bound(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->assertTrue( $this->sut->can_change_payee_email() );

		$this->sut->set_payee_email( 'new@example.com' );
		$this->assertSame( 'new@example.com', $this->sut->payee_email() );

		$this->sut->bind_payee();
		$this->assertFalse( $this->sut->can_change_payee_email() );

		$this->expectException( RuntimeException::class );
		$this->sut->set_payee_email( 'late@example.com' );
	}

	/**
	 * @testdox Should refuse to set a payee email when not collecting.
	 */
	public function test_set_payee_email_throws_when_not_collecting(): void {
		$this->assertFalse( $this->sut->can_change_payee_email() );

		$this->expectException( RuntimeException::class );
		$this->sut->set_payee_email( 'new@example.com' );
	}

	/**
	 * @testdox Should refuse an invalid payee email.
	 */
	public function test_set_payee_email_validates(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );

		$this->expectException( InvalidArgumentException::class );
		$this->sut->set_payee_email( 'nope' );
	}

	/**
	 * @testdox Should flip the bound flag on bind_payee and keep the other keys.
	 */
	public function test_bind_payee_flips_the_flag(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$before = get_option( Options::COLLECTING );

		$this->sut->bind_payee();

		$this->assertSame( array_merge( $before, array( 'payee_bound' => true ) ), get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should do nothing on bind_payee when not collecting.
	 */
	public function test_bind_payee_does_not_create_the_option(): void {
		$this->sut->bind_payee();

		$this->assertFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should let only the first order claim the first-order slot, without touching the collecting option.
	 */
	public function test_claim_first_order_succeeds_once(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$before  = get_option( Options::COLLECTING );
		$updates = $this->spy_filter( 'pre_update_option_' . Options::COLLECTING );

		$this->assertTrue( $this->sut->claim_first_order( 17 ) );
		$this->assertFalse( $this->sut->claim_first_order( 18 ) );

		$this->assertSame( 17, (int) get_option( Options::FIRST_ORDER ), 'The first claim stays' );
		$this->assertSame( $before, get_option( Options::COLLECTING ) );
		$this->assertCount( 0, $updates, 'Claiming must not update the collecting option' );
		delete_option( Options::FIRST_ORDER );
	}

	/**
	 * @testdox Should write the platform option and delete the collecting option on complete.
	 */
	public function test_complete_moves_the_state_to_the_platform_option(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$tracking_id = $this->sut->tracking_id();

		$this->sut->complete( 'M2' );

		$platform = get_option( Options::PLATFORM );
		$this->assertSame( array( 'merchant_id', 'tracking_id', 'payee_email', 'connected_at', 'environment' ), array_keys( $platform ) );
		$this->assertSame( 'M2', $platform['merchant_id'] );
		$this->assertSame( $tracking_id, $platform['tracking_id'] );
		$this->assertSame( 'payee@example.com', $platform['payee_email'] );
		$this->assertSame( 'sandbox', $platform['environment'] );
		$this->assertEqualsWithDelta( time(), $platform['connected_at'], 5 );
		$this->assertFalse( get_option( Options::COLLECTING ), 'The collecting option is deleted' );
		$this->assertTrue( $this->sut->is_platform_connected() );
		$this->assertFalse( $this->sut->is_collecting() );
		$this->assertSame( 'M2', $this->sut->merchant_id() );
		$this->assertSame( 'payee@example.com', $this->sut->payee_email(), 'The payee reads from the platform option after completing' );
		$this->assertSame( $tracking_id, $this->sut->tracking_id() );
	}

	/**
	 * @testdox Should refuse to complete when the store is not collecting and write no platform option.
	 */
	public function test_complete_refuses_when_not_collecting(): void {
		try {
			$this->sut->complete( 'M2' );
			$this->fail( 'complete() must refuse when not collecting' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( Options::PLATFORM ) );
		}
	}

	/**
	 * @testdox Should refuse to complete with an empty merchant ID and keep the collecting option.
	 */
	public function test_complete_refuses_an_empty_merchant_id(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );

		try {
			$this->sut->complete( '' );
			$this->fail( 'complete() must refuse an empty merchant ID' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertTrue( $this->sut->is_collecting() );
			$this->assertFalse( get_option( Options::PLATFORM ) );
		}
	}

	/**
	 * @testdox Should keep the collecting option on takeover or disable while orders are held, and delete it when none are.
	 *
	 * @testWith ["takeover"]
	 *           ["disabled"]
	 *
	 * @param string $reason The abandon reason.
	 */
	public function test_takeover_and_disable_keep_the_option_while_orders_are_held( string $reason ): void {
		$held = $this->state_with_held_orders( 1 );
		$held->enter( 'payee@example.com', 'sandbox' );

		$held->abandon( $reason );
		$this->assertTrue( $held->is_collecting(), 'A held order keeps the collecting option' );

		$this->sut->abandon( $reason );
		$this->assertFalse( get_option( Options::COLLECTING ), 'No held order deletes the collecting option' );
	}

	/**
	 * @testdox Should always delete the collecting option on a first-party connection, even with held orders.
	 */
	public function test_first_party_always_deletes_the_option(): void {
		$held = $this->state_with_held_orders( 3 );
		$held->enter( 'payee@example.com', 'sandbox' );

		$held->abandon( 'first_party' );

		$this->assertFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should delete the platform option on a first-party connection too, even with held orders: first-party wins over everything.
	 */
	public function test_first_party_deletes_the_platform_option(): void {
		$held = $this->state_with_held_orders( 2 );
		$held->enter( 'payee@example.com', 'sandbox' );
		$held->complete( 'MERCHANT1' );
		$this->assertTrue( $held->is_platform_connected() );
		update_option( Options::SELLER_STATUS, array( 'payments_receivable' => false ), false );

		$held->abandon( CollectingState::ABANDON_FIRST_PARTY );

		$this->assertFalse( get_option( Options::PLATFORM ) );
		$this->assertFalse( get_option( Options::COLLECTING ) );
		$this->assertFalse( get_option( Options::SELLER_STATUS ) );
		$this->assertFalse( $held->is_platform_connected() );
	}

	/**
	 * @testdox Should keep the platform option when the extension takes over or the gateway is turned off.
	 *
	 * @testWith ["takeover"]
	 *           ["disabled"]
	 *
	 * @param string $reason The abandon reason.
	 */
	public function test_takeover_and_disable_keep_the_platform_option( string $reason ): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );
		$this->sut->complete( 'MERCHANT1' );

		$this->sut->abandon( $reason );

		$this->assertTrue( $this->sut->is_platform_connected() );
	}

	/**
	 * @testdox Should refuse an unknown abandon reason and keep the option.
	 */
	public function test_abandon_refuses_an_unknown_reason(): void {
		$this->sut->enter( 'payee@example.com', 'sandbox' );

		try {
			$this->sut->abandon( 'bogus' );
			$this->fail( 'abandon() must refuse an unknown reason' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertTrue( $this->sut->is_collecting() );
		}
	}

	/**
	 * @testdox Should never write an option from the readers, whether the options are present, malformed or absent.
	 *
	 * @testWith ["present"]
	 *           ["malformed"]
	 *           ["absent"]
	 *
	 * @param string $seed How the options are seeded.
	 */
	public function test_readers_never_write( string $seed ): void {
		if ( 'present' === $seed ) {
			$this->sut->enter( 'payee@example.com', 'sandbox' );
			$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		} elseif ( 'malformed' === $seed ) {
			$this->set_wallet_option( Options::COLLECTING, 'not-an-array' );
			$this->set_wallet_option( Options::PLATFORM, array( 'tracking_id' => 't' ) );
		}
		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};
		foreach ( array( Options::COLLECTING, Options::PLATFORM, Options::FIRST_ORDER ) as $name ) {
			add_action( 'add_option_' . $name, $count );
			add_action( 'delete_option_' . $name, $count );
		}
		$updates = array(
			$this->spy_filter( 'pre_update_option_' . Options::COLLECTING ),
			$this->spy_filter( 'pre_update_option_' . Options::PLATFORM ),
		);

		$this->sut->is_collecting();
		$this->sut->is_platform_connected();
		$this->sut->payee_email();
		$this->sut->tracking_id();
		$this->sut->environment();
		$this->sut->merchant_id();
		$this->sut->can_change_payee_email();

		$this->assertSame( 0, $writes, 'No reader may add or delete an option' );
		$this->assertCount( 0, $updates[0], 'No reader may update the collecting option' );
		$this->assertCount( 0, $updates[1], 'No reader may update the platform option' );
	}

	/**
	 * @testdox Should read an empty or malformed option as no state.
	 */
	public function test_malformed_options_read_as_empty(): void {
		$this->set_wallet_option( Options::COLLECTING, 'garbage' );
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 5 ) );

		$this->assertFalse( $this->sut->is_collecting() );
		$this->assertFalse( $this->sut->is_platform_connected() );
		$this->assertSame( '', $this->sut->payee_email() );
		$this->assertSame( 'production', $this->sut->environment() );
	}
}
