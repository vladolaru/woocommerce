<?php
/**
 * WooPayments invoice exception compatibility.
 *
 * @deprecated 11.4.0 No public replacement. Removed in WooCommerce 12.0.0.
 */

namespace WCPay\MultiCurrency\Exceptions;

class_alias( \Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException::class, 'WCPay\MultiCurrency\Exceptions\InvalidCurrencyRateException' );
