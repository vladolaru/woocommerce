<?php
/**
 * WooPaymentsFeaturePolicy class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;

/**
 * Shared provider policy for account-dependent WooPayments feature gates.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsFeaturePolicy {

	private const AMAZON_PAY_FLAG_OPTION = '_wcpay_feature_amazon_pay';
	private const ACCOUNT_CACHE_OPTION   = 'wcpay_account_data';

	/**
	 * Last request-local Amazon Pay policy decision.
	 *
	 * @var array{blog_id:int,account_service:WooPaymentsAccountService,rollout_flag:string,decision:bool}|null
	 */
	private static $amazon_pay_enabled;

	/**
	 * Tell whether ECE confirmation tokens are enabled for the connected account.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	public static function is_ece_confirmation_tokens_enabled( WooPaymentsAccountService $account_service ): bool {
		$account_data = $account_service->get_cached_account_data();

		return empty( $account_data['ece_confirmation_tokens_disabled'] );
	}

	/**
	 * Tell whether Amazon Pay is enabled by rollout and account policy.
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return bool
	 */
	public static function is_amazon_pay_enabled( WooPaymentsAccountService $account_service ): bool {
		$blog_id      = get_current_blog_id();
		$rollout_flag = (string) get_option( self::AMAZON_PAY_FLAG_OPTION, '1' );
		if (
			null !== self::$amazon_pay_enabled &&
			$blog_id === self::$amazon_pay_enabled['blog_id'] &&
			$account_service === self::$amazon_pay_enabled['account_service'] &&
			$rollout_flag === self::$amazon_pay_enabled['rollout_flag']
		) {
			return self::$amazon_pay_enabled['decision'];
		}

		$decision = '1' === $rollout_flag
			&& self::is_ece_confirmation_tokens_enabled( $account_service );

		self::$amazon_pay_enabled = array(
			'blog_id'         => $blog_id,
			'account_service' => $account_service,
			'rollout_flag'    => $rollout_flag,
			'decision'        => $decision,
		);
		self::register_account_cache_invalidation();

		return $decision;
	}

	/**
	 * Reset request-local feature-policy decisions.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 */
	public static function reset_cache(): void {
		self::$amazon_pay_enabled = null;
		remove_action( 'woocommerce_payments_account_refreshed', array( self::class, 'handle_account_refresh' ), 10 );
		remove_action( 'added_option', array( self::class, 'handle_account_cache_option_change' ), 10 );
		remove_action( 'updated_option', array( self::class, 'handle_account_cache_option_change' ), 10 );
		remove_action( 'deleted_option', array( self::class, 'handle_account_cache_option_change' ), 10 );
	}

	/**
	 * Clear the policy decision after account data is refreshed.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 */
	public static function handle_account_refresh(): void {
		self::reset_cache();
	}

	/**
	 * Clear the policy decision when the authoritative account cache changes.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 *
	 * @param mixed $option Option name.
	 */
	public static function handle_account_cache_option_change( $option ): void {
		if ( self::ACCOUNT_CACHE_OPTION !== $option ) {
			return;
		}

		self::reset_cache();
	}

	/**
	 * Register the authoritative account-cache invalidation signals.
	 */
	private static function register_account_cache_invalidation(): void {
		if ( false === has_action( 'woocommerce_payments_account_refreshed', array( self::class, 'handle_account_refresh' ) ) ) {
			add_action( 'woocommerce_payments_account_refreshed', array( self::class, 'handle_account_refresh' ) );
		}

		if ( false === has_action( 'added_option', array( self::class, 'handle_account_cache_option_change' ) ) ) {
			add_action( 'added_option', array( self::class, 'handle_account_cache_option_change' ) );
		}

		if ( false === has_action( 'updated_option', array( self::class, 'handle_account_cache_option_change' ) ) ) {
			add_action( 'updated_option', array( self::class, 'handle_account_cache_option_change' ) );
		}

		if ( false === has_action( 'deleted_option', array( self::class, 'handle_account_cache_option_change' ) ) ) {
			add_action( 'deleted_option', array( self::class, 'handle_account_cache_option_change' ) );
		}
	}

	/**
	 * Tell whether Link folds into the card payment method for a currency.
	 *
	 * The single source of truth for Link enablement: the Payment Element and
	 * the intent's payment_method_types must agree, or a Link-paid checkout
	 * produces a credential the intent does not declare.
	 *
	 * @param WooPaymentsAccountService        $account_service WooPayments account service.
	 * @param WooPaymentsPaymentMethodRegistry $registry        Payment method registry.
	 * @param string                           $currency        Checkout currency.
	 * @param WooPaymentsLegacyRuntime|null    $legacy_runtime  Legacy runtime for the settings fallback; resolved from the container when omitted and needed.
	 * @return bool
	 */
	public static function is_link_folded_into_card( WooPaymentsAccountService $account_service, WooPaymentsPaymentMethodRegistry $registry, string $currency, ?WooPaymentsLegacyRuntime $legacy_runtime = null ): bool {
		$method_ids = $account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', null );
		if ( ! is_array( $method_ids ) ) {
			$legacy_runtime = $legacy_runtime ?? wc_get_container()->get( WooPaymentsLegacyRuntime::class );
			$method_ids     = $legacy_runtime->get_gateway_upe_enabled_payment_method_ids();
		}

		$enabled_method_ids = array();
		foreach ( (array) $method_ids as $method_id ) {
			if ( is_scalar( $method_id ) ) {
				$enabled_method_ids[] = sanitize_key( (string) $method_id );
			}
		}

		if ( ! in_array( 'card', $enabled_method_ids, true ) || ! in_array( 'link', $enabled_method_ids, true ) ) {
			return false;
		}

		$link_definition = $registry->get( 'link' );
		if ( null === $link_definition || ! $link_definition->is_available_for( $currency, $account_service->get_account_country() ) ) {
			return false;
		}

		$account_data = $account_service->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();
		$fees         = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();

		return 'active' === ( $capabilities['link_payments'] ?? null ) && array_key_exists( 'link', $fees );
	}
}
