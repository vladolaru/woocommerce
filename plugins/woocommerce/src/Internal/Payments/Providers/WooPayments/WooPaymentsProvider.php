<?php
/**
 * WooPaymentsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsGatewayRegistry;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayExtensionSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayOrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplier;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;

/**
 * First-party WooPayments provider skeleton for the native payments runtime.
 *
 * A3 exposes WooPayments money-moving operations behind the provider contract.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsProvider implements ProviderContract, ProviderOperationEffectApplier, ProviderOutcomeMetadataMapper, ProviderPostLifecycleEffectApplier {

	/**
	 * WooPayments gateway adapter.
	 *
	 * @var WooPaymentsProviderGatewayAdapter
	 */
	private WooPaymentsProviderGatewayAdapter $gateway_adapter;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Request-scoped native payment gateways keyed by payment method ID.
	 *
	 * @var array<string,NativeWooPaymentsGateway>|null
	 */
	private ?array $payment_gateways = null;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier|null
	 */
	private ?WooPaymentsOrderEffectApplier $order_effect_applier = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsProviderGatewayAdapter     $gateway_adapter         WooPayments gateway adapter.
	 * @param WooPaymentsApiClient                  $api_client              Native WooPayments API client.
	 * @param WooPaymentsAccountService             $account_service         WooPayments account service.
	 * @param WooPaymentsPaymentMethodRegistry|null $payment_method_registry Optional payment method registry.
	 * @param WooPaymentsOrderEffectApplier|null    $order_effect_applier    Optional order effect applier.
	 */
	final public function init(
		WooPaymentsProviderGatewayAdapter $gateway_adapter,
		WooPaymentsApiClient $api_client,
		WooPaymentsAccountService $account_service,
		?WooPaymentsPaymentMethodRegistry $payment_method_registry = null,
		?WooPaymentsOrderEffectApplier $order_effect_applier = null
	): void {
		$this->gateway_adapter         = $gateway_adapter;
		$this->api_client              = $api_client;
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry ?? new WooPaymentsPaymentMethodRegistry();
		$this->order_effect_applier    = $order_effect_applier;
		$this->payment_gateways        = null;
	}

	/**
	 * Get the provider-owned roots for each native dormancy tier and request class.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,array<string,array<int,class-string>>> Root classes in registration order.
	 */
	public static function get_bootstrap_root_matrix(): array {
		$connected_admin           = array(
			WooPaymentsCutoverController::class,
			WooPaymentsAdminNavigationController::class,
			WooPaymentsAccountService::class,
			WooPaymentsWebhookReliabilityService::class,
			WooPaymentsCustomerService::class,
			WooPaymentsOrderFraudMetaBox::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsOrderStatusChangeController::class,
			WooPaymentsWooPayOrderStatusSync::class,
			WooPaymentsWooPayExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsCurrencyComplianceNotice::class,
			WooPaymentsOrderTrackingService::class,
			WooPaymentsOperationalQueueService::class,
		);
		$connected_ajax            = array(
			WooPaymentsAccountService::class,
			WooPaymentsWebhookReliabilityService::class,
			WooPaymentsCustomerService::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsOrderStatusChangeController::class,
			WooPaymentsWooPayOrderStatusSync::class,
			WooPaymentsWooPayExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsOrderTrackingService::class,
			WooPaymentsOperationalQueueService::class,
		);
		$connected_rest            = array(
			WooPaymentsAccountService::class,
			WooPaymentsWebhookReliabilityService::class,
			WooPaymentsMerchantRestController::class,
			WooPaymentsCustomerService::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsWooPayOrderStatusSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsWebhookRestController::class,
			WooPaymentsMobileRestController::class,
			WooPaymentsAccountSessionRestController::class,
			WooPaymentsCustomersRestController::class,
			WooPaymentsDepositsRestController::class,
			WooPaymentsPaymentDetailsRestController::class,
			WooPaymentsAuthorizationsRestController::class,
			WooPaymentsTransactionsRestController::class,
			WooPaymentsDisputesRestController::class,
			WooPaymentsDisputeReadinessRestController::class,
			WooPaymentsCapitalRestController::class,
			WooPaymentsDocumentsRestController::class,
			WooPaymentsReportsRestController::class,
			WooPaymentsTosRestController::class,
			WooPaymentsOrderTrackingService::class,
			WooPaymentsOperationalQueueService::class,
		);
		$connected_cron            = array(
			WooPaymentsAccountService::class,
			WooPaymentsWebhookReliabilityService::class,
			WooPaymentsOperationalQueueService::class,
			WooPaymentsOrderTrackingService::class,
			WooPaymentsWooPayOrderStatusSync::class,
			WooPaymentsWooPayExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsCanceledAuthorizationFeeRemediationService::class,
			WooPaymentsOrderAdminActionsController::class,
		);
		$active_prefix             = array(
			NativePaymentsGatewayRegistry::class,
			self::class,
		);
		$active_maintenance_prefix = array_merge(
			array( WooPaymentsCutoverNormalizationRunner::class ),
			$active_prefix
		);

		return array(
			NativePaymentsState::AVAILABLE => array(
				'admin' => array( WooPaymentsCutoverController::class ),
			),
			NativePaymentsState::CONNECTED => array(
				'admin' => $connected_admin,
				'ajax'  => $connected_ajax,
				'rest'  => $connected_rest,
				'cron'  => $connected_cron,
			),
			NativePaymentsState::ACTIVE    => array(
				'front' => array_merge(
					$active_prefix,
					array(
						WooPaymentsAccountService::class,
						WooPaymentsWebhookReliabilityService::class,
						WooPaymentsFrontendStylesService::class,
						WooPaymentsCheckoutBridge::class,
						WooPaymentsAddressProvider::class,
						WooPaymentsCustomerService::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsRedirectReturnController::class,
						WooPaymentsOrderAdminActionsController::class,
						WooPaymentsOrderStatusChangeController::class,
						WooPaymentsTokenizedCartSessionController::class,
						WooPaymentsWooPaySessionController::class,
						WooPaymentsWooPayOrderStatusSync::class,
						WooPaymentsWooPayExtensionSync::class,
						WooPaymentsExpressCheckoutController::class,
						WooPaymentsOrderSuccessPage::class,
						WooPaymentsPaymentMethodMessaging::class,
						WooPaymentsTokenClassMapController::class,
						WooPaymentsApplePayDomainService::class,
						WooPaymentsFrontendTrackingController::class,
						WooPaymentsOrderTrackingService::class,
						WooPaymentsOperationalQueueService::class,
					)
				),
				'admin' => array_merge( $active_maintenance_prefix, $connected_admin ),
				'ajax'  => array_merge(
					$active_prefix,
					$connected_ajax,
					array(
						WooPaymentsCheckoutBridge::class,
						WooPaymentsAddressProvider::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsCheckoutAjaxController::class,
						WooPaymentsTokenizedCartSessionController::class,
						WooPaymentsWooPaySessionController::class,
						WooPaymentsExpressCheckoutController::class,
						WooPaymentsPaymentMethodMessaging::class,
						WooPaymentsTokenClassMapController::class,
						WooPaymentsFrontendTrackingController::class,
					)
				),
				'rest'  => array_merge(
					$active_prefix,
					$connected_rest,
					array(
						WooPaymentsCheckoutBridge::class,
						WooPaymentsAddressProvider::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsTokenizedCartSessionController::class,
						WooPaymentsWooPaySessionController::class,
						WooPaymentsExpressCheckoutController::class,
						WooPaymentsExpressCheckoutStoreApiExtension::class,
						WooPaymentsExpressCheckoutCurrencyGuard::class,
						WooPaymentsTokenClassMapController::class,
					)
				),
				'cron'  => array_merge(
					$active_maintenance_prefix,
					$connected_cron,
					array(
						WooPaymentsOrderStatusChangeController::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
					)
				),
			),
		);
	}

	/**
	 * Get the provider/gateway ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->get_persistence_profile()->get_gateway_id();
	}

	/**
	 * Get the provider capability manifest.
	 *
	 * @return CapabilityManifest
	 */
	public function get_capability_manifest(): CapabilityManifest {
		return CapabilityManifest::from_array(
			array(
				CapabilityManifest::CAPABILITY_CARDS,
				CapabilityManifest::CAPABILITY_SAVED_TOKENS,
				CapabilityManifest::CAPABILITY_MANDATES,
				CapabilityManifest::CAPABILITY_ASYNC_REDIRECT,
				CapabilityManifest::CAPABILITY_REFUNDS,
				CapabilityManifest::CAPABILITY_PARTIAL_REFUNDS,
				CapabilityManifest::CAPABILITY_MANUAL_CAPTURE,
				CapabilityManifest::CAPABILITY_EXPRESS_CHECKOUT,
				CapabilityManifest::CAPABILITY_HOSTED_SESSION,
				CapabilityManifest::CAPABILITY_SUBSCRIPTIONS,
				CapabilityManifest::CAPABILITY_IN_PERSON,
				CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP,
			)
		);
	}

	/**
	 * Get the provider persistence profile.
	 *
	 * @return ProviderPersistenceProfile
	 *
	 * @since 11.0.0
	 */
	public function get_persistence_profile(): ProviderPersistenceProfile {
		return new WooPaymentsPersistenceProfile();
	}

	/**
	 * Map a neutral outcome to WooPayments order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome );
	}

	/**
	 * Map a failed authorization operation to WooPayments order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_capture_failure_outcome_meta( $outcome );
	}

	/**
	 * Get payment gateway instances registered by WooPayments.
	 *
	 * @return array<int,NativeWooPaymentsGateway>
	 *
	 * @since 11.0.0
	 */
	public function get_payment_gateways(): array {
		return array_values(
			array_filter(
				$this->get_payment_gateway_map(),
				static fn( NativeWooPaymentsGateway $gateway ): bool => $gateway->get_payment_method_definition()->should_publish_gateway()
			)
		);
	}

	/**
	 * Get a native WooPayments gateway for a payment method or gateway ID.
	 *
	 * @param string $payment_method_or_gateway_id Payment method ID or gateway ID.
	 * @return NativeWooPaymentsGateway|null
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_for_method( string $payment_method_or_gateway_id ): ?NativeWooPaymentsGateway {
		$payment_method_id = $this->normalize_payment_method_id( $payment_method_or_gateway_id );
		$gateways          = $this->get_payment_gateway_map();

		return $gateways[ $payment_method_id ] ?? null;
	}

	/**
	 * Tell whether WooPayments can currently process native money operations.
	 *
	 * @return bool
	 */
	public function can_process_payments(): bool {
		return $this->api_client->is_available() && $this->account_service->can_process_payments();
	}

	/**
	 * Tell whether WooPayments can perform native onboarding/admin account operations.
	 *
	 * Unlike money-moving readiness, onboarding availability must not require an already-connected account.
	 *
	 * @return bool
	 */
	public function can_manage_onboarding(): bool {
		return $this->api_client->is_available();
	}

	/**
	 * Charge an order through WooPayments.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->get_gateway_adapter()->charge( $context, $idempotency_key );
	}

	/**
	 * Capture an authorized WooPayments charge.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->get_gateway_adapter()->capture( $context, $idempotency_key );
	}

	/**
	 * Cancel an authorized WooPayments charge.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->get_gateway_adapter()->cancel( $context, $idempotency_key );
	}

	/**
	 * Refund a WooPayments charge.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->get_gateway_adapter()->refund( $context, $idempotency_key );
	}

	/**
	 * Apply a request-scoped WooPayments effect plan.
	 *
	 * @param PaymentContext $context   Payment context.
	 * @param PaymentOutcome $outcome   Provider outcome.
	 * @param string         $operation Operation name.
	 * @return PaymentOutcome
	 */
	public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
		unset( $operation );

		$plan = $outcome->get_effect_plan();
		if ( ! $plan instanceof WooPaymentsOrderEffectPlan ) {
			return $outcome;
		}

		return $this->get_order_effect_applier()->apply( $context, $outcome, $plan );
	}

	/**
	 * Apply WooPayments display details after the generic payment lifecycle.
	 *
	 * @param PaymentContext $context   Payment context.
	 * @param PaymentOutcome $outcome   Applied provider outcome.
	 * @param string         $operation Operation name.
	 */
	public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void {
		$plan = $outcome->get_effect_plan();
		if ( $plan instanceof WooPaymentsOrderEffectPlan && WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT === $plan->get_type() ) {
			$this->get_order_effect_applier()->apply_payment_method_display_details( $context->get_order(), $plan->get_provider_result() );
		}

		if ( 'charge' === $operation ) {
			$this->get_gateway_adapter()->finalize_charge_idempotency_key( $context->get_order(), $outcome );
		}
	}

	/**
	 * Get the WooPayments gateway adapter.
	 *
	 * @return WooPaymentsProviderGatewayAdapter
	 */
	private function get_gateway_adapter(): WooPaymentsProviderGatewayAdapter {
		return $this->gateway_adapter;
	}

	/**
	 * Get the WooPayments order effect applier.
	 *
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function get_order_effect_applier(): WooPaymentsOrderEffectApplier {
		if ( null === $this->order_effect_applier ) {
			$this->order_effect_applier = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		}

		return $this->order_effect_applier;
	}

	/**
	 * Get the request-scoped native gateway map.
	 *
	 * @return array<string,NativeWooPaymentsGateway>
	 */
	private function get_payment_gateway_map(): array {
		if ( null === $this->payment_gateways ) {
			$this->payment_gateways = $this->build_payment_gateway_map();
		}

		return $this->payment_gateways;
	}

	/**
	 * Build native gateway instances for active payment method definitions.
	 *
	 * @return array<string,NativeWooPaymentsGateway>
	 */
	private function build_payment_gateway_map(): array {
		$definitions = $this->payment_method_registry->get_all();
		if ( ! empty( $definitions ) ) {
			// Prime caches to reduce future queries.
			wp_prime_option_caches(
				array_map(
					static function ( WooPaymentsPaymentMethodDefinition $definition ): string {
						$payment_method_id = $definition->get_id();

						return sprintf( 'woocommerce_%s_settings', 'card' === $payment_method_id ? OrderPaymentStore::GATEWAY_ID : OrderPaymentStore::GATEWAY_ID . '_' . $payment_method_id );
					},
					$definitions
				)
			);
		}

		$gateways = array();

		foreach ( $definitions as $definition ) {
			if ( 'amazon_pay' === $definition->get_id() && ! WooPaymentsFeaturePolicy::is_amazon_pay_enabled( $this->account_service ) ) {
				continue;
			}

			$gateways[ $definition->get_id() ] = 'card' === $definition->get_id()
				? wc_get_container()->get( NativeWooPaymentsGateway::class )
				: new NativeWooPaymentsGateway( $definition );
		}

		return $gateways;
	}

	/**
	 * Normalize a payment method ID from a payment method or gateway ID.
	 *
	 * @param string $payment_method_or_gateway_id Payment method ID or gateway ID.
	 * @return string
	 */
	private function normalize_payment_method_id( string $payment_method_or_gateway_id ): string {
		$payment_method_or_gateway_id = strtolower( trim( $payment_method_or_gateway_id ) );

		if ( OrderPaymentStore::GATEWAY_ID === $payment_method_or_gateway_id || '' === $payment_method_or_gateway_id ) {
			return 'card';
		}

		$gateway_prefix = OrderPaymentStore::GATEWAY_ID . '_';
		if ( str_starts_with( $payment_method_or_gateway_id, $gateway_prefix ) ) {
			return (string) substr( $payment_method_or_gateway_id, strlen( $gateway_prefix ) );
		}

		return $payment_method_or_gateway_id;
	}
}
