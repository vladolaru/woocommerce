<?php
/**
 * Creates the list of disabled funding sources.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\FreeTrialHandlerTrait;

/**
 * Class DisabledFundingSources.
 */
class DisabledFundingSources {

	use FreeTrialHandlerTrait;

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;
	/**
	 * All funding sources.
	 *
	 * @var array
	 */
	private array $all_funding_sources;

	/**
	 * DisabledFundingSources constructor.
	 *
	 * @param SettingsProvider $settings_provider   The settings provider.
	 * @param array            $all_funding_sources The all funding sources.
	 */
	public function __construct(
		SettingsProvider $settings_provider,
		array $all_funding_sources
	) {
		$this->settings_provider   = $settings_provider;
		$this->all_funding_sources = $all_funding_sources;
	}

	/**
	 * Returns the list of funding sources to be disabled.
	 *
	 * @param string $context The context.
	 * @return string[] List of disabled sources
	 */
	public function sources( string $context ): array {
		$block_contexts = array( 'checkout-block', 'cart-block' );
		$flags          = array(
			'context'          => $context,
			'is_block_context' => in_array( $context, $block_contexts, true ),
			'is_free_trial'    => $this->is_free_trial_cart(),
		);

		// Free trials have a shorter, special funding-source rule.
		if ( $flags['is_free_trial'] ) {
			return $this->sanitize_and_filter_sources(
				$this->get_sources_for_free_trial(),
				$flags
			);
		}

		$disable_funding = $this->get_sources_from_settings( $context );
		$disable_funding = $this->apply_card_rules( $disable_funding );

		if ( $flags['is_block_context'] ) {
			$disable_funding = $this->apply_block_checkout_rules( $disable_funding );
		}

		return $this->sanitize_and_filter_sources( $disable_funding, $flags );
	}

	/**
	 * Gets disabled funding sources from settings.
	 *
	 * Also the SDK v6 manager's rule for whether Venmo is offered in a location.
	 *
	 * @param string $context The context.
	 * @return array
	 */
	public function get_sources_from_settings( string $context ): array {
		$disabled_funding = array();
		$methods          = $this->settings_provider->button_styling( $context )->methods;

		if ( ! $this->settings_provider->venmo_enabled() || ! in_array( 'venmo', $methods, true ) ) {
			$disabled_funding[] = 'venmo';
		}

		/**
		 * Filters the list of disabled funding methods.
		 *
		 * This filter allows merchants to programmatically disable funding sources.
		 *
		 * @since 11.3.0
		 *
		 * @param array $disabled_funding The disabled funding source IDs.
		 */
		return (array) apply_filters(
			'woocommerce_paypal_payments_disabled_funding',
			$disabled_funding
		);
	}

	/**
	 * Gets disabled funding sources for free trial carts.
	 *
	 * Rule: every funding source is disabled, including 'card'.
	 *
	 * @return array
	 */
	private function get_sources_for_free_trial(): array {
		return $this->apply_card_rules( array_keys( $this->all_funding_sources ) );
	}

	/**
	 * Adds 'card' to the disabled funding sources.
	 *
	 * This is the single authority for the 'card' funding-source decision.
	 * No other module should add or remove 'card' via the disabled-funding
	 * filters, except the branded-only correction in SettingsModule
	 * (which depends on PaymentSettings gateway state unavailable here).
	 *
	 * @param array $disable_funding The current disabled funding sources.
	 * @return array
	 */
	private function apply_card_rules( array $disable_funding ): array {
		if ( $this->should_disable_card() ) {
			$disable_funding[] = 'card';
		}

		return $disable_funding;
	}

	/**
	 * Determines whether the 'card' funding source should be disabled.
	 *
	 * Card funding in the PayPal button stack is off by decision: the separate card button is the extension's cut
	 * card button, not wallet functionality, and PayPal's own guest card path inside the popup is unaffected. See
	 * FORK.md, Conventions.
	 *
	 * @return bool Always true.
	 */
	private function should_disable_card(): bool {
		return true;
	}

	/**
	 * Applies special rules for block checkout.
	 *
	 * Block checkout only supports: PayPal, PayLater, Venmo and card.
	 * All other funding methods are disabled here.
	 *
	 * @param array $disable_funding The current disabled funding sources.
	 * @return array
	 */
	private function apply_block_checkout_rules( array $disable_funding ): array {
		$allowed_in_blocks = array( 'venmo', 'paylater', 'paypal', 'card' );

		return array_merge(
			$disable_funding,
			array_diff( array_keys( $this->all_funding_sources ), $allowed_in_blocks )
		);
	}

	/**
	 * Filters the disabled "funding-sources" list and returns a sanitized array.
	 *
	 * @param array $disable_funding The disabled funding sources.
	 * @param array $flags           Decision flags.
	 * @return string[]
	 */
	private function sanitize_and_filter_sources( array $disable_funding, array $flags ): array {

		/**
		 * Filters the final list of disabled funding sources.
		 *
		 * @since 11.3.0
		 *
		 * @param array $disable_funding The filter value, funding sources to be disabled.
		 * @param array $flags           Decision flags to provide more context to filters.
		 */
		$disable_funding = apply_filters(
			'woocommerce_paypal_payments_sdk_disabled_funding_hook',
			$disable_funding,
			array(
				'context'          => (string) ( $flags['context'] ?? '' ),
				'is_block_context' => (bool) ( $flags['is_block_context'] ?? false ),
				'is_free_trial'    => (bool) ( $flags['is_free_trial'] ?? false ),
			)
		);

		// Make sure "paypal" is never disabled in the funding-sources.
		$disable_funding = array_filter(
			$disable_funding,
			static fn( string $funding_source ) => 'paypal' !== $funding_source
		);

		return array_unique( $disable_funding );
	}
}
