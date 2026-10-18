<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayBlocksDataExtractor;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayBlocksDataExtractor class.
 */
class WooPaymentsWooPayBlocksDataExtractorTest extends WC_Unit_Test_Case {

	/** Checkout field settings the Checkout block sidebar writes. */
	private const FIELD_OPTIONS = array( 'woocommerce_checkout_company_field', 'woocommerce_checkout_address_2_field', 'woocommerce_checkout_phone_field' );

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_checkout_page_id' );
		foreach ( self::FIELD_OPTIONS as $option ) {
			delete_option( $option );
		}
		parent::tearDown();
	}

	/**
	 * Set up test fixtures: no checkout field setting stored.
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( self::FIELD_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * @testdox Blocks data extractor returns data from supported checkout block integrations.
	 *
	 * A separate process: the extension class alias lives for the whole PHP process, and later WooPay tests must not see
	 * the extension as active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_data_extracts_supported_checkout_block_script_data(): void {
		$this->register_fake_mailchimp_blocks_integration();

		$this->assertTrue( class_exists( WooPaymentsWooPayBlocksDataExtractor::class ), 'WooPaymentsWooPayBlocksDataExtractor should exist.' );

		$extractor = new WooPaymentsWooPayBlocksDataExtractor();

		$this->assertSame(
			array(
				'mailchimp-newsletter_data' => array(
					'enabled' => true,
					'source'  => 'fake-mailchimp',
				),
			),
			$extractor->get_data()
		);
	}

	/**
	 * @testdox Blocks data extractor maps an unmigrated Checkout block's field attributes to WooPay field status through the checkout field settings.
	 */
	public function test_get_optional_fields_status_reads_checkout_block_attrs(): void {
		$this->assertTrue( class_exists( WooPaymentsWooPayBlocksDataExtractor::class ), 'WooPaymentsWooPayBlocksDataExtractor should exist.' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:woocommerce/checkout {"showCompanyField":true,"requireCompanyField":true,"showApartmentField":false,"showPhoneField":false} --><div class="wp-block-woocommerce-checkout"><!-- wp:woocommerce/checkout-fields-block --><div><!-- wp:woocommerce/checkout-terms-block {"checkbox":true,"text":"Accept custom terms"} /--></div><!-- /wp:woocommerce/checkout-fields-block --></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );

		$extractor = new WooPaymentsWooPayBlocksDataExtractor();

		$this->assertSame(
			array(
				'company'        => 'required',
				'address_2'      => 'hidden',
				'phone'          => 'hidden',
				'terms_checkbox' => true,
				'custom_terms'   => 'Accept custom terms',
			),
			$extractor->get_optional_fields_status()
		);
	}

	/**
	 * @testdox WooPay's field status follows the checkout field settings on a block checkout: $_dataName.
	 *
	 * The Checkout block sidebar writes these settings and the checkout's required flags read them (CartCheckoutUtils,
	 * CoreCheckoutFields), whatever attributes the block still carries.
	 *
	 * @dataProvider provide_checkout_field_settings
	 *
	 * @param string               $block_comment Opening comment of the Checkout block, attributes included.
	 * @param array<string,string> $settings      Stored field settings, by option name.
	 * @param array<string,string> $expected      Expected company, address_2 and phone status.
	 */
	public function test_get_optional_fields_status_follows_the_checkout_field_settings( string $block_comment, array $settings, array $expected ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $block_comment . '<div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		foreach ( $settings as $option => $value ) {
			update_option( $option, $value );
		}

		$status = ( new WooPaymentsWooPayBlocksDataExtractor() )->get_optional_fields_status();

		$this->assertSame( $expected, array_intersect_key( $status, $expected ) );
	}

	/**
	 * Block checkouts with stored field settings: the block's opening comment, the settings, the expected status.
	 *
	 * @return array<string,array{string,array<string,string>,array<string,string>}>
	 */
	public function provide_checkout_field_settings(): array {
		$settings = static fn( string $company, string $address_2, string $phone ): array => array(
			'woocommerce_checkout_company_field'   => $company,
			'woocommerce_checkout_address_2_field' => $address_2,
			'woocommerce_checkout_phone_field'     => $phone,
		);
		$expected = static fn( string $company, string $address_2, string $phone ): array => array(
			'company'   => $company,
			'address_2' => $address_2,
			'phone'     => $phone,
		);

		return array(
			'phone set to Required, block without attributes' => array( '<!-- wp:woocommerce/checkout -->', $settings( 'hidden', 'optional', 'required' ), $expected( 'hidden', 'optional', 'required' ) ),
			'company set to Required, block with old attributes' => array( '<!-- wp:woocommerce/checkout {"showCompanyField":true} -->', $settings( 'required', 'optional', 'optional' ), $expected( 'required', 'optional', 'optional' ) ),
			'address line 2 set to Required' => array( '<!-- wp:woocommerce/checkout -->', $settings( 'hidden', 'required', 'optional' ), $expected( 'hidden', 'required', 'optional' ) ),
		);
	}

	/**
	 * Register fake Mailchimp blocks integration class.
	 */
	private function register_fake_mailchimp_blocks_integration(): void {
		if ( ! class_exists( '\Mailchimp_Woocommerce_Newsletter_Blocks_Integration', false ) ) {
			class_alias( FakeWooPayMailchimpBlocksIntegration::class, 'Mailchimp_Woocommerce_Newsletter_Blocks_Integration' );
		}
	}
}
