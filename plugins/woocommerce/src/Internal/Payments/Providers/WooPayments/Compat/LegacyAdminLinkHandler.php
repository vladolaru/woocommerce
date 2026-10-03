<?php
/**
 * LegacyAdminLinkHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingSource;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Handles platform-issued WooPayments admin links while native owns the runtime.
 *
 * @since 11.2.0
 * @internal Transitional compatibility component for the native payments runtime.
 */
class LegacyAdminLinkHandler implements RegisterHooksInterface {

	/**
	 * The plugin's `WC_Payments_Account::ONBOARDING_STATE_TRANSIENT`, holding the hosted KYC state secret.
	 */
	private const ONBOARDING_STATE_TRANSIENT = 'wcpay_stripe_onboarding_state';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native WooPayments admin navigation, which resolves legacy admin routes.
	 *
	 * @var WooPaymentsAdminNavigationController
	 */
	private WooPaymentsAdminNavigationController $navigation;

	/**
	 * Capital controller, which builds the loan offer link.
	 *
	 * @var WooPaymentsCapitalRestController
	 */
	private WooPaymentsCapitalRestController $capital;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Platform tracking info reader.
	 *
	 * @var WooPaymentsTrackingInfoService
	 */
	private WooPaymentsTrackingInfoService $tracking_info;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter         $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsApiClient                 $api_client      Native WooPayments API client.
	 * @param WooPaymentsAdminNavigationController $navigation      Native WooPayments admin navigation.
	 * @param WooPaymentsCapitalRestController     $capital         Capital controller.
	 * @param WooPaymentsAccountService            $account_service WooPayments account service.
	 * @param WooPaymentsTrackingInfoService       $tracking_info   Platform tracking info reader.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsAdminNavigationController $navigation, WooPaymentsCapitalRestController $capital, WooPaymentsAccountService $account_service, WooPaymentsTrackingInfoService $tracking_info ): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->navigation      = $navigation;
		$this->capital         = $capital;
		$this->account_service = $account_service;
		$this->tracking_info   = $tracking_info;
	}

	/**
	 * Register the legacy admin-link hook.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'admin_init', array( $this, 'handle_request' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_request' ) );
		}

		if ( false === has_action( 'admin_init', array( $this, 'handle_login_request' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_login_request' ) );
		}

		if ( false === has_action( 'admin_init', array( $this, 'handle_reconnect_wpcom_request' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_reconnect_wpcom_request' ) );
		}

		// Priority 9 runs before the legacy route redirect, which would otherwise leave the connect page first.
		if ( false === has_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ), 9 );
		}

		// Priority 9 also finalizes a hosted KYC return before the legacy route redirect sends it on to Overview.
		if ( false === has_action( 'admin_init', array( $this, 'handle_hosted_kyc_return' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_hosted_kyc_return' ), 9 );
		}

		// The Capital controller loads only for REST requests, after admin_init, so its own hook never ran.
		if ( false === has_action( 'admin_init', array( $this, 'handle_loan_offer_request' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_loan_offer_request' ), 12 );
		}
	}

	/**
	 * Redirect a Capital offer email link, like the plugin's `maybe_redirect_by_get_param()` at admin_init priority 12 (11.1.0).
	 */
	public function handle_loan_offer_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email links carry no nonce; the controller checks the capability.
		if ( isset( $_GET['wcpay-loan-offer'] ) ) {
			$this->capital->redirect_loan_offer_request();
		}
	}

	/**
	 * Record a merchant returning from a platform KYC reminder email and continue to the native route for the connect page.
	 *
	 * The email links to `page=wc-admin&path=/payments/connect&wcpay-connect-redirect=<reminder>`, matching the plugin's `maybe_redirect_by_get_param()` (11.1.0).
	 */
	public function handle_kyc_reminder_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Email links carry no nonce; the capability check guards this read-only redirect.
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || ! $this->arbiter->should_native_register() || ! isset( $_GET['wcpay-connect-redirect'] ) ) {
			return;
		}

		$connect_page = array(
			'page' => 'wc-admin',
			'path' => '/payments/connect',
		);
		foreach ( $connect_page as $key => $value ) {
			if ( wc_clean( wp_unslash( $_GET[ $key ] ?? '' ) ) !== $value ) {
				return;
			}
		}

		$reminder = sanitize_text_field( wp_unslash( $_GET['wcpay-connect-redirect'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( in_array( $reminder, array( 'initial', 'second' ), true ) ) {
			$offset      = 'initial' === $reminder ? 1 : 3;
			$description = $reminder;
		} else {
			$week        = in_array( $reminder, array( '1', '2', '3', '4' ), true ) ? $reminder : '0';
			$offset      = (int) $week * 7;
			$description = 'weekly-' . $week;
		}

		if ( function_exists( 'wc_admin_record_tracks_event' ) ) {
			wc_admin_record_tracks_event(
				'wcpay_kyc_reminder_merchant_returned',
				array(
					'offset'      => $offset,
					'description' => $description,
				)
			);
		}

		// The plugin continues with from=WCPAY_KYC_REMINDER (11.1.0 `redirect_to_wcpay_connect()`), which native onboarding keeps for attribution.
		$from         = array( 'from' => 'WCPAY_KYC_REMINDER' );
		$redirect_url = $this->navigation->get_legacy_payment_path_redirect_url( $connect_page + $from );
		wp_safe_redirect( '' !== $redirect_url ? $redirect_url : Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, $from ) );
		exit;
	}

	/**
	 * Finalize the return from the platform's hosted KYC (`wcpay-state` and `wcpay-mode`), started by a `complete_kyc_link`.
	 *
	 * Mirrors the plugin's `finalize_connection()` (11.1.0 `class-wc-payments-account.php:2354-2437`), reached from
	 * `maybe_handle_onboarding()` (:1866-1880). The plugin's `wcpay_account_connect_finished` event is superseded by NOX events.
	 */
	public function handle_hosted_kyc_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The platform return carries no nonce; the stored state secret is the check, like the plugin.
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || ! $this->arbiter->should_native_register() || ! isset( $_GET['wcpay-state'], $_GET['wcpay-mode'] ) ) {
			return;
		}

		$state            = sanitize_text_field( wp_unslash( $_GET['wcpay-state'] ) );
		$mode             = sanitize_text_field( wp_unslash( $_GET['wcpay-mode'] ) );
		$connection_error = ! empty( $_GET['wcpay-connection-error'] );
		$params           = array(
			'from'   => WooPaymentsOnboardingSource::get_from(),
			'source' => WooPaymentsOnboardingSource::get_source(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( get_transient( self::ONBOARDING_STATE_TRANSIENT ) !== $state ) {
			$this->redirect_to_connect_page( $params );
		}

		delete_transient( self::ONBOARDING_STATE_TRANSIENT );
		$this->account_service->clear_cache();
		wc_get_container()->get( WooPaymentsService::class )->finalize_native_hosted_kyc_connection( 'live' === $mode );

		$params['from'] = WooPaymentsOnboardingSource::FROM_STRIPE;
		if ( $connection_error ) {
			// The merchant left KYC early: the account exists but is not valid yet.
			$params['wcpay-connection-error'] = '1';
			$this->redirect_to_connect_page( $params );
		}

		$params['wcpay-connection-success'] = '1';
		wp_safe_redirect( Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, $params ) );
		exit;
	}

	/**
	 * Redirect to the native route for the plugin's connect page, or to Overview when none resolves.
	 *
	 * @param array<string,string> $params Query arguments to carry.
	 * @return never
	 */
	private function redirect_to_connect_page( array $params ): void {
		$redirect_url = $this->navigation->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '/payments/connect',
			) + $params
		);
		wp_safe_redirect( '' !== $redirect_url ? $redirect_url : Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, $params ) );
		exit;
	}

	/**
	 * Create and redirect to the platform account link requested by a legacy email URL.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->arbiter->should_native_register() ) {
			return;
		}

		// The GET flag is an authenticated email-link instruction, matching the standalone plugin behavior; it does not authorize the request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcpay-link-handler'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The capability check above is the authorization boundary for this email-link flow.
		$args = wc_clean( wp_unslash( $_GET ) );
		$args = is_array( $args ) ? $args : array();
		unset( $args['wcpay-link-handler'] );

		$this->redirect_to_account_link( $args );
	}

	/**
	 * Redirect the Overview "Edit details" link (`wcpay-login`) to the Stripe dashboard.
	 *
	 * Mirrors the plugin's `maybe_handle_onboarding()` login branch (11.1.0 `class-wc-payments-account.php:1276-1297`):
	 * an account with unsubmitted details goes to the KYC link instead, and a failure lands on Overview with `wcpay-login-error`.
	 */
	public function handle_login_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() below verifies the nonce.
		if ( ! isset( $_GET['wcpay-login'] ) || ! current_user_can( 'manage_woocommerce' ) || ! $this->arbiter->should_native_register() ) {
			return;
		}

		check_admin_referer( 'wcpay-login' );

		if ( $this->account_service->has_account() && ! $this->account_service->is_details_submitted() ) {
			$args = wc_clean( wp_unslash( $_GET ) );
			$args = is_array( $args ) ? $args : array();
			unset( $args['wcpay-login'], $args['_wpnonce'] );
			$args['type'] = 'complete_kyc_link';

			$this->redirect_to_account_link( $args );
		}

		$url = '';
		try {
			// Like the plugin, drop the account cache so details edited on the dashboard show on return.
			$this->account_service->clear_cache();
			$login = $this->api_client->create_login_link( Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH ) );
			$url   = isset( $login['url'] ) && is_string( $login['url'] ) ? $login['url'] : '';
		} catch ( WooPaymentsApiException $exception ) {
			unset( $exception );
		}

		wp_safe_redirect(
			'' !== $url
				? $url
				: Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, array( 'wcpay-login-error' => '1' ) )
		);
		exit;
	}

	/**
	 * Restart the WordPress.com connection when its owner is gone (`wcpay-reconnect-wpcom`).
	 *
	 * Mirrors the plugin's `maybe_handle_onboarding()` reconnect branch and `WC_Payments_Http::start_connection()`
	 * (11.1.0 `class-wc-payments-account.php:1300-1318`, `class-wc-payments-http.php:186-212`). The plugin fatals when
	 * site registration fails; native lands on Overview with `wcpay-server-link-error` instead.
	 */
	public function handle_reconnect_wpcom_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() below verifies the nonce.
		if ( ! isset( $_GET['wcpay-reconnect-wpcom'] ) || ! current_user_can( 'manage_woocommerce' ) || ! $this->arbiter->should_native_register() ) {
			return;
		}

		check_admin_referer( 'wcpay-reconnect-wpcom' );

		$manager = JetpackConnection::get_manager();
		$this->record_wpcom_connection_start( $manager );

		if ( ! $manager->is_connected() ) {
			$result = $manager->try_registration();
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH, array( 'wcpay-server-link-error' => '1' ) ) );
				exit;
			}
		}

		add_filter( 'jetpack_use_iframe_authorization_flow', '__return_false' );
		// Same logic as in WC-Admin.
		$calypso_env = defined( 'WOOCOMMERCE_CALYPSO_ENVIRONMENT' ) && in_array( WOOCOMMERCE_CALYPSO_ENVIRONMENT, array( 'development', 'wpcalypso', 'horizon', 'stage' ), true ) ? WOOCOMMERCE_CALYPSO_ENVIRONMENT : 'production';
		// The plugin returns to its settings page, which is the native WooPayments settings route.
		$authorization_url = $manager->get_authorization_url( null, Utils::wc_payments_settings_url( '/woopayments/settings' ) );

		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_jetpack_redirect_host' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'from'        => 'woocommerce-core-profiler',
					// Same identity as native onboarding (`Utils::get_wpcom_connection_authorization()`).
					'plugin_name' => Constants::is_true( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' ) ? 'woocommerce-payments' : 'woocommerce',
					'calypso_env' => $calypso_env,
				),
				is_string( $authorization_url ) ? $authorization_url : ''
			)
		);
		exit;
	}

	/**
	 * Allow the Jetpack authorization host, like the plugin's `WC_Payments_Http::allowed_redirect_hosts()` (11.1.0).
	 *
	 * @internal
	 *
	 * @param mixed $hosts Allowed redirect hosts.
	 * @return mixed
	 */
	public function allow_jetpack_redirect_host( $hosts ) {
		if ( is_array( $hosts ) && ! in_array( 'jetpack.wordpress.com', $hosts, true ) ) {
			$hosts[] = 'jetpack.wordpress.com';
		}

		return $hosts;
	}

	/**
	 * Record the plugin's `wcpay_account_connect_wpcom_connection_start` event for a reconnect.
	 *
	 * Like the plugin's `tracks_event()`, the cached platform tracking info is merged last.
	 *
	 * @param \Automattic\Jetpack\Connection\Manager $manager Jetpack connection manager.
	 */
	private function record_wpcom_connection_start( $manager ): void {
		if ( ! function_exists( 'wc_admin_record_tracks_event' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The caller verified the nonce.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';

		wc_admin_record_tracks_event(
			'wcpay_account_connect_wpcom_connection_start',
			array_merge(
				array(
					'is_reconnect'      => true,
					'from'              => $from,
					'is_test_mode'      => $this->account_service->is_test_mode_enabled(),
					'jetpack_connected' => $manager->is_connected() && $manager->has_connected_owner(),
					'wcpay_version'     => WooPaymentsClientVersion::VERSION,
					'woo_country_code'  => WC()->countries->get_base_country(),
				),
				$this->tracking_info->get_tracking_info() ?? array()
			)
		);
	}

	/**
	 * Create a platform account link and redirect to it, or to the Overview link error.
	 *
	 * @param array<string,mixed> $args Account-link arguments.
	 * @return never
	 */
	private function redirect_to_account_link( array $args ): void {
		try {
			$link = $this->api_client->create_account_link( $args );
			$url  = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';

			if ( '' !== $url ) {
				if ( 'complete_kyc_link' === ( $args['type'] ?? '' ) && isset( $link['state'] ) ) {
					set_transient( self::ONBOARDING_STATE_TRANSIENT, $link['state'], DAY_IN_SECONDS );
				}

				wp_safe_redirect( $url );
				exit;
			}
		} catch ( WooPaymentsApiException $exception ) {
			unset( $exception );
		}

		wp_safe_redirect(
			Utils::wc_payments_settings_url(
				WooPaymentsService::OVERVIEW_PATH,
				array( 'wcpay-server-link-error' => '1' )
			)
		);
		exit;
	}
}
