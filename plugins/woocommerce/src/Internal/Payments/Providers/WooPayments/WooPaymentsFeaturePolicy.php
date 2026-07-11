<?php
/**
 * WooPaymentsFeaturePolicy class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Shared provider policy for account-dependent WooPayments feature gates.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsFeaturePolicy {

	private const AMAZON_PAY_FLAG_OPTION = '_wcpay_feature_amazon_pay';

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
		return '1' === (string) get_option( self::AMAZON_PAY_FLAG_OPTION, '1' )
			&& self::is_ece_confirmation_tokens_enabled( $account_service );
	}
}
