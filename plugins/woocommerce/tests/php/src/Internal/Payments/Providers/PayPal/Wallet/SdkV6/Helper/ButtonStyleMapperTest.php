<?php
/**
 * Tests for the mapper from button settings to v6 web component styles.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\ButtonStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\LocationStylingDTO;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The color class and the border radius the v6 buttons get from the styling of a location.
 *
 * @group paypal-wallet
 */
class ButtonStyleMapperTest extends WalletTestCase {

	/**
	 * Define the DTO aliases the way the shell does before it boots the wallet.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';
	}

	/**
	 * A mapper over a settings provider that serves the given styling for the product location.
	 *
	 * @param string $color The configured color.
	 * @param string $shape The configured shape.
	 * @return ButtonStyleMapper
	 */
	private function mapper_for( string $color, string $shape ): ButtonStyleMapper {
		$dto = new LocationStylingDTO( 'product', true, array(), $shape, 'pay', $color );

		$provider = $this->mock( SettingsProvider::class );
		$provider->shouldReceive( 'button_styling' )->with( 'product' )->andReturn( $dto );

		return new ButtonStyleMapper( $provider );
	}

	/**
	 * @testdox Should map color "$color" and shape "$shape" to the class "$expected_class" and the radius "$expected_radius".
	 * @dataProvider color_shape_data
	 *
	 * @param string $color          The configured color.
	 * @param string $shape          The configured shape.
	 * @param string $expected_class The v6 color class.
	 * @param string $expected_radius The v6 border radius.
	 */
	public function test_maps_colors_and_shapes_to_web_component_styles( string $color, string $shape, string $expected_class, string $expected_radius ): void {
		$result = $this->mapper_for( $color, $shape )->styles_for_context( 'product' );

		$this->assertSame( $expected_class, $result['colorClass'] );
		$this->assertSame( $expected_radius, $result['borderRadius'] );
	}

	/**
	 * Colors and shapes with the styles they map to.
	 *
	 * @return array
	 */
	public function color_shape_data(): array {
		return array(
			'silver maps to white' => array( 'silver', 'rect', 'paypal-white', '4px' ),
			'unknown color'        => array( 'rainbow', 'pill', 'paypal-gold', '24px' ),
			'unknown shape'        => array( 'gold', 'hexagon', 'paypal-gold', '24px' ),
		);
	}

	/**
	 * @testdox Should fall back to the gold color and the pill shape when the styling holds empty values.
	 */
	public function test_empty_dto_values_fall_back_to_defaults(): void {
		$result = $this->mapper_for( '', '' )->styles_for_context( 'product' );

		$this->assertSame( 'paypal-gold', $result['colorClass'] );
		$this->assertSame( '24px', $result['borderRadius'] );
	}

	/**
	 * @testdox Should not expose a height style, because the styling holds no height.
	 */
	public function test_does_not_expose_a_height_style(): void {
		$result = $this->mapper_for( 'gold', 'pill' )->styles_for_context( 'product' );

		$this->assertSame( array( 'colorClass', 'borderRadius' ), array_keys( $result ) );
	}
}
