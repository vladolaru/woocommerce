<?php
/**
 * WooPaymentsPaymentMethodType class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Payment method identifiers used by WooPayments.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsPaymentMethodType {

	/**
	 * Affirm payment method.
	 *
	 * @var string
	 */
	public const AFFIRM = 'affirm';

	/**
	 * Afterpay / Clearpay payment method.
	 *
	 * @var string
	 */
	public const AFTERPAY_CLEARPAY = 'afterpay_clearpay';

	/**
	 * Alipay payment method.
	 *
	 * @var string
	 */
	public const ALIPAY = 'alipay';

	/**
	 * Amazon Pay payment method.
	 *
	 * @var string
	 */
	public const AMAZON_PAY = 'amazon_pay';

	/**
	 * Apple Pay payment method.
	 *
	 * @var string
	 */
	public const APPLE_PAY = 'apple_pay';

	/**
	 * Australian BECS Direct Debit payment method.
	 *
	 * @var string
	 */
	public const AU_BECS_DEBIT = 'au_becs_debit';

	/**
	 * Bancontact payment method.
	 *
	 * @var string
	 */
	public const BANCONTACT = 'bancontact';

	/**
	 * Card payment method.
	 *
	 * @var string
	 */
	public const CARD = 'card';

	/**
	 * In-person card payment method.
	 *
	 * @var string
	 */
	public const CARD_PRESENT = 'card_present';

	/**
	 * EPS payment method.
	 *
	 * @var string
	 */
	public const EPS = 'eps';

	/**
	 * Giropay payment method.
	 *
	 * @var string
	 */
	public const GIROPAY = 'giropay';

	/**
	 * Google Pay payment method.
	 *
	 * @var string
	 */
	public const GOOGLE_PAY = 'google_pay';

	/**
	 * Grabpay payment method.
	 *
	 * @var string
	 */
	public const GRABPAY = 'grabpay';

	/**
	 * IDEAL payment method.
	 *
	 * @var string
	 */
	public const IDEAL = 'ideal';

	/**
	 * In-person Interac payment method.
	 *
	 * @var string
	 */
	public const INTERAC_PRESENT = 'interac_present';

	/**
	 * JCB payment method.
	 *
	 * @var string
	 */
	public const JCB = 'jcb';

	/**
	 * Klarna payment method.
	 *
	 * @var string
	 */
	public const KLARNA = 'klarna';

	/**
	 * Link payment method.
	 *
	 * @var string
	 */
	public const LINK = 'link';

	/**
	 * Multibanco payment method.
	 *
	 * @var string
	 */
	public const MULTIBANCO = 'multibanco';

	/**
	 * Przelewy24 payment method.
	 *
	 * @var string
	 */
	public const P24 = 'p24';

	/**
	 * SEPA Direct Debit payment method.
	 *
	 * @var string
	 */
	public const SEPA_DEBIT = 'sepa_debit';

	/**
	 * Sofort payment method.
	 *
	 * @var string
	 */
	public const SOFORT = 'sofort';

	/**
	 * WeChat Pay payment method.
	 *
	 * @var string
	 */
	public const WECHAT_PAY = 'wechat_pay';
}
