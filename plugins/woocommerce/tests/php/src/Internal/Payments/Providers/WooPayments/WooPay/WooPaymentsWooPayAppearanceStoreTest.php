<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayAppearanceStore class.
 */
class WooPaymentsWooPayAppearanceStoreTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture;

	/**
	 * @testdox On a classic theme with nothing stored, WooPay gets no appearance.
	 */
	public function test_classic_theme_gives_woopay_no_computed_appearance(): void {
		$original_theme = get_stylesheet();
		$sut            = $this->create_appearance_store(
			array( 'is_woopay_global_theme_support_enabled' => 'yes' ),
			array( 'platform_global_theme_support_enabled' => true )
		);

		try {
			switch_theme( 'storefront' );
			$appearance = $sut->get_woopay_appearance();
		} finally {
			switch_theme( $original_theme );
		}

		$this->assertSame( array(), $appearance );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
	}

	/**
	 * @testdox Should store WooPay checkout appearance in the WooPayments extension's option shape.
	 */
	public function test_stores_appearance_in_the_woopayments_extension_option_shape(): void {
		$sut        = $this->create_appearance_store();
		$appearance = $this->get_valid_appearance();
		$font_rules = array(
			array(
				'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
			),
		);

		$sut->save_woopay_appearance( $appearance, $font_rules );

		$stored = get_option( 'wcpay_woopay_checkout_appearance' );
		$this->assertIsArray( $stored );
		$this->assertSame( $appearance, $stored['appearance'] );
		$this->assertSame( $font_rules, $stored['font_rules'] );
		$this->assertNotEmpty( $stored['version'] );
		$this->assertSame( $appearance, $sut->get_woopay_appearance() );
		$this->assertSame( $font_rules, $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should ignore stale WooPay appearance data when the styles cache version changes.
	 */
	public function test_ignores_stale_appearance_when_styles_cache_version_changes(): void {
		$sut        = $this->create_appearance_store();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		$sut->save_woopay_appearance(
			$appearance,
			array(
				array(
					'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
				),
			)
		);

		update_option( 'wcpay_styles_cache_version', 'version-two' );

		$this->assertSame( array(), $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
		$this->assertTrue(
			$sut->maybe_save_woopay_appearance(
				array(
					'theme'     => 'night',
					'variables' => array(
						'colorText' => '#ffffff',
					),
				)
			)
		);
	}

	/**
	 * An appearance the WooPayments extension stored for the current styles cache version is still valid, as client 11.1.0
	 * reads it (includes/class-wc-payments-styles-cache.php:69-75), so a store moving from the extension keeps it.
	 *
	 * @testdox Should keep an appearance stored for the current styles cache version.
	 */
	public function test_keeps_appearance_stored_for_the_current_styles_cache_version(): void {
		$sut        = $this->create_appearance_store();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		update_option(
			'wcpay_woopay_checkout_appearance',
			array(
				'appearance' => $appearance,
				'font_rules' => array(),
				'version'    => 'version-one',
			)
		);

		$this->assertSame( $appearance, $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should conditionally store shopper appearance only when no appearance exists.
	 */
	public function test_conditionally_stores_shopper_appearance_only_once(): void {
		$sut    = $this->create_appearance_store();
		$first  = $this->get_valid_appearance();
		$second = array(
			'theme'     => 'night',
			'variables' => array(
				'colorText' => '#ffffff',
			),
		);

		$this->assertTrue( $sut->maybe_save_woopay_appearance( $first ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( $second ) );
		$this->assertSame( $first, $sut->get_woopay_appearance() );
	}

	/**
	 * @testdox Should reject invalid WooPay appearance schema.
	 */
	public function test_rejects_invalid_appearance_schema(): void {
		$sut = $this->create_appearance_store();

		$this->assertFalse( $sut->validate_appearance_schema( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
	}

	/**
	 * @testdox Font rules keep only https URLs on the allowed font hosts, at most ten: $_dataName.
	 *
	 * Client 11.1.0 WooPay_Session::sanitize_font_rules() (includes/woopay/class-woopay-session.php:652-669) with
	 * WC_Payments_Styles_Cache::ALLOWED_FONT_DOMAINS (includes/class-wc-payments-styles-cache.php:30-36).
	 *
	 * @dataProvider font_rule_cases
	 *
	 * @param array<int,mixed>                $font_rules Posted font rules.
	 * @param array<int,array<string,string>> $expected   Font rules kept.
	 */
	public function test_font_rules_keep_only_https_urls_on_the_allowed_hosts( array $font_rules, array $expected ): void {
		$this->assertSame( $expected, $this->create_appearance_store()->sanitize_woopay_font_rules( $font_rules ) );
	}

	/**
	 * Posted font rules and the ones kept.
	 *
	 * @return array<string,array{0:array<int,mixed>,1:array<int,array<string,string>>}>
	 */
	public function font_rule_cases(): array {
		$allowed = array( 'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter' );

		return array(
			'an allowed host'                  => array( array( $allowed ), array( $allowed ) ),
			'another host'                     => array( array( array( 'cssSrc' => 'https://fonts.example.com/inter.css' ) ), array() ),
			'an allowed host name as a path'   => array( array( array( 'cssSrc' => 'https://fonts.example.com/fonts.gstatic.com/a.css' ) ), array() ),
			'http on an allowed host'          => array( array( array( 'cssSrc' => 'http://use.typekit.net/abc.css' ) ), array() ),
			'no cssSrc'                        => array( array( array( 'href' => 'https://fonts.bunny.net/a.css' ) ), array() ),
			'eleven rules, the first ten kept' => array( array_fill( 0, 11, $allowed ), array_fill( 0, 10, $allowed ) ),
		);
	}
}
