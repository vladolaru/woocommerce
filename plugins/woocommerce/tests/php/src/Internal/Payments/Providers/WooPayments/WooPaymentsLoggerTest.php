<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * WooPayments log lines follow client 11.1.0 `src/Internal/Logger.php:22,64-91`: written under `woopayments`, and only
 * in dev mode or with the gateway's `enable_logging` setting on.
 */
class WooPaymentsLoggerTest extends WC_Unit_Test_Case {

	/**
	 * @testdox With debug logging $logging and dev mode $dev_mode, an error line is written: $expected.
	 * @dataProvider logging_states
	 *
	 * @param string $logging  Gateway `enable_logging` setting.
	 * @param bool   $dev_mode Whether WooPayments runs in dev mode.
	 * @param bool   $expected Whether the line is written.
	 */
	public function test_writes_error_only_when_logging_is_on( string $logging, bool $dev_mode, bool $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $logging ) );
		add_filter( 'wcpay_dev_mode', $dev_mode ? '__return_true' : '__return_false' );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );
		$sut = new WooPaymentsLogger();
		$sut->init( $account_service );

		$written = array();
		$filter  = function ( $message, $level, $context ) use ( &$written ) {
			$written[] = array( $level, $message, $context );
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try {
			$sut->error(
				'Error occurred during the payment process. Exception: Card declined',
				array(
					'order_id' => 42,
					'source'   => 'payment-info',
				)
			);
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $filter, 10 );
		}

		$lines = array_values( array_filter( $written, static fn( array $line ): bool => 'Error occurred during the payment process. Exception: Card declined' === $line[1] ) );
		if ( ! $expected ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$this->assertNotSame( array(), $lines );
		$this->assertSame( 'error', $lines[0][0] );
		$this->assertSame( 'woopayments', $lines[0][2]['source'] );
		$this->assertSame( 42, $lines[0][2]['order_id'] );
	}

	/**
	 * @testdox A line logged without a level is written at info level, as client 11.1.0 `Logger::log()` does.
	 */
	public function test_log_writes_info_by_default(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );
		$sut = new WooPaymentsLogger();
		$sut->init( $account_service );

		$written = array();
		$filter  = function ( $message, $level, $context ) use ( &$written ) {
			$written[] = array( $level, $message, $context['source'] ?? '' );
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try {
			$sut->log( 'Stripe Billing product sync failed for product 7' );
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $filter, 10 );
		}

		$this->assertContains( array( 'info', 'Stripe Billing product sync failed for product 7', 'woopayments' ), $written );
	}

	/**
	 * @testdox With debug logging off, a caught $throwable_class is written: $expected.
	 *
	 * The client catches only exceptions at these sites, so a PHP Error would fatal there; native writes it always.
	 *
	 * @testWith ["TypeError", true]
	 *           ["RuntimeException", false]
	 *
	 * @param string $throwable_class Class of the caught throwable.
	 * @param bool   $expected        Whether the line is written.
	 */
	public function test_writes_a_php_error_whatever_the_logging_setting( string $throwable_class, bool $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ) );
		$sut = new WooPaymentsLogger();
		$sut->init( $account_service );
		$throwable = ( static function ( string $secret ) use ( $throwable_class ) {
			unset( $secret ); // Only here to put an argument in the trace.
			return new $throwable_class( 'Argument #1 must be of type array', 7 );
		} )( 'secret-call-argument' );

		$written = array();
		$filter  = function ( $message, $level, $context ) use ( &$written ) {
			$written[] = array( $level, $message, $context );
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try {
			$sut->log_throwable( 'Error completing the payment: Argument #1 must be of type array', $throwable, array( 'order_id' => 42 ), 'info' );
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $filter, 10 );
		}

		$lines = array_values( array_filter( $written, static fn( array $line ): bool => 'Error completing the payment: Argument #1 must be of type array' === $line[1] ) );
		if ( ! $expected ) {
			$this->assertSame( array(), $lines );
			return;
		}
		$this->assertNotSame( array(), $lines );
		$this->assertSame( 'error', $lines[0][0], 'A PHP Error is written at error level whatever level the site asks for.' );
		$this->assertSame( 'woopayments', $lines[0][2]['source'] );
		$this->assertSame( 42, $lines[0][2]['order_id'] );
		$this->assertSame( 'TypeError', $lines[0][2]['exception'] );
		$this->assertSame( 7, $lines[0][2]['code'] );
		$this->assertStringContainsString( __CLASS__ . '->' . __FUNCTION__ . '()', $lines[0][2]['trace'] );
		$this->assertStringNotContainsString( 'secret-call-argument', $lines[0][2]['trace'], 'The trace leaves out call arguments.' );
	}

	/** @return array<string,array{string,bool,bool}> */
	public static function logging_states(): array {
		return array(
			'logging off'           => array( 'no', false, false ),
			'logging on'            => array( 'yes', false, true ),
			'dev mode, logging off' => array( 'no', true, true ),
		);
	}
}
