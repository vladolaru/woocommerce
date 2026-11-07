<?php
/**
 * PaymentMethodRegistry class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

/**
 * The WooPayments payment method definitions, filtered by account capability, country and currency.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentMethodRegistry {

	private const REFUNDS = 'refunds';

	private const MULTI_CURRENCY = 'multi_currency';

	private const TOKENIZATION = 'tokenization';

	/**
	 * Capability marking a method whose payment can be authorized now and captured later.
	 *
	 * Public so the intent request asks for manual capture only for methods that have it.
	 */
	public const CAPTURE_LATER = 'capture_later';

	private const BUY_NOW_PAY_LATER = 'buy_now_pay_later';

	/**
	 * Capability marking a method as restricted to the account's domestic currency.
	 *
	 * Public so Multi-Currency reconciliation can honor the restriction when computing the currencies an enabled method requires.
	 */
	public const DOMESTIC_TRANSACTIONS_ONLY = 'domestic_transactions_only';

	private const EXPRESS_CHECKOUT = 'express_checkout';

	/**
	 * Payment methods WooPayments discontinued: they have no definition here, and settings that still name them are cleaned when a store switches to the built-in WooPayments.
	 */
	public const DISCONTINUED_PAYMENT_METHOD_IDS = array( 'giropay', 'sofort' );

	/**
	 * Payment method IDs the WooPayments settings accept and report.
	 */
	public const SETTINGS_PAYMENT_METHOD_IDS = array(
		'alipay',
		'amazon_pay',
		'apple_pay',
		'au_becs_debit',
		'bancontact',
		'card',
		'eps',
		'google_pay',
		'grabpay',
		'ideal',
		'jcb',
		'klarna',
		'link',
		'multibanco',
		'p24',
		'sepa_debit',
		'wechat_pay',
		'affirm',
		'afterpay_clearpay',
	);

	/**
	 * Payment method definitions keyed by payment method ID.
	 *
	 * @var array<string,PaymentMethodDefinition>
	 */
	private array $definitions = array();

	/**
	 * Get the definitions WooPayments registers on this store, keyed by payment method ID in WooPayments' display order.
	 *
	 * Every definition, with Amazon Pay only while its feature is on. The availability filter does not apply here: it
	 * decides only which of these methods the account is offered (get_available_payment_method_ids_for_account()).
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return array<string,PaymentMethodDefinition>
	 */
	public function get_registered( WooPaymentsAccountService $account_service ): array {
		$this->initialize_definitions();

		$definitions = $this->definitions;
		if ( ! $this->is_amazon_pay_enabled( $account_service ) ) {
			unset( $definitions['amazon_pay'] );
		}

		return $definitions;
	}

	/**
	 * Tell whether Amazon Pay is enabled by account policy: it needs Express Checkout confirmation tokens.
	 *
	 * @since 11.2.0
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	public function is_amazon_pay_enabled( WooPaymentsAccountService $account_service ): bool {
		return $account_service->is_ece_confirmation_tokens_enabled();
	}

	/**
	 * Tell whether Link folds into the card payment method for a currency.
	 *
	 * The single source of truth for Link enablement: the Payment Element and
	 * the intent's payment_method_types must agree, or a Link-paid checkout
	 * produces a credential the intent does not declare.
	 *
	 * @since 11.2.0
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param string                    $currency        Checkout currency.
	 * @return bool
	 */
	public function is_link_folded_into_card( WooPaymentsAccountService $account_service, string $currency ): bool {
		$method_ids = $account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );

		$enabled_method_ids = array();
		foreach ( (array) $method_ids as $method_id ) {
			if ( is_scalar( $method_id ) ) {
				$enabled_method_ids[] = sanitize_key( (string) $method_id );
			}
		}

		if ( ! in_array( 'card', $enabled_method_ids, true ) || ! in_array( 'link', $enabled_method_ids, true ) ) {
			return false;
		}

		$link_definition = $this->get( 'link' );
		if ( null === $link_definition || ! $link_definition->is_available_for( $currency, $account_service->get_account_country() ) ) {
			return false;
		}

		$account_data = $account_service->get_cached_account_data();
		$fees         = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();

		return $account_service->is_capability_active( $link_definition->get_account_capability_key() ) && array_key_exists( 'link', $fees );
	}

	/**
	 * Get the payment methods WooPayments offers on this store: the available methods the account has fees for.
	 *
	 * Apple Pay and Google Pay are charged at card fees, so they count while card has fees. Client 11.1.0
	 * `get_upe_available_payment_methods()` (class-wc-payment-gateway-wcpay.php:4848-4879) runs the availability filter
	 * over the registered methods (PaymentMethodDefinitionRegistry.php:97-106), then reads the account's fees. The filter
	 * has the final say, so a callback may add Amazon Pay back while its feature is off.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return string[]
	 */
	public function get_available_payment_method_ids_for_account( WooPaymentsAccountService $account_service ): array {
		$available_ids = $this->filter_available_payment_method_ids( array_keys( $this->get_registered( $account_service ) ) );
		$account_data  = $account_service->get_cached_account_data();
		$fee_ids       = is_array( $account_data['fees'] ?? null ) ? array_map( 'strval', array_keys( $account_data['fees'] ) ) : array();

		if ( in_array( 'card', $fee_ids, true ) ) {
			foreach ( array( 'google_pay', 'apple_pay' ) as $wallet_id ) {
				if ( in_array( $wallet_id, $available_ids, true ) ) {
					$fee_ids[] = $wallet_id;
				}
			}
		}

		return array_values( array_intersect( $available_ids, $fee_ids ) );
	}

	/**
	 * Run the availability filter over a payment method catalog.
	 *
	 * A filter return that is not a list of payment method IDs is ignored, and the catalog is used as it is.
	 *
	 * @param string[] $catalog Payment method IDs.
	 * @return string[]
	 */
	private function filter_available_payment_method_ids( array $catalog ): array {
		/**
		 * Filters the payment methods available to WooPayments.
		 *
		 * @param string[] $payment_method_ids Available payment method IDs.
		 *
		 * @since 11.0.0
		 */
		$payment_method_ids = apply_filters( 'wcpay_upe_available_payment_methods', $catalog );

		if ( ! is_array( $payment_method_ids ) || count( array_filter( $payment_method_ids, 'is_string' ) ) !== count( $payment_method_ids ) ) {
			return $catalog;
		}

		return array_values( $payment_method_ids );
	}

	/**
	 * Get a definition by payment method ID.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @return PaymentMethodDefinition|null
	 */
	public function get( string $payment_method_id ): ?PaymentMethodDefinition {
		$this->initialize_definitions();

		$payment_method_id = strtolower( $payment_method_id );

		return $this->definitions[ $payment_method_id ] ?? null;
	}

	/**
	 * Initialize the definitions map.
	 */
	private function initialize_definitions(): void {
		if ( ! empty( $this->definitions ) ) {
			return;
		}

		foreach ( $this->get_definition_configs() as $config ) {
			$definition                                 = new PaymentMethodDefinition( $config );
			$this->definitions[ $definition->get_id() ] = $definition;
		}
	}


	/**
	 * Translate a catalog title or description at consumption time.
	 *
	 * The definition table in get_definition_configs() stays untranslated on
	 * purpose: definitions are built while gateways are constructed, which can
	 * happen before 'init', where WordPress 6.7+ forbids loading translations.
	 * This map carries the extractable twin of every catalog title and
	 * description; consumers translate when they read, never when they build.
	 *
	 * @param string $text Raw catalog string.
	 * @return string The translation once 'init' has fired; the raw string before that or when unknown.
	 */
	public static function translate_catalog_string( string $text ): string {
		if ( '' === $text || 0 === did_action( 'init' ) ) {
			return $text;
		}

		$translations = array(
			'Card'              => __( 'Card', 'woocommerce' ),
			'Affirm'            => __( 'Affirm', 'woocommerce' ),
			'Afterpay'          => __( 'Afterpay', 'woocommerce' ),
			'Alipay'            => __( 'Alipay', 'woocommerce' ),
			'Bancontact'        => __( 'Bancontact', 'woocommerce' ),
			'BECS Direct Debit' => __( 'BECS Direct Debit', 'woocommerce' ),
			'EPS'               => __( 'EPS', 'woocommerce' ),
			'GrabPay'           => __( 'GrabPay', 'woocommerce' ),
			'iDEAL | Wero'      => __( 'iDEAL | Wero', 'woocommerce' ),
			'Link'              => __( 'Link', 'woocommerce' ),
			'Onelink'           => __( 'Onelink', 'woocommerce' ),
			'Multibanco'        => __( 'Multibanco', 'woocommerce' ),
			'Klarna'            => __( 'Klarna', 'woocommerce' ),
			'Przelewy24 (P24)'  => __( 'Przelewy24 (P24)', 'woocommerce' ),
			'SEPA Direct Debit' => __( 'SEPA Direct Debit', 'woocommerce' ),
			'WeChat Pay'        => __( 'WeChat Pay', 'woocommerce' ),
			'Apple Pay'         => __( 'Apple Pay', 'woocommerce' ),
			'Google Pay'        => __( 'Google Pay', 'woocommerce' ),
			'Amazon Pay'        => __( 'Amazon Pay', 'woocommerce' ),
			'Cash App Afterpay' => __( 'Cash App Afterpay', 'woocommerce' ),
			'Clearpay'          => __( 'Clearpay', 'woocommerce' ),
			'Let your customers pay with major credit and debit cards without leaving your store.' => __( 'Let your customers pay with major credit and debit cards without leaving your store.', 'woocommerce' ),
			'Allow customers to pay over time with Affirm.' => __( 'Allow customers to pay over time with Affirm.', 'woocommerce' ),
			'Allow customers to pay over time with Afterpay.' => __( 'Allow customers to pay over time with Afterpay.', 'woocommerce' ),
			'A digital wallet for customers with mainland China Alipay accounts. Regional versions like AlipayHK are not supported.' => __( 'A digital wallet for customers with mainland China Alipay accounts. Regional versions like AlipayHK are not supported.', 'woocommerce' ),
			'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.' => __( 'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.', 'woocommerce' ),
			'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.' => __( 'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.', 'woocommerce' ),
			'Accept your payment with EPS — a common payment method in Austria.' => __( 'Accept your payment with EPS — a common payment method in Austria.', 'woocommerce' ),
			'A popular digital wallet for cashless payments in Singapore.' => __( 'A popular digital wallet for cashless payments in Singapore.', 'woocommerce' ),
			"Expand your business with iDEAL | Wero — Netherlands's most popular payment method." => __( "Expand your business with iDEAL | Wero — Netherlands's most popular payment method.", 'woocommerce' ),
			"Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience." => __( "Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.", 'woocommerce' ),
			"Onelink autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience." => __( "Onelink autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.", 'woocommerce' ),
			'A voucher based payment method for your customers in Portugal.' => __( 'A voucher based payment method for your customers in Portugal.', 'woocommerce' ),
			'Allow customers to pay over time or pay now with Klarna.' => __( 'Allow customers to pay over time or pay now with Klarna.', 'woocommerce' ),
			'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.' => __( 'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.', 'woocommerce' ),
			'Reach 500 million customers and over 20 million businesses across the European Union.' => __( 'Reach 500 million customers and over 20 million businesses across the European Union.', 'woocommerce' ),
			'A digital wallet for customers with mainland China WeChat Pay wallets. Regional versions like WeChat Pay HK are not supported.' => __( 'A digital wallet for customers with mainland China WeChat Pay wallets. Regional versions like WeChat Pay HK are not supported.', 'woocommerce' ),
			'Apple Pay is an easy and secure way for customers to pay on your store.' => __( 'Apple Pay is an easy and secure way for customers to pay on your store.', 'woocommerce' ),
			'Offer customers a fast, secure checkout experience with Google Pay.' => __( 'Offer customers a fast, secure checkout experience with Google Pay.', 'woocommerce' ),
			'Offer customers a fast, secure checkout experience with Amazon Pay.' => __( 'Offer customers a fast, secure checkout experience with Amazon Pay.', 'woocommerce' ),
			'Allow customers to pay over time with Cash App Afterpay.' => __( 'Allow customers to pay over time with Cash App Afterpay.', 'woocommerce' ),
			'Allow customers to pay over time with Clearpay.' => __( 'Allow customers to pay over time with Clearpay.', 'woocommerce' ),
		);

		return $translations[ $text ] ?? $text;
	}

	/**
	 * Get definition configs in WooPayments' display order, excluding discontinued methods.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_definition_configs(): array {
		return array(
			array(
				'id'                         => 'card',
				'stripe_id'                  => 'card_payments',
				'stripe_payment_method_type' => 'card',
				'title'                      => 'Card',
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
				'settings_icon'              => 'assets/images/payment-methods/affirm-badge-color.svg',
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
				'icon'                       => 'assets/images/payment-methods/afterpay-badge-color.svg',
				'dark_icon'                  => 'assets/images/payment-methods/afterpay-badge-color.svg',
				'settings_icon'              => 'assets/images/payment-methods/afterpay-logo.svg',
				'icons_by_country'           => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-logo.svg',
					'GB' => 'assets/images/payment-methods/clearpay-icon.svg',
				),
				'dark_icons_by_country'      => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-logo-dark.svg',
					'GB' => 'assets/images/payment-methods/clearpay-icon.svg',
				),
				'settings_icons_by_country'  => array(
					'US' => 'assets/images/payment-methods/afterpay-cashapp-badge.svg',
					'GB' => 'assets/images/payment-methods/clearpay-icon.svg',
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
				'icon'                       => 'assets/images/payment-methods/alipay-logo-color.svg',
			),
			array(
				'id'                         => 'bancontact',
				'stripe_id'                  => 'bancontact_payments',
				'stripe_payment_method_type' => 'bancontact',
				'title'                      => 'Bancontact',
				'description'                => 'Bancontact is a bank redirect payment method offered by more than 80% of online businesses in Belgium.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'BE' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/bancontact-color.svg',
			),
			array(
				'id'                         => 'au_becs_debit',
				'stripe_id'                  => 'au_becs_debit_payments',
				'stripe_payment_method_type' => 'au_becs_debit',
				'title'                      => 'BECS Direct Debit',
				'description'                => 'Bulk Electronic Clearing System — Accept secure bank transfer from Australia.',
				'currencies'                 => array( 'AUD' ),
				'countries'                  => array( 'AU' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/bank-debit-color.svg',
			),
			array(
				'id'                         => 'eps',
				'stripe_id'                  => 'eps_payments',
				'stripe_payment_method_type' => 'eps',
				'title'                      => 'EPS',
				'description'                => 'Accept your payment with EPS — a common payment method in Austria.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'AT' ),
				'capabilities'               => array( self::REFUNDS ),
				// Client 11.1.0 EpsDefinition uses its coloured EPS mark at checkout, in dark mode and in settings; core ships it as eps-color.svg (eps.svg stays for the Blocks icon list).
				'icon'                       => 'assets/images/payment-methods/eps-color.svg',
			),
			array(
				'id'                         => 'grabpay',
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
				'icon'                       => 'assets/images/payment-methods/grabpay-color.svg',
			),
			array(
				'id'                         => 'ideal',
				'stripe_id'                  => 'ideal_payments',
				'stripe_payment_method_type' => 'ideal',
				'title'                      => 'iDEAL | Wero',
				'description'                => "Expand your business with iDEAL | Wero — Netherlands's most popular payment method.",
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'NL' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/ideal.svg',
				'dark_icon'                  => 'assets/images/payment-methods/ideal-dark-color.svg',
				// Client 11.1.0 IdealDefinition::get_settings_icon_url() is its iDEAL | Wero tile; core ships it as ideal-wero.svg.
				'settings_icon'              => 'assets/images/payment-methods/ideal-wero.svg',
			),
			array(
				'id'                         => 'link',
				'publish_gateway'            => false,
				'stripe_id'                  => 'link_payments',
				'stripe_payment_method_type' => 'link',
				'title'                      => 'Link',
				'titles_by_country'          => array(
					'GB' => 'Onelink',
				),
				'description'                => "Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
				'descriptions_by_country'    => array(
					'GB' => "Onelink autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
				),
				'currencies'                 => array( 'USD', 'GBP' ),
				'countries'                  => array(),
				'capabilities'               => array(
					self::REFUNDS,
					self::TOKENIZATION,
				),
				'icon'                       => 'assets/images/payment-methods/link-color.svg',
				'icons_by_country'           => array(
					'GB' => 'assets/images/payment-methods/onelink.svg',
				),
			),
			array(
				'id'                         => 'multibanco',
				'stripe_id'                  => 'multibanco_payments',
				'stripe_payment_method_type' => 'multibanco',
				'title'                      => 'Multibanco',
				'description'                => 'A voucher based payment method for your customers in Portugal.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'PT' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/multibanco-logo.svg',
				'dark_icon'                  => 'assets/images/payment-methods/multibanco-logo-dark.svg',
				'settings_icon'              => 'assets/images/payment-methods/multibanco-color.svg',
			),
			array(
				'id'                         => 'klarna',
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
				'settings_icon'              => 'assets/images/payment-methods/klarna-color.svg',
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
				'offered_to_new_accounts'    => false,
				'stripe_id'                  => 'p24_payments',
				'stripe_payment_method_type' => 'p24',
				'title'                      => 'Przelewy24 (P24)',
				'description'                => 'Accept payments with Przelewy24 (P24), the most popular payment method in Poland.',
				'currencies'                 => array( 'EUR', 'PLN' ),
				'countries'                  => array( 'PL' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/p24-color.svg',
			),
			array(
				'id'                         => 'sepa_debit',
				'stripe_id'                  => 'sepa_debit_payments',
				'stripe_payment_method_type' => 'sepa_debit',
				'title'                      => 'SEPA Direct Debit',
				'description'                => 'Reach 500 million customers and over 20 million businesses across the European Union.',
				'currencies'                 => array( 'EUR' ),
				'countries'                  => array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'GB', 'SM', 'VA', 'AD', 'MC', 'LI', 'NO', 'IS' ),
				'capabilities'               => array( self::REFUNDS ),
				'icon'                       => 'assets/images/payment-methods/sepa-debit-color.svg',
			),
			array(
				'id'                         => 'wechat_pay',
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
				'icon'                       => 'assets/images/payment-methods/apple-pay-color.svg',
			),
			array(
				'id'                         => 'google_pay',
				'account_capability_key'     => 'card_payments',
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
				'icon'                       => 'assets/images/payment-methods/google-pay-color.svg',
			),
			array(
				'id'                         => 'amazon_pay',
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
				'icon'                       => 'assets/images/payment-methods/amazon-pay-color.svg',
			),
		);
	}
}
