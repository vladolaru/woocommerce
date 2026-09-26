<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayBlocksDataExtractor;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayBlocksDataExtractor class.
 */
class WooPaymentsWooPayBlocksDataExtractorTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_checkout_page_id' );
		parent::tearDown();
	}

	/**
	 * @testdox Blocks data extractor returns data from supported checkout block integrations.
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
	 * @testdox Blocks data extractor maps checkout block optional field attrs to WooPay field status.
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
	 * Register fake Mailchimp blocks integration class.
	 */
	private function register_fake_mailchimp_blocks_integration(): void {
		if ( ! class_exists( '\Mailchimp_Woocommerce_Newsletter_Blocks_Integration', false ) ) {
			class_alias( FakeWooPayMailchimpBlocksIntegration::class, 'Mailchimp_Woocommerce_Newsletter_Blocks_Integration' );
		}
	}
}
