<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Properties\Properties;
use WC_Unit_Test_Case;

/**
 * Tests for the forked wallet's Modularity properties.
 */
class WalletPropertiesTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should keep the extension's base name and version, because inbox notes and the version option are shared with the extension.
	 */
	public function test_keeps_the_shared_identity(): void {
		$properties = WalletProperties::new();

		$this->assertInstanceOf( Properties::class, $properties );
		$this->assertSame( 'woocommerce-paypal-payments', $properties->baseName() );
		$this->assertSame( WalletProperties::EXTENSION_VERSION, $properties->version() );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', WalletProperties::EXTENSION_VERSION );
		$this->assertSame( 'woocommerce', $properties->textDomain() );
	}

	/**
	 * @testdox Should point the base path and URL at core's built assets for the wallet.
	 */
	public function test_points_at_the_built_assets(): void {
		$properties = WalletProperties::new();

		$this->assertSame( WC_ABSPATH . 'assets/client/paypal-wallet/', $properties->basePath() );
		$this->assertSame( WC()->plugin_url() . '/assets/client/paypal-wallet/', $properties->baseUrl() );
	}
}
