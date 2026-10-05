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
	 * @testdox Should point the base path and URL at the wallet's directory of the blocks build.
	 */
	public function test_points_at_the_built_assets(): void {
		$properties = WalletProperties::new();

		$this->assertSame( WC_ABSPATH . 'assets/client/blocks/paypal-wallet/', $properties->basePath() );
		$this->assertSame( WC()->plugin_url() . '/assets/client/blocks/paypal-wallet/', $properties->baseUrl() );
	}

	/**
	 * The modules register each block from these directories; this checks where the blocks build copies the metadata, not the modules' strings.
	 *
	 * @testdox Should find each wallet block's metadata in a directory named after the block under the base path, where the blocks build copies it.
	 * @testWith ["product-smart-buttons", "woocommerce-paypal-payments/product-smart-buttons"]
	 *           ["product-paylater-messages", "woocommerce-paypal-payments/product-paylater-messages"]
	 *           ["paylater-messages", "woocommerce-paypal-payments/paylater-messages"]
	 *           ["cart-paylater-messages", "woocommerce-paypal-payments/cart-paylater-messages"]
	 *           ["checkout-paylater-messages", "woocommerce-paypal-payments/checkout-paylater-messages"]
	 *
	 * @param string $directory  The block's directory relative to the base path.
	 * @param string $block_name The block name its metadata declares.
	 */
	public function test_finds_the_block_metadata_under_the_base_path( string $directory, string $block_name ): void {
		$file = WalletProperties::new()->basePath() . $directory . '/block.json';

		$this->assertFileExists( $file );
		$metadata = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local build file.
		$this->assertSame( $block_name, $metadata['name'] ?? null );
	}
}
