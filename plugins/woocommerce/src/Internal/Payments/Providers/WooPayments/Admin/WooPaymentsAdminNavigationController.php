<?php
/**
 * WooPaymentsAdminNavigationController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the WooPayments pages in the Payments menu and sends merchants to them: the submenu with its badges, the menu
 * highlighting, the browser tab title, the redirects of `/payments/*` WC Admin links and the onboarding redirect.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAdminNavigationController implements RegisterHooksInterface {

	private const CAPABILITY = 'manage_woocommerce';

	private const MENU_HOOK_PRIORITY = 70;

	/**
	 * Client 11.1.0 runs its child-page onboarding redirect after its account redirects (admin_init priority 16).
	 *
	 * It runs after `redirect_payments_paths()` (`admin_init`, priority 10), so an old `/payments/*` link reaches its
	 * Payments settings route before the onboarding redirect looks at the request.
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

	public const PATH_OVERVIEW = '/woopayments/overview';

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

	public const PATH_SETTINGS = '/woopayments/settings';

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

	/**
	 * The `/payments/*` WC Admin paths that order notes and platform emails link to; PAYMENTS_PATH_REDIRECTS sends each one
	 * to its Payments settings route.
	 */
	private const PAYMENTS_DOCUMENTS_PATH = '/payments/documents';

	private const PAYMENTS_REPORTS_PATH = '/payments/reports';

	private const PAYMENTS_CARD_READERS_PATH = '/payments/card-readers';

	private const PAYMENTS_LOANS_PATH = '/payments/loans';

	private const PAYMENTS_TRANSACTIONS_PATH = '/payments/transactions';

	/**
	 * Client 11.1.0 Transactions tab names (`client/transactions/index.tsx:77-97`, written to the URL as `tab`)
	 * mapped to the Transactions page's `view` values.
	 */
	private const PAYMENTS_TRANSACTIONS_TAB_VIEWS = array(
		'uncaptured-page' => 'uncaptured',
		'blocked-page'    => 'blocked',
	);

	/**
	 * Client 11.1.0 WC Admin paths mapped to the Payments settings `/woopayments/*` routes.
	 *
	 * The onboarding route uses the `/woopayments/onboarding` path the Launch Your Store hub links to and checks for
	 * (`client/admin/client/launch-your-store/hub/main-content/xstate.tsx:122`, `launch-your-store/data/setup-payments-context.tsx:130`).
	 * Old `/payments/*` links keep working through this map; the platform's email links go through
	 * `WooPaymentsAdminLinkController`.
	 */
	private const PAYMENTS_PATH_REDIRECTS = array(
		'/payments/connect'                    => self::PATH_ONBOARDING,
		'/payments/onboarding'                 => self::PATH_ONBOARDING,
		'/payments/onboarding/kyc'             => self::PATH_ONBOARDING,
		'/payments/overview'                   => self::PATH_OVERVIEW,
		'/payments/deposits'                   => self::PATH_PAYOUTS,
		'/payments/deposits/details'           => self::PATH_PAYOUT_DETAILS,
		'/payments/payouts'                    => self::PATH_PAYOUTS,
		'/payments/payouts/details'            => self::PATH_PAYOUT_DETAILS,
		self::PAYMENTS_TRANSACTIONS_PATH       => self::PATH_TRANSACTIONS,
		'/payments/transactions/details'       => self::PATH_TRANSACTION_DETAILS,
		self::PAYMENTS_REPORTS_PATH            => self::PATH_REPORTS,
		'/payments/disputes'                   => self::PATH_DISPUTES,
		'/payments/disputes/details'           => self::PATH_DISPUTE_DETAILS,
		'/payments/disputes/challenge'         => self::PATH_DISPUTE_CHALLENGE,
		self::PAYMENTS_CARD_READERS_PATH       => self::PATH_CARD_READERS,
		self::PAYMENTS_LOANS_PATH              => self::PATH_LOANS,
		self::PAYMENTS_DOCUMENTS_PATH          => self::PATH_DOCUMENTS,
		'/payments/settings'                   => self::PATH_SETTINGS,
		'/payments/fraud-protection'           => self::PATH_FRAUD_PROTECTION_SETTINGS,
		'/payments/multi-currency-setup'       => self::PATH_SETTINGS,
		'/payments/additional-payment-methods' => self::PATH_SETTINGS,
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

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
	 * WooPayments onboarding redirect.
	 *
	 * @var WooPaymentsOnboardingRedirect
	 */
	private WooPaymentsOnboardingRedirect $onboarding_redirect;

	/**
	 * The pages' availability for this request, and whether onboarding continues an account already started.
	 *
	 * The menu and the shared settings preload both read it, so the account is asked once per request.
	 *
	 * @var array{availability:array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>},continue_onboarding:bool}|null
	 */
	private ?array $access = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter        $arbiter             Runtime owner arbiter.
	 * @param WooPaymentsAccountService        $account_service     WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService $badge_service       WooPayments admin menu badge service.
	 * @param WooPaymentsOnboardingRedirect    $onboarding_redirect WooPayments onboarding redirect.
	 */
	final public function init(
		WooPaymentsRuntimeArbiter $arbiter,
		WooPaymentsAccountService $account_service,
		WooPaymentsAdminMenuBadgeService $badge_service,
		WooPaymentsOnboardingRedirect $onboarding_redirect
	): void {
		$this->arbiter             = $arbiter;
		$this->account_service     = $account_service;
		$this->badge_service       = $badge_service;
		$this->onboarding_redirect = $onboarding_redirect;
	}

	/**
	 * Get the route a client 11.1.0 WC Admin path redirects to, or an empty string for any other path.
	 *
	 * Static, so the onboarding redirect can use it on stores where this controller is not registered.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @param string $payments_path `/payments/*` WC Admin path, for example `/payments/overview`.
	 * @return string
	 */
	public static function get_route_for_payments_path( string $payments_path ): string {
		return self::PAYMENTS_PATH_REDIRECTS[ $payments_path ] ?? '';
	}

	/**
	 * Register admin navigation hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_items' ), self::MENU_HOOK_PRIORITY );

		add_action( 'admin_init', array( $this, 'redirect_payments_paths' ) );

		add_action( 'admin_init', array( $this->onboarding_redirect, 'maybe_redirect' ), self::ONBOARDING_REDIRECT_HOOK_PRIORITY );

		add_filter( 'submenu_file', array( $this, 'highlight_current_payments_submenu' ) );

		add_action( 'adminmenu', array( $this, 'open_payments_menu' ) );

		add_filter( 'admin_title', array( $this, 'set_admin_title' ) );
	}

	/**
	 * Name the browser tab after the WooPayments page on first paint, in WooCommerce admin's header format.
	 *
	 * A detail page's title is followed by its list's title: the same sections `admin/routes.tsx` sets after load, with each
	 * parent taken from the client's `client/index.js` breadcrumbs (client 11.1.0).
	 * Settings pages keep the WooCommerce settings title.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @param mixed $admin_title The admin page title.
	 * @return mixed
	 */
	public function set_admin_title( $admin_title ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page title.
		$current_path = $this->is_payments_settings_request() ? $this->get_request_scalar( $_GET, 'path' ) : '';
		if ( ! in_array( $current_path, self::REGISTERED_ROUTE_PATHS, true ) ) {
			return $admin_title;
		}

		$page_titles = $this->get_page_titles();
		$sections    = array();
		for ( $path = $current_path; '' !== $path; $path = substr( $path, 0, (int) strrpos( $path, '/' ) ) ) {
			if ( isset( $page_titles[ $path ] ) ) {
				$sections[] = esc_html( $page_titles[ $path ] );
			}
		}
		if ( empty( $sections ) ) {
			return $admin_title;
		}

		$sections[] = esc_html__( 'Payments', 'woocommerce' );
		return sprintf(
			/* translators: 1: The page title. 2: The name of the website. */
			esc_html__( '%1$s &lsaquo; %2$s &#8212; WooCommerce', 'woocommerce' ),
			implode( ' &lsaquo; ', $sections ),
			esc_html( get_bloginfo( 'name' ) )
		);
	}

	/**
	 * Open the Payments menu on the WooPayments pages.
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
	 * Mark the current WooPayments page's Payments submenu item; a detail page marks its list.
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
	 * Get the registered Payments submenu URL for the current WooPayments page, or an empty string.
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
	 * Redirect the `/payments/*` WC Admin paths and the `woocommerce_payments_*` settings sections to their Payments settings
	 * routes.
	 *
	 * @return void
	 */
	public function redirect_payments_paths(): void {
		if ( wp_doing_ajax() || ! current_user_can( self::CAPABILITY ) || ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for old admin links.
		$redirect_url = $this->get_payments_path_redirect_url( $_GET );
		if ( '' === $redirect_url ) {
			return;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Get the Payments settings URL a `/payments/*` WC Admin request or a `woocommerce_payments_*` section redirects to, or
	 * an empty string.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	public function get_payments_path_redirect_url( array $request ): string {
		if ( 'wc-admin' !== $this->get_request_scalar( $request, 'page' ) ) {
			return $this->get_payment_method_section_redirect_url( $request );
		}

		$payments_path = sanitize_text_field( rawurldecode( $this->get_raw_request_scalar( $request, 'path' ) ) );
		$target_path   = self::PAYMENTS_PATH_REDIRECTS[ $payments_path ] ?? '';
		if ( '' === $target_path ) {
			return '';
		}

		$route_availability = $this->get_admin_route_availability();
		if ( $this->is_payments_setup_path( $payments_path ) ) {
			$target_path = $this->get_payments_setup_path_redirect( $route_availability );
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

		// `tab` belongs to wc-settings on these routes, so an old link's tab moves to the argument each page reads.
		$tab = $query['tab'] ?? null;
		unset( $query['tab'] );
		if ( self::PAYMENTS_REPORTS_PATH === $payments_path && null !== $tab ) {
			$query['report_tab'] = $tab;
		}

		if ( self::PAYMENTS_TRANSACTIONS_PATH === $payments_path && isset( self::PAYMENTS_TRANSACTIONS_TAB_VIEWS[ (string) $tab ] ) ) {
			$query['view'] = self::PAYMENTS_TRANSACTIONS_TAB_VIEWS[ (string) $tab ];
		}

		if ( '/payments/multi-currency-setup' === $payments_path ) {
			$fragment = self::SETTINGS_FRAGMENT_ADVANCED;
		}

		if ( '/payments/additional-payment-methods' === $payments_path ) {
			$fragment = self::SETTINGS_FRAGMENT_PAYMENT_METHODS;
		}

		return Utils::wc_payments_settings_url( $target_path, $query, $fragment );
	}

	/**
	 * Get the settings URL a `woocommerce_payments_*` payment method section redirects to.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	private function get_payment_method_section_redirect_url( array $request ): string {
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
	 * Tell whether a `/payments/*` path is a setup or onboarding link.
	 *
	 * @param string $payments_path `/payments/*` WC Admin path.
	 * @return bool
	 */
	private function is_payments_setup_path( string $payments_path ): bool {
		return in_array(
			$payments_path,
			array(
				'/payments/connect',
				'/payments/onboarding',
				'/payments/onboarding/kyc',
			),
			true
		);
	}

	/**
	 * Pick the route a setup link opens: onboarding when available, else the Overview, else the settings.
	 *
	 * @param array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>} $route_availability Route availability.
	 * @return string
	 */
	private function get_payments_setup_path_redirect( array $route_availability ): string {
		if ( true === ( $route_availability['allowedRoutes'][ self::PATH_ONBOARDING ] ?? false ) ) {
			return self::PATH_ONBOARDING;
		}

		if ( true === ( $route_availability['allowedRoutes'][ self::PATH_OVERVIEW ] ?? false ) ) {
			return self::PATH_OVERVIEW;
		}

		return self::PATH_SETTINGS;
	}

	/**
	 * Pick the route a redirect opens when its target is not available: the Overview when available, else the settings.
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
	 * Tell whether the current admin request is the Payments settings page.
	 *
	 * @internal
	 * @return bool
	 */
	public function is_payments_settings_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request routing for admin shared settings.
		$page = $this->get_request_scalar( $_GET, 'page' );
		$tab  = $this->get_request_scalar( $_GET, 'tab' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab;
	}

	/**
	 * Get the WooPayments pages' availability for this request.
	 *
	 * @internal
	 * @return array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>}
	 */
	public function get_admin_route_availability(): array {
		return $this->get_access()['availability'];
	}

	/**
	 * Get the pages' availability and the onboarding menu title's account state, derived once per request.
	 *
	 * @return array{availability:array{gatewayEnabled:bool,accountState:string,allowedRoutes:array<string,bool>},continue_onboarding:bool}
	 */
	private function get_access(): array {
		if ( null !== $this->access ) {
			return $this->access;
		}

		// Client 11.1.0 shows the account's pages only with a working WordPress.com connection, and sends everything else to connect.
		$gateway_enabled  = $this->account_service->is_gateway_enabled();
		$connected        = $this->onboarding_redirect->has_working_connection();
		$restricted       = $connected && ( $this->account_service->is_account_rejected() || $this->account_service->is_account_under_review() );
		$valid_account    = $connected && $this->account_service->has_valid_account_for_admin_navigation();
		$not_ready        = $connected && ! $restricted && ! $valid_account;
		$has_account      = $not_ready && $this->account_service->has_account();
		$onboarding       = ! $connected || ( $not_ready && ( ! $has_account || ! $this->account_service->is_details_submitted() ) );
		$full_access      = $valid_account && ! $restricted;
		$reduced_access   = $restricted;
		$protected_access = $full_access || $reduced_access;

		$this->access = array(
			'availability'        => array(
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
			),
			// A connected account that started onboarding without submitting its details continues it.
			'continue_onboarding' => $onboarding && $has_account,
		);

		return $this->access;
	}

	/**
	 * Get the availability's account state: restricted, onboarding, full or unavailable.
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
	 * Add the WooPayments submenu items under the Payments menu.
	 *
	 * @return void
	 */
	public function add_menu_items(): void {
		if (
			! current_user_can( self::CAPABILITY )
			|| ! $this->arbiter->is_builtin_owner()
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
		$access = $this->get_access();

		switch ( $access['availability']['accountState'] ) {
			case 'restricted':
				return $this->get_reduced_menu_items();
			case 'onboarding':
				// Client 11.1.0 renders no sub-items without a working connection; its menu links to the connect page (class-wc-payments-admin.php:383-394).
				return array(
					array(
						'title' => $access['continue_onboarding'] ? __( 'Continue onboarding', 'woocommerce' ) : __( 'Onboarding', 'woocommerce' ),
						'path'  => self::PATH_ONBOARDING,
					),
				);
			case 'full':
				return $this->get_full_menu_items( $access['availability']['allowedRoutes'] );
			default:
				return array();
		}
	}

	/**
	 * Get the full WooPayments submenu for a valid account.
	 *
	 * @param array<string,bool> $allowed_routes The pages' availability.
	 * @return array<int,array{title:string,path:string,query?:array<string,string>,badge_count?:int}>
	 */
	private function get_full_menu_items( array $allowed_routes ): array {
		$page_titles = $this->get_page_titles();
		$menu_items  = array(
			array(
				'title' => $page_titles[ self::PATH_OVERVIEW ],
				'path'  => self::PATH_OVERVIEW,
			),
			array(
				'title' => $page_titles[ self::PATH_PAYOUTS ],
				'path'  => self::PATH_PAYOUTS,
			),
			$this->get_transactions_menu_item(),
		);

		$menu_items[] = $this->get_disputes_menu_item();

		// After Disputes, as the client registers it (client 11.1.0 `includes/admin/class-wc-payments-admin.php:370-381, 530-531`).
		if ( $allowed_routes[ self::PATH_REPORTS ] ) {
			$menu_items[] = array(
				'title' => $page_titles[ self::PATH_REPORTS ],
				'path'  => self::PATH_REPORTS,
			);
		}

		if ( $allowed_routes[ self::PATH_CARD_READERS ] ) {
			$menu_items[] = array(
				'title' => $page_titles[ self::PATH_CARD_READERS ],
				'path'  => self::PATH_CARD_READERS,
			);
		}

		if ( $allowed_routes[ self::PATH_LOANS ] ) {
			$menu_items[] = array(
				'title' => $page_titles[ self::PATH_LOANS ],
				'path'  => self::PATH_LOANS,
			);
		}

		if ( $allowed_routes[ self::PATH_DOCUMENTS ] ) {
			$menu_items[] = array(
				'title' => $page_titles[ self::PATH_DOCUMENTS ],
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
	 * Get the titles of the WooPayments pages, keyed by route path.
	 *
	 * The submenu reads the list pages' titles. The first-paint browser tab title reads them all: the same sections
	 * `admin/routes.tsx` sets, with each detail page's parent taken from the client's `client/index.js` breadcrumbs
	 * (client 11.1.0). The client's server-side parent chains differ for payout details and challenge dispute.
	 *
	 * @return array<string,string>
	 */
	private function get_page_titles(): array {
		return array(
			self::PATH_OVERVIEW            => __( 'Overview', 'woocommerce' ),
			self::PATH_PAYOUTS             => __( 'Payouts', 'woocommerce' ),
			self::PATH_PAYOUT_DETAILS      => __( 'Payout details', 'woocommerce' ),
			self::PATH_TRANSACTIONS        => __( 'Transactions', 'woocommerce' ),
			self::PATH_TRANSACTION_DETAILS => __( 'Payment details', 'woocommerce' ),
			self::PATH_DISPUTES            => __( 'Disputes', 'woocommerce' ),
			self::PATH_DISPUTE_DETAILS     => __( 'Dispute details', 'woocommerce' ),
			self::PATH_DISPUTE_CHALLENGE   => __( 'Challenge dispute', 'woocommerce' ),
			self::PATH_REPORTS             => __( 'Reports', 'woocommerce' ),
			self::PATH_CARD_READERS        => __( 'Card Readers', 'woocommerce' ),
			self::PATH_LOANS               => __( 'Capital Loans', 'woocommerce' ),
			self::PATH_DOCUMENTS           => __( 'Documents', 'woocommerce' ),
		);
	}

	/**
	 * Get the reduced WooPayments submenu for a rejected or under-review account.
	 *
	 * @return array<int,array{title:string,path:string,query?:array<string,string>,badge_count?:int}>
	 */
	private function get_reduced_menu_items(): array {
		return array(
			array(
				'title' => $this->get_page_titles()[ self::PATH_OVERVIEW ],
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
			'title' => $this->get_page_titles()[ self::PATH_TRANSACTIONS ],
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
			'title' => $this->get_page_titles()[ self::PATH_DISPUTES ],
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
	 * Append one submenu item under the Payments menu.
	 *
	 * @param string               $title       Menu title.
	 * @param string               $path        Payments settings route path.
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
	 * Get the Payments menu's slug.
	 *
	 * @return string
	 */
	private function get_parent_slug(): string {
		return 'admin.php?page=wc-settings&tab=checkout&from=' . Payments::FROM_PAYMENTS_MENU_ITEM;
	}
}
