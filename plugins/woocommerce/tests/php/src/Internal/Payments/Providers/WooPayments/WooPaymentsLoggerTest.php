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

	/** @return array<string,array{string,bool,bool}> */
	public static function logging_states(): array {
		return array(
			'logging off'           => array( 'no', false, false ),
			'logging on'            => array( 'yes', false, true ),
			'dev mode, logging off' => array( 'no', true, true ),
		);
	}
}
