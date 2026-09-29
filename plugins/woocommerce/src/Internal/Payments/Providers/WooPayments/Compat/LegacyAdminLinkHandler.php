<?php
/**
 * LegacyAdminLinkHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Handles platform-issued WooPayments admin links while native owns the runtime.
 *
 * @since 11.2.0
 * @internal Transitional compatibility component for the native payments runtime.
 */
class LegacyAdminLinkHandler implements RegisterHooksInterface {

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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter         $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsApiClient                 $api_client      Native WooPayments API client.
	 * @param WooPaymentsAdminNavigationController $navigation      Native WooPayments admin navigation.
	 * @param WooPaymentsCapitalRestController     $capital         Capital controller.
	 * @param WooPaymentsAccountService            $account_service WooPayments account service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsAdminNavigationController $navigation, WooPaymentsCapitalRestController $capital, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->navigation      = $navigation;
		$this->capital         = $capital;
		$this->account_service = $account_service;
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

		// Priority 9 runs before the legacy route redirect, which would otherwise leave the connect page first.
		if ( false === has_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ), 9 );
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
					set_transient( 'wcpay_stripe_onboarding_state', $link['state'], DAY_IN_SECONDS );
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
