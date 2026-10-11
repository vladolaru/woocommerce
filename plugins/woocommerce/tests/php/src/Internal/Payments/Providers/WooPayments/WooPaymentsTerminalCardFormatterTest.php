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
	 * @testdox A recognized terminal network takes precedence over the card brand.
	 */
	public function test_prefers_a_recognized_terminal_network(): void {
		$this->assertSame(
			'eftpos',
			WooPaymentsTerminalCardFormatter::get_terminal_card_display_name(
				array(
					'brand'   => 'visa',
					'network' => 'eftpos_au',
				)
			)
		);
	}

	/**
	 * @testdox An unknown terminal network falls back to the card brand.
	 */
	public function test_uses_the_card_brand_for_an_unknown_network(): void {
		$this->assertSame(
			'Visa',
			WooPaymentsTerminalCardFormatter::get_terminal_card_display_name(
				array(
					'brand'   => 'visa',
					'network' => 'unsupported_network',
				)
			)
		);
	}
}
