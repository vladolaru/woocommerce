<?php
/**
 * The constants for handling custom_id in the webhook requests.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

/**
 * Interface CustomIds
 */
interface CustomIds {

	public const CUSTOMER_ID_PREFIX = 'pcp_customer_';
}
