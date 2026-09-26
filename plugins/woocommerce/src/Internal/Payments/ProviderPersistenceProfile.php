<?php
/**
 * ProviderPersistenceProfile interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Legacy provider persistence profile retained for compatibility.
 *
 * New providers should implement ProviderPersistenceVocabulary for persisted
 * identifiers and ProviderOutcomeMetadataMapper for outcome interpretation.
 * The three methods declared here remain operational compatibility fallbacks
 * for existing implementations and will not be extended.
 *
 * @since 11.0.0
 * @deprecated 11.0.0 Use ProviderPersistenceVocabulary and ProviderOutcomeMetadataMapper.
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderPersistenceProfile extends ProviderPersistenceVocabulary {

	/**
	 * Map a neutral outcome to provider order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Implement ProviderOutcomeMetadataMapper on the provider.
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array;

	/**
	 * Map a failed capture outcome to provider order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Implement ProviderOutcomeMetadataMapper on the provider.
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array;

	/**
	 * Tell whether a provider-written duplicate order note should be skipped.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 *
	 * @since 11.0.0
	 * @deprecated 11.0.0 Lifecycle note identity is owned by the lifecycle service.
	 */
	public function should_skip_note( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool;
}
