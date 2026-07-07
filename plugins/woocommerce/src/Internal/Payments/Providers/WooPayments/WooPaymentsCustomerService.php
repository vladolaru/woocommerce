<?php
/**
 * WooPaymentsCustomerService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Customer;
use WC_Order;

/**
 * Core-owned customer persistence for the native WooPayments runtime.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCustomerService implements RegisterHooksInterface {

	/**
	 * Personal-data eraser identifier registered with WordPress.
	 */
	private const ERASER_ID = 'woocommerce-payments-customer';

	/**
	 * Deprecated customer ID option key.
	 */
	public const DEPRECATED_CUSTOMER_ID_OPTION = '_wcpay_customer_id';

	/**
	 * Live-mode customer ID option key.
	 */
	public const LIVE_CUSTOMER_ID_OPTION = '_wcpay_customer_id_live';

	/**
	 * Test-mode customer ID option key.
	 */
	public const TEST_CUSTOMER_ID_OPTION = '_wcpay_customer_id_test';

	/**
	 * Session key used for guest customer IDs.
	 */
	public const CUSTOMER_ID_SESSION_KEY = 'wcpay_customer_id';

	/**
	 * Native API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient      $api_client      Native API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Register the GDPR personal-data eraser for stored WooPayments customer IDs.
	 *
	 * The eraser is registered unconditionally rather than behind the native
	 * runtime arbiter: the customer-ID user options can persist on a site
	 * regardless of which payments runtime currently owns it (native or the
	 * standalone plugin), and erasure coverage must not blink off when ownership
	 * changes. The callback only deletes user options, so it is idempotent and
	 * harmless when there is nothing to remove.
	 *
	 * @internal
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_personal_data_eraser' ) );
	}

	/**
	 * Filter callback that adds this service's eraser to WP's GDPR registry.
	 *
	 * @internal
	 *
	 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers Existing erasers.
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public function register_personal_data_eraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'WooPayments Customer Data', 'woocommerce' ),
			'callback'             => array( $this, 'erase_customer_data' ),
		);

		return $erasers;
	}

	/**
	 * Erase the WooPayments customer IDs linking a WordPress user to Stripe.
	 *
	 * Resolves the user by email and deletes the deprecated, live, and test
	 * customer-ID user options. Each option is stored via `update_user_option()`
	 * with the default `$global = false`, so deletion mirrors that by leaving the
	 * `$global` argument at its default.
	 *
	 * @internal
	 *
	 * @param string $email_address Email address being erased.
	 * @param int    $page          Pagination page (unused; all data fits one page).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase_customer_data( string $email_address, int $page = 1 ): array {
		unset( $page );

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $result;
		}

		$option_keys = array(
			self::DEPRECATED_CUSTOMER_ID_OPTION,
			self::LIVE_CUSTOMER_ID_OPTION,
			self::TEST_CUSTOMER_ID_OPTION,
		);

		foreach ( $option_keys as $option_key ) {
			if ( false === get_user_option( $option_key, $user->ID ) ) {
				continue;
			}

			if ( delete_user_option( $user->ID, $option_key ) ) {
				$result['items_removed'] = true;
			}
		}

		return $result;
	}

	/**
	 * Get or create the WooPayments customer ID associated with an order.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return string
	 */
	public function get_or_create_customer_id_for_order( WC_Order $order ): string {
		$order_customer_id = (string) $order->get_meta( '_stripe_customer_id', true );
		if ( '' !== $order_customer_id ) {
			return $this->update_customer_for_order( $order_customer_id, $order );
		}

		$user_id     = $this->get_order_user_id( $order );
		$customer_id = $this->get_customer_id_by_user_id( $user_id );

		if ( null !== $customer_id ) {
			return $this->update_customer_for_order( $customer_id, $order );
		}

		// Guard against concurrent checkouts creating duplicate remote customers
		// for the same logged-in user. On stores with a persistent object cache
		// the advisory lock is shared across requests, so only the request that
		// acquires it creates while the losers re-read the persisted ID. Guests
		// keep their ID in the request-local session and are unaffected, so they
		// skip the lock. Without a persistent object cache the lock is
		// request-local and every request falls through to create, exactly as
		// before -- never worse than today.
		$lock_key = null === $user_id ? '' : 'wcpay_customer_create_' . $user_id;

		// wp_cache_add() both acquires the lock and reports contention: it
		// returns false when another request already holds the key, so a true
		// result means this request is the one that owns the lock.
		$acquired_lock = '' !== $lock_key && wp_cache_add( $lock_key, 1, 'woopayments', 10 );

		if ( '' !== $lock_key && ! $acquired_lock ) {
			$customer_id = $this->get_customer_id_by_user_id( $user_id );
			if ( null !== $customer_id ) {
				// The winning request already created and persisted the customer.
				return $this->update_customer_for_order( $customer_id, $order );
			}
			// The lock holder has not persisted yet (or there is no persistent
			// object cache); create anyway to avoid regressing below today. We
			// do not own the lock, so we must not release it on the way out.
		}

		try {
			$customer_id = $this->api_client->create_customer( $this->map_customer_data( $order ) );
			$this->persist_customer_id( $user_id, $customer_id );

			return $customer_id;
		} finally {
			if ( $acquired_lock ) {
				wp_cache_delete( $lock_key, 'woopayments' );
			}
		}
	}

	/**
	 * Update the WooPayments customer associated with an order.
	 *
	 * If the remote customer no longer exists, recreate it and update local persistence.
	 *
	 * @param string   $customer_id WooPayments customer ID.
	 * @param WC_Order $order       Order being charged.
	 * @return string Updated or recreated WooPayments customer ID.
	 * @throws WooPaymentsApiException When updating the remote customer fails for reasons other than a missing customer.
	 */
	public function update_customer_for_order( string $customer_id, WC_Order $order ): string {
		$user_id       = $this->get_order_user_id( $order );
		$customer_data = $this->map_customer_data( $order );

		try {
			$this->api_client->update_customer( $customer_id, $customer_data );
			$this->persist_customer_id( $user_id, $customer_id );

			return $customer_id;
		} catch ( WooPaymentsApiException $exception ) {
			if ( 'resource_missing' === $exception->get_error_code() ) {
				return $this->recreate_customer_for_order( $order );
			}

			throw $exception;
		}
	}

	/**
	 * Recreate the WooPayments customer associated with an order.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return string
	 */
	public function recreate_customer_for_order( WC_Order $order ): string {
		$user_id = $this->get_order_user_id( $order );
		$this->delete_customer_id( $user_id );

		$customer_id = $this->api_client->create_customer( $this->map_customer_data( $order ) );
		$this->persist_customer_id( $user_id, $customer_id );

		return $customer_id;
	}

	/**
	 * Get or create the WooPayments customer ID associated with a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public function get_or_create_customer_id_for_user( int $user_id ): string {
		$customer_id = $this->get_customer_id_by_user_id( $user_id );
		if ( null !== $customer_id ) {
			return $customer_id;
		}

		$customer_id = $this->api_client->create_customer( $this->map_customer_data_for_user( $user_id ) );
		$this->persist_customer_id( $user_id, $customer_id );

		return $customer_id;
	}

	/**
	 * Set a payment method as the default for a WooPayments customer.
	 *
	 * @param string $customer_id       WooPayments customer ID.
	 * @param string $payment_method_id WooPayments payment method ID.
	 * @return void
	 */
	public function set_default_payment_method_for_customer( string $customer_id, string $payment_method_id ): void {
		$this->api_client->update_customer(
			$customer_id,
			array(
				'invoice_settings' => array(
					'default_payment_method' => $payment_method_id,
				),
			)
		);
	}

	/**
	 * Get WooPayments payment methods for a customer.
	 *
	 * @param string $customer_id WooPayments customer ID.
	 * @param string $type        Payment method type.
	 * @return array<int,array<string,mixed>>
	 * @throws WooPaymentsApiException When the API request fails for reasons other than a missing customer.
	 */
	public function get_payment_methods_for_customer( string $customer_id, string $type = 'card' ): array {
		if ( '' === $customer_id ) {
			return array();
		}

		try {
			$response = $this->api_client->get_payment_methods( $customer_id, $type );
		} catch ( WooPaymentsApiException $exception ) {
			if ( 'resource_missing' === $exception->get_error_code() ) {
				return array();
			}

			throw $exception;
		}

		return isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
	}

	/**
	 * Map WooCommerce order data to the WooPayments customer payload.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return array<string,mixed>
	 */
	public function map_customer_data( WC_Order $order ): array {
		$user_id     = $this->get_order_user_id( $order );
		$user        = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		$wc_customer = new WC_Customer( $user_id > 0 ? $user_id : 0 );
		$name        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		/* translators: 1: customer full name, 2: WordPress username. */
		$registered_customer_description = sprintf( __( 'Name: %1$s, Username: %2$s', 'woocommerce' ), $name, $wc_customer->get_username() );
		/* translators: %s: customer full name. */
		$guest_customer_description = sprintf( __( 'Name: %1$s, Guest', 'woocommerce' ), $name );

		$customer_data = array(
			'name'        => $name,
			'description' => $user ? $registered_customer_description : $guest_customer_description,
			'email'       => $order->get_billing_email(),
			'phone'       => $order->get_billing_phone(),
			'address'     => array(
				'line1'       => $order->get_billing_address_1(),
				'line2'       => $order->get_billing_address_2(),
				'postal_code' => $order->get_billing_postcode(),
				'city'        => $order->get_billing_city(),
				'state'       => $order->get_billing_state(),
				'country'     => $order->get_billing_country(),
			),
		);

		if ( '' !== $order->get_shipping_postcode() ) {
			$customer_data['shipping'] = array(
				'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'address' => array(
					'line1'       => $order->get_shipping_address_1(),
					'line2'       => $order->get_shipping_address_2(),
					'postal_code' => $order->get_shipping_postcode(),
					'city'        => $order->get_shipping_city(),
					'state'       => $order->get_shipping_state(),
					'country'     => $order->get_shipping_country(),
				),
			);
		}

		return $customer_data;
	}

	/**
	 * Map WordPress customer data to the WooPayments customer payload.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string,mixed>
	 */
	private function map_customer_data_for_user( int $user_id ): array {
		$customer = new WC_Customer( $user_id );
		$user     = get_user_by( 'id', $user_id );
		$name     = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );

		/* translators: 1: customer full name, 2: WordPress username. */
		$description = sprintf( __( 'Name: %1$s, Username: %2$s', 'woocommerce' ), $name, $customer->get_username() );

		return array(
			'name'        => $name,
			'description' => $description,
			'email'       => $user ? $user->user_email : $customer->get_email(),
			'phone'       => $customer->get_billing_phone(),
			'address'     => array(
				'line1'       => $customer->get_billing_address_1(),
				'line2'       => $customer->get_billing_address_2(),
				'postal_code' => $customer->get_billing_postcode(),
				'city'        => $customer->get_billing_city(),
				'state'       => $customer->get_billing_state(),
				'country'     => $customer->get_billing_country(),
			),
		);
	}

	/**
	 * Get the persisted customer ID for a user or guest checkout.
	 *
	 * @param int|null $user_id WordPress user ID or null for guests.
	 * @return string|null
	 */
	public function get_customer_id_by_user_id( ?int $user_id ): ?string {
		if ( null === $user_id || 0 === $user_id ) {
			$customer_id = WC()->session ? WC()->session->get( self::CUSTOMER_ID_SESSION_KEY ) : null;
			return is_string( $customer_id ) && '' !== $customer_id ? $customer_id : null;
		}

		$customer_id = get_user_option( $this->get_customer_id_option(), $user_id );
		if ( false === $customer_id ) {
			$this->maybe_migrate_deprecated_customer_id( $user_id );
			$customer_id = get_user_option( $this->get_customer_id_option(), $user_id );
		}

		return is_string( $customer_id ) && '' !== $customer_id ? $customer_id : null;
	}

	/**
	 * Persist a WooPayments customer ID for a user or guest session.
	 *
	 * @param int|null $user_id     WordPress user ID or null for guests.
	 * @param string   $customer_id WooPayments customer ID.
	 */
	private function persist_customer_id( ?int $user_id, string $customer_id ): void {
		if ( null !== $user_id && 0 !== $user_id ) {
			update_user_option( $user_id, $this->get_customer_id_option(), $customer_id );
		}

		if ( WC()->session ) {
			WC()->session->set( self::CUSTOMER_ID_SESSION_KEY, $customer_id );
		}
	}

	/**
	 * Delete a persisted customer ID for a user or guest session.
	 *
	 * @param int|null $user_id WordPress user ID or null for guests.
	 */
	private function delete_customer_id( ?int $user_id ): void {
		if ( null !== $user_id && 0 !== $user_id ) {
			delete_user_option( $user_id, $this->get_customer_id_option() );
			delete_user_option( $user_id, self::DEPRECATED_CUSTOMER_ID_OPTION );
		}

		if ( WC()->session ) {
			WC()->session->set( self::CUSTOMER_ID_SESSION_KEY, null );
		}
	}

	/**
	 * Migrate the deprecated WooPayments customer ID option to the current mode-aware key.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function maybe_migrate_deprecated_customer_id( int $user_id ): void {
		$customer_id = get_user_option( self::DEPRECATED_CUSTOMER_ID_OPTION, $user_id );
		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return;
		}

		update_user_option( $user_id, $this->get_customer_id_option(), $customer_id );
		delete_user_option( $user_id, self::DEPRECATED_CUSTOMER_ID_OPTION );
	}

	/**
	 * Get the mode-aware user option key for WooPayments customer IDs.
	 *
	 * @return string
	 */
	private function get_customer_id_option(): string {
		return $this->account_service->is_test_mode_enabled() ? self::TEST_CUSTOMER_ID_OPTION : self::LIVE_CUSTOMER_ID_OPTION;
	}

	/**
	 * Get the order's associated WordPress user ID when present.
	 *
	 * @param WC_Order $order Order being charged.
	 * @return int|null
	 */
	private function get_order_user_id( WC_Order $order ): ?int {
		$user_id = (int) $order->get_customer_id();

		return $user_id > 0 ? $user_id : null;
	}
}
