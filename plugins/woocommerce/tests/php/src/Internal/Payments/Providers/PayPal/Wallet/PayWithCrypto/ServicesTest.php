<?php
/**
 * Tests for the services of the Pay with Crypto module.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCPaymentMethod;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles\ContainerDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The module's services, resolved from a container booted with the wallet's real module list, and the eligibility
 * checks resolved from the services file over a container that answers the merchant country and the shop currency.
 *
 * @group paypal-wallet
 */
class ServicesTest extends WalletTestCase {

	use BootsCollectingContainer;

	/**
	 * The services file of the module.
	 *
	 * @return array
	 */
	private function services(): array {
		return require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/PayWithCrypto/services.php';
	}

	/**
	 * A container that answers the merchant country and the shop currency, and the two rules the combined check reads
	 * as the services file computes them over those answers.
	 *
	 * @param string $country  The merchant country.
	 * @param string $currency The shop currency.
	 * @return ContainerDouble
	 */
	private function container_for( string $country, string $currency ): ContainerDouble {
		$currency_getter = $this->mock( CurrencyGetter::class );
		$currency_getter->shouldReceive( 'get' )->andReturn( $currency );

		$services = $this->services();
		$inputs   = array(
			'api.merchant.country'     => $country,
			'api.shop.currency.getter' => $currency_getter,
		);
		$answers  = new ContainerDouble( $inputs );

		return new ContainerDouble(
			array_merge(
				$inputs,
				array(
					'ppcp-local-apms.eligibility.check'  => $services['ppcp-local-apms.eligibility.check']( $answers ),
					'ppcp-local-apms.pwc.currency.check' => $services['ppcp-local-apms.pwc.currency.check']( $answers ),
				)
			)
		);
	}

	/**
	 * @testdox Should resolve the seven Pay with Crypto services from a booted container.
	 */
	public function test_the_services_resolve_from_a_booted_container(): void {
		$container = $this->boot_container();

		$this->assertInstanceOf( AssetGetter::class, $container->get( 'ppcp-local-apms.asset_getter' ) );
		$this->assertIsArray( $container->get( 'ppcp-local-apms.payment-methods' ) );
		$this->assertInstanceOf( PWCGateway::class, $container->get( 'ppcp-local-apms.pwc.wc-gateway' ) );
		$this->assertInstanceOf( PWCPaymentMethod::class, $container->get( 'ppcp-local-apms.pwc.payment-method' ) );
		$this->assertIsBool( $container->get( 'ppcp-local-apms.eligibility.check' ) );
		$this->assertIsBool( $container->get( 'ppcp-local-apms.pwc.currency.check' ) );
		$this->assertIsBool( $container->get( 'ppcp-local-apms.pwc.eligibility.check' ) );
	}

	/**
	 * @testdox Should list Pay with Crypto as the one local payment method, for USD and every country.
	 */
	public function test_the_payment_methods_hold_the_pwc_entry_only(): void {
		$methods = $this->boot_container()->get( 'ppcp-local-apms.payment-methods' );

		$this->assertSame(
			array(
				'pwc' => array(
					'id'         => 'ppcp-pwc',
					'countries'  => array(),
					'currencies' => array( 'USD' ),
				),
			),
			$methods
		);
	}

	/**
	 * @testdox Should serve the module's own asset getter, scoped to the extension's local payment methods module name.
	 */
	public function test_the_asset_getter_is_scoped_to_the_extensions_module_name(): void {
		$getter = $this->boot_container()->get( 'ppcp-local-apms.asset_getter' );

		$this->assertStringEndsWith( 'ppcp-local-alternative-payment-methods-js-pwc-payment-method.js', $getter->get_asset_url( 'pwc-payment-method.js' ) );
	}

	/**
	 * The country rule and the currency rule, each over the merchant country and the shop currency the test gives.
	 *
	 * @return array[]
	 */
	public function eligibility_provider(): array {
		return array(
			'a US merchant and a USD shop' => array( 'US', 'USD', true, true, true ),
			'a RU merchant'                => array( 'RU', 'USD', false, true, false ),
			'a BR merchant'                => array( 'BR', 'USD', false, true, false ),
			'a JP merchant'                => array( 'JP', 'USD', false, true, false ),
			'a EUR shop'                   => array( 'DE', 'EUR', true, false, false ),
			'a RU merchant and a EUR shop' => array( 'RU', 'EUR', false, false, false ),
		);
	}

	/**
	 * @testdox Should be eligible only for a merchant outside RU, BR and JP and a shop in USD: $_dataName.
	 * @dataProvider eligibility_provider
	 *
	 * @param string $country          The merchant country.
	 * @param string $currency         The shop currency.
	 * @param bool   $country_ok       The expected answer of the country rule.
	 * @param bool   $currency_ok      The expected answer of the currency rule.
	 * @param bool   $eligible         The expected answer of the combined check.
	 */
	public function test_the_eligibility_checks( string $country, string $currency, bool $country_ok, bool $currency_ok, bool $eligible ): void {
		$container = $this->container_for( $country, $currency );
		$services  = $this->services();

		$this->assertSame( $country_ok, $services['ppcp-local-apms.eligibility.check']( $container ) );
		$this->assertSame( $currency_ok, $services['ppcp-local-apms.pwc.currency.check']( $container ) );
		$this->assertSame( $eligible, $services['ppcp-local-apms.pwc.eligibility.check']( $container ) );
	}
}
