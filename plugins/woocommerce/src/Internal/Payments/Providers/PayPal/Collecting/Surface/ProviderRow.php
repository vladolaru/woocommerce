<?php
/**
 * ProviderRow class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use WC_AJAX;

defined( 'ABSPATH' ) || exit;

/**
 * The notice under the PayPal Wallet row of the Payments settings list, once an order was paid with the wallet and the
 * store still collects.
 *
 * The provider class asks the core-owned filter `woocommerce_paypal_wallet_provider_notice` for the notice, and this class
 * answers it. The row's NOX list item renders the notice (the POC slot) and its dismiss button calls the `wc_ajax`
 * action below.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class ProviderRow {

	/**
	 * The dismissal surface slug.
	 *
	 * @since 11.3.0
	 */
	public const SURFACE = 'row-notice';

	/**
	 * The `wc_ajax` action that stores a dismissal, and the action of its nonce.
	 *
	 * @since 11.3.0
	 */
	public const AJAX_ACTION = 'wc_paypal_wallet_dismiss_notice';

	/**
	 * The gateway row the notice belongs to.
	 */
	private const GATEWAY_ID = 'ppcp-gateway';

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
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection;

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options    The option reader; the stored options by default.
	 * @param Dismissals|null      $dismissals The dismissals; the stored ones by default.
	 * @param ConnectionState|null $connection The connection state reader; the stored options by default.
	 */
	public function __construct( ?Options $options = null, ?Dismissals $dismissals = null, ?ConnectionState $connection = null ) {
		$this->options    = $options ?? new Options();
		$this->dismissals = $dismissals ?? new Dismissals();
		$this->connection = $connection ?? new ConnectionState( $this->options );
	}

	/**
	 * Hook the notice and its dismissal.
	 *
	 * @since 11.3.0
	 */
	public function register(): void {
		add_filter( 'woocommerce_paypal_wallet_provider_notice', array( $this, 'handle_woocommerce_paypal_wallet_provider_notice' ), 10, 2 );
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'handle_wc_ajax_wc_paypal_wallet_dismiss_notice' ) );
	}

	/**
	 * Give the PayPal Wallet row its notice: a first wallet order exists, the store still collects, core owns the wallet and
	 * the current user has not dismissed it since.
	 *
	 * Hooked to `woocommerce_paypal_wallet_provider_notice`. A notice another callback already set is left alone.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $notice     The notice so far, or null.
	 * @param mixed $gateway_id The ID of the gateway row.
	 * @return mixed The notice array, or the value unchanged.
	 */
	public function handle_woocommerce_paypal_wallet_provider_notice( $notice = null, $gateway_id = '' ) {
		if ( null !== $notice || self::GATEWAY_ID !== $gateway_id ) {
			return $notice;
		}

		$first_order_id = $this->options->first_order_id();
		if ( $first_order_id <= 0 || ConnectionState::COLLECTING !== $this->connection->resolve() ) {
			return $notice;
		}
		// The extension's row shares the gateway ID, and its setup route does not exist; the Plugins page notice speaks then.
		if ( RuntimeServices::extension_owns_wallet() ) {
			return $notice;
		}
		if ( $this->dismissals->is_dismissed( self::SURFACE, get_current_user_id(), $first_order_id ) ) {
			return $notice;
		}

		return array(
			'title'        => __( 'Complete setup to receive your payment', 'woocommerce' ),
			'text'         => __( 'A customer placed an order and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', 'woocommerce' ),
			'action_label' => __( 'Complete setup', 'woocommerce' ),
			'action_url'   => PayPalWalletBootstrap::get_settings_url(),
			'dismissible'  => true,
			'dismiss_url'  => add_query_arg( '_wpnonce', wp_create_nonce( self::AJAX_ACTION ), WC_AJAX::get_endpoint( self::AJAX_ACTION ) ),
		);
	}

	/**
	 * Store the dismissal for the current user and answer the request.
	 *
	 * Hooked to `wc_ajax_wc_paypal_wallet_dismiss_notice`.
	 *
	 * @since 11.3.0
	 */
	public function handle_wc_ajax_wc_paypal_wallet_dismiss_notice(): void {
		// The nonce is checked in dismiss_from_request().
		if ( $this->dismiss_from_request( $_REQUEST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_send_json_success();
		}

		wp_send_json_error( null, 403 );
	}

	/**
	 * Store the dismissal for the current user when the request carries a valid nonce and the user can manage WooCommerce.
	 *
	 * @since 11.3.0
	 *
	 * @param array $request The request parameters.
	 * @return bool Whether the dismissal is stored.
	 */
	public function dismiss_from_request( array $request ): bool {
		$nonce = isset( $request['_wpnonce'] ) && is_string( $request['_wpnonce'] ) ? $request['_wpnonce'] : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, self::AJAX_ACTION ) ) {
			return false;
		}

		return $this->dismissals->dismiss( self::SURFACE, get_current_user_id() );
	}
}
