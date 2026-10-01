<?php
/**
 * WooPaymentsAdminNavigationController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers persistent admin navigation for native WooPayments surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAdminNavigationController implements RegisterHooksInterface {

	private const CAPABILITY = 'manage_woocommerce';

	private const MENU_HOOK_PRIORITY = 70;

	/**
	 * Client 11.1.0 runs its child-page onboarding redirect after its account redirects (admin_init priority 16).
	 */
	private const ONBOARDING_REDIRECT_HOOK_PRIORITY = 16;

	/**
	 * Moves the open state to the top-level menu holding the current submenu item, with WordPress's own menu classes.
	 */
	private const OPEN_CURRENT_MENU_SCRIPT = '( function () {'
		. 'var item = document.querySelector( "#adminmenu .wp-submenu li.current" );'
		. 'var top = item && item.closest( "li.menu-top" );'
		. 'if ( ! top || top.classList.contains( "wp-has-current-submenu" ) ) { return; }'
		. 'var swap = function ( el, remove, add ) { if ( el ) { el.classList.remove.apply( el.classList, remove ); el.classList.add.apply( el.classList, add ); } };'
		. 'document.querySelectorAll( "#adminmenu > li.wp-has-current-submenu" ).forEach( function ( open ) {'
		. 'swap( open, [ "wp-has-current-submenu", "wp-menu-open" ], [ "wp-not-current-submenu" ] );'
		. 'swap( open.querySelector( ":scope > a" ), [ "wp-has-current-submenu", "wp-menu-open" ], [ "wp-not-current-submenu" ] );'
		. '} );'
		. 'swap( top, [ "wp-not-current-submenu" ], [ "wp-has-current-submenu", "wp-menu-open" ] );'
		. 'swap( top.querySelector( ":scope > a" ), [ "wp-not-current-submenu" ], [ "wp-has-current-submenu", "wp-menu-open" ] );'
		. '} )();';

	private const UNRESOLVED_NOTIFICATION_BADGE_FORMAT = ' <span class="wcpay-menu-badge awaiting-mod count-%1$d"><span class="plugin-count">%1$d</span></span>';

	private const PATH_ONBOARDING = '/woopayments/onboarding';

	private const PATH_OVERVIEW = '/woopayments/overview';

	private const PATH_PAYOUTS = '/woopayments/payouts';

	private const PATH_TRANSACTIONS = '/woopayments/transactions';

	private const PATH_TRANSACTION_DETAILS = '/woopayments/transactions/details';

	private const PATH_REPORTS = '/woopayments/reports';

	private const PATH_DISPUTES = '/woopayments/disputes';

	private const PATH_DISPUTE_DETAILS = '/woopayments/disputes/details';

	private const PATH_DISPUTE_CHALLENGE = '/woopayments/disputes/challenge';

	private const PATH_CARD_READERS = '/woopayments/card-readers';

	private const PATH_LOANS = '/woopayments/loans';

	private const PATH_DOCUMENTS = '/woopayments/documents';

	private const PATH_SETTINGS = '/woopayments/settings';

	private const PATH_FRAUD_PROTECTION_SETTINGS = '/woopayments/settings/fraud-protection';

	private const PATH_EXPRESS_CHECKOUT_SETTINGS = '/woopayments/settings/express-checkout/:methodId';

	private const PATH_PAYOUT_DETAILS = '/woopayments/payouts/details';

	private const REGISTERED_ROUTE_PATHS = array(
		self::PATH_SETTINGS,
		self::PATH_EXPRESS_CHECKOUT_SETTINGS,
		self::PATH_FRAUD_PROTECTION_SETTINGS,
		self::PATH_ONBOARDING,
		self::PATH_OVERVIEW,
		self::PATH_PAYOUTS,
		self::PATH_PAYOUT_DETAILS,
		self::PATH_TRANSACTIONS,
		self::PATH_TRANSACTION_DETAILS,
		self::PATH_REPORTS,
		self::PATH_DISPUTES,
		self::PATH_DISPUTE_DETAILS,
		self::PATH_DISPUTE_CHALLENGE,
		self::PATH_CARD_READERS,
		self::PATH_LOANS,
		self::PATH_DOCUMENTS,
	);

	private const SETTINGS_FRAGMENT_ADVANCED = 'advanced';

	private const SETTINGS_FRAGMENT_PAYMENT_METHODS = 'payment-methods';

	private const VAT_DETAILS_REDIRECT_QUERY_ARG = 'woopayments-vat-details-redirect';

	private const VAT_DETAILS_MODAL_QUERY_ARG = 'woopayments-vat-details-modal';

	private const LEGACY_DOCUMENTS_ROUTE = '/payments/documents';

	private const LEGACY_REPORTS_ROUTE = '/payments/reports';

	private const LEGACY_CARD_READERS_ROUTE = '/payments/card-readers';

	private const LEGACY_LOANS_ROUTE = '/payments/loans';

	private const LEGACY_TRANSACTIONS_ROUTE = '/payments/transactions';

	/**
	 * Client 11.1.0 Transactions tab names (`client/transactions/index.tsx:77-97`, written to the URL as `tab`)
	 * mapped to the native Transactions `view` values.
	 */
	private const LEGACY_TRANSACTIONS_TAB_VIEWS = array(
		'uncaptured-page' => 'uncaptured',
		'blocked-page'    => 'blocked',
	);

	/**
	 * Client 11.1.0 WC Admin paths mapped to the native `/woopayments/*` routes.
	 *
	 * Authorized divergence (inbox.md N-125): native paths follow trunk's `/woopayments/onboarding` convention
	 * (launch-your-store/hub/main-content/xstate.tsx:122, launch-your-store/data/setup-payments-context.tsx:115 on trunk).
	 * Client deep links keep working through this map; platform-issued email links go through Compat/LegacyAdminLinkHandler.
	 * The test pins every client route from Fixtures/plugin-11.1.0-admin-routes.json.
	 */
	private const LEGACY_ROUTE_REDIRECTS = array(
		'/payments/connect'                    => self::PATH_ONBOARDING,
		'/payments/onboarding'                 => self::PATH_ONBOARDING,
		'/payments/onboarding/kyc'             => self::PATH_ONBOARDING,
		'/payments/overview'                   => self::PATH_OVERVIEW,
		'/payments/deposits'                   => self::PATH_PAYOUTS,
		'/payments/deposits/details'           => self::PATH_PAYOUT_DETAILS,
		'/payments/payouts'                    => self::PATH_PAYOUTS,
		'/payments/payouts/details'            => self::PATH_PAYOUT_DETAILS,
		self::LEGACY_TRANSACTIONS_ROUTE        => self::PATH_TRANSACTIONS,
		'/payments/transactions/details'       => self::PATH_TRANSACTION_DETAILS,
		self::LEGACY_REPORTS_ROUTE             => self::PATH_REPORTS,
		'/payments/disputes'                   => self::PATH_DISPUTES,
		'/payments/disputes/details'           => self::PATH_DISPUTE_DETAILS,
		'/payments/disputes/challenge'         => self::PATH_DISPUTE_CHALLENGE,
		self::LEGACY_CARD_READERS_ROUTE        => self::PATH_CARD_READERS,
		self::LEGACY_LOANS_ROUTE               => self::PATH_LOANS,
		self::LEGACY_DOCUMENTS_ROUTE           => self::PATH_DOCUMENTS,
		'/payments/settings'                   => self::PATH_SETTINGS,
		'/payments/fraud-protection'           => self::PATH_FRAUD_PROTECTION_SETTINGS,
		'/payments/multi-currency-setup'       => self::PATH_SETTINGS,
		'/payments/additional-payment-methods' => self::PATH_SETTINGS,
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

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
	 * WooPayments onboarding redirect.
	 *
	 * @var WooPaymentsOnboardingRedirect
	 */
	private WooPaymentsOnboardingRedirect $onboarding_redirect;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter     $arbiter                  Runtime owner arbiter.
	 * @param WooPaymentsAccountService        $account_service          WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService $badge_service            WooPayments admin menu badge service.
	 * @param WooPaymentsApplePayDomainService $apple_pay_domain_service WooPayments Apple Pay domain service.
	 * @param WooPaymentsOnboardingRedirect    $onboarding_redirect      WooPayments onboarding redirect.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsAccountService $account_service,
		WooPaymentsAdminMenuBadgeService $badge_service,
		WooPaymentsApplePayDomainService $apple_pay_domain_service,
		WooPaymentsOnboardingRedirect $onboarding_redirect
	): void {
		$this->arbiter                  = $arbiter;
		$this->account_service          = $account_service;
		$this->badge_service            = $badge_service;
		$this->apple_pay_domain_service = $apple_pay_domain_service;
		$this->onboarding_redirect      = $onboarding_redirect;
	}

	/**
	 * Get the native route a client 11.1.0 WC Admin path redirects to, or an empty string for any other path.
	 *
	 * The onboarding redirect uses it on stores where this controller is not loaded.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @param string $legacy_path Client WC Admin route path, for example `/payments/overview`.
	 * @return string
	 */
	public static function get_native_route_for_legacy_path( string $legacy_path ): string {
		return self::LEGACY_ROUTE_REDIRECTS[ $legacy_path ] ?? '';
	}

	/**
	 * Register admin navigation hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'admin_menu', array( $this, 'add_menu_items' ) ) ) {
			add_action( 'admin_menu', array( $this, 'add_menu_items' ), self::MENU_HOOK_PRIORITY );
		}

		if ( false === has_action( 'admin_init', array( $this, 'redirect_legacy_payment_paths' ) ) ) {
			add_action( 'admin_init', array( $this, 'redirect_legacy_payment_paths' ) );
		}

		if ( false === has_action( 'admin_init', array( $this, 'maybe_redirect_to_onboarding' ) ) ) {
			add_action( 'admin_init', array( $this, 'maybe_redirect_to_onboarding' ), self::ONBOARDING_REDIRECT_HOOK_PRIORITY );
		}

		if ( false === has_action( 'template_redirect', array( $this, 'redirect_vat_details_request' ) ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_vat_details_request' ) );
		}

		if ( false === has_filter( 'woocommerce_admin_shared_settings', array( $this, 'preload_shared_settings' ) ) ) {
			add_filter( 'woocommerce_admin_shared_settings', array( $this, 'preload_shared_settings' ) );
		}

		if ( false === has_filter( 'submenu_file', array( $this, 'highlight_current_payments_submenu' ) ) ) {
			add_filter( 'submenu_file', array( $this, 'highlight_current_payments_submenu' ) );
		}

		if ( false === has_action( 'adminmenu', array( $this, 'open_payments_menu' ) ) ) {
			add_action( 'adminmenu', array( $this, 'open_payments_menu' ) );
		}
	}

	/**
	 * Open the Payments menu on native WooPayments pages, like the client's pages under its Payments menu.
	 *
	 * WordPress resolves the open top-level menu from the `wc-settings` page slug after the `parent_file` filter runs,
	 * so it always opens WooCommerce here. This moves the open state to the menu that holds the current item, right
	 * after the menu is printed.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @return void
	 */
	public function open_payments_menu(): void {
		if ( '' === $this->get_current_menu_item_url() ) {
			return;
		}

		wp_print_inline_script_tag( self::OPEN_CURRENT_MENU_SCRIPT );
	}

	/**
	 * Mark the current native WooPayments page's Payments submenu item; detail pages mark their list, like the client.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @param mixed $submenu_file Current submenu slug.
	 * @return mixed
	 */
	public function highlight_current_payments_submenu( $submenu_file ) {
		$menu_item_url = $this->get_current_menu_item_url();

		return '' === $menu_item_url ? $submenu_file : $menu_item_url;
	}

	/**
	 * Get the registered Payments submenu URL for the current native WooPayments page, or an empty string.
	 *
	 * @return string
	 */
	private function get_current_menu_item_url(): string {
		global $submenu;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu highlighting.
		if ( ! $this->is_payments_settings_request() || ! isset( $_GET['path'] ) || ! is_string( $_GET['path'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu highlighting.
		$current_path = sanitize_text_field( wp_unslash( $_GET['path'] ) );
		// Client 11.1.0 settings is a WooCommerce > Settings section, so WooCommerce stays open there.
		if ( ! str_starts_with( $current_path, '/woopayments/' ) || self::PATH_SETTINGS === $current_path || str_starts_with( $current_path, self::PATH_SETTINGS . '/' ) ) {
			return '';
		}

		foreach ( $submenu[ $this->get_parent_slug() ] ?? array() as $menu_item ) {
			$menu_item_url = is_array( $menu_item ) && isset( $menu_item[2] ) && is_string( $menu_item[2] ) ? $menu_item[2] : '';
			parse_str( (string) wp_parse_url( $menu_item_url, PHP_URL_QUERY ), $menu_item_query );
			$menu_item_path = isset( $menu_item_query['path'] ) && is_string( $menu_item_query['path'] ) ? $menu_item_query['path'] : '';

			if ( '' !== $menu_item_path && ( $current_path === $menu_item_path || str_starts_with( $current_path, $menu_item_path . '/' ) ) ) {
				return $menu_item_url;
			}
		}

		return '';
	}

	/**
	 * Preload native WooPayments settings for the Payments settings page frontend.
	 *
	 * @param mixed $settings Shared admin settings.
	 * @return array<mixed>
	 */
	public function preload_shared_settings( $settings = array() ): array {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! $this->is_payments_settings_request() ) {
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

		$route_availability                                        = $this->get_admin_route_availability();
		$settings['woopaymentsSettings']['adminRouteAvailability'] = $route_availability;
		// Plugin 11.1.0 `WC_Payments_Admin::get_js_settings()` localizes these for the test-mode notice.
		$settings['woopaymentsSettings']['testMode'] = $this->account_service->is_test_mode_enabled();
		$settings['woopaymentsSettings']['devMode']  = $this->account_service->is_dev_mode_enabled();
		// Plugin 11.1.0 localizes `accountStatus.country`; the payment method icons pick the Afterpay brand by it.
		$settings['woopaymentsSettings']['accountCountry'] = $this->account_service->get_account_country();
		// Plugin 11.1.0 `WC_Payments_Admin` localizes this for `maybeTrackStripeConnected()`.
		$track_stripe_connected                                  = get_option( '_wcpay_onboarding_stripe_connected' );
		$settings['woopaymentsSettings']['trackStripeConnected'] = $track_stripe_connected ? $track_stripe_connected : '';
		// Plugin 11.1.0 shows the Uncaptured transactions tab when manual capture is on (`transactions/index.tsx:63-72`).
		$settings['woopaymentsSettings']['isManualCaptureEnabled'] = $this->badge_service->is_manual_capture_enabled();
		// Plugin 11.1.0 `class-wc-payments-admin.php:1060` localizes this for the payouts page schedule notice (`deposits/index.tsx:28-43`).
		$settings['woopaymentsSettings']['isNextDepositNoticeDismissed'] = '1' === get_option( 'wcpay_next_deposit_notice_dismissed', '0' );
		// Plugin 11.1.0 `class-wc-payments-admin.php:1021` localizes this for the transactions list's Subscription # column.
		$settings['woopaymentsSettings']['isSubscriptionsActive'] = $this->is_subscriptions_plugin_active();
		// Plugin 11.1.0 `class-wc-payments-admin.php:1040` localizes this for `formatExplicitCurrency()`.
		$settings['woopaymentsSettings']['shouldUseExplicitPrice'] = MultiCurrencyExplicitPriceProjectionService::should_output_explicit_admin_price();
		// Plugin 11.1.0 `class-wc-payments-admin.php:1074-1085` localizes this for the dispute cover letter.
		$settings['woopaymentsSettings']['formattedStoreAddress'] = $this->get_formatted_store_address();
		// Plugin 11.1.0 `class-wc-payments-admin.php:1031` localizes this on every page; the transactions list reads it for its Loan filter.
		$settings['woopaymentsSettings']['accountLoans'] = array( 'loans' => $this->get_capital_loans() );
		// Plugin 11.1.0 `class-wc-payments-admin.php:930-935,1046`: the list exports send it as `user_email`, the address the platform emails the file to.
		$current_user                                        = wp_get_current_user();
		$settings['woopaymentsSettings']['currentUserEmail'] = $current_user->user_email ? $current_user->user_email : get_option( 'admin_email' );

		$current_path = $this->get_request_scalar( $_GET, 'path' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route check.
		// Plugin 11.1.0 has the Overview's account data in the page, so the page renders before any request.
		if ( self::PATH_OVERVIEW === $current_path && ! empty( $route_availability['allowedRoutes'][ self::PATH_OVERVIEW ] ) ) {
			$overview_shell = $this->get_overview_shell();
			if ( null !== $overview_shell ) {
				$settings['woopaymentsSettings']['overviewShell'] = $overview_shell;
			}
		}
		// Plugin 11.1.0 renders the test and sandbox account notices on the server, so they show even when the page's reads fail.
		if ( self::PATH_SETTINGS === $current_path || str_starts_with( $current_path, self::PATH_SETTINGS . '/express-checkout/' ) ) {
			$account_mode = $this->get_account_mode();
			if ( null !== $account_mode ) {
				$settings['woopaymentsSettings']['accountMode'] = $account_mode;
			}
		}

		$provider_settings = $settings['woopaymentsSettings'];

		/**
		 * Filters native WooPayments settings with Core-owned Multi-Currency data.
		 *
		 * @since 11.2.0
		 *
		 * @param array<string,mixed> $settings Native WooPayments settings.
		 */
		$core_settings_candidate = apply_filters( 'woocommerce_multi_currency_js_settings', $provider_settings );
		if ( is_array( $core_settings_candidate ) ) {
			$provider_settings = $core_settings_candidate;
		}

		/**
		 * Filters native WooPayments settings for legacy WooPayments extensions.
		 *
		 * @since 11.2.0
		 *
		 * @param array<string,mixed> $settings Native WooPayments settings.
		 */
		$legacy_settings_candidate = apply_filters( 'wcpay_js_settings', $provider_settings );
		if ( is_array( $legacy_settings_candidate ) ) {
			$provider_settings = $legacy_settings_candidate;
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
	 * The cached account's Capital loans, like the plugin's `accountLoans.loans`: `<loan id>|<status>` strings.
	 *
	 * @return string[]
	 */
	private function get_capital_loans(): array {
		$capital = $this->account_service->get_cached_account_data()['capital'] ?? array();
		$loans   = is_array( $capital ) && is_array( $capital['loans'] ?? null ) ? $capital['loans'] : array();

		return array_values( array_filter( $loans, 'is_string' ) );
	}

	/**
	 * The Overview shell the Overview page would request, built from the cached account.
	 *
	 * @return array<string,mixed>|null The shell, or null when it cannot be built and the page should request it.
	 */
	private function get_overview_shell(): ?array {
		try {
			return wc_get_container()->get( WooPaymentsOverviewService::class )->get_overview();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The account fields the settings test account notice reads, from the same summary the account request returns.
	 *
	 * @return array{connected:bool,live:bool,testDrive:bool,sandbox:bool,setupUrl:string}|null The fields, or null when the summary cannot be read.
	 */
	private function get_account_mode(): ?array {
		try {
			$summary = wc_get_container()->get( WooPaymentsService::class )->get_account_summary();
		} catch ( \Throwable $e ) {
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
	 * The store address as WooCommerce formats it on one line, like the plugin's `formattedStoreAddress`.
	 *
	 * @return string The formatted address, or an empty string when WooCommerce's countries are not loaded.
	 */
	private function get_formatted_store_address(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries instanceof \WC_Countries ) {
			return '';
		}

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
	 * Whether WooCommerce Subscriptions 2.2.0 or later is active, like the plugin's `isSubscriptionsActive`.
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
	 * Redirect legacy WooPayments WC Admin paths to their native Settings > Payments routes.
	 *
	 * @return void
	 */
	public function redirect_legacy_payment_paths(): void {
		if ( wp_doing_ajax() || ! current_user_can( self::CAPABILITY ) || ! $this->arbiter->should_native_register() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for legacy admin deep links.
		$redirect_url = $this->get_legacy_payment_path_redirect_url( $_GET );
		if ( '' === $redirect_url ) {
			return;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect native WooPayments admin pages to onboarding without a working connection and a valid account.
	 *
	 * Runs at the client's admin_init priority; see WooPaymentsOnboardingRedirect for the ported client behavior.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function maybe_redirect_to_onboarding(): void {
		$this->onboarding_redirect->maybe_redirect();
	}

	/**
	 * Redirect temporary VAT details requests to the native provider settings route.
	 *
	 * @return void
	 */
	public function redirect_vat_details_request(): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for a temporary legacy compatibility URL.
		$redirect_url = $this->get_vat_details_redirect_url( $_GET );
		if ( '' === $redirect_url ) {
			return;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Get the native provider settings URL for temporary VAT details requests.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	public function get_vat_details_redirect_url( array $request ): string {
		if ( ! isset( $request[ self::VAT_DETAILS_REDIRECT_QUERY_ARG ] ) ) {
			return '';
		}

		return Utils::wc_payments_settings_url(
			self::PATH_SETTINGS,
			array(
				self::VAT_DETAILS_MODAL_QUERY_ARG => 'true',
			)
		);
	}

	/**
	 * Get the native redirect URL for a legacy WooPayments WC Admin request.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	public function get_legacy_payment_path_redirect_url( array $request ): string {
		if ( 'wc-admin' !== $this->get_request_scalar( $request, 'page' ) ) {
			return $this->get_legacy_payment_method_section_redirect_url( $request );
		}

		$legacy_path = sanitize_text_field( rawurldecode( $this->get_raw_request_scalar( $request, 'path' ) ) );
		$target_path = self::LEGACY_ROUTE_REDIRECTS[ $legacy_path ] ?? '';
		if ( '' === $target_path ) {
			return '';
		}

		$route_availability = $this->get_admin_route_availability();
		if ( $this->is_legacy_setup_route( $legacy_path ) ) {
			$target_path = $this->get_legacy_setup_route_redirect_path( $route_availability );
		}

		if ( true !== ( $route_availability['allowedRoutes'][ $target_path ] ?? false ) ) {
			$target_path = $this->get_fallback_redirect_path( $route_availability );
		}

		$query    = array();
		$fragment = '';
		foreach ( $request as $key => $value ) {
			if ( in_array( $key, array( 'page', 'path' ), true ) || ! is_scalar( $value ) ) {
				continue;
			}

			$sanitized_key = sanitize_key( (string) $key );
			if ( '' === $sanitized_key ) {
				continue;
			}

			$query[ $sanitized_key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		// `tab` belongs to wc-settings on native routes, so the client's tab moves to the argument each native page reads.
		$legacy_tab = $query['tab'] ?? null;
		unset( $query['tab'] );
		if ( self::LEGACY_REPORTS_ROUTE === $legacy_path && null !== $legacy_tab ) {
			$query['report_tab'] = $legacy_tab;
		}

		if ( self::LEGACY_TRANSACTIONS_ROUTE === $legacy_path && isset( self::LEGACY_TRANSACTIONS_TAB_VIEWS[ (string) $legacy_tab ] ) ) {
			$query['view'] = self::LEGACY_TRANSACTIONS_TAB_VIEWS[ (string) $legacy_tab ];
		}

		if ( '/payments/multi-currency-setup' === $legacy_path ) {
			$fragment = self::SETTINGS_FRAGMENT_ADVANCED;
		}

		if ( '/payments/additional-payment-methods' === $legacy_path ) {
			$fragment = self::SETTINGS_FRAGMENT_PAYMENT_METHODS;
		}

		return Utils::wc_payments_settings_url( $target_path, $query, $fragment );
	}

	/**
	 * Get the native settings redirect URL for legacy WooPayments payment method sections.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	private function get_legacy_payment_method_section_redirect_url( array $request ): string {
		if ( 'wc-settings' !== $this->get_request_scalar( $request, 'page' ) || 'checkout' !== $this->get_request_scalar( $request, 'tab' ) ) {
			return '';
		}

		$section = $this->get_request_scalar( $request, 'section' );
		if ( ! str_starts_with( $section, 'woocommerce_payments_' ) ) {
			return '';
		}

		// Client 11.1.0 WC_Payments_Admin_Settings::maybe_redirect_payment_method_settings() redirects every woocommerce_payments_ section.
		return Utils::wc_payments_settings_url( self::PATH_SETTINGS );
	}

	/**
	 * Tell whether a legacy route is part of the setup/onboarding flow.
	 *
	 * @param string $legacy_path Legacy WooPayments WC Admin route path.
	 * @return bool
	 */
	private function is_legacy_setup_route( string $legacy_path ): bool {
		return in_array(
			$legacy_path,
			array(
				'/payments/connect',
				'/payments/onboarding',
				'/payments/onboarding/kyc',
			),
			true
		);
	}

	/**
	 * Resolve setup-era deep links to the best available native account route.
	 *
	 * @param array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>} $route_availability Route availability.
	 * @return string
	 */
	private function get_legacy_setup_route_redirect_path( array $route_availability ): string {
		if ( true === ( $route_availability['allowedRoutes'][ self::PATH_ONBOARDING ] ?? false ) ) {
			return self::PATH_ONBOARDING;
		}

		if ( true === ( $route_availability['allowedRoutes'][ self::PATH_OVERVIEW ] ?? false ) ) {
			return self::PATH_OVERVIEW;
		}

		return self::PATH_SETTINGS;
	}

	/**
	 * Resolve denied legacy redirects to the best available native fallback.
	 *
	 * @param array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>} $route_availability Route availability.
	 * @return string
	 */
	private function get_fallback_redirect_path( array $route_availability ): string {
		if ( true === ( $route_availability['allowedRoutes'][ self::PATH_OVERVIEW ] ?? false ) ) {
			return self::PATH_OVERVIEW;
		}

		return self::PATH_SETTINGS;
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

	/**
	 * Get a raw scalar request value.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @param string              $key     Query key.
	 * @return string
	 */
	private function get_raw_request_scalar( array $request, string $key ): string {
		if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
			return '';
		}

		return wp_unslash( (string) $request[ $key ] );
	}

	/**
	 * Tell whether the current admin request is the Core Payments settings page.
	 *
	 * @return bool
	 */
	private function is_payments_settings_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request routing for admin shared settings.
		$page = $this->get_request_scalar( $_GET, 'page' );
		$tab  = $this->get_request_scalar( $_GET, 'tab' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab;
	}

	/**
	 * Get native WooPayments admin route availability for protected routes.
	 *
	 * @return array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>}
	 */
	private function get_admin_route_availability(): array {
		$gateway_enabled  = $this->account_service->is_gateway_enabled();
		$restricted       = $this->account_service->is_account_rejected() || $this->account_service->is_account_under_review();
		$valid_account    = $this->account_service->has_valid_account_for_admin_navigation();
		$onboarding       = ! $restricted && ! $valid_account && ( ! $this->account_service->has_account() || ! $this->account_service->is_details_submitted() );
		$full_access      = $valid_account && ! $restricted;
		$reduced_access   = $restricted;
		$protected_access = $full_access || $reduced_access;

		return array(
			'gatewayEnabled' => $gateway_enabled,
			'accountState'   => $this->get_admin_route_account_state( $restricted, $valid_account, $onboarding ),
			'allowedRoutes'  => array(
				self::PATH_SETTINGS                  => true,
				self::PATH_FRAUD_PROTECTION_SETTINGS => true,
				self::PATH_ONBOARDING                => $onboarding,
				self::PATH_OVERVIEW                  => $protected_access,
				self::PATH_PAYOUTS                   => $full_access,
				self::PATH_PAYOUT_DETAILS            => $full_access,
				self::PATH_TRANSACTIONS              => $protected_access,
				self::PATH_TRANSACTION_DETAILS       => $protected_access,
				self::PATH_DISPUTES                  => $protected_access,
				self::PATH_DISPUTE_DETAILS           => $protected_access,
				self::PATH_DISPUTE_CHALLENGE         => $protected_access,
				self::PATH_REPORTS                   => $full_access && $this->account_service->is_reports_enabled(),
				self::PATH_CARD_READERS              => $full_access && $this->account_service->is_card_present_eligible() && $this->account_service->has_card_readers_available(),
				self::PATH_LOANS                     => $full_access && $this->account_service->has_previous_capital_loans(),
				self::PATH_DOCUMENTS                 => $full_access && $this->account_service->is_documents_enabled(),
			),
		);
	}

	/**
	 * Tell whether every route exposed through the availability map is registered by the client router.
	 *
	 * @internal
	 * @return bool
	 */
	public function are_all_available_routes_registered(): bool {
		$available_route_paths = array_keys( $this->get_admin_route_availability()['allowedRoutes'] );

		return array() === array_diff( $available_route_paths, self::REGISTERED_ROUTE_PATHS );
	}

	/**
	 * Get a coarse account state label for native WooPayments admin route availability.
	 *
	 * @param bool $restricted      Whether the account is rejected or under review.
	 * @param bool $valid_account   Whether the account can use admin navigation.
	 * @param bool $onboarding      Whether the account should use the onboarding route.
	 * @return string
	 */
	private function get_admin_route_account_state( bool $restricted, bool $valid_account, bool $onboarding ): string {
		if ( $restricted ) {
			return 'restricted';
		}

		if ( $onboarding ) {
			return 'onboarding';
		}

		if ( $valid_account ) {
			return 'full';
		}

		return 'unavailable';
	}

	/**
	 * Add native WooPayments submenu items under the Core Payments parent menu.
	 *
	 * @return void
	 */
	public function add_menu_items(): void {
		if (
			! current_user_can( self::CAPABILITY )
			|| ! $this->arbiter->should_native_register()
		) {
			return;
		}

		foreach ( $this->get_menu_items() as $menu_item ) {
			$this->append_menu_item(
				$menu_item['title'],
				$menu_item['path'],
				$menu_item['query'] ?? array(),
				$menu_item['badge_count'] ?? 0
			);
		}
	}

	/**
	 * Get the menu items that should be visible for the cached account state.
	 *
	 * @return array<int,array{title:string,path:string,query?:array<string,string>,badge_count?:int}>
	 */
	private function get_menu_items(): array {
		if ( $this->account_service->is_account_rejected() || $this->account_service->is_account_under_review() ) {
			return $this->get_reduced_menu_items();
		}

		if ( ! $this->account_service->has_valid_account_for_admin_navigation() ) {
			if ( ! $this->account_service->has_account() ) {
				return array(
					array(
						'title' => __( 'Onboarding', 'woocommerce' ),
						'path'  => self::PATH_ONBOARDING,
					),
				);
			}

			if ( ! $this->account_service->is_details_submitted() ) {
				return array(
					array(
						'title' => __( 'Continue onboarding', 'woocommerce' ),
						'path'  => self::PATH_ONBOARDING,
					),
				);
			}

			return array();
		}

		return $this->get_full_menu_items();
	}

	/**
	 * Get the full native WooPayments submenu for a valid account.
	 *
	 * @return array<int,array{title:string,path:string,query?:array<string,string>,badge_count?:int}>
	 */
	private function get_full_menu_items(): array {
		$menu_items = array(
			array(
				'title' => __( 'Overview', 'woocommerce' ),
				'path'  => self::PATH_OVERVIEW,
			),
			array(
				'title' => __( 'Payouts', 'woocommerce' ),
				'path'  => self::PATH_PAYOUTS,
			),
			$this->get_transactions_menu_item(),
		);

		if ( $this->account_service->is_reports_enabled() ) {
			$menu_items[] = array(
				'title' => __( 'Reports', 'woocommerce' ),
				'path'  => self::PATH_REPORTS,
			);
		}

		$menu_items[] = $this->get_disputes_menu_item();

		if ( $this->account_service->is_card_present_eligible() && $this->account_service->has_card_readers_available() ) {
			$menu_items[] = array(
				'title' => __( 'Card Readers', 'woocommerce' ),
				'path'  => self::PATH_CARD_READERS,
			);
		}

		if ( $this->account_service->has_previous_capital_loans() ) {
			$menu_items[] = array(
				'title' => __( 'Capital Loans', 'woocommerce' ),
				'path'  => self::PATH_LOANS,
			);
		}

		if ( $this->account_service->is_documents_enabled() ) {
			$menu_items[] = array(
				'title' => __( 'Documents', 'woocommerce' ),
				'path'  => self::PATH_DOCUMENTS,
			);
		}

		$menu_items[] = array(
			'title' => __( 'Settings', 'woocommerce' ),
			'path'  => self::PATH_SETTINGS,
		);

		return $menu_items;
	}

	/**
	 * Get the reduced native WooPayments submenu for restricted accounts.
	 *
	 * @return array<int,array{title:string,path:string,query?:array<string,string>,badge_count?:int}>
	 */
	private function get_reduced_menu_items(): array {
		return array(
			array(
				'title' => __( 'Overview', 'woocommerce' ),
				'path'  => self::PATH_OVERVIEW,
			),
			$this->get_transactions_menu_item(),
			$this->get_disputes_menu_item(),
		);
	}

	/**
	 * Get the Transactions menu item with the uncaptured authorization badge when needed.
	 *
	 * @return array{title:string,path:string,badge_count?:int}
	 */
	private function get_transactions_menu_item(): array {
		$menu_item = array(
			'title' => __( 'Transactions', 'woocommerce' ),
			'path'  => self::PATH_TRANSACTIONS,
		);

		$count = $this->badge_service->get_uncaptured_transactions_count();
		if ( $count > 0 ) {
			$menu_item['badge_count'] = $count;
		}

		return $menu_item;
	}

	/**
	 * Get the Disputes menu item with the awaiting-response badge and filter when needed.
	 *
	 * @return array{title:string,path:string,query?:array<string,string>,badge_count?:int}
	 */
	private function get_disputes_menu_item(): array {
		$menu_item = array(
			'title' => __( 'Disputes', 'woocommerce' ),
			'path'  => self::PATH_DISPUTES,
		);

		$count = $this->badge_service->get_disputes_awaiting_response_count();
		if ( $count > 0 ) {
			$menu_item['query']       = array( 'filter' => 'awaiting_response' );
			$menu_item['badge_count'] = $count;
		}

		return $menu_item;
	}

	/**
	 * Append one submenu item under the Core Payments parent.
	 *
	 * @param string               $title       Menu title.
	 * @param string               $path        Native WooPayments settings route path.
	 * @param array<string,string> $query       Extra query args.
	 * @param int                  $badge_count Badge count.
	 * @return void
	 */
	private function append_menu_item( string $title, string $path, array $query = array(), int $badge_count = 0 ): void {
		global $submenu;

		$parent_slug = $this->get_parent_slug();
		$query       = array_merge( array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ), $query );

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress admin menus are registered through global arrays.
		if ( ! isset( $submenu[ $parent_slug ] ) || ! is_array( $submenu[ $parent_slug ] ) ) {
			$submenu[ $parent_slug ] = array();
		}

		$submenu[ $parent_slug ][] = array(
			esc_html( $title ) . $this->get_notification_badge( $badge_count ),
			self::CAPABILITY,
			Utils::wc_payments_settings_url( $path, $query ),
		);
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Get a menu notification badge.
	 *
	 * @param int $count Badge count.
	 * @return string
	 */
	private function get_notification_badge( int $count ): string {
		if ( $count <= 0 ) {
			return '';
		}

		return sprintf( self::UNRESOLVED_NOTIFICATION_BADGE_FORMAT, $count );
	}

	/**
	 * Get the Core Payments parent menu slug.
	 *
	 * @return string
	 */
	private function get_parent_slug(): string {
		return 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM;
	}
}
