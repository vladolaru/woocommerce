<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\ReconcileCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the reconcile command's summary lines.
 *
 * @group paypal-wallet
 */
class ReconcileCommandTest extends WalletTestCase {

	/**
	 * @testdox Should describe each outcome with its order IDs, "none" when empty, and the onboarding outcome.
	 */
	public function test_describe(): void {
		$reconciler = ( new \ReflectionClass( Reconciler::class ) )->newInstanceWithoutConstructor();
		$sut        = new ReconcileCommand( $reconciler );

		$lines = $sut->describe(
			array(
				'completed'  => array( 12, 15 ),
				'returned'   => array(),
				'failed'     => array( 7 ),
				'onboarding' => 'incomplete',
			)
		);

		$this->assertSame( array( 'Completed: 12, 15', 'Returned: none', 'Failed: 7', 'Onboarding: incomplete' ), $lines );
	}
}
