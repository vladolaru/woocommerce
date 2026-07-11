<?php
/**
 * NativePaymentsShadowMode class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Shadow;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Throwable;
use WC_Order;

/**
 * Records same-store native shadow output while the WooPayments plugin owns processing.
 *
 * A1 shadow mode is intentionally read-only: it observes after plugin-owned hooks have run, reads
 * the persisted payment surface, computes the native A1 projection, and logs a machine-readable
 * comparison outside the order. It must not save orders, create refunds, add notes, or call provider
 * mutation APIs.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class NativePaymentsShadowMode implements RegisterHooksInterface {

	/**
	 * Filter that enables read-only native shadow mode while the plugin owns processing.
	 *
	 * @var string
	 */
	const FILTER_SHADOW_ENABLED = 'woocommerce_native_payments_shadow_mode_enabled';

	/**
	 * Filter that enables full actual/native-computed surfaces in shadow logs.
	 *
	 * @var string
	 */
	const FILTER_LOG_FULL_SURFACES = 'woocommerce_native_payments_shadow_mode_log_full_surfaces';

	/**
	 * Filter that allows live-mode provider reads for native shadow projection.
	 *
	 * @var string
	 */
	const FILTER_ALLOW_LIVE_READS = 'woocommerce_native_payments_shadow_mode_allow_live_reads';

	/**
	 * WC logger source for machine-readable shadow comparison records.
	 *
	 * @var string
	 */
	const LOG_SOURCE = 'native-payments-shadow';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Order payment projection store.
	 *
	 * @var OrderPaymentStore
	 */
	private OrderPaymentStore $order_payment_store;

	/**
	 * Payment-surface differ.
	 *
	 * @var PaymentSurfaceDiffer
	 */
	private PaymentSurfaceDiffer $differ;

	/**
	 * Legacy proxy for mockable global calls.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * WooPayments API client for read-only intent projection.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceProfile
	 */
	private WooPaymentsPersistenceProfile $persistence_profile;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter  $arbiter             Runtime owner arbiter.
	 * @param OrderPaymentStore             $order_payment_store Order payment projection store.
	 * @param PaymentSurfaceDiffer          $differ              Payment-surface differ.
	 * @param LegacyProxy                   $legacy_proxy        Legacy proxy.
	 * @param WooPaymentsApiClient          $api_client           WooPayments API client.
	 * @param WooPaymentsPersistenceProfile $persistence_profile  WooPayments persistence profile.
	 * @param WooPaymentsAccountService     $account_service      WooPayments account service.
	 * @param WooPaymentsOrderDataService   $order_data_service   WooPayments order data service.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		OrderPaymentStore $order_payment_store,
		PaymentSurfaceDiffer $differ,
		LegacyProxy $legacy_proxy,
		WooPaymentsApiClient $api_client,
		WooPaymentsPersistenceProfile $persistence_profile,
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderDataService $order_data_service
	): void {
		$this->arbiter             = $arbiter;
		$this->order_payment_store = $order_payment_store;
		$this->differ              = $differ;
		$this->legacy_proxy        = $legacy_proxy;
		$this->api_client          = $api_client;
		$this->persistence_profile = $persistence_profile;
		$this->account_service     = $account_service;
		$this->order_data_service  = $order_data_service;
	}

	/**
	 * Register read-only shadow hooks.
	 *
	 * Shadow hooks are allowed only while the WooPayments plugin owns processing and the explicit
	 * shadow flag is enabled. This deliberately does not use should_native_register(), which remains
	 * false in the plugin-owned state.
	 */
	public function register() {
		if ( ! $this->should_register_shadow_hooks() ) {
			return;
		}

		$this->add_shadow_action_once( 'woocommerce_payment_complete', array( $this, 'handle_woocommerce_payment_complete' ), 100, 1 );
		$this->add_shadow_action_once( 'woocommerce_order_refunded', array( $this, 'handle_woocommerce_order_refunded' ), 100, 2 );
	}

	/**
	 * Tell whether shadow mode should register hooks.
	 *
	 * @return bool
	 */
	public function should_register_shadow_hooks(): bool {
		return $this->is_shadow_mode_enabled() && $this->arbiter->is_plugin_runtime_active();
	}

	/**
	 * Tell whether shadow mode is enabled.
	 *
	 * @return bool
	 */
	public function is_shadow_mode_enabled(): bool {
		/**
		 * Filters whether read-only native shadow mode is enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether shadow mode is enabled. Default false.
		 */
		return (bool) apply_filters( self::FILTER_SHADOW_ENABLED, false );
	}

	/**
	 * Observe the WooCommerce payment-complete hook after plugin-owned effects.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_woocommerce_payment_complete( int $order_id ): void {
		$order = $this->legacy_proxy->call_function( 'wc_get_order', $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->is_woopayments_order( $order ) ) {
			return;
		}

		$this->record_shadow_for_order( $order, 'woocommerce_payment_complete' );
	}

	/**
	 * Observe the WooCommerce order-refunded hook after plugin-owned effects.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function handle_woocommerce_order_refunded( int $order_id, int $refund_id ): void {
		$order = $this->legacy_proxy->call_function( 'wc_get_order', $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->is_woopayments_order( $order ) ) {
			return;
		}

		$this->record_shadow_for_order( $order, 'woocommerce_order_refunded' );
	}

	/**
	 * Record a same-store shadow comparison for an order.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $trigger Trigger name.
	 * @return ShadowComparison|null Shadow comparison, or null for non-WooPayments orders.
	 */
	public function record_shadow_for_order( WC_Order $order, string $trigger ): ?ShadowComparison {
		if ( ! $this->is_woopayments_order( $order ) ) {
			return null;
		}

		$start           = microtime( true );
		$actual          = $this->order_payment_store->read_payment_surface( $order, $this->persistence_profile );
		$native_computed = $this->compute_native_projection( $order );
		if ( null === $native_computed ) {
			return null;
		}

		$diff       = $this->differ->diff( $native_computed, $actual );
		$elapsed_ms = ( microtime( true ) - $start ) * 1000;

		$comparison = new ShadowComparison( $trigger, (int) $order->get_id(), $actual, $native_computed, $diff, $elapsed_ms );
		$this->log_comparison( $comparison );

		return $comparison;
	}

	/**
	 * Compute the independent native projection from provider intent data.
	 *
	 * Shadow mode is read-only: the fetched intent is passed through the same pure WooPayments codec
	 * and persistence profile used by native checkout to build an independent expected surface.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string,mixed>|null Native projection, or null when projection is intentionally skipped.
	 */
	private function compute_native_projection( WC_Order $order ): ?array {
		$intent_id = $this->get_projection_intent_id( $order );
		if ( '' === $intent_id || 0 !== strpos( $intent_id, 'pi_' ) ) {
			return null;
		}

		$order_mode = $this->get_order_mode( $order );
		if ( ! $this->should_read_provider_intent( $order, $order_mode ) || ! $this->api_client->is_available() ) {
			return null;
		}

		try {
			$intent = $this->api_client->get_payment_intention_for_mode( $intent_id, 'test' === $order_mode );
		} catch ( Throwable $exception ) {
			return null;
		}

		if ( empty( $intent ) ) {
			return null;
		}

		$plan    = WooPaymentsOrderEffectPlan::for_payment_intent( $intent, false );
		$outcome = WooPaymentsIntentCodec::outcome_from_intention( $intent, $order, $this->get_projection_context( $order, $intent, $order_mode ) )
			->with_effect_plan( $plan );

		return $this->project_surface_from_outcome( $order, $intent, $outcome );
	}

	/**
	 * Get the PaymentIntent ID to project.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_projection_intent_id( WC_Order $order ): string {
		return (string) $order->get_meta( '_intent_id', true );
	}

	/**
	 * Tell whether the provider intent may be read for this shadow comparison.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $order_mode Preserved order mode.
	 * @return bool
	 */
	private function should_read_provider_intent( WC_Order $order, string $order_mode ): bool {
		if ( 'test' === $order_mode ) {
			return true;
		}

		/**
		 * Filters whether live-mode shadow projection may read provider intents.
		 *
		 * Defaults to false so enabling shadow mode on a live store cannot generate live WPCOM reads
		 * without an explicit operator opt-in.
		 *
		 * @since 11.0.0
		 *
		 * @param bool     $allow Whether live reads are allowed. Default false.
		 * @param WC_Order $order Order being projected.
		 */
		return (bool) apply_filters( self::FILTER_ALLOW_LIVE_READS, false, $order );
	}

	/**
	 * Get the provider mode preserved on an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string `test` or `live`.
	 */
	private function get_order_mode( WC_Order $order ): string {
		$mode = strtolower( trim( (string) $order->get_meta( '_wcpay_mode', true ) ) );
		if ( 'test' === $mode ) {
			return 'test';
		}
		if ( in_array( $mode, array( 'live', 'prod' ), true ) ) {
			return 'live';
		}

		return $this->account_service->get_mode();
	}

	/**
	 * Build mapping context for the native WooPayments intent codec.
	 *
	 * @param WC_Order            $order      Order object.
	 * @param array<string,mixed> $intent     Provider intent response.
	 * @param string              $order_mode Preserved order mode.
	 * @return array<string,mixed>
	 */
	private function get_projection_context( WC_Order $order, array $intent, string $order_mode ): array {
		$args = array(
			'account_mode'         => $order_mode,
			'payment_credential'   => (string) $order->get_meta( '_payment_method_id', true ),
			'fallback_customer_id' => (string) $order->get_meta( '_stripe_customer_id', true ),
		);

		if ( 'succeeded' === (string) ( $intent['status'] ?? '' ) ) {
			$charge = WooPaymentsOrderEffects::latest_charge( $intent );
			if ( ! empty( $charge ) ) {
				$args['completed_meta'] = WooPaymentsOrderEffects::completed_charge_meta(
					$intent,
					$charge,
					$order,
					$this->account_service->get_account_default_currency(),
					$this->order_data_service
				);
			}
		}

		return $args;
	}

	/**
	 * Project an order payment surface from a native provider outcome.
	 *
	 * @param WC_Order            $order   Order object.
	 * @param array<string,mixed> $intent  Provider intent response.
	 * @param PaymentOutcome      $outcome Native provider outcome.
	 * @return array<string,mixed>
	 */
	private function project_surface_from_outcome( WC_Order $order, array $intent, PaymentOutcome $outcome ): array {
		$display_effects = $this->get_projected_display_effects( $order, $outcome );
		$payment_method  = (string) $order->get_payment_method();
		$meta            = $this->persistence_profile->get_outcome_meta( $outcome );
		if ( ! empty( $display_effects ) ) {
			$meta = array_merge( $meta, $display_effects['meta'] );
			if ( '' !== $display_effects['payment_method_id'] ) {
				$payment_method = $display_effects['payment_method_id'];
			}
		}

		$surface = array(
			'order_id'       => (int) $order->get_id(),
			'status'         => $this->get_projected_order_status( $order, $outcome ),
			'payment_method' => $payment_method,
			'transaction_id' => '',
			'currency'       => (string) $order->get_currency(),
			'total'          => (string) $order->get_total(),
			'meta'           => $meta,
			'refunds'        => $this->project_refund_surfaces( $order, $intent ),
		);

		if ( '' !== $outcome->get_provider_payment_id() && in_array( $outcome->get_status(), array( PaymentOutcome::STATUS_COMPLETED, PaymentOutcome::STATUS_AUTHORIZED ), true ) ) {
			$surface['transaction_id'] = $outcome->get_provider_payment_id();
		}

		ksort( $surface['meta'] );

		return $surface;
	}

	/**
	 * Project display effects from the in-memory PaymentIntent plan without invoking its writer.
	 *
	 * @param WC_Order       $order   Order being projected.
	 * @param PaymentOutcome $outcome Native provider outcome.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_title:string}|array{}
	 */
	private function get_projected_display_effects( WC_Order $order, PaymentOutcome $outcome ): array {
		$plan = $outcome->get_effect_plan();
		if ( ! $plan instanceof WooPaymentsOrderEffectPlan || WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT !== $plan->get_type() ) {
			return array();
		}

		return WooPaymentsOrderEffects::compose_payment_method_display_details(
			$plan->get_provider_result(),
			$this->account_service->get_account_country(),
			(string) $order->get_billing_country()
		);
	}

	/**
	 * Project WooCommerce refund identities with provider-owned refund metadata.
	 *
	 * @param WC_Order            $order  Order object.
	 * @param array<string,mixed> $intent Provider intent response.
	 * @return array<int,array<string,mixed>>
	 */
	private function project_refund_surfaces( WC_Order $order, array $intent ): array {
		$charge            = WooPaymentsOrderEffects::latest_charge( $intent );
		$refund_collection = isset( $charge['refunds'] ) && is_array( $charge['refunds'] ) ? $charge['refunds'] : array();
		$provider_refunds  = isset( $refund_collection['data'] ) && is_array( $refund_collection['data'] ) ? $refund_collection['data'] : array();
		$refunds_by_id     = array();

		foreach ( $provider_refunds as $provider_refund ) {
			if ( is_array( $provider_refund ) && isset( $provider_refund['id'] ) ) {
				$refunds_by_id[ (string) $provider_refund['id'] ] = $provider_refund;
			}
		}

		$projected  = array();
		$wc_refunds = array_values( $order->get_refunds() );

		foreach ( $wc_refunds as $refund ) {
			$provider_refund_id = (string) $refund->get_meta( '_wcpay_refund_id', true );
			$provider_refund    = $refunds_by_id[ $provider_refund_id ] ?? null;
			if ( ! is_array( $provider_refund ) && 1 === count( $wc_refunds ) && 1 === count( $provider_refunds ) ) {
				$provider_refund = reset( $provider_refunds );
			}

			$meta = array();
			if ( is_array( $provider_refund ) && isset( $provider_refund['id'] ) ) {
				$meta['_wcpay_refund_id'] = (string) $provider_refund['id'];
				$balance_transaction_id   = WooPaymentsOrderEffects::balance_transaction_id( $provider_refund['balance_transaction'] ?? null );
				if ( '' !== $balance_transaction_id ) {
					$meta['_wcpay_refund_transaction_id'] = $balance_transaction_id;
				}
			}

			ksort( $meta );
			$projected[] = array(
				'refund_id' => (int) $refund->get_id(),
				'amount'    => (string) $refund->get_amount(),
				'currency'  => (string) $refund->get_currency(),
				'reason'    => (string) $refund->get_reason(),
				'meta'      => $meta,
			);
		}

		usort(
			$projected,
			static function ( array $left, array $right ): int {
				return $left['refund_id'] <=> $right['refund_id'];
			}
		);

		return $projected;
	}

	/**
	 * Get the projected WooCommerce order status for a native outcome.
	 *
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Native provider outcome.
	 * @return string
	 */
	private function get_projected_order_status( WC_Order $order, PaymentOutcome $outcome ): string {
		$current_status = (string) $order->get_status();

		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
					return (string) $order->get_status();
				}

				return $this->get_completed_order_status( $order );

			case PaymentOutcome::STATUS_AUTHORIZED:
				return 'on-hold';

			case PaymentOutcome::STATUS_FAILED:
				return 'failed';

			case PaymentOutcome::STATUS_CANCELED:
				return 'cancelled';

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return $current_status;
		}

		return $current_status;
	}

	/**
	 * Get the order status WooCommerce would choose for a completed payment.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private function get_completed_order_status( WC_Order $order ): string {
		return $order->needs_processing() ? 'processing' : 'completed';
	}

	/**
	 * Tell whether an order belongs to WooPayments.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True for the main WooPayments gateway and split-UPE WooPayments gateway IDs.
	 */
	private function is_woopayments_order( WC_Order $order ): bool {
		$payment_method = (string) $order->get_payment_method();

		return OrderPaymentStore::GATEWAY_ID === $payment_method || 0 === strpos( $payment_method, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}

	/**
	 * Log a machine-readable shadow comparison out of band.
	 *
	 * @param ShadowComparison $comparison Shadow comparison.
	 */
	private function log_comparison( ShadowComparison $comparison ): void {
		$logger = $this->legacy_proxy->call_function( 'wc_get_logger' );
		if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'debug' ) ) ) {
			return;
		}

		/**
		 * Filters whether shadow logs include full actual/native-computed surfaces.
		 *
		 * Defaults to false so production canaries record compact diffs and surface hashes rather
		 * than duplicating full order/refund payment surfaces on every observed event.
		 *
		 * @since 11.0.0
		 *
		 * @param bool             $include_surfaces Whether to include full surfaces. Default false.
		 * @param ShadowComparison $comparison       Shadow comparison.
		 */
		$include_surfaces = (bool) apply_filters( self::FILTER_LOG_FULL_SURFACES, false, $comparison );

		$message = wp_json_encode( $comparison->to_log_array( $include_surfaces ) );
		if ( false === $message ) {
			return;
		}

		$logger->debug(
			$message,
			array(
				'source' => self::LOG_SOURCE,
			)
		);
	}

	/**
	 * Register an action only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_shadow_action_once( string $hook, callable $callback, int $priority, int $accepted_args ): void {
		if ( false === has_action( $hook, $callback ) ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}
