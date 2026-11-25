<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsHtmlUtils class.
 */
class WooPaymentsHtmlUtilsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Interpolated HTML escapes everything except the mapped tags: $_dataName.
	 * @dataProvider provide_interpolated_html
	 *
	 * @param string               $source   Source string.
	 * @param array<string,string> $mapping  Tag mapping.
	 * @param string               $expected Escaped string.
	 */
	public function test_escape_interpolated_html( string $source, array $mapping, string $expected ): void {
		$this->assertSame( $expected, WooPaymentsHtmlUtils::escape_interpolated_html( $source, $mapping ) );
	}

	/**
	 * Source strings, tag mappings and the escaped result.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:string}>
	 */
	public function provide_interpolated_html(): array {
		return array(
			'unknown markup and text are escaped, mapped tags are preserved' => array(
				'<script>alert(1)</script> <strong>Paid & settled</strong>',
				array( 'strong' => '<strong>' ),
				'&lt;script&gt;alert(1)&lt;/script&gt; <strong>Paid &amp; settled</strong>',
			),
			'an invalid mapping escapes the complete source string'         => array(
				'<strong>Paid</strong>',
				array( 'strong' => 'not-an-html-element' ),
				'&lt;strong&gt;Paid&lt;/strong&gt;',
			),
		);
	}
}
