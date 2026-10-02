<?php
/**
 * StripeBillingLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the module's log lines only when WooPayments debug logging is on or in dev mode, as client 11.1.0's `Logger::log()` does.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingLogger {

	/**
	 * Log source the client writes to.
	 */
	private const SOURCE = 'woopayments';

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Write a log line when logging is enabled.
	 *
	 * @param string $message Message.
	 * @param string $level   Log level.
	 */
	public function log( string $message, string $level = 'info' ): void {
		if ( ! $this->account_service->is_dev_mode_enabled() && 'yes' !== $this->account_service->get_gateway_setting( 'enable_logging' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
