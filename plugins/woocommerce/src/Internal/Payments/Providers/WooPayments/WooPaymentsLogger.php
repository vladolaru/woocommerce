<?php
/**
 * WooPaymentsLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Exception;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Writes WooPayments log lines under one source, only when debug logging is on or in dev mode.
 *
 * Mirrors client 11.1.0 `src/Internal/Logger.php:22,64-91`: every level is gated, and the setting is read
 * from the gateway settings option so no gateway needs to be initialized. The one exception is a PHP Error
 * caught where the client catches only exceptions: see log_throwable().
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
	 * Number of stack frames written for a caught throwable.
	 */
	private const TRACE_FRAMES = 5;

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

	/**
	 * Log a caught throwable with its class, code and a short trace.
	 *
	 * An Exception follows the logging setting, as on the client (`includes/class-logger.php:100-112`). Any other
	 * throwable is a PHP Error the client would let fatal, so it is always written at error level (monitor rule 2026-10-04).
	 *
	 * @param string              $message   Message.
	 * @param Throwable           $throwable Caught throwable.
	 * @param array<string,mixed> $context   Context, such as order_id or intent_id.
	 * @param string              $level     Log level for an Exception.
	 */
	public function log_throwable( string $message, Throwable $throwable, array $context = array(), string $level = 'error' ): void {
		if ( $throwable instanceof Exception ) {
			$this->log( $message, $level, array_merge( $context, self::get_throwable_context( $throwable ) ) );
			return;
		}

		$this->log_throwable_always( $message, $throwable, $context );
	}

	/**
	 * Write an error line for a caught throwable whatever the logging setting.
	 *
	 * @param string              $message   Message.
	 * @param Throwable           $throwable Caught throwable.
	 * @param array<string,mixed> $context   Context, such as order_id or intent_id.
	 */
	public function log_throwable_always( string $message, Throwable $throwable, array $context = array() ): void {
		$this->log_always( $message, 'error', array_merge( $context, self::get_throwable_context( $throwable ) ) );
	}

	/**
	 * Write a line whatever the logging setting, for an anomaly on the money path that support needs to see.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Log level.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function log_always( string $message, string $level, array $context = array() ): void {
		wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
	}

	/**
	 * Get a throwable's class, code and first stack frames, without its message or call arguments.
	 *
	 * Public for the WooPay lines still written under their own log source.
	 *
	 * @param Throwable $throwable Caught throwable.
	 * @return array{exception:string,code:int|string,trace:string}
	 */
	public static function get_throwable_context( Throwable $throwable ): array {
		$frames = array();
		foreach ( array_slice( $throwable->getTrace(), 0, self::TRACE_FRAMES ) as $index => $frame ) {
			$frames[] = sprintf(
				'#%d %s(%s): %s%s%s()',
				$index,
				$frame['file'] ?? '[internal function]',
				$frame['line'] ?? '',
				$frame['class'] ?? '',
				$frame['type'] ?? '',
				$frame['function']
			);
		}

		return array(
			'exception' => get_class( $throwable ),
			'code'      => $throwable->getCode(),
			'trace'     => implode( "\n", $frames ),
		);
	}
}
