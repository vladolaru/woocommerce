<?php
/**
 * WooPaymentsOrderCardInfo class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Provides the card info core's PaymentInfo shows for orders paid with WooPayments.
 *
 * Client 11.1.0 `class-wc-payments-payment-method-service.php:55-123`: the stored payment method details are read
 * first, so rendering a paid order (thank-you page, order view, emails) needs no platform call; the payment method is
 * fetched, and stored, only when they are missing. Without it, core's PaymentInfo fallback fetched on the first render
 * of every natively paid order. It registers on every request, as the client does, and resolves the details service
 * only when an order renders without stored details.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderCardInfo implements RegisterHooksInterface {

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
	 * Register the card info filter when native owns the runtime.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'wc_order_payment_card_info', array( $this, 'handle_order_payment_card_info' ) ) ) {
			add_filter( 'wc_order_payment_card_info', array( $this, 'handle_order_payment_card_info' ), 10, 2 );
		}
	}

	/**
	 * Provide the card brand, last 4 digits and, for a terminal card, its receipt details and network icon for an order.
	 *
	 * @param mixed $card_info Card info from earlier callbacks.
	 * @param mixed $order     Order.
	 * @return mixed
	 */
	public function handle_order_payment_card_info( $card_info, $order ) {
		if ( ! $order instanceof WC_Order || OrderPaymentStore::GATEWAY_ID !== $order->get_payment_method() ) {
			return $card_info;
		}

		$details = json_decode( (string) $order->get_meta( '_wcpay_payment_method_details', true ), true );
		if ( ! is_array( $details ) || array() === $details ) {
			$payment_method_id = (string) $order->get_meta( '_payment_method_id', true );
			if ( '' === $payment_method_id ) {
				return $card_info;
			}

			$details = wc_get_container()->get( WooPaymentsPaymentMethodDetailsService::class )->get_payment_method_details( $payment_method_id );
			if ( array() === $details ) {
				return $card_info;
			}

			$encoded_details = wp_json_encode( $details );
			if ( false !== $encoded_details ) {
				$order->update_meta_data( '_wcpay_payment_method_details', $encoded_details );
				$order->save_meta_data();
			}
		}

		$type = is_string( $details['type'] ?? null ) ? $details['type'] : '';
		if ( '' === $type || ! is_array( $details[ $type ] ?? null ) ) {
			return array();
		}

		$card = $details[ $type ];
		if ( 'card_present' === $type || 'interac_present' === $type ) {
			$brand = WooPaymentsTerminalCardFormatter::get_terminal_card_display_brand( $card );
			$info  = array(
				'brand'        => $brand,
				'last4'        => (string) ( $card['last4'] ?? '' ),
				'account_type' => (string) ( $card['receipt']['account_type'] ?? '' ),
				'aid'          => (string) ( $card['receipt']['dedicated_file_name'] ?? '' ),
				'app_name'     => (string) ( $card['receipt']['application_preferred_name'] ?? '' ),
			);
			$icon  = self::get_terminal_card_brand_icon( $brand );
			if ( '' !== $icon ) {
				$info['icon'] = $icon;
			}
		} else {
			$info = array(
				'brand' => (string) ( $card['brand'] ?? '' ),
				'last4' => (string) ( $card['last4'] ?? '' ),
			);
		}

		return array_map( 'sanitize_text_field', $info );
	}

	/**
	 * Get the base64 SVG of a terminal card network that has its own icon, or '' for the others.
	 *
	 * Client 11.1.0 `WC_Payments_Utils::get_terminal_card_brand_icon_base64()` reads its `assets/images/cards/<name>.svg`;
	 * core ships the same files as `assets/images/payment-methods/<name>-color.svg`.
	 *
	 * @param string $brand Displayed card brand or network.
	 * @return string
	 */
	private static function get_terminal_card_brand_icon( string $brand ): string {
		$asset_name = WooPaymentsTerminalCardFormatter::get_terminal_card_brand_asset_name( $brand );
		if ( '' === $asset_name ) {
			return '';
		}

		$asset_path = WC()->plugin_path() . "/assets/images/payment-methods/{$asset_name}-color.svg";
		if ( ! file_exists( $asset_path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Core's PaymentInfo takes the icon as a base64 SVG.
		return base64_encode( (string) file_get_contents( $asset_path ) );
	}
}
