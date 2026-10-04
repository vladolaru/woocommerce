<?php
/**
 * Tests for the PayPal fees rows on the order screen.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\FeesRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * The rows the order totals box shows from the fee breakdown the capture stored on the order: the fee, the payout, and
 * the refund rows. The order and the price formatting are real; the rows are read back as label and price text.
 *
 * @group paypal-wallet
 */
class FeesRendererTest extends WalletTestCase {

	/**
	 * The renderer under test.
	 *
	 * @var FeesRenderer
	 */
	private FeesRenderer $renderer;

	/**
	 * Build the renderer.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->renderer = new FeesRenderer();
	}

	/**
	 * An order that is not saved, holding the given fee breakdown and refund fee breakdown as order meta.
	 *
	 * @param mixed $fees        The value of the fees meta, or null for none.
	 * @param mixed $refund_fees The value of the refund fees meta, or null for none.
	 * @return WC_Order
	 */
	private function make_order( $fees, $refund_fees = null ): WC_Order {
		$order = new WC_Order();
		if ( null !== $fees ) {
			$order->update_meta_data( PayPalGateway::FEES_META_KEY, $fees );
		}
		if ( null !== $refund_fees ) {
			$order->update_meta_data( PayPalGateway::REFUND_FEES_META_KEY, $refund_fees );
		}

		return $order;
	}

	/**
	 * A breakdown part in the shape PayPal returns it.
	 *
	 * @param string $value The amount.
	 * @return array{currency_code: string, value: string}
	 */
	private function money( string $value ): array {
		return array(
			'currency_code' => 'USD',
			'value'         => $value,
		);
	}

	/**
	 * The rendered rows as label and price text, for example "PayPal Fee:" => "- $0.41".
	 *
	 * @param string $html The rendered rows.
	 * @return array<string, string>
	 */
	private function rows( string $html ): array {
		preg_match_all( '#<td class="label[^"]*">.*?</span>\s*([^<]+?)\s*</td>.*?<td class="total[^"]*">(.*?)</td>#s', $html, $matches, PREG_SET_ORDER );

		$rows = array();
		foreach ( $matches as $match ) {
			$price                     = html_entity_decode( wp_strip_all_tags( $match[2] ) );
			$rows[ trim( $match[1] ) ] = trim( (string) preg_replace( '/\s+/', ' ', $price ) );
		}

		return $rows;
	}

	/**
	 * @testdox Should render the fee, the refund fee, the refunded amount and the payout as formatted prices.
	 */
	public function test_render(): void {
		$order = $this->make_order(
			array(
				'gross_amount' => $this->money( '10.42' ),
				'paypal_fee'   => $this->money( '0.41' ),
				'net_amount'   => $this->money( '10.01' ),
			),
			array(
				'gross_amount' => $this->money( '20.52' ),
				'paypal_fee'   => $this->money( '0.51' ),
				'net_amount'   => $this->money( '50.01' ),
			)
		);

		$rows = $this->rows( $this->renderer->render( $order ) );

		$this->assertSame( '- $0.41', $rows['PayPal Fee:'] ?? null, 'The fee is shown as a deduction' );
		$this->assertSame( '$10.01', $rows['PayPal Payout:'] ?? null, 'The payout is shown as a plain amount' );
		$this->assertSame( '- $0.51', $rows['PayPal Refund Fee:'] ?? null, 'The refund fee is shown as a deduction' );
		$this->assertSame( '- $50.01', $rows['PayPal Refunded:'] ?? null, 'The refunded amount is shown as a deduction' );
	}

	/**
	 * @testdox Should add a net total row of the payout minus the refunds when the order was refunded.
	 */
	public function test_render_adds_the_net_total_after_a_refund(): void {
		$order = $this->make_order(
			array(
				'paypal_fee' => $this->money( '0.41' ),
				'net_amount' => $this->money( '10.01' ),
			),
			array(
				'paypal_fee' => $this->money( '0.10' ),
				'net_amount' => $this->money( '2.00' ),
			)
		);

		$rows = $this->rows( $this->renderer->render( $order ) );

		$this->assertSame( '$7.91', $rows['PayPal Net Total:'] ?? null, 'The net total is the payout minus the refund fee and the refunded amount' );
	}

	/**
	 * @testdox Should show no net total row when nothing was refunded.
	 */
	public function test_render_shows_no_net_total_without_a_refund(): void {
		$order = $this->make_order(
			array(
				'paypal_fee' => $this->money( '0.41' ),
				'net_amount' => $this->money( '10.01' ),
			)
		);

		$rows = $this->rows( $this->renderer->render( $order ) );

		$this->assertSame( array( 'PayPal Fee:', 'PayPal Payout:' ), array_keys( $rows ) );
	}

	/**
	 * @testdox Should render the fee without a payout row when the breakdown has no net amount.
	 */
	public function test_render_without_net(): void {
		$order = $this->make_order( array( 'paypal_fee' => $this->money( '0.41' ) ), array() );

		$rows = $this->rows( $this->renderer->render( $order ) );

		$this->assertSame( array( 'PayPal Fee:' => '- $0.41' ), $rows );
	}

	/**
	 * @testdox Should render nothing when the stored breakdown holds no usable fee.
	 *
	 * @dataProvider data_no_fees
	 *
	 * @param mixed $fees The fees meta value, or null for none.
	 */
	public function test_no_fees( $fees ): void {
		$this->assertSame( '', $this->renderer->render( $this->make_order( $fees, array() ) ) );
	}

	/**
	 * Breakdowns that do not hold a fee row.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_no_fees(): array {
		return array(
			'a string instead of a breakdown' => array( 'hello' ),
			'an empty breakdown'              => array( array() ),
			'a fee that is not a money part'  => array( array( 'paypal_fee' => 'hello' ) ),
			'no breakdown stored'             => array( null ),
		);
	}
}
