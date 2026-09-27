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
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter         $arbiter    Runtime owner arbiter.
	 * @param WooPaymentsApiClient                 $api_client Native WooPayments API client.
	 * @param WooPaymentsAdminNavigationController $navigation Native WooPayments admin navigation.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsAdminNavigationController $navigation ): void {
		$this->arbiter    = $arbiter;
		$this->api_client = $api_client;
		$this->navigation = $navigation;
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

		// Priority 9 runs before the legacy route redirect, which would otherwise leave the connect page first.
		if ( false === has_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ) ) ) {
			add_action( 'admin_init', array( $this, 'handle_kyc_reminder_return' ), 9 );
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
