<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\BlocksDataExtractor;
use WC_Unit_Test_Case;

/**
 * Tests for the BlocksDataExtractor class.
 */
class BlocksDataExtractorTest extends WC_Unit_Test_Case {

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
	 * @testdox WooPay's field status follows the checkout field settings on a block checkout, and its terms the terms block: $_dataName.
	 *
	 * The Checkout block sidebar writes these settings and the checkout's required flags read them (CartCheckoutUtils,
	 * CoreCheckoutFields), whatever attributes the block still carries. The terms checkbox and text come from the terms
	 * block inside the checkout fields block.
	 *
	 * @dataProvider provide_checkout_field_settings
	 *
	 * @param string               $content  Checkout page content: the Checkout block, its attributes and inner blocks.
	 * @param array<string,string> $settings Stored field settings, by option name.
	 * @param array<string,mixed>  $expected Expected status, by key.
	 */
	public function test_get_optional_fields_status_follows_the_checkout_field_settings( string $content, array $settings, array $expected ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		foreach ( $settings as $option => $value ) {
			update_option( $option, $value );
		}

		$status = ( new BlocksDataExtractor() )->get_optional_fields_status();

		$this->assertSame( $expected, $status );
	}

	/**
	 * Block checkouts with stored field settings: the page content, the settings, the whole expected status.
	 *
	 * @return array<string,array{string,array<string,string>,array<string,mixed>}>
	 */
	public function provide_checkout_field_settings(): array {
		$page     = static fn( string $opening_comment, string $inner_blocks = '' ): string => $opening_comment . '<div class="wp-block-woocommerce-checkout">' . $inner_blocks . '</div><!-- /wp:woocommerce/checkout -->';
		$settings = static fn( string $company, string $address_2, string $phone ): array => array(
			'woocommerce_checkout_company_field'   => $company,
			'woocommerce_checkout_address_2_field' => $address_2,
			'woocommerce_checkout_phone_field'     => $phone,
		);

		return array(
			'phone set to Required, block without attributes' => array(
				$page( '<!-- wp:woocommerce/checkout -->' ),
				$settings( 'hidden', 'optional', 'required' ),
				array(
					'company'        => 'hidden',
					'address_2'      => 'optional',
					'phone'          => 'required',
					'terms_checkbox' => false,
					'custom_terms'   => '',
				),
			),
			'company set to Required, block with old attributes' => array(
				$page( '<!-- wp:woocommerce/checkout {"showCompanyField":true} -->' ),
				$settings( 'required', 'optional', 'optional' ),
				array(
					'company'        => 'required',
					'address_2'      => 'optional',
					'phone'          => 'optional',
					'terms_checkbox' => false,
					'custom_terms'   => '',
				),
			),
			'address line 2 set to Required' => array(
				$page( '<!-- wp:woocommerce/checkout -->' ),
				$settings( 'hidden', 'required', 'optional' ),
				array(
					'company'        => 'hidden',
					'address_2'      => 'required',
					'phone'          => 'optional',
					'terms_checkbox' => false,
					'custom_terms'   => '',
				),
			),
			'no settings, unmigrated attributes and a terms block' => array(
				$page(
					'<!-- wp:woocommerce/checkout {"showCompanyField":true,"requireCompanyField":true,"showApartmentField":false,"showPhoneField":false} -->',
					'<!-- wp:woocommerce/checkout-fields-block --><div><!-- wp:woocommerce/checkout-terms-block {"checkbox":true,"text":"Accept custom terms"} /--></div><!-- /wp:woocommerce/checkout-fields-block -->'
				),
				array(),
				array(
					'company'        => 'required',
					'address_2'      => 'hidden',
					'phone'          => 'hidden',
					'terms_checkbox' => true,
					'custom_terms'   => 'Accept custom terms',
				),
			),
		);
	}
}
