<?php
/**
 * WooPaymentsTransportLog class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Writes platform requests and responses to the WooPayments log when debug logging is on.
 *
 * Logging is on in dev mode or with the gateway's `enable_logging` setting, the one gate every WooPayments log line
 * uses (WooPaymentsLogger::can_log()), and lines go to the WooPayments log source. Callers redact what they pass.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTransportLog {

	/**
	 * WooPayments logger, whose gate and source the transport log uses.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLogger $logger WooPayments logger.
	 */
	final public function init( WooPaymentsLogger $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Tell whether transport lines are written.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->logger->can_log();
	}

	/**
	 * Write an info line when logging is on.
	 *
	 * @param string              $message Line text.
	 * @param array<string,mixed> $context Context, already redacted.
	 */
	public function info( string $message, array $context = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wc_get_logger()->info( $message, array_merge( $context, array( 'source' => WooPaymentsLogger::SOURCE ) ) );
	}

	/**
	 * Write an error line when logging is on.
	 *
	 * @param string $message Line text, already redacted.
	 */
	public function error( string $message ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wc_get_logger()->error( $message, array( 'source' => WooPaymentsLogger::SOURCE ) );
	}

	/**
	 * Write a debug line when logging is on.
	 *
	 * @param string              $message Line text.
	 * @param array<string,mixed> $context Context, already redacted.
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wc_get_logger()->debug( $message, array_merge( $context, array( 'source' => WooPaymentsLogger::SOURCE ) ) );
	}
}
