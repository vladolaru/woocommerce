<?php
/**
 * WooPaymentsAdminSharedSettings class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\Jetpack\Identity_Crisis;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ExplicitPriceFormatter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the WooPayments admin pages' first-render data to WooCommerce's admin shared settings.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAdminSharedSettings implements RegisterHooksInterface {

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments admin menu badge service.
	 *
	 * @var WooPaymentsAdminMenuBadgeService
	 */
	private WooPaymentsAdminMenuBadgeService $badge_service;

	/**
	 * WooPayments Apple Pay domain service.
	 *
	 * @var WooPaymentsApplePayDomainService
	 */
	private WooPaymentsApplePayDomainService $apple_pay_domain_service;

	/**
	 * WooPayments admin navigation, for the request check and the pages' availability.
	 *
	 * @var WooPaymentsAdminNavigationController
	 */
	private WooPaymentsAdminNavigationController $navigation;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService            $account_service          WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService     $badge_service            WooPayments admin menu badge service.
	 * @param WooPaymentsApplePayDomainService     $apple_pay_domain_service WooPayments Apple Pay domain service.
	 * @param WooPaymentsAdminNavigationController $navigation               WooPayments admin navigation.
	 */
	final public function init(
		WooPaymentsAccountService $account_service,
		WooPaymentsAdminMenuBadgeService $badge_service,
		WooPaymentsApplePayDomainService $apple_pay_domain_service,
		WooPaymentsAdminNavigationController $navigation
	): void {
		$this->account_service          = $account_service;
		$this->badge_service            = $badge_service;
		$this->apple_pay_domain_service = $apple_pay_domain_service;
		$this->navigation               = $navigation;
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_admin_shared_settings', array( $this, 'preload_shared_settings' ) );
	}

	/**
	 * Get each country's currency format, keyed by country code, as client 11.1.0 `WC_Payments_Admin::get_js_settings()` builds
	 * it from WooCommerce's locale data (`class-wc-payments-admin.php:937-964`).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_currency_data(): array {
		$locale_info = include WC()->plugin_path() . '/i18n/locale-info.php';
		if ( ! is_array( $locale_info ) ) {
			return array();
		}

		$symbols       = get_woocommerce_currency_symbols();
		$currency_data = array();
		foreach ( $locale_info as $key => $value ) {
			$currency_code             = $value['currency_code'] ?? '';
			$default_locale_formatting = $value['locales']['default'] ?? array();
			$currency_data[ $key ]     = array(
				'code'              => $currency_code,
				'symbol'            => $value['short_symbol'] ?? $symbols[ $currency_code ] ?? '',
				'symbolPosition'    => $value['currency_pos'] ?? '',
				'thousandSeparator' => $value['thousand_sep'] ?? '',
				'decimalSeparator'  => $value['decimal_sep'] ?? '',
				'precision'         => $value['num_decimals'],
				'defaultLocale'     => array(
					'symbolPosition'    => $default_locale_formatting['currency_pos'] ?? '',
					'thousandSeparator' => $default_locale_formatting['thousand_sep'] ?? '',
					'decimalSeparator'  => $default_locale_formatting['decimal_sep'] ?? '',
				),
			);
		}

		return $currency_data;
	}

	/**
	 * Add the WooPayments pages' data to the shared settings, on the Payments settings tab only.
	 *
	 * @param mixed $settings Shared admin settings.
	 * @return array<mixed>
	 */
	public function preload_shared_settings( $settings = array() ): array {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! $this->navigation->is_payments_settings_request() ) {
			return $settings;
		}

		if ( ! isset( $settings['woopaymentsSettings'] ) || ! is_array( $settings['woopaymentsSettings'] ) ) {
			$settings['woopaymentsSettings'] = array();
		}

		$feature_flags = $settings['woopaymentsSettings']['featureFlags'] ?? array();
		if ( ! is_array( $feature_flags ) ) {
			$feature_flags = array();
		}

		$feature_flags['reportsArea']                             = $this->account_service->is_reports_enabled();
		$settings['woopaymentsSettings']['featureFlags']          = $feature_flags;
		$settings['woopaymentsSettings']['balanceReportIdentity'] = $this->get_balance_report_identity();

		$route_availability                                        = $this->navigation->get_admin_route_availability();
		$settings['woopaymentsSettings']['adminRouteAvailability'] = $route_availability;
		// The account mode notice reads `devMode`.
		$settings['woopaymentsSettings']['testMode'] = $this->account_service->is_test_mode_enabled();
		$settings['woopaymentsSettings']['devMode']  = $this->account_service->is_dev_mode_enabled();
		// The payment method icons pick the Afterpay brand by the account country.
		$settings['woopaymentsSettings']['accountCountry'] = $this->account_service->get_account_country();
		// Client 11.1.0 `class-wc-payments-admin.php:1053` localizes this; the Balance report requests it (`reports/balance/index.tsx:177`).
		$settings['woopaymentsSettings']['accountDefaultCurrency'] = $this->account_service->get_account_default_currency();
		// The Overview page reads it to record the KYC completion event once.
		$track_stripe_connected                                  = get_option( '_wcpay_onboarding_stripe_connected' );
		$settings['woopaymentsSettings']['trackStripeConnected'] = $track_stripe_connected ? $track_stripe_connected : '';
		// The Transactions page shows the Uncaptured tab when manual capture is on or there are authorizations to capture, as
		// client 11.1.0 does (`client/transactions/index.tsx:68-75`).
		$settings['woopaymentsSettings']['isManualCaptureEnabled'] = $this->badge_service->is_manual_capture_enabled();
		// Client 11.1.0 `class-wc-payments-admin.php:1060` localizes this for the payouts page schedule notice (`deposits/index.tsx:28-43`).
		$settings['woopaymentsSettings']['isNextDepositNoticeDismissed'] = '1' === get_option( 'wcpay_next_deposit_notice_dismissed', '0' );
		// Client 11.1.0 `class-wc-payments-admin.php:1026` localizes this for the Overview's Safe Mode notice.
		$settings['woopaymentsSettings']['isJetpackIdcActive'] = Identity_Crisis::has_identity_crisis();
		// Client 11.1.0 `class-wc-payments-admin.php:1021` localizes this for the transactions list's Subscription # column.
		$settings['woopaymentsSettings']['isSubscriptionsActive'] = $this->is_subscriptions_plugin_active();
		// Client 11.1.0 `class-wc-payments-admin.php:1040` localizes this for `formatExplicitCurrency()`.
		$settings['woopaymentsSettings']['shouldUseExplicitPrice'] = ExplicitPriceFormatter::should_output_explicit_admin_price();
		// Client 11.1.0 `class-wc-payments-admin.php:1007,1023,1047` localizes these for `formatCurrency()`: the store country, the
		// zero-decimal currencies and each country's currency format.
		$settings['woopaymentsSettings']['storeCountry']          = WC()->countries->get_base_country();
		$settings['woopaymentsSettings']['zeroDecimalCurrencies'] = WooPaymentsCurrencyUtils::get_zero_decimal_currencies();
		$settings['woopaymentsSettings']['currencyData']          = $this->get_currency_data();
		// Client 11.1.0 `class-wc-payments-admin.php:1074-1085` localizes this for the dispute cover letter.
		$settings['woopaymentsSettings']['formattedStoreAddress'] = $this->get_formatted_store_address();
		// Client 11.1.0 `class-wc-payments-admin.php:1031` localizes this on every page; the transactions list reads it for its Loan filter.
		$settings['woopaymentsSettings']['accountLoans'] = $this->get_account_loans();
		// The Documents page and the VAT link read these.
		$settings['woopaymentsSettings']['accountDocuments'] = array(
			'enabled'                => $this->account_service->is_documents_enabled(),
			'has_submitted_vat_data' => $this->account_service->has_submitted_vat_data(),
			'country'                => $this->account_service->get_account_country(),
		);
		// Client 11.1.0 `class-wc-payments-admin.php:930-935,1046`: the list exports send it as `user_email`, the address the platform emails the file to.
		$current_user                                        = wp_get_current_user();
		$settings['woopaymentsSettings']['currentUserEmail'] = $current_user->user_email ? $current_user->user_email : get_option( 'admin_email' );
		// Client 11.1.0 `class-wc-payments-admin.php:1048`: the REST root for file URLs, which also works on Plain permalinks.
		$settings['woopaymentsSettings']['restUrl'] = get_rest_url( null, '' );

		$current_path = $this->get_request_scalar( $_GET, 'path' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route check.
		// Client 11.1.0 `class-wc-payments-admin.php:1066`: the admin Tracks events add its hosting_provider (`client/tracks/index.ts:44`).
		// Read only on the WooPayments pages, where the client localizes it (`class-wc-payments-admin.php:754`, with the
		// `woocommerce_payments` section check of `class-wc-payments-utils.php:653`), so another gateway's settings page never
		// refreshes the cache.
		if ( $this->is_woopayments_page( $current_path ) ) {
			$settings['woopaymentsSettings']['trackingInfo'] = wc_get_container()->get( WooPaymentsTrackingInfoService::class )->get_tracking_info();
		} elseif ( '' === $this->get_request_scalar( $_GET, 'section' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route check.
			// The Payments settings app opens the WooPayments pages without a reload (its Manage button), so it carries the
			// info too, but only as cached: this page never fetches it.
			$cached_tracking_info = wc_get_container()->get( WooPaymentsTrackingInfoService::class )->get_cached_tracking_info();
			if ( null !== $cached_tracking_info ) {
				$settings['woopaymentsSettings']['trackingInfo'] = $cached_tracking_info;
			}
		}

		// The Overview's data comes with the page, so the page renders before any request.
		if ( WooPaymentsAdminNavigationController::PATH_OVERVIEW === $current_path && ! empty( $route_availability['allowedRoutes'][ WooPaymentsAdminNavigationController::PATH_OVERVIEW ] ) ) {
			$overview_shell = $this->get_overview_shell();
			if ( null !== $overview_shell ) {
				$settings['woopaymentsSettings']['overviewShell'] = $overview_shell;
			}
		}
		// The test and sandbox account notices get their data with the page, so they show even when the page's reads fail.
		if ( WooPaymentsAdminNavigationController::PATH_SETTINGS === $current_path || str_starts_with( $current_path, WooPaymentsAdminNavigationController::PATH_SETTINGS . '/express-checkout/' ) ) {
			$account_mode = $this->get_account_mode();
			if ( null !== $account_mode ) {
				$settings['woopaymentsSettings']['accountMode'] = $account_mode;
			}
		}

		$provider_settings = $settings['woopaymentsSettings'];

		// While WooCommerce runs Multi-Currency, the pages' data carries its flag, as the WooPayments extension's admin data does
		// (class-wc-payments-admin.php:1039).
		$multi_currency_arbiter = wc_get_container()->get( MultiCurrencyRuntimeArbiter::class );
		if ( $multi_currency_arbiter->is_builtin_owner() ) {
			$provider_settings['isMultiCurrencyEnabled'] = true;
		}

		/**
		 * Filters the WooPayments pages' data under the WooPayments extension's `wcpay_js_settings` name, so callbacks written
		 * for it keep running.
		 *
		 * @since 11.2.0
		 *
		 * @param array<string,mixed> $settings The WooPayments pages' data.
		 */
		$filtered_settings = apply_filters( 'wcpay_js_settings', $provider_settings );
		if ( is_array( $filtered_settings ) ) {
			$provider_settings = $filtered_settings;
		}

		// The settings page can open through in-app navigation, with no server-rendered notice.
		$apple_pay_domain_error = $this->apple_pay_domain_service->get_error_notice_for_settings_bootstrap();
		if ( null !== $apple_pay_domain_error ) {
			$provider_settings['applePayDomainError'] = $apple_pay_domain_error;
		}

		$settings['woopaymentsSettings'] = $provider_settings;

		return $settings;
	}

	/**
	 * The cached account's Capital block, for the Capital page and the transactions list's Loan filter: the
	 * `<loan id>|<status>` loan strings and whether there is an active or a previous loan.
	 *
	 * @return array{loans:string[],has_active_loan:bool,has_previous_loans:bool}
	 */
	private function get_account_loans(): array {
		$capital = $this->account_service->get_cached_account_data()['capital'] ?? array();
		$capital = is_array( $capital ) ? $capital : array();
		$loans   = is_array( $capital['loans'] ?? null ) ? $capital['loans'] : array();

		return array(
			'loans'              => array_values( array_filter( $loans, 'is_string' ) ),
			'has_active_loan'    => true === ( $capital['has_active_loan'] ?? false ),
			'has_previous_loans' => true === ( $capital['has_previous_loans'] ?? false ),
		);
	}

	/**
	 * The Overview shell the Overview page would request, built from the cached account.
	 *
	 * A failure is logged as client 11.1.0 logs its JS settings failures (`includes/admin/class-wc-payments-admin.php:973-988`).
	 *
	 * @return array<string,mixed>|null The shell, or null when it cannot be built and the page should request it.
	 */
	private function get_overview_shell(): ?array {
		try {
			return wc_get_container()->get( WooPaymentsOverviewService::class )->get_overview();
		} catch ( \Throwable $e ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'WooPayments JS settings: Could not build the Overview data.', $e, array(), 'warning' );

			return null;
		}
	}

	/**
	 * The account fields the settings test account notice reads, from the same summary the account request returns.
	 *
	 * A failure is logged like the Overview shell's.
	 *
	 * @return array{connected:bool,live:bool,testDrive:bool,sandbox:bool,setupUrl:string}|null The fields, or null when the summary cannot be read.
	 */
	private function get_account_mode(): ?array {
		try {
			$summary = wc_get_container()->get( WooPaymentsOverviewService::class )->get_account_summary();
		} catch ( \Throwable $e ) {
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'WooPayments JS settings: Could not read the account mode.', $e, array(), 'warning' );

			return null;
		}

		$account = $summary['account'] ?? null;
		if ( ! is_array( $account ) ) {
			return null;
		}

		$setup_url = $summary['urls']['setup'] ?? '';

		return array(
			'connected' => ! empty( $account['connected'] ),
			'live'      => ! empty( $account['live'] ),
			'testDrive' => ! empty( $account['test_drive'] ),
			'sandbox'   => ! empty( $account['sandbox'] ),
			'setupUrl'  => is_string( $setup_url ) ? $setup_url : '',
		);
	}

	/**
	 * The store address as WooCommerce formats it on one line, for the dispute cover letter.
	 *
	 * @return string The formatted address.
	 */
	private function get_formatted_store_address(): string {
		return WC()->countries->get_formatted_address(
			array(
				'address_1' => get_option( 'woocommerce_store_address', '' ),
				'address_2' => get_option( 'woocommerce_store_address_2', '' ),
				'city'      => get_option( 'woocommerce_store_city', '' ),
				'state'     => WC()->countries->get_base_state(),
				'postcode'  => get_option( 'woocommerce_store_postcode', '' ),
				'country'   => WC()->countries->get_base_country(),
			),
			', '
		);
	}

	/**
	 * Whether WooCommerce Subscriptions 2.2.0 or later is active.
	 *
	 * @return bool
	 */
	private function is_subscriptions_plugin_active(): bool {
		return class_exists( 'WC_Subscriptions' )
			&& isset( \WC_Subscriptions::$version )
			&& version_compare( (string) \WC_Subscriptions::$version, '2.2.0', '>=' );
	}

	/**
	 * Get the WooPayments identity used in Balance report exports.
	 *
	 * @return array{businessName:string,accountId:string}
	 */
	private function get_balance_report_identity(): array {
		$account_data     = $this->account_service->get_cached_account_data();
		$business_profile = $account_data['business_profile'] ?? array();
		$business_name    = is_array( $business_profile ) && isset( $business_profile['name'] ) && is_scalar( $business_profile['name'] )
			? trim( (string) $business_profile['name'] )
			: '';

		if ( '' === $business_name ) {
			$business_name = trim( get_bloginfo( 'name' ) );
		}

		return array(
			'businessName' => $business_name,
			'accountId'    => trim( $this->account_service->get_account_id() ),
		);
	}

	/**
	 * Tell whether the request shows a WooPayments page: one of its admin routes, or its section of the payments settings.
	 *
	 * @param string $current_path The request's admin route path.
	 * @return bool
	 */
	private function is_woopayments_page( string $current_path ): bool {
		$section = $this->get_request_scalar( $_GET, 'section' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route check.

		return str_starts_with( $current_path, '/woopayments/' ) || str_starts_with( $section, 'woocommerce_payments' );
	}

	/**
	 * Get a scalar request value.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @param string              $key     Query key.
	 * @return string
	 */
	private function get_request_scalar( array $request, string $key ): string {
		if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $request[ $key ] ) );
	}
}
