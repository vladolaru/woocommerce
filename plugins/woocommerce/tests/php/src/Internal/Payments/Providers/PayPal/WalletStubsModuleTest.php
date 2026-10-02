<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\WalletStubsModule;
use WC_Unit_Test_Case;

/**
 * Tests for the WalletStubsModule class.
 */
class WalletStubsModuleTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WalletStubsModule
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		// The module implements interfaces from the vendored extension's autoloader.
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PluginModule' ) ) {
			require PayPalWalletBootstrap::VENDORED_DIR . '/vendor/autoload.php';
		}
		$this->sut = new WalletStubsModule();
	}

	/**
	 * @testdox Should register every stubbed service ID as a callable that reports not eligible.
	 */
	public function test_every_stubbed_service_is_a_false_callable(): void {
		$services = $this->sut->services();

		$this->assertSame( WalletStubsModule::STUBBED_SERVICE_IDS, array_keys( $services ), 'The registered IDs must match the published list' );
		foreach ( $services as $id => $factory ) {
			$service = $factory();
			$this->assertIsCallable( $service, "$id must resolve to a callable" );
			$this->assertFalse( $service(), "$id must report not eligible" );
		}
	}

	/**
	 * @testdox Should identify itself by class name so Modularity can report it.
	 */
	public function test_module_id_is_class_name(): void {
		$this->assertSame( WalletStubsModule::class, $this->sut->id() );
	}
}
