<?php
/**
 * Tests that the webhook module keeps the stored webhook on a plugin upgrade.
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
 * An upgrade never clears the stored webhook: a fresh install, a merchant without Pay upon Invoice or OXXO, and a merchant
 * who still has either of them enabled all keep it, because the wallet does not register the webhook again for them.
 *
 * @group paypal-wallet
 */
class WebhookModuleUpgradeKeepsWebhookTest extends WalletTestCase {

	private const PUI_OPTION  = 'woocommerce_ppcp-pay-upon-invoice-gateway_settings';
	private const OXXO_OPTION = 'woocommerce_ppcp-oxxo-gateway_settings';

	/**
	 * Run the module so its listeners are registered, with no other listener on the migration hook, and store a webhook.
	 */
	public function setUp(): void {
		parent::setUp();

		remove_all_actions( 'woocommerce_paypal_payments_gateway_migrate' );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->with( 'webhook.registrar' )->andReturn( $this->mock( WebhookRegistrar::class ) );
		$this->assertTrue( ( new WebhookModule() )->run( $container ) );

		$this->set_wallet_option( WebhookRegistrar::KEY, array( 'id' => 'WH-1' ) );
	}

	/**
	 * Whether the stored webhook is still there.
	 *
	 * @return bool
	 */
	private function webhook_is_stored(): bool {
		return false !== get_option( WebhookRegistrar::KEY );
	}

	/**
	 * @testdox Should keep the stored webhook on a fresh install, even when Pay upon Invoice is enabled.
	 */
	public function test_fresh_install_keeps_the_webhook(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'enabled' => 'yes' ) );

		do_action( 'woocommerce_paypal_payments_gateway_migrate', '' );

		$this->assertTrue( $this->webhook_is_stored() );
	}

	/**
	 * @testdox Should keep the stored webhook on an upgrade when neither Pay upon Invoice nor OXXO is enabled.
	 */
	public function test_upgrade_without_the_two_methods_keeps_the_webhook(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'enabled' => 'no' ) );

		do_action( 'woocommerce_paypal_payments_gateway_migrate', '3.4.0' );

		$this->assertTrue( $this->webhook_is_stored() );
	}

	/**
	 * @testdox Should keep the stored webhook on an upgrade even when Pay upon Invoice and OXXO are enabled, because the wallet does not re-register for them.
	 */
	public function test_upgrade_with_the_two_methods_keeps_the_webhook(): void {
		$this->set_wallet_option( self::PUI_OPTION, array( 'enabled' => 'yes' ) );
		$this->set_wallet_option( self::OXXO_OPTION, array( 'enabled' => 'yes' ) );

		do_action( 'woocommerce_paypal_payments_gateway_migrate', '3.4.0' );

		$this->assertTrue( $this->webhook_is_stored() );
	}
}
