<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationKeys;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentOperationKeys class.
 */
class PaymentOperationKeysTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should mint a fresh key for every payment attempt.
	 */
	public function test_mints_a_fresh_key_for_every_attempt(): void {
		$sut = new PaymentOperationKeys();

		$first  = $sut->mint_attempt_key();
		$second = $sut->mint_attempt_key();

		$this->assertNotSame( $first, $second, 'Each payment attempt must carry its own key so the provider never replays a previous attempt\'s cached outcome.' );
	}

	/**
	 * @testdox Should mint attempt keys in the UUID v4 shape the platform-proven client sends.
	 */
	public function test_mints_attempt_keys_as_uuid4(): void {
		$sut = new PaymentOperationKeys();

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
		$sut   = new PaymentOperationKeys();

		$this->assertNotSame(
			$sut->derive_operation_key( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'capture', 20.00, 'USD' ),
			$sut->derive_operation_key( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'cancel', 20.00, 'USD' ),
			'Different operations on the same order need different idempotency keys.'
		);
	}

	/**
	 * @testdox Capture amounts distinguish partial captures while identical retries retain the same key.
	 */
	public function test_capture_amount_distinguishes_partial_capture_keys(): void {
		$order = wc_create_order();
		$sut   = new PaymentOperationKeys();

		$first       = $sut->derive_operation_key( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'capture', 4.25, 'USD' );
		$second      = $sut->derive_operation_key( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'capture', 5.75, 'USD' );
		$first_retry = $sut->derive_operation_key( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'capture', 4.25, 'USD' );

		$this->assertNotSame( $first, $second, 'Distinct partial-capture amounts must not collapse to one provider operation.' );
		$this->assertSame( $first, $first_retry, 'A retry of the same partial capture must retain its provider operation key.' );
	}
}
