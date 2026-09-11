<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationIdempotency;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentOperationIdempotency class.
 */
class PaymentOperationIdempotencyTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should mint a fresh key for every payment attempt.
	 */
	public function test_mints_a_fresh_key_for_every_attempt(): void {
		$sut = new PaymentOperationIdempotency();

		$first  = $sut->mint_attempt_key();
		$second = $sut->mint_attempt_key();

		$this->assertNotSame( $first, $second, 'Each payment attempt must carry its own key so the provider never replays a previous attempt\'s cached outcome.' );
	}

	/**
	 * @testdox Should mint attempt keys in the UUID v4 shape the platform-proven client sends.
	 */
	public function test_mints_attempt_keys_as_uuid4(): void {
		$sut = new PaymentOperationIdempotency();

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$sut->mint_attempt_key()
		);
	}

	/**
	 * @testdox Should change the key when the operation changes.
	 */
	public function test_changes_key_when_operation_changes(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$this->assertNotSame(
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'charge', 20.00, 'USD' ),
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 20.00, 'USD' ),
			'Different operations on the same order need different idempotency keys.'
		);
	}

	/**
	 * @testdox Should change the key when refund details change.
	 */
	public function test_changes_key_when_refund_details_change(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$this->assertNotSame(
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request' ),
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 6.00, 'USD', 'Customer request' ),
			'Refund amount must participate in the key so distinct refunds do not collapse together.'
		);
	}

	/**
	 * @testdox Capture amounts distinguish partial captures while identical retries retain the same key.
	 */
	public function test_capture_amount_distinguishes_partial_capture_keys(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$first       = $sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 4.25, 'USD' );
		$second      = $sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 5.75, 'USD' );
		$first_retry = $sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'capture', 4.25, 'USD' );

		$this->assertNotSame( $first, $second, 'Distinct partial-capture amounts must not collapse to one provider operation.' );
		$this->assertSame( $first, $first_retry, 'A retry of the same partial capture must retain its provider operation key.' );
	}

	/**
	 * @testdox Should change the key when the per-instance discriminator changes for otherwise identical operations.
	 */
	public function test_changes_key_when_instance_changes(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$this->assertNotSame(
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request', '101' ),
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request', '102' ),
			'Two refund instances of the same amount and reason must yield different keys so the provider cannot replay the first.'
		);
	}

	/**
	 * @testdox Should derive the same key for identical operations sharing the same per-instance discriminator.
	 */
	public function test_derives_same_key_for_identical_inputs_and_instance(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$this->assertSame(
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request', '101' ),
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request', '101' ),
			'A retry of the same refund instance must collapse to one provider operation.'
		);
	}

	/**
	 * @testdox A null per-instance discriminator must keep the key byte-identical to the six-argument form.
	 */
	public function test_null_instance_preserves_backward_compatible_key(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationIdempotency();

		$this->assertSame(
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request' ),
			$sut->derive_key( $order, OrderPaymentStore::GATEWAY_ID, 'refund', 5.00, 'USD', 'Customer request', null ),
			'Passing a null instance must not change the key, preserving the pre-instance backward-compatible contract.'
		);
	}
}
