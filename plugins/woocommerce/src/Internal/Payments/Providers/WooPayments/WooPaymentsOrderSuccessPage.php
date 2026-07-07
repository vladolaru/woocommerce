<?php
/**
 * WooPaymentsOrderSuccessPage class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Native WooPayments order-success page callbacks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderSuccessPage implements RegisterHooksInterface {

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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register order-success hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_before_thankyou', array( $this, 'maybe_render_multibanco_payment_instructions' ) ) ) {
			add_action( 'woocommerce_before_thankyou', array( $this, 'maybe_render_multibanco_payment_instructions' ) );
		}

		if ( false === has_action( 'woocommerce_order_details_before_order_table', array( $this, 'maybe_render_multibanco_payment_instructions' ) ) ) {
			add_action( 'woocommerce_order_details_before_order_table', array( $this, 'maybe_render_multibanco_payment_instructions' ) );
		}
	}

	/**
	 * Maybe render Multibanco payment instructions.
	 *
	 * @internal
	 *
	 * @param int $order_id The order ID.
	 */
	public function maybe_render_multibanco_payment_instructions( int $order_id ): void {
		if ( is_order_received_page() && 'woocommerce_order_details_before_order_table' === current_filter() ) {
			return;
		}

		$order = wc_get_order( $order_id );
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
		$multibanco_icon_url   = WC()->plugin_url() . '/assets/images/payment-methods/multibanco.svg';

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
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['entity'] ); ?>"><?php echo esc_html( $multibanco_info['entity'] ); ?><i class="copy-icon"></i></button>
					</div>
					<div class="payment-box-row">
						<span class="payment-box-label"><?php esc_html_e( 'Reference', 'woocommerce' ); ?></span>
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['reference'] ); ?>"><?php echo esc_html( $multibanco_info['reference'] ); ?><i class="copy-icon"></i></button>
					</div>
					<div class="payment-box-row">
						<span class="payment-box-label"><?php esc_html_e( 'Amount', 'woocommerce' ); ?></span>
						<button type="button" class="payment-box-value copy-btn" data-copy-value="<?php echo esc_attr( $formatted_order_total ); ?>"><?php echo esc_html( $formatted_order_total ); ?><i class="copy-icon"></i></button>
					</div>
				</div>

				<button type="button" class="button alt print-btn"><?php esc_html_e( 'Print', 'woocommerce' ); ?></button>
				<button type="button" class="button alt copy-link-btn copy-btn" data-copy-value="<?php echo esc_attr( $multibanco_info['url'] ); ?>"><?php esc_html_e( 'Copy link for sharing', 'woocommerce' ); ?><i class="copy-icon"></i></button>
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
}
