<?php
/**
 * WooPaymentsOrderCardInfo class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

/**
 * Provides the card info core's PaymentInfo shows for orders paid with WooPayments.
 *
 * Client 11.1.0 `class-wc-payments-payment-method-service.php:55-123`: the stored payment method details are read
 * first, so rendering a paid order (thank-you page, order view, emails) needs no platform call; the payment method is
 * fetched, and stored, only when they are missing. Without it, core's PaymentInfo fallback fetched on the first render
 * of every order the built-in WooPayments paid. It is registered on every request once WooPayments is connected, as
 * client 11.1.0 registers it on every request (class-wc-payments.php:674-675), and calls the details service only when
 * an order renders without stored details.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsOrderCardInfo implements RegisterHooksInterface {

	/**
	 * Card info answered when the payment method could not be fetched: no card shown, and no second fetch by core.
	 */
	private const NO_CARD_INFO = array(
		'brand' => '',
		'last4' => '',
	);

	/**
	 * Payment method details service.
	 *
	 * @var WooPaymentsPaymentMethodDetailsService
	 */
	private WooPaymentsPaymentMethodDetailsService $payment_method_details_service;

	/**
	 * Failed fetches in this process, keyed by order ID, then by payment method ID.
	 *
	 * It is for one order rendered more than once in a process: a declined WooPay checkout leaves a payment method only the
	 * platform account can read, and both Failed order emails render that order in the same request, so the second render
	 * answers no card info without asking or logging again. Another order with the same payment method asks again.
	 *
	 * @var array<int,array<string,true>>
	 */
	private array $failed_fetches = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsPaymentMethodDetailsService $payment_method_details_service Payment method details service.
	 */
	final public function init( WooPaymentsPaymentMethodDetailsService $payment_method_details_service ): void {
		$this->payment_method_details_service = $payment_method_details_service;
	}

	/**
	 * Register the card info filter.
	 */
	public function register() {
		add_filter( 'wc_order_payment_card_info', array( $this, 'handle_order_payment_card_info' ), 10, 2 );
	}

	/**
	 * Provide the card brand, last 4 digits and, for a terminal card, its receipt details and network icon for an order.
	 *
	 * @param mixed $card_info Card info from earlier callbacks.
	 * @param mixed $order     Order.
	 * @return mixed
	 */
	public function handle_order_payment_card_info( $card_info, $order ) {
		if ( ! $order instanceof WC_Order || WooPaymentsPersistenceVocabulary::GATEWAY_ID !== $order->get_payment_method() ) {
			return $card_info;
		}

		$details = $this->get_stored_details( $order );
		if ( array() === $details && $order->get_id() ) {
			// Another email or confirmation may have stored the card since this object was read.
			$order   = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
			$details = $this->get_stored_details( $order );
		}
		if ( array() === $details ) {
			$payment_method_id = $order->get_meta( '_payment_method_id', true );
			if ( '' === $payment_method_id ) {
				return $card_info;
			}
			$order_id = $order->get_id();
			if ( is_string( $payment_method_id ) && isset( $this->failed_fetches[ $order_id ][ $payment_method_id ] ) ) {
				return self::NO_CARD_INFO;
			}

			// Rendering an order must survive a failed fetch, as core's own fallback does (PaymentInfo::get_wcpay_card_info()),
			// with the same always-on 'payment-info' line for an Exception or a PHP Error, but the failure's
			// class, code and platform status instead of its message. A failure answers with empty card fields, so that
			// fallback does not fetch a second time. It is remembered for this order and payment method in this process only,
			// so a render of the same order skips the fetch and another order with the same payment method asks again.
			// An order with no ID is not remembered, and nothing is stored on the order.
			try {
				// Stated lock exception: rendering a Failed email can fetch the payment method when the failure carried no details.
				$details = $this->payment_method_details_service->fetch_payment_method_details( $payment_method_id );
			} catch ( Throwable $exception ) {
				self::log_fetch_failure( $order, $payment_method_id, $exception );
				if ( 0 !== $order_id && is_string( $payment_method_id ) ) {
					$this->failed_fetches[ $order_id ][ $payment_method_id ] = true;
				}
				$details = array();
			}
			if ( array() === $details ) {
				return self::NO_CARD_INFO;
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
	 * Read the stored card details, ignoring malformed or filtered meta.
	 *
	 * @param WC_Order $order Order being rendered.
	 * @return array<string,mixed>
	 */
	private function get_stored_details( WC_Order $order ): array {
		$stored  = $order->get_meta( '_wcpay_payment_method_details', true );
		$details = is_string( $stored ) ? json_decode( $stored, true ) : null;
		return is_array( $details ) ? $details : array();
	}

	/**
	 * Write the always-on 'payment-info' line for a failed card info fetch; logging never stops the render.
	 *
	 * @param WC_Order  $order             Order being rendered.
	 * @param mixed     $payment_method_id Payment method ID read from the order.
	 * @param Throwable $exception         Fetch failure.
	 */
	private static function log_fetch_failure( WC_Order $order, $payment_method_id, Throwable $exception ): void {
		wc_get_logger()->error(
			sprintf(
				'WooPaymentsOrderCardInfo - retrieving info for payment method %1$s for order %2$d.',
				is_scalar( $payment_method_id ) ? (string) $payment_method_id : gettype( $payment_method_id ),
				$order->get_id()
			),
			array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'payment-info' ) )
		);
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
