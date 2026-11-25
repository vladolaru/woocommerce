<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalCardFormatter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTerminalCardFormatter class.
 */
class WooPaymentsTerminalCardFormatterTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Terminal-specific card brands use extension-compatible display names.
	 * @dataProvider terminal_brand_display_name_provider
	 *
	 * @param string $brand    Provider card brand.
	 * @param string $expected Expected display name.
	 */
	public function test_formats_terminal_specific_card_brands( string $brand, string $expected ): void {
		$this->assertSame( $expected, WooPaymentsTerminalCardFormatter::get_card_brand_display_name( $brand ) );
	}

	/**
	 * Data provider for terminal-specific card brand display names.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function terminal_brand_display_name_provider(): array {
		return array(
			'eftpos'              => array( 'eftpos', 'eftpos' ),
			'eftpos AU'           => array( 'eftpos_au', 'eftpos' ),
			'eftpos AU hyphen'    => array( 'eftpos-au', 'eftpos' ),
			'Cartes Bancaires'    => array( 'cartes_bancaires', 'Cartes Bancaires' ),
			'CB'                  => array( 'cb', 'Cartes Bancaires' ),
			'ordinary card brand' => array( 'visa', 'Visa' ),
		);
	}

	/**
	 * @testdox The terminal card display name follows the card network or brand: $_dataName.
	 * @dataProvider terminal_card_display_name_provider
	 *
	 * @param array<string,string> $card     Terminal card.
	 * @param string               $expected Expected display name.
	 */
	public function test_terminal_card_display_name( array $card, string $expected ): void {
		$this->assertSame( $expected, WooPaymentsTerminalCardFormatter::get_terminal_card_display_name( $card ) );
	}

	/**
	 * Data provider for terminal card display names.
	 *
	 * @return array<string,array{array<string,string>,string}>
	 */
	public function terminal_card_display_name_provider(): array {
		return array(
			'a recognized network takes precedence over the brand' => array(
				array(
					'brand'   => 'visa',
					'network' => 'eftpos_au',
				),
				'eftpos',
			),
			'an unknown network falls back to the brand' => array(
				array(
					'brand'   => 'visa',
					'network' => 'unsupported_network',
				),
				'Visa',
			),
		);
	}
}
