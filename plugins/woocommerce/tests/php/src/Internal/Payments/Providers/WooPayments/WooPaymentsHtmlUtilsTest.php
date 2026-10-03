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
	 * @testdox Interpolated HTML escapes unknown markup and text while preserving mapped tags.
	 */
	public function test_escape_interpolated_html_preserves_only_mapped_tags(): void {
		$this->assertSame(
			'&lt;script&gt;alert(1)&lt;/script&gt; <strong>Paid &amp; settled</strong>',
			WooPaymentsHtmlUtils::escape_interpolated_html(
				'<script>alert(1)</script> <strong>Paid & settled</strong>',
				array( 'strong' => '<strong>' )
			)
		);
	}

	/**
	 * @testdox Invalid interpolation mappings escape the complete source string.
	 */
	public function test_escape_interpolated_html_fails_closed_for_invalid_mapping(): void {
		$this->assertSame(
			'&lt;strong&gt;Paid&lt;/strong&gt;',
			WooPaymentsHtmlUtils::escape_interpolated_html(
				'<strong>Paid</strong>',
				array( 'strong' => 'not-an-html-element' )
			)
		);
	}
}
