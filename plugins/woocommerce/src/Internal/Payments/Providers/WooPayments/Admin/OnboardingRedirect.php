<?php
/**
 * OnboardingRedirect class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\OnboardingSource;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a merchant from a WooPayments page to onboarding when the store has no working connection or no valid account.
 *
 * Ports client 11.1.0 `WC_Payments_Admin::maybe_redirect_from_payments_admin_child_pages()` and the Overview and
 * settings redirects in `WC_Payments_Account`. The navigation controller calls it on connected stores, and the Payments
 * settings controller, through the adapter, on every store, so every setup tier gets the same redirect.
 * While the WooPayments extension owns payments, a link to a settings route opens the WooPayments extension's settings
 * section instead.
 *
 * @since 11.2.0
 * @internal
 */
class OnboardingRedirect {

	private const PATH_ONBOARDING = '/woopayments/onboarding';

	private const PATH_OVERVIEW = '/woopayments/overview';

	private const PATH_SETTINGS = '/woopayments/settings';

	/**
	 * Client 11.1.0 WC Admin paths start with this prefix; they live on in old emails, notes and bookmarks.
	 */
	private const PAYMENTS_PATH_PREFIX = '/payments/';

	/**
	 * Client 11.1.0 onboarding wizard path, which `maybe_redirect_from_onboarding_wizard_page()` tracks as its own origin.
	 */
	private const PAYMENTS_ONBOARDING_PATH = '/payments/onboarding';

	/**
	 * Client 11.1.0 settings section; `woocommerce_payments_<method>` sections redirect to it first.
	 */
	private const GATEWAY_SETTINGS_SECTION = 'woocommerce_payments';

	/**
	 * Payments settings routes that need a working connection and a valid account, matched by prefix.
	 *
	 * Mirrors the client 11.1.0 Payments child pages plus its settings page, which redirect to onboarding
	 * otherwise. Detail and settings sub-routes match through their parent prefix, as the client 11.1.0 pattern has no end
	 * anchor (`includes/admin/class-wc-payments-admin.php:1280`).
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
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

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
	 * @param WooPaymentsRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param Client                    $api_client      WooPayments API client.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, Client $api_client, WooPaymentsAccountService $account_service ): void {
		$this->arbiter         = $arbiter;
		$this->api_client      = $api_client;
		$this->account_service = $account_service;
	}

	/**
	 * Get the guarded route a request asks for, or an empty string when it asks for none.
	 *
	 * Also covers the old links: the `woocommerce_payments` settings sections and the WC Admin `/payments/*` paths.
	 * Static and reads only the request, so a caller can check it before resolving this class.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	public static function get_guarded_path( array $request ): string {
		$page = $request['page'] ?? '';
		if ( 'wc-admin' === $page ) {
			return self::get_guarded_payments_path( $request );
		}

		if ( 'wc-settings' !== $page || 'checkout' !== ( $request['tab'] ?? '' ) ) {
			return '';
		}

		if ( isset( $request['path'] ) && is_string( $request['path'] ) ) {
			return self::match_guarded_path( wp_unslash( $request['path'] ) );
		}

		$section = $request['section'] ?? '';
		if ( is_string( $section ) && ( self::GATEWAY_SETTINGS_SECTION === $section || str_starts_with( $section, self::GATEWAY_SETTINGS_SECTION . '_' ) ) ) {
			return self::PATH_SETTINGS;
		}

		return '';
	}

	/**
	 * Get the guarded route for a client 11.1.0 WC Admin `/payments/*` link, or an empty string.
	 *
	 * The setup links map to onboarding, which is guarded only here: an onboarding route request must not redirect to itself.
	 *
	 * @param array<string,mixed> $request Query request.
	 * @return string
	 */
	private static function get_guarded_payments_path( array $request ): string {
		if ( ! isset( $request['path'] ) || ! is_string( $request['path'] ) ) {
			return '';
		}

		$payments_path = wp_unslash( $request['path'] );
		if ( ! str_starts_with( $payments_path, self::PAYMENTS_PATH_PREFIX ) ) {
			return '';
		}

		$route = NavigationController::get_route_for_payments_path( $payments_path );
		if ( self::PATH_ONBOARDING === $route ) {
			return self::PATH_ONBOARDING;
		}

		return self::match_guarded_path( $route );
	}

	/**
	 * Get the guarded route a Payments settings route path falls under, or an empty string.
	 *
	 * @param string $current_path Payments settings route path.
	 * @return string
	 */
	private static function match_guarded_path( string $current_path ): string {
		if ( '' === $current_path ) {
			return '';
		}

		foreach ( self::GUARDED_PATHS as $path ) {
			if ( str_starts_with( $current_path, $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Tell whether the WordPress.com connection works.
	 *
	 * Client 11.1.0 `has_working_jetpack_connection()`: a connected site with a connection owner.
	 *
	 * @internal
	 * @return bool
	 */
	public function has_working_connection(): bool {
		return $this->api_client->is_available();
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect for the WooPayments routes and old links.
		$guarded_path   = self::get_guarded_path( $_GET );
		$requested_path = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $guarded_path || $this->decided ) {
			return;
		}

		$this->decided = true;

		if ( ! $this->arbiter->is_builtin_owner() ) {
			// While the WooPayments extension owns payments these routes are not served: a settings route link opens its
			// settings page, the URL WooPayments::get_settings_url() gives there (client 11.1.0
			// `class-wc-payments-admin-settings.php:32-36`). The section link itself carries no path, so it is left alone.
			if ( self::PATH_SETTINGS === $guarded_path && str_starts_with( $requested_path, self::PATH_SETTINGS ) && $this->arbiter->is_extension_owner() ) {
				wp_safe_redirect( Utils::wc_payments_settings_url( null, array( 'section' => self::GATEWAY_SETTINGS_SECTION ) ) );
				exit;
			}

			return;
		}

		// A working connection and a valid account, as client 11.1.0 checks (`includes/admin/class-wc-payments-admin.php:1285`);
		// no connection skips the account read.
		if ( $this->has_working_connection() && $this->account_service->has_valid_account_for_admin_navigation() ) {
			return;
		}

		$query = array();
		if ( self::PATH_OVERVIEW === $guarded_path ) {
			$query['from']   = OnboardingSource::FROM_OVERVIEW_PAGE;
			$query['source'] = OnboardingSource::get_source();
		} elseif ( self::PATH_SETTINGS === $guarded_path ) {
			$query['from']   = OnboardingSource::FROM_WCADMIN_PAYMENTS_SETTINGS;
			$query['source'] = OnboardingSource::SOURCE_WCADMIN_SETTINGS_PAGE;
		} elseif ( self::PATH_ONBOARDING === $guarded_path && self::PAYMENTS_ONBOARDING_PATH === $requested_path ) {
			$query['from']   = OnboardingSource::FROM_ONBOARDING_WIZARD;
			$query['source'] = OnboardingSource::get_source();
		}

		wp_safe_redirect( Utils::wc_payments_settings_url( self::PATH_ONBOARDING, $query ) );
		exit;
	}
}
