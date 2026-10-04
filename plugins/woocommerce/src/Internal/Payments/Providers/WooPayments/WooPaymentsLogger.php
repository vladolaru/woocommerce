<?php
/**
 * WooPaymentsLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * Writes WooPayments log lines under one source, only when debug logging is on or in dev mode.
 *
 * Mirrors client 11.1.0 `src/Internal/Logger.php:22,64-91`: every level is gated, and the setting is read
 * from the gateway settings option so no gateway needs to be initialized.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsLogger {

	/**
	 * Log source the client writes to.
	 */
	public const SOURCE = 'woopayments';

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
	 * Tell whether log lines are written: in dev mode or with the gateway's `enable_logging` setting on.
	 *
	 * @return bool
	 */
	public function can_log(): bool {
		return $this->account_service->is_dev_mode_enabled() || 'yes' === $this->account_service->get_gateway_setting( 'enable_logging' );
	}

	/**
	 * Write a log line when logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Log level.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function log( string $message, string $level = 'info', array $context = array() ): void {
		if ( ! $this->can_log() ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
	}

	/**
	 * Write an error line when logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( $message, 'error', $context );
	}
}
