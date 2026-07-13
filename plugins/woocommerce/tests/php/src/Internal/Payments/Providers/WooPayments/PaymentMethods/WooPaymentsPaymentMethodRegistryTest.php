<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\PaymentMethods;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments payment method definition registry.
 */
class WooPaymentsPaymentMethodRegistryTest extends WC_Unit_Test_Case {

	private const EXPECTED_DEFINITION_IDS = array(
		'card',
		'affirm',
		'afterpay_clearpay',
		'alipay',
		'bancontact',
		'au_becs_debit',
		'eps',
		'grabpay',
		'ideal',
		'link',
		'multibanco',
		'klarna',
		'p24',
		'sepa_debit',
		'wechat_pay',
		'apple_pay',
		'google_pay',
		'amazon_pay',
	);

	/**
	 * Original store currency option.
	 *
	 * @var mixed
	 */
	private $original_currency;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $registry;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_currency = get_option( 'woocommerce_currency', null );
		$this->registry          = new WooPaymentsPaymentMethodRegistry();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_upe_available_payment_methods' );

		if ( null === $this->original_currency ) {
			delete_option( 'woocommerce_currency' );
		} else {
			update_option( 'woocommerce_currency', $this->original_currency );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Registry exposes the non-deprecated extension payment method definition order.
	 */
	public function test_registry_exposes_non_deprecated_extension_definition_order(): void {
		$this->assertSame( self::EXPECTED_DEFINITION_IDS, array_keys( $this->registry->get_all() ) );
		$this->assertNull( $this->registry->get( 'giropay' ), 'giropay is deprecated and should not be registered natively.' );
		$this->assertNull( $this->registry->get( 'sofort' ), 'Sofort is deprecated and should not be registered natively.' );
		$this->assertNull( $this->registry->get( 'jcb' ), 'The standalone extension has no JCB payment method definition.' );
	}

	/**
	 * @testdox Availability filter receives one ordered ID-list argument exactly once and controls the catalog.
	 */
	public function test_availability_filter_receives_oracle_shape_once_and_controls_catalog(): void {
		$filter_calls = 0;
		$filter_args  = array();
		add_filter(
			'wcpay_upe_available_payment_methods',
			static function ( array $payment_method_ids ) use ( &$filter_calls, &$filter_args ): array {
				++$filter_calls;
				$filter_args = func_get_args();

				return array_diff( $payment_method_ids, array( 'bancontact' ) );
			},
			10,
			99
		);

		$available_definitions = $this->registry->get_all();

		$this->assertSame( 1, $filter_calls, 'One registry catalog computation should dispatch the availability filter once.' );
		$this->assertCount( 1, $filter_args, 'The pinned oracle passes no gateway or context argument.' );
		$this->assertSame( self::EXPECTED_DEFINITION_IDS, $filter_args[0], 'The filter should receive every definition ID in registry order.' );
		$this->assertArrayNotHasKey( 'bancontact', $available_definitions, 'A filtered method should not remain in the available definition catalog.' );
		$this->assertSame(
			array_values( array_diff( self::EXPECTED_DEFINITION_IDS, array( 'bancontact' ) ) ),
			array_keys( $available_definitions ),
			'The remaining definition order should follow the normalized filtered ID list.'
		);
	}

	/**
	 * @testdox Availability filter preserves the oracle TypeError for a non-array return.
	 */
	public function test_availability_filter_rejects_non_array_return_like_oracle(): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn() => false
		);

		$this->expectException( \TypeError::class );

		$this->registry->get_all();
	}

	/**
	 * @testdox Definitions preserve the extension contract values used by settings and checkout.
	 *
	 * @dataProvider payment_method_definition_provider
	 *
	 * @param string              $payment_method_id Payment method ID.
	 * @param array<string,mixed> $expected          Expected definition values.
	 */
	public function test_definitions_preserve_extension_contract_values( string $payment_method_id, array $expected ): void {
		$definition = $this->registry->get( $payment_method_id );

		$this->assertNotNull( $definition );
		$this->assertSame( $payment_method_id, $definition->get_id() );
		$this->assertSame( $expected['keywords'], $definition->get_keywords() );
		$this->assertSame( $expected['stripe_id'], $definition->get_stripe_id() );
		$this->assertSame( $expected['stripe_payment_method_type'], $definition->get_stripe_payment_method_type() );
		$this->assertSame( $expected['title'], $definition->get_title() );
		$this->assertSame( $expected['settings_label'], $definition->get_settings_label() );
		$this->assertSame( $expected['description'], $definition->get_description() );
		$this->assertSame( $expected['currencies'], $definition->get_supported_currencies() );
		$this->assertSame( $expected['countries'], $definition->get_supported_countries() );
		$this->assertSame( $expected['capabilities'], $definition->get_capabilities() );
		$this->assertSame( $expected['icon'], $definition->get_icon_asset_path() );
		$this->assertSame( $expected['dark_icon'], $definition->get_dark_icon_asset_path() );
		$this->assertSame( $expected['settings_icon'], $definition->get_settings_icon_asset_path() );
	}

	/**
	 * @testdox Definitions keep account capability ownership separate from public gateway publication.
	 */
	public function test_definitions_expose_gateway_publication_and_account_capability_ownership(): void {
		$link       = $this->registry->get( 'link' );
		$apple_pay  = $this->registry->get( 'apple_pay' );
		$google_pay = $this->registry->get( 'google_pay' );
		$klarna     = $this->registry->get( 'klarna' );

		$this->assertNotNull( $link );
		$this->assertFalse( $link->should_publish_gateway() );
		$this->assertSame( 'link_payments', $link->get_account_capability_key() );

		$this->assertNotNull( $apple_pay );
		$this->assertTrue( $apple_pay->should_publish_gateway() );
		$this->assertSame( 'card_payments', $apple_pay->get_account_capability_key() );

		$this->assertNotNull( $google_pay );
		$this->assertTrue( $google_pay->should_publish_gateway() );
		$this->assertSame( 'card_payments', $google_pay->get_account_capability_key() );

		$this->assertNotNull( $klarna );
		$this->assertTrue( $klarna->should_publish_gateway() );
		$this->assertSame( $klarna->get_stripe_id(), $klarna->get_account_capability_key() );
	}

	/**
	 * @testdox Country-specific definition values preserve extension behavior.
	 */
	public function test_country_specific_definition_values_preserve_extension_behavior(): void {
		$afterpay = $this->registry->get( 'afterpay_clearpay' );
		$affirm   = $this->registry->get( 'affirm' );
		$klarna   = $this->registry->get( 'klarna' );

		$this->assertNotNull( $afterpay );
		$this->assertSame( 'Cash App Afterpay', $afterpay->get_title( 'US' ) );
		$this->assertSame( 'Allow customers to pay over time with Cash App Afterpay.', $afterpay->get_description( 'US' ) );
		$this->assertSame( 'assets/images/payment-methods/afterpay-cashapp-logo.svg', $afterpay->get_icon_asset_path( 'US' ) );
		$this->assertSame( 'assets/images/payment-methods/afterpay-cashapp-logo-dark.svg', $afterpay->get_dark_icon_asset_path( 'US' ) );
		$this->assertSame( 'assets/images/payment-methods/afterpay-cashapp-badge.svg', $afterpay->get_settings_icon_asset_path( 'US' ) );
		$this->assertSame( 'Clearpay', $afterpay->get_title( 'GB' ) );
		$this->assertSame( 'Allow customers to pay over time with Clearpay.', $afterpay->get_description( 'GB' ) );
		$this->assertSame( 'assets/images/payment-methods/clearpay.svg', $afterpay->get_icon_asset_path( 'GB' ) );
		$this->assertSame( array( 'GB' ), $afterpay->get_supported_countries( 'GB' ) );

		$this->assertNotNull( $affirm );
		$this->assertSame( array( 'US' ), $affirm->get_supported_countries( 'us' ) );
		$this->assertSame( array( 'US', 'CA' ), $affirm->get_supported_countries( 'nl' ) );

		$this->assertNotNull( $klarna );
		update_option( 'woocommerce_currency', 'EUR' );
		$this->assertSame( array( 'AT', 'BE', 'FI', 'FR', 'DE', 'IE', 'IT', 'NL', 'ES' ), $klarna->get_supported_countries( 'NL' ) );
		$this->assertTrue( $klarna->is_available_for( 'eur', 'nl' ) );
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertSame( array(), $klarna->get_supported_countries( 'NL' ) );
		$this->assertTrue( $klarna->is_available_for( 'eur', 'nl' ), 'The native registry mirrors the extension behavior where an empty dynamic country list is treated as unrestricted.' );

		$alipay     = $this->registry->get( 'alipay' );
		$amazon_pay = $this->registry->get( 'amazon_pay' );
		$wechat_pay = $this->registry->get( 'wechat_pay' );

		$this->assertNotNull( $alipay );
		$this->assertSame( array( 'EUR' ), $alipay->get_supported_currencies( 'NL' ) );
		$this->assertSame( array( 'JPY' ), $alipay->get_supported_currencies( 'JP' ) );

		$this->assertNotNull( $amazon_pay );
		$this->assertSame( array( 'USD' ), $amazon_pay->get_supported_currencies( 'US' ) );
		$this->assertSame( array( 'USD', 'AUD', 'GBP', 'DKK', 'EUR', 'HKD', 'JPY', 'NZD', 'NOK', 'SEK', 'CHF', 'ZAR' ), $amazon_pay->get_supported_currencies( 'NL' ) );

		$this->assertNotNull( $wechat_pay );
		$this->assertSame( array( 'EUR' ), $wechat_pay->get_supported_currencies( 'NL' ) );
		$this->assertSame( array( 'NONE_SUPPORTED' ), $wechat_pay->get_supported_currencies( 'BR' ) );
	}

	/**
	 * @testdox Every registry-projected icon is published with the WooCommerce plugin.
	 */
	public function test_registry_icon_assets_exist(): void {
		foreach ( $this->registry->get_all() as $definition ) {
			$countries = array_merge( array( null ), $definition->get_supported_countries() );
			foreach ( $countries as $country ) {
				$asset_paths = array(
					$definition->get_icon_asset_path( $country ),
					$definition->get_dark_icon_asset_path( $country ),
					$definition->get_settings_icon_asset_path( $country ),
				);

				foreach ( array_unique( $asset_paths ) as $asset_path ) {
					$this->assertNotSame( '', $asset_path, $definition->get_id() . ' must publish a non-empty icon path.' );
					$this->assertFileExists( WC_ABSPATH . $asset_path, $definition->get_id() . ' references a missing icon: ' . $asset_path );
				}
			}
		}
	}

	/**
	 * @testdox Registry availability normalizes currency and account country inputs.
	 */
	public function test_availability_normalizes_currency_and_account_country_inputs(): void {
		$this->assertTrue( $this->registry->is_available_for_checkout( 'card', 'ro', 'ron' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'bancontact', 'be', 'eur' ) );
		$this->assertFalse( $this->registry->is_available_for_checkout( 'bancontact', 'be', 'usd' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'affirm', 'us', 'usd' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'afterpay_clearpay', 'gb', 'gbp' ) );
		$this->assertFalse( $this->registry->is_available_for_checkout( 'link', 'us', 'eur' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'p24', 'pl', 'pln' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'apple_pay', 'nl', 'eur' ) );
		$this->assertTrue( $this->registry->is_available_for_checkout( 'amazon_pay', 'us', 'usd' ) );
		$this->assertFalse( $this->registry->is_available_for_checkout( 'missing_method', 'us', 'usd' ) );
	}

	/**
	 * @testdox Registry exposes the staged native-chargeable set.
	 */
	public function test_registry_exposes_staged_natively_chargeable_ids(): void {
		$this->assertSame(
			array(
				'card',
				'link',
				'sepa_debit',
				'ideal',
				'bancontact',
				'klarna',
				'affirm',
				'afterpay_clearpay',
				'eps',
				'p24',
				'multibanco',
				'au_becs_debit',
				'grabpay',
				'wechat_pay',
				'alipay',
			),
			$this->registry->get_natively_chargeable_ids()
		);
	}

	/**
	 * @testdox Definitions expose extension amount limits.
	 */
	public function test_definitions_expose_extension_amount_limits(): void {
		$affirm   = $this->registry->get( 'affirm' );
		$klarna   = $this->registry->get( 'klarna' );
		$afterpay = $this->registry->get( 'afterpay_clearpay' );

		$this->assertNotNull( $affirm );
		$this->assertSame( 3500, $affirm->get_minimum_amount( 'usd', 'us' ) );
		$this->assertSame( 3000000, $affirm->get_maximum_amount( 'cad', 'ca' ) );
		$this->assertNull( $affirm->get_minimum_amount( 'eur', 'nl' ) );

		$this->assertNotNull( $klarna );
		$this->assertSame( 100, $klarna->get_minimum_amount( 'eur', 'nl' ) );
		$this->assertSame( 500000, $klarna->get_maximum_amount( 'eur', 'nl' ) );
		$this->assertSame( 10000000, $klarna->get_maximum_amount( 'sek', 'se' ) );

		$this->assertNotNull( $afterpay );
		$this->assertSame( 100, $afterpay->get_minimum_amount( 'gbp', 'gb' ) );
		$this->assertSame( 120000, $afterpay->get_maximum_amount( 'gbp', 'gb' ) );
	}

	/**
	 * Data provider for extension definition contract values.
	 *
	 * @return array<string,array{string,array<string,mixed>}>
	 */
	public function payment_method_definition_provider(): array {
		return array(
			'card'              => array(
				'card',
				array(
					'keywords'                   => array( 'card', 'credit card', 'debit card', 'cc' ),
					'stripe_id'                  => 'card_payments',
					'stripe_payment_method_type' => 'card',
					'title'                      => 'Card',
					'settings_label'             => 'Credit / Debit Cards',
					'description'                => 'Let your customers pay with major credit and debit cards without leaving your store.',
					'currencies'                 => array(),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later' ),
					'icon'                       => 'assets/images/payment-methods/generic-card.svg',
					'dark_icon'                  => 'assets/images/payment-methods/generic-card.svg',
					'settings_icon'              => 'assets/images/payment-methods/generic-card-black.svg',
				),
			),
			'affirm'            => array(
				'affirm',
				array(
					'keywords'                   => array( 'affirm' ),
					'stripe_id'                  => 'affirm_payments',
					'stripe_payment_method_type' => 'affirm',
					'title'                      => 'Affirm',
					'settings_label'             => 'Affirm',
					'description'                => 'Allow customers to pay over time with Affirm.',
					'currencies'                 => array( 'USD', 'CAD' ),
					'countries'                  => array( 'US', 'CA' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/affirm-logo.svg',
					'dark_icon'                  => 'assets/images/payment-methods/affirm-logo-dark.svg',
					'settings_icon'              => 'assets/images/payment-methods/affirm-badge.svg',
				),
			),
			'afterpay_clearpay' => array(
				'afterpay_clearpay',
				array(
					'keywords'                   => array( 'afterpay', 'clearpay' ),
					'stripe_id'                  => 'afterpay_clearpay_payments',
					'stripe_payment_method_type' => 'afterpay_clearpay',
					'title'                      => 'Afterpay',
					'settings_label'             => 'Afterpay',
					'description'                => 'Allow customers to pay over time with Afterpay.',
					'currencies'                 => array( 'USD', 'CAD', 'AUD', 'NZD', 'GBP' ),
					'countries'                  => array( 'US', 'CA', 'AU', 'NZ', 'GB' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/afterpay-badge.svg',
					'dark_icon'                  => 'assets/images/payment-methods/afterpay-badge.svg',
					'settings_icon'              => 'assets/images/payment-methods/afterpay-logo.svg',
				),
			),
			'alipay'            => array(
				'alipay',
				array(
					'keywords'                   => array( 'alipay' ),
					'stripe_id'                  => 'alipay_payments',
					'stripe_payment_method_type' => 'alipay',
					'title'                      => 'Alipay',
					'settings_label'             => 'Alipay',
					'description'                => 'A digital wallet for customers with mainland China Alipay accounts. Regional versions like AlipayHK are not supported.',
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency' ),
					'icon'                       => 'assets/images/payment-methods/alipay-logo.svg',
					'dark_icon'                  => 'assets/images/payment-methods/alipay-logo.svg',
					'settings_icon'              => 'assets/images/payment-methods/alipay-logo.svg',
				),
			),
			'bancontact'        => array(
				'bancontact',
				array(
					'keywords'                   => array( 'bancontact' ),
					'stripe_id'                  => 'bancontact_payments',
					'stripe_payment_method_type' => 'bancontact',
					'title'                      => 'Bancontact',
					'settings_label'             => 'Bancontact',
					'description'                => 'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'BE' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/bancontact.svg',
					'dark_icon'                  => 'assets/images/payment-methods/bancontact.svg',
					'settings_icon'              => 'assets/images/payment-methods/bancontact.svg',
				),
			),
			'au_becs_debit'     => array(
				'au_becs_debit',
				array(
					'keywords'                   => array( 'becs' ),
					'stripe_id'                  => 'au_becs_debit_payments',
					'stripe_payment_method_type' => 'au_becs_debit',
					'title'                      => 'BECS Direct Debit',
					'settings_label'             => 'BECS Direct Debit',
					'description'                => 'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.',
					'currencies'                 => array( 'AUD' ),
					'countries'                  => array( 'AU' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/bank-debit.svg',
					'dark_icon'                  => 'assets/images/payment-methods/bank-debit.svg',
					'settings_icon'              => 'assets/images/payment-methods/bank-debit.svg',
				),
			),
			'eps'               => array(
				'eps',
				array(
					'keywords'                   => array( 'eps' ),
					'stripe_id'                  => 'eps_payments',
					'stripe_payment_method_type' => 'eps',
					'title'                      => 'EPS',
					'settings_label'             => 'EPS',
					'description'                => 'Accept your payment with EPS — a common payment method in Austria.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'AT' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/eps.svg',
					'dark_icon'                  => 'assets/images/payment-methods/eps.svg',
					'settings_icon'              => 'assets/images/payment-methods/eps.svg',
				),
			),
			'grabpay'           => array(
				'grabpay',
				array(
					'keywords'                   => array( 'grabpay', 'grab_pay', 'grab' ),
					'stripe_id'                  => 'grabpay_payments',
					'stripe_payment_method_type' => 'grabpay',
					'title'                      => 'GrabPay',
					'settings_label'             => 'GrabPay',
					'description'                => 'A popular digital wallet for cashless payments in Singapore.',
					'currencies'                 => array( 'SGD' ),
					'countries'                  => array( 'SG' ),
					'capabilities'               => array( 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/grabpay.svg',
					'dark_icon'                  => 'assets/images/payment-methods/grabpay.svg',
					'settings_icon'              => 'assets/images/payment-methods/grabpay.svg',
				),
			),
			'ideal'             => array(
				'ideal',
				array(
					'keywords'                   => array( 'ideal' ),
					'stripe_id'                  => 'ideal_payments',
					'stripe_payment_method_type' => 'ideal',
					'title'                      => 'iDEAL | Wero',
					'settings_label'             => 'iDEAL | Wero',
					'description'                => "Expand your business with iDEAL | Wero — Netherlands's most popular payment method.",
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'NL' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/ideal.svg',
					'dark_icon'                  => 'assets/images/payment-methods/ideal-dark.svg',
					'settings_icon'              => 'assets/images/payment-methods/ideal.svg',
				),
			),
			'link'              => array(
				'link',
				array(
					'keywords'                   => array( 'link', 'stripe link' ),
					'stripe_id'                  => 'link_payments',
					'stripe_payment_method_type' => 'link',
					'title'                      => 'Link',
					'settings_label'             => 'Link',
					'description'                => "Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'tokenization' ),
					'icon'                       => 'assets/images/payment-methods/link.svg',
					'dark_icon'                  => 'assets/images/payment-methods/link.svg',
					'settings_icon'              => 'assets/images/payment-methods/link.svg',
				),
			),
			'multibanco'        => array(
				'multibanco',
				array(
					'keywords'                   => array( 'multibanco' ),
					'stripe_id'                  => 'multibanco_payments',
					'stripe_payment_method_type' => 'multibanco',
					'title'                      => 'Multibanco',
					'settings_label'             => 'Multibanco',
					'description'                => 'A voucher based payment method for your customers in Portugal.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'PT' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/multibanco-logo.svg',
					'dark_icon'                  => 'assets/images/payment-methods/multibanco-logo-dark.svg',
					'settings_icon'              => 'assets/images/payment-methods/multibanco.svg',
				),
			),
			'klarna'            => array(
				'klarna',
				array(
					'keywords'                   => array( 'klarna' ),
					'stripe_id'                  => 'klarna_payments',
					'stripe_payment_method_type' => 'klarna',
					'title'                      => 'Klarna',
					'settings_label'             => 'Klarna',
					'description'                => 'Allow customers to pay over time or pay now with Klarna.',
					'currencies'                 => array( 'USD', 'GBP', 'EUR', 'DKK', 'NOK', 'SEK' ),
					'countries'                  => array( 'US', 'GB', 'AT', 'DE', 'NL', 'BE', 'ES', 'IT', 'IE', 'DK', 'FI', 'NO', 'SE', 'FR' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/klarna-pill.svg',
					'dark_icon'                  => 'assets/images/payment-methods/klarna-pill.svg',
					'settings_icon'              => 'assets/images/payment-methods/klarna.svg',
				),
			),
			'p24'               => array(
				'p24',
				array(
					'keywords'                   => array( 'p24', 'przelewy24' ),
					'stripe_id'                  => 'p24_payments',
					'stripe_payment_method_type' => 'p24',
					'title'                      => 'Przelewy24 (P24)',
					'settings_label'             => 'Przelewy24 (P24)',
					'description'                => 'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.',
					'currencies'                 => array( 'EUR', 'PLN' ),
					'countries'                  => array( 'PL' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/p24.svg',
					'dark_icon'                  => 'assets/images/payment-methods/p24.svg',
					'settings_icon'              => 'assets/images/payment-methods/p24.svg',
				),
			),
			'sepa_debit'        => array(
				'sepa_debit',
				array(
					'keywords'                   => array( 'sepa' ),
					'stripe_id'                  => 'sepa_debit_payments',
					'stripe_payment_method_type' => 'sepa_debit',
					'title'                      => 'SEPA Direct Debit',
					'settings_label'             => 'SEPA Direct Debit',
					'description'                => 'Reach 500 million customers and over 20 million businesses across the European Union.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'GB', 'SM', 'VA', 'AD', 'MC', 'LI', 'NO', 'IS' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/sepa-debit.svg',
					'dark_icon'                  => 'assets/images/payment-methods/sepa-debit.svg',
					'settings_icon'              => 'assets/images/payment-methods/sepa-debit.svg',
				),
			),
			'wechat_pay'        => array(
				'wechat_pay',
				array(
					'keywords'                   => array( 'wechat_pay', 'wechatpay' ),
					'stripe_id'                  => 'wechat_pay_payments',
					'stripe_payment_method_type' => 'wechat_pay',
					'title'                      => 'WeChat Pay',
					'settings_label'             => 'WeChat Pay',
					'description'                => 'A digital wallet for customers with mainland China WeChat Pay wallets. Regional versions like WeChat Pay HK are not supported.',
					'currencies'                 => array( 'USD' ),
					'countries'                  => array( 'US', 'AU', 'CA', 'AT', 'BE', 'DK', 'FI', 'FR', 'DE', 'IE', 'IT', 'LU', 'NL', 'NO', 'PT', 'ES', 'SE', 'CH', 'GB', 'HK', 'JP', 'SG' ),
					'capabilities'               => array( 'refunds', 'multi_currency' ),
					'icon'                       => 'assets/images/payment-methods/wechat-pay.svg',
					'dark_icon'                  => 'assets/images/payment-methods/wechat-pay.svg',
					'settings_icon'              => 'assets/images/payment-methods/wechat-pay.svg',
				),
			),
			'apple_pay'         => array(
				'apple_pay',
				array(
					'keywords'                   => array( 'apple_pay', 'applepay' ),
					'stripe_id'                  => 'apple_pay_payments',
					'stripe_payment_method_type' => 'card',
					'title'                      => 'Apple Pay',
					'settings_label'             => 'Apple Pay',
					'description'                => 'Apple Pay is an easy and secure way for customers to pay on your store.',
					'currencies'                 => array(),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/cards/apple-pay.svg',
					'dark_icon'                  => 'assets/images/cards/apple-pay.svg',
					'settings_icon'              => 'assets/images/cards/apple-pay.svg',
				),
			),
			'google_pay'        => array(
				'google_pay',
				array(
					'keywords'                   => array( 'google_pay', 'googlepay', 'gpay' ),
					'stripe_id'                  => 'google_pay_payments',
					'stripe_payment_method_type' => 'card',
					'title'                      => 'Google Pay',
					'settings_label'             => 'Google Pay',
					'description'                => 'Offer customers a fast, secure checkout experience with Google Pay.',
					'currencies'                 => array(),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/cards/google-pay.svg',
					'dark_icon'                  => 'assets/images/cards/google-pay.svg',
					'settings_icon'              => 'assets/images/cards/google-pay.svg',
				),
			),
			'amazon_pay'        => array(
				'amazon_pay',
				array(
					'keywords'                   => array( 'amazon_pay', 'amazonpay', 'amazon' ),
					'stripe_id'                  => 'amazon_pay_payments',
					'stripe_payment_method_type' => 'amazon_pay',
					'title'                      => 'Amazon Pay',
					'settings_label'             => 'Amazon Pay',
					'description'                => 'Offer customers a fast, secure checkout experience with Amazon Pay.',
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/payment-methods/amazon-pay.svg',
					'dark_icon'                  => 'assets/images/payment-methods/amazon-pay.svg',
					'settings_icon'              => 'assets/images/payment-methods/amazon-pay.svg',
				),
			),
		);
	}
}
