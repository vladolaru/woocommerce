<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\CollectCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles\ContainerDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tests for the CollectingModule skeleton and the collect command.
 *
 * @group paypal-wallet
 */
class CollectingModuleTest extends WalletTestCase {

	/**
	 * A collect command over a state with no held orders.
	 *
	 * @return CollectCommand
	 */
	private function command(): CollectCommand {
		return new CollectCommand( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
	}

	/**
	 * @testdox Should expose the collecting services, build them from the container, and extend nothing yet.
	 */
	public function test_services_and_extensions(): void {
		$sut      = new CollectingModule();
		$services = $sut->services();

		$this->assertSame( array( 'collecting.options', 'collecting.state', 'collecting.connection-state' ), array_keys( $services ) );
		$this->assertSame( array(), $sut->extensions() );
		$this->assertSame( CollectingModule::class, $sut->id() );

		$container = new ContainerDouble(
			array( 'collecting.options' => new Options() )
		);
		$this->assertInstanceOf( Options::class, $services['collecting.options']() );
		$this->assertInstanceOf( CollectingState::class, $services['collecting.state']( $container ) );
		$this->assertInstanceOf( ConnectionState::class, $services['collecting.connection-state']( $container ) );
	}

	/**
	 * @testdox Should report success from run.
	 */
	public function test_run_returns_true(): void {
		$this->assertTrue( ( new CollectingModule() )->run( $this->mock( ContainerInterface::class ) ) );
	}

	/**
	 * @testdox Should enter the collecting state from the collect command and describe it.
	 */
	public function test_collect_command_enters_the_state(): void {
		$summary = ( $this->command() )->enter_collecting( 'payee@example.com', true );

		$data = get_option( Options::COLLECTING );
		$this->assertSame( 'payee@example.com', $data['payee_email'] );
		$this->assertSame( 'sandbox', $data['environment'] );
		$this->assertSame(
			array(
				'Payee'       => 'payee@example.com',
				'Tracking ID' => $data['tracking_id'],
				'Environment' => 'sandbox',
			),
			$summary
		);
	}

	/**
	 * @testdox Should use production without the sandbox flag.
	 */
	public function test_collect_command_defaults_to_production(): void {
		( $this->command() )->enter_collecting( 'payee@example.com', false );

		$this->assertSame( 'production', get_option( Options::COLLECTING )['environment'] );
	}

	/**
	 * @testdox Should refuse an invalid email from the collect command and write nothing.
	 */
	public function test_collect_command_rejects_an_invalid_email(): void {
		try {
			( $this->command() )->enter_collecting( 'nope', false );
			$this->fail( 'An invalid email must be refused' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertFalse( get_option( Options::COLLECTING ) );
		}
	}

	/**
	 * @testdox Should refuse the collect command on a platform-connected store and leave the platform option alone.
	 */
	public function test_collect_command_refuses_a_platform_connected_store(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );

		try {
			$this->command()->enter_collecting( 'payee@example.com', false );
			$this->fail( 'A platform-connected store must not collect' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( Options::COLLECTING ) );
			$this->assertSame( array( 'merchant_id' => 'M2' ), get_option( Options::PLATFORM ) );
		}
	}
}
