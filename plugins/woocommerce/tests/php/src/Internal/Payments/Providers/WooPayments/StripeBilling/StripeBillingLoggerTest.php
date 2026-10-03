<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use WC_Unit_Test_Case;

/**
 * The module logs only when WooPayments logging is on or in dev mode (client 11.1.0 `src/Internal/Logger.php:65,77-95`).
 */
class StripeBillingLoggerTest extends WC_Unit_Test_Case {

	/**
	 * @testdox With debug logging $logging and dev mode $dev_mode, a line is written: $expected.
	 * @dataProvider logging_states
	 *
	 * @param string $logging  Gateway `enable_logging` setting.
	 * @param bool   $dev_mode Whether WooPayments runs in dev mode.
	 * @param bool   $expected Whether the line is written.
	 */
	public function test_logs_only_when_logging_is_on( string $logging, bool $dev_mode, bool $expected ): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_dev_mode_enabled' )->willReturn( $dev_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnMap( array( array( 'enable_logging', null, $logging ) ) );
		$sut = new StripeBillingLogger();
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

		$lines = array_filter( $written, static fn( array $line ): bool => 'Stripe Billing product sync failed for product 7' === $line[1] );
		$this->assertSame( $expected, array() !== $lines );
		if ( $expected ) {
			$line = array_values( $lines )[0];
			$this->assertSame( 'info', $line[0] );
			$this->assertSame( 'woopayments', $line[2] );
		}
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
