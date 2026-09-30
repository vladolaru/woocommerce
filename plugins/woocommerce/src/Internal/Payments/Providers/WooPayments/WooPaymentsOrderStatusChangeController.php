<?php
/**
 * WooPaymentsOrderStatusChangeController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Loads the native WooPayments order status-change confirmation on the order-edit screen, and prints
 * the client's order-screen payment rows (WooPay line and transaction fee under the totals).
 *
 * The script it enqueues intercepts the order-edit status dropdown so that moving a WooPayments order
 * to Refunded raises a confirmation modal that refunds at the provider, instead of core silently
 * recording a local-only refund the customer never receives.
 *
 * All business rules live in {@see WooPaymentsOrderStatusChangeProjectionService}; this controller only
 * decides when to load the asset and hands the projected config to the browser.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderStatusChangeController implements RegisterHooksInterface {

	/**
	 * The wp-admin-scripts entry backing the confirmation modal.
	 *
	 * @var string
	 */
	private const SCRIPT_ENTRY = 'woopayments-order-status-change';

	/**
	 * The script handle produced by WCAdminAssets for the entry above.
	 *
	 * @var string
	 */
	private const SCRIPT_HANDLE = 'wc-admin-' . self::SCRIPT_ENTRY;

	/**
	 * The JS global carrying the projected config.
	 *
	 * @var string
	 */
	private const CONFIG_OBJECT_NAME = 'woocommerceWooPaymentsOrderStatusChange';

	/**
	 * ID of the order-screen notice mount point after the payment info.
	 *
	 * @var string
	 */
	private const PAYMENT_DETAILS_CONTAINER_ID = 'woocommerce-woopayments-order-payment-details';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Status-change confirmation projection service.
	 *
	 * @var WooPaymentsOrderStatusChangeProjectionService
	 */
	private WooPaymentsOrderStatusChangeProjectionService $projection_service;

	/**
	 * Admin asset availability resolver.
	 *
	 * @var callable|null
	 */
	private $asset_available_resolver = null;

	/**
	 * Admin asset registrar.
	 *
	 * @var callable|null
	 */
	private $asset_registrar = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter                  $arbiter            Runtime owner arbiter.
	 * @param WooPaymentsOrderStatusChangeProjectionService $projection_service Status-change confirmation projection service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsOrderStatusChangeProjectionService $projection_service ): void {
		$this->arbiter            = $arbiter;
		$this->projection_service = $projection_service;
	}

	/**
	 * Set the admin asset availability resolver.
	 *
	 * @internal Used by tests and future explicit asset bootstrap.
	 *
	 * @param callable $asset_available_resolver Admin asset availability resolver.
	 */
	public function set_asset_available_resolver( callable $asset_available_resolver ): void {
		$this->asset_available_resolver = $asset_available_resolver;
	}

	/**
	 * Set the admin asset registrar.
	 *
	 * @internal Used by tests and future explicit asset bootstrap.
	 *
	 * @param callable $asset_registrar Admin asset registrar.
	 */
	public function set_asset_registrar( callable $asset_registrar ): void {
		$this->asset_registrar = $asset_registrar;
	}

	/**
	 * Register order-edit screen hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) );
		}

		if ( false === has_action( 'woocommerce_admin_order_data_after_payment_info', array( $this, 'render_payment_details_container' ) ) ) {
			add_action( 'woocommerce_admin_order_data_after_payment_info', array( $this, 'render_payment_details_container' ) );
		}

		if ( false === has_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_woopay_payment_method_name' ) ) ) {
			add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_woopay_payment_method_name' ) );
		}

		if ( false === has_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_transaction_fee_row' ) ) ) {
			add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_transaction_fee_row' ) );
		}
	}

	/**
	 * Render the "Paid with WooPay" line under the order totals of a WooPay order.
	 *
	 * Mirrors plugin 11.1.0 `WC_Payments_Admin::show_woopay_payment_method_name_admin()`.
	 *
	 * @internal
	 *
	 * @param mixed $order_id The order ID.
	 */
	public function render_woopay_payment_method_name( $order_id = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( 'is_woopay' ) ) {
			return;
		}

		$last4 = (string) $order->get_meta( 'last4' );
		?>
		<div class="wc-payment-gateway-method-name-woopay-wrapper">
			<?php esc_html_e( 'Paid with', 'woocommerce' ); ?>
			<img alt="WooPay" src="<?php echo esc_url_raw( WC()->plugin_url() . '/assets/images/payment-methods/woo-short.svg' ); ?>">
			<?php
			if ( '' !== $last4 ) {
				esc_html_e( 'Card ending in', 'woocommerce' );
				echo ' ' . esc_html( $last4 );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the WooPayments transaction fee row under the order totals.
	 *
	 * Mirrors plugin 11.1.0 `WC_Payments_Admin::display_wcpay_transaction_fee()`: hidden while the
	 * payment awaits capture and when no fee is stored.
	 *
	 * @internal
	 *
	 * @param mixed $order_id The order ID.
	 */
	public function render_transaction_fee_row( $order_id = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'requires_capture' === $order->get_meta( '_intention_status' ) ) {
			return;
		}

		$transaction_fee = $order->get_meta( '_wcpay_transaction_fee' );
		if ( ! $transaction_fee ) {
			return;
		}

		?>
		<tr>
			<td class="label wcpay-transaction-fee">
				<?php
				echo wc_help_tip( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes its tip.
					sprintf(
						/* translators: %s: WooPayments */
						__( 'This represents the fee %s collects for the transaction.', 'woocommerce' ),
						'WooPayments'
					)
				);
				?>
				<?php esc_html_e( 'Transaction Fee:', 'woocommerce' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				-<?php echo wp_kses_post( wc_price( (float) $transaction_fee, array( 'currency' => $order->get_currency() ) ) ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the mount point for order notices after the order's payment info.
	 *
	 * Mirrors plugin 11.1.0 `WC_Payments_Admin::render_order_edit_payment_details_container()`, where the
	 * browser renders the order's test-mode notice (client `order/index.js:131-141`).
	 *
	 * @internal
	 *
	 * @param mixed $order The order being displayed.
	 */
	public function render_payment_details_container( $order = null ): void {
		if ( ! $order instanceof WC_Order || ! $this->projection_service->should_offer_confirmation( $order ) ) {
			return;
		}

		echo '<div id="' . esc_attr( self::PAYMENT_DETAILS_CONTAINER_ID ) . '"></div>';
	}

	/**
	 * Handle the admin_enqueue_scripts hook.
	 *
	 * @internal
	 */
	public function handle_admin_enqueue_scripts(): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';
		if ( ! in_array( $screen_id, $this->get_order_edit_screen_ids(), true ) ) {
			return;
		}

		$order = wc_get_order();
		if ( ! $order instanceof WC_Order || ! $this->projection_service->should_offer_confirmation( $order ) ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_add_inline_style( 'woocommerce_admin_styles', $this->get_inline_styles() );

		if ( ! $this->is_script_asset_available() ) {
			return;
		}

		$this->register_admin_script();

		// A JSON handoff rather than wp_localize_script(), which stringifies every scalar. The browser
		// decides on `can_refund` being false and `refund_amount` not being positive, and "0" is truthy
		// in JS - so the config crosses as real booleans and numbers, not as "1"/"" and decimal strings.
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.' . self::CONFIG_OBJECT_NAME . ' = ' . wp_json_encode(
				$this->projection_service->get_config( $order ),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * Get the order screen styles for the WooPay line.
	 *
	 * Client 11.1.0 `assets/css/admin.css:193-202`: the WooPay line sits at the end of the totals.
	 *
	 * @return string
	 */
	private function get_inline_styles(): string {
		return '.wc-payment-gateway-method-name-woopay-wrapper{display:flex;justify-content:end;align-items:center}'
			. '.wc-payment-gateway-method-name-woopay-wrapper img{margin:0 5px;padding-top:2px}';
	}

	/**
	 * Get order edit screen IDs that should receive the confirmation script.
	 *
	 * @return string[]
	 */
	private function get_order_edit_screen_ids(): array {
		$screen_ids = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}

	/**
	 * Tell whether the confirmation script bundle has been built.
	 *
	 * WCAdminAssets::register_script() throws when the asset registry is missing, so an unbuilt bundle
	 * must degrade to "no confirmation modal" rather than fatal the order-edit screen.
	 *
	 * @return bool
	 */
	private function is_script_asset_available(): bool {
		if ( null !== $this->asset_available_resolver ) {
			return (bool) call_user_func( $this->asset_available_resolver );
		}

		if ( ! defined( 'WC_ADMIN_ABSPATH' ) || ! defined( 'WC_ADMIN_DIST_JS_FOLDER' ) ) {
			return false;
		}

		$asset_path = WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . 'wp-admin-scripts/' . self::SCRIPT_ENTRY;

		return is_readable( $asset_path . '.asset.php' ) || is_readable( $asset_path . '.min.asset.php' );
	}

	/**
	 * Register the confirmation script.
	 *
	 * The jQuery dependency is declared explicitly even though the bundle never imports it. The order status
	 * control is a `wc-enhanced-select`, and selectWoo announces a selection by triggering
	 * jQuery's own `change` event, which never reaches a listener bound with `addEventListener`.
	 * The script therefore binds through jQuery when it is present, and the dependency
	 * extraction plugin cannot infer that from an import that does not exist. Without this,
	 * load order is merely conventional, and the failure mode is silent: the confirmation
	 * simply never opens and a refund quietly becomes local-only.
	 */
	private function register_admin_script(): void {
		if ( null !== $this->asset_registrar ) {
			call_user_func( $this->asset_registrar, self::SCRIPT_ENTRY );
			return;
		}

		WCAdminAssets::register_script( 'wp-admin-scripts', self::SCRIPT_ENTRY, true, array( 'jquery' ) );
	}
}
