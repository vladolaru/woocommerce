<?php
/**
 * StripeBillingProductService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a Stripe product for each subscription product, and for each one-time item type such as shipping or a sign-up fee.
 *
 * Meta keys, options and hashes are client 11.1.0's (`includes/subscriptions/class-wc-payments-product-service.php`),
 * so a store can go back to the plugin.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingProductService {

	/**
	 * Product meta holding a hash of the name and description last sent to the platform.
	 */
	public const PRODUCT_HASH_KEY = '_wcpay_product_hash';

	/**
	 * Product meta holding the live Stripe product ID; with `_linked_to` appended, the account it belongs to.
	 */
	public const LIVE_PRODUCT_ID_KEY = '_wcpay_product_id_live';

	/**
	 * Product meta holding the test Stripe product ID; with `_linked_to` appended, the account it belongs to.
	 */
	public const TEST_PRODUCT_ID_KEY = '_wcpay_product_id_test';

	/**
	 * Product meta holding a hash of the price data last sent, from client versions before 3.3.0.
	 */
	public const PRICE_HASH_KEY = '_wcpay_product_price_hash';

	/**
	 * Product meta holding the live Stripe price ID, from client versions before 3.3.0.
	 */
	public const LIVE_PRICE_ID_KEY = '_wcpay_product_price_id_live';

	/**
	 * Product meta holding the test Stripe price ID, from client versions before 3.3.0.
	 */
	public const TEST_PRICE_ID_KEY = '_wcpay_product_price_id_test';

	/**
	 * Platform calls.
	 *
	 * @var StripeBillingApi
	 */
	private StripeBillingApi $api;

	/**
	 * Account service, for the mode and the Stripe account ID.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Products to create or update at the end of the request, keyed by product ID.
	 *
	 * @var array<int,int>
	 */
	private array $products_to_update = array();

	/**
	 * Whether product saves are being queued; off while this service writes its own meta.
	 *
	 * @var bool
	 */
	private bool $listening = true;

	/**
	 * Module logger.
	 *
	 * @var StripeBillingLogger
	 */
	private StripeBillingLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingApi          $api             Platform calls.
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @param StripeBillingLogger       $logger          Module logger.
	 */
	final public function init( StripeBillingApi $api, WooPaymentsAccountService $account_service, StripeBillingLogger $logger ): void {
		$this->api             = $api;
		$this->account_service = $account_service;
		$this->logger          = $logger;
	}

	/**
	 * Get the Stripe product ID of a product, creating the Stripe product when it has none in the current mode.
	 *
	 * @param WC_Product $product   Product.
	 * @param bool|null  $test_mode Mode to read; null for the current mode.
	 * @return string Stripe product ID, or an empty string.
	 */
	public function get_or_create_wcpay_product_id( WC_Product $product, ?bool $test_mode = null ): string {
		if ( ! $this->has_wcpay_product_id( $product, $test_mode ) ) {
			if ( null === $test_mode || $this->account_service->is_test_mode_enabled() === $test_mode ) {
				$this->create_product( $product );
			}
		}

		return (string) $product->get_meta( $this->get_product_id_key( $test_mode ), true );
	}

	/**
	 * Get the Stripe product ID for a one-time item type, such as `shipping`, creating it when needed.
	 *
	 * @param string $type Item type.
	 * @return string Stripe product ID.
	 * @throws WooPaymentsApiException When the Stripe product cannot be created.
	 */
	public function get_wcpay_product_id_for_item( string $type ): string {
		$type             = sanitize_key( str_replace( ' ', '_', trim( $type ) ) );
		$option_key       = $this->get_product_id_key() . '_' . $type;
		$wcpay_product_id = (string) get_option( $option_key );

		if ( '' === $wcpay_product_id ) {
			return $this->create_product_for_item_type( $type );
		}

		$linked_account_id = get_option( $option_key . '_linked_to' );
		$stripe_account_id = $this->account_service->get_account_id();

		// Saved before products were linked to an account: keep it only if the current account has it.
		if ( ! $linked_account_id ) {
			try {
				if ( $this->api->get_product_by_id( $wcpay_product_id ) ) {
					$this->save_item_product_id( $type, $wcpay_product_id, $stripe_account_id );
					return $wcpay_product_id;
				}

				return $this->create_product_for_item_type( $type );
			} catch ( \Exception $exception ) {
				$this->log( sprintf( 'Error occurred when fetching product : wcpay_product_id=%s, account_id=%s, error=%s', $wcpay_product_id, $stripe_account_id, $exception->getMessage() ) );
				return $this->create_product_for_item_type( $type );
			}
		}

		if ( $linked_account_id !== $stripe_account_id ) {
			return $this->create_product_for_item_type( $type );
		}

		return $wcpay_product_id;
	}

	/**
	 * Tell whether a subscription billing cycle is no longer than one year.
	 *
	 * @param mixed $period   Billing period: `day`, `week`, `month` or `year`.
	 * @param mixed $interval Billing interval.
	 * @return bool
	 */
	public function is_valid_billing_cycle( $period, $interval ): bool {
		$interval_limits = array(
			'year'  => 1,
			'month' => 12,
			'week'  => 52,
			'day'   => 365,
		);
		$interval_limit  = is_string( $period ) ? ( $interval_limits[ $period ] ?? 0 ) : 0;

		return $interval_limit > 0 && ! empty( $interval ) && $interval <= $interval_limit;
	}

	/**
	 * Keep the Stripe product IDs and hashes off a duplicated product.
	 *
	 * @internal
	 *
	 * @param mixed $meta_keys Meta keys the duplicate leaves out.
	 * @return array<int|string,mixed>
	 */
	public function exclude_meta_wcpay_product( $meta_keys ): array {
		return array_merge(
			(array) $meta_keys,
			array(
				self::PRODUCT_HASH_KEY,
				self::LIVE_PRODUCT_ID_KEY,
				self::TEST_PRODUCT_ID_KEY,
				self::PRICE_HASH_KEY,
				self::LIVE_PRICE_ID_KEY,
				self::TEST_PRICE_ID_KEY,
			)
		);
	}

	/**
	 * Queue a saved subscription product, or its variations, when its Stripe product is missing or out of date.
	 *
	 * @internal
	 *
	 * @param mixed $product_id Product or variation ID.
	 */
	public function maybe_schedule_product_create_or_update( $product_id ): void {
		if ( ! $this->listening || ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return;
		}

		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || isset( $this->products_to_update[ $product_id ] ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			return;
		}

		foreach ( $this->get_products_to_update( $product ) as $product_to_update ) {
			if ( isset( $this->products_to_update[ $product_to_update->get_id() ] ) ) {
				continue;
			}

			// A variation without a price cannot be billed yet.
			if ( $product_to_update->is_type( 'subscription_variation' ) && '' === $product_to_update->get_price() ) {
				continue;
			}

			if ( ! $this->has_wcpay_product_id( $product_to_update ) || $this->product_needs_update( $product_to_update ) ) {
				$this->products_to_update[ $product_to_update->get_id() ] = $product_to_update->get_id();
			}
		}
	}

	/**
	 * Create or update the Stripe products queued in this request.
	 *
	 * @internal
	 */
	public function create_or_update_products(): void {
		foreach ( $this->products_to_update as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				$this->update_products( $product );
			}
		}
	}

	/**
	 * Archive the Stripe products and prices of a trashed subscription product.
	 *
	 * @internal
	 *
	 * @param mixed $post_id Trashed post ID.
	 */
	public function maybe_archive_product( $post_id ): void {
		foreach ( $this->get_subscription_products_for_post( $post_id ) as $product ) {
			$this->archive_product( $product );
		}
	}

	/**
	 * Reactivate the Stripe products of a restored subscription product.
	 *
	 * @internal
	 *
	 * @param mixed $post_id Restored post ID.
	 */
	public function maybe_unarchive_product( $post_id ): void {
		foreach ( $this->get_subscription_products_for_post( $post_id ) as $product ) {
			$this->unarchive_product( $product );
		}
	}

	/**
	 * Tell whether a product has a Stripe product ID that belongs to the current account.
	 *
	 * @param WC_Product $product   Product.
	 * @param bool|null  $test_mode Mode to read; null for the current mode.
	 * @return bool
	 */
	private function has_wcpay_product_id( WC_Product $product, ?bool $test_mode = null ): bool {
		$product_id_key   = $this->get_product_id_key( $test_mode );
		$wcpay_product_id = $product->get_meta( $product_id_key );
		if ( empty( $wcpay_product_id ) ) {
			return false;
		}

		$linked_key         = $product_id_key . '_linked_to';
		$linked_account_id  = $product->get_meta( $linked_key );
		$current_account_id = $this->account_service->get_account_id();
		if ( ! empty( $linked_account_id ) ) {
			return $linked_account_id === $current_account_id;
		}

		// Saved before products were linked to an account: keep it only if the current account has it.
		try {
			if ( empty( $this->api->get_product_by_id( (string) $wcpay_product_id ) ) ) {
				return false;
			}

			$product->update_meta_data( $linked_key, $current_account_id );
			$product->save();
			return true;
		} catch ( \Exception $exception ) {
			$this->log( sprintf( 'Error validating WooPayments product: product_id=%d, wcpay_product_id=%s, account_id=%s, error=%s', $product->get_id(), (string) $wcpay_product_id, $current_account_id, $exception->getMessage() ) );
			return false;
		}
	}

	/**
	 * Create the Stripe product of a product in the current mode.
	 *
	 * @param WC_Product $product Product.
	 */
	private function create_product( WC_Product $product ): void {
		$product_data = $this->get_product_data( $product );
		if ( '' === $product_data['name'] ) {
			$this->log( sprintf( 'There was a problem creating the product #%s in WooPayments: The product "name" is required.', $product->get_id() ) );
			return;
		}

		try {
			$stripe_account_id = $this->account_service->get_account_id();
			$wcpay_product     = $this->api->create_product( $product_data );

			$this->listening = false;
			$product_id_key  = $this->get_product_id_key();
			$product->update_meta_data( self::PRODUCT_HASH_KEY, $this->get_product_hash( $product ) );
			$product->update_meta_data( $product_id_key, (string) $wcpay_product['wcpay_product_id'] );
			$product->update_meta_data( $product_id_key . '_linked_to', $stripe_account_id );
			$product->save();
		} catch ( \Exception $exception ) {
			$this->log( sprintf( 'There was a problem creating the product #%s in WooPayments: %s', $product->get_id(), $exception->getMessage() ) );
		} finally {
			$this->listening = true;
		}
	}

	/**
	 * Create the Stripe product for a one-time item type and save its ID.
	 *
	 * @param string $type Sanitized item type.
	 * @return string Stripe product ID.
	 * @throws WooPaymentsApiException When the platform call fails.
	 */
	private function create_product_for_item_type( string $type ): string {
		$wcpay_product    = $this->api->create_product(
			array(
				'description' => 'N/A',
				'name'        => ucfirst( $type ),
			)
		);
		$wcpay_product_id = (string) $wcpay_product['wcpay_product_id'];
		$this->save_item_product_id( $type, $wcpay_product_id, $this->account_service->get_account_id() );

		return $wcpay_product_id;
	}

	/**
	 * Save the Stripe product ID of a one-time item type and the account it belongs to.
	 *
	 * @param string $type              Sanitized item type.
	 * @param string $wcpay_product_id  Stripe product ID.
	 * @param string $stripe_account_id Stripe account ID.
	 */
	private function save_item_product_id( string $type, string $wcpay_product_id, string $stripe_account_id ): void {
		$option_key = $this->get_product_id_key() . '_' . $type;
		update_option( $option_key, $wcpay_product_id );
		update_option( $option_key . '_linked_to', $stripe_account_id );
	}

	/**
	 * Create the Stripe product for the current mode when missing, then send name and description changes to every mode.
	 *
	 * @param WC_Product $product Product.
	 */
	private function update_products( WC_Product $product ): void {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			return;
		}

		$wcpay_product_ids = $this->get_all_wcpay_product_ids( $product );
		if ( ! isset( $wcpay_product_ids[ $this->account_service->is_test_mode_enabled() ? 'test' : 'live' ] ) ) {
			$this->create_product( $product );
		}

		if ( empty( $wcpay_product_ids ) || ! $this->product_needs_update( $product ) ) {
			return;
		}

		$data = $this->get_product_data( $product );
		if ( '' === $data['name'] ) {
			$this->log( sprintf( 'There was a problem updating the product #%s in WooPayments: The product "name" is required.', $product->get_id() ) );
			return;
		}

		$this->listening = false;
		try {
			foreach ( $wcpay_product_ids as $environment => $wcpay_product_id ) {
				$data['test_mode'] = 'live' !== $environment;
				$this->api->update_product( $wcpay_product_id, $data );
			}

			$product->update_meta_data( self::PRODUCT_HASH_KEY, $this->get_product_hash( $product ) );
			$product->save();
		} catch ( \Exception $exception ) {
			$this->log( sprintf( 'There was a problem updating the product #%s in WooPayments: %s', $product->get_id(), $exception->getMessage() ) );
		} finally {
			$this->listening = true;
		}
	}

	/**
	 * Archive the live and test Stripe products of a product, and archive and forget its legacy prices.
	 *
	 * @param WC_Product $product Product.
	 */
	private function archive_product( WC_Product $product ): void {
		foreach ( $this->get_all_wcpay_product_ids( $product ) as $environment => $wcpay_product_id ) {
			try {
				$this->delete_all_wcpay_price_ids( $product );
				$this->api->update_product(
					$wcpay_product_id,
					array(
						'active'    => 'false',
						'test_mode' => 'live' !== $environment,
					)
				);
			} catch ( WooPaymentsApiException $exception ) {
				$this->log( 'There was a problem archiving the ' . $environment . ' product in WooPayments: ' . $exception->getMessage() );
			}
		}
	}

	/**
	 * Reactivate the live and test Stripe products of a product.
	 *
	 * @param WC_Product $product Product.
	 */
	private function unarchive_product( WC_Product $product ): void {
		foreach ( $this->get_all_wcpay_product_ids( $product ) as $environment => $wcpay_product_id ) {
			try {
				$this->api->update_product(
					$wcpay_product_id,
					array(
						'active'    => 'true',
						'test_mode' => 'live' !== $environment,
					)
				);
			} catch ( WooPaymentsApiException $exception ) {
				$this->log( 'There was a problem unarchiving the ' . $environment . ' product in WooPayments: ' . $exception->getMessage() );
			}
		}
	}

	/**
	 * Archive a product's legacy Stripe prices in both modes and delete their meta and the price hash.
	 *
	 * @param WC_Product $product Product.
	 */
	private function delete_all_wcpay_price_ids( WC_Product $product ): void {
		foreach ( array( 'test', 'live' ) as $environment ) {
			$test_mode    = 'test' === $environment;
			$price_id_key = $test_mode ? self::TEST_PRICE_ID_KEY : self::LIVE_PRICE_ID_KEY;
			if ( ! $product->meta_exists( $price_id_key ) ) {
				continue;
			}

			try {
				$this->api->update_price(
					(string) $product->get_meta( $price_id_key, true ),
					array(
						'active'    => 'false',
						'test_mode' => $test_mode,
					)
				);
			} catch ( WooPaymentsApiException $exception ) {
				$this->log( 'There was a problem archiving the ' . $environment . ' product price ID in WooPayments: ' . $exception->getMessage() );
			}

			$product->delete_meta_data( $price_id_key );
			$product->delete_meta_data( $price_id_key . '_linked_to' );
		}

		$product->delete_meta_data( self::PRICE_HASH_KEY );
		$product->save();
	}

	/**
	 * Get the live and test Stripe product IDs of a product that belong to the current account.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,string> Stripe product IDs keyed by `live` and `test`.
	 */
	private function get_all_wcpay_product_ids( WC_Product $product ): array {
		$wcpay_product_ids = array();
		foreach ( array( 'live', 'test' ) as $environment ) {
			$test_mode = 'test' === $environment;
			if ( $this->has_wcpay_product_id( $product, $test_mode ) ) {
				$wcpay_product_ids[ $environment ] = (string) $product->get_meta( $this->get_product_id_key( $test_mode ), true );
			}
		}

		return array_filter( $wcpay_product_ids );
	}

	/**
	 * Get the subscription products behind a post: its variations for a variable subscription, else itself.
	 *
	 * @param mixed $post_id Post ID.
	 * @return array<int,WC_Product>
	 */
	private function get_subscription_products_for_post( $post_id ): array {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return array();
		}

		$product = wc_get_product( absint( $post_id ) );
		if ( ! $product instanceof WC_Product || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			return array();
		}

		return $this->get_products_to_update( $product );
	}

	/**
	 * Get the products whose Stripe products follow a product: its variations for a variable subscription, else itself.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int,WC_Product>
	 */
	private function get_products_to_update( WC_Product $product ): array {
		if ( $product->is_type( 'variable-subscription' ) && $product instanceof \WC_Product_Variable ) {
			return array_values( $product->get_available_variations( 'objects' ) );
		}

		return array( $product );
	}

	/**
	 * Get the product data the platform keeps: name, and description or `N/A`.
	 *
	 * @param WC_Product $product Product.
	 * @return array{description: string, name: string}
	 */
	private function get_product_data( WC_Product $product ): array {
		return array(
			'description' => $product->get_description() ? $product->get_description() : 'N/A',
			'name'        => $product->get_name(),
		);
	}

	/**
	 * Hash the product data, to tell whether the platform has the latest.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function get_product_hash( WC_Product $product ): string {
		return md5( implode( $this->get_product_data( $product ) ) );
	}

	/**
	 * Tell whether the product data changed since it was last sent.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function product_needs_update( WC_Product $product ): bool {
		return $this->get_product_hash( $product ) !== $product->get_meta( self::PRODUCT_HASH_KEY, true );
	}

	/**
	 * Get the product ID meta key, also the prefix of the item type options, for a mode.
	 *
	 * @param bool|null $test_mode Mode; null for the current mode.
	 * @return string
	 */
	private function get_product_id_key( ?bool $test_mode = null ): string {
		$test_mode = $test_mode ?? $this->account_service->is_test_mode_enabled();

		return $test_mode ? self::TEST_PRODUCT_ID_KEY : self::LIVE_PRODUCT_ID_KEY;
	}

	/**
	 * Log a failure when WooPayments logging is on.
	 *
	 * @param string $message Message.
	 */
	private function log( string $message ): void {
		$this->logger->log( $message );
	}
}
