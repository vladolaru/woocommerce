<?php
/**
 * CollectingRestEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Rest;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\ProviderRow;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The REST routes of the collecting panel in the wallet's settings Overview, under the core-owned `wc/v3/paypal-wallet`
 * namespace: the panel data, the payee email, the status check, the onboarding link and the notice dismissals.
 *
 * Registered by the owner-independent surfaces, so the routes exist whoever owns the wallet. The transport and the
 * reconciler are resolved only when a route needs them.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectingRestEndpoint {

	/**
	 * The REST namespace. Core owns it; the wallet's own routes stay under `wc/v3/wc_paypal`.
	 *
	 * @since 11.3.0
	 */
	public const NAMESPACE = 'wc/v3/paypal-wallet';

	/**
	 * The merchant has no PayPal account PayPal can pay out to yet.
	 *
	 * @since 11.3.0
	 */
	public const MERCHANT_NO_ACCOUNT = 'no_account';

	/**
	 * The merchant has an account that can receive payments, but has not confirmed its email.
	 *
	 * @since 11.3.0
	 */
	public const MERCHANT_EMAIL_UNCONFIRMED = 'email_unconfirmed';

	/**
	 * The merchant's account is confirmed, but the onboarding is not complete.
	 *
	 * @since 11.3.0
	 */
	public const MERCHANT_CONFIRMED_NOT_CONNECTED = 'confirmed_not_connected';

	/**
	 * The store is connected.
	 *
	 * @since 11.3.0
	 */
	public const MERCHANT_CONNECTED = 'connected';

	/**
	 * The surfaces the dismiss route accepts.
	 *
	 * @since 11.3.0
	 */
	public const DISMISSIBLE_SURFACES = array( ProviderRow::SURFACE );

	/**
	 * The most held orders the panel lists; the count covers the rest.
	 */
	private const HELD_ORDERS_LISTED = 10;

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection;

	/**
	 * The held orders.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The dismissals.
	 *
	 * @var Dismissals
	 */
	private Dismissals $dismissals;

	/**
	 * Gives the transport.
	 *
	 * @var callable(): PlatformTransport
	 */
	private $transport;

	/**
	 * Gives the reconciler, or null when the wallet is not running.
	 *
	 * @var callable(): ?Reconciler
	 */
	private $reconciler;

	/**
	 * The arbiter, or null for core's.
	 *
	 * @var PayPalWalletRuntimeArbiter|null
	 */
	private ?PayPalWalletRuntimeArbiter $arbiter;

	/**
	 * Constructor.
	 *
	 * @param CollectingState                 $state       The collecting state.
	 * @param ConnectionState                 $connection  The connection state.
	 * @param HeldOrders                      $held_orders The held orders.
	 * @param Options                         $options     The option reader.
	 * @param Dismissals                      $dismissals  The dismissals.
	 * @param callable                        $transport   Gives the transport.
	 * @param callable                        $reconciler  Gives the reconciler, or null when the wallet is not running.
	 * @param PayPalWalletRuntimeArbiter|null $arbiter     Decides who owns the wallet; core's by default.
	 */
	public function __construct( CollectingState $state, ConnectionState $connection, HeldOrders $held_orders, Options $options, Dismissals $dismissals, callable $transport, callable $reconciler, ?PayPalWalletRuntimeArbiter $arbiter = null ) {
		$this->state       = $state;
		$this->connection  = $connection;
		$this->held_orders = $held_orders;
		$this->options     = $options;
		$this->dismissals  = $dismissals;
		$this->transport   = $transport;
		$this->reconciler  = $reconciler;
		$this->arbiter     = $arbiter;
	}

	/**
	 * Register the routes. Runs on `rest_api_init`; reads nothing.
	 *
	 * @since 11.3.0
	 */
	public function register_routes(): void {
		$permission = array( $this, 'check_permission' );

		register_rest_route(
			self::NAMESPACE,
			'/collecting',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_collecting' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/collecting/payee',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_payee' ),
				'permission_callback' => $permission,
				'args'                => array(
					'email' => array(
						'description'       => __( 'The PayPal email address buyers pay.', 'woocommerce' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && false !== is_email( $value );
						},
						'sanitize_callback' => static function ( $value ): string {
							return sanitize_email( (string) $value );
						},
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/collecting/check-status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_status' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/collecting/referral',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'referral' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/collecting/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => $permission,
				'args'                => array(
					'surface' => array(
						'description'       => __( 'The notice to dismiss.', 'woocommerce' ),
						'type'              => 'string',
						'required'          => true,
						'enum'              => self::DISMISSIBLE_SURFACES,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Only a user who can manage WooCommerce reaches the routes.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Answer the panel data.
	 *
	 * @since 11.3.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_collecting(): WP_REST_Response {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Change the payee email while it can change, or enter the collecting state with it from a dormant store where core
	 * owns the wallet and the transport is ready.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_payee( WP_REST_Request $request ) {
		$email = (string) $request->get_param( 'email' );
		$state = $this->connection->resolve();

		if ( ConnectionState::COLLECTING !== $state && ConnectionState::DORMANT !== $state ) {
			return new WP_Error( 'wc_paypal_wallet_already_connected', __( 'The store is already connected to PayPal, so the payee email cannot change.', 'woocommerce' ), array( 'status' => 409 ) );
		}

		try {
			if ( ConnectionState::COLLECTING === $state ) {
				$this->state->set_payee_email( $email );
			} else {
				// A dormant store starts collecting only where core runs the wallet, as the profiler card requires.
				if ( ! RuntimeServices::core_owns_wallet( $this->arbiter ) ) {
					return new WP_Error( 'wc_paypal_wallet_not_owned', __( 'PayPal Payments runs PayPal on this store, so WooCommerce cannot start collecting payments for it.', 'woocommerce' ), array( 'status' => 409 ) );
				}
				$transport = $this->transport();
				if ( ! $transport->is_ready() ) {
					return $this->transport_not_configured();
				}
				$this->state->enter( $email, $transport->environment() );
			}
		} catch ( InvalidArgumentException $invalid ) {
			return new WP_Error( 'wc_paypal_wallet_invalid_payee', $invalid->getMessage(), array( 'status' => 400 ) );
		} catch ( RuntimeException $refused ) {
			$code = ConnectionState::COLLECTING === $state ? 'wc_paypal_wallet_payee_bound' : 'wc_paypal_wallet_enter_failed';

			return new WP_Error( $code, $refused->getMessage(), array( 'status' => ConnectionState::COLLECTING === $state ? 409 : 500 ) );
		}

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Run the reconcile, which reads the seller status from PayPal and completes the setup when it is complete, then
	 * answer the panel data with the check's outcome under `check`.
	 *
	 * @since 11.3.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_status() {
		if ( ! $this->transport()->is_ready() ) {
			return $this->transport_not_configured();
		}
		$reconciler = ( $this->reconciler )();
		if ( ! $reconciler instanceof Reconciler ) {
			return new WP_Error( 'wc_paypal_wallet_not_running', __( 'PayPal Wallet is not running on this store, so its status cannot be checked.', 'woocommerce' ), array( 'status' => 503 ) );
		}

		$summary          = $reconciler->run();
		$payload          = $this->payload();
		$payload['check'] = $summary['onboarding'];

		return rest_ensure_response( $payload );
	}

	/**
	 * Answer the THIRD_PARTY onboarding link for the store's tracking ID, returning to the wallet's settings.
	 *
	 * @since 11.3.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function referral() {
		$transport = $this->transport();
		if ( ! $transport->is_ready() ) {
			return $this->transport_not_configured();
		}
		if ( ! $this->state->is_collecting() ) {
			return new WP_Error( 'wc_paypal_wallet_not_collecting', __( 'The store is not waiting for PayPal Wallet setup.', 'woocommerce' ), array( 'status' => 409 ) );
		}

		try {
			$url = $transport->referral_link( $this->state->tracking_id(), PayPalWalletBootstrap::get_settings_url() );
		} catch ( Throwable $failure ) {
			wc_get_logger()->warning(
				sprintf( 'PayPal wallet referral failed: %1$s: %2$s', get_class( $failure ), $failure->getMessage() ),
				array( 'source' => 'woocommerce-paypal-wallet' )
			);

			return new WP_Error( 'wc_paypal_wallet_paypal_unavailable', __( 'PayPal could not start the setup. Please try again later.', 'woocommerce' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response( array( 'url' => esc_url_raw( $url ) ) );
	}

	/**
	 * Store the current user's dismissal of a notice.
	 *
	 * @since 11.3.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response {
		$surface = (string) $request->get_param( 'surface' );

		return rest_ensure_response( array( 'dismissed' => $this->dismissals->dismiss( $surface, get_current_user_id() ) ) );
	}

	/**
	 * The panel data: the state, the payee, the merchant state, the held orders and whether the transport is ready.
	 *
	 * The merchant state comes from the seller status the last reconcile cached; reading it asks PayPal nothing. The
	 * held orders are the oldest few, oldest first, with the total in `held_orders_count`.
	 *
	 * @since 11.3.0
	 *
	 * @return array{state: string, payee_email: string, can_change_payee_email: bool, merchant_state: string, held_orders: array<int, array{id: int, number: string, deadline: int, edit_url: string}>, held_orders_count: int, earliest_deadline: int|null, transport_ready: bool}
	 */
	public function payload(): array {
		$state = $this->connection->resolve();

		$held = array();
		foreach ( $this->held_orders->oldest( self::HELD_ORDERS_LISTED ) as $order ) {
			$held[] = array(
				'id'       => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'deadline' => $this->held_orders->deadline_for( $order ),
				'edit_url' => $order->get_edit_order_url(),
			);
		}

		return array(
			'state'                  => $state,
			'payee_email'            => $this->state->payee_email(),
			'can_change_payee_email' => $this->state->can_change_payee_email(),
			'merchant_state'         => $this->merchant_state( $state ),
			'held_orders'            => $held,
			'held_orders_count'      => array() === $held ? 0 : $this->held_orders->count(),
			'earliest_deadline'      => array() === $held ? null : $held[0]['deadline'],
			'transport_ready'        => $this->transport()->is_ready(),
		);
	}

	/**
	 * Where the merchant stands with PayPal, from the connection state and the cached seller status.
	 *
	 * @param string $state The connection state.
	 * @return string One of the MERCHANT_ constants.
	 */
	private function merchant_state( string $state ): string {
		if ( ConnectionState::CONNECTED === $state || ConnectionState::PLATFORM_CONNECTED === $state ) {
			return self::MERCHANT_CONNECTED;
		}

		$status = $this->options->seller_status();
		if ( empty( $status['payments_receivable'] ) ) {
			return self::MERCHANT_NO_ACCOUNT;
		}

		return empty( $status['primary_email_confirmed'] ) ? self::MERCHANT_EMAIL_UNCONFIRMED : self::MERCHANT_CONFIRMED_NOT_CONNECTED;
	}

	/**
	 * The transport.
	 *
	 * @return PlatformTransport
	 */
	private function transport(): PlatformTransport {
		return ( $this->transport )();
	}

	/**
	 * The error a transport-bound route answers when the platform credentials are not configured.
	 *
	 * @return WP_Error
	 */
	private function transport_not_configured(): WP_Error {
		return new WP_Error( 'wc_paypal_wallet_transport_not_configured', __( 'PayPal Wallet setup is not available: the platform transport is not configured.', 'woocommerce' ), array( 'status' => 503 ) );
	}
}
