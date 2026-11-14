<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Logging\LogHandlerFileV2;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingMigrationLogHandler;
use Automattic\WooCommerce\Utilities\LoggingUtil;
use WC_Log_Handler_DB;
use WC_Log_Handler_File;
use WC_Logger;
use WC_Unit_Test_Case;

/**
 * Migration log kept through WooCommerce's log cleanup.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-subscription-migration-log-handler.php`).
 */
class StripeBillingMigrationLogHandlerTest extends WC_Unit_Test_Case {

	/**
	 * Source of the log entries WooCommerce's cleanup must delete.
	 */
	private const OTHER_SOURCE = 'rec-t63-other-log-source';

	/**
	 * The one "now" the handler and the expectations share.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Give the handler and the expectations one clock, so a run that crosses midnight UTC still sees one date.
	 *
	 * The clock is a day behind, so a handler that reads the real date instead of the proxied one fails every run. Migration
	 * log files other tests left behind (the migrator writes real ones) are removed first, so each test counts only its own.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->delete_log_files();
		$this->now = time() - DAY_IN_SECONDS;
		$now       = $this->now;
		$this->register_legacy_proxy_function_mocks(
			array(
				'gmdate' => static fn( string $format, ?int $timestamp = null ): string => gmdate( $format, $timestamp ?? $now ),
			)
		);
	}

	/**
	 * Remove the log files and entries the tests wrote, and the log handler constant.
	 */
	public function tearDown(): void {
		global $wpdb;

		try {
			$this->delete_log_files();
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}woocommerce_log WHERE source IN ( %s, %s )",
					StripeBillingMigrationLogHandler::HANDLE,
					self::OTHER_SOURCE
				)
			);
			Constants::clear_single_constant( 'WC_LOG_HANDLER' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should rename an aged migration log file to today's date when WooCommerce cleans up logs, keeping its content: $_dataName.
	 *
	 * WooCommerce writes files with LogHandlerFileV2 by default, and names it in WC_LOG_HANDLER on stores that pin it
	 * (Settings::get_default_handler()). Both are file handlers to core (Settings::get_settings_definitions()). The
	 * constant row fails when the handler is detected only by an exact WC_Log_Handler_File match.
	 *
	 * @testWith ["default handler", null]
	 *           ["WC_LOG_HANDLER names LogHandlerFileV2", "Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2"]
	 *
	 * @param string      $name     Case label.
	 * @param string|null $constant WC_LOG_HANDLER value, or null to leave it undefined.
	 */
	public function test_keeps_aged_migration_log_files( string $name, ?string $constant ): void {
		unset( $name );
		if ( null !== $constant ) {
			Constants::set_constant( 'WC_LOG_HANDLER', $constant );
		}
		$this->assertSame( $constant ?? LogHandlerFileV2::class, LoggingUtil::get_default_handler(), 'The premise: WooCommerce writes with LogHandlerFileV2.' );
		$message = 'Migrating subscription #4712.';
		$sut     = new StripeBillingMigrationLogHandler();
		$sut->init_hooks();
		$sut->log( $message );

		$log_files = $this->get_log_files();
		$this->assertCount( 1, $log_files, 'The migration log file is written.' );
		$old_date = gmdate( 'Y-m-d', $this->now - YEAR_IN_SECONDS );
		$aged     = trailingslashit( WC_LOG_DIR ) . preg_replace( '/\d{4}-\d{2}-\d{2}/', $old_date, basename( $log_files[0] ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $log_files[0], $aged );

		do_action( 'woocommerce_cleanup_logs' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$log_files = $this->get_log_files();
		$this->assertCount( 1, $log_files );
		$this->assertStringContainsString( gmdate( 'Y-m-d', $this->now ), basename( $log_files[0] ) );
		$this->assertStringContainsString( $message, (string) file_get_contents( $log_files[0] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * @testdox Should merge an aged migration log file into today's, older entries first.
	 */
	public function test_merges_aged_migration_logs_into_todays_in_order(): void {
		$sut = new StripeBillingMigrationLogHandler();
		$sut->init_hooks();
		$log_dir  = trailingslashit( WC_LOG_DIR );
		$hash     = wp_hash( StripeBillingMigrationLogHandler::HANDLE );
		$old_path = $log_dir . StripeBillingMigrationLogHandler::HANDLE . '-' . gmdate( 'Y-m-d', $this->now - DAY_IN_SECONDS ) . "-{$hash}.log";
		$new_path = $log_dir . StripeBillingMigrationLogHandler::HANDLE . '-' . gmdate( 'Y-m-d', $this->now ) . "-{$hash}.log";
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $old_path, "Old message from yesterday\n" );
		file_put_contents( $new_path, "New message from today\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		do_action( 'woocommerce_cleanup_logs' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertFileDoesNotExist( $old_path );
		$this->assertSame( "Old message from yesterday\nNew message from today\n", file_get_contents( $new_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * @testdox Should keep aged migration log entries in the database through WooCommerce's cleanup, with their timestamps, while other aged entries go: $_dataName.
	 *
	 * A store picks the database handler with WC_LOG_HANDLER or in WooCommerce > Status > Logs, which stores the
	 * woocommerce_logs_default_handler option (Settings::get_default_handler()). The setting row fails when the handler
	 * is read from the constant alone.
	 *
	 * @testWith ["WC_LOG_HANDLER"]
	 *           ["logs setting"]
	 *
	 * @param string $chosen_by Where the store chose the database handler.
	 */
	public function test_keeps_aged_migration_log_entries_in_the_database( string $chosen_by ): void {
		global $wpdb;

		if ( 'WC_LOG_HANDLER' === $chosen_by ) {
			Constants::set_constant( 'WC_LOG_HANDLER', WC_Log_Handler_DB::class );
		} else {
			update_option( 'woocommerce_logs_default_handler', WC_Log_Handler_DB::class );
		}
		$db_logger = new WC_Logger( array( new WC_Log_Handler_DB() ) );
		add_action( 'woocommerce_cleanup_logs', array( $db_logger, 'clear_expired_logs' ) );
		$sut = new StripeBillingMigrationLogHandler();
		$sut->init_hooks();
		$this->set_logger( $sut, $db_logger );

		$sut->log( 'Migrating subscription #4712.' );
		$db_logger->log( 'debug', 'Another message.', array( 'source' => self::OTHER_SOURCE ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}woocommerce_log SET timestamp = DATE_SUB( timestamp, INTERVAL 1 YEAR ) WHERE source IN ( %s, %s )",
				StripeBillingMigrationLogHandler::HANDLE,
				self::OTHER_SOURCE
			)
		);
		$aged_timestamp = $this->get_migration_log_entry()['timestamp'];

		do_action( 'woocommerce_cleanup_logs' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_log WHERE source = %s", self::OTHER_SOURCE ) ) );
		$entry = $this->get_migration_log_entry();
		$this->assertSame( $aged_timestamp, $entry['timestamp'], 'The real timestamp is restored.' );
		$this->assertNull( $entry['context'] );
	}

	/**
	 * Get the one migration log entry in the database.
	 *
	 * @return array<string,mixed>
	 */
	private function get_migration_log_entry(): array {
		global $wpdb;

		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, context FROM {$wpdb->prefix}woocommerce_log WHERE source = %s", StripeBillingMigrationLogHandler::HANDLE ), ARRAY_A );
		$this->assertCount( 1, $entries );

		return $entries[0];
	}

	/**
	 * Get the log files of the migration log and of these tests.
	 *
	 * @return string[]
	 */
	private function get_log_files(): array {
		$log_files = array();
		foreach ( WC_Log_Handler_File::get_log_files() as $log_file_name ) {
			if ( 0 === strpos( $log_file_name, StripeBillingMigrationLogHandler::HANDLE ) || 0 === strpos( $log_file_name, self::OTHER_SOURCE ) ) {
				$log_files[] = trailingslashit( WC_LOG_DIR ) . $log_file_name;
			}
		}

		return $log_files;
	}

	/**
	 * Delete the log files of the migration log and of these tests.
	 */
	private function delete_log_files(): void {
		foreach ( $this->get_log_files() as $log_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $log_file );
		}
	}

	/**
	 * Make the handler log through the given logger.
	 *
	 * @param StripeBillingMigrationLogHandler $sut    Handler.
	 * @param WC_Logger                        $logger Logger.
	 */
	private function set_logger( StripeBillingMigrationLogHandler $sut, WC_Logger $logger ): void {
		$property = new \ReflectionProperty( $sut, 'logger' );
		$property->setAccessible( true );
		$property->setValue( $sut, $logger );
	}
}
