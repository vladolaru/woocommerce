<?php
/**
 * Tests for the PayPal wallet transaction ID trait (ported from the extension's TransactionIdHandlingTraitTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\TransactionIdHandlingTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The transaction ID of a PayPal order is the ID of its first live capture, else of a usable authorization.
 *
 * @group paypal-wallet
 */
class TransactionIdHandlingTraitTest extends WalletTestCase {

	/**
	 * A throwaway object that uses the trait.
	 *
	 * @var object
	 */
	private $fixture;

	/**
	 * Build the object under test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->fixture = new class() {
			use TransactionIdHandlingTrait;
		};
	}

	/**
	 * @testdox Should return the ID of a single completed capture.
	 */
	public function test_completed_capture_returns_its_id(): void {
		$order = $this->order_with_captures( array( $this->capture( 'capture-1', CaptureStatus::COMPLETED ) ) );

		$this->assertSame( 'capture-1', $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return null for a declined capture, so the order is not treated as already paid.
	 */
	public function test_declined_capture_returns_null(): void {
		$order = $this->order_with_captures( array( $this->capture( 'capture-declined', CaptureStatus::DECLINED ) ) );

		$this->assertNull( $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return null for a capture that failed to process.
	 */
	public function test_failed_capture_returns_null(): void {
		$order = $this->order_with_captures( array( $this->capture( 'capture-failed', CaptureStatus::FAILED ) ) );

		$this->assertNull( $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return the ID of a capture whose status is $status, since money moved or may still settle.
	 *
	 * @dataProvider live_capture_status_provider
	 *
	 * @param string $status The capture status.
	 */
	public function test_live_capture_status_returns_its_id( string $status ): void {
		$order = $this->order_with_captures( array( $this->capture( 'capture-live', $status ) ) );

		$this->assertSame( 'capture-live', $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * Capture statuses that are not treated as dead.
	 *
	 * @return array<string, array<string>>
	 */
	public function live_capture_status_provider(): array {
		return array(
			'completed'          => array( CaptureStatus::COMPLETED ),
			'pending may settle' => array( CaptureStatus::PENDING ),
			'refunded'           => array( CaptureStatus::REFUNDED ),
			'partially refunded' => array( CaptureStatus::PARTIALLY_REFUNDED ),
		);
	}

	/**
	 * @testdox Should return the completed capture's ID when a declined capture comes first, so the whole list is scanned.
	 */
	public function test_declined_capture_followed_by_completed_returns_completed_id(): void {
		$order = $this->order_with_captures(
			array(
				$this->capture( 'capture-declined', CaptureStatus::DECLINED ),
				$this->capture( 'capture-completed', CaptureStatus::COMPLETED ),
			)
		);

		$this->assertSame( 'capture-completed', $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should fall back to the ID of a still-open authorization when no capture is usable.
	 */
	public function test_declined_capture_falls_back_to_usable_authorization(): void {
		$order = $this->order_with_captures_and_authorizations(
			array( $this->capture( 'capture-declined', CaptureStatus::DECLINED ) ),
			array( $this->authorization( 'auth-created', AuthorizationStatus::CREATED ) )
		);

		$this->assertSame( 'auth-created', $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return null when neither a capture nor an authorization is usable.
	 */
	public function test_declined_capture_and_denied_authorization_return_null(): void {
		$order = $this->order_with_captures_and_authorizations(
			array( $this->capture( 'capture-declined', CaptureStatus::DECLINED ) ),
			array( $this->authorization( 'auth-denied', AuthorizationStatus::DENIED ) )
		);

		$this->assertNull( $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return null for a PayPal order with no purchase units.
	 */
	public function test_no_purchase_units_returns_null(): void {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array() );

		$this->assertNull( $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * @testdox Should return null for a purchase unit with no payments information.
	 */
	public function test_no_payments_on_purchase_unit_returns_null(): void {
		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'payments' )->andReturnNull();

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array( $purchase_unit ) );

		$this->assertNull( $this->fixture->get_paypal_order_transaction_id( $order ) );
	}

	/**
	 * A PayPal order whose purchase unit holds the given captures and no authorizations.
	 *
	 * @param Capture[] $captures The captures.
	 * @return Order
	 */
	private function order_with_captures( array $captures ): Order {
		return $this->order_with_captures_and_authorizations( $captures, array() );
	}

	/**
	 * A PayPal order whose purchase unit holds the given captures and authorizations.
	 *
	 * @param Capture[]       $captures       The captures.
	 * @param Authorization[] $authorizations The authorizations.
	 * @return Order
	 */
	private function order_with_captures_and_authorizations( array $captures, array $authorizations ): Order {
		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'payments' )->andReturn( new Payments( $authorizations, $captures ) );

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array( $purchase_unit ) );

		return $order;
	}

	/**
	 * A capture of 10.00 USD with the given ID and status.
	 *
	 * @param string $id     The capture ID.
	 * @param string $status The capture status.
	 * @return Capture
	 */
	private function capture( string $id, string $status ): Capture {
		return new Capture( $id, new CaptureStatus( $status ), new Amount( new Money( 10.00, 'USD' ) ), true, '', '', '', null, null );
	}

	/**
	 * An authorization with the given ID and status.
	 *
	 * @param string $id     The authorization ID.
	 * @param string $status The authorization status.
	 * @return Authorization
	 */
	private function authorization( string $id, string $status ): Authorization {
		return new Authorization( $id, new AuthorizationStatus( $status ), null );
	}
}
