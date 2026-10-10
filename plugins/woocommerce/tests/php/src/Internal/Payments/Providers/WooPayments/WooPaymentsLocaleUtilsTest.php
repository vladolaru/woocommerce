<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLocaleUtils;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsLocaleUtils class.
 */
class WooPaymentsLocaleUtilsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should convert WordPress locales to the closest Stripe-supported locale like the reference client
	 * @dataProvider provider_locales
	 *
	 * @param string $wp_locale     WordPress locale.
	 * @param string $stripe_locale Expected Stripe locale.
	 */
	public function test_converts_locale_to_stripe_supported_locale( string $wp_locale, string $stripe_locale ): void {
		$this->assertSame( $stripe_locale, WooPaymentsLocaleUtils::convert_to_stripe_locale( $wp_locale ) );
	}

	/**
	 * Locale conversion cases mirroring the reference client's convert_to_stripe_locale().
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provider_locales(): array {
		return array(
			'base language kept'              => array( 'de', 'de' ),
			'unsupported region to base'      => array( 'de_DE', 'de' ),
			'US English to base'              => array( 'en_US', 'en' ),
			'supported region case preserved' => array( 'en_GB', 'en-GB' ),
			'Brazilian Portuguese preserved'  => array( 'pt_BR', 'pt-BR' ),
			'Canadian French preserved'       => array( 'fr_CA', 'fr-CA' ),
			'Latin American Spanish'          => array( 'es_419', 'es-419' ),
			'Traditional Chinese preserved'   => array( 'zh_TW', 'zh-TW' ),
			'three-letter language kept'      => array( 'fil', 'fil' ),
			'unknown locale falls to auto'    => array( 'xx_YY', 'auto' ),
			'empty locale falls to auto'      => array( '', 'auto' ),
		);
	}

	/**
	 * @testdox Should give Stripe the site locale, following a locale switch, as the client's get_locale() reads do
	 */
	public function test_get_stripe_locale_reads_the_site_locale(): void {
		switch_to_locale( 'de_DE' );

		try {
			$this->assertSame( 'de', WooPaymentsLocaleUtils::get_stripe_locale() );
		} finally {
			restore_previous_locale();
		}
	}
}
