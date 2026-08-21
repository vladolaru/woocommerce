<?php
/**
 * WooPaymentsOrderSuccessPage class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Native WooPayments order-success page callbacks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderSuccessPage implements RegisterHooksInterface {

	private const ASSET_HANDLE = 'wc-woopayments-order-success';

	private const MULTIBANCO_REFERENCE_META_KEY = '_wcpay_multibanco_reference';
	private const MULTIBANCO_ENTITY_META_KEY    = '_wcpay_multibanco_entity';
	private const MULTIBANCO_EXPIRY_META_KEY    = '_wcpay_multibanco_expiry';
	private const MULTIBANCO_URL_META_KEY       = '_wcpay_multibanco_url';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Payment-method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Frontend tracking controller.
	 *
	 * @var WooPaymentsFrontendTrackingController|null
	 */
	private ?WooPaymentsFrontendTrackingController $frontend_tracking_controller = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter               $arbiter                 Runtime owner arbiter.
	 * @param WooPaymentsPaymentMethodRegistry           $payment_method_registry Payment-method definition registry.
	 * @param WooPaymentsAccountService                  $account_service              WooPayments account service.
	 * @param WooPaymentsFrontendTrackingController|null $frontend_tracking_controller Optional frontend tracking controller.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsPaymentMethodRegistry $payment_method_registry, WooPaymentsAccountService $account_service, ?WooPaymentsFrontendTrackingController $frontend_tracking_controller = null ): void {
		$this->arbiter                      = $arbiter;
		$this->payment_method_registry      = $payment_method_registry;
		$this->account_service              = $account_service;
		$this->frontend_tracking_controller = $frontend_tracking_controller;
	}

	/**
	 * Register order-success hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_before_thankyou', array( $this, 'register_payment_method_title_override' ) ) ) {
			add_action( 'woocommerce_before_thankyou', array( $this, 'register_payment_method_title_override' ) );
		}

		if ( false === has_action( 'woocommerce_thankyou', array( $this, 'record_order_success_page_view' ) ) ) {
			add_action( 'woocommerce_thankyou', array( $this, 'record_order_success_page_view' ) );
		}

		if ( false === has_action( 'woocommerce_before_thankyou', array( $this, 'maybe_render_multibanco_payment_instructions' ) ) ) {
			add_action( 'woocommerce_before_thankyou', array( $this, 'maybe_render_multibanco_payment_instructions' ) );
		}

		if ( false === has_action( 'woocommerce_order_details_before_order_table', array( $this, 'unregister_payment_method_title_override' ) ) ) {
			add_action( 'woocommerce_order_details_before_order_table', array( $this, 'unregister_payment_method_title_override' ) );
		}

		if ( false === has_action( 'woocommerce_order_details_before_order_table', array( $this, 'maybe_render_multibanco_payment_instructions' ) ) ) {
			add_action( 'woocommerce_order_details_before_order_table', array( $this, 'maybe_render_multibanco_payment_instructions' ) );
		}

		if ( false === has_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		// Priority 11 so the notices append after the store's own order-received text
		// customizations, matching the WooPayments extension. The same filter renders on
		// the classic thank-you template and the blocks order-confirmation page.
		if ( false === has_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'add_notice_previous_paid_order' ) ) ) {
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'add_notice_previous_paid_order' ), 11 );
		}

		if ( false === has_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'add_notice_previous_successful_intent' ) ) ) {
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'add_notice_previous_successful_intent' ), 11 );
		}
	}

	/**
	 * Tell the shopper a duplicate-order payment was prevented.
	 *
	 * The duplicate-payment guard redirects here with a flag instead of charging a second
	 * time; without this notice the shopper lands on a plain thank-you page and never
	 * learns why their new order disappeared.
	 *
	 * @internal
	 *
	 * @param string $text Default thank-you text.
	 * @return string
	 */
	public function add_notice_previous_paid_order( $text ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag appended by the duplicate-payment redirect.
		if ( isset( $_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_ORDER_PAID ] ) ) {
			$text .= sprintf(
				'<div class="woocommerce-info">%s</div>',
				esc_html__( 'We detected and prevented an attempt to pay for a duplicate order. If this was a mistake and you wish to try again, please create a new order.', 'woocommerce' )
			);
		}

		return $text;
	}

	/**
	 * Tell the shopper a second payment for this order was prevented.
	 *
	 * @internal
	 *
	 * @param string $text Default thank-you text.
	 * @return string
	 */
	public function add_notice_previous_successful_intent( $text ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag appended by the duplicate-payment redirect.
		if ( isset( $_GET[ WooPaymentsDuplicatePaymentPreventionService::FLAG_PREVIOUS_SUCCESSFUL_INTENT ] ) ) {
			$text .= sprintf(
				'<div class="woocommerce-info">%s</div>',
				esc_html__( 'We prevented multiple payments for the same order. If this was a mistake and you wish to try again, please create a new order.', 'woocommerce' )
			);
		}

		return $text;
	}

	/**
	 * Record the order-success page view for the canonical WooPayments gateway.
	 *
	 * @internal
	 * @param int $order_id Order ID.
	 */
	public function record_order_success_page_view( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || OrderPaymentStore::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		try {
			$this->get_frontend_tracking_controller()->record_user_event(
				'order_success_page_view',
				array( 'record_event_data' => array( 'track_on_all_stores' => true ) )
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt the order-success page.
		}
	}

	/**
	 * Register the temporary payment-method title override used by the thank-you summary.
	 *
	 * @internal
	 */
	public function register_payment_method_title_override(): void {
		add_filter( 'woocommerce_order_get_payment_method_title', array( $this, 'filter_payment_method_title' ), 10, 2 );
	}

	/**
	 * Remove the thank-you payment-method title override before later order details render.
	 *
	 * @internal
	 */
	public function unregister_payment_method_title_override(): void {
		remove_filter( 'woocommerce_order_get_payment_method_title', array( $this, 'filter_payment_method_title' ), 10 );
	}

	/**
	 * Render a WooPayments-branded payment method title on the order-received page.
	 *
	 * @internal
	 *
	 * @param string|mixed   $payment_method_title Stored payment method title.
	 * @param WC_Order|mixed $order                Order being rendered.
	 * @return string|mixed
	 */
	public function filter_payment_method_title( $payment_method_title, $order ) {
		if ( ! is_order_received_page() || ! $order instanceof WC_Order ) {
			return $payment_method_title;
		}

		$gateway_id = (string) $order->get_payment_method();
		if ( OrderPaymentStore::GATEWAY_ID !== $gateway_id && 0 !== strpos( $gateway_id, OrderPaymentStore::GATEWAY_ID_PREFIX ) ) {
			return $payment_method_title;
		}

		if ( $order->get_meta( 'is_woopay', true ) ) {
			return $this->render_woopay_title( $order );
		}

		$express_method = (string) $order->get_meta( '_wcpay_express_checkout_payment_method', true );
		if ( '' !== $express_method ) {
			$definition = $this->payment_method_registry->get( $express_method );
			return null === $definition ? $payment_method_title : $this->render_definition_title( $definition, $order, true );
		}

		$payment_method_id = OrderPaymentStore::GATEWAY_ID === $gateway_id
			? 'card'
			: substr( $gateway_id, strlen( OrderPaymentStore::GATEWAY_ID_PREFIX ) );
		if ( 'card' === $payment_method_id ) {
			return $this->render_card_title( $order, $payment_method_title );
		}

		$definition = $this->payment_method_registry->get( $payment_method_id );
		return null === $definition ? $payment_method_title : $this->render_definition_title( $definition, $order );
	}

	/**
	 * Enqueue order-success assets on customer order pages.
	 *
	 * @internal
	 */
	public function enqueue_assets(): void {
		if ( ! is_order_received_page() && ! is_view_order_page() ) {
			return;
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		WooPaymentsFrontendAssets::register_appearance_script();

		wp_enqueue_style(
			self::ASSET_HANDLE,
			WC()->plugin_url() . '/assets/css/woopayments-order-success.css',
			array(),
			WC_VERSION
		);
		wp_style_add_data( self::ASSET_HANDLE, 'rtl', 'replace' );

		wp_enqueue_script(
			self::ASSET_HANDLE,
			WC()->plugin_url() . '/assets/js/frontend/woopayments-order-success' . $suffix . '.js',
			array( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE ),
			WC_VERSION,
			true
		);
		wp_localize_script(
			self::ASSET_HANDLE,
			'wc_woopayments_order_success_params',
			array(
				'copied'     => __( 'Copied to clipboard.', 'woocommerce' ),
				'copyFailed' => __( 'Copy failed. Copy the value manually.', 'woocommerce' ),
			)
		);
	}

	/**
	 * Maybe render Multibanco payment instructions.
	 *
	 * @internal
	 *
	 * @param int|WC_Order $order_or_id The order or order ID.
	 */
	public function maybe_render_multibanco_payment_instructions( $order_or_id ): void {
		if ( is_order_received_page() && 'woocommerce_order_details_before_order_table' === current_filter() ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if (
			! $order instanceof WC_Order
			|| OrderPaymentStore::GATEWAY_ID_PREFIX . 'multibanco' !== $order->get_payment_method()
			|| 'on-hold' !== $order->get_status()
		) {
			return;
		}

		$multibanco_info = $this->get_multibanco_info_from_order( $order );
		if ( ! $this->has_complete_multibanco_info( $multibanco_info ) ) {
			return;
		}

		$expiry_timestamp      = (int) $multibanco_info['expiry'];
		$expiry_date           = date_i18n( wc_date_format() . ' ' . wc_time_format(), $expiry_timestamp );
		$days_remaining        = max( 0, (int) floor( ( $expiry_timestamp - time() ) / DAY_IN_SECONDS ) );
		$formatted_order_total = wp_strip_all_tags( $order->get_formatted_order_total() );
		$multibanco_icon_url   = WC()->plugin_url() . '/assets/images/payment-methods/multibanco-instructions.svg';

		wc_print_notice(
			__( 'Your order is on hold until payment is received. Please follow the payment instructions by the expiry date.', 'woocommerce' ),
			'notice'
		);
		?>
		<div id="wc-payment-gateway-multibanco-instructions-container">
			<div class="card">
				<div class="card-header">
					<div class="logo-container">
						<img src="<?php echo esc_url( $multibanco_icon_url ); ?>" alt="<?php esc_attr_e( 'Multibanco', 'woocommerce' ); ?>">
					</div>
					<div class="payment-details">
						<div class="payment-header">
							<?php
							printf(
								/* translators: %s: order number. */
								esc_html__( 'Order #%s', 'woocommerce' ),
								esc_html( $order->get_order_number() )
							);
							?>
						</div>
						<div class="payment-expiry">
							<?php
							printf(
								wp_kses(
									/* translators: %s: expiry date. */
									__( 'Expires <strong>%s</strong>', 'woocommerce' ),
									array(
										'strong' => array(),
									)
								),
								esc_html( $expiry_date )
							);
							?>
							<span class="badge">
								<?php
								printf(
									/* translators: %d: number of days. */
									esc_html( _n( '%d day', '%d days', $days_remaining, 'woocommerce' ) ),
									esc_html( (string) $days_remaining )
								);
								?>
							</span>
						</div>
					</div>
				</div>

				<div class="payment-instructions">
					<p><strong><?php esc_html_e( 'Payment instructions', 'woocommerce' ); ?></strong></p>
					<ol>
						<li><?php esc_html_e( 'In your online bank account or from an ATM, choose "Payment and other services".', 'woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Click "Payments of services/shopping".', 'woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Enter the entity number, reference number, and amount.', 'woocommerce' ); ?></li>
					</ol>
				</div>

				<div class="payment-box">
					<div class="payment-box-row">
						<span class="payment-box-label"><?php esc_html_e( 'Entity', 'woocommerce' ); ?></span>
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['entity'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Multibanco entity value. */ __( 'Copy entity: %s', 'woocommerce' ), $multibanco_info['entity'] ) ); ?>"><?php echo esc_html( $multibanco_info['entity'] ); ?><i class="copy-icon" aria-hidden="true"></i></button>
					</div>
					<div class="payment-box-row">
						<span class="payment-box-label"><?php esc_html_e( 'Reference', 'woocommerce' ); ?></span>
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['reference'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Multibanco reference value. */ __( 'Copy reference: %s', 'woocommerce' ), $multibanco_info['reference'] ) ); ?>"><?php echo esc_html( $multibanco_info['reference'] ); ?><i class="copy-icon" aria-hidden="true"></i></button>
					</div>
					<div class="payment-box-row">
						<span class="payment-box-label"><?php esc_html_e( 'Amount', 'woocommerce' ); ?></span>
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $formatted_order_total ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Multibanco payment amount. */ __( 'Copy amount: %s', 'woocommerce' ), $formatted_order_total ) ); ?>"><?php echo esc_html( $formatted_order_total ); ?><i class="copy-icon" aria-hidden="true"></i></button>
					</div>
				</div>

				<button type="button" class="button alt print-btn"><?php esc_html_e( 'Print', 'woocommerce' ); ?></button>
				<button type="button" class="button alt copy-link-btn copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['url'] ); ?>"><?php esc_html_e( 'Copy link for sharing', 'woocommerce' ); ?><i class="copy-icon" aria-hidden="true"></i></button>
				<p class="woocommerce-woopayments-copy-status screen-reader-text" role="status" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get Multibanco information from the order.
	 *
	 * @param WC_Order $order The order.
	 * @return array{reference:string,entity:string,url:string,expiry:int}
	 */
	private function get_multibanco_info_from_order( WC_Order $order ): array {
		return array(
			'reference' => (string) $order->get_meta( self::MULTIBANCO_REFERENCE_META_KEY, true ),
			'entity'    => (string) $order->get_meta( self::MULTIBANCO_ENTITY_META_KEY, true ),
			'url'       => (string) $order->get_meta( self::MULTIBANCO_URL_META_KEY, true ),
			'expiry'    => (int) $order->get_meta( self::MULTIBANCO_EXPIRY_META_KEY, true ),
		);
	}

	/**
	 * Check whether the order contains renderable Multibanco information.
	 *
	 * @param array{reference:string,entity:string,url:string,expiry:int} $multibanco_info Multibanco information.
	 * @return bool
	 */
	private function has_complete_multibanco_info( array $multibanco_info ): bool {
		return '' !== $multibanco_info['reference']
			&& '' !== $multibanco_info['entity']
			&& '' !== $multibanco_info['url']
			&& 0 < $multibanco_info['expiry'];
	}

	/**
	 * Render card brand and last-four details.
	 *
	 * @param WC_Order     $order                Order being rendered.
	 * @param string|mixed $payment_method_title Stored payment method title.
	 * @return string|mixed
	 */
	private function render_card_title( WC_Order $order, $payment_method_title ) {
		$card_brand = sanitize_key( (string) $order->get_meta( '_card_brand', true ) );
		if ( '' === $card_brand ) {
			return $payment_method_title;
		}

		$relative_path = 'assets/images/payment-methods-cards/' . $card_brand . '.svg';
		if ( ! is_file( WC_ABSPATH . $relative_path ) ) {
			return $payment_method_title;
		}

		return $this->render_logo_title(
			(string) $payment_method_title,
			WC()->plugin_url() . '/' . $relative_path,
			'',
			'wc-payment-card-logo',
			(string) $order->get_meta( 'last4', true )
		);
	}

	/**
	 * Render a registry-backed payment method title.
	 *
	 * @param WooPaymentsPaymentMethodDefinition $definition     Payment-method definition.
	 * @param WC_Order                           $order          Order being rendered.
	 * @param bool                               $is_express Whether this is an express-wallet title.
	 * @return string
	 */
	private function render_definition_title( WooPaymentsPaymentMethodDefinition $definition, WC_Order $order, bool $is_express = false ): string {
		$account_country = $this->account_service->get_account_country();
		$icon_url        = WC()->plugin_url() . '/' . ltrim( $definition->get_icon_asset_path( $account_country ), '/' );
		$dark_icon_url   = WC()->plugin_url() . '/' . ltrim( $definition->get_dark_icon_asset_path( $account_country ), '/' );

		if ( ! $is_express ) {
			$icon_url = apply_filters_deprecated(
				'wc_payments_thank_you_page_bnpl_payment_method_logo_url',
				array( $icon_url, $definition->get_id() ),
				'8.5.0',
				'wc_payments_thank_you_page_lpm_payment_method_logo_url'
			);

			/**
			 * Filters the payment method logo URL shown on the thank-you page.
			 *
			 * @since 11.0.0
			 *
			 * @param string $icon_url          Payment method logo URL.
			 * @param string $payment_method_id Payment method ID.
			 */
			$icon_url = (string) apply_filters( 'wc_payments_thank_you_page_lpm_payment_method_logo_url', $icon_url, $definition->get_id() );
		}

		if ( '' === $icon_url ) {
			return $definition->get_title( $account_country );
		}

		return $this->render_logo_title(
			$definition->get_title( $account_country ),
			$icon_url,
			$dark_icon_url,
			$is_express ? 'wc-payment-card-logo' : 'wc-payment-lpm-logo wc-payment-lpm-logo--' . sanitize_html_class( $definition->get_id() ),
			$is_express ? (string) $order->get_meta( 'last4', true ) : ''
		);
	}

	/**
	 * Render WooPay branding and optional card digits.
	 *
	 * @param WC_Order $order Order being rendered.
	 * @return string
	 */
	private function render_woopay_title( WC_Order $order ): string {
		return $this->render_logo_title(
			'WooPay',
			WC()->plugin_url() . '/assets/images/payment-methods/woo-short.svg',
			'',
			'woopay',
			(string) $order->get_meta( 'last4', true )
		);
	}

	/**
	 * Render escaped payment-method logo markup.
	 *
	 * @param string $title         Payment method title.
	 * @param string $icon_url      Light icon URL.
	 * @param string $dark_icon_url Dark icon URL.
	 * @param string $classes       Additional wrapper classes.
	 * @param string $last4         Optional last four digits.
	 * @return string
	 */
	private function render_logo_title( string $title, string $icon_url, string $dark_icon_url, string $classes, string $last4 = '' ): string {
		$dark_attribute = '' !== $dark_icon_url && $dark_icon_url !== $icon_url
			? ' data-dark-src="' . esc_url( $dark_icon_url ) . '"'
			: '';
		$last4_markup   = '' === $last4
			? ''
			: sprintf(
				'<span aria-label="%1$s">&bull;&bull;&bull; %2$s</span>',
				esc_attr( sprintf( /* translators: %s: last four card digits. */ __( 'Card ending in %s', 'woocommerce' ), $last4 ) ),
				esc_html( $last4 )
			);

		return sprintf(
			'<span class="wc-payment-gateway-method-logo-wrapper %1$s"><img alt="%2$s" src="%3$s"%4$s>%5$s</span>',
			esc_attr( $classes ),
			esc_attr( $title ),
			esc_url( $icon_url ),
			$dark_attribute, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped attribute composed above.
			$last4_markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is composed from escaped values.
		);
	}

	/**
	 * Get the frontend tracking controller.
	 *
	 * @return WooPaymentsFrontendTrackingController
	 */
	private function get_frontend_tracking_controller(): WooPaymentsFrontendTrackingController {
		if ( null === $this->frontend_tracking_controller ) {
			$this->frontend_tracking_controller = wc_get_container()->get( WooPaymentsFrontendTrackingController::class );
		}

		return $this->frontend_tracking_controller;
	}
}
