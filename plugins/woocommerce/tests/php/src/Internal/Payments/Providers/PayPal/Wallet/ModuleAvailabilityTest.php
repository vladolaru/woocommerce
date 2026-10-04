<?php
/**
 * Tests for the optional module availability service (ported from the extension's ModuleAvailabilityTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ModuleAvailability;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use RuntimeException;

/**
 * An optional module registers `<prefix>.available` and, when it has an eligibility rule, `<prefix>.eligibility.check`.
 * The service answers "loaded", "eligible" and "available" from those IDs, so a module that is not there reads as not
 * loaded rather than as a missing service.
 *
 * Every case is a wallet case: the service stays in the fork. The extension's cases used the IDs of modules the fork
 * dropped (Apple Pay, Google Pay, local APMs, order tracking) as sample prefixes; this port uses neutral prefixes, so
 * the file needs no edit when a module's name leaves the code.
 *
 * @group paypal-wallet
 */
class ModuleAvailabilityTest extends WalletTestCase {

	/**
	 * A service over a stub container that holds exactly the given services.
	 *
	 * @param array<string, mixed> $services The services by ID.
	 * @return ModuleAvailability
	 */
	private function sut( array $services ): ModuleAvailability {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->andReturnUsing(
			static function ( string $id ) use ( $services ): bool {
				return array_key_exists( $id, $services );
			}
		);
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $id ) use ( $services ) {
				if ( ! array_key_exists( $id, $services ) ) {
					throw new RuntimeException( "Service $id not found" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				return $services[ $id ];
			}
		);

		return new ModuleAvailability( $container );
	}

	/**
	 * @testdox Should read an absent module as not loaded, not eligible and not available (wallet).
	 */
	public function test_absent_module_is_not_loaded_not_eligible_not_available(): void {
		$sut = $this->sut( array() );

		$this->assertFalse( $sut->is_loaded( 'sample-module' ) );
		$this->assertFalse( ( $sut->eligibility_check( 'sample-module' ) )() );
		$this->assertFalse( ( $sut->availability_check( 'sample-module' ) )() );
		$this->assertFalse( $sut->is_eligible( 'sample-module' ) );
		$this->assertFalse( $sut->is_available( 'sample-module' ) );
	}

	/**
	 * @testdox Should read a loaded module with a callable check and an available flag as loaded, eligible and available (wallet).
	 */
	public function test_loaded_module_with_callable_check_and_available_flag(): void {
		$sut = $this->sut(
			array(
				'sample-module.available'         => true,
				'sample-module.eligibility.check' => static fn(): bool => true,
			)
		);

		$this->assertTrue( $sut->is_loaded( 'sample-module' ) );
		$this->assertTrue( $sut->is_eligible( 'sample-module' ) );
		$this->assertTrue( $sut->is_available( 'sample-module' ) );
	}

	/**
	 * @testdox Should read an eligible module whose available flag is false as not available (wallet).
	 */
	public function test_eligible_but_not_available(): void {
		$sut = $this->sut(
			array(
				'sample-module.available'         => false,
				'sample-module.eligibility.check' => static fn(): bool => true,
			)
		);

		$this->assertTrue( $sut->is_eligible( 'sample-module' ) );
		$this->assertFalse( $sut->is_available( 'sample-module' ) );
	}

	/**
	 * @testdox Should wrap a bool eligibility service in a callable (wallet).
	 */
	public function test_bool_check_is_wrapped_in_a_callable(): void {
		$sut = $this->sut(
			array(
				'sample-module.available'              => true,
				'sample-module.eligibility.check'      => true,
				'sample-module.part.eligibility.check' => false,
			)
		);

		$this->assertIsCallable( $sut->eligibility_check( 'sample-module' ) );
		$this->assertTrue( ( $sut->eligibility_check( 'sample-module' ) )() );
		$this->assertFalse( ( $sut->eligibility_check( 'sample-module.part' ) )() );
	}

	/**
	 * @testdox Should read a loaded module without an eligibility rule as eligible once it is available (wallet).
	 */
	public function test_loaded_module_without_check_is_eligible_when_available(): void {
		$sut = $this->sut( array( 'sample-module.available' => true ) );

		$this->assertTrue( $sut->is_loaded( 'sample-module' ) );
		$this->assertTrue( $sut->is_eligible( 'sample-module' ) );
		$this->assertTrue( $sut->is_available( 'sample-module' ) );
	}

	/**
	 * @testdox Should not run the eligibility check until the availability check is called (wallet).
	 */
	public function test_availability_check_is_lazy(): void {
		$calls = 0;
		$sut   = $this->sut(
			array(
				'sample-module.available'         => true,
				'sample-module.eligibility.check' => static function () use ( &$calls ): bool {
					++$calls;
					return true;
				},
			)
		);

		$check = $sut->availability_check( 'sample-module' );
		$this->assertSame( 0, $calls, 'Building the check must not run it' );
		$this->assertTrue( $check() );
		$this->assertSame( 1, $calls );
	}

	/**
	 * @testdox Should check eligibility before it reads the available flag, because the flag may call PayPal (wallet).
	 */
	public function test_eligibility_is_checked_before_availability(): void {
		/**
		 * The container mock.
		 *
		 * @var ContainerInterface&MockInterface $container
		 */
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->with( 'sample-module.available' )->andReturn( true );
		$container->shouldReceive( 'has' )->with( 'sample-module.eligibility.check' )->andReturn( true );
		$container->shouldReceive( 'get' )->with( 'sample-module.eligibility.check' )->andReturn( static fn(): bool => false );
		$container->shouldReceive( 'get' )->with( 'sample-module.available' )->never();

		$sut = new ModuleAvailability( $container );

		$this->assertFalse( ( $sut->availability_check( 'sample-module' ) )() );
	}
}
