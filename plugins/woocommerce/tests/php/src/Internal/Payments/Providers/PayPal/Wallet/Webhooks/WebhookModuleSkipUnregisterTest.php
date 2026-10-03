<?php
/**
 * Tests for the PayPal wallet webhook module's deactivation listener (ported from the extension's WebhookModuleSkipUnregisterTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The deactivation listener must leave the PayPal webhook subscription in place when a host says so through the skip
 * filter, for example a host that keeps serving the wallet after this plugin deactivates.
 *
 * The module's run() is called with a container that only knows the registrar mock; the real action then runs the
 * listener the module registered.
 *
 * @group paypal-wallet
 */
class WebhookModuleSkipUnregisterTest extends WalletTestCase {

	/**
	 * Run the module against a container that serves the given registrar, with no other listener or filter on the
	 * deactivation hooks (a booted wallet in the same process could have added some).
	 *
	 * @param WebhookRegistrar $registrar The registrar the container serves.
	 */
	private function run_module_with( WebhookRegistrar $registrar ): void {
		remove_all_actions( 'woocommerce_paypal_payments_gateway_deactivate' );
		remove_all_filters( 'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate' );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'webhook.registrar' )->andReturn( $registrar );

		$this->assertTrue( ( new WebhookModule() )->run( $container ) );
		$this->assertTrue( has_action( 'woocommerce_paypal_payments_gateway_deactivate' ), 'WebhookModule must register the deactivation listener' );
	}

	/**
	 * @testdox Should leave the webhooks in place when the skip filter returns true, and ask the filter with false.
	 */
	public function test_unregister_is_skipped_when_the_filter_says_so(): void {
		$registrar = $this->mock( WebhookRegistrar::class );
		$registrar->shouldReceive( 'unregister' )->never();
		$this->run_module_with( $registrar );

		$received = null;
		add_filter(
			'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate',
			function ( $skip ) use ( &$received ) {
				$received = $skip;
				return true;
			}
		);

		do_action( 'woocommerce_paypal_payments_gateway_deactivate' );

		$this->assertFalse( $received, 'The filter should start from false' );
	}

	/**
	 * @testdox Should unregister the webhooks on deactivation by default.
	 */
	public function test_unregister_runs_by_default(): void {
		$registrar = $this->mock( WebhookRegistrar::class );
		$registrar->shouldReceive( 'unregister' )->once();
		$this->run_module_with( $registrar );

		do_action( 'woocommerce_paypal_payments_gateway_deactivate' );
	}
}
