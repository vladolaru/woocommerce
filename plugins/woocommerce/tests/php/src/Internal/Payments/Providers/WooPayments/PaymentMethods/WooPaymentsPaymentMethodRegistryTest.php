<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\PaymentMethods;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
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
	 * @testdox Registry exposes the payment method definitions in display order, without the discontinued methods.
	 */
	public function test_registry_exposes_definition_order_without_discontinued_methods(): void {
		$this->assertSame( self::EXPECTED_DEFINITION_IDS, array_keys( $this->registry->get_registered( $this->create_account_service() ) ) );
		foreach ( array( 'giropay', 'sofort' ) as $payment_method_id ) {
			$this->assertNull( $this->registry->get( $payment_method_id ), "{$payment_method_id} is discontinued and should have no definition." );
		}
		foreach ( WooPaymentsPaymentMethodRegistry::DISCONTINUED_PAYMENT_METHOD_IDS as $payment_method_id ) {
			$this->assertNull( $this->registry->get( $payment_method_id ), "{$payment_method_id} is listed as discontinued, so cutover removes it from settings; it must have no definition." );
		}
		$this->assertNull( $this->registry->get( 'jcb' ), 'The standalone extension has no JCB payment method definition.' );
	}

	/**
	 * @testdox Availability filter receives one ordered ID-list argument exactly once and controls the available methods.
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

		$available_ids = $this->registry->get_available_payment_method_ids_for_account( $this->create_account_service() );

		$this->assertSame( 1, $filter_calls, 'One available-methods computation should dispatch the availability filter once.' );
		$this->assertCount( 1, $filter_args, 'The pinned oracle passes no gateway or context argument.' );
		$this->assertSame( self::EXPECTED_DEFINITION_IDS, $filter_args[0], 'The filter should receive every definition ID in registry order.' );
		$this->assertSame(
			array_values( array_diff( self::EXPECTED_DEFINITION_IDS, array( 'bancontact' ) ) ),
			$available_ids,
			'The available methods should follow the normalized filtered ID list.'
		);
	}

	/**
	 * @testdox Should ignore an availability filter return that is not a list of payment method IDs and use the whole catalog.
	 * @testWith [null]
	 *           [false]
	 *           ["card"]
	 *           [["card", 3]]
	 *           [{"first": "card", "second": ["ideal"]}]
	 *
	 * @param mixed $filter_return What the availability filter callback returns.
	 */
	public function test_availability_filter_falls_back_to_the_catalog_for_an_invalid_return( $filter_return ): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn() => $filter_return
		);

		$this->assertSame( self::EXPECTED_DEFINITION_IDS, $this->registry->get_available_payment_method_ids_for_account( $this->create_account_service() ) );
	}

	/**
	 * Client 11.1.0 keeps a valid filter return as it is, an empty list included (`includes/class-wc-payment-gateway-wcpay.php:4864-4879`).
	 *
	 * @testdox Should offer no payment method when an availability filter callback returns an empty list.
	 */
	public function test_availability_filter_empty_list_offers_no_payment_method(): void {
		add_filter(
			'wcpay_upe_available_payment_methods',
			static fn(): array => array()
		);

		$this->assertSame( array(), $this->registry->get_available_payment_method_ids_for_account( $this->create_account_service() ) );
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
		$this->assertSame( $expected['description'], $definition->get_description() );
		$this->assertSame( $expected['currencies'], $definition->get_supported_currencies() );
		$this->assertSame( $expected['countries'], $definition->get_supported_countries() );
		$this->assertSame( $expected['capabilities'], $definition->get_capabilities() );
		$this->assertSame( $expected['icon'], $definition->get_icon_asset_path() );
		$this->assertSame( $expected['dark_icon'], $definition->get_dark_icon_asset_path() );
		$this->assertSame( $expected['settings_icon'], $definition->get_settings_icon_asset_path() );
	}

	/**
	 * @testdox Should register catalog titles and descriptions with the woocommerce text domain
	 */
	public function test_catalog_strings_are_translatable(): void {
		$filter = static function ( string $translation, string $text, string $domain ) {
			if ( 'woocommerce' !== $domain ) {
				return $translation;
			}

			$translations = array(
				'iDEAL | Wero' => 'iDEAL translated',
				'Clearpay'     => 'Clearpay translated',
				'Allow customers to pay over time with Clearpay.' => 'Clearpay description translated',
			);

			return $translations[ $text ] ?? $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			$registry = new WooPaymentsPaymentMethodRegistry();
			$ideal    = $registry->get( 'ideal' );
			$afterpay = $registry->get( 'afterpay_clearpay' );

			$this->assertSame( 'iDEAL translated', $ideal->get_title() );
			$this->assertSame( 'Clearpay translated', $afterpay->get_title( 'GB' ) );
			$this->assertSame( 'Clearpay description translated', $afterpay->get_description( 'GB' ) );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}
	}

	/**
	 * @testdox Should translate every catalog title and description at consumption time
	 */
	public function test_every_catalog_title_and_description_is_translatable(): void {
		$filter = static function ( $translation, $text, $domain ) {
			return 'woocommerce' === $domain ? 'T:' . $text : $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			foreach ( ( new WooPaymentsPaymentMethodRegistry() )->get_registered( $this->create_account_service() ) as $id => $definition ) {
				foreach ( array( null, 'US', 'GB' ) as $country ) {
					$this->assertStringStartsWith( 'T:', $definition->get_title( $country ), "Untranslated title for {$id} ({$country})" );
					$this->assertStringStartsWith( 'T:', $definition->get_description( $country ), "Untranslated description for {$id} ({$country})" );
				}
			}
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}
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
	 * The platform's payment method availability service decides which methods new accounts are offered (wpcom
	 * wp-content/rest-api-plugins/endpoints/wcpay/service/class-payment-method-availability-service.php): P24 is on its list
	 * of methods kept for existing accounts (:22-35) and its config turns P24 off for everyone else (:100-109). SEPA is
	 * configured the same way (:164-169) but is not on that list, so it stays unmarked here.
	 *
	 * @testdox Should offer every payment method to new accounts except P24.
	 */
	public function test_only_p24_is_not_offered_to_new_accounts(): void {
		$not_offered = array();
		foreach ( self::EXPECTED_DEFINITION_IDS as $payment_method_id ) {
			$definition = $this->registry->get( $payment_method_id );
			$this->assertNotNull( $definition, "{$payment_method_id} should have a definition." );

			if ( ! $definition->is_offered_to_new_accounts() ) {
				$not_offered[] = $payment_method_id;
			}
		}

		$this->assertSame( array( 'p24' ), $not_offered );
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
		$this->assertSame( 'assets/images/payment-methods/clearpay-icon.svg', $afterpay->get_icon_asset_path( 'GB' ) );
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
		foreach ( $this->registry->get_registered( $this->create_account_service() ) as $definition ) {
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
		$this->assertTrue( $this->registry->get( 'card' )->is_available_for( 'ron', 'ro' ) );
		$this->assertTrue( $this->registry->get( 'bancontact' )->is_available_for( 'eur', 'be' ) );
		$this->assertFalse( $this->registry->get( 'bancontact' )->is_available_for( 'usd', 'be' ) );
		$this->assertTrue( $this->registry->get( 'affirm' )->is_available_for( 'usd', 'us' ) );
		$this->assertTrue( $this->registry->get( 'afterpay_clearpay' )->is_available_for( 'gbp', 'gb' ) );
		$this->assertFalse( $this->registry->get( 'link' )->is_available_for( 'eur', 'us' ) );
		$this->assertTrue( $this->registry->get( 'p24' )->is_available_for( 'pln', 'pl' ) );
		$this->assertTrue( $this->registry->get( 'apple_pay' )->is_available_for( 'eur', 'nl' ) );
		$this->assertTrue( $this->registry->get( 'amazon_pay' )->is_available_for( 'usd', 'us' ) );
		$this->assertNull( $this->registry->get( 'missing_method' ) );
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
					'description'                => 'Allow customers to pay over time with Affirm.',
					'currencies'                 => array( 'USD', 'CAD' ),
					'countries'                  => array( 'US', 'CA' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/affirm-logo.svg',
					'dark_icon'                  => 'assets/images/payment-methods/affirm-logo-dark.svg',
					'settings_icon'              => 'assets/images/payment-methods/affirm-badge-color.svg',
				),
			),
			'afterpay_clearpay' => array(
				'afterpay_clearpay',
				array(
					'keywords'                   => array( 'afterpay', 'clearpay' ),
					'stripe_id'                  => 'afterpay_clearpay_payments',
					'stripe_payment_method_type' => 'afterpay_clearpay',
					'title'                      => 'Afterpay',
					'description'                => 'Allow customers to pay over time with Afterpay.',
					'currencies'                 => array( 'USD', 'CAD', 'AUD', 'NZD', 'GBP' ),
					'countries'                  => array( 'US', 'CA', 'AU', 'NZ', 'GB' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/afterpay-badge-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/afterpay-badge-color.svg',
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
					'description'                => 'A digital wallet for customers with mainland China Alipay accounts. Regional versions like AlipayHK are not supported.',
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency' ),
					'icon'                       => 'assets/images/payment-methods/alipay-logo-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/alipay-logo-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/alipay-logo-color.svg',
				),
			),
			'bancontact'        => array(
				'bancontact',
				array(
					'keywords'                   => array( 'bancontact' ),
					'stripe_id'                  => 'bancontact_payments',
					'stripe_payment_method_type' => 'bancontact',
					'title'                      => 'Bancontact',
					'description'                => 'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'BE' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/bancontact-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/bancontact-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/bancontact-color.svg',
				),
			),
			'au_becs_debit'     => array(
				'au_becs_debit',
				array(
					'keywords'                   => array( 'becs' ),
					'stripe_id'                  => 'au_becs_debit_payments',
					'stripe_payment_method_type' => 'au_becs_debit',
					'title'                      => 'BECS Direct Debit',
					'description'                => 'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.',
					'currencies'                 => array( 'AUD' ),
					'countries'                  => array( 'AU' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/bank-debit-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/bank-debit-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/bank-debit-color.svg',
				),
			),
			'eps'               => array(
				'eps',
				array(
					'keywords'                   => array( 'eps' ),
					'stripe_id'                  => 'eps_payments',
					'stripe_payment_method_type' => 'eps',
					'title'                      => 'EPS',
					'description'                => 'Accept your payment with EPS — a common payment method in Austria.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'AT' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/eps-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/eps-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/eps-color.svg',
				),
			),
			'grabpay'           => array(
				'grabpay',
				array(
					'keywords'                   => array( 'grabpay', 'grab_pay', 'grab' ),
					'stripe_id'                  => 'grabpay_payments',
					'stripe_payment_method_type' => 'grabpay',
					'title'                      => 'GrabPay',
					'description'                => 'A popular digital wallet for cashless payments in Singapore.',
					'currencies'                 => array( 'SGD' ),
					'countries'                  => array( 'SG' ),
					'capabilities'               => array( 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/grabpay-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/grabpay-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/grabpay-color.svg',
				),
			),
			'ideal'             => array(
				'ideal',
				array(
					'keywords'                   => array( 'ideal' ),
					'stripe_id'                  => 'ideal_payments',
					'stripe_payment_method_type' => 'ideal',
					'title'                      => 'iDEAL | Wero',
					'description'                => "Expand your business with iDEAL | Wero — Netherlands's most popular payment method.",
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'NL' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/ideal.svg',
					'dark_icon'                  => 'assets/images/payment-methods/ideal-dark-color.svg',
					// Client 11.1.0 IdealDefinition::get_settings_icon_url() (:160-162) is its iDEAL | Wero tile; core ships it as ideal-wero.svg.
					'settings_icon'              => 'assets/images/payment-methods/ideal-wero.svg',
				),
			),
			'link'              => array(
				'link',
				array(
					'keywords'                   => array( 'link', 'stripe link' ),
					'stripe_id'                  => 'link_payments',
					'stripe_payment_method_type' => 'link',
					'title'                      => 'Link',
					'description'                => "Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'tokenization' ),
					'icon'                       => 'assets/images/payment-methods/link-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/link-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/link-color.svg',
				),
			),
			'multibanco'        => array(
				'multibanco',
				array(
					'keywords'                   => array( 'multibanco' ),
					'stripe_id'                  => 'multibanco_payments',
					'stripe_payment_method_type' => 'multibanco',
					'title'                      => 'Multibanco',
					'description'                => 'A voucher based payment method for your customers in Portugal.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'PT' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/multibanco-logo.svg',
					'dark_icon'                  => 'assets/images/payment-methods/multibanco-logo-dark.svg',
					'settings_icon'              => 'assets/images/payment-methods/multibanco-color.svg',
				),
			),
			'klarna'            => array(
				'klarna',
				array(
					'keywords'                   => array( 'klarna' ),
					'stripe_id'                  => 'klarna_payments',
					'stripe_payment_method_type' => 'klarna',
					'title'                      => 'Klarna',
					'description'                => 'Allow customers to pay over time or pay now with Klarna.',
					'currencies'                 => array( 'USD', 'GBP', 'EUR', 'DKK', 'NOK', 'SEK' ),
					'countries'                  => array( 'US', 'GB', 'AT', 'DE', 'NL', 'BE', 'ES', 'IT', 'IE', 'DK', 'FI', 'NO', 'SE', 'FR' ),
					'capabilities'               => array( 'buy_now_pay_later', 'refunds', 'domestic_transactions_only' ),
					'icon'                       => 'assets/images/payment-methods/klarna-pill.svg',
					'dark_icon'                  => 'assets/images/payment-methods/klarna-pill.svg',
					'settings_icon'              => 'assets/images/payment-methods/klarna-color.svg',
				),
			),
			'p24'               => array(
				'p24',
				array(
					'keywords'                   => array( 'p24', 'przelewy24' ),
					'stripe_id'                  => 'p24_payments',
					'stripe_payment_method_type' => 'p24',
					'title'                      => 'Przelewy24 (P24)',
					'description'                => 'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.',
					'currencies'                 => array( 'EUR', 'PLN' ),
					'countries'                  => array( 'PL' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/p24-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/p24-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/p24-color.svg',
				),
			),
			'sepa_debit'        => array(
				'sepa_debit',
				array(
					'keywords'                   => array( 'sepa' ),
					'stripe_id'                  => 'sepa_debit_payments',
					'stripe_payment_method_type' => 'sepa_debit',
					'title'                      => 'SEPA Direct Debit',
					'description'                => 'Reach 500 million customers and over 20 million businesses across the European Union.',
					'currencies'                 => array( 'EUR' ),
					'countries'                  => array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'GB', 'SM', 'VA', 'AD', 'MC', 'LI', 'NO', 'IS' ),
					'capabilities'               => array( 'refunds' ),
					'icon'                       => 'assets/images/payment-methods/sepa-debit-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/sepa-debit-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/sepa-debit-color.svg',
				),
			),
			'wechat_pay'        => array(
				'wechat_pay',
				array(
					'keywords'                   => array( 'wechat_pay', 'wechatpay' ),
					'stripe_id'                  => 'wechat_pay_payments',
					'stripe_payment_method_type' => 'wechat_pay',
					'title'                      => 'WeChat Pay',
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
					'description'                => 'Apple Pay is an easy and secure way for customers to pay on your store.',
					'currencies'                 => array(),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/payment-methods/apple-pay-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/apple-pay-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/apple-pay-color.svg',
				),
			),
			'google_pay'        => array(
				'google_pay',
				array(
					'keywords'                   => array( 'google_pay', 'googlepay', 'gpay' ),
					'stripe_id'                  => 'google_pay_payments',
					'stripe_payment_method_type' => 'card',
					'title'                      => 'Google Pay',
					'description'                => 'Offer customers a fast, secure checkout experience with Google Pay.',
					'currencies'                 => array(),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/payment-methods/google-pay-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/google-pay-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/google-pay-color.svg',
				),
			),
			'amazon_pay'        => array(
				'amazon_pay',
				array(
					'keywords'                   => array( 'amazon_pay', 'amazonpay', 'amazon' ),
					'stripe_id'                  => 'amazon_pay_payments',
					'stripe_payment_method_type' => 'amazon_pay',
					'title'                      => 'Amazon Pay',
					'description'                => 'Offer customers a fast, secure checkout experience with Amazon Pay.',
					'currencies'                 => array( 'USD' ),
					'countries'                  => array(),
					'capabilities'               => array( 'refunds', 'multi_currency', 'tokenization', 'capture_later', 'express_checkout' ),
					'icon'                       => 'assets/images/payment-methods/amazon-pay-color.svg',
					'dark_icon'                  => 'assets/images/payment-methods/amazon-pay-color.svg',
					'settings_icon'              => 'assets/images/payment-methods/amazon-pay-color.svg',
				),
			),
		);
	}

	/**
	 * Create an account service whose account has a fee for every payment method, so Amazon Pay is registered and every
	 * method is available. Fees are keyed by payment method ID, as in the recorded Fixtures/rec-t60-test-drive-account.json
	 * `account.fees`.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country' => 'US',
				'fees'    => array_fill_keys( self::EXPECTED_DEFINITION_IDS, array() ),
			)
		);

		return $account_service;
	}
}
