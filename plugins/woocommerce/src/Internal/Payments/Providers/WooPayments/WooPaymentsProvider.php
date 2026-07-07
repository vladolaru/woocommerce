<?php
/**
 * WooPaymentsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\CapabilityManifest;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;

/**
 * First-party WooPayments provider skeleton for the native payments runtime.
 *
 * A3 exposes WooPayments money-moving operations behind the provider contract.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsProvider implements ProviderContract {

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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsProviderGatewayAdapter     $gateway_adapter         WooPayments gateway adapter.
	 * @param WooPaymentsApiClient                  $api_client              Native WooPayments API client.
	 * @param WooPaymentsAccountService             $account_service         WooPayments account service.
	 * @param WooPaymentsPaymentMethodRegistry|null $payment_method_registry Optional payment method registry.
	 */
	final public function init(
		WooPaymentsProviderGatewayAdapter $gateway_adapter,
		WooPaymentsApiClient $api_client,
		WooPaymentsAccountService $account_service,
		?WooPaymentsPaymentMethodRegistry $payment_method_registry = null
	): void {
		$this->gateway_adapter         = $gateway_adapter;
		$this->api_client              = $api_client;
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry ?? new WooPaymentsPaymentMethodRegistry();
		$this->payment_gateways        = null;
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
	 * Get payment gateway instances registered by WooPayments.
	 *
	 * @return array<int,NativeWooPaymentsGateway>
	 *
	 * @since 11.0.0
	 */
	public function get_payment_gateways(): array {
		return array_values( $this->get_payment_gateway_map() );
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
	 * Get the WooPayments gateway adapter.
	 *
	 * @return WooPaymentsProviderGatewayAdapter
	 */
	private function get_gateway_adapter(): WooPaymentsProviderGatewayAdapter {
		return $this->gateway_adapter;
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
		$capabilities = $this->get_account_capabilities();
		$gateways     = array();

		foreach ( $this->payment_method_registry->get_all() as $definition ) {
			if ( ! $this->should_publish_gateway_for_definition( $definition, $capabilities ) ) {
				continue;
			}

			$gateways[ $definition->get_id() ] = 'card' === $definition->get_id()
				? wc_get_container()->get( NativeWooPaymentsGateway::class )
				: new NativeWooPaymentsGateway( $definition );
		}

		return $gateways;
	}

	/**
	 * Tell whether a payment method definition should publish a gateway instance.
	 *
	 * @param WooPaymentsPaymentMethodDefinition $definition Payment method definition.
	 * @param array<string,mixed>                $capabilities Account capability status map.
	 * @return bool
	 */
	private function should_publish_gateway_for_definition( WooPaymentsPaymentMethodDefinition $definition, array $capabilities ): bool {
		if ( 'card' === $definition->get_id() ) {
			return true;
		}

		$status = $capabilities[ $definition->get_stripe_id() ] ?? '';

		return 'active' === $status;
	}

	/**
	 * Get cached WooPayments account capabilities.
	 *
	 * @return array<string,mixed>
	 */
	private function get_account_capabilities(): array {
		$account_data = $this->account_service->get_cached_account_data();

		return is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();
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
