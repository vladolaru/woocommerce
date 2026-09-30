<?php
/**
 * WooPaymentsOnboardingSource class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * WooPayments onboarding `from` and `source` vocabulary, and the referrer-based source detection.
 *
 * Ports client 11.1.0 `WC_Payments_Onboarding_Service` constants and `get_source()`. The source is a tracking value.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsOnboardingSource {

	public const SOURCE_WCADMIN_PAYMENT_TASK               = 'wcadmin-payment-task';
	public const SOURCE_WCADMIN_SETTINGS_PAGE              = 'wcadmin-settings-page';
	public const SOURCE_WCADMIN_NOX_IN_CONTEXT             = 'wcadmin-nox-in-context';
	public const SOURCE_WCADMIN_INCENTIVE_PAGE             = 'wcadmin-incentive-page';
	public const SOURCE_WCPAY_CONNECT_PAGE                 = 'wcpay-connect-page';
	public const SOURCE_WCPAY_OVERVIEW_PAGE                = 'wcpay-overview-page';
	public const SOURCE_WCPAY_PAYOUTS_PAGE                 = 'wcpay-payouts-page';
	public const SOURCE_WCPAY_RESET_ACCOUNT                = 'wcpay-reset-account';
	public const SOURCE_WCPAY_SETUP_LIVE_PAYMENTS          = 'wcpay-setup-live-payments';
	public const SOURCE_WCPAY_FINISH_SETUP_TASK            = 'wcpay-finish-setup-task';
	public const SOURCE_WCPAY_UPDATE_BUSINESS_DETAILS_TASK = 'wcpay-update-business-details-task';
	public const SOURCE_WCPAY_PO_BANK_ACCOUNT_TASK         = 'wcpay-po-bank-account-task';
	public const SOURCE_WCPAY_RECONNECT_WPCOM_TASK         = 'wcpay-reconnect-wpcom-task';
	public const SOURCE_WCPAY_GO_LIVE_TASK                 = 'wcpay-go-live-task';
	public const SOURCE_WCPAY_FINISH_SETUP_TOOL            = 'wcpay-finish-setup-tool';
	public const SOURCE_WCPAY_PAYOUT_FAILURE_NOTICE        = 'wcpay-payout-failure-notice';
	public const SOURCE_WCPAY_ACCOUNT_DETAILS              = 'wcpay-account-details';
	public const SOURCE_UNKNOWN                            = 'unknown';

	public const FROM_WCADMIN_PAYMENTS_TASK     = 'WCADMIN_PAYMENT_TASK';
	public const FROM_WCADMIN_PAYMENTS_SETTINGS = 'WCADMIN_PAYMENT_SETTINGS';
	public const FROM_WCADMIN_NOX_IN_CONTEXT    = 'WCADMIN_NOX_IN_CONTEXT';
	public const FROM_WCADMIN_INCENTIVE         = 'WCADMIN_PAYMENT_INCENTIVE';
	public const FROM_CONNECT_PAGE              = 'WCPAY_CONNECT';
	public const FROM_OVERVIEW_PAGE             = 'WCPAY_OVERVIEW';
	public const FROM_ACCOUNT_DETAILS           = 'WCPAY_ACCOUNT_DETAILS';
	public const FROM_SETTINGS                  = 'WCPAY_SETTINGS';
	public const FROM_PAYOUTS                   = 'WCPAY_PAYOUTS';
	public const FROM_GO_LIVE_TASK              = 'WCPAY_GO_LIVE_TASK';
	public const FROM_STRIPE                    = 'STRIPE';

	private const VALID_SOURCES = array(
		self::SOURCE_WCADMIN_PAYMENT_TASK,
		self::SOURCE_WCADMIN_SETTINGS_PAGE,
		self::SOURCE_WCADMIN_NOX_IN_CONTEXT,
		self::SOURCE_WCADMIN_INCENTIVE_PAGE,
		self::SOURCE_WCPAY_CONNECT_PAGE,
		self::SOURCE_WCPAY_OVERVIEW_PAGE,
		self::SOURCE_WCPAY_PAYOUTS_PAGE,
		self::SOURCE_WCPAY_RESET_ACCOUNT,
		self::SOURCE_WCPAY_SETUP_LIVE_PAYMENTS,
		self::SOURCE_WCPAY_FINISH_SETUP_TASK,
		self::SOURCE_WCPAY_UPDATE_BUSINESS_DETAILS_TASK,
		self::SOURCE_WCPAY_PO_BANK_ACCOUNT_TASK,
		self::SOURCE_WCPAY_RECONNECT_WPCOM_TASK,
		self::SOURCE_WCPAY_GO_LIVE_TASK,
		self::SOURCE_WCPAY_FINISH_SETUP_TOOL,
		self::SOURCE_WCPAY_PAYOUT_FAILURE_NOTICE,
		self::SOURCE_WCPAY_ACCOUNT_DETAILS,
	);

	/**
	 * Determine the initial onboarding source from the referer and URL params.
	 *
	 * Client 11.1.0 `WC_Payments_Onboarding_Service::get_source()` (class-wc-payments-onboarding-service.php:1256-1458),
	 * with the native `path=/woopayments/*` pages recognized where the client recognizes its own `/payments/*` pages.
	 *
	 * @since 11.2.0
	 *
	 * @param string|null              $referer    Optional. The referer URL. Defaults to wp_get_raw_referer().
	 * @param array<string,mixed>|null $get_params Optional. GET params. Defaults to $_GET.
	 * @return string The source, or self::SOURCE_UNKNOWN when it is unknown.
	 */
	public static function get_source( ?string $referer = null, ?array $get_params = null ): string {
		$referer = $referer ?? wp_get_raw_referer();
		// wp_get_raw_referer() returns false without a referer; the client passes that to urldecode() as ''.
		$referer    = urldecode( is_string( $referer ) ? $referer : '' );
		$get_params = $get_params ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$source_param = isset( $get_params['source'] ) ? sanitize_text_field( wp_unslash( $get_params['source'] ) ) : '';
		if ( in_array( $source_param, self::VALID_SOURCES, true ) ) {
			return $source_param;
		}

		// Action-type params have priority over the `wcpay-connect`, `from`, and referer clues.
		if ( isset( $get_params['wcpay-disable-onboarding-test-mode'] ) && 'true' === $get_params['wcpay-disable-onboarding-test-mode'] ) {
			return self::SOURCE_WCPAY_SETUP_LIVE_PAYMENTS;
		}
		if ( isset( $get_params['wcpay-reset-account'] ) && 'true' === $get_params['wcpay-reset-account'] ) {
			return self::SOURCE_WCPAY_RESET_ACCOUNT;
		}

		$wcpay_connect_param = isset( $get_params['wcpay-connect'] ) ? sanitize_text_field( wp_unslash( $get_params['wcpay-connect'] ) ) : '';
		$from_param          = isset( $get_params['from'] ) ? sanitize_text_field( wp_unslash( $get_params['from'] ) ) : '';

		switch ( $wcpay_connect_param ) {
			case self::FROM_WCADMIN_PAYMENTS_TASK:
				return self::SOURCE_WCADMIN_PAYMENT_TASK;
			case self::FROM_WCADMIN_PAYMENTS_SETTINGS:
				return self::SOURCE_WCADMIN_SETTINGS_PAGE;
			case self::FROM_WCADMIN_NOX_IN_CONTEXT:
				return self::SOURCE_WCADMIN_NOX_IN_CONTEXT;
			case self::FROM_WCADMIN_INCENTIVE:
				return self::SOURCE_WCADMIN_INCENTIVE_PAGE;
			default:
				break;
		}

		switch ( $from_param ) {
			case self::FROM_WCADMIN_PAYMENTS_TASK:
				return self::SOURCE_WCADMIN_PAYMENT_TASK;
			case self::FROM_SETTINGS:
			case self::FROM_WCADMIN_PAYMENTS_SETTINGS:
				return self::SOURCE_WCADMIN_SETTINGS_PAGE;
			case self::FROM_WCADMIN_NOX_IN_CONTEXT:
				return self::SOURCE_WCADMIN_NOX_IN_CONTEXT;
			case self::FROM_WCADMIN_INCENTIVE:
				return self::SOURCE_WCADMIN_INCENTIVE_PAGE;
			case self::FROM_CONNECT_PAGE:
				return self::SOURCE_WCPAY_CONNECT_PAGE;
			case self::FROM_PAYOUTS:
				return self::SOURCE_WCPAY_PAYOUTS_PAGE;
			case self::FROM_GO_LIVE_TASK:
				return self::SOURCE_WCPAY_GO_LIVE_TASK;
			case self::FROM_ACCOUNT_DETAILS:
				return self::SOURCE_WCPAY_ACCOUNT_DETAILS;
			default:
				break;
		}

		$referer_params = array();
		wp_parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $referer_params );

		// An array `source` sanitizes to '' in the client, so only a string can match.
		$source_param = isset( $referer_params['source'] ) && is_string( $referer_params['source'] ) ? sanitize_text_field( wp_unslash( $referer_params['source'] ) ) : '';
		if ( ! empty( $source_param ) && in_array( $source_param, self::VALID_SOURCES, true ) ) {
			return $source_param;
		}

		if ( self::referer_has( $referer_params, 'wc-admin', 'task', 'payments' ) ) {
			return self::SOURCE_WCADMIN_PAYMENT_TASK;
		}
		if ( self::referer_has( $referer_params, 'wc-settings', 'tab', 'checkout' ) ) {
			return self::get_payments_settings_referer_source( $referer_params );
		}
		if ( self::referer_has( $referer_params, 'wc-admin', 'path', '/wc-pay-welcome-page' ) ) {
			return self::SOURCE_WCADMIN_INCENTIVE_PAGE;
		}
		if ( self::referer_has( $referer_params, 'wc-admin', 'path', '/payments/connect' ) ) {
			return self::SOURCE_WCPAY_CONNECT_PAGE;
		}
		if ( self::referer_has( $referer_params, 'wc-admin', 'path', '/payments/overview' ) ) {
			return self::SOURCE_WCPAY_OVERVIEW_PAGE;
		}
		if (
			self::referer_has( $referer_params, 'wc-admin', 'path', '/payments/deposits' )
			|| self::referer_has( $referer_params, 'wc-admin', 'path', '/payments/payouts' )
		) {
			return self::SOURCE_WCPAY_PAYOUTS_PAGE;
		}

		return self::SOURCE_UNKNOWN;
	}

	/**
	 * Resolve the source for a Settings > Payments referer, where native WooPayments admin pages live.
	 *
	 * @param array<mixed> $referer_params Referer query params.
	 * @return string
	 */
	private static function get_payments_settings_referer_source( array $referer_params ): string {
		$path = isset( $referer_params['path'] ) && is_string( $referer_params['path'] ) ? $referer_params['path'] : '';

		// Client branch: discriminate the NOX in-context onboarding from the settings page.
		if ( '' !== $path && 0 === strpos( $path, '/woopayments/onboarding' ) ) {
			return self::SOURCE_WCADMIN_NOX_IN_CONTEXT;
		}

		// Native Overview; the client matches `page=wc-admin&path=/payments/overview`.
		if ( '/woopayments/overview' === $path ) {
			return self::SOURCE_WCPAY_OVERVIEW_PAGE;
		}

		// Native Payouts; the client matches `page=wc-admin&path=/payments/deposits` or `/payments/payouts`.
		if ( '/woopayments/payouts' === $path ) {
			return self::SOURCE_WCPAY_PAYOUTS_PAGE;
		}

		// Native settings; the client settings and express checkout pages are `page=wc-settings&tab=checkout&section=woocommerce_payments`.
		if ( '/woopayments/settings' === $path || 0 === strpos( $path, '/woopayments/settings/express-checkout/' ) ) {
			return self::SOURCE_WCADMIN_SETTINGS_PAGE;
		}

		// Other native pages are `page=wc-admin&path=/payments/*` pages in the client, which match no source branch.
		if ( 0 === strpos( $path, '/woopayments/' ) ) {
			return self::SOURCE_UNKNOWN;
		}

		return self::SOURCE_WCADMIN_SETTINGS_PAGE;
	}

	/**
	 * Tell whether a referer has the given `page` and one other query param, like the client's `array_intersect_assoc()` checks.
	 *
	 * @param array<mixed> $referer_params Referer query params.
	 * @param string       $page           Expected `page` value.
	 * @param string       $key            Other query param name.
	 * @param string       $value          Other query param value.
	 * @return bool
	 */
	private static function referer_has( array $referer_params, string $page, string $key, string $value ): bool {
		return 2 === count(
			array_intersect_assoc(
				$referer_params,
				array(
					'page' => $page,
					$key   => $value,
				)
			)
		);
	}
}
