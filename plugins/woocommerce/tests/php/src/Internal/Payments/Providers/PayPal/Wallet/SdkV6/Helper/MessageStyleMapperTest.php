<?php
/**
 * Tests for the mapper from Pay Later messaging settings to v6 web component attributes.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessageStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The logo, the text color and the font size the v6 messaging component gets from the style of a location.
 *
 * @group paypal-wallet
 */
class MessageStyleMapperTest extends WalletTestCase {

	private const LOCATION = 'checkout';

	/**
	 * A mapper over a settings provider that serves the given style for the checkout location.
	 *
	 * @param array $style The messaging style of the location.
	 * @return MessageStyleMapper
	 */
	private function mapper_for( array $style ): MessageStyleMapper {
		$provider = $this->mock( SettingsProvider::class );
		$provider->shouldReceive( 'pay_later_messaging_style' )->with( self::LOCATION )->andReturn( $style );

		return new MessageStyleMapper( $provider );
	}

	/**
	 * @testdox Should map the logo type "$logo_type" at "$logo_position" to $expected_logo_type at $expected_logo_position.
	 * @dataProvider logo_type_data
	 *
	 * @param string $logo_type              The configured logo type.
	 * @param string $logo_position          The configured logo position.
	 * @param string $expected_logo_type     The v6 logo type.
	 * @param string $expected_logo_position The v6 logo position.
	 */
	public function test_maps_logo_types_and_inline_position( string $logo_type, string $logo_position, string $expected_logo_type, string $expected_logo_position ): void {
		$result = $this->mapper_for(
			array(
				'logo_type'     => $logo_type,
				'logo_position' => $logo_position,
				'text_color'    => 'black',
				'text_size'     => '',
			)
		)->styles_for_location( self::LOCATION );

		$this->assertSame( $expected_logo_type, $result['logoType'] );
		$this->assertSame( $expected_logo_position, $result['logoPosition'] );
	}

	/**
	 * Logo types and positions with the values they map to.
	 *
	 * @return array
	 */
	public function logo_type_data(): array {
		return array(
			'primary maps to WORDMARK, keeps configured position' => array( 'primary', 'left', 'WORDMARK', 'LEFT' ),
			'alternative maps to MONOGRAM, keeps configured position' => array( 'alternative', 'right', 'MONOGRAM', 'RIGHT' ),
			'none maps to TEXT, keeps configured position' => array( 'none', 'top', 'TEXT', 'TOP' ),
			'inline maps to WORDMARK and forces INLINE position' => array( 'inline', 'right', 'WORDMARK', 'INLINE' ),
		);
	}

	/**
	 * @testdox Should map the text color "$text_color" to $expected.
	 * @dataProvider text_color_data
	 *
	 * @param string $text_color The configured text color.
	 * @param string $expected   The v6 text color.
	 */
	public function test_maps_text_colors( string $text_color, string $expected ): void {
		$result = $this->mapper_for(
			array(
				'logo_type'     => 'primary',
				'logo_position' => 'left',
				'text_color'    => $text_color,
				'text_size'     => '',
			)
		)->styles_for_location( self::LOCATION );

		$this->assertSame( $expected, $result['textColor'] );
	}

	/**
	 * Text colors with the values they map to. The v5-only grayscale maps onto the nearest v6 neighbour.
	 *
	 * @return array
	 */
	public function text_color_data(): array {
		return array(
			'black maps to BLACK'           => array( 'black', 'BLACK' ),
			'white maps to WHITE'           => array( 'white', 'WHITE' ),
			'monochrome maps to MONOCHROME' => array( 'monochrome', 'MONOCHROME' ),
			'grayscale maps to MONOCHROME'  => array( 'grayscale', 'MONOCHROME' ),
		);
	}

	/**
	 * @testdox Should turn the text size "$text_size" into the font size "$expected", clamped into the 10 to 16px range of the component.
	 * @dataProvider text_size_data
	 *
	 * @param string $text_size The configured text size.
	 * @param string $expected  The font size.
	 */
	public function test_clamps_text_size_into_supported_range( string $text_size, string $expected ): void {
		$result = $this->mapper_for(
			array(
				'logo_type'     => 'primary',
				'logo_position' => 'left',
				'text_color'    => 'black',
				'text_size'     => $text_size,
			)
		)->styles_for_location( self::LOCATION );

		$this->assertSame( $expected, $result['fontSize'] );
	}

	/**
	 * Text sizes with the font sizes they map to.
	 *
	 * @return array
	 */
	public function text_size_data(): array {
		return array(
			'below the minimum is clamped up to 10px'   => array( '8', '10px' ),
			'within range is kept as-is'                => array( '12', '12px' ),
			'above the maximum is clamped down to 16px' => array( '20', '16px' ),
			'empty value yields no font size'           => array( '', '' ),
			'non-numeric value yields no font size'     => array( 'abc', '' ),
		);
	}

	/**
	 * @testdox Should return ordinary text message styles for a banner (flex) layout, because v6 has no banner layout and the flex-only keys are never read.
	 */
	public function test_flex_layout_settings_are_ignored_and_text_styles_are_returned(): void {
		$result = $this->mapper_for(
			array(
				'layout'        => 'flex',
				'flex_color'    => 'blue',
				'ratio'         => '8x1',
				'logo_type'     => 'alternative',
				'logo_position' => 'top',
				'text_color'    => 'white',
				'text_size'     => '14',
			)
		)->styles_for_location( self::LOCATION );

		$this->assertSame(
			array(
				'logoType'     => 'MONOGRAM',
				'logoPosition' => 'TOP',
				'textColor'    => 'WHITE',
				'fontSize'     => '14px',
			),
			$result
		);
	}

	/**
	 * @testdox Should fall back to WORDMARK, LEFT and BLACK when every mapped setting holds an unrecognised value.
	 */
	public function test_unknown_values_fall_back_to_defaults(): void {
		$result = $this->mapper_for(
			array(
				'logo_type'     => 'bogus',
				'logo_position' => 'bogus',
				'text_color'    => 'bogus',
				'text_size'     => '',
			)
		)->styles_for_location( self::LOCATION );

		$this->assertSame( 'WORDMARK', $result['logoType'] );
		$this->assertSame( 'LEFT', $result['logoPosition'] );
		$this->assertSame( 'BLACK', $result['textColor'] );
	}
}
