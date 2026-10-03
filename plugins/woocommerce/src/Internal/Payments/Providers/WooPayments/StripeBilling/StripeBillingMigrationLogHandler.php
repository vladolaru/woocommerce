<?php
/**
 * StripeBillingMigrationLogHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\Jetpack\Constants;
use WC_Log_Handler_DB;
use WC_Log_Handler_File;
use WC_Logger_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the log of the migration off Stripe Billing, and keeps it through WooCommerce's log cleanup.
 *
 * Port of client 11.1.0 `includes/subscriptions/class-wc-payments-subscription-migration-log-handler.php`, with its log source,
 * so the plugin and native write and keep the same log.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingMigrationLogHandler {

	/**
	 * Log source.
	 */
	public const HANDLE = 'woopayments-subscription-migration';

	/**
	 * Flag stored in the context column of database log entries while their life is extended.
	 */
	public const EXTENDED_DB_ENTRY_FLAG = 'extended_migration_log';

	/**
	 * Years added to a database log entry while WooCommerce cleans up logs.
	 */
	private const DB_ENTRY_EXTENSION_IN_YEARS = 5;

	/**
	 * Logger, fetched on first use.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private ?WC_Logger_Interface $logger = null;

	/**
	 * Keep migration logs through WooCommerce's log cleanup, which deletes logs at priority 10.
	 */
	public function init_hooks(): void {
		if ( $this->has_file_logger_enabled() ) {
			add_action( 'woocommerce_cleanup_logs', array( $this, 'extend_life_of_migration_file_logs' ), 5 );
		} elseif ( $this->has_db_logger_enabled() ) {
			add_action( 'woocommerce_cleanup_logs', array( $this, 'extend_life_of_migration_db_logs' ), 5 );
			add_action( 'woocommerce_cleanup_logs', array( $this, 'restore_db_log_timestamps' ), 100 );
		}
	}

	/**
	 * Log a message to the migration log.
	 *
	 * @param string $message Message.
	 */
	public function log( string $message ): void {
		if ( ! $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$this->logger->debug( $message, array( 'source' => self::HANDLE ) );
	}

	/**
	 * Rename migration log files to today's date, merging into today's file when there is one, so the date-based cleanup keeps them.
	 *
	 * @internal
	 */
	public function extend_life_of_migration_file_logs(): void {
		$log_dir = trailingslashit( WC_LOG_DIR );

		foreach ( WC_Log_Handler_File::get_log_files() as $log_file_name ) {
			if ( 0 !== strpos( $log_file_name, self::HANDLE ) ) {
				continue;
			}

			$old_file_path = $log_dir . $log_file_name;
			$new_file_name = $this->get_updated_log_filename( $log_file_name );
			$new_file_path = $log_dir . $new_file_name;

			if ( $new_file_name === $log_file_name || ! file_exists( $old_file_path ) ) {
				continue;
			}

			// Append today's file to the old one, so older entries come first, then give the result today's name.
			if ( file_exists( $new_file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$source_handle = fopen( $new_file_path, 'r' );
				if ( false !== $source_handle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $old_file_path, $source_handle, FILE_APPEND );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $source_handle );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $new_file_path );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			rename( $old_file_path, $new_file_path );
		}
	}

	/**
	 * Move migration database log entries five years ahead, flagged, so the cleanup at priority 10 keeps them.
	 *
	 * `restore_db_log_timestamps()` moves them back after the cleanup.
	 *
	 * @internal
	 */
	public function extend_life_of_migration_db_logs(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}woocommerce_log
				SET timestamp = DATE_ADD( timestamp, INTERVAL %d YEAR ), context = %s
				WHERE source = %s",
				self::DB_ENTRY_EXTENSION_IN_YEARS,
				self::EXTENDED_DB_ENTRY_FLAG,
				self::HANDLE
			)
		);
	}

	/**
	 * Move the flagged migration database log entries back to their real timestamps.
	 *
	 * @internal
	 */
	public function restore_db_log_timestamps(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}woocommerce_log
				SET timestamp = DATE_SUB( timestamp, INTERVAL %d YEAR ), context = NULL
				WHERE source = %s AND context = %s",
				self::DB_ENTRY_EXTENSION_IN_YEARS,
				self::HANDLE,
				self::EXTENDED_DB_ENTRY_FLAG
			)
		);
	}

	/**
	 * Get a log file name with today's date, keeping its suffix: `woopayments-subscription-migration-YYYY-MM-DD-{suffix}.log`.
	 *
	 * @param string $old_filename Log file name.
	 * @return string
	 */
	private function get_updated_log_filename( string $old_filename ): string {
		$pattern = '/^(' . preg_quote( self::HANDLE, '/' ) . ')-(\d{4}-\d{2}-\d{2})-(.+)\.log$/';

		if ( ! preg_match( $pattern, $old_filename, $matches ) ) {
			return $old_filename;
		}

		$today = gmdate( 'Y-m-d' );

		return $matches[2] === $today ? $old_filename : "{$matches[1]}-{$today}-{$matches[3]}.log";
	}

	/**
	 * Get the log handler class WooCommerce uses: `WC_LOG_HANDLER`, or the file handler.
	 *
	 * @return string
	 */
	private function get_default_log_handler_class(): string {
		$handler_class = Constants::get_constant( 'WC_LOG_HANDLER' );

		if ( ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
			$handler_class = WC_Log_Handler_File::class;
		}

		return $handler_class;
	}

	/**
	 * Tell whether WooCommerce logs to files.
	 *
	 * @return bool
	 */
	private function has_file_logger_enabled(): bool {
		return WC_Log_Handler_File::class === $this->get_default_log_handler_class();
	}

	/**
	 * Tell whether WooCommerce logs to the database.
	 *
	 * @return bool
	 */
	private function has_db_logger_enabled(): bool {
		return WC_Log_Handler_DB::class === $this->get_default_log_handler_class();
	}
}
