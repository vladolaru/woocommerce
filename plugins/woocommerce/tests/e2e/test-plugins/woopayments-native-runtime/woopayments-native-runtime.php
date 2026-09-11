<?php
/**
 * Plugin Name: WooPayments native E2E runtime
 * Description: Fail-closed native WooPayments activation and authenticated diagnostics for E2E environments.
 *
 * @package woopayments-native-runtime
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Installs the early native-runtime filter and read-only E2E diagnostics.
 */
final class WooCommerce_WooPayments_Native_E2E_Runtime {

	/**
	 * Native runtime enablement filter.
	 *
	 * @var string
	 */
	private const NATIVE_ENABLED_FILTER = 'woocommerce_native_payments_enabled';

	/**
	 * Host-controlled native runtime kill-switch option.
	 *
	 * @var string
	 */
	private const KILL_SWITCH_OPTION = 'woocommerce_native_payments_killswitch';

	/**
	 * Diagnostic route namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'wc-native-payments-e2e/v1';

	/**
	 * Diagnostic route.
	 *
	 * @var string
	 */
	private const REST_ROUTE = '/status';

	/**
	 * Saved-card evidence route.
	 *
	 * @var string
	 */
	private const SAVED_CARD_REST_ROUTE = '/saved-card-evidence';

	/**
	 * Subscription evidence route.
	 *
	 * @var string
	 */
	private const SUBSCRIPTION_REST_ROUTE = '/subscription-evidence';

	/**
	 * The WooCommerce Subscriptions renewal-payment scheduled action hook.
	 *
	 * @var string
	 */
	private const RENEWAL_ACTION_HOOK = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Register bootstrap hooks immediately at mu-plugin load.
	 *
	 * @since 11.0.0
	 */
	/**
	 * Tell whether PHPUnit is driving this request rather than a served E2E request.
	 *
	 * This file is mapped as an mu-plugin by the top-level `mappings` block in
	 * `.wp-env.json`, which applies to every wp-env environment, so it also
	 * loads inside the PHPUnit container. There it would force native payments
	 * ownership on for every test as an invisible ambient precondition — the
	 * opposite of the opt-in contract the unit tests are written against, where
	 * a test needing the native runtime adds the enablement filter itself.
	 *
	 * @return bool True when PHPUnit is driving this request.
	 */
	public static function is_phpunit_run(): bool {
		// Both signals are set before WordPress loads mu-plugins: the constant
		// by PHPUnit's Composer entry point, the class by other install methods.
		return defined( 'PHPUNIT_COMPOSER_INSTALL' )
			|| class_exists( 'PHPUnit\\Framework\\TestCase', false );
	}

	/**
	 * Install the native-runtime filter and the read-only diagnostics route.
	 */
	public function register(): void {
		add_filter( self::NATIVE_ENABLED_FILTER, array( $this, 'handle_native_enabled' ), 0 );
		add_action( 'rest_api_init', array( $this, 'handle_rest_api_init' ) );
	}

	/**
	 * Enable native ownership only for the exact E2E opt-in and an inactive kill switch.
	 *
	 * @internal
	 *
	 * @param bool $enabled Existing runtime enablement.
	 * @return bool
	 */
	public function handle_native_enabled( bool $enabled ): bool {
		if ( true !== self::get_e2e_activation_constant() ) {
			return $enabled;
		}

		return ! (bool) get_option( self::KILL_SWITCH_OPTION, false );
	}

	/**
	 * Read the exact E2E activation constant.
	 *
	 * Resolved through Jetpack's constants layer when it is loaded, so a test can
	 * simulate the constant being absent. That matters because wp-env defines
	 * this constant for the whole environment — including the PHPUnit container —
	 * which would otherwise make the absent case impossible to exercise on a
	 * configured machine while still passing on CI.
	 *
	 * @return mixed The constant value, or null when it is not set.
	 */
	private static function get_e2e_activation_constant() {
		if ( class_exists( '\Automattic\Jetpack\Constants' ) ) {
			return \Automattic\Jetpack\Constants::get_constant( 'E2E_WOOPAYMENTS_NATIVE' );
		}

		return defined( 'E2E_WOOPAYMENTS_NATIVE' ) ? constant( 'E2E_WOOPAYMENTS_NATIVE' ) : null;
	}

	/**
	 * Register the authenticated, read-only runtime status route.
	 *
	 * @internal
	 */
	public function handle_rest_api_init(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::SAVED_CARD_REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_saved_card_evidence' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::SUBSCRIPTION_REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_evidence' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check whether the caller may inspect WooCommerce runtime diagnostics.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get redacted runtime readiness diagnostics.
	 *
	 * @return WP_REST_Response
	 *
	 * @since 11.0.0
	 */
	public function get_status(): WP_REST_Response {
		$status_data = $this->get_native_status_data();

		return rest_ensure_response(
			array(
				'bootstrap_load_phase'    => 'mu-plugin',
				'site_url'                => get_site_url(),
				'wpcom_blog_id'           => $this->get_wpcom_blog_id(),
				'runtime_owner'           => (string) ( $status_data['runtime_owner'] ?? 'none' ),
				'native_enabled'          => (bool) ( $status_data['native_enabled'] ?? false ),
				'kill_switch'             => (bool) get_option( self::KILL_SWITCH_OPTION, false ),
				'account_id'              => (string) ( $status_data['account_id'] ?? '' ),
				'account_connected'       => (bool) ( $status_data['account_connected'] ?? false ),
				'gateway_enabled'         => (bool) ( $status_data['gateway_enabled'] ?? false ),
				'test_mode'               => (bool) ( $status_data['test_mode'] ?? false ),
				'enabled_payment_methods' => $this->get_enabled_payment_methods( $status_data ),
				'last_webhook_fetch'      => (int) ( $status_data['last_webhook_fetch'] ?? 0 ),
				'callback_probe'          => array(
					'registered'    => false,
					'reachable'     => false,
					'wpcom_blog_id' => 0,
				),
			)
		);
	}

	/**
	 * Get strict, read-only saved-card evidence for the configured E2E customer.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_saved_card_evidence( WP_REST_Request $request ) {
		$customer_username = $request->get_param( 'customer_username' );
		if ( ! is_string( $customer_username ) || '' === $customer_username ) {
			return new WP_Error(
				'saved_card_customer_required',
				'An exact customer username is required.',
				array( 'status' => 400 )
			);
		}

		$customer = get_user_by( 'login', $customer_username );
		if ( false === $customer ) {
			return new WP_Error(
				'saved_card_customer_not_found',
				'The exact saved-card customer does not exist.',
				array( 'status' => 404 )
			);
		}

		$tokens         = WC_Payment_Tokens::get_tokens(
			array(
				'user_id'    => $customer->ID,
				'gateway_id' => 'woocommerce_payments',
				'limit'      => 100,
			)
		);
		$token_evidence = array();
		foreach ( $tokens as $token ) {
			if ( ! $token instanceof WC_Payment_Token ) {
				return new WP_Error(
					'saved_card_token_malformed',
					'WooCommerce returned malformed saved-card token evidence.',
					array( 'status' => 500 )
				);
			}
			if ( 'woocommerce_payments' !== $token->get_gateway_id() ) {
				continue;
			}

			$token_evidence[] = array(
				'token_id'          => $token->get_id(),
				'payment_method_id' => $token->get_token(),
				'is_default'        => $token->is_default(),
			);
		}
		usort(
			$token_evidence,
			static fn ( array $first, array $second ): int => $first['token_id'] <=> $second['token_id']
		);

		$response = array(
			'creation_ready' => ! WC_Rate_Limiter::retried_too_soon( 'add_payment_method_' . $customer->ID ),
			'tokens'         => $token_evidence,
		);

		$token_id          = $request->get_param( 'token_id' );
		$payment_method_id = $request->get_param( 'payment_method_id' );
		$has_token_id      = null !== $token_id;
		$has_payment_id    = null !== $payment_method_id;
		if ( $has_token_id !== $has_payment_id ) {
			return new WP_Error(
				'saved_card_identity_incomplete',
				'Both the token ID and payment-method ID are required for named evidence.',
				array( 'status' => 400 )
			);
		}
		if ( ! $has_token_id ) {
			// Unnamed evidence still reports the provider customer when the store
			// has one. A negative saved-card contract — "the declined method did
			// not attach" — has no token to name, so the strict named branch below
			// cannot serve it, and without the customer identity there is no way
			// to read the provider's attachment list at all. Resolution is
			// best-effort here: a customer who has never reached the provider
			// simply has no ID, which is an observation rather than an error.
			$provider_customer_id = $this->resolve_provider_customer_id( $customer->ID );
			if ( '' !== $provider_customer_id ) {
				$response['provider_customer_id'] = $provider_customer_id;
			}

			return rest_ensure_response( $response );
		}

		if (
			! is_numeric( $token_id ) ||
			(int) $token_id <= 0 ||
			(string) (int) $token_id !== (string) $token_id ||
			! is_string( $payment_method_id ) ||
			'' === $payment_method_id
		) {
			return new WP_Error(
				'saved_card_identity_invalid',
				'Named saved-card evidence requires exact, well-formed identifiers.',
				array( 'status' => 400 )
			);
		}

		$matched_token = array_values(
			array_filter(
				$token_evidence,
				static fn ( array $candidate ): bool => (int) $token_id === $candidate['token_id']
			)
		);
		if (
			1 !== count( $matched_token ) ||
			$payment_method_id !== $matched_token[0]['payment_method_id']
		) {
			return new WP_Error(
				'saved_card_mapping_mismatch',
				'The exact local token and provider payment-method mapping was not found.',
				array( 'status' => 409 )
			);
		}
		if ( true !== $matched_token[0]['is_default'] ) {
			return new WP_Error(
				'saved_card_not_default',
				'The exact local token is not the default payment method.',
				array( 'status' => 409 )
			);
		}

		$status_data = $this->get_native_status_data();
		if ( 'native' !== ( $status_data['runtime_owner'] ?? null ) ) {
			return new WP_Error(
				'saved_card_native_runtime_required',
				'Provider saved-card evidence is available only from the native runtime owner.',
				array( 'status' => 409 )
			);
		}

		$customer_service_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCustomerService';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $customer_service_class ) ) {
			return new WP_Error(
				'saved_card_provider_unavailable',
				'Native WooPayments provider evidence is unavailable.',
				array( 'status' => 502 )
			);
		}

		try {
			$customer_service     = wc_get_container()->get( $customer_service_class );
			$provider_customer_id = $customer_service->get_persisted_customer_id_by_user_id( $customer->ID );
			if ( ! is_string( $provider_customer_id ) || '' === $provider_customer_id ) {
				return new WP_Error(
					'saved_card_provider_customer_missing',
					'The customer has no exact WooPayments provider customer ID.',
					array( 'status' => 409 )
				);
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			return new WP_Error(
				'saved_card_provider_read_failed',
				'WooPayments provider saved-card evidence could not be read.',
				array( 'status' => 502 )
			);
		}

		$response['provider_customer_id'] = $provider_customer_id;

		return rest_ensure_response( $response );
	}

	/**
	 * Get strict, read-only subscription evidence.
	 *
	 * The WooCommerce REST API cannot serve this. A subscription's recurring
	 * credential lives in the `_payment_tokens` order meta, which every order
	 * data store lists as an internal meta key, so it is filtered out of the
	 * `meta_data` a subscription response carries. Scheduled actions and
	 * provider SetupIntents have no REST surface at all. A fidelity claim about
	 * "the exact token the subscription renews on" therefore has nothing to read
	 * without this route; a rendered card label is exactly the weak oracle the
	 * claim exists to replace.
	 *
	 * Read-only throughout: nothing here creates, mutates, or deletes anything.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subscription_evidence( WP_REST_Request $request ) {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'wcs_is_subscription' ) ) {
			return new WP_Error(
				'subscription_support_unavailable',
				'WooCommerce Subscriptions is not active on this store.',
				array( 'status' => 501 )
			);
		}

		$response = array(
			'pending_renewal_actions' => $this->get_pending_renewal_actions(),
			'store_baseline'          => $this->get_store_baseline(),
		);

		$customer_username = $request->get_param( 'customer_username' );
		if ( null !== $customer_username ) {
			if ( ! is_string( $customer_username ) || '' === $customer_username ) {
				return new WP_Error(
					'subscription_customer_invalid',
					'An exact customer username is required.',
					array( 'status' => 400 )
				);
			}

			$customer = get_user_by( 'login', $customer_username );
			if ( false === $customer ) {
				return new WP_Error(
					'subscription_customer_not_found',
					'The exact subscription customer does not exist.',
					array( 'status' => 404 )
				);
			}

			$customer_subscriptions    = wcs_get_subscriptions(
				array(
					'customer_id'            => $customer->ID,
					'subscriptions_per_page' => 200,
				)
			);
			$customer_subscription_ids = array_map( 'absint', array_keys( $customer_subscriptions ) );
			sort( $customer_subscription_ids );
			$response['customer_subscription_ids'] = array_values( $customer_subscription_ids );
		}

		$subscription_id = $request->get_param( 'subscription_id' );
		if ( null !== $subscription_id ) {
			if (
				! is_numeric( $subscription_id ) ||
				(int) $subscription_id <= 0 ||
				(string) (int) $subscription_id !== (string) $subscription_id
			) {
				return new WP_Error(
					'subscription_identity_invalid',
					'Named subscription evidence requires an exact, well-formed subscription ID.',
					array( 'status' => 400 )
				);
			}

			$subscription = wcs_get_subscription( (int) $subscription_id );
			if ( ! $subscription ) {
				return new WP_Error(
					'subscription_not_found',
					'The exact subscription does not exist.',
					array( 'status' => 404 )
				);
			}

			$response['subscription'] = $this->describe_subscription( $subscription );
		}

		$setup_intent_id = $request->get_param( 'setup_intent_id' );
		if ( null !== $setup_intent_id ) {
			$setup_intent = $this->read_provider_setup_intent( $setup_intent_id );
			if ( $setup_intent instanceof WP_Error ) {
				return $setup_intent;
			}

			$response['setup_intent'] = $setup_intent;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get the store configuration a subscription run must leave byte-identical.
	 *
	 * The gateway settings are reported as a stable hash rather than verbatim:
	 * a byte-for-byte restoration check needs to compare bytes, not to publish
	 * them, and the three fields this family actually depends on are named
	 * alongside so a drift report can say which one moved.
	 *
	 * @return array<string,mixed>
	 */
	private function get_store_baseline(): array {
		$settings = get_option( 'woocommerce_woocommerce_payments_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		ksort( $settings );

		return array(
			'gateway_settings_hash' => hash( 'sha256', (string) wp_json_encode( $settings ) ),
			'gateway_enabled'       => (string) ( $settings['enabled'] ?? '' ),
			'gateway_saved_cards'   => (string) ( $settings['saved_cards'] ?? '' ),
			'gateway_test_mode'     => (string) ( $settings['test_mode'] ?? '' ),
			'store_currency'        => (string) get_option( 'woocommerce_currency', '' ),
			'subscription_settings' => array(
				'accept_manual_renewals'      => (string) get_option( 'woocommerce_subscriptions_accept_manual_renewals', '' ),
				'turn_off_automatic_payments' => (string) get_option( 'woocommerce_subscriptions_turn_off_automatic_payments', '' ),
				'multiple_purchase'           => (string) get_option( 'woocommerce_subscriptions_multiple_purchase', '' ),
			),
		);
	}

	/**
	 * Describe one subscription's exact identity relationships.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array<string,mixed>
	 */
	private function describe_subscription( $subscription ): array {
		// Order matters and duplicates are real: WooCommerce treats the last
		// entry as the active credential, so the list is reported verbatim
		// rather than de-duplicated or sorted.
		$token_ids = array_values( array_map( 'absint', $subscription->get_payment_tokens() ) );
		$tokens    = array();
		foreach ( $token_ids as $token_id ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( ! $token instanceof WC_Payment_Token ) {
				$tokens[] = array(
					'token_id'          => $token_id,
					'exists'            => false,
					'payment_method_id' => '',
					'gateway_id'        => '',
					'user_id'           => 0,
					'is_default'        => false,
				);
				continue;
			}

			$tokens[] = array(
				'token_id'          => $token_id,
				'exists'            => true,
				'payment_method_id' => (string) $token->get_token(),
				'gateway_id'        => (string) $token->get_gateway_id(),
				'user_id'           => (int) $token->get_user_id(),
				'is_default'        => (bool) $token->is_default(),
			);
		}

		$line_items = array();
		foreach ( $subscription->get_items( 'line_item' ) as $item_id => $item ) {
			$line_items[] = array(
				'item_id'    => (int) $item_id,
				'product_id' => (int) $item->get_product_id(),
				'quantity'   => (int) $item->get_quantity(),
				'subtotal'   => (string) $item->get_subtotal(),
				'total'      => (string) $item->get_total(),
			);
		}

		$fee_lines = array();
		foreach ( $subscription->get_items( 'fee' ) as $item_id => $item ) {
			$fee_lines[] = array(
				'item_id' => (int) $item_id,
				'name'    => (string) $item->get_name(),
				'total'   => (string) $item->get_total(),
			);
		}

		$related_orders = array();
		foreach ( array( 'parent', 'renewal', 'switch', 'resubscribe' ) as $relation ) {
			$ids = array_values( array_map( 'absint', (array) $subscription->get_related_orders( 'ids', $relation ) ) );
			sort( $ids );
			$related_orders[ $relation ] = $ids;
		}

		return array(
			'id'                      => (int) $subscription->get_id(),
			'status'                  => (string) $subscription->get_status(),
			'parent_id'               => (int) $subscription->get_parent_id(),
			'customer_id'             => (int) $subscription->get_customer_id(),
			'currency'                => strtoupper( (string) $subscription->get_currency() ),
			'total'                   => (string) $subscription->get_total(),
			'payment_method'          => (string) $subscription->get_payment_method(),
			'billing_period'          => (string) $subscription->get_billing_period(),
			'billing_interval'        => (string) $subscription->get_billing_interval(),
			'requires_manual_renewal' => (bool) $subscription->get_requires_manual_renewal(),
			'payment_count'           => (int) $subscription->get_payment_count(),
			'start_gmt'               => (string) $subscription->get_date( 'start' ),
			'trial_end_gmt'           => (string) $subscription->get_date( 'trial_end' ),
			'trial_end_display'       => date_i18n( wc_date_format(), wcs_date_to_time( get_date_from_gmt( $subscription->get_date( 'trial_end' ) ) ) ),
			'next_payment_gmt'        => (string) $subscription->get_date( 'next_payment' ),
			'payment_token_ids'       => $token_ids,
			'active_token_id'         => count( $token_ids ) > 0 ? (int) end( $token_ids ) : 0,
			'payment_tokens'          => $tokens,
			'line_items'              => $line_items,
			'fee_lines'               => $fee_lines,
			'related_orders'          => $related_orders,
			'scheduled_actions'       => $this->get_subscription_scheduled_actions( (int) $subscription->get_id() ),
		);
	}

	/**
	 * List every scheduled action Action Scheduler holds for one subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_subscription_scheduled_actions( int $subscription_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return array();
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'args'     => array( 'subscription_id' => $subscription_id ),
				'per_page' => 100,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			),
			'ids'
		);

		return $this->describe_scheduled_actions( is_array( $action_ids ) ? $action_ids : array() );
	}

	/**
	 * List every pending WooCommerce Subscriptions renewal action on the store.
	 *
	 * A run that dispatches WP-Cron runs the whole due queue, not only its own
	 * action, so a caller has to be able to prove no foreign subscription is due
	 * before it does. Reporting every pending renewal action lets the caller
	 * refuse to dispatch rather than silently charge someone else's fixture.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_renewal_actions(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array();
		}

		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => self::RENEWAL_ACTION_HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 200,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			),
			'ids'
		);

		return $this->describe_scheduled_actions( is_array( $action_ids ) ? $action_ids : array() );
	}

	/**
	 * Describe Action Scheduler actions by ID.
	 *
	 * @param array<int,mixed> $action_ids Action IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function describe_scheduled_actions( array $action_ids ): array {
		$store   = ActionScheduler::store();
		$actions = array();

		foreach ( $action_ids as $raw_action_id ) {
			$action_id = absint( $raw_action_id );
			if ( 0 === $action_id ) {
				continue;
			}

			try {
				$action = $store->fetch_action( (string) $action_id );
			} catch ( Throwable $exception ) {
				unset( $exception );
				continue;
			}

			$scheduled_timestamp = 0;
			try {
				$scheduled = $store->get_date( (string) $action_id );
				if ( $scheduled instanceof DateTime ) {
					$scheduled_timestamp = (int) $scheduled->getTimestamp();
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
			}

			$arguments = $action->get_args();

			$actions[] = array(
				'action_id'           => $action_id,
				'hook'                => (string) $action->get_hook(),
				'status'              => (string) $store->get_status( (string) $action_id ),
				'subscription_id'     => isset( $arguments['subscription_id'] ) ? absint( $arguments['subscription_id'] ) : 0,
				'scheduled_timestamp' => $scheduled_timestamp,
			);
		}

		return $actions;
	}

	/**
	 * Read one provider SetupIntent, so a zero-total signup can be proved at the provider.
	 *
	 * @param mixed $setup_intent_id Requested SetupIntent ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read_provider_setup_intent( $setup_intent_id ) {
		if ( ! is_string( $setup_intent_id ) || 1 !== preg_match( '/^seti_[A-Za-z0-9_]+$/', $setup_intent_id ) ) {
			return new WP_Error(
				'setup_intent_identity_invalid',
				'Provider SetupIntent evidence requires an exact SetupIntent ID.',
				array( 'status' => 400 )
			);
		}

		$status_data = $this->get_native_status_data();
		if ( 'native' !== ( $status_data['runtime_owner'] ?? null ) ) {
			return new WP_Error(
				'setup_intent_native_runtime_required',
				'Provider SetupIntent evidence is available only from the native runtime owner.',
				array( 'status' => 409 )
			);
		}

		$api_client_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $api_client_class ) ) {
			return new WP_Error(
				'setup_intent_provider_unavailable',
				'Native WooPayments provider evidence is unavailable.',
				array( 'status' => 502 )
			);
		}

		try {
			$api_client   = wc_get_container()->get( $api_client_class );
			$setup_intent = $api_client->get_setup_intention( $setup_intent_id );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return new WP_Error(
				'setup_intent_provider_read_failed',
				'The WooPayments provider SetupIntent could not be read.',
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $setup_intent ) ) {
			return new WP_Error(
				'setup_intent_provider_malformed',
				'The WooPayments provider returned malformed SetupIntent evidence.',
				array( 'status' => 502 )
			);
		}

		$payment_method = $setup_intent['payment_method'] ?? '';
		if ( is_array( $payment_method ) ) {
			$payment_method = $payment_method['id'] ?? '';
		}
		$customer = $setup_intent['customer'] ?? '';
		if ( is_array( $customer ) ) {
			$customer = $customer['id'] ?? '';
		}
		$last_setup_error = $setup_intent['last_setup_error'] ?? array();
		$last_setup_error = is_array( $last_setup_error ) ? $last_setup_error : array();
		$next_action      = $setup_intent['next_action'] ?? array();
		$next_action      = is_array( $next_action ) ? $next_action : array();

		// Redacted on purpose: the identity fields the claim needs and nothing
		// else, so no client secret or raw provider payload leaves the store.
		return array(
			'id'                => (string) ( $setup_intent['id'] ?? '' ),
			'status'            => (string) ( $setup_intent['status'] ?? '' ),
			'usage'             => (string) ( $setup_intent['usage'] ?? '' ),
			'payment_method_id' => is_scalar( $payment_method ) ? (string) $payment_method : '',
			'customer_id'       => is_scalar( $customer ) ? (string) $customer : '',
			'next_action_type'  => is_scalar( $next_action['type'] ?? null ) ? (string) $next_action['type'] : '',
			'last_setup_error'  => array(
				'type' => is_scalar( $last_setup_error['type'] ?? null ) ? (string) $last_setup_error['type'] : '',
				'code' => is_scalar( $last_setup_error['code'] ?? null ) ? (string) $last_setup_error['code'] : '',
			),
		);
	}

	/**
	 * Resolve a user's persisted WooPayments provider customer ID, or an empty string.
	 *
	 * Never throws and never errors: callers use it where an absent provider
	 * customer is a legitimate observation, not a failure.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private function resolve_provider_customer_id( int $user_id ): string {
		$status_data = $this->get_native_status_data();
		if ( 'native' !== ( $status_data['runtime_owner'] ?? null ) ) {
			return '';
		}

		$customer_service_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCustomerService';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $customer_service_class ) ) {
			return '';
		}

		try {
			$customer_service     = wc_get_container()->get( $customer_service_class );
			$provider_customer_id = $customer_service->get_persisted_customer_id_by_user_id( $user_id );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return '';
		}

		return is_string( $provider_customer_id ) ? $provider_customer_id : '';
	}

	/**
	 * Get native status data without exposing credentials or raw provider payloads.
	 *
	 * @return array<string,mixed>
	 */
	private function get_native_status_data(): array {
		$status_report_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsStatusReport';

		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $status_report_class ) ) {
			return array();
		}

		try {
			$status_report = wc_get_container()->get( $status_report_class );
			$status_data   = $status_report->get_status_data();
		} catch ( Throwable $exception ) {
			unset( $exception );
			return array();
		}

		return is_array( $status_data ) ? $status_data : array();
	}

	/**
	 * Get the Jetpack/WPCOM blog ID.
	 *
	 * @return int
	 */
	private function get_wpcom_blog_id(): int {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return 0;
		}

		return (int) Jetpack_Options::get_option( 'id' );
	}

	/**
	 * Get a scalar-only list of enabled methods.
	 *
	 * @param array<string,mixed> $status_data Native status data.
	 * @return string[]
	 */
	private function get_enabled_payment_methods( array $status_data ): array {
		$methods = $status_data['enabled_payment_methods'] ?? array();
		if ( ! is_array( $methods ) ) {
			return array();
		}

		return array_values(
			array_map(
				'strval',
				array_filter( $methods, 'is_scalar' )
			)
		);
	}
}

if ( ! WooCommerce_WooPayments_Native_E2E_Runtime::is_phpunit_run() ) {
	( new WooCommerce_WooPayments_Native_E2E_Runtime() )->register();
}
