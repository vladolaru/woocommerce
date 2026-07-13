<?php
/**
 * WooPaymentsPaymentMethodRegistry class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods;

/**
 * Registry for native WooPayments payment method definitions.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native WooPayments settings runtime.
 */
class WooPaymentsPaymentMethodRegistry {

	private const REFUNDS = 'refunds';

	private const MULTI_CURRENCY = 'multi_currency';

	private const TOKENIZATION = 'tokenization';

	private const CAPTURE_LATER = 'capture_later';

	private const BUY_NOW_PAY_LATER = 'buy_now_pay_later';

	private const DOMESTIC_TRANSACTIONS_ONLY = 'domestic_transactions_only';

	private const EXPRESS_CHECKOUT = 'express_checkout';

	private const NATIVELY_CHARGEABLE_PAYMENT_METHOD_IDS = array(
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
	);

	/**
	 * Payment method definitions keyed by payment method ID.
	 *
	 * @var array<string,WooPaymentsPaymentMethodDefinition>
	 */
	private array $definitions = array();

	/**
	 * Get all registered definitions keyed by payment method ID.
	 *
	 * @return array<string,WooPaymentsPaymentMethodDefinition>
	 */
	public function get_all(): array {
		$this->initialize_definitions();

		$available_definitions = array();
		foreach ( $this->get_available_payment_method_ids() as $payment_method_id ) {
			if ( is_string( $payment_method_id ) && isset( $this->definitions[ $payment_method_id ] ) ) {
				$available_definitions[ $payment_method_id ] = $this->definitions[ $payment_method_id ];
			}
		}

		return $available_definitions;
	}

	/**
	 * Get available payment method IDs in extension registry order.
	 *
	 * @return string[]
	 */
	public function get_available_payment_method_ids(): array {
		$this->initialize_definitions();

		/**
		 * Filters the payment methods available to WooPayments.
		 *
		 * @param string[] $payment_method_ids Available payment method IDs.
		 *
		 * @since 11.0.0
		 */
		$payment_method_ids = apply_filters( 'wcpay_upe_available_payment_methods', array_keys( $this->definitions ) );

		return array_values( $payment_method_ids );
	}

	/**
	 * Get a definition by payment method ID.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @return WooPaymentsPaymentMethodDefinition|null
	 */
	public function get( string $payment_method_id ): ?WooPaymentsPaymentMethodDefinition {
		$this->initialize_definitions();

		$payment_method_id = strtolower( $payment_method_id );

		return $this->definitions[ $payment_method_id ] ?? null;
	}

	/**
	 * Get the payment methods the staged native WooPayments gateway can charge today.
	 *
	 * @return string[]
	 */
	public function get_natively_chargeable_ids(): array {
		return self::NATIVELY_CHARGEABLE_PAYMENT_METHOD_IDS;
	}

	/**
	 * Tell whether a payment method is available for checkout.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @param string $account_country   Merchant account country.
	 * @param string $currency          Checkout currency.
	 * @return bool
	 */
	public function is_available_for_checkout( string $payment_method_id, string $account_country, string $currency ): bool {
		$definition = $this->get( $payment_method_id );

		return null !== $definition && $definition->is_available_for( $currency, $account_country );
	}

	/**
	 * Initialize the definitions map.
	 */
	private function initialize_definitions(): void {
		if ( ! empty( $this->definitions ) ) {
			return;
		}

		foreach ( $this->get_definition_configs() as $config ) {
			$definition                                 = new WooPaymentsStaticPaymentMethodDefinition( $config );
			$this->definitions[ $definition->get_id() ] = $definition;
		}
	}

	/**
	 * Get definition configs in extension registry order, excluding deprecated methods.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_definition_configs(): array {
		return array(
			array(
				'id'                         => 'card',
				'keywords'                   => array( 'card', 'credit card', 'debit card', 'cc' ),
				'stripe_id'                  => 'card_payments',
				'stripe_payment_method_type' => 'card',
				'title'                      => 'Card',
				'settings_label'             => 'Credit / Debit Cards',
				'description'                => 'Let your customers pay with major credit and debit cards without leaving your store.',
				'currencies'                 => array(),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
					self::TOKENIZATION,
					self::CAPTURE_LATER,
				),
				'icon'                       => 'assets/images/payment-methods/generic-card.svg',
				'settings_icon'              => 'assets/images/payment-methods/generic-card-black.svg',
			),
			array(
				'id'                         => 'affirm',
				'keywords'                   => array( 'affirm' ),
				'stripe_id'                  => 'affirm_payments',
				'stripe_payment_method_type' => 'affirm',
				'title'                      => 'Affirm',
				'description'                => 'Allow customers to pay over time with Affirm.',
				'currencies'                 => array( 'USD', 'CAD' ),
				'countries'                  => array( 'US', 'CA' ),
				'country_mode'               => 'domestic_only',
				'capabilities'               => array(
					self::BUY_NOW_PAY_LATER,
					self::REFUNDS,
					self::DOMESTIC_TRANSACTIONS_ONLY,
				),
				'icon'                       => 'assets/images/payment-methods/affirm-logo.svg',
				'dark_icon'                  => 'assets/images/payment-methods/affirm-logo-dark.svg',
				'settings_icon'              => 'assets/images/payment-methods/affirm-badge.svg',
				'limits'                     => array(
					'CAD' => array(
						'CA' => array(
							'min' => 3500,
							'max' => 3000000,
						),
					),
					'USD' => array(
						'US' => array(
							'min' => 3500,
							'max' => 3000000,
						),
					),
				),
			),
			array(
				'id'                         => 'afterpay_clearpay',
				'keywords'                   => array( 'afterpay', 'clearpay' ),
				'stripe_id'                  => 'afterpay_clearpay_payments',
				'stripe_payment_method_type' => 'afterpay_clearpay',
				'title'                      => 'Afterpay',
				'titles_by_country'          => array(
					'US' => 'Cash App Afterpay',
					'GB' => 'Clearpay',
				),
				'description'                => 'Allow customers to pay over time with Afterpay.',
				'descriptions_by_country'    => array(
					'US' => 'Allow customers to pay over time with Cash App Afterpay.',
					'GB' => 'Allow customers to pay over time with Clearpay.',
				),
				'currencies'                 => array( 'USD', 'CAD', 'AUD', 'NZD', 'GBP' ),
				'countries'                  => array( 'US', 'CA', 'AU', 'NZ', 'GB' ),
				'country_mode'               => 'domestic_only',
				'capabilities'               => array(
					self::BUY_NOW_PAY_LATER,
					self::REFUNDS,
					self::DOMESTIC_TRANSACTIONS_ONLY,
				),
				'icon'                       => 'assets/images/payment-methods/afterpay-badge.svg',
				'dark_icon'                  => 'assets/images/payment-methods/afterpay-badge.svg',
				'settings_icon'              => 'assets/images/payment-methods/afterpay-logo.svg',
				'icons_by_country'           => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-logo.svg',
					'GB' => 'assets/images/payment-methods/clearpay.svg',
				),
				'dark_icons_by_country'      => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-logo-dark.svg',
					'GB' => 'assets/images/payment-methods/clearpay.svg',
				),
				'settings_icons_by_country'  => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-badge.svg',
					'GB' => 'assets/images/payment-methods/clearpay.svg',
				),
				'limits'                     => array(
					'AUD' => array(
						'AU' => array(
							'min' => 100,
							'max' => 200000,
						),
					),
					'CAD' => array(
						'CA' => array(
							'min' => 100,
							'max' => 200000,
						),
					),
					'NZD' => array(
						'NZ' => array(
							'min' => 100,
							'max' => 200000,
						),
					),
					'GBP' => array(
						'GB' => array(
							'min' => 100,
							'max' => 120000,
						),
					),
					'USD' => array(
						'US' => array(
							'min' => 100,
							'max' => 400000,
						),
					),
				),
			),
			array(
				'id'                         => 'alipay',
				'keywords'                   => array( 'alipay' ),
				'stripe_id'                  => 'alipay_payments',
				'stripe_payment_method_type' => 'alipay',
				'title'                      => 'Alipay',
				'description'                => 'A digital wallet for customers with mainland China Alipay accounts. Regional versions like AlipayHK are not supported.',
				'currencies'                 => array( 'USD' ),
				'currencies_by_country'      => array(
					'AU' => array( 'AUD' ),
					'CA' => array( 'CAD' ),
					'GB' => array( 'GBP' ),
					'HK' => array( 'HKD' ),
					'JP' => array( 'JPY' ),
					'NZ' => array( 'NZD' ),
					'SG' => array( 'SGD' ),
					'US' => array( 'USD' ),
					'HU' => array( 'HUF' ),
				),
				'currency_country_groups'    => array(
					array(
						'countries'  => array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'HR' ),
						'currencies' => array( 'EUR' ),
					),
				),
				'fallback_currencies'        => array( 'CNY' ),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
				),
				'icon'                       => 'assets/images/payment-methods/alipay-logo.svg',
			),
			array(
				'id'                         => 'bancontact',
				'keywords'                   => array( 'bancontact' ),
				'stripe_id'                  => 'bancontact_payments',
				'stripe_payment_method_type' => 'bancontact',
				'title'                      => 'Bancontact',
				'description'                => 'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'BE' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/bancontact.svg',
			),
			array(
				'id'                         => 'au_becs_debit',
				'keywords'                   => array( 'becs' ),
				'stripe_id'                  => 'au_becs_debit_payments',
				'stripe_payment_method_type' => 'au_becs_debit',
				'title'                      => 'BECS Direct Debit',
				'description'                => 'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.',
				'currencies'                 => array( 'AUD' ),
				'countries'                  => array( 'AU' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/bank-debit.svg',
			),
			array(
				'id'                         => 'eps',
				'keywords'                   => array( 'eps' ),
				'stripe_id'                  => 'eps_payments',
				'stripe_payment_method_type' => 'eps',
				'title'                      => 'EPS',
				'description'                => 'Accept your payment with EPS — a common payment method in Austria.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'AT' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/eps.svg',
			),
			array(
				'id'                         => 'grabpay',
				'keywords'                   => array( 'grabpay', 'grab_pay', 'grab' ),
				'stripe_id'                  => 'grabpay_payments',
				'stripe_payment_method_type' => 'grabpay',
				'title'                      => 'GrabPay',
				'description'                => 'A popular digital wallet for cashless payments in Singapore.',
				'currencies'                 => array( 'SGD' ),
				'countries'                  => array( 'SG' ),
				'capabilities'               => array(
					self::REFUNDS,
					self::DOMESTIC_TRANSACTIONS_ONLY,
				),
				'icon'                       => 'assets/images/payment-methods/grabpay.svg',
			),
			array(
				'id'                         => 'ideal',
				'keywords'                   => array( 'ideal' ),
				'stripe_id'                  => 'ideal_payments',
				'stripe_payment_method_type' => 'ideal',
				'title'                      => 'iDEAL | Wero',
				'description'                => "Expand your business with iDEAL | Wero — Netherlands's most popular payment method.",
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'NL' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/ideal.svg',
				'dark_icon'                  => 'assets/images/payment-methods/ideal-dark.svg',
			),
			array(
				'id'                         => 'link',
				'publish_gateway'            => false,
				'keywords'                   => array( 'link', 'stripe link' ),
				'stripe_id'                  => 'link_payments',
				'stripe_payment_method_type' => 'link',
				'title'                      => 'Link',
				'description'                => "Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
				'currencies'                 => array( 'USD' ),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::TOKENIZATION,
				),
				'icon'                       => 'assets/images/payment-methods/link.svg',
			),
			array(
				'id'                         => 'multibanco',
				'keywords'                   => array( 'multibanco' ),
				'stripe_id'                  => 'multibanco_payments',
				'stripe_payment_method_type' => 'multibanco',
				'title'                      => 'Multibanco',
				'description'                => 'A voucher based payment method for your customers in Portugal.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'PT' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/multibanco-logo.svg',
				'dark_icon'                  => 'assets/images/payment-methods/multibanco-logo-dark.svg',
				'settings_icon'              => 'assets/images/payment-methods/multibanco.svg',
			),
			array(
				'id'                         => 'klarna',
				'keywords'                   => array( 'klarna' ),
				'stripe_id'                  => 'klarna_payments',
				'stripe_payment_method_type' => 'klarna',
				'title'                      => 'Klarna',
				'description'                => 'Allow customers to pay over time or pay now with Klarna.',
				'currencies'                 => array( 'USD', 'GBP', 'EUR', 'DKK', 'NOK', 'SEK' ),
				'countries'                  => array( 'US', 'GB', 'AT', 'DE', 'NL', 'BE', 'ES', 'IT', 'IE', 'DK', 'FI', 'NO', 'SE', 'FR' ),
				'country_mode'               => 'klarna',
				'capabilities'               => array(
					self::BUY_NOW_PAY_LATER,
					self::REFUNDS,
					self::DOMESTIC_TRANSACTIONS_ONLY,
				),
				'icon'                       => 'assets/images/payment-methods/klarna-pill.svg',
				'settings_icon'              => 'assets/images/payment-methods/klarna.svg',
				'limits'                     => array(
					'USD' => array(
						'US' => array(
							'min' => 100,
							'max' => 1000000,
						),
					),
					'GBP' => array(
						'GB' => array(
							'min' => 100,
							'max' => 500000,
						),
					),
					'EUR' => array(
						'AT' => array(
							'min' => 100,
							'max' => 1000000,
						),
						'BE' => array(
							'min' => 100,
							'max' => 1000000,
						),
						'DE' => array(
							'min' => 100,
							'max' => 1000000,
						),
						'NL' => array(
							'min' => 100,
							'max' => 500000,
						),
						'FI' => array(
							'min' => 100,
							'max' => 1000000,
						),
						'ES' => array(
							'min' => 100,
							'max' => 1000000,
						),
						'IE' => array(
							'min' => 100,
							'max' => 400000,
						),
						'IT' => array(
							'min' => 100,
							'max' => 400000,
						),
						'FR' => array(
							'min' => 100,
							'max' => 400000,
						),
					),
					'DKK' => array(
						'DK' => array(
							'min' => 100,
							'max' => 10000000,
						),
					),
					'NOK' => array(
						'NO' => array(
							'min' => 100,
							'max' => 10000000,
						),
					),
					'SEK' => array(
						'SE' => array(
							'min' => 100,
							'max' => 10000000,
						),
					),
				),
			),
			array(
				'id'                         => 'p24',
				'keywords'                   => array( 'p24', 'przelewy24' ),
				'stripe_id'                  => 'p24_payments',
				'stripe_payment_method_type' => 'p24',
				'title'                      => 'Przelewy24 (P24)',
				'description'                => 'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.',
				'currencies'                 => array( 'EUR', 'PLN' ),
				'countries'                  => array( 'PL' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/p24.svg',
			),
			array(
				'id'                         => 'sepa_debit',
				'keywords'                   => array( 'sepa' ),
				'stripe_id'                  => 'sepa_debit_payments',
				'stripe_payment_method_type' => 'sepa_debit',
				'title'                      => 'SEPA Direct Debit',
				'description'                => 'Reach 500 million customers and over 20 million businesses across the European Union.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'GB', 'SM', 'VA', 'AD', 'MC', 'LI', 'NO', 'IS' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/sepa-debit.svg',
			),
			array(
				'id'                         => 'wechat_pay',
				'keywords'                   => array( 'wechat_pay', 'wechatpay' ),
				'stripe_id'                  => 'wechat_pay_payments',
				'stripe_payment_method_type' => 'wechat_pay',
				'title'                      => 'WeChat Pay',
				'description'                => 'A digital wallet for customers with mainland China WeChat Pay wallets. Regional versions like WeChat Pay HK are not supported.',
				'currencies'                 => array( 'USD' ),
				'currencies_by_country'      => array(
					'AU' => array( 'AUD' ),
					'CA' => array( 'CAD' ),
					'DK' => array( 'DKK' ),
					'HK' => array( 'HKD' ),
					'JP' => array( 'JPY' ),
					'NO' => array( 'NOK' ),
					'SG' => array( 'SGD' ),
					'SE' => array( 'SEK' ),
					'CH' => array( 'CHF' ),
					'GB' => array( 'GBP' ),
					'US' => array( 'USD' ),
				),
				'currency_country_groups'    => array(
					array(
						'countries'  => array( 'AT', 'BE', 'FI', 'FR', 'DE', 'IE', 'IT', 'LU', 'NL', 'PT', 'ES' ),
						'currencies' => array( 'EUR' ),
					),
				),
				'fallback_currencies'        => array( 'NONE_SUPPORTED' ),
				'countries'                  => array( 'US', 'AU', 'CA', 'AT', 'BE', 'DK', 'FI', 'FR', 'DE', 'IE', 'IT', 'LU', 'NL', 'NO', 'PT', 'ES', 'SE', 'CH', 'GB', 'HK', 'JP', 'SG' ),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
				),
				'icon'                       => 'assets/images/payment-methods/wechat-pay.svg',
			),
			array(
				'id'                         => 'apple_pay',
				'account_capability_key'     => 'card_payments',
				'keywords'                   => array( 'apple_pay', 'applepay' ),
				'stripe_id'                  => 'apple_pay_payments',
				'stripe_payment_method_type' => 'card',
				'title'                      => 'Apple Pay',
				'description'                => 'Apple Pay is an easy and secure way for customers to pay on your store.',
				'currencies'                 => array(),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
					self::TOKENIZATION,
					self::CAPTURE_LATER,
					self::EXPRESS_CHECKOUT,
				),
				'icon'                       => 'assets/images/cards/apple-pay.svg',
			),
			array(
				'id'                         => 'google_pay',
				'account_capability_key'     => 'card_payments',
				'keywords'                   => array( 'google_pay', 'googlepay', 'gpay' ),
				'stripe_id'                  => 'google_pay_payments',
				'stripe_payment_method_type' => 'card',
				'title'                      => 'Google Pay',
				'description'                => 'Offer customers a fast, secure checkout experience with Google Pay.',
				'currencies'                 => array(),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
					self::TOKENIZATION,
					self::CAPTURE_LATER,
					self::EXPRESS_CHECKOUT,
				),
				'icon'                       => 'assets/images/cards/google-pay.svg',
			),
			array(
				'id'                         => 'amazon_pay',
				'keywords'                   => array( 'amazon_pay', 'amazonpay', 'amazon' ),
				'stripe_id'                  => 'amazon_pay_payments',
				'stripe_payment_method_type' => 'amazon_pay',
				'title'                      => 'Amazon Pay',
				'description'                => 'Offer customers a fast, secure checkout experience with Amazon Pay.',
				'currencies'                 => array( 'USD' ),
				'currencies_by_country'      => array(
					'US' => array( 'USD' ),
				),
				'fallback_currencies'        => array( 'USD', 'AUD', 'GBP', 'DKK', 'EUR', 'HKD', 'JPY', 'NZD', 'NOK', 'SEK', 'CHF', 'ZAR' ),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::MULTI_CURRENCY,
					self::TOKENIZATION,
					self::CAPTURE_LATER,
					self::EXPRESS_CHECKOUT,
				),
				'icon'                       => 'assets/images/payment-methods/amazon-pay.svg',
			),
		);
	}
}
