<?php
/**
 * WooPaymentsGatewayListController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Shapes WooCommerce's payment gateway list around the WooPayments gateways, as the client does.
 *
 * The settings page lists only the card gateway; the split gateways stay registered for checkout
 * (client 11.1.0 `includes/class-wc-payments.php:742,1024-1031`). All WooPayments gateways keep one
 * block in the gateway order (client 11.1.0 `includes/class-wc-payments.php:731-732,1041-1077`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsGatewayListController implements RegisterHooksInterface {

	/**
	 * Runs before other listeners so they see the list without split gateways, as in the client.
	 */
	private const PAYMENT_GATEWAYS_DISPLAY_HOOK_PRIORITY = 5;

	/**
	 * The client's priorities for the saved and default gateway order filters.
	 */
	private const GATEWAY_ORDER_OPTION_PRIORITY = 2;

	private const GATEWAY_ORDER_DEFAULT_OPTION_PRIORITY = 3;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register the gateway list hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_admin_field_payment_gateways', array( $this, 'handle_woocommerce_admin_field_payment_gateways' ) ) ) {
			add_action(
				'woocommerce_admin_field_payment_gateways',
				array( $this, 'handle_woocommerce_admin_field_payment_gateways' ),
				self::PAYMENT_GATEWAYS_DISPLAY_HOOK_PRIORITY
			);
		}

		if ( false === has_filter( 'option_woocommerce_gateway_order', array( $this, 'handle_gateway_order_option' ) ) ) {
			add_filter( 'option_woocommerce_gateway_order', array( $this, 'handle_gateway_order_option' ), self::GATEWAY_ORDER_OPTION_PRIORITY );
		}

		if ( false === has_filter( 'default_option_woocommerce_gateway_order', array( $this, 'handle_gateway_order_option' ) ) ) {
			add_filter( 'default_option_woocommerce_gateway_order', array( $this, 'handle_gateway_order_option' ), self::GATEWAY_ORDER_DEFAULT_OPTION_PRIORITY );
		}
	}

	/**
	 * Keep only the canonical native WooPayments gateway in settings displays.
	 *
	 * Split gateways remain registered for checkout and payment processing. This callback only
	 * changes the request-local collection after the settings display hook fires.
	 *
	 * @internal
	 */
	public function handle_woocommerce_admin_field_payment_gateways(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		$payment_gateways  = WC()->payment_gateways();
		$gateways          = $payment_gateways->payment_gateways;
		$canonical_gateway = null;

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof WooPaymentsGateway && WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway->id ) {
				$canonical_gateway = $gateway;
				break;
			}
		}

		if ( ! $canonical_gateway instanceof WooPaymentsGateway ) {
			return;
		}

		foreach ( $gateways as $index => $gateway ) {
			if ( $gateway instanceof WooPaymentsGateway && $gateway !== $canonical_gateway ) {
				unset( $payment_gateways->payment_gateways[ $index ] );
			}
		}
	}

	/**
	 * Keep the WooPayments gateways together in the saved or default gateway order.
	 *
	 * WooCommerce reads this option while it builds its gateway list, so the IDs come from the provider, never from WC()->payment_gateways().
	 *
	 * @internal
	 *
	 * @param mixed $ordering Saved or default gateway order.
	 * @return mixed
	 */
	public function handle_gateway_order_option( $ordering ) {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return $ordering;
		}

		try {
			$gateway_ids = array();
			foreach ( wc_get_container()->get( WooPaymentsProvider::class )->get_payment_gateways() as $gateway ) {
				$gateway_ids[] = $gateway->id;
			}

			if ( array() === $gateway_ids ) {
				return $ordering;
			}

			return self::order_woopayments_gateways( $ordering, $gateway_ids );
		} catch ( \Exception $e ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Failed to order gateways.', $e, array(), 'warning' );
			return (array) $ordering;
		}
	}

	/**
	 * Place the WooPayments gateways as one block at the card gateway's position, or first when it has none.
	 *
	 * Ports the client's `WC_Payments::order_woopayments_gateways()`: WooPayments gateways saved before the card gateway keep their place, and those saved after it join the block.
	 *
	 * @internal
	 *
	 * @param mixed             $ordering    Saved or default gateway order.
	 * @param array<int,string> $gateway_ids WooPayments gateway IDs, card gateway first.
	 * @return array<int|string,int> Gateway order.
	 */
	public static function order_woopayments_gateways( $ordering, array $gateway_ids ): array {
		$ordering = (array) $ordering;

		$woopayments_payment_methods = array_flip( $gateway_ids );
		$main_gateway_position       = $ordering[ WooPaymentsPersistenceVocabulary::GATEWAY_ID ] ?? null;

		$before = array();
		$after  = array();

		foreach ( $ordering as $gateway_id => $position ) {
			if ( null === $main_gateway_position || $position < $main_gateway_position ) {
				$before[ $gateway_id ] = null;
			} elseif ( $position > $main_gateway_position && ! isset( $woopayments_payment_methods[ $gateway_id ] ) ) {
				$after[ $gateway_id ] = null;
			}
		}

		if ( null === $main_gateway_position ) {
			$new_ordering = array_merge( $woopayments_payment_methods, $before, $after );
		} else {
			$new_ordering = array_merge( $before, $woopayments_payment_methods, $after );
		}

		$positions = array();
		$index     = 0;
		foreach ( array_keys( $new_ordering ) as $gateway_id ) {
			$positions[ $gateway_id ] = $index++;
		}

		return $positions;
	}
}
