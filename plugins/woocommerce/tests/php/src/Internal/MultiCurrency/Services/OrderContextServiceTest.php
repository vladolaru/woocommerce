<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderContextService class.
 */
class OrderContextServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should use the order's currency only on a supported order page while formatting an order total: $_dataName.
	 * @dataProvider provide_order_contexts
	 *
	 * Client 11.1.0 checks the 'orders' query var alongside order-pay, order-received and view-order before honoring an
	 * order's own currency (FrontendCurrencies.php:461-472).
	 *
	 * @param array<int,string>   $backtrace  Backtrace summary.
	 * @param array<string,mixed> $query_vars WordPress query vars.
	 * @param bool                $expected   Whether the order's currency is used.
	 */
	public function test_should_use_order_currency_by_page_and_backtrace( array $backtrace, array $query_vars, bool $expected ): void {
		$sut = $this->create_service( $backtrace );

		$this->with_query_vars(
			$query_vars,
			function () use ( $sut, $expected ): void {
				$this->assertSame( $expected, $sut->should_use_order_currency() );
			}
		);
	}

	/** @return array<string,array{array<int,string>,array<string,mixed>,bool}> */
	public static function provide_order_contexts(): array {
		$total = array( 'WC_Order->get_formatted_order_total' );

		return array(
			'order-pay page while formatting a total'  => array(
				$total,
				array(
					'pagename'  => 'checkout',
					'order-pay' => 123,
				),
				true,
			),
			'My Account orders list'                   => array(
				$total,
				array(
					'pagename' => 'my-account',
					'orders'   => 1,
				),
				true,
			),
			'outside the supported pages'              => array(
				$total,
				array(
					'pagename'  => 'shop',
					'order-pay' => 123,
				),
				false,
			),
			'without a supported order query var'      => array( $total, array( 'pagename' => 'checkout' ), false ),
			'without a supported formatting backtrace' => array(
				array( 'Other_Class->render' ),
				array(
					'pagename'       => 'my-account',
					'view-order'     => 123,
					'unrelated-data' => 'present',
				),
				false,
			),
		);
	}

	/**
	 * Create an order context service with a deterministic backtrace.
	 *
	 * @param array<int, string> $backtrace_summary Backtrace summary.
	 * @return OrderContextService
	 */
	private function create_service( array $backtrace_summary ): OrderContextService {
		return new OrderContextService(
			static function () use ( $backtrace_summary ): array {
				return $backtrace_summary;
			}
		);
	}

	/**
	 * Run a callback with temporary WordPress query vars.
	 *
	 * @param array<string, mixed> $query_vars Query vars.
	 * @param callable             $callback   Callback to run.
	 */
	private function with_query_vars( array $query_vars, callable $callback ): void {
		global $wp;

		$this->assertInstanceOf( \WP::class, $wp );

		$previous_query_vars = $wp->query_vars;
		$wp->query_vars      = $query_vars;

		try {
			$callback();
		} finally {
			$wp->query_vars = $previous_query_vars;
		}
	}
}
