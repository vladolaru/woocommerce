<?php
/**
 * WooPaymentsAccountService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\DefaultPaymentGateways;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Reads and caches the merchant's WooPayments account, and the gateway settings, test mode and dev mode it runs with.
 *
 * Read it from a hook callback on `init` or later: a `register()` reads only options, and before WordPress's pluggable
 * functions exist the account read serves the cache and never fetches.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAccountService implements RegisterHooksInterface {

	private const REPORTS_AREA_FLAG_OPTION = '_wcpay_feature_reports_area';

	private const ONBOARDING_TEST_MODE_OPTION = 'wcpay_onboarding_test_mode';

	private const ONBOARDING_DISABLED_TRANSIENT = 'wcpay_on_boarding_disabled';

	private const ONBOARDING_STRIPE_CONNECTED_OPTION = '_wcpay_onboarding_stripe_connected';

	private const ONBOARDING_CONNECTION_SUCCESS_MODAL_OPTION = 'wcpay_connection_success_modal_dismissed';

	/** Transient holding the hosted KYC state secret (the WooPayments extension's `WC_Payments_Account::ONBOARDING_STATE_TRANSIENT`). */
	public const ONBOARDING_STATE_TRANSIENT = 'wcpay_stripe_onboarding_state';

	private const EMBEDDED_KYC_IN_PROGRESS_OPTION = 'wcpay_onboarding_embedded_kyc_in_progress';

	private const ONBOARDING_INIT_IN_PROGRESS_TRANSIENT = 'wcpay_onboarding_init_in_progress';

	private const TEST_MODE_ENABLED_DATE_OPTION = 'wcpay_test_mode_enabled_date';

	private const TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT = 'wcpay_test_to_live_eligible';

	private const POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT = 'wcpay_post_kyc_activation_eligible';

	private const INCENTIVES_USAGE_OPTION = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments';

	private const INCENTIVES_USAGE_VERSION_OPTION = 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments_version';

	private const DEV_MODE_ENVIRONMENTS = array(
		'development',
		'staging',
	);

	/**
	 * The LegacyProxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Setup tier store.
	 *
	 * @var WooPaymentsSetupTier|null
	 */
	private ?WooPaymentsSetupTier $setup_tier = null;

	/**
	 * Runtime arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter|null
	 */
	private ?WooPaymentsRuntimeArbiter $runtime_arbiter = null;

	/**
	 * Database cache the account and the onboarding fields are read through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param LegacyProxy                    $legacy_proxy    The LegacyProxy instance.
	 * @param WooPaymentsDatabaseCache       $database_cache  Database cache.
	 * @param WooPaymentsSetupTier|null      $setup_tier      Optional setup tier store.
	 * @param WooPaymentsRuntimeArbiter|null $runtime_arbiter Optional payments runtime arbiter.
	 */
	final public function init(
		LegacyProxy $legacy_proxy,
		WooPaymentsDatabaseCache $database_cache,
		?WooPaymentsSetupTier $setup_tier = null,
		?WooPaymentsRuntimeArbiter $runtime_arbiter = null
	): void {
		$this->legacy_proxy    = $legacy_proxy;
		$this->database_cache  = $database_cache;
		$this->setup_tier      = $setup_tier;
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Register the account cache hooks and allow the Stripe redirect host.
	 *
	 * The database cache stops refreshing while an Action Scheduler job runs.
	 */
	public function register() {
		add_action( 'action_scheduler_before_execute', array( $this->database_cache, 'disable_refresh' ) );

		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );

		// Like client 11.1.0, drop the account cache when the WordPress.com connection is made or removed.
		foreach ( array( 'jetpack_site_registered', 'jetpack_site_disconnected' ) as $hook_name ) {
			add_action( $hook_name, array( $this, 'clear_cache' ) );
		}
	}

	/**
	 * Allow redirects to Stripe Connect (`connect.stripe.com`).
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
	 * Get normalized WooPayments account data, refreshing the cache from the platform when a refresh is forced, or when
	 * the cache is stale outside AJAX, cron and Action Scheduler requests.
	 *
	 * A refresh fetches the account, rewrites the cache and, on success, fires `woocommerce_payments_account_refreshed`.
	 * Without a platform connection this returns no account and leaves the cache alone. While plugins load the
	 * connection state is not known yet, so this serves the cache as it is, with no fetch and no write.
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

		if ( is_array( $cache_contents ) && array_key_exists( 'data', $cache_contents ) ) {
			$this->ensure_onboarding_test_mode_for_dev_mode( $cache_contents['data'] );
			if ( $this->is_valid_cached_account( $cache_contents['data'] ) ) {
				$data     = $cache_contents['data'];
				$old_data = $data;
			}
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
				 * Fires after the WooPayments account data is fetched from the platform.
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
	 * Tell whether the site can reach the WooPayments platform: the API client is connected.
	 *
	 * @since 11.2.0
	 * @return bool
	 */
	public function is_platform_connected(): bool {
		return $this->get_api_client()->is_available();
	}

	/**
	 * Get the onboarding fields data through the database cache.
	 *
	 * Successful data is kept for a week under the option and shape the WooPayments extension uses, with the locale stored
	 * in the data so a different locale refetches. Errors back off for minutes and keep the old data. Without a platform
	 * connection this serves whatever is cached, regardless of expiry.
	 *
	 * @since 11.2.0
	 * @param string $locale The locale to translate the fields data into.
	 * @return array<string,mixed>|null The fields data, or null when it could not be retrieved and nothing valid is cached.
	 */
	public function get_onboarding_fields_data( string $locale ): ?array {
		if ( ! $this->is_platform_connected() ) {
			$cache_contents = $this->database_cache->get_contents( WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY );
			$data           = is_array( $cache_contents ) && array_key_exists( 'data', $cache_contents ) ? $cache_contents['data'] : null;

			return is_array( $data ) ? $data : null;
		}

		return $this->database_cache->get_or_add(
			WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY,
			fn() => $this->fetch_onboarding_fields_data( $locale ),
			static fn( $data ): bool => is_array( $data ) && isset( $data['__locale'] ) && $data['__locale'] === $locale
		);
	}

	/**
	 * Drop the cached onboarding fields data so the next read fetches it.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function clear_onboarding_fields_cache(): void {
		$this->database_cache->delete( WooPaymentsDatabaseCache::ONBOARDING_FIELDS_DATA_KEY );
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
	 * Fetch the account from the platform.
	 *
	 * @return array<string,mixed>|false
	 */
	private function fetch_account_data() {
		$api_client = $this->get_api_client();
		if ( ! $api_client->is_available() ) {
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

		$this->ensure_onboarding_test_mode_for_dev_mode( $account_data );
		if ( ! $this->is_valid_cached_account( $account_data ) ) {
			return false;
		}

		return $account_data;
	}

	/**
	 * Delete the account cache, so the next account read fetches the account.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->database_cache->delete( WooPaymentsDatabaseCache::ACCOUNT_KEY );
	}

	/**
	 * Store account data in the account cache as a successful fetch.
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
	 * An onboarding answer carries only part of the account. Storing it marked as never fetched makes the next read fetch
	 * the full account, while the account ID and publishable key stay available if that fetch fails.
	 *
	 * @since 11.2.0
	 * @param array<string,mixed> $account_data Partial account data from an onboarding response.
	 * @return void
	 */
	public function cache_account_data_until_refreshed( array $account_data ): void {
		$cache_contents            = $this->build_account_cache_contents( $account_data, false );
		$cache_contents['fetched'] = 0;
		$this->persist_account_cache( $cache_contents );
	}

	/**
	 * Store an empty account right away, so nothing reads the account while the platform deletes it.
	 *
	 * @return void
	 */
	public function overwrite_cache_with_no_account(): void {
		$this->write_account_cache( array(), false );
	}

	/**
	 * Get the account data stored in the local account cache without forcing a refresh.
	 *
	 * @since 11.0.0
	 * @return array<string,mixed>
	 */
	public function get_stored_account_data(): array {
		$cache = $this->get_account_cache();
		$data  = is_array( $cache ) && array_key_exists( 'data', $cache ) ? $cache['data'] : null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Reset the store's WooPayments account and onboarding state after the platform deleted the account.
	 *
	 * The gateway is turned off and leaves test mode with only cards enabled; the onboarding and incentive options and every
	 * database cache entry go. Runs after the store's own account deletion and on the account.deleted webhook, as client
	 * 11.1.0 cleanup_on_account_reset() does (includes/class-wc-payments-onboarding-service.php:932-965). The NOX profile and
	 * lock and the cached saved payment methods are left to the callers, and no account refresh runs here.
	 *
	 * @since 11.0.0
	 * @return void
	 */
	public function cleanup_after_account_reset(): void {
		$settings = $this->get_stored_gateway_settings();

		$settings['enabled']                        = 'no';
		$settings['test_mode']                      = 'no';
		$settings['upe_enabled_payment_method_ids'] = array( 'card' );

		$this->legacy_proxy->call_function( 'update_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
		$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_STRIPE_CONNECTED_OPTION, array() );
		$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_TEST_MODE_OPTION, 'no', true );
		$this->legacy_proxy->call_function( 'delete_option', self::ONBOARDING_CONNECTION_SUCCESS_MODAL_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_STATE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_option', self::EMBEDDED_KYC_IN_PROGRESS_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_INIT_IN_PROGRESS_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_option', self::TEST_MODE_ENABLED_DATE_OPTION );
		$this->legacy_proxy->call_function( 'delete_transient', self::TEST_TO_LIVE_NOTICE_ELIGIBLE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::POST_KYC_ACTIVATION_ELIGIBLE_TRANSIENT );
		$this->legacy_proxy->call_function( 'delete_transient', self::ONBOARDING_DISABLED_TRANSIENT );

		$this->database_cache->delete_all();

		// The version option is deleted even when a delete hook throws, so a stale usage answer is never left paired with
		// the current logic version.
		try {
			$this->legacy_proxy->call_function( 'delete_option', self::INCENTIVES_USAGE_OPTION );
		} finally {
			$this->legacy_proxy->call_function( 'delete_option', self::INCENTIVES_USAGE_VERSION_OPTION );
		}
	}

	/**
	 * Get the raw persisted account cache wrapper.
	 *
	 * @return array<string,mixed>|false
	 */
	private function get_account_cache() {
		return $this->database_cache->get_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY );
	}

	/**
	 * Write account data with cache metadata.
	 *
	 * Writing reads the stored option again, so a third-party option filter that makes the account cache read throw makes
	 * this write throw too. The failure is logged and the read goes on with the account it has.
	 *
	 * @param mixed $account_data Account data.
	 * @param bool  $errored      Whether the refresh that produced this write errored.
	 * @return void
	 */
	private function write_account_cache( $account_data, bool $errored ): void {
		try {
			$this->persist_account_cache( $this->build_account_cache_contents( $account_data, $errored ) );
		} catch ( \Throwable $e ) {
			$this->log_write_failure( $e );
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
		return $this->database_cache->build_contents( WooPaymentsDatabaseCache::ACCOUNT_KEY, $account_data, $errored );
	}

	/**
	 * Store account cache contents and rewrite the setup tier from them.
	 *
	 * Requests read the setup tier, not the account, to decide which WooPayments classes load, so the tier follows the
	 * account cache writes. Only a write that reads back from the database and records no error rewrites it; any other
	 * write leaves the stored tier as it was.
	 *
	 * @param array<string,mixed> $cache_contents Account cache contents.
	 * @return void
	 */
	private function persist_account_cache( array $cache_contents ): void {
		$this->database_cache->write( WooPaymentsDatabaseCache::ACCOUNT_KEY, $cache_contents );

		if (
			$this->is_persisted_account_cache( $cache_contents ) &&
			null !== $this->runtime_arbiter &&
			true !== ( $cache_contents['errored'] ?? null ) &&
			is_array( $cache_contents['data'] ?? null )
		) {
			$this->sync_setup_tier( $cache_contents['data'], $this->runtime_arbiter->is_extension_owner() );
		}
	}

	/**
	 * Rewrite the setup tier from the stored account cache and gateway settings, without refreshing the account.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $extension_owns_payments True while the WooPayments extension takes payments: the store then reads
	 *                                      `available`. False derives `connected` or `active` from the account and the
	 *                                      gateway's enabled setting.
	 */
	public function sync_setup_tier_from_options( bool $extension_owns_payments ): void {
		if ( null === $this->setup_tier || null === $this->runtime_arbiter ) {
			return;
		}

		$cache_contents = $this->legacy_proxy->call_function( 'get_option', WooPaymentsDatabaseCache::ACCOUNT_KEY );

		$this->sync_setup_tier( is_array( $cache_contents ) ? ( $cache_contents['data'] ?? null ) : null, $extension_owns_payments );
	}

	/**
	 * Write the setup tier for this account data.
	 *
	 * Nothing is written while the WooPayments feature is off. Missing account data keeps the stored tier unless the
	 * WooPayments extension owns payments.
	 *
	 * @param mixed $account_data            Last persisted account data.
	 * @param bool  $extension_owns_payments True while the WooPayments extension takes payments.
	 */
	private function sync_setup_tier( $account_data, bool $extension_owns_payments ): void {
		if ( null === $this->setup_tier || null === $this->runtime_arbiter ) {
			return;
		}

		if ( ! $this->runtime_arbiter->is_builtin_enabled() ) {
			return;
		}

		if ( $extension_owns_payments || array() === $account_data ) {
			$this->setup_tier->write_tier( WooPaymentsSetupTier::AVAILABLE );
			return;
		}

		if ( ! is_array( $account_data ) ) {
			return;
		}

		$account_id = $account_data['account_id'] ?? null;
		if ( ! is_scalar( $account_id ) || '' === (string) $account_id ) {
			return;
		}

		$settings = $this->get_stored_gateway_settings();
		$this->setup_tier->write_tier(
			'yes' === ( $settings['enabled'] ?? null ) ? WooPaymentsSetupTier::ACTIVE : WooPaymentsSetupTier::CONNECTED
		);
	}

	/**
	 * Tell whether the account cache row in the database holds these contents, read past the object cache.
	 *
	 * @param array<string,mixed> $cache_contents Expected account cache contents.
	 * @return bool
	 */
	private function is_persisted_account_cache( array $cache_contents ): bool {
		try {
			$this->legacy_proxy->call_function( 'wp_cache_delete', WooPaymentsDatabaseCache::ACCOUNT_KEY, 'options' );
			$persisted = $this->legacy_proxy->call_function( 'get_option', WooPaymentsDatabaseCache::ACCOUNT_KEY );
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
		return $this->database_cache->should_refresh(
			WooPaymentsDatabaseCache::ACCOUNT_KEY,
			$cache_contents,
			function ( $data ): bool {
				$this->ensure_onboarding_test_mode_for_dev_mode( $data );

				return $this->is_valid_cached_account( $data );
			},
			$force_refresh
		);
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
	 * Get the connected account country, or US when the account data has none.
	 *
	 * Client 11.1.0 falls back to US, never to the store country (`includes/class-wc-payments-account.php:2731-2734`,
	 * `includes/class-wc-payment-gateway-wcpay.php:3604-3613`).
	 *
	 * @since 11.2.0
	 *
	 * @return string Uppercase country code.
	 */
	public function get_account_country_or_us(): string {
		$account_data = $this->get_cached_account_data();
		$country      = isset( $account_data['country'] ) && is_scalar( $account_data['country'] )
			? strtoupper( (string) $account_data['country'] )
			: '';

		return '' !== $country ? $country : 'US';
	}

	/**
	 * Get the connected account's domestic currency, lowercase.
	 *
	 * The account country's locale data gives the currency, and the account default currency is the fallback when the
	 * country has no locale data.
	 *
	 * @since 11.2.0
	 *
	 * @return string
	 */
	public function get_account_domestic_currency(): string {
		$country_locale_data = wc_get_container()->get( MultiCurrencyLocalizationService::class )->get_country_locale_data( $this->get_account_country_or_us() );
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
	 * Reads the account payload's customer_currencies.supported list.
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
	 * Tell whether the account can take payments: it has an ID and a publishable key for the current mode, payments are
	 * enabled and details are submitted.
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
	 * not. With no capabilities reported, as before onboarding, card payments count as active
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
	 * Tell whether ECE confirmation tokens are enabled: an account is cached and does not turn them off, as client 11.1.0
	 * `WC_Payments_Features::is_ece_confirmation_tokens_enabled()` (class-wc-payments-features.php:438-442).
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_ece_confirmation_tokens_enabled(): bool {
		$account_data = $this->get_cached_account_data();

		return array() !== $account_data && empty( $account_data['ece_confirmation_tokens_disabled'] );
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
	 * platform connection the state is known (no account), so an errored entry left from that time does not count. While
	 * plugins load the connection state is not known yet, which is indeterminate.
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
		 * The filter keeps the WooPayments extension's name, so a callback written for it keeps working. It is meant only
		 * for Automattic's own sites; returning true keeps customer IDs, saved payment methods and the WooPay flags the
		 * same across the network.
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
	 * Tell whether the account finished onboarding: details are submitted and card payments were requested.
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
		$account_data = $this->get_stored_account_data();
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
	 * Get the connected account's business name, or '' when the account has none.
	 *
	 * @since 11.2.0
	 * @return string
	 */
	public function get_business_name(): string {
		return $this->get_account_string( 'business_profile', 'name' );
	}

	/**
	 * Get the connected account's business support address, or an empty array when the account has none.
	 *
	 * @since 11.2.0
	 * @return array<string,mixed>
	 */
	public function get_business_support_address(): array {
		$address = $this->get_cached_account_data()['business_profile']['support_address'] ?? array();

		return is_array( $address ) ? $address : array();
	}

	/**
	 * Get the connected account's business support email, or '' when the account has none.
	 *
	 * @since 11.2.0
	 * @return string
	 */
	public function get_business_support_email(): string {
		return $this->get_account_string( 'business_profile', 'support_email' );
	}

	/**
	 * Get the connected account's business support phone, or '' when the account has none.
	 *
	 * @since 11.2.0
	 * @return string
	 */
	public function get_business_support_phone(): string {
		return $this->get_account_string( 'business_profile', 'support_phone' );
	}

	/**
	 * Get the file ID of the connected account's branding logo, or '' when the account has none.
	 *
	 * @since 11.2.0
	 * @return string
	 */
	public function get_branding_logo(): string {
		return $this->get_account_string( 'branding', 'logo' );
	}

	/**
	 * Get a scalar field of a section of the cached account as a string, '' when it is missing, null or not scalar.
	 *
	 * @param string $section Account data section, such as business_profile.
	 * @param string $field   Field in that section.
	 * @return string
	 */
	private function get_account_string( string $section, string $field ): string {
		$value = $this->get_cached_account_data()[ $section ][ $field ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Get the countries where WooPayments accounts are supported: core's WooPayments country list, labelled with
	 * WooCommerce's country names.
	 *
	 * @return array<string,string> Country labels keyed by ISO country code.
	 */
	public function get_supported_countries(): array {
		$all_countries       = WC()->countries->get_countries();
		$supported_countries = array();

		foreach ( DefaultPaymentGateways::get_wcpay_countries() as $country_code ) {
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
	 * Run a callback in the test mode a queued job was scheduled in.
	 *
	 * The callback runs with the `wcpay_test_mode` filter that is_test_mode_enabled() reads set to the job's mode, so its
	 * platform requests use the mode the job recorded even when the store's mode changed since. The filter is removed when
	 * the callback ends.
	 *
	 * @since 11.2.0
	 *
	 * @param bool     $is_test_mode Whether the callback runs in test mode.
	 * @param callable $callback     Callback to run.
	 */
	public function run_in_test_mode_context( bool $is_test_mode, callable $callback ): void {
		$apply_test_mode_context = static function () use ( $is_test_mode ): bool {
			return $is_test_mode;
		};

		add_filter( 'wcpay_test_mode', $apply_test_mode_context );
		try {
			$callback();
		} finally {
			remove_filter( 'wcpay_test_mode', $apply_test_mode_context );
		}
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
	 * @param mixed  $fallback Optional caller fallback, which takes precedence over the form default.
	 * @return mixed Persisted value, caller fallback, form default, or an empty string when no default exists.
	 */
	public function get_gateway_setting( string $key, $fallback = null ) {
		$settings = $this->get_stored_gateway_settings();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		if ( 1 < func_num_args() ) {
			return $fallback;
		}

		return WooPaymentsSettingsDefaults::get( $key ) ?? '';
	}

	/**
	 * Tell whether a yes/no gateway setting is on: `yes`, `1` or true. A setting never saved reads as its form default.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_gateway_setting_enabled( string $key ): bool {
		$value = $this->get_gateway_setting( $key );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Get the gateway settings, a key never saved reading as its form default.
	 *
	 * A store onboarded through the built-in WooPayments stores only the keys onboarding writes until the settings page is
	 * saved; the gateway answers every other key with its form default (WC_Settings_API::get_option()). A settings read
	 * that throws is logged and read as no stored settings.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_gateway_settings(): array {
		return array_replace( WooPaymentsSettingsDefaults::all(), $this->get_stored_gateway_settings() );
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
	 * Tell whether Apple Pay or Google Pay is enabled in their own gateway settings.
	 *
	 * @return bool
	 */
	public function is_payment_request_enabled(): bool {
		foreach ( WooPaymentsGatewaySettingsSynchronizer::PAYMENT_REQUEST_METHOD_IDS as $method_id ) {
			if ( $this->is_payment_request_method_enabled( $method_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether one of the Apple Pay and Google Pay gateways is enabled in its own settings.
	 *
	 * @param string $method_id Apple Pay or Google Pay method ID.
	 * @return bool
	 */
	public function is_payment_request_method_enabled( string $method_id ): bool {
		if ( ! in_array( $method_id, WooPaymentsGatewaySettingsSynchronizer::PAYMENT_REQUEST_METHOD_IDS, true ) ) {
			return false;
		}

		$settings = get_option( WooPaymentsGatewaySettingsSynchronizer::get_split_option_name( $method_id ), array() );

		return is_array( $settings ) && 'yes' === (string) ( $settings['enabled'] ?? 'no' );
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
		$test_mode_onboarding = $this->is_dev_mode_enabled() || $this->get_onboarding_test_mode_option();

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
		return in_array( wp_get_environment_type(), self::DEV_MODE_ENVIRONMENTS, true );
	}

	/**
	 * Tell whether WordPress development mode implies WooPayments dev mode.
	 *
	 * @return bool
	 */
	private function is_wp_development_mode_enabled(): bool {
		return '' !== wp_get_development_mode();
	}

	/**
	 * Get the WooPayments API client, resolved from the container on first use.
	 *
	 * The API client takes this service in its `init()` (test mode, the store ID, the account refresh after VAT details
	 * are saved), so it is not injected here; it is resolved when a read checks the platform connection or fetches.
	 *
	 * @return WooPaymentsApiClient
	 */
	protected function get_api_client(): WooPaymentsApiClient {
		return wc_get_container()->get( WooPaymentsApiClient::class );
	}

	/**
	 * Get the WooCommerce store ID sent with account refresh and onboarding requests.
	 *
	 * @internal
	 *
	 * @return string
	 */
	public function get_woocommerce_store_id(): string {
		$store_id = $this->legacy_proxy->call_function( 'get_option', \WC_Install::STORE_ID_OPTION, '' );

		return is_scalar( $store_id ) ? (string) $store_id : '';
	}

	/**
	 * Get the gateway settings as stored, without the form defaults.
	 *
	 * @return array<string,mixed>
	 */
	private function get_stored_gateway_settings(): array {
		$settings = $this->legacy_proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Tell whether the stored onboarding test-mode option is on, without the dev-mode fallback or the filter.
	 *
	 * @return bool
	 */
	private function get_onboarding_test_mode_option(): bool {
		$value = $this->legacy_proxy->call_function( 'get_option', self::ONBOARDING_TEST_MODE_OPTION, 'no' );

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
		wc_get_logger()->error(
			'WooPayments could not read ' . $what . '; treating it as missing.',
			array_merge( WooPaymentsLogger::get_failure_context( $throwable ), array( 'source' => WooPaymentsLogger::SOURCE ) )
		);
	}

	/**
	 * Log an account cache write or setup-tier sync that threw, whatever the logging setting.
	 *
	 * The throw comes from the cache write, so the account is returned without being stored and the next request refreshes
	 * again, or from the setup-tier sync after the row is stored. Either way the account read returns the account it has.
	 *
	 * @param \Throwable $throwable Caught throwable.
	 */
	private function log_write_failure( \Throwable $throwable ): void {
		wc_get_logger()->error(
			'WooPayments could not write the account cache or sync the setup tier; the fetched account was returned.',
			array_merge( WooPaymentsLogger::get_failure_context( $throwable ), array( 'source' => WooPaymentsLogger::SOURCE ) )
		);
	}

	/**
	 * Turn onboarding test mode on for a test account read in dev mode while the option is off.
	 *
	 * Runs right before each account validity check, on cache reads as well as refreshes (client 11.1.0 `includes/class-wc-payments-account.php:2554-2583`).
	 *
	 * @param mixed $account_data Account data.
	 */
	private function ensure_onboarding_test_mode_for_dev_mode( $account_data ): void {
		// The same early exits as is_valid_cached_account(); keep the two in step.
		if ( ! is_array( $account_data ) || array() === $account_data || $this->is_truthy( $account_data['is_live'] ?? false ) ) {
			return;
		}

		if ( $this->get_onboarding_test_mode_option() || ! $this->is_dev_mode_enabled() ) {
			return;
		}

		// Autoloaded: storefront renders read it through is_test_mode_enabled().
		$this->legacy_proxy->call_function( 'update_option', self::ONBOARDING_TEST_MODE_OPTION, 'yes', true );
		$this->legacy_proxy->call_function( 'wp_cache_delete', self::ONBOARDING_TEST_MODE_OPTION, 'options' );
	}

	/**
	 * Tell whether account data is valid to read from or write to the cache.
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
