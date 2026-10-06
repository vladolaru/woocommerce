<?php
/**
 * WooPaymentsAccountService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use RuntimeException;

/**
 * Reads Core-owned WooPayments account readiness from preserved persisted data.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAccountService implements RegisterHooksInterface {

	/**
	 * Countries where WooPayments platform accounts are supported, in extension order.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_COUNTRY_CODES = array(
		'AE',
		'AT',
		'AU',
		'BE',
		'BG',
		'CA',
		'CH',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'FI',
		'ES',
		'FR',
		'HR',
		'JP',
		'LU',
		'GB',
		'GR',
		'HK',
		'HU',
		'IE',
		'IT',
		'LT',
		'LV',
		'MT',
		'NL',
		'NO',
		'NZ',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
		'SG',
		'US',
		'PR',
	);

	private const ACCOUNT_OPTION = 'wcpay_account_data';

	private const ONBOARDING_FIELDS_DATA_OPTION = 'wcpay_onboarding_fields_data';

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const REPORTS_AREA_FLAG_OPTION = '_wcpay_feature_reports_area';

	private const ONBOARDING_TEST_MODE_OPTION = 'wcpay_onboarding_test_mode';

	private const ONBOARDING_DISABLED_TRANSIENT = 'wcpay_on_boarding_disabled';

	private const ONBOARDING_STRIPE_CONNECTED_OPTION = '_wcpay_onboarding_stripe_connected';

	private const ONBOARDING_CONNECTION_SUCCESS_MODAL_OPTION = 'wcpay_connection_success_modal_dismissed';

	private const ONBOARDING_STATE_TRANSIENT = 'wcpay_stripe_onboarding_state';

	private const EMBEDDED_KYC_IN_PROGRESS_OPTION = 'wcpay_onboarding_embedded_kyc_in_progress';

	private const WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT = 'woopay_enabled_by_default';

	private const ONBOARDING_INIT_IN_PROGRESS_TRANSIENT = 'wcpay_onboarding_init_in_progress';

	private const TEST_MODE_ENABLED_DATE_OPTION = 'wcpay_test_mode_enabled_date';

	private const TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT = 'wcpay_test_to_live_eligible';

	private const POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT = 'wcpay_post_kyc_activation_eligible';

	private const NOX_PROFILE_OPTION = 'woocommerce_woopayments_nox_profile';

	private const NOX_ONBOARDING_LOCKED_OPTION = 'woocommerce_woopayments_nox_onboarding_locked';

	private const ACCOUNT_DELETION_PENDING_OPTION = 'wcpay_account_deletion_pending_id';

	private const INCENTIVES_USAGE_OPTION = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments';

	private const INCENTIVES_USAGE_VERSION_OPTION = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version';

	private const DATABASE_CACHE_OPTIONS = array(
		self::ACCOUNT_OPTION,
		'wcpay_address_autocomplete_jwt',
		self::ONBOARDING_FIELDS_DATA_OPTION,
		'wcpay_business_types_data',
		'wcpay_fraud_services_data',
		'wcpay_recommended_payment_methods',
		'wcpay_dispute_status_counts_cache',
		'wcpay_test_dispute_status_counts_cache',
		'wcpay_active_dispute_cache',
		'wcpay_authorization_summary_cache',
		'wcpay_test_authorization_summary_cache',
		'wcpay_connect_incentive',
		'wcpay_tracking_info_cache',
	);

	private const ACCOUNT_CACHE_ADMIN_TTL = 2 * HOUR_IN_SECONDS;

	private const ACCOUNT_CACHE_FRONTEND_TTL = DAY_IN_SECONDS;

	private const ONBOARDING_FIELDS_DATA_TTL = WEEK_IN_SECONDS;

	private const DATABASE_CACHE_ERRORED_TTL_LADDER = array(
		2 * MINUTE_IN_SECONDS,
		5 * MINUTE_IN_SECONDS,
		10 * MINUTE_IN_SECONDS,
		15 * MINUTE_IN_SECONDS,
	);

	private const DEV_MODE_ENVIRONMENTS = array(
		'development',
		'staging',
	);

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Split gateway settings repository.
	 *
	 * @var WooPaymentsGatewaySettingsSynchronizer|null
	 */
	private ?WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer = null;

	/**
	 * Durable native payments state store.
	 *
	 * @var NativePaymentsState|null
	 */
	private ?NativePaymentsState $native_payments_state = null;

	/**
	 * Native payments runtime arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter|null
	 */
	private ?NativePaymentsRuntimeArbiter $runtime_arbiter = null;

	/**
	 * In-request account cache contents keyed by blog ID.
	 *
	 * @var array<int,array<string,mixed>|false>
	 */
	private array $account_cache = array();

	/**
	 * In-request onboarding fields cache contents keyed by blog ID.
	 *
	 * @var array<int,array<string,mixed>|false>
	 */
	private array $onboarding_fields_cache = array();

	/**
	 * Whether database cache refreshes are disabled for this request.
	 *
	 * @var bool
	 */
	private bool $refresh_disabled = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy                                 $legacy_proxy                  Legacy proxy.
	 * @param WooPaymentsGatewaySettingsSynchronizer|null $gateway_settings_synchronizer Optional split settings repository.
	 * @param NativePaymentsState|null                    $native_payments_state         Optional durable native payments state store.
	 * @param NativePaymentsRuntimeArbiter|null           $runtime_arbiter               Optional native payments runtime arbiter.
	 */
	final public function init(
		LegacyProxy $legacy_proxy,
		?WooPaymentsGatewaySettingsSynchronizer $gateway_settings_synchronizer = null,
		?NativePaymentsState $native_payments_state = null,
		?NativePaymentsRuntimeArbiter $runtime_arbiter = null
	): void {
		$this->legacy_proxy                  = $legacy_proxy;
		$this->gateway_settings_synchronizer = $gateway_settings_synchronizer;
		$this->native_payments_state         = $native_payments_state;
		$this->runtime_arbiter               = $runtime_arbiter;
	}

	/**
	 * Register account cache hooks.
	 */
	public function register() {
		if ( false === has_action( 'action_scheduler_before_execute', array( $this, 'disable_refresh' ) ) ) {
			add_action( 'action_scheduler_before_execute', array( $this, 'disable_refresh' ) );
		}

		if ( false === has_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) ) ) {
			add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );
		}

		// Like client 11.1.0, drop the account cache when the WordPress.com connection is made or removed.
		foreach ( array( 'jetpack_site_registered', 'jetpack_site_disconnected' ) as $hook_name ) {
			if ( false === has_action( $hook_name, array( $this, 'clear_cache' ) ) ) {
				add_action( $hook_name, array( $this, 'clear_cache' ) );
			}
		}
	}

	/**
	 * Add preserved WooPayments external redirect hosts.
	 *
	 * @param mixed $hosts Allowed redirect hosts.
	 * @return mixed
	 */
	public function allowed_redirect_hosts( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return $hosts;
		}

		if ( ! in_array( 'connect.stripe.com', $hosts, true ) ) {
			$hosts[] = 'connect.stripe.com';
		}

		return $hosts;
	}

	/**
	 * Disable account and onboarding fields cache refreshes for this request.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function disable_refresh(): void {
		$this->refresh_disabled = true;
	}

	/**
	 * Get normalized WooPayments account cache data.
	 *
	 * Without a platform connection this returns no account and leaves the cache alone, like the client. While plugins
	 * load the connection state is not known yet, so this serves the cache as it is, with no fetch and no write.
	 *
	 * @param bool $force_refresh Whether to force a live account refresh.
	 * @return array<string,mixed>
	 */
	public function get_cached_account_data( bool $force_refresh = false ): array {
		$connection_state_known = $this->is_connection_state_known();
		if ( $connection_state_known && ! $this->is_platform_connected() ) {
			return array();
		}

		$cache_contents = $this->get_account_cache();
		$data           = null;
		$old_data       = null;

		if (
			is_array( $cache_contents )
			&& array_key_exists( 'data', $cache_contents )
			&& $this->is_valid_cached_account( $cache_contents['data'] )
		) {
			$data     = $cache_contents['data'];
			$old_data = $data;
		}

		if ( $connection_state_known && $this->should_refresh_account_cache( $cache_contents, $force_refresh ) ) {
			$data      = $this->fetch_account_data();
			$errored   = false === $data;
			$refreshed = ! $errored;

			if ( $errored ) {
				$data = $old_data;
			}

			$this->write_account_cache( $data, $errored );

			if ( $refreshed ) {
				/**
				 * Allows native WooPayments integrations to react when account data is refreshed.
				 *
				 * @since 11.0.0
				 *
				 * @param array<string,mixed> $account_data Refreshed WooPayments account data.
				 */
				do_action( 'woocommerce_payments_account_refreshed', $data );
			}
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Refetch WooPayments account data and persist the refreshed account cache.
	 *
	 * @return array<string,mixed>
	 */
	public function refresh_account_data(): array {
		return $this->get_cached_account_data( true );
	}

	/**
	 * Refetch WooPayments account data and fail when fresh data cannot be fetched.
	 *
	 * Normal account reads intentionally fall back to stale data on provider failures for storefront stability. The
	 * `account.deleted` webhook keeps this strict path so a failed refresh redelivers instead of acknowledging stale
	 * account state, which its deletion-retry marker depends on (see {@see WooPaymentsAccountEventHandler::process()}).
	 * `account.updated` uses the non-strict {@see self::refresh_account_data()} instead (C25).
	 *
	 * @since 11.0.0
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When fresh account data cannot be fetched.
	 */
	public function refresh_account_data_strict(): array {
		$data = $this->fetch_account_data();
		if ( false === $data ) {
			throw new WooPaymentsApiException( 'Unable to refresh WooPayments account data.', 'wcpay_account_refresh_failed', 500 );
		}

		$cache_contents = $this->build_account_cache_contents( $data, false );
		$this->persist_account_cache( $cache_contents );
		if ( ! $this->is_persisted_account_cache( $cache_contents ) ) {
			throw new WooPaymentsApiException( 'Unable to persist refreshed WooPayments account data.', 'wcpay_account_refresh_persist_failed', 500 );
		}

		/**
		 * Allows native WooPayments integrations to react when account data is refreshed.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $data Refreshed WooPayments account data.
		 */
		do_action( 'woocommerce_payments_account_refreshed', $data );

		return $data;
	}

	/**
	 * Tell whether the site can reach the WooPayments platform, like the client's `is_server_connected()`.
	 *
	 * @since 11.2.0
	 * @return bool
	 */
	public function is_platform_connected(): bool {
		$api_client = $this->get_api_client();

		return null !== $api_client && $api_client->is_available();
	}

	/**
	 * Get the onboarding fields data, cached like the client's `WC_Payments_Onboarding_Service::get_fields_data()`.
	 *
	 * Successful data is cached for a week under the client's option and shape, with the locale stored in the data so a
	 * different locale refetches. Errors back off for minutes and keep the old data. Without a platform connection this
	 * serves whatever is cached, regardless of expiry.
	 *
	 * @since 11.2.0
	 * @param string $locale The locale to translate the fields data into.
	 * @return array<string,mixed>|null The fields data, or null when it could not be retrieved and nothing valid is cached.
	 */
	public function get_onboarding_fields_data( string $locale ): ?array {
		$cache_contents = $this->get_onboarding_fields_cache();

		if ( ! $this->is_platform_connected() ) {
			$data = is_array( $cache_contents ) && array_key_exists( 'data', $cache_contents ) ? $cache_contents['data'] : null;

			return is_array( $data ) ? $data : null;
		}

		$is_valid_data = static fn( $data ): bool => is_array( $data ) && isset( $data['__locale'] ) && $data['__locale'] === $locale;
		$data          = null;

		if ( is_array( $cache_contents ) && array_key_exists( 'data', $cache_contents ) && $is_valid_data( $cache_contents['data'] ) ) {
			$data = $cache_contents['data'];
		}

		if ( $this->should_refresh_database_cache( self::ONBOARDING_FIELDS_DATA_OPTION, $cache_contents, $is_valid_data, false ) ) {
			$fresh_data = $this->fetch_onboarding_fields_data( $locale );
			$errored    = null === $fresh_data;
			if ( ! $errored ) {
				$data = $fresh_data;
			}

			$new_contents = $this->build_database_cache_contents( $data, $errored, $cache_contents );

			$this->onboarding_fields_cache[ get_current_blog_id() ] = $new_contents;
			$this->store_database_cache( self::ONBOARDING_FIELDS_DATA_OPTION, $new_contents );
		}

		return $data;
	}

	/**
	 * Drop the cached onboarding fields data so the next read fetches it.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function clear_onboarding_fields_cache(): void {
		unset( $this->onboarding_fields_cache[ get_current_blog_id() ] );
		$this->legacy_proxy->call_function( 'delete_option', self::ONBOARDING_FIELDS_DATA_OPTION );
		$this->legacy_proxy->call_function( 'wp_cache_delete', self::ONBOARDING_FIELDS_DATA_OPTION, 'options' );
	}

	/**
	 * Get the raw persisted onboarding fields cache wrapper.
	 *
	 * @return array<string,mixed>|false
	 */
	private function get_onboarding_fields_cache() {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->onboarding_fields_cache ) ) {
			$cache = $this->legacy_proxy->call_function( 'get_option', self::ONBOARDING_FIELDS_DATA_OPTION );

			$this->onboarding_fields_cache[ $blog_id ] = is_array( $cache ) ? $cache : false;
		}

		return $this->onboarding_fields_cache[ $blog_id ];
	}

	/**
	 * Fetch the onboarding fields data from the platform, with the locale stored in it.
	 *
	 * An empty result is valid data; only a failed request is an error.
	 *
	 * @param string $locale The locale to translate the fields data into.
	 * @return array<string,mixed>|null The fields data, or null on error.
	 */
	private function fetch_onboarding_fields_data( string $locale ): ?array {
		$api_client = $this->get_api_client();
		if ( null === $api_client ) {
			return null;
		}

		try {
			$fields_data = $api_client->get_onboarding_fields_data( $locale );
		} catch ( \Throwable $e ) {
			return null;
		}

		$fields_data['__locale'] = $locale;

		return $fields_data;
	}

	/**
	 * Tell whether the platform connection state can be determined yet.
	 *
	 * Jetpack's connection-owner check calls get_userdata(), which WordPress defines in pluggable.php only after plugins
	 * load. Before that the check throws and the connection reads as missing even on a connected store.
	 *
	 * @return bool
	 */
	private function is_connection_state_known(): bool {
		if ( ! isset( $this->legacy_proxy ) ) {
			return function_exists( 'get_userdata' );
		}

		return (bool) $this->legacy_proxy->call_function( 'function_exists', 'get_userdata' );
	}

	/**
	 * Fetch account data from the native WooPayments API.
	 *
	 * @return array<string,mixed>|false
	 */
	private function fetch_account_data() {
		$api_client = $this->get_api_client();
		if ( ! $api_client || ! $api_client->is_available() ) {
			return false;
		}

		try {
			$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_DISABLED_TRANSIENT );
			$account_data = $api_client->get_account( $this->get_woocommerce_store_id() );
		} catch ( WooPaymentsApiException $e ) {
			if ( 'wcpay_account_not_found' === $e->get_error_code() ) {
				$account_data = array();
			} elseif ( 'wcpay_on_boarding_disabled' === $e->get_error_code() ) {
				$account_data = array();
				$this->legacy_proxy->call_function( 'set_transient', self::ONBOARDING_DISABLED_TRANSIENT, true, 2 * HOUR_IN_SECONDS );
			} else {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! $this->is_valid_cached_account( $account_data ) ) {
			return false;
		}

		return $account_data;
	}

	/**
	 * Clear the preserved WooPayments account cache.
	 *
	 * This mirrors the plugin behavior after account creation/finalization so the next account read can refresh the
	 * provider state instead of continuing to use stale cached readiness.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		try {
			unset( $this->account_cache[ get_current_blog_id() ] );
			$this->legacy_proxy->call_function( 'delete_option', self::ACCOUNT_OPTION );
			$this->legacy_proxy->call_function( 'wp_cache_delete', self::ACCOUNT_OPTION, 'options' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Persist normalized WooPayments account cache data.
	 *
	 * @param array<string,mixed> $account_data Account data.
	 * @return void
	 */
	public function cache_account_data( array $account_data ): void {
		$this->write_account_cache( $account_data, false );
	}

	/**
	 * Persist partial account data that the next read must replace with the full account.
	 *
	 * The plugin clears its account cache after onboarding creates or finishes an account, so the next read fetches
	 * the full record. Keeping the partial record, marked as never fetched, gives the same refetch while leaving the
	 * account ID and publishable key available if that refetch fails.
	 *
	 * @since 11.2.0
	 * @param array<string,mixed> $account_data Partial account data from an onboarding response.
	 * @return void
	 */
	public function cache_account_data_until_refreshed( array $account_data ): void {
		try {
			$cache_contents            = $this->build_account_cache_contents( $account_data, false );
			$cache_contents['fetched'] = 0;
			$this->persist_account_cache( $cache_contents );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Immediately overwrite the preserved account cache with a connected-but-no-account payload.
	 *
	 * This avoids reading a stale account while the platform finishes deleting it.
	 *
	 * @return void
	 */
	public function overwrite_cache_with_no_account(): void {
		$this->write_account_cache( array(), false );
	}

	/**
	 * Get the account ID currently preserved in the local account cache without forcing a refresh.
	 *
	 * @since 11.0.0
	 * @return string
	 */
	public function get_preserved_account_id(): string {
		$cache = $this->get_account_cache();
		$data  = is_array( $cache ) && isset( $cache['data'] ) && is_array( $cache['data'] ) ? $cache['data'] : array();

		return isset( $data['account_id'] ) && is_scalar( $data['account_id'] )
			? (string) $data['account_id']
			: '';
	}

	/**
	 * Get the account data currently preserved in the local account cache without forcing a refresh.
	 *
	 * @since 11.0.0
	 * @return array<string,mixed>
	 */
	public function get_preserved_account_data_snapshot(): array {
		$cache = $this->get_account_cache();
		$data  = is_array( $cache ) && array_key_exists( 'data', $cache ) ? $cache['data'] : null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist the account deletion currently being processed so retries can continue after partial cleanup.
	 *
	 * @since 11.0.0
	 * @param string $account_id Account ID.
	 * @return void
	 * @throws RuntimeException When the marker cannot be persisted.
	 */
	public function mark_account_deletion_pending( string $account_id ): void {
		$this->legacy_proxy->call_function( 'update_option', self::ACCOUNT_DELETION_PENDING_OPTION, $account_id, false );
		$this->legacy_proxy->call_function( 'wp_cache_delete', self::ACCOUNT_DELETION_PENDING_OPTION, 'options' );
		if ( $account_id !== $this->get_pending_account_deletion_id() ) {
			throw new RuntimeException( 'Unable to persist the pending WooPayments account deletion marker.' );
		}
	}

	/**
	 * Get the pending account deletion ID, if any.
	 *
	 * @since 11.0.0
	 * @return string
	 */
	public function get_pending_account_deletion_id(): string {
		$account_id = $this->legacy_proxy->call_function( 'get_option', self::ACCOUNT_DELETION_PENDING_OPTION, '' );

		return is_scalar( $account_id ) ? (string) $account_id : '';
	}

	/**
	 * Clear the pending account deletion marker.
	 *
	 * @since 11.0.0
	 * @return void
	 * @throws RuntimeException When the marker cannot be cleared.
	 */
	public function clear_pending_account_deletion(): void {
		$this->legacy_proxy->call_function( 'delete_option', self::ACCOUNT_DELETION_PENDING_OPTION );
		$this->legacy_proxy->call_function( 'wp_cache_delete', self::ACCOUNT_DELETION_PENDING_OPTION, 'options' );
		if ( '' !== $this->get_pending_account_deletion_id() ) {
			throw new RuntimeException( 'Unable to clear the pending WooPayments account deletion marker.' );
		}
	}

	/**
	 * Reset preserved WooPayments account/onboarding state after the provider account is deleted.
	 *
	 * This mirrors the extension account-reset cleanup for the Core-owned native runtime without performing a live account
	 * refresh. The webhook handler refreshes account data after cleanup so the platform can drive the next steps.
	 *
	 * @since 11.0.0
	 * @return void
	 */
	public function cleanup_after_account_reset(): void {
		$settings = $this->get_gateway_settings();

		$settings['enabled']                        = 'no';
		$settings['test_mode']                      = 'no';
		$settings['upe_enabled_payment_method_ids'] = array( 'card' );

		$this->legacy_proxy->call_function( 'update_option', self::SETTINGS_OPTION, $settings );
		$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_STRIPE_CONNECTED_OPTION, array() );
		$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_TEST_MODE_OPTION, 'no', true );
		$this->legacy_proxy->call_function( 'delete_option', self::ONBOARDING_CONNECTION_SUCCESS_MODAL_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_STATE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_option', self::EMBEDDED_KYC_IN_PROGRESS_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', self::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_INIT_IN_PROGRESS_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_option', self::TEST_MODE_ENABLED_DATE_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', self::TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_DISABLED_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_option', self::NOX_PROFILE_OPTION );
		$this->legacy_proxy->call_function( 'delete_option', self::NOX_ONBOARDING_LOCKED_OPTION );

		$this->clear_preserved_database_cache();

		try {
			$this->legacy_proxy->call_function( 'delete_option', self::INCENTIVES_USAGE_OPTION );
		} finally {
			$this->legacy_proxy->call_function( 'delete_option', self::INCENTIVES_USAGE_VERSION_OPTION );
		}
	}

	/**
	 * Clear preserved WooPayments database cache keys that hinge on the connected account.
	 *
	 * @return void
	 */
	private function clear_preserved_database_cache(): void {
		unset( $this->account_cache[ get_current_blog_id() ], $this->onboarding_fields_cache[ get_current_blog_id() ] );

		foreach ( self::DATABASE_CACHE_OPTIONS as $option_name ) {
			$this->legacy_proxy->call_function( 'delete_option', $option_name );
			$this->legacy_proxy->call_function( 'wp_cache_delete', $option_name, 'options' );
		}
	}

	/**
	 * Get the raw persisted account cache wrapper.
	 *
	 * @return array<string,mixed>|false
	 */
	private function get_account_cache() {
		$blog_id = get_current_blog_id();
		if ( array_key_exists( $blog_id, $this->account_cache ) ) {
			return $this->account_cache[ $blog_id ];
		}

		try {
			$cache = $this->legacy_proxy->call_function( 'get_option', self::ACCOUNT_OPTION );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the account cache', $e );
			$cache = false;
		}

		$this->account_cache[ $blog_id ] = is_array( $cache ) ? $cache : false;

		return $this->account_cache[ $blog_id ];
	}

	/**
	 * Write account data with cache metadata.
	 *
	 * @param mixed $account_data Account data.
	 * @param bool  $errored      Whether the refresh that produced this write errored.
	 * @return void
	 */
	private function write_account_cache( $account_data, bool $errored ): void {
		try {
			$this->persist_account_cache( $this->build_account_cache_contents( $account_data, $errored ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Build account cache contents with metadata.
	 *
	 * @param mixed $account_data Account data.
	 * @param bool  $errored      Whether the refresh that produced this write errored.
	 * @return array<string,mixed>
	 */
	private function build_account_cache_contents( $account_data, bool $errored ): array {
		return $this->build_database_cache_contents( $account_data, $errored, $errored ? $this->get_account_cache() : false );
	}

	/**
	 * Build database cache contents in the client's `Database_Cache` shape.
	 *
	 * Each errored write increments `consecutive_errors` from the previous contents; a successful write resets it.
	 *
	 * @param mixed $data     Data to cache.
	 * @param bool  $errored  Whether the refresh that produced this write errored.
	 * @param mixed $previous Previous cache contents, read only when errored.
	 * @return array<string,mixed>
	 */
	private function build_database_cache_contents( $data, bool $errored, $previous ): array {
		$consecutive_errors = 0;
		if ( $errored ) {
			$previous_count     = is_array( $previous ) && isset( $previous['consecutive_errors'] )
				? (int) $previous['consecutive_errors']
				: 0;
			$consecutive_errors = $previous_count + 1;
		}

		return array(
			'data'               => $data,
			'fetched'            => $this->legacy_proxy->call_function( 'time' ),
			'errored'            => $errored,
			'consecutive_errors' => $consecutive_errors,
		);
	}

	/**
	 * Persist account cache contents.
	 *
	 * @param array<string,mixed> $cache_contents Account cache contents.
	 * @return void
	 */
	private function persist_account_cache( array $cache_contents ): void {
		$this->account_cache[ get_current_blog_id() ] = $cache_contents;
		$this->store_database_cache( self::ACCOUNT_OPTION, $cache_contents );

		if (
			$this->is_persisted_account_cache( $cache_contents ) &&
			null !== $this->runtime_arbiter &&
			true !== ( $cache_contents['errored'] ?? null ) &&
			is_array( $cache_contents['data'] ?? null )
		) {
			$this->synchronize_native_payments_state( $cache_contents['data'], $this->runtime_arbiter->is_plugin_runtime_active() );
		}
	}

	/**
	 * Write database cache contents to their option, not autoloaded, like the client's `Database_Cache::write_to_cache()`.
	 *
	 * @param string              $key            Cache option key.
	 * @param array<string,mixed> $cache_contents Cache wrapper.
	 * @return void
	 */
	private function store_database_cache( string $key, array $cache_contents ): void {
		$result = $this->legacy_proxy->call_function( 'update_option', $key, $cache_contents, 'no' );
		if ( false !== $result ) {
			$this->legacy_proxy->call_function( 'wp_cache_delete', $key, 'options' );
		}
	}

	/**
	 * Rewrite the durable native payments state from the persisted account cache and gateway settings.
	 *
	 * Reads raw options only, with no account refresh.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $plugin_runtime_active Whether the standalone plugin owns the runtime; false once the caller has deactivated it.
	 */
	public function synchronize_native_payments_state_from_options( bool $plugin_runtime_active ): void {
		if ( null === $this->native_payments_state || null === $this->runtime_arbiter ) {
			return;
		}

		try {
			$cache_contents = $this->legacy_proxy->call_function( 'get_option', self::ACCOUNT_OPTION );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the account cache', $e );
			return;
		}

		$this->synchronize_native_payments_state( is_array( $cache_contents ) ? ( $cache_contents['data'] ?? null ) : null, $plugin_runtime_active );
	}

	/**
	 * Rewrite the durable native payments state after the WooPayments plugin writes its account cache.
	 *
	 * Without this, a plugin store keeps the state the upgrade repair wrote, so an account that becomes eligible later
	 * never gets the start notice. Errored or data-less writes keep the prior state, as native's own cache writes do.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $cache_contents Account cache contents the plugin wrote.
	 */
	public function synchronize_after_plugin_account_cache_write( $cache_contents ): void {
		if (
			null === $this->runtime_arbiter ||
			! $this->runtime_arbiter->is_plugin_runtime_active() ||
			! is_array( $cache_contents ) ||
			true === ( $cache_contents['errored'] ?? null ) ||
			! is_array( $cache_contents['data'] ?? null )
		) {
			return;
		}

		$this->synchronize_native_payments_state( $cache_contents['data'], true );
	}

	/**
	 * Synchronize durable state without affecting the account source write.
	 *
	 * Missing account data preserves the prior state unless native is disabled or the plugin owns the runtime.
	 *
	 * @param mixed $account_data          Last persisted account data.
	 * @param bool  $plugin_runtime_active Whether the standalone plugin owns the runtime.
	 */
	private function synchronize_native_payments_state( $account_data, bool $plugin_runtime_active ): void {
		if ( null === $this->native_payments_state || null === $this->runtime_arbiter ) {
			return;
		}

		if ( ! $this->runtime_arbiter->is_native_runtime_enabled() || ( is_array( $account_data ) && ! $this->is_native_eligible_account_data( $account_data ) ) ) {
			$this->native_payments_state->write_state( NativePaymentsState::DISABLED );
			return;
		}

		if ( $plugin_runtime_active || array() === $account_data ) {
			$this->native_payments_state->write_state( NativePaymentsState::AVAILABLE );
			return;
		}

		if ( ! is_array( $account_data ) ) {
			return;
		}

		$account_id = $account_data['account_id'] ?? null;
		if ( ! is_scalar( $account_id ) || '' === (string) $account_id ) {
			return;
		}

		$settings = $this->get_gateway_settings();
		$this->native_payments_state->write_state(
			'yes' === ( $settings['enabled'] ?? null ) ? NativePaymentsState::ACTIVE : NativePaymentsState::CONNECTED
		);
	}

	/**
	 * Tell whether account cache contents were durably persisted.
	 *
	 * @param array<string,mixed> $cache_contents Expected account cache contents.
	 * @return bool
	 */
	private function is_persisted_account_cache( array $cache_contents ): bool {
		try {
			$this->legacy_proxy->call_function( 'wp_cache_delete', self::ACCOUNT_OPTION, 'options' );
			$persisted = $this->legacy_proxy->call_function( 'get_option', self::ACCOUNT_OPTION );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the account cache', $e );
			return false;
		}

		return $cache_contents === $persisted;
	}

	/**
	 * Tell whether the account cache should be refreshed.
	 *
	 * @param mixed $cache_contents Raw cache wrapper.
	 * @param bool  $force_refresh  Whether to force refresh.
	 * @return bool
	 */
	private function should_refresh_account_cache( $cache_contents, bool $force_refresh ): bool {
		return $this->should_refresh_database_cache( self::ACCOUNT_OPTION, $cache_contents, fn( $data ): bool => $this->is_valid_cached_account( $data ), $force_refresh );
	}

	/**
	 * Tell whether a database cache entry should be refreshed, like the client's `Database_Cache::should_refresh_cache()`.
	 *
	 * @param string   $key            Cache option key.
	 * @param mixed    $cache_contents Raw cache wrapper.
	 * @param callable $is_valid_data  Validates the cached data.
	 * @param bool     $force_refresh  Whether to force refresh.
	 * @return bool
	 */
	private function should_refresh_database_cache( string $key, $cache_contents, callable $is_valid_data, bool $force_refresh ): bool {
		if ( $force_refresh ) {
			return true;
		}

		if (
			defined( 'DOING_CRON' )
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| $this->refresh_disabled
		) {
			return false;
		}

		if ( false === $cache_contents ) {
			return true;
		}

		if (
			! is_array( $cache_contents )
			|| empty( $cache_contents )
			|| ! array_key_exists( 'data', $cache_contents )
			|| ! isset( $cache_contents['fetched'] )
			|| ! array_key_exists( 'errored', $cache_contents )
		) {
			return true;
		}

		if ( ! $cache_contents['errored'] && ! $is_valid_data( $cache_contents['data'] ) ) {
			return true;
		}

		return $this->is_database_cache_expired( $key, $cache_contents );
	}

	/**
	 * Tell whether database cache contents are expired.
	 *
	 * @param string              $key            Cache option key.
	 * @param array<string,mixed> $cache_contents Cache wrapper.
	 * @return bool
	 */
	private function is_database_cache_expired( string $key, array $cache_contents ): bool {
		$fetched = is_numeric( $cache_contents['fetched'] ?? null ) ? (int) $cache_contents['fetched'] : 0;
		$ttl     = $this->get_database_cache_ttl( $key, $cache_contents );

		$now = (int) $this->legacy_proxy->call_function( 'time' );

		return $fetched + $ttl < $now;
	}

	/**
	 * Get a database cache TTL for the current request context, like the client's `Database_Cache::get_ttl()`.
	 *
	 * @param string              $key            Cache option key.
	 * @param array<string,mixed> $cache_contents Cache wrapper.
	 * @return int
	 */
	private function get_database_cache_ttl( string $key, array $cache_contents ): int {
		$errored = ! empty( $cache_contents['errored'] );

		if ( self::ONBOARDING_FIELDS_DATA_OPTION === $key ) {
			$ttl = $errored
				? $this->get_errored_cache_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
				: self::ONBOARDING_FIELDS_DATA_TTL;
		} elseif ( is_admin() ) {
			$ttl = $errored
				? $this->get_errored_cache_ttl( (int) ( $cache_contents['consecutive_errors'] ?? 0 ) )
				: self::ACCOUNT_CACHE_ADMIN_TTL;
		} else {
			$ttl = self::ACCOUNT_CACHE_FRONTEND_TTL;
		}

		/**
		 * Filters the WooPayments database cache TTL.
		 *
		 * @since 11.0.0
		 *
		 * @param int                 $ttl            Cache TTL in seconds.
		 * @param string              $key            Cache option key.
		 * @param array<string,mixed> $cache_contents Cache wrapper.
		 */
		return (int) apply_filters( 'wcpay_database_cache_ttl', $ttl, $key, $cache_contents );
	}

	/**
	 * Get the progressive backoff TTL for errored database cache refreshes.
	 *
	 * @param int $consecutive_errors Consecutive error count.
	 * @return int
	 */
	private function get_errored_cache_ttl( int $consecutive_errors ): int {
		$index = max( 0, min( count( self::DATABASE_CACHE_ERRORED_TTL_LADDER ) - 1, $consecutive_errors - 1 ) );

		return self::DATABASE_CACHE_ERRORED_TTL_LADDER[ $index ];
	}

	/**
	 * Get the connected WooPayments account ID.
	 *
	 * @return string
	 */
	public function get_account_id(): string {
		$account_data = $this->get_cached_account_data();

		return isset( $account_data['account_id'] ) && is_scalar( $account_data['account_id'] )
			? (string) $account_data['account_id']
			: '';
	}

	/**
	 * Tell whether the platform account is eligible for native payments.
	 *
	 * Older platform payloads that do not include native eligibility remain eligible.
	 *
	 * @since 11.2.0
	 * @return bool
	 */
	public function is_native_eligible(): bool {
		return $this->is_native_eligible_account_data( $this->get_native_payments_account_data() );
	}

	/**
	 * Get the platform rollout cohort for native payments.
	 *
	 * @since 11.2.0
	 * @return string
	 */
	public function get_native_cohort(): string {
		$account_data    = $this->get_native_payments_account_data();
		$native_payments = $account_data['native_payments'] ?? null;
		if ( ! is_array( $native_payments ) || ! is_string( $native_payments['cohort'] ?? null ) ) {
			return '';
		}

		return $native_payments['cohort'];
	}

	/**
	 * Get the account data that native eligibility and cohort read.
	 *
	 * These flags have no client counterpart. Without a platform connection they keep the cached platform decision,
	 * because the account read then returns no account, which would read as eligible.
	 *
	 * @return array<string,mixed>
	 */
	private function get_native_payments_account_data(): array {
		return $this->is_platform_connected() ? $this->get_cached_account_data() : $this->get_preserved_account_data_snapshot();
	}

	/**
	 * Parse native eligibility from account data.
	 *
	 * @param array<string,mixed> $account_data Account payload.
	 * @return bool
	 */
	private function is_native_eligible_account_data( array $account_data ): bool {
		$native_payments = $account_data['native_payments'] ?? null;

		return ! is_array( $native_payments ) || false !== ( $native_payments['eligible'] ?? null );
	}

	/**
	 * Get the connected account country, falling back to the store base country, then to US.
	 *
	 * @since 11.2.0
	 *
	 * @return string Uppercase country code.
	 */
	public function get_account_or_store_country(): string {
		$account_data = $this->get_cached_account_data();
		$country      = isset( $account_data['country'] ) && is_scalar( $account_data['country'] )
			? strtoupper( (string) $account_data['country'] )
			: '';

		if ( '' === $country ) {
			$base    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
			$country = strtoupper( (string) ( $base['country'] ?? '' ) );
		}

		if ( false !== strpos( $country, ':' ) ) {
			$base_country = strtok( $country, ':' );
			$country      = is_string( $base_country ) ? $base_country : '';
		}

		return '' !== $country ? $country : 'US';
	}

	/**
	 * Get the connected account's domestic currency, lowercase.
	 *
	 * Mirrors the client's get_account_domestic_currency(): the account country's locale data gives the currency, and the
	 * account default currency is the fallback when the country has no locale data.
	 *
	 * @since 11.2.0
	 *
	 * @return string
	 */
	public function get_account_domestic_currency(): string {
		$country_locale_data = wc_get_container()->get( MultiCurrencyLocalizationService::class )->get_country_locale_data( $this->get_account_or_store_country() );
		$currency_code       = $country_locale_data['currency_code'] ?? null;

		if ( ! is_string( $currency_code ) || '' === $currency_code ) {
			return $this->get_account_default_currency();
		}

		return strtolower( $currency_code );
	}

	/**
	 * Get the connected WooPayments account default currency.
	 *
	 * @since 11.0.0
	 *
	 * @return string Lowercase account default currency, or usd when unavailable.
	 */
	public function get_account_default_currency(): string {
		$account_data     = $this->get_cached_account_data();
		$store_currencies = is_array( $account_data['store_currencies'] ?? null ) ? $account_data['store_currencies'] : array();
		$default_currency = $store_currencies['default'] ?? 'usd';

		return is_scalar( $default_currency ) ? strtolower( trim( (string) $default_currency ) ) : 'usd';
	}

	/**
	 * Get the account's customer-supported presentment currencies.
	 *
	 * Reads the account payload's customer_currencies.supported list, as the
	 * reference client's get_account_customer_supported_currencies() does.
	 * Currency codes are returned as the platform ships them (lowercase).
	 *
	 * @since 11.0.0
	 *
	 * @return string[] Supported presentment currencies, empty when unknown.
	 */
	public function get_customer_supported_currencies(): array {
		$account_data = $this->get_cached_account_data();

		$customer_currencies = $account_data['customer_currencies'] ?? array();
		if ( ! is_array( $customer_currencies ) ) {
			return array();
		}

		$supported_currencies = $customer_currencies['supported'] ?? array();
		if ( ! is_array( $supported_currencies ) ) {
			return array();
		}

		return array_values( array_filter( $supported_currencies, 'is_string' ) );
	}

	/**
	 * Get the mode-specific publishable key.
	 *
	 * @return string
	 */
	public function get_publishable_key(): string {
		$key_name        = $this->is_test_mode_enabled() ? 'test_publishable_key' : 'live_publishable_key';
		$account_data    = $this->get_cached_account_data();
		$publishable_key = $account_data[ $key_name ] ?? '';

		return is_scalar( $publishable_key ) ? (string) $publishable_key : '';
	}

	/**
	 * Tell whether WooPayments native processing has enough account data to act.
	 *
	 * @return bool
	 */
	public function can_process_payments(): bool {
		$account_data = $this->get_cached_account_data();

		return '' !== $this->get_account_id()
			&& '' !== $this->get_publishable_key()
			&& $this->is_truthy( $account_data['payments_enabled'] ?? false )
			&& $this->is_truthy( $account_data['details_submitted'] ?? false );
	}

	/**
	 * Tell whether the platform reports an account capability as active.
	 *
	 * Only `active` counts; every other status the platform reports (pending, inactive, rejected, disabled and the rest) does
	 * not. With no capabilities reported, card payments count as active, as the client assumes before onboarding
	 * (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:4696-4717`).
	 *
	 * @param string $capability_key Capability key, such as `card_payments` or `link_payments`.
	 * @return bool
	 */
	public function is_capability_active( string $capability_key ): bool {
		$account_data = $this->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();

		if ( array() === $capabilities ) {
			return 'card_payments' === $capability_key;
		}

		return 'active' === ( $capabilities[ $capability_key ] ?? null );
	}

	/**
	 * Tell whether a connected WooPayments account ID is available.
	 *
	 * @return bool
	 */
	public function has_account(): bool {
		return '' !== $this->get_account_id();
	}

	/**
	 * Tell whether an account exists or its connection state cannot be determined after a refresh failure.
	 *
	 * Address-token cache cleanup must not treat a transient account refresh failure as a confirmed disconnect. Without a
	 * platform connection the state is known, like the client's `is_stripe_connected( true )`, so an errored entry left
	 * from that time does not count. While plugins load the connection state is not known yet, which is indeterminate.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function has_account_or_is_connection_indeterminate(): bool {
		if ( ! $this->is_connection_state_known() ) {
			return true;
		}

		if ( ! $this->is_platform_connected() ) {
			return false;
		}

		if ( $this->has_account() ) {
			return true;
		}

		$cache = $this->get_account_cache();

		return is_array( $cache )
			&& array_key_exists( 'data', $cache )
			&& null === $cache['data']
			&& is_numeric( $cache['fetched'] ?? null )
			&& 0 < (float) $cache['fetched']
			&& true === ( $cache['errored'] ?? null )
			&& is_numeric( $cache['consecutive_errors'] ?? null )
			&& 0 < (float) $cache['consecutive_errors'];
	}

	/**
	 * Tell whether the cached account can currently receive payments.
	 *
	 * @return bool
	 */
	public function has_working_account(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['payments_enabled'] ?? false );
	}

	/**
	 * Tell whether the cached account is a test-drive account.
	 *
	 * @return bool
	 */
	public function has_test_account(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['is_test_drive'] ?? false );
	}

	/**
	 * Tell whether the cached account is a sandbox account.
	 *
	 * @return bool
	 */
	public function has_sandbox_account(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account()
			&& array_key_exists( 'is_live', $account_data )
			&& ! $this->is_truthy( $account_data['is_live'] )
			&& ! $this->has_test_account();
	}

	/**
	 * Tell whether the cached account is a live account.
	 *
	 * @return bool
	 */
	public function has_live_account(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['is_live'] ?? false );
	}

	/**
	 * Get the cached account's liveness, when it can be determined.
	 *
	 * @return bool|null True for a live account, false for a non-live one, null when no cached
	 *                   account data carries an is_live field (liveness unknown).
	 */
	public function get_account_is_live(): ?bool {
		$account_data = $this->get_cached_account_data();

		if ( array() === $account_data || ! isset( $account_data['is_live'] ) ) {
			return null;
		}

		return $this->is_truthy( $account_data['is_live'] );
	}

	/**
	 * Tell whether the site only uses network-wide saved payment methods.
	 *
	 * @return bool
	 */
	public function is_network_saved_cards_enabled(): bool {
		/**
		 * Allows forcing WooPayments to use network-wide saved payment methods across a multisite network.
		 *
		 * Kept under the WooPayments extension's filter name for parity. The extension
		 * marks it internal to Automattic; native honors the same opt-in so customer
		 * IDs, saved-method behavior and the WooPay flags stay network-consistent.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether the site should only use network-wide saved payment methods.
		 */
		return (bool) apply_filters( 'wcpay_force_network_saved_cards', false );
	}

	/**
	 * Tell whether the cached account is rejected.
	 *
	 * @return bool
	 */
	public function is_account_rejected(): bool {
		$account_data = $this->get_cached_account_data();
		$status       = $account_data['status'] ?? '';

		return $this->has_account()
			&& is_scalar( $status )
			&& str_starts_with( (string) $status, 'rejected' );
	}

	/**
	 * Tell whether the cached account is under review.
	 *
	 * @return bool
	 */
	public function is_account_under_review(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && 'under_review' === ( $account_data['status'] ?? null );
	}

	/**
	 * Tell whether the cached account completed details submission.
	 *
	 * @return bool
	 */
	public function is_details_submitted(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->is_truthy( $account_data['details_submitted'] ?? false );
	}

	/**
	 * Tell whether the cached account is valid for native WooPayments admin navigation.
	 *
	 * @return bool
	 */
	public function has_valid_account_for_admin_navigation(): bool {
		$account_data = $this->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();

		return $this->has_account()
			&& $this->is_details_submitted()
			&& isset( $capabilities['card_payments'] )
			&& 'unrequested' !== $capabilities['card_payments'];
	}

	/**
	 * Tell whether the cached account is eligible for in-person payments.
	 *
	 * @return bool
	 */
	public function is_card_present_eligible(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['card_present_eligible'] ?? false );
	}

	/**
	 * Tell whether the cached account has card readers available.
	 *
	 * @return bool
	 */
	public function has_card_readers_available(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['has_card_readers_available'] ?? false );
	}

	/**
	 * Tell whether the cached account has previous Capital loans.
	 *
	 * @return bool
	 */
	public function has_previous_capital_loans(): bool {
		$account_data = $this->get_cached_account_data();
		$capital      = is_array( $account_data['capital'] ?? null ) ? $account_data['capital'] : array();

		return $this->has_account() && $this->is_truthy( $capital['has_previous_loans'] ?? false );
	}

	/**
	 * Tell whether the cached account is eligible for Documents.
	 *
	 * @since 11.0.0
	 * @return bool
	 */
	public function is_documents_enabled(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['is_documents_enabled'] ?? false );
	}

	/**
	 * Tell whether the cached account is eligible for Reports.
	 *
	 * @since 11.0.0
	 * @return bool
	 */
	public function is_reports_enabled(): bool {
		$account_data = $this->get_preserved_account_data_snapshot();
		if ( array_key_exists( 'reports_area_enabled', $account_data ) && null !== $account_data['reports_area_enabled'] ) {
			return (bool) $account_data['reports_area_enabled'];
		}

		$enabled = $this->legacy_proxy->call_function( 'get_option', self::REPORTS_AREA_FLAG_OPTION, '0' );

		return '1' === (string) $enabled;
	}

	/**
	 * Tell whether the cached account has submitted VAT details.
	 *
	 * @since 11.0.0
	 * @return bool
	 */
	public function has_submitted_vat_data(): bool {
		$account_data = $this->get_cached_account_data();

		return $this->has_account() && $this->is_truthy( $account_data['has_submitted_vat_data'] ?? false );
	}

	/**
	 * Get the cached account country.
	 *
	 * @since 11.0.0
	 * @return string
	 */
	public function get_account_country(): string {
		$account_data = $this->get_cached_account_data();
		$country      = $account_data['country'] ?? '';

		return $this->has_account() && is_scalar( $country ) ? strtoupper( trim( (string) $country ) ) : '';
	}

	/**
	 * Get countries where WooPayments platform accounts are supported.
	 *
	 * @return array<string,string> Country labels keyed by ISO country code.
	 */
	public function get_supported_countries(): array {
		$all_countries       = WC()->countries->get_countries();
		$supported_countries = array();

		foreach ( self::SUPPORTED_COUNTRY_CODES as $country_code ) {
			if ( isset( $all_countries[ $country_code ] ) && is_string( $all_countries[ $country_code ] ) ) {
				$supported_countries[ $country_code ] = $all_countries[ $country_code ];
			}
		}

		return $supported_countries;
	}

	/**
	 * Tell whether WooPayments is in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode_enabled(): bool {
		$test_mode_onboarding = $this->is_test_mode_onboarding_enabled();
		if ( $test_mode_onboarding ) {
			$test_mode = true;
		} else {
			$test_mode = 'yes' === $this->get_gateway_setting( 'test_mode' );
		}

		/**
		 * Allows WooPayments to process payments in test mode.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $test_mode Whether WooPayments should process payments in test mode.
		 */
		return (bool) apply_filters( 'wcpay_test_mode', $test_mode );
	}

	/**
	 * Get the current WooPayments mode slug.
	 *
	 * @return string
	 */
	public function get_mode(): string {
		return $this->is_test_mode_enabled() ? 'test' : 'live';
	}

	/**
	 * Get the `_wcpay_mode` order meta value for the current mode.
	 *
	 * Plugin 11.1.0 writes `Order_Mode::TEST` or `Order_Mode::PRODUCTION` (`prod`), not the account mode (class-wc-payment-gateway-wcpay.php:1677).
	 *
	 * @return string One of the WooPaymentsOrderMode values.
	 */
	public function get_order_mode(): string {
		return 'test' === $this->get_mode() ? WooPaymentsOrderMode::TEST : WooPaymentsOrderMode::PRODUCTION;
	}

	/**
	 * Get a persisted WooPayments gateway setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Optional caller fallback, which takes precedence over the plugin default.
	 * @return mixed Persisted value, caller fallback, plugin default, or an empty string when no default exists.
	 */
	public function get_gateway_setting( string $key, $fallback = null ) {
		$settings = $this->get_gateway_settings();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		if ( 1 < func_num_args() ) {
			return $fallback;
		}

		return WooPaymentsSettingsDefaults::get( $key ) ?? '';
	}

	/**
	 * Tell whether the WooPayments gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_gateway_enabled(): bool {
		return 'yes' === (string) $this->get_gateway_setting( 'enabled' );
	}

	/**
	 * Tell whether Apple Pay or Google Pay is enabled in split gateway settings.
	 *
	 * @return bool
	 */
	public function is_payment_request_enabled(): bool {
		if ( null === $this->gateway_settings_synchronizer ) {
			$this->gateway_settings_synchronizer = wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class );
		}

		return $this->gateway_settings_synchronizer->is_payment_request_enabled( $this->get_gateway_settings() );
	}

	/**
	 * Tell whether one split payment-request gateway is enabled.
	 *
	 * @param string $method_id Apple Pay or Google Pay method ID.
	 * @return bool
	 */
	public function is_payment_request_method_enabled( string $method_id ): bool {
		if ( null === $this->gateway_settings_synchronizer ) {
			$this->gateway_settings_synchronizer = wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class );
		}

		return $this->gateway_settings_synchronizer->is_payment_request_method_enabled( $method_id );
	}

	/**
	 * Tell whether WooPayments onboarding was disabled by the platform.
	 *
	 * @since 11.0.0
	 * @return bool
	 */
	public function is_onboarding_disabled(): bool {
		return (bool) $this->legacy_proxy->call_function( 'get_transient', self::ONBOARDING_DISABLED_TRANSIENT );
	}

	/**
	 * Tell whether WooPayments is in test-mode onboarding.
	 *
	 * @return bool
	 */
	public function is_test_mode_onboarding_enabled(): bool {
		$test_mode_onboarding = $this->is_dev_mode_enabled() || $this->is_onboarding_test_mode_enabled();

		/**
		 * Allows WooPayments to use test mode onboarding.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $test_mode_onboarding Whether WooPayments should use test mode onboarding.
		 */
		return (bool) apply_filters( 'wcpay_test_mode_onboarding', $test_mode_onboarding );
	}

	/**
	 * Tell whether WooPayments development mode is enabled.
	 *
	 * @return bool
	 */
	public function is_dev_mode_enabled(): bool {
		return array() !== $this->get_dev_mode_triggers();
	}

	/**
	 * Get what put WooPayments in development mode, empty when it is off.
	 *
	 * The trigger names are the client's (client 11.1.0 `includes/core/class-mode.php:72-111`).
	 *
	 * @return string[]
	 */
	public function get_dev_mode_triggers(): array {
		$triggers = array();
		if ( $this->is_wcpay_dev_mode_defined() ) {
			$triggers[] = 'WCPAY_DEV_MODE';
		}
		if ( $this->is_wp_environment_dev_mode() ) {
			$triggers[] = 'WP_ENVIRONMENT_TYPE=' . wp_get_environment_type();
		}
		if ( $this->is_wp_development_mode_enabled() ) {
			$triggers[] = 'WP_DEVELOPMENT_MODE=' . wp_get_development_mode();
		}

		/**
		 * Allows WooPayments to enter dev mode.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $dev_mode Whether WooPayments should enter dev mode.
		 */
		$dev_mode = (bool) apply_filters( 'wcpay_dev_mode', array() !== $triggers );
		if ( ! $dev_mode ) {
			return array();
		}

		return array() !== $triggers ? $triggers : array( 'wcpay_dev_mode filter' );
	}

	/**
	 * Tell whether WooPayments dev mode constant is defined.
	 *
	 * @return bool
	 */
	private function is_wcpay_dev_mode_defined(): bool {
		return defined( 'WCPAY_DEV_MODE' ) && WCPAY_DEV_MODE;
	}

	/**
	 * Tell whether the current WordPress environment implies WooPayments dev mode.
	 *
	 * @return bool
	 */
	private function is_wp_environment_dev_mode(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}

		return in_array( wp_get_environment_type(), self::DEV_MODE_ENVIRONMENTS, true );
	}

	/**
	 * Tell whether WordPress development mode implies WooPayments dev mode.
	 *
	 * @return bool
	 */
	private function is_wp_development_mode_enabled(): bool {
		return function_exists( 'wp_get_development_mode' ) && '' !== wp_get_development_mode();
	}

	/**
	 * Get the native WooPayments API client when account data needs a live refresh.
	 *
	 * @return WooPaymentsApiClient|null
	 */
	protected function get_api_client(): ?WooPaymentsApiClient {
		if ( ! function_exists( 'wc_get_container' ) ) {
			return null;
		}

		try {
			return wc_get_container()->get( WooPaymentsApiClient::class );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get the WooCommerce store ID sent with account refresh and onboarding requests.
	 *
	 * @internal
	 *
	 * @return string
	 */
	public function get_woocommerce_store_id(): string {
		$option_name = class_exists( '\WC_Install' ) && defined( '\WC_Install::STORE_ID_OPTION' )
			? \WC_Install::STORE_ID_OPTION
			: 'woocommerce_store_id';

		try {
			$store_id = $this->legacy_proxy->call_function( 'get_option', $option_name, '' );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the store ID', $e );
			return '';
		}

		return is_scalar( $store_id ) ? (string) $store_id : '';
	}

	/**
	 * Get persisted gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_gateway_settings(): array {
		try {
			$settings = $this->legacy_proxy->call_function( 'get_option', self::SETTINGS_OPTION, array() );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the gateway settings', $e );
			return array();
		}

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Tell whether onboarding test mode is enabled.
	 *
	 * @return bool
	 */
	private function is_onboarding_test_mode_enabled(): bool {
		try {
			$value = $this->legacy_proxy->call_function( 'get_option', self::ONBOARDING_TEST_MODE_OPTION, 'no' );
		} catch ( \Throwable $e ) {
			$this->log_read_failure( 'the onboarding test mode setting', $e );
			return false;
		}

		return in_array( $value, array( 'yes', '1' ), true );
	}

	/**
	 * Log an option read that threw, whatever the logging setting.
	 *
	 * Only a third-party option filter can make the read throw; the caller then treats the option as missing, so the line
	 * is the only trace. Written through wc_get_logger() directly, because WooPaymentsLogger reads the gateway settings here.
	 *
	 * @param string     $what      What was being read.
	 * @param \Throwable $throwable Caught throwable.
	 */
	private function log_read_failure( string $what, \Throwable $throwable ): void {
		try {
			wc_get_logger()->error(
				'Native WooPayments could not read ' . $what . '; treating it as missing.',
				array_merge( WooPaymentsLogger::get_failure_context( $throwable ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
		} catch ( \Throwable $logging_error ) {
			unset( $logging_error );
		}
	}

	/**
	 * Tell whether account data is valid to read from or write to the cache.
	 *
	 * Runs on cache reads as well as refreshes. For a test account checked in dev mode with onboarding test mode off, it
	 * also turns onboarding test mode on, as the client does (client 11.1.0 `includes/class-wc-payments-account.php:2554-2583`).
	 *
	 * @param mixed $account_data Account data.
	 * @return bool
	 */
	private function is_valid_cached_account( $account_data ): bool {
		if ( null === $account_data || false === $account_data ) {
			return false;
		}

		if ( ! is_array( $account_data ) ) {
			return false;
		}

		if ( array() === $account_data ) {
			return true;
		}

		if ( $this->is_truthy( $account_data['is_live'] ?? false ) ) {
			return true;
		}

		if ( ! $this->is_onboarding_test_mode_enabled() && $this->is_dev_mode_enabled() ) {
			try {
				// Autoloaded like the client: storefront renders read it through is_test_mode_enabled().
				$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_TEST_MODE_OPTION, 'yes', true );
				$this->legacy_proxy->call_function( 'wp_cache_delete', self::ONBOARDING_TEST_MODE_OPTION, 'options' );
			} catch ( \Throwable $e ) {
				return $this->is_test_mode_onboarding_enabled();
			}
		}

		return $this->is_test_mode_onboarding_enabled();
	}

	/**
	 * Normalize persisted booleans.
	 *
	 * @param mixed $value Raw boolean-like value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
