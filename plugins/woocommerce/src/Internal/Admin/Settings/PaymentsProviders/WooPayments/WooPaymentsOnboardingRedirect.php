<?php
/**
 * WooPaymentsOnboardingRedirect class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

defined( 'ABSPATH' ) || exit;

/**
 * Sends merchants from native WooPayments admin pages to onboarding without a working connection and a valid account.
 *
 * Ports client 11.1.0 `WC_Payments_Admin::maybe_redirect_from_payments_admin_child_pages()` and the Overview and
 * settings redirects in `WC_Payments_Account`. The admin navigation controller and the Payments settings controller
 * both call it, so every native payments state gets the same redirect.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOnboardingRedirect {

	private const PATH_ONBOARDING = '/woopayments/onboarding';

	private const PATH_OVERVIEW = '/woopayments/overview';

	private const PATH_SETTINGS = '/woopayments/settings';

	/**
	 * Native routes that need a working connection and a valid account, matched by prefix.
	 *
	 * Mirrors the client 11.1.0 Payments child pages plus its settings page, which redirect to onboarding
	 * otherwise. Detail and settings sub-routes match through their parent prefix, like the client's unanchored regex.
	 */
	private const GUARDED_PATHS = array(
		self::PATH_OVERVIEW,
		'/woopayments/payouts',
		'/woopayments/transactions',
		'/woopayments/reports',
		'/woopayments/disputes',
		'/woopayments/card-readers',
		'/woopayments/loans',
		'/woopayments/documents',
		self::PATH_SETTINGS,
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Whether this request already made the redirect decision.
	 *
	 * @var bool
	 */
	private bool $decided = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsApiClient         $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService    $account_service WooPayments account service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Get the guarded native route a request asks for, or an empty string when it asks for none.
	 *
	 * Reads only the request, so callers can skip resolving this class on every other page.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	public static function get_guarded_path( array $request ): string {
		if (
			'wc-settings' !== ( $request['page'] ?? '' )
			|| 'checkout' !== ( $request['tab'] ?? '' )
			|| ! isset( $request['path'] )
			|| ! is_string( $request['path'] )
		) {
			return '';
		}

		$current_path = wp_unslash( $request['path'] );
		foreach ( self::GUARDED_PATHS as $path ) {
			if ( str_starts_with( $current_path, $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Redirect to onboarding when the current request asks for a guarded route without a working connection and a valid account.
	 *
	 * Decides once per request, so a second caller in the same request does nothing.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect for native admin routes.
		$guarded_path = self::get_guarded_path( $_GET );
		if ( '' === $guarded_path || $this->decided ) {
			return;
		}

		$this->decided = true;

		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		// Client `has_working_jetpack_connection() && is_stripe_account_valid()`; no connection skips the account read.
		if ( $this->api_client->is_available() && $this->account_service->has_valid_account_for_admin_navigation() ) {
			return;
		}

		$query = array();
		if ( self::PATH_OVERVIEW === $guarded_path ) {
			$query['from']   = WooPaymentsOnboardingSource::FROM_OVERVIEW_PAGE;
			$query['source'] = WooPaymentsOnboardingSource::get_source();
		} elseif ( self::PATH_SETTINGS === $guarded_path ) {
			$query['from']   = WooPaymentsOnboardingSource::FROM_WCADMIN_PAYMENTS_SETTINGS;
			$query['source'] = WooPaymentsOnboardingSource::SOURCE_WCADMIN_SETTINGS_PAGE;
		}

		wp_safe_redirect( Utils::wc_payments_settings_url( self::PATH_ONBOARDING, $query ) );
		exit;
	}
}
