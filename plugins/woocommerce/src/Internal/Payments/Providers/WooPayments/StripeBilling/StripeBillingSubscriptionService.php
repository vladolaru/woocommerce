<?php
/**
 * StripeBillingSubscriptionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use WC_Coupon;
use WC_Order;
use WC_Order_Item;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Links WooCommerce Subscriptions subscriptions to their Stripe subscriptions.
 *
 * Meta keys and payload shapes are client 11.1.0's (`includes/subscriptions/class-wc-payments-subscription-service.php`),
 * so a store can go back to the plugin.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingSubscriptionService {

	/**
	 * Subscription meta holding the Stripe subscription ID.
	 */
	public const SUBSCRIPTION_ID_META_KEY = '_wcpay_subscription_id';

	/**
	 * Subscription item meta holding the Stripe subscription item ID.
	 */
	public const SUBSCRIPTION_ITEM_ID_META_KEY = '_wcpay_subscription_item_id';

	/**
	 * Subscription meta holding the Stripe discount IDs.
	 */
	public const SUBSCRIPTION_DISCOUNT_IDS_META_KEY = '_wcpay_subscription_discount_ids';

	/**
	 * Product service, for the Stripe products the subscription items are billed under.
	 *
	 * @var StripeBillingProductService
	 */
	private StripeBillingProductService $product_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingProductService $product_service Product service.
	 */
	final public function init( StripeBillingProductService $product_service ): void {
		$this->product_service = $product_service;
	}

	/**
	 * Tell whether a subscription is billed by Stripe Billing: paid with WooPayments and linked to a Stripe subscription.
	 *
	 * Always false on a staging copy, so that it never acts at Stripe for the live store.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	public function is_wcpay_subscription( WC_Order $subscription ): bool {
		return ! WooPaymentsSubscriptionMethodPolicy::is_duplicate_site()
			&& WooPaymentsPersistenceProfile::GATEWAY_ID === $subscription->get_payment_method()
			&& (bool) $this->get_wcpay_subscription_id( $subscription );
	}

	/**
	 * Get the Stripe subscription ID of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	public function get_wcpay_subscription_id( WC_Order $subscription ): string {
		return (string) $subscription->get_meta( self::SUBSCRIPTION_ID_META_KEY, true );
	}

	/**
	 * Get the Stripe subscription item ID of a subscription item.
	 *
	 * @param WC_Order_Item $item Subscription item.
	 * @return string
	 */
	public function get_wcpay_subscription_item_id( WC_Order_Item $item ): string {
		return (string) $item->get_meta( self::SUBSCRIPTION_ITEM_ID_META_KEY, true );
	}

	/**
	 * Get the Stripe discount IDs of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,mixed>
	 */
	public function get_wcpay_discount_ids( WC_Order $subscription ): array {
		$discount_ids = $subscription->get_meta( self::SUBSCRIPTION_DISCOUNT_IDS_META_KEY, true );

		return is_array( $discount_ids ) ? $discount_ids : array();
	}

	/**
	 * Save the Stripe discount IDs of a subscription.
	 *
	 * @param WC_Order         $subscription Subscription.
	 * @param array<int,mixed> $discounts    Stripe discount IDs.
	 */
	public function set_wcpay_discount_ids( WC_Order $subscription, array $discounts ): void {
		$subscription->update_meta_data( self::SUBSCRIPTION_DISCOUNT_IDS_META_KEY, $discounts );
		$subscription->save();
	}

	/**
	 * Get the Stripe discounts for a subscription's coupons: recurring coupons apply forever, others once.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_discount_item_data_for_subscription( WC_Order $subscription ): array {
		$data = array();

		foreach ( $subscription->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$code     = $item->get_code();
			$coupon   = new WC_Coupon( $code );
			$duration = in_array( $coupon->get_discount_type(), array( 'recurring_fee', 'recurring_percent' ), true ) ? 'forever' : 'once';
			$discount = $item->get_discount();

			if ( $discount ) {
				$data[] = array(
					'amount_off' => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $discount, $subscription->get_currency() ),
					'currency'   => $subscription->get_currency(),
					'duration'   => $duration,
					/* translators: %s: Coupon code. */
					'name'       => sprintf( __( 'Coupon - %s', 'woocommerce' ), $code ),
				);
			}
		}

		return $data;
	}

	/**
	 * Get the Stripe subscription items for a subscription: its products, then its fees, shipping and taxes.
	 *
	 * Creates the Stripe products that are missing.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array<string,mixed>>
	 * @throws StripeBillingException When the product of a subscription item no longer exists.
	 */
	public function get_recurring_item_data_for_subscription( WC_Order $subscription ): array {
		$data           = array();
		$currency       = $subscription->get_currency();
		$billing_period = is_callable( array( $subscription, 'get_billing_period' ) ) ? (string) $subscription->get_billing_period() : '';
		$interval       = is_callable( array( $subscription, 'get_billing_interval' ) ) ? (int) $subscription->get_billing_interval() : 0;

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is application data, not HTML output.
				throw new StripeBillingException(
					sprintf(
						/* translators: 1: subscription ID, 2: subscription item ID */
						__( 'Subscription #%1$d cannot be billed through Stripe Billing: the product of its item #%2$d no longer exists.', 'woocommerce' ),
						$subscription->get_id(),
						$item->get_id()
					),
					StripeBillingException::SUBSCRIPTION_PRODUCT_MISSING
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$data[] = array(
				'metadata'   => $this->get_item_metadata( $item ),
				'quantity'   => $item->get_quantity(),
				'price_data' => $this->format_item_price_data(
					$currency,
					$this->product_service->get_or_create_wcpay_product_id( $product ),
					(float) $item->get_subtotal() / $item->get_quantity(),
					$billing_period,
					$interval
				),
			);
		}

		$additional_items = array_merge( $subscription->get_fees(), $subscription->get_shipping_methods(), $subscription->get_taxes() );

		foreach ( $additional_items as $item ) {
			if ( $item instanceof \WC_Order_Item_Tax ) {
				$item_name   = $item->get_label();
				$unit_amount = (float) $item->get_tax_total() + (float) $item->get_shipping_tax_total();
			} else {
				$item_name   = $item->get_type();
				$unit_amount = (float) $item->get_total();
			}

			if ( $unit_amount ) {
				$data[] = array(
					'metadata'   => $this->get_item_metadata( $item ),
					'price_data' => $this->format_item_price_data( $currency, $this->product_service->get_wcpay_product_id_for_item( $item_name ), $unit_amount, $billing_period, $interval ),
				);
			}
		}

		return $data;
	}

	/**
	 * Build a Stripe price: the unit amount in the currency's smallest unit, with fractions kept, and the billing cycle when given.
	 *
	 * @param string $currency         Currency code.
	 * @param string $wcpay_product_id Stripe product ID.
	 * @param float  $unit_amount      Unit amount.
	 * @param string $interval         Billing period.
	 * @param int    $interval_count   Billing interval.
	 * @return array<string,mixed>
	 */
	private function format_item_price_data( string $currency, string $wcpay_product_id, float $unit_amount, string $interval = '', int $interval_count = 0 ): array {
		$data = array(
			'currency'            => $currency,
			'product'             => $wcpay_product_id,
			'unit_amount_decimal' => round( $unit_amount, wc_get_rounding_precision() ),
		);

		if ( ! WooPaymentsCurrencyUtils::is_zero_decimal_currency( $currency ) ) {
			$data['unit_amount_decimal'] *= 100;
		}

		if ( $interval && $interval_count ) {
			$data['recurring'] = array(
				'interval'       => $interval,
				'interval_count' => $interval_count,
			);
		}

		return $data;
	}

	/**
	 * Get the metadata that ties a Stripe subscription item to its WooCommerce item.
	 *
	 * @param WC_Order_Item $item Subscription item of any type.
	 * @return array<string,mixed>
	 */
	private function get_item_metadata( WC_Order_Item $item ): array {
		$metadata = array( 'wc_item_id' => $item->get_id() );

		if ( $item instanceof \WC_Order_Item_Tax ) {
			$metadata['wc_rate_id']  = $item->get_rate_id();
			$metadata['code']        = $item->get_rate_code();
			$metadata['rate']        = $item->get_rate_percent();
			$metadata['is_compound'] = wc_bool_to_string( $item->is_compound() );
		} elseif ( $item instanceof \WC_Order_Item_Shipping ) {
			$metadata['method'] = $item->get_name();
		} elseif ( $item instanceof \WC_Order_Item_Fee ) {
			$metadata['type'] = $item->get_name();
		}

		return $metadata;
	}
}
