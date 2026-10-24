<?php
/**
 * WooPaymentsAdminLinkController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingSource;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentsSettingsAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTracks;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Serves the WooPayments links that arrive as `wcpay-*` query arguments: the Stripe dashboard login link of the Overview page,
 * and the links in the platform's KYC and Capital emails.
 *
 * WooPaymentsAdminNavigationController redirects the `/payments/*` path links; this class takes the query-argument ones.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAdminLinkController implements RegisterHooksInterface {

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments admin navigation, which resolves the `/payments/*` path links.
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
	 * @param WooPaymentsApiClient                 $api_client      WooPayments API client.
	 * @param WooPaymentsAdminNavigationController $navigation      WooPayments admin navigation.
	 * @param WooPaymentsCapitalRestController     $capital         Capital controller.
	 * @param WooPaymentsAccountService            $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsAdminNavigationController $navigation, WooPaymentsCapitalRestController $capital, WooPaymentsAccountService $account_service ): void {
		$this->api_client      = $api_client;
		$this->navigation      = $navigation;
		$this->capital         = $capital;
		$this->account_service = $account_service;
	}

	/**
	 * Register the admin-link hooks.
	 *
	 * The setup tier lists this class only on connected and active admin requests, which exist only while the built-in
	 * WooPayments owns the store.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_account_link_request' ) );

		add_action( 'admin_init', array( $this, 'handle_login_request' ) );

		// Priority 9 runs before the `/payments/*` path redirect (priority 10), which would otherwise leave the connect page first.
		add_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ), 9 );

		// Priority 9 also finalizes a hosted KYC return before the `/payments/*` path redirect sends it on to Overview.
		add_action( 'admin_init', array( $this, 'handle_hosted_kyc_return' ), 9 );

		// Forward admin offer links to the Capital controller.
		add_action( 'admin_init', array( $this, 'handle_loan_offer_request' ), 12 );
	}

	/**
	 * Redirect a Capital offer email link at admin_init priority 12, as the WooPayments extension's `maybe_redirect_by_get_param()` does (client 11.1.0 `includes/class-wc-payments-account.php:767`).
	 */
	public function handle_loan_offer_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Email links carry no nonce; the controller checks the capability.
		if ( isset( $_GET['wcpay-loan-offer'] ) ) {
			$this->capital->redirect_loan_offer_request();
		}
	}

	/**
	 * Record a merchant returning from a platform KYC reminder email and continue to the built-in route for the connect page.
	 *
	 * The email links to `page=wc-admin&path=/payments/connect&wcpay-connect-redirect=<reminder>`, which the WooPayments extension's `maybe_redirect_by_get_param()` also takes (client 11.1.0 `includes/class-wc-payments-account.php:767`).
	 */
	public function handle_kyc_reminder_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Email links carry no nonce; the capability check guards this read-only redirect.
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['wcpay-connect-redirect'] ) ) {
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

		WooPaymentsTracks::record_wcadmin_event_with_tracking_info(
			'wcpay_kyc_reminder_merchant_returned',
			array(
				'offset'      => $offset,
				'description' => $description,
			)
		);

		// The WooPayments extension continues with from=WCPAY_KYC_REMINDER (client 11.1.0 `includes/class-wc-payments-redirect-service.php:60`), which the built-in onboarding keeps for attribution.
		$from         = array( 'from' => 'WCPAY_KYC_REMINDER' );
		$redirect_url = $this->navigation->get_payments_path_redirect_url( $connect_page + $from );
		wp_safe_redirect( '' !== $redirect_url ? $redirect_url : Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH, $from ) );
		exit;
	}

	/**
	 * Finalize the return from the platform's hosted KYC (`wcpay-state` and `wcpay-mode`), started by a `complete_kyc_link`.
	 *
	 * Does what the WooPayments extension's `finalize_connection()` does (client 11.1.0 `class-wc-payments-account.php:2354-2437`),
	 * reached from `maybe_handle_onboarding()` (:1866-1880). The extension's `wcpay_account_connect_finished` event is replaced by the NOX events.
	 */
	public function handle_hosted_kyc_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The platform return carries no nonce; the stored state secret is the check, as in the WooPayments extension.
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['wcpay-state'], $_GET['wcpay-mode'] ) ) {
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

		if ( get_transient( WooPaymentsAccountService::ONBOARDING_STATE_TRANSIENT ) !== $state ) {
			$this->redirect_to_connect_page( $params );
		}

		delete_transient( WooPaymentsAccountService::ONBOARDING_STATE_TRANSIENT );
		$this->account_service->clear_cache();
		wc_get_container()->get( WooPaymentsOnboardingService::class )->finish_hosted_kyc_connection( 'live' === $mode, ! $connection_error );

		$params['from'] = WooPaymentsOnboardingSource::FROM_STRIPE;
		if ( $connection_error ) {
			// The merchant left KYC early: the account exists but is not valid yet.
			$params['wcpay-connection-error'] = '1';
			$this->redirect_to_connect_page( $params );
		}

		$params['wcpay-connection-success'] = '1';
		wp_safe_redirect( Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH, $params ) );
		exit;
	}

	/**
	 * Redirect to the built-in route for the connect page, or to Overview when none resolves.
	 *
	 * @param array<string,string> $params Query arguments to carry.
	 * @return never
	 */
	private function redirect_to_connect_page( array $params ): void {
		$redirect_url = $this->navigation->get_payments_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '/payments/connect',
			) + $params
		);
		wp_safe_redirect( '' !== $redirect_url ? $redirect_url : Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH, $params ) );
		exit;
	}

	/**
	 * Create and redirect to the platform account link requested by an email URL with the `wcpay-link-handler` argument.
	 */
	public function handle_account_link_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// The GET flag is an authenticated email-link instruction, as in the WooPayments extension; it does not authorize the request.
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
	 * Does what the login branch of the WooPayments extension's `maybe_handle_onboarding()` does (client 11.1.0 `class-wc-payments-account.php:1276-1297`):
	 * an account with unsubmitted details goes to the KYC link instead, and a failure lands on Overview with `wcpay-login-error`.
	 */
	public function handle_login_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() below verifies the nonce.
		if ( ! isset( $_GET['wcpay-login'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
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
			// Drop the account cache, as the WooPayments extension does, so details edited on the dashboard show on return.
			$this->account_service->clear_cache();
			$login = $this->api_client->create_login_link( Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH ) );
			$url   = isset( $login['url'] ) && is_string( $login['url'] ) ? $login['url'] : '';
		} catch ( WooPaymentsApiException $exception ) {
			unset( $exception );
		}

		wp_safe_redirect(
			'' !== $url
				? $url
				: Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH, array( 'wcpay-login-error' => '1' ) )
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
					set_transient( WooPaymentsAccountService::ONBOARDING_STATE_TRANSIENT, $link['state'], DAY_IN_SECONDS );
				}

				wp_safe_redirect( $url );
				exit;
			}
		} catch ( WooPaymentsApiException $exception ) {
			unset( $exception );
		}

		wp_safe_redirect(
			Utils::wc_payments_settings_url(
				WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH,
				array( 'wcpay-server-link-error' => '1' )
			)
		);
		exit;
	}
}
