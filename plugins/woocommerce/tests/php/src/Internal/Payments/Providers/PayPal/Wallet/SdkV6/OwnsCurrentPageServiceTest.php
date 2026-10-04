<?php
/**
 * Tests for the service that says whether the v6 SDK owns the current page.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\SdkV6Manager;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The 'sdk-v6.owns-current-page' service, resolved from the real SdkV6 services file against a container that only
 * serves 'sdk-v6.manager'.
 *
 * The wallet modules read this service to decide whether to stand down, so a second PayPal SDK does not run against
 * window.paypal. The service answers with what the manager says about the current page: the manager's own tests cover
 * which pages load the SDK (a page that only shows Pay Later messaging included).
 *
 * @group paypal-wallet
 */
class OwnsCurrentPageServiceTest extends WalletTestCase {

	/**
	 * Resolve the service callable from the real services file.
	 *
	 * @param SdkV6Manager $manager The manager the container serves.
	 * @return callable
	 */
	private function resolve_service( SdkV6Manager $manager ): callable {
		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'sdk-v6.manager' )->andReturn( $manager );

		$services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SdkV6/services.php';

		return $services['sdk-v6.owns-current-page']( $container );
	}

	/**
	 * A page where only Pay Later messaging loads the v6 SDK, with no button location claiming it, must count as owned.
	 * Reporting false there would let the v5 button stack enqueue its own SDK next to the v6 one that messaging already
	 * put on the page, and both would claim window.paypal. The service cannot tell the two reasons apart: it forwards the
	 * manager's answer, so this case holds for a page claimed by buttons as well.
	 *
	 * @testdox Should report that v6 owns the page when the manager loads the SDK on it, whether buttons or only messaging claim the page.
	 */
	public function test_owns_current_page_true_when_the_manager_loads_the_sdk(): void {
		$manager = $this->mock( SdkV6Manager::class );
		$manager->shouldReceive( 'should_load_on_current_page' )->once()->andReturn( true );

		$owns_current_page = $this->resolve_service( $manager );

		$this->assertTrue( $owns_current_page() );
	}

	/**
	 * @testdox Should report that v6 does not own the page when nothing claims the SDK on it.
	 */
	public function test_owns_current_page_false_when_nothing_claims_the_sdk(): void {
		$manager = $this->mock( SdkV6Manager::class );
		$manager->shouldReceive( 'should_load_on_current_page' )->once()->andReturn( false );

		$owns_current_page = $this->resolve_service( $manager );

		$this->assertFalse( $owns_current_page() );
	}

	/**
	 * @testdox Should ask the manager again on every call, because the answer depends on the query and not on the moment the container was built.
	 */
	public function test_owns_current_page_asks_the_manager_on_every_call(): void {
		$manager = $this->mock( SdkV6Manager::class );
		$manager->shouldReceive( 'should_load_on_current_page' )->twice()->andReturn( false, true );

		$owns_current_page = $this->resolve_service( $manager );

		$this->assertFalse( $owns_current_page(), 'The first page is not owned' );
		$this->assertTrue( $owns_current_page(), 'The second page is owned' );
	}
}
