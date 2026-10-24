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
 * Creates and stores the WooPayments customer of a user or a guest session, and moves a guest's customer onto the account
 * created at checkout.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCustomerService implements RegisterHooksInterface {

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
	 * WooPayments API client.
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
	 * WooPayments session service.
	 *
	 * @var WooPaymentsSessionService
	 */
	private WooPaymentsSessionService $session_service;

	/**
	 * Orders whose payer's customer is refreshed at shutdown, with that payer, by order ID.
	 *
	 * @var array<int,array{order:WC_Order,user_id:int|null}>
	 */
	private array $orders_to_refresh = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient      $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param WooPaymentsSessionService $session_service WooPayments session service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service, WooPaymentsSessionService $session_service ): void {
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
		$this->session_service = $session_service;
	}

	/**
	 * Register the guest-to-account customer promotion.
	 *
	 * The personal-data eraser for the stored customer IDs is WooPaymentsCustomerDataEraser, registered on every tier.
	 *
	 * @internal
	 */
	public function register() {
		add_action( 'woocommerce_created_customer', array( $this, 'handle_woocommerce_created_customer' ) );
	}

	/**
	 * Handle the woocommerce_created_customer hook.
	 *
	 * When a shopper starts checkout as a guest and an account is created for them
	 * mid-checkout (e.g. purchasing a subscription product), promote the guest
	 * session's provider customer ID onto the new user so their payment history and
	 * saved methods follow the account instead of being orphaned on a guest customer.
	 *
	 * @internal
	 *
	 * @param int|string $user_id Newly created WordPress user ID.
	 * @return void
	 */
	public function handle_woocommerce_created_customer( $user_id ): void {
		// Not processing a checkout, bail.
		if (
			! ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT )
			&& ! ( function_exists( 'wcs_is_checkout_blocks_api_request' ) && wcs_is_checkout_blocks_api_request( 'v1/checkout' ) )
		) {
			return;
		}

		if ( ! $this->account_service->has_account() ) {
			return;
		}

		$customer_id = WC()->session ? WC()->session->get( self::CUSTOMER_ID_SESSION_KEY ) : null;
		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return;
		}

		$this->persist_customer_id( absint( $user_id ), $customer_id );
	}

	/**
	 * Get customer billing data for order-pay and add-payment-method surfaces.
	 *
	 * @return array{name:string,email:string,billing_country:string,address:array<string,string>|null}|array{}
	 */
	public function get_prepared_customer_data(): array {
		// Only genuine pay-for-order requests ship order billing data; change-payment
		// and hand-built order-pay URLs get none, as in client 11.1.0
		// (class-wc-payments-customer-service.php:507-511).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill gate on a public URL flag.
		$pay_for_order = isset( $_GET['pay_for_order'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) );
		$order_id      = absint( get_query_var( 'order-pay' ) );
		if ( $pay_for_order && 0 < $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && current_user_can( 'pay_for_order', $order->get_id() ) ) {
				return array(
					'name'            => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'email'           => $order->get_billing_email(),
					'billing_country' => $order->get_billing_country(),
					'address'         => array(
						'city'        => $order->get_billing_city(),
						'country'     => $order->get_billing_country(),
						'line1'       => $order->get_billing_address_1(),
						'line2'       => $order->get_billing_address_2(),
						'postal_code' => $order->get_billing_postcode(),
						'state'       => $order->get_billing_state(),
					),
				);
			}
		}

		if ( ! is_add_payment_method_page() ) {
			return array();
		}

		$user = wp_get_current_user();
		if ( 0 >= $user->ID ) {
			return array();
		}

		$billing_email = (string) get_user_meta( $user->ID, 'billing_email', true );

		return array(
			'name'            => trim( $user->user_firstname . ' ' . $user->user_lastname ),
			'email'           => '' !== $billing_email ? $billing_email : $user->user_email,
			'billing_country' => (string) get_user_meta( $user->ID, 'billing_country', true ),
			'address'         => null,
		);
	}

	/**
	 * Get the payer's WooPayments customer ID for a checkout or order-pay payment, creating one when the payer has none.
	 *
	 * An existing customer is refreshed from the order at shutdown, as client 11.1.0 does
	 * (includes/class-wc-payment-gateway-wcpay.php:1541-1584).
	 *
	 * @param WC_Order $order Order being charged.
	 * @return string
	 */
	public function get_or_create_customer_id_for_order( WC_Order $order ): string {
		return $this->get_or_create_customer_id( $order, $this->get_payer_customer_user_id( $order ), true );
	}

	/**
	 * Get or create a customer ID without refreshing an existing customer from a subscription.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Subscription order being charged.
	 * @return string
	 */
	public function get_or_create_customer_id_for_subscription_payment_method_change( WC_Order $order ): string {
		return $this->get_or_create_customer_id( $order, $this->get_payer_customer_user_id( $order ), false );
	}

	/**
	 * Get or create the customer ID of the order's own user, or of a guest's session, never the logged-in user's.
	 *
	 * For the mobile app's create_customer route, where the logged-in user is the merchant, not the payer.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order the customer is for.
	 * @return string
	 */
	public function get_or_create_customer_id_for_order_user( WC_Order $order ): string {
		return $this->get_or_create_customer_id( $order, $this->get_order_user_id( $order ), true );
	}

	/**
	 * Get the WordPress user paying an order on a shopper path: the order's user, else the logged-in user, else 0.
	 *
	 * As client 11.1.0 (includes/class-wc-payment-gateway-wcpay.php:1542-1544). Core shows the order-pay form only to the
	 * order's user, or to anyone for a guest order (pay_for_order capability), but the form's POST checks only the order key
	 * and nonce (WC_Form_Handler::pay_action()), so a saved card the request names is checked with get_request_token_user_id().
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order being paid.
	 * @return int
	 */
	public static function get_payer_user_id( WC_Order $order ): int {
		$user_id = $order->get_user_id();

		return 0 < $user_id ? $user_id : get_current_user_id();
	}

	/**
	 * Get the user whose saved card a shopper's request may use: the logged-in user when they are the payer, else 0.
	 *
	 * As client 11.1.0 (includes/class-payment-information.php:331-349), and also refused before any platform call when the
	 * logged-in user is not the order's user, whose customer the card would be charged on.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order being paid.
	 * @return int
	 */
	public static function get_request_token_user_id( WC_Order $order ): int {
		$user_id = get_current_user_id();

		return self::get_payer_user_id( $order ) === $user_id ? $user_id : 0;
	}

	/**
	 * Get or create the customer ID of the given user or, with none, of the guest session, refreshing an existing customer
	 * from the order at shutdown when asked.
	 *
	 * The order's _stripe_customer_id is not read: it can be another customer's or another plugin's.
	 *
	 * @param WC_Order $order                   Order being charged.
	 * @param int|null $user_id                 User whose customer is used, or null for the guest session.
	 * @param bool     $should_refresh_customer Whether an existing customer is refreshed from the order.
	 * @return string
	 */
	private function get_or_create_customer_id( WC_Order $order, ?int $user_id, bool $should_refresh_customer ): string {
		$customer_id = $this->migrate_and_get_customer_id( $user_id );

		if ( null !== $customer_id ) {
			if ( $should_refresh_customer ) {
				$this->refresh_customer_at_shutdown( $order, $user_id );
			}

			return $customer_id;
		}

		// Guard against concurrent checkouts creating duplicate remote customers
		// for the same logged-in user. On stores with a persistent object cache
		// the advisory lock is shared across requests, so only the request that
		// acquires it creates while the losers re-read the persisted ID. Guests
		// keep their ID in the WooCommerce session, which this lock does not
		// cover, so they skip it: two concurrent checkouts in one guest session
		// can each create a customer. Without a persistent object cache the lock
		// is request-local and every request creates, as with no lock.
		$lock_key = null === $user_id ? '' : 'wcpay_customer_create_' . $user_id;

		// wp_cache_add() both acquires the lock and reports contention: it
		// returns false when another request already holds the key, so a true
		// result means this request is the one that owns the lock.
		$acquired_lock = '' !== $lock_key && wp_cache_add( $lock_key, 1, 'woopayments', 10 );

		if ( '' !== $lock_key && ! $acquired_lock ) {
			$customer_id = $this->migrate_and_get_customer_id( $user_id );
			if ( null !== $customer_id ) {
				// The winning request already created and persisted the customer.
				if ( $should_refresh_customer ) {
					$this->refresh_customer_at_shutdown( $order, $user_id );
				}

				return $customer_id;
			}
			// The lock holder has not persisted yet (or there is no persistent
			// object cache); create anyway, as with no lock. We
			// do not own the lock, so we must not release it on the way out.
		}

		try {
			$customer_id = $this->api_client->create_customer( $this->with_session_id( $this->map_customer_data( $order, $user_id ) ) );
			$this->persist_customer_id( $user_id, $customer_id );

			return $customer_id;
		} finally {
			if ( $acquired_lock ) {
				wp_cache_delete( $lock_key, 'woopayments' );
			}
		}
	}

	/**
	 * Queue the payer's customer for a refresh from the order's details at shutdown.
	 *
	 * @param WC_Order $order   Order being charged.
	 * @param int|null $user_id Payer whose customer was used, or null for the guest session.
	 */
	private function refresh_customer_at_shutdown( WC_Order $order, ?int $user_id ): void {
		$this->orders_to_refresh[ $order->get_id() ] = array(
			'order'   => $order,
			'user_id' => $user_id,
		);
		if ( false === has_action( 'shutdown', array( $this, 'refresh_queued_customers' ) ) ) {
			add_action( 'shutdown', array( $this, 'refresh_queued_customers' ) );
		}
	}

	/**
	 * Refresh the customers queued in this request from their orders, after the payment answered.
	 *
	 * The queued payer's customer ID is read again, in case a missing customer was recreated during the payment. A failure
	 * is only logged, as client 11.1.0 does (includes/class-wc-payment-gateway-wcpay.php:1563-1582).
	 *
	 * @internal
	 */
	public function refresh_queued_customers(): void {
		$queued                  = $this->orders_to_refresh;
		$this->orders_to_refresh = array();
		foreach ( $queued as $entry ) {
			try {
				$customer_id = $this->migrate_and_get_customer_id( $entry['user_id'] );
				if ( null !== $customer_id ) {
					$this->update_customer( $customer_id, $entry['order'], $entry['user_id'] );
				}
			} catch ( \Exception $exception ) {
				wc_get_container()->get( WooPaymentsLogger::class )->error( 'Failed to update customer during shutdown: ' . $exception->getMessage() );
			}
		}
	}

	/**
	 * Update the WooPayments customer associated with an order.
	 *
	 * If the remote customer no longer exists, it is replaced; see replace_missing_customer_for_order(). An update does not
	 * store the customer on the user, as client 11.1.0's update_customer_for_user() does not (class-wc-payments-customer-service.php:200-222).
	 *
	 * @param string   $customer_id WooPayments customer ID.
	 * @param WC_Order $order       Order being charged.
	 * @return string Updated or replacement WooPayments customer ID.
	 * @throws WooPaymentsApiException When updating the remote customer fails for reasons other than a missing customer.
	 */
	public function update_customer_for_order( string $customer_id, WC_Order $order ): string {
		return $this->update_customer( $customer_id, $order, $this->get_order_user_id( $order ) );
	}

	/**
	 * Update a WooPayments customer from an order, naming the given user in its description.
	 *
	 * A missing remote customer is replaced as for the order; see replace_missing_customer_for_order().
	 *
	 * @param string   $customer_id WooPayments customer ID.
	 * @param WC_Order $order       Order being charged.
	 * @param int|null $user_id     User the customer belongs to, or null for a guest.
	 * @return string Updated or replacement WooPayments customer ID.
	 * @throws WooPaymentsApiException When updating the remote customer fails for reasons other than a missing customer.
	 */
	private function update_customer( string $customer_id, WC_Order $order, ?int $user_id ): string {
		$customer_data = $this->map_customer_data( $order, $user_id );

		try {
			$this->api_client->update_customer( $customer_id, $customer_data );

			return $customer_id;
		} catch ( WooPaymentsApiException $exception ) {
			if ( 'resource_missing' === $exception->get_error_code() ) {
				return $this->replace_missing_customer_for_order( $order, $customer_id );
			}

			throw $exception;
		}
	}

	/**
	 * Replace a customer the platform no longer has, for a payment of the order.
	 *
	 * When the missing customer is not the order's user's stored customer and the user has one, such as a WooCommerce
	 * Stripe Gateway customer left on a subscription, the user's customer is used, and nothing is deleted. Otherwise the
	 * user's customer is deleted and recreated, as client 11.1.0 does (class-wc-payment-gateway-wcpay.php:5417-5433).
	 *
	 * @param WC_Order $order               Order being charged.
	 * @param string   $missing_customer_id Customer ID the platform did not find.
	 * @return string
	 */
	public function replace_missing_customer_for_order( WC_Order $order, string $missing_customer_id ): string {
		$user_id = $this->get_order_user_id( $order );
		if ( null !== $user_id ) {
			$user_customer_id = $this->migrate_and_get_customer_id( $user_id );
			if ( null !== $user_customer_id && $missing_customer_id !== $user_customer_id ) {
				return $user_customer_id;
			}
		}

		$this->delete_customer_id( $user_id );

		$customer_id = $this->api_client->create_customer( $this->with_session_id( $this->map_customer_data( $order, $user_id ) ) );
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
		$customer_id = $this->migrate_and_get_customer_id( $user_id );
		if ( null !== $customer_id ) {
			return $customer_id;
		}

		$customer_id = $this->api_client->create_customer( $this->with_session_id( $this->map_customer_data_for_user( $user_id ) ) );
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
	 * Map WooCommerce order data to the WooPayments customer payload, naming the given user in the description.
	 *
	 * On a shopper path the user is the payer, as client 11.1.0 maps it (includes/class-wc-payment-gateway-wcpay.php:1507, :1551).
	 *
	 * @param WC_Order $order   Order being charged.
	 * @param int|null $user_id User the customer belongs to, or null for a guest.
	 * @return array<string,mixed>
	 */
	public function map_customer_data( WC_Order $order, ?int $user_id ): array {
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
		$name     = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );

		/* translators: 1: customer full name, 2: WordPress username. */
		$description = sprintf( __( 'Name: %1$s, Username: %2$s', 'woocommerce' ), $name, $customer->get_username() );

		$customer_data = array(
			'name'        => $name,
			'description' => $description,
			'email'       => $customer->get_billing_email(),
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

		if ( '' !== $customer->get_shipping_postcode() ) {
			$customer_data['shipping'] = array(
				'name'    => trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() ),
				'address' => array(
					'line1'       => $customer->get_shipping_address_1(),
					'line2'       => $customer->get_shipping_address_2(),
					'postal_code' => $customer->get_shipping_postcode(),
					'city'        => $customer->get_shipping_city(),
					'state'       => $customer->get_shipping_state(),
					'country'     => $customer->get_shipping_country(),
				),
			);
		}

		return $customer_data;
	}

	/**
	 * Get a user's or guest's customer ID, first moving the deprecated `_wcpay_customer_id` option to the current mode's
	 * option when that one is missing.
	 *
	 * Not a pure read: when the mode-aware option is missing, it migrates the deprecated
	 * `_wcpay_customer_id` user option to the live or test option and deletes the deprecated one.
	 * Use get_persisted_customer_id_by_user_id() for a read without side effects.
	 *
	 * @param int|null $user_id WordPress user ID or null for guests.
	 * @return string|null
	 */
	public function migrate_and_get_customer_id( ?int $user_id ): ?string {
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
	 * Read a persisted customer ID without migrating or deleting user options.
	 *
	 * For a check that must change nothing, such as the redirect return's SetupIntent customer check.
	 *
	 * @param int|null $user_id WordPress user ID or null for guests.
	 * @return string|null
	 */
	public function get_persisted_customer_id_by_user_id( ?int $user_id ): ?string {
		if ( null === $user_id || 0 === $user_id ) {
			$customer_id = WC()->session ? WC()->session->get( self::CUSTOMER_ID_SESSION_KEY ) : null;
			return is_string( $customer_id ) && '' !== $customer_id ? $customer_id : null;
		}

		$customer_id = get_user_option( $this->get_customer_id_option(), $user_id );
		if ( false === $customer_id ) {
			$customer_id = get_user_option( self::DEPRECATED_CUSTOMER_ID_OPTION, $user_id );
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
			$updated = update_user_option( $user_id, $this->get_customer_id_option(), $customer_id, $this->account_service->is_network_saved_cards_enabled() );

			// update_user_option() also returns false when the stored value is already
			// current; only a write that leaves a different value behind is a real failure.
			if ( ! $updated && get_user_option( $this->get_customer_id_option(), $user_id ) !== $customer_id ) {
				wc_get_container()->get( WooPaymentsLogger::class )->error( 'Failed to update the WooPayments customer ID for user ' . $user_id . '.' );
			}
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

		// A live account is optimistically assumed to hold a live customer, to avoid losing live
		// customer data; a non-live account can only hold test objects. When liveness cannot be
		// determined, default to live so a live account is never treated as test.
		$account_is_live = $this->account_service->get_account_is_live();
		$target_option   = false === $account_is_live ? self::TEST_CUSTOMER_ID_OPTION : self::LIVE_CUSTOMER_ID_OPTION;

		if ( update_user_option( $user_id, $target_option, $customer_id ) ) {
			delete_user_option( $user_id, self::DEPRECATED_CUSTOMER_ID_OPTION );
		} else {
			wc_get_container()->get( WooPaymentsLogger::class )->error( 'Failed to store the migrated WooPayments customer ID for user ' . $user_id . '; the unmigrated customer option was kept.' );
		}
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

	/**
	 * Get the payer's user ID for a customer lookup, or null when the payer is a guest; see get_payer_user_id().
	 *
	 * @param WC_Order $order Order being paid.
	 * @return int|null
	 */
	private function get_payer_customer_user_id( WC_Order $order ): ?int {
		$user_id = self::get_payer_user_id( $order );

		return $user_id > 0 ? $user_id : null;
	}

	/**
	 * Attach the shopper's Sift session ID to a customer-create payload.
	 *
	 * Create-only, as in client 11.1.0 (class-wc-payments-customer-service.php:150): the platform links the
	 * browsing session to the new customer for fraud scoring; updates never
	 * carry it.
	 *
	 * @param array<string,mixed> $customer_data Customer payload.
	 * @return array<string,mixed>
	 */
	private function with_session_id( array $customer_data ): array {
		$customer_data['session_id'] = $this->session_service->get_sift_session_id();

		return $customer_data;
	}
}
