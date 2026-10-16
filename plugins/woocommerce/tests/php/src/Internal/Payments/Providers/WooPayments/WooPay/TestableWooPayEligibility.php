<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayEligibility;

/**
 * WooPay eligibility rules with controllable optional-extension predicates.
 */
class TestableWooPayEligibility extends WooPaymentsWooPayEligibility {
	/**
	 * Whether a product is a pre-order charged upon release.
	 *
	 * @var bool
	 */
	public bool $preorder_product_charged_on_release = false;

	/**
	 * Whether a booking product requires confirmation.
	 *
	 * @var bool
	 */
	public bool $booking_product_requires_confirmation = false;

	/**
	 * Whether the cart has a pre-order charged upon release.
	 *
	 * @var bool
	 */
	public bool $cart_preorder_charged_on_release = false;

	/**
	 * Whether the cart contains a subscription.
	 *
	 * @var bool
	 */
	public bool $cart_contains_subscription = false;

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Product|null $product Product being checked.
	 */
	protected function is_woopay_preorder_product_charged_on_release( ?\WC_Product $product ): bool {
		unset( $product );

		return $this->preorder_product_charged_on_release;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Product $product Product being checked.
	 */
	protected function is_woopay_booking_product_requiring_confirmation( \WC_Product $product ): bool {
		unset( $product );

		return $this->booking_product_requires_confirmation;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_woopay_cart_preorder_charged_on_release(): bool {
		return $this->cart_preorder_charged_on_release;
	}

	/**
	 * {@inheritDoc}
	 */
	public function woopay_cart_contains_subscription(): bool {
		return $this->cart_contains_subscription;
	}
}
