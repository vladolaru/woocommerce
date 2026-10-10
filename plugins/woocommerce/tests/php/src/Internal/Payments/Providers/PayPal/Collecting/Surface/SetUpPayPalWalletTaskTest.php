<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\SetUpPayPalWalletTask;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the Home task that asks the merchant to set up PayPal Wallet.
 *
 * @group paypal-wallet
 */
class SetUpPayPalWalletTaskTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var SetUpPayPalWalletTask
	 */
	private $sut;

	/**
	 * Build the task over the real held-orders query.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new SetUpPayPalWalletTask();
	}

	/**
	 * @testdox Should carry the task ID, the title, the time, the level and the settings URL, and be dismissable.
	 */
	public function test_describes_itself(): void {
		$this->assertSame( 'wc-paypal-wallet-setup', $this->sut->get_id() );
		$this->assertSame( 'Set up PayPal Wallet', $this->sut->get_title() );
		$this->assertSame( '2 minutes', $this->sut->get_time() );
		$this->assertSame( 1, $this->sut->get_level() );
		$this->assertSame( PayPalWalletBootstrap::get_settings_url(), $this->sut->get_action_url() );
		$this->assertTrue( $this->sut->is_dismissable() );
	}

	/**
	 * @testdox Should point the task at the Plugins page while the extension owns the wallet, where the wallet's settings route does not exist.
	 */
	public function test_action_url_while_the_extension_owns_the_wallet(): void {
		$url = $this->as_extension_owner(
			function (): string {
				return $this->sut->get_action_url();
			}
		);

		$this->assertSame( admin_url( 'plugins.php' ), $url );
	}

	/**
	 * @testdox Should show the task only when a wallet order exists and the store is not first-party connected: $state.
	 * @testWith ["dormant", true]
	 *           ["collecting", true]
	 *           ["platform", true]
	 *           ["first_party", false]
	 *
	 * @param string $state    The connection state set up for the test.
	 * @param bool   $expected Whether the task is viewable once a first order exists.
	 */
	public function test_can_view_needs_a_first_order_and_no_first_party_connection( string $state, bool $expected ): void {
		$this->set_state( $state );
		$this->assertFalse( $this->sut->can_view(), 'Without a first order there is nothing to set up' );

		$this->set_first_order( 7 );

		$this->assertSame( $expected, ( new SetUpPayPalWalletTask() )->can_view() );
	}

	/**
	 * @testdox Should be complete only when connected through the platform and no order is held: $state with $held held orders.
	 * @testWith ["collecting", 0, false]
	 *           ["collecting", 1, false]
	 *           ["platform", 1, false]
	 *           ["platform", 0, true]
	 *           ["first_party", 0, false]
	 *           ["first_party", 2, false]
	 *
	 * @param string $state    The connection state.
	 * @param int    $held     How many orders are held.
	 * @param bool   $expected Whether the task is complete.
	 */
	public function test_is_complete_needs_a_connection_and_no_held_order( string $state, int $held, bool $expected ): void {
		$this->set_state( $state );
		$this->set_first_order( 7 );
		for ( $i = 0; $i < $held; $i++ ) {
			$this->held_order();
		}

		$this->assertSame( $expected, $this->sut->is_complete() );
	}

	/**
	 * @testdox Should count no held order and record no completion for a first-party connected store, which never sees the task.
	 */
	public function test_a_hidden_task_is_never_complete_and_counts_nothing(): void {
		$held = new class() extends HeldOrders {
			/**
			 * How many times the count ran.
			 *
			 * @var int
			 */
			public int $counted = 0;

			/**
			 * Count the call.
			 *
			 * @return int
			 */
			public function count(): int {
				++$this->counted;
				return 0;
			}
		};
		$this->set_first_party_connected();
		$this->set_first_order( 7 );
		$sut = new SetUpPayPalWalletTask( null, $held );

		$json = $sut->get_json();

		$this->assertFalse( $json['canView'] );
		$this->assertFalse( $json['isComplete'] );
		$this->assertSame( 0, $held->counted, 'The held orders are not counted for a task nobody sees' );
		$this->assertNotContains( 'wc-paypal-wallet-setup', (array) get_option( Task::COMPLETED_OPTION, array() ), 'No completion is recorded' );
	}

	/**
	 * @testdox Should say what to do while one order, or none, is held.
	 * @testWith [0]
	 *           [1]
	 *
	 * @param int $held How many orders are held.
	 */
	public function test_content_asks_to_connect_with_at_most_one_held_order( int $held ): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		for ( $i = 0; $i < $held; $i++ ) {
			$this->held_order();
		}

		$this->assertSame( 'You received an order paid with PayPal Wallet. Connect PayPal Wallet to receive the payment.', $this->sut->get_content() );
	}

	/**
	 * @testdox Should name the number of held orders and the earliest deadline in the site's time zone when two or more are held.
	 */
	public function test_content_names_the_count_and_the_earliest_deadline(): void {
		$this->set_wallet_option( 'timezone_string', 'Pacific/Auckland' );
		$this->set_wallet_option( 'date_format', 'F j, Y' );
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->held_order( 1790086400 );
		$this->held_order( 1790000000 );
		$this->held_order( 1790172800 );

		$this->assertSame(
			'3 orders are waiting; the first is returned to the customer on October 22, 2026 if setup is not completed',
			$this->sut->get_content()
		);
	}

	/**
	 * @testdox Should ask the held-orders query once per request however often the task is read.
	 */
	public function test_asks_the_held_orders_query_once(): void {
		$held = new class() extends HeldOrders {
			/**
			 * How many times each query ran.
			 *
			 * @var int[]
			 */
			public array $calls = array(
				'count'    => 0,
				'earliest' => 0,
			);

			/**
			 * Two orders are held.
			 *
			 * @return int
			 */
			public function count(): int {
				++$this->calls['count'];
				return 2;
			}

			/**
			 * The deadline of the earliest one.
			 *
			 * @return int|null
			 */
			public function earliest_deadline(): ?int {
				++$this->calls['earliest'];
				return 1792592000;
			}
		};
		$this->set_platform_connected();
		$this->set_first_order( 7 );
		$sut = new SetUpPayPalWalletTask( null, $held );

		$sut->is_complete();
		$sut->is_complete();
		$sut->get_content();
		$sut->get_content();
		$sut->can_view();

		$this->assertSame( 1, $held->calls['count'], 'One count query' );
		$this->assertSame( 1, $held->calls['earliest'], 'One deadline query' );
	}

	/**
	 * Put the store in a connection state.
	 *
	 * @param string $state `dormant`, `collecting`, `platform` or `first_party`.
	 */
	private function set_state( string $state ): void {
		switch ( $state ) {
			case 'collecting':
				$this->set_collecting();
				break;
			case 'platform':
				$this->set_platform_connected();
				break;
			case 'first_party':
				$this->set_first_party_connected();
				break;
		}
	}
}
