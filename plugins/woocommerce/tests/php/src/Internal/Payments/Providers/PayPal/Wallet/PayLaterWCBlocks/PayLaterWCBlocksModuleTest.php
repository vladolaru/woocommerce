<?php
/**
 * Tests for the Pay Later WooCommerce Blocks module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\HookedBlocksRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks\PayLaterWCBlocksModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The block editor script that inserts the cart Pay Later messaging block under the cart totals.
 *
 * @group paypal-wallet
 */
class PayLaterWCBlocksModuleTest extends WalletTestCase {

	/**
	 * Handle of the inserter script.
	 */
	private const INSERTER_HANDLE = 'ppcp-checkout-paylater-block-editor-inserter';

	/**
	 * Remove the inserter script, so a test starts and ends without it.
	 */
	public function tearDown(): void {
		try {
			wp_dequeue_script( self::INSERTER_HANDLE );
			wp_deregister_script( self::INSERTER_HANDLE );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Run the module against a container that serves what the editor hook reads, then fire the editor hook.
	 *
	 * The test runner restores the global hooks after each test, so the callbacks that run() adds do not leak.
	 */
	private function run_module_and_enqueue_editor_assets(): void {
		$messages_apply = $this->mock( MessagesApply::class );
		$messages_apply->shouldReceive( 'for_country' )->andReturn( true );

		$asset_getter = $this->mock( AssetGetter::class );
		$asset_getter->shouldReceive( 'get_asset_url' )
			->with( 'CartPayLaterMessagesBlock/cart-paylater-block-inserter.js' )
			->andReturn( 'https://example.org/cart-paylater-block-inserter.js' );

		$registrar = $this->mock( HookedBlocksRegistrar::class );
		$registrar->shouldReceive( 'register' );

		$container = $this->container_with_v6_ownership(
			null,
			array(
				'button.helper.messages-apply'    => $messages_apply,
				'paylater-wc-blocks.asset_getter' => $asset_getter,
				'paylater-wc-blocks.hooked-blocks-registrar' => $registrar,
				'ppcp.asset-version'              => '1.0.0',
			)
		);

		remove_all_actions( 'enqueue_block_editor_assets' );
		( new PayLaterWCBlocksModule() )->run( $container );
		do_action( 'enqueue_block_editor_assets' );
	}

	/**
	 * @testdox Should register the cart block inserter with the lodash dependency its script reads as a global, because the script destructures lodash on load and fails without it.
	 */
	public function test_inserter_script_declares_lodash_as_a_dependency(): void {
		$this->run_module_and_enqueue_editor_assets();

		$script = wp_scripts()->query( self::INSERTER_HANDLE );

		$this->assertNotFalse( $script, 'The inserter script should be registered on enqueue_block_editor_assets.' );
		$this->assertContains( 'lodash', $script->deps );
		$this->assertTrue( wp_script_is( self::INSERTER_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should keep the wp-blocks, wp-data and wp-element dependencies of the inserter script.
	 */
	public function test_inserter_script_keeps_the_block_editor_dependencies(): void {
		$this->run_module_and_enqueue_editor_assets();

		$script = wp_scripts()->query( self::INSERTER_HANDLE );

		$this->assertNotFalse( $script );
		$this->assertEqualsCanonicalizing(
			array( 'wp-blocks', 'wp-data', 'wp-element', 'lodash' ),
			$script->deps
		);
	}
}
