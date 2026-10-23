<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\LocationStylingDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\OAuthConnectionDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\PayLaterMessagingDTO;
use WC_Unit_Test_Case;

/**
 * The DTOs the wallet stores as PHP objects keep the extension's class names, so stored data stays readable both ways.
 */
class SerializedClassesTest extends WC_Unit_Test_Case {

	/**
	 * Old class names, as the extension stores them, mapped to the wallet-namespace aliases the forked code uses.
	 */
	private const CLASSES = array(
		'WooCommerce\\PayPalCommerce\\Settings\\DTO\\LocationStylingDTO'   => LocationStylingDTO::class,
		'WooCommerce\\PayPalCommerce\\Settings\\DTO\\PayLaterMessagingDTO' => PayLaterMessagingDTO::class,
		'WooCommerce\\PayPalCommerce\\Settings\\DTO\\OAuthConnectionDTO'   => OAuthConnectionDTO::class,
	);

	/**
	 * Define the classes the way the shell does before it boots the wallet.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';
	}

	/**
	 * @testdox Should create the original class when the wallet-namespace alias is instantiated.
	 */
	public function test_alias_creates_the_original_class(): void {
		foreach ( self::CLASSES as $original => $alias ) {
			$this->assertTrue( class_exists( $alias, false ), "$alias must be defined by the loader" );
			$this->assertSame( $original, ( new \ReflectionClass( $alias ) )->getName(), "$alias must be an alias of the extension's class name" );
		}

		$this->assertSame( 'WooCommerce\\PayPalCommerce\\Settings\\DTO\\LocationStylingDTO', get_class( new LocationStylingDTO( 'cart' ) ) );
		$this->assertSame( 'WooCommerce\\PayPalCommerce\\Settings\\DTO\\PayLaterMessagingDTO', get_class( new PayLaterMessagingDTO( 'cart' ) ) );
		$this->assertSame( 'WooCommerce\\PayPalCommerce\\Settings\\DTO\\OAuthConnectionDTO', get_class( new OAuthConnectionDTO( true, 'shared-id', 'auth-token' ) ) );
	}

	/**
	 * @testdox Should serialize with the extension's class name, so the extension can read what core writes.
	 */
	public function test_serialize_writes_the_original_class_name(): void {
		$serialized = serialize( new LocationStylingDTO( 'cart', true, array( 'ppcp-gateway', 'venmo' ), 'pill', 'buynow', 'blue', 'horizontal', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Checking the stored format.

		$this->assertStringStartsWith( 'O:58:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\LocationStylingDTO":8:{', $serialized );
		$this->assertStringNotContainsString( 'Automattic', $serialized );
	}

	/**
	 * @testdox Should read objects the extension stored, which is the direction that failed with an incomplete class.
	 */
	public function test_unserialize_reads_what_the_extension_stored(): void {
		$styling = unserialize( 'O:58:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\LocationStylingDTO":8:{s:8:"location";s:4:"cart";s:7:"enabled";b:1;s:7:"methods";a:2:{i:0;s:12:"ppcp-gateway";i:1;s:5:"venmo";}s:5:"shape";s:4:"pill";s:5:"label";s:6:"buynow";s:5:"color";s:4:"blue";s:6:"layout";s:10:"horizontal";s:7:"tagline";b:1;}' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fixture of the stored format.
		$this->assertInstanceOf( LocationStylingDTO::class, $styling );
		$this->assertSame( 'cart', $styling->location );
		$this->assertSame( array( 'ppcp-gateway', 'venmo' ), $styling->methods );
		$this->assertSame( 'pill', $styling->shape );

		$messaging = unserialize( 'O:60:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\PayLaterMessagingDTO":9:{s:8:"location";s:4:"cart";s:7:"enabled";b:1;s:6:"layout";s:4:"flex";s:9:"logo_type";s:6:"inline";s:13:"logo_position";s:4:"left";s:10:"text_color";s:5:"black";s:9:"text_size";s:2:"12";s:10:"flex_color";s:4:"blue";s:10:"flex_ratio";s:3:"8x1";}' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fixture of the stored format.
		$this->assertInstanceOf( PayLaterMessagingDTO::class, $messaging );
		$this->assertSame( 'flex', $messaging->layout );
		$this->assertSame( '8x1', $messaging->flex_ratio );

		$oauth = unserialize( 'O:58:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\OAuthConnectionDTO":4:{s:10:"is_sandbox";b:1;s:9:"shared_id";s:9:"shared-id";s:10:"auth_token";s:10:"auth-token";s:9:"timestamp";i:1791032830;}' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fixture of the stored format.
		$this->assertInstanceOf( OAuthConnectionDTO::class, $oauth );
		$this->assertSame( 'shared-id', $oauth->shared_id );
		$this->assertSame( 1791032830, $oauth->timestamp );
	}

	/**
	 * @testdox Should keep the definitions out of Composer's optimized classmap, so only the loader defines them.
	 */
	public function test_definitions_are_not_in_the_composer_classmap(): void {
		$map = require WC_ABSPATH . 'vendor/composer/autoload_classmap.php';

		foreach ( array_keys( self::CLASSES ) as $original ) {
			$this->assertArrayNotHasKey( $original, $map, "$original must only be defined by the loader" );
		}
		$this->assertDirectoryExists( WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses' );
		$this->assertFileDoesNotExist( WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/Settings/DTO/LocationStylingDTO.php' );
	}
}
