<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMoneyMovementOrderService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use WC_Order;

/**
 * Shared fixtures for native WooPayments money-movement REST controller tests.
 *
 * Used by the dedicated authorizations and disputes controller test classes, and by the
 * remaining transactions/payment-details coverage in {@see WooPaymentsMoneyMovementRestControllerTest}.
 */
trait WooPaymentsMoneyMovementControllerTestTrait {

	/**
	 * Create a runtime arbiter stub.
	 *
	 * @param bool $native_register Whether native should own routes.
	 * @return NativePaymentsRuntimeArbiter
	 */
	private function create_arbiter( bool $native_register ): NativePaymentsRuntimeArbiter {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		return $arbiter;
	}

	/**
	 * Create an order enrichment service.
	 *
	 * @return WooPaymentsMoneyMovementOrderService
	 */
	private function create_order_service(): WooPaymentsMoneyMovementOrderService {
		$service = new WooPaymentsMoneyMovementOrderService();
		$service->init( new WooPaymentsOrderDataService() );

		return $service;
	}

	/**
	 * Create an order with WooPayments charge metadata.
	 *
	 * @param string $charge_id Charge ID.
	 * @param string $intent_id Intent ID.
	 * @return WC_Order
	 */
	private function create_order_with_charge( string $charge_id, string $intent_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_currency( 'USD' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_customer_ip_address( '127.0.0.1' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->save();

		return $order;
	}

	/**
	 * Create a recording logger test double.
	 *
	 * @return object
	 */
	private function create_recording_logger(): object {
		return new class() implements \WC_Logger_Interface {
			/**
			 * Logged entries.
			 *
			 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
			 */
			public array $entries = array();

			/**
			 * Add a log entry.
			 *
			 * @param string $handle  File handle.
			 * @param string $message Log message.
			 * @param string $level   Log level.
			 * @return bool
			 */
			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				$this->record( $level, $message, array( 'source' => $handle ) );

				return true;
			}

			/**
			 * Add a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function log( $level, $message, $context = array() ) {
				$this->record( $level, $message, $context );
			}

			/**
			 * Record an emergency log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function emergency( $message, $context = array() ) {
				$this->record( 'emergency', $message, $context );
			}

			/**
			 * Record an alert log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function alert( $message, $context = array() ) {
				$this->record( 'alert', $message, $context );
			}

			/**
			 * Record a critical log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function critical( $message, $context = array() ) {
				$this->record( 'critical', $message, $context );
			}

			/**
			 * Record an info log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function info( $message, $context = array() ) {
				$this->record( 'info', $message, $context );
			}

			/**
			 * Record an error log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function error( $message, $context = array() ) {
				$this->record( 'error', $message, $context );
			}

			/**
			 * Record a warning log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function warning( $message, $context = array() ) {
				$this->record( 'warning', $message, $context );
			}

			/**
			 * Record a notice log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function notice( $message, $context = array() ) {
				$this->record( 'notice', $message, $context );
			}

			/**
			 * Record a debug log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function debug( $message, $context = array() ) {
				$this->record( 'debug', $message, $context );
			}

			/**
			 * Record a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			private function record( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		};
	}
}
