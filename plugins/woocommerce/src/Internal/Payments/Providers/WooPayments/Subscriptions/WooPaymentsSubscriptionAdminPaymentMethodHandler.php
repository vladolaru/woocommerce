<?php
/**
 * WooPaymentsSubscriptionAdminPaymentMethodHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Tokens;

/**
 * Handles WooCommerce Subscriptions admin payment-method metadata for native WooPayments.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSubscriptionAdminPaymentMethodHandler {

	/**
	 * WCS custom payment-meta table for order tokens.
	 */
	private const PAYMENT_METHOD_META_TABLE = 'wc_order_tokens';

	/**
	 * WCS custom payment-meta key for the selected token.
	 */
	private const PAYMENT_METHOD_META_KEY = 'token';

	/**
	 * Singleton instance used by gateway instances to avoid duplicate hook identities.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the shared handler instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register native WooPayments subscription admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 3 );
		add_action( 'wcs_save_other_payment_meta', array( $this, 'save_meta_in_order_tokens' ), 10, 4 );
		add_filter( 'wcs_copy_payment_meta_to_order', array( $this, 'append_payment_meta' ), 10, 3 );
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );
		add_filter( 'woocommerce_subscription_payment_method_to_display', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );
		add_filter( 'wcs_view_subscription_actions', array( $this, 'maybe_hide_change_payment_for_manual_subscriptions' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'maybe_hide_auto_renew_toggle_for_manual_subscriptions' ), 100, 3 );
		add_filter( 'woocommerce_subscription_note_old_payment_method_title', array( $this, 'get_specific_old_payment_method_title' ), 10, 3 );
		add_filter( 'woocommerce_subscription_note_new_payment_method_title', array( $this, 'get_specific_new_payment_method_title' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'add_payment_method_select_to_subscription_edit' ) );
		add_filter( 'woocommerce_subscriptions_update_subscription_token', array( $this, 'update_subscription_token' ), 10, 3 );
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( $this, 'update_payment_method_for_subscriptions' ), 10, 3 );
		add_action( 'wp_ajax_wcpay_get_user_payment_tokens', array( $this, 'ajax_get_user_payment_tokens' ) );
	}

	/**
	 * Add native WooPayments payment metadata to the WCS admin payment-method form.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $payment_meta Payment metadata.
	 * @param mixed               $subscription Subscription object.
	 * @return array<string,mixed>
	 */
	public function add_subscription_payment_meta( array $payment_meta, $subscription ): array {
		if ( ! $subscription instanceof WC_Order ) {
			return $payment_meta;
		}

		$payment_meta[ OrderPaymentStore::GATEWAY_ID ] = $this->get_payment_meta( $subscription );
		add_action(
			sprintf(
				'woocommerce_subscription_payment_meta_input_%s_%s_%s',
				OrderPaymentStore::GATEWAY_ID,
				self::PAYMENT_METHOD_META_TABLE,
				self::PAYMENT_METHOD_META_KEY
			),
			array( $this, 'render_custom_payment_meta_input' ),
			10,
			3
		);

		return $payment_meta;
	}

	/**
	 * Validate WCS admin payment metadata for native WooPayments.
	 *
	 * @internal
	 *
	 * @param string              $payment_gateway_id Payment gateway ID.
	 * @param array<string,mixed> $payment_meta       Payment metadata.
	 * @param mixed               $subscription       Subscription object.
	 * @return void
	 */
	public function validate_subscription_payment_meta( string $payment_gateway_id, array $payment_meta, $subscription ): void {
		if ( OrderPaymentStore::GATEWAY_ID !== $payment_gateway_id ) {
			return;
		}

		if ( ! $subscription instanceof WC_Order ) {
			throw new \InvalidArgumentException( __( 'A valid WooPayments subscription was not provided.', 'woocommerce' ) );
		}

		$token_id = $this->get_submitted_token_id_from_payment_meta( $payment_meta );
		if ( '' === $token_id ) {
			throw new \InvalidArgumentException( __( 'A valid WooPayments saved payment method must be selected for this subscription.', 'woocommerce' ) );
		}

		$token = WC_Payment_Tokens::get( absint( $token_id ) );
		if ( ! $this->is_valid_subscription_token( $token, $subscription ) ) {
			throw new \InvalidArgumentException( __( 'A valid WooPayments saved payment method must be selected for this subscription.', 'woocommerce' ) );
		}
	}

	/**
	 * Save WCS custom order-token payment metadata.
	 *
	 * @internal
	 *
	 * @param mixed  $subscription Subscription object.
	 * @param string $table        Metadata table.
	 * @param string $meta_key     Metadata key.
	 * @param string $meta_value   Metadata value.
	 * @return void
	 */
	public function save_meta_in_order_tokens( $subscription, string $table, string $meta_key, string $meta_value ): void {
		if ( self::PAYMENT_METHOD_META_TABLE !== $table || self::PAYMENT_METHOD_META_KEY !== $meta_key || ! $subscription instanceof WC_Order ) {
			return;
		}

		$token = WC_Payment_Tokens::get( absint( $meta_value ) );
		if ( ! $this->is_valid_subscription_token( $token, $subscription ) ) {
			return;
		}

		if ( ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$this->apply_token_to_subscription( $subscription, $token );
	}

	/**
	 * Append native WooPayments payment metadata when WCS copies subscription meta to an order.
	 *
	 * @internal
	 *
	 * @param mixed $payment_meta Payment metadata.
	 * @param mixed $order        Order object.
	 * @param mixed $subscription Subscription object.
	 * @return mixed
	 */
	public function append_payment_meta( $payment_meta, $order, $subscription ) {
		if (
			! is_array( $payment_meta ) ||
			! $order instanceof WC_Order ||
			! $subscription instanceof WC_Order ||
			OrderPaymentStore::GATEWAY_ID !== $order->get_payment_method() ||
			OrderPaymentStore::GATEWAY_ID !== $subscription->get_payment_method()
		) {
			return $payment_meta;
		}

		return array_merge( $payment_meta, $this->get_payment_meta( $subscription ) );
	}

	/**
	 * Render the saved token display name for native WooPayments subscriptions.
	 *
	 * @internal
	 *
	 * @param string $payment_method_to_display Default payment method display.
	 * @param mixed  $subscription              Subscription object.
	 * @return string
	 */
	public function maybe_render_subscription_payment_method( string $payment_method_to_display, $subscription ): string {
		if ( ! $this->should_handle_subscription( $subscription ) ) {
			return $payment_method_to_display;
		}

		$token = $this->get_payment_token( $subscription );

		return $token instanceof WC_Payment_Token ? $token->get_display_name() : $payment_method_to_display;
	}

	/**
	 * Hide change-payment actions for preserved manual subscriptions with non-reusable WooPayments methods.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $actions      Subscription actions.
	 * @param mixed               $subscription Subscription object.
	 * @return array<string,mixed>
	 */
	public function maybe_hide_change_payment_for_manual_subscriptions( array $actions, $subscription ): array {
		if ( $this->has_manual_non_reusable_original_method( $subscription ) ) {
			unset( $actions['change_payment_method'] );
		}

		return $actions;
	}

	/**
	 * Hide the auto-renew toggle for preserved manual subscriptions with non-reusable WooPayments methods.
	 *
	 * @internal
	 *
	 * @param array<string,bool> $allcaps All capabilities.
	 * @param array<int,string>  $caps    Checked primitive capabilities.
	 * @param array<int,mixed>   $args    Capability arguments.
	 * @return array<string,bool>
	 */
	public function maybe_hide_auto_renew_toggle_for_manual_subscriptions( array $allcaps, array $caps, array $args ): array {
		if ( ! isset( $caps[0] ) || 'toggle_shop_subscription_auto_renewal' !== $caps[0] || ! isset( $args[2] ) ) {
			return $allcaps;
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( absint( $args[2] ) ) : null;
		if ( $this->has_manual_non_reusable_original_method( $subscription ) ) {
			unset( $allcaps['toggle_shop_subscription_auto_renewal'] );
		}

		return $allcaps;
	}

	/**
	 * Enqueue the native WooPayments subscription-edit selector script.
	 *
	 * @internal
	 *
	 * @param mixed $order Order object.
	 * @return void
	 */
	public function add_payment_method_select_to_subscription_edit( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $order ) ) {
			return;
		}

		$script_path = WC()->plugin_url() . '/assets/js/admin/woopayments-subscription-edit.js';
		wp_enqueue_script(
			'woocommerce_woopayments_subscription_edit',
			$script_path,
			array( 'jquery' ),
			WC()->version,
			true
		);
	}

	/**
	 * Update a subscription with a new native WooPayments token.
	 *
	 * @internal
	 *
	 * @param bool             $updated      Whether WCS already updated the token.
	 * @param mixed            $subscription Subscription object.
	 * @param WC_Payment_Token $new_token    New token.
	 * @return bool
	 */
	public function update_subscription_token( bool $updated, $subscription, WC_Payment_Token $new_token ): bool {
		if ( OrderPaymentStore::GATEWAY_ID !== $new_token->get_gateway_id() || ! $subscription instanceof WC_Order ) {
			return $updated;
		}

		$subscription->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$this->apply_token_to_subscription( $subscription, $new_token );

		return true;
	}

	/**
	 * Tell WCS customer payment-method changes whether native WooPayments can use the submitted method.
	 *
	 * @internal
	 *
	 * @param bool   $update_payment_method Whether WCS should update payment method.
	 * @param string $new_payment_method    New payment method ID.
	 * @param mixed  $subscription          Subscription object.
	 * @return bool
	 */
	public function update_payment_method_for_subscriptions( bool $update_payment_method, string $new_payment_method, $subscription ): bool {
		if ( OrderPaymentStore::GATEWAY_ID !== $new_payment_method || ! $subscription instanceof WC_Order ) {
			return $update_payment_method;
		}

		if ( ! $this->is_wcs_change_payment_method_request( $subscription ) ) {
			return $update_payment_method;
		}

		$token_key = 'wc-' . OrderPaymentStore::GATEWAY_ID . '-payment-token';
		if ( isset( $_POST[ $token_key ] ) && 'new' !== sanitize_text_field( wp_unslash( $_POST[ $token_key ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $update_payment_method;
		}

		return false;
	}

	/**
	 * Get the old payment-method note title for native WooPayments subscriptions.
	 *
	 * @internal
	 *
	 * @param string $old_payment_method_title Old payment-method title.
	 * @param string $old_payment_method       Old payment-method ID.
	 * @param mixed  $subscription             Subscription object.
	 * @return string
	 */
	public function get_specific_old_payment_method_title( string $old_payment_method_title, string $old_payment_method, $subscription ): string {
		if ( ! $this->is_native_woopayments_gateway_id( $old_payment_method ) || ! $subscription instanceof WC_Order ) {
			return $old_payment_method_title;
		}

		$token_ids = array_values( array_map( 'absint', $subscription->get_payment_tokens() ) );
		if ( 2 > count( $token_ids ) ) {
			return $old_payment_method_title;
		}

		$token = WC_Payment_Tokens::get( $token_ids[ count( $token_ids ) - 2 ] );

		return $this->get_payment_method_title_from_token( $token, $old_payment_method_title );
	}

	/**
	 * Get the new payment-method note title for native WooPayments subscriptions.
	 *
	 * @internal
	 *
	 * @param string $new_payment_method_title New payment-method title.
	 * @param string $new_payment_method       New payment-method ID.
	 * @param mixed  $subscription             Subscription object.
	 * @return string
	 */
	public function get_specific_new_payment_method_title( string $new_payment_method_title, string $new_payment_method, $subscription ): string {
		if ( ! $this->is_native_woopayments_gateway_id( $new_payment_method ) || ! $subscription instanceof WC_Order ) {
			return $new_payment_method_title;
		}

		return $this->get_payment_method_title_from_token( $this->get_payment_token( $subscription ), $new_payment_method_title );
	}

	/**
	 * AJAX handler for the subscription-edit payment token selector.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function ajax_get_user_payment_tokens(): void {
		check_ajax_referer( 'wcpay-subscription-edit', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'woocommerce' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 0 >= $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_success( array( 'tokens' => array() ) );
		}

		wp_send_json_success( array( 'tokens' => $this->get_user_formatted_tokens_array( $user_id ) ) );
	}

	/**
	 * Render a token selector for WCS admin payment metadata.
	 *
	 * @internal
	 *
	 * @param mixed  $subscription Subscription object.
	 * @param string $field_id     Field ID.
	 * @param string $field_value  Field value.
	 * @return void
	 */
	public function render_custom_payment_meta_input( $subscription, string $field_id, string $field_value ): void {
		$field_value = ctype_digit( $field_value ) ? absint( $field_value ) : 0;
		$user_id     = $subscription instanceof WC_Order ? $subscription->get_user_id() : 0;
		$options     = array();
		$selected    = 0;
		$disabled    = false;
		$tokens      = 0 < $user_id ? $this->get_user_formatted_tokens_array( $user_id ) : array();

		foreach ( $tokens as $token ) {
			$token_id             = (int) $token['tokenId'];
			$options[ $token_id ] = (string) $token['displayName'];
			if ( $field_value === $token_id || ( 0 === $field_value && ! empty( $token['isDefault'] ) ) ) {
				$selected = $token_id;
			}
		}

		if ( empty( $options ) ) {
			$options[0] = 0 < $user_id ? __( 'No payment methods found for customer', 'woocommerce' ) : __( 'Please select a customer first', 'woocommerce' );
			$disabled   = true;
		}

		$prepared_data = array(
			'value'                 => $field_value,
			'userId'                => $user_id,
			'tokens'                => $tokens,
			'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'wcpay-subscription-edit' ),
			'gatewayId'             => OrderPaymentStore::GATEWAY_ID,
			'noPaymentMethodsLabel' => __( 'No payment methods found for customer', 'woocommerce' ),
		);
		$selector_data = wp_json_encode( $prepared_data );
		$selector_data = false === $selector_data ? '' : $selector_data;
		?>
		<span class="wcpay-subscription-payment-method" data-wcpay-pm-selector="<?php echo esc_attr( $selector_data ); ?>">
			<select name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>">
				<?php if ( 0 !== $field_value && $field_value !== $selected ) : ?>
					<option value="" selected disabled><?php esc_html_e( 'Please select a payment method', 'woocommerce' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $options as $token_id => $display_name ) : ?>
					<option value="<?php echo esc_attr( (string) $token_id ); ?>" <?php selected( $token_id, $selected ); ?> <?php disabled( $disabled ); ?>>
						<?php echo esc_html( $display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</span>
		<?php
	}

	/**
	 * Build the WCS payment metadata for native WooPayments.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return array<string,array<string,array<string,string>>>
	 */
	private function get_payment_meta( WC_Order $subscription ): array {
		$active_token = $this->get_payment_token( $subscription );

		return array(
			self::PAYMENT_METHOD_META_TABLE => array(
				self::PAYMENT_METHOD_META_KEY => array(
					'label' => __( 'Saved payment method', 'woocommerce' ),
					'value' => $active_token instanceof WC_Payment_Token ? (string) $active_token->get_id() : '',
				),
			),
		);
	}

	/**
	 * Get the active native WooPayments payment token from a subscription/order.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Payment_Token|null
	 */
	private function get_payment_token( WC_Order $order ): ?WC_Payment_Token {
		$token_ids = array_map( 'absint', $order->get_payment_tokens() );
		foreach ( array_reverse( $token_ids ) as $token_id ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( $token instanceof WC_Payment_Token && OrderPaymentStore::GATEWAY_ID === $token->get_gateway_id() ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Get the submitted WCS token ID from payment metadata.
	 *
	 * @param array<string,mixed> $payment_meta Payment metadata.
	 * @return string
	 */
	private function get_submitted_token_id_from_payment_meta( array $payment_meta ): string {
		$token_meta = $payment_meta[ self::PAYMENT_METHOD_META_TABLE ][ self::PAYMENT_METHOD_META_KEY ]['value'] ?? '';

		return is_scalar( $token_meta ) ? (string) $token_meta : '';
	}

	/**
	 * Tell whether a token is a native WooPayments token owned by the subscription customer.
	 *
	 * @param mixed    $token        Token object.
	 * @param WC_Order $subscription Subscription object.
	 * @return bool
	 */
	private function is_valid_subscription_token( $token, WC_Order $subscription ): bool {
		return $token instanceof WC_Payment_Token
			&& OrderPaymentStore::GATEWAY_ID === $token->get_gateway_id()
			&& (int) $subscription->get_user_id() === (int) $token->get_user_id();
	}

	/**
	 * Tell whether native WooPayments should handle a subscription object.
	 *
	 * @param mixed $subscription Subscription object.
	 * @return bool
	 */
	private function should_handle_subscription( $subscription ): bool {
		return $subscription instanceof WC_Order && $this->is_native_woopayments_gateway_id( $subscription->get_payment_method() );
	}

	/**
	 * Tell whether a gateway ID belongs to native WooPayments.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	private function is_native_woopayments_gateway_id( string $gateway_id ): bool {
		return OrderPaymentStore::GATEWAY_ID === $gateway_id || 0 === strpos( $gateway_id, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}

	/**
	 * Tell whether a subscription is manual because it preserves an original non-reusable WooPayments method.
	 *
	 * @param mixed $subscription Subscription object.
	 * @return bool
	 */
	private function has_manual_non_reusable_original_method( $subscription ): bool {
		if ( ! $subscription instanceof WC_Order || ! is_callable( array( $subscription, 'is_manual' ) ) ) {
			return false;
		}

		if ( ! (bool) $subscription->is_manual() ) {
			return false;
		}

		return '' !== (string) $subscription->get_meta( '_wcpay_original_payment_method_id', true );
	}

	/**
	 * Tell whether the current request is a WC Subscriptions customer change-payment request.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return bool
	 */
	private function is_wcs_change_payment_method_request( WC_Order $subscription ): bool {
		if ( ! isset( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_change_payment_method' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$subscription_id = $subscription->get_id();
		$request_id      = 0;

		if ( isset( $_POST['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$request_id = absint( $_POST['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request_id = absint( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return 0 === $request_id || $subscription_id === $request_id;
	}

	/**
	 * Get a payment-method title from a WooCommerce token when possible.
	 *
	 * @param mixed  $token    Token object.
	 * @param string $fallback Fallback title.
	 * @return string
	 */
	private function get_payment_method_title_from_token( $token, string $fallback ): string {
		if ( ! $token instanceof WC_Payment_Token || ! $this->is_native_woopayments_gateway_id( $token->get_gateway_id() ) ) {
			return $fallback;
		}

		return $token->get_display_name();
	}

	/**
	 * Apply a native WooPayments token to a subscription/order and keep provider metadata coherent.
	 *
	 * @param WC_Order         $subscription Subscription object.
	 * @param WC_Payment_Token $token        Token object.
	 * @return void
	 */
	private function apply_token_to_subscription( WC_Order $subscription, WC_Payment_Token $token ): void {
		$token_ids = array_map( 'absint', $subscription->get_payment_tokens() );
		if ( ! in_array( $token->get_id(), $token_ids, true ) ) {
			$subscription->add_payment_token( $token );
		}

		$subscription->update_meta_data( '_payment_method_id', (string) $token->get_token() );

		if ( '' === (string) $subscription->get_meta( '_stripe_customer_id', true ) ) {
			$customer_id = $this->get_customer_id_from_token( $token );
			if ( '' !== $customer_id ) {
				$subscription->update_meta_data( '_stripe_customer_id', $customer_id );
			}
		}

		$subscription->save();
	}

	/**
	 * Get a provider customer ID from token metadata when available.
	 *
	 * @param WC_Payment_Token $token Token object.
	 * @return string
	 */
	private function get_customer_id_from_token( WC_Payment_Token $token ): string {
		foreach ( array( '_stripe_customer_id', 'customer_id' ) as $key ) {
			$value = $token->get_meta( $key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Get formatted native WooPayments tokens for the admin selector.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_user_formatted_tokens_array( int $user_id ): array {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, OrderPaymentStore::GATEWAY_ID );
		$formatted_tokens = array();

		foreach ( $tokens as $token ) {
			$formatted_tokens[] = array(
				'tokenId'     => $token->get_id(),
				'displayName' => $token->get_display_name(),
				'isDefault'   => $token->is_default(),
			);
		}

		return $formatted_tokens;
	}
}
