<?php
/**
 * PreflightService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountMilestonesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreSetupReportController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the conditions that block the cutover on a site.
 *
 * It also finds and deactivates the active WooPayments extension, and reads the extension version the store last ran.
 *
 * @since 11.2.0
 * @internal
 */
class PreflightService {

	/**
	 * Oldest WooPayments extension version the cutover switches from.
	 *
	 * The data migration removes only the retired settings, options and transients this version no longer reads, so
	 * re-activating the extension at this version still finds what it reads; it no longer reads the appearance caches the
	 * migration clears. It is also the first version that reads every order, token and setting the built-in WooPayments
	 * writes for payments, cards and renewals: 10.6.0 loads Amazon Pay tokens and registers the Amazon Pay gateway by
	 * default (class-wc-payments.php:516, class-wc-payments-features.php:381-382).
	 */
	public const MINIMUM_CUTOVER_PLUGIN_VERSION = '10.6.0';

	/** The WooPayments feature is off on this site, so no other check runs. */
	public const FAILURE_BUILTIN_RUNTIME_DISABLED = 'builtin_runtime_disabled';

	/** The recorded WooPayments extension version is below MINIMUM_CUTOVER_PLUGIN_VERSION. */
	public const FAILURE_PLUGIN_VERSION_UNSUPPORTED = 'woopayments_plugin_version_unsupported';

	/** The built-in WooPayments cannot process payments for this account yet. */
	public const FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE = 'builtin_transport_unavailable';

	/** A currency on automatic exchange rates has no rate provider. */
	public const FAILURE_MULTI_CURRENCY_RATES_UNAVAILABLE = 'multi_currency_rates_unavailable';

	/** A queued WooPayments action has no built-in handler. */
	public const FAILURE_QUEUE_HOOKS_UNHANDLED = 'operational_queue_hooks_unhandled';

	/** The store bills subscriptions through the WooPayments extension's bundled subscriptions; such a store is excluded. */
	public const FAILURE_BUNDLED_SUBSCRIPTIONS = 'bundled_stripe_billing_subscriptions_present';

	/** A plugin that charges renewals through the WooPayments extension's own classes is active; the switch waits while it is. */
	public const FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE = 'extension_renewal_plugin_active';

	private const IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK = 'woocommerce_woopayments_identifier_placeholders_unavailable';

	private const OPERATIONAL_QUEUE_QUERY_FAILED_HOOK = 'woocommerce_woopayments_operational_queue_query_failed';


	/**
	 * Action Scheduler hooks the built-in WooPayments handles, so a queued action on one does not block the cutover.
	 *
	 * The reconciliation job's ADOPTED_QUEUE_HOOKS lists the ones it records as carried over.
	 *
	 * @var string[]
	 */
	private const BUILTIN_HANDLED_QUEUE_HOOKS = array(
		WooPaymentsStoreSetupReportController::STORE_SETUP_SYNC_ACTION,
		WooPaymentsTokenService::UPDATE_SAVED_PAYMENT_METHOD_ACTION,
		WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
		WooPaymentsStoreSetupReportController::UPDATE_COMPATIBILITY_DATA_ACTION,
		WooPaymentsAccountMilestonesController::INSTANT_DEPOSIT_REMINDER_ACTION,
		WooPaymentsAccountMilestonesController::POST_KYC_ACTIVATION_EMAIL_SEND_ACTION,
		WooPaymentsFraudOrderTrackingService::TRACK_NEW_ORDER_ACTION,
		WooPaymentsFraudOrderTrackingService::TRACK_UPDATE_ORDER_ACTION,
		WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK,
		WooPaymentsCanceledAuthorizationFeeRemediationService::BUILTIN_CHECK_HOOK,
		WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION,
		WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
		// Stripe Billing migration off Stripe, continued by the built-in WooPayments' migrator after the switch.
		'wcpay_schedule_subscription_migrations',
		'wcpay_migrate_subscription',
		'wcpay_migrate_subscription_retry',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Built-in WooPayments provider.
	 *
	 * @var WooPaymentsProvider|null
	 */
	private ?WooPaymentsProvider $provider = null;

	/**
	 * Plugins that charge renewals through the WooPayments extension.
	 *
	 * @var ExtensionBoundRenewalPlugins|null
	 */
	private ?ExtensionBoundRenewalPlugins $renewal_plugins = null;

	/**
	 * Bundled subscriptions guard.
	 *
	 * @var BundledSubscriptionsGuard|null
	 */
	private ?BundledSubscriptionsGuard $bundled_subscriptions_guard = null;

	/**
	 * Platform connection readiness service.
	 *
	 * @var PlatformConnectionService|null
	 */
	private ?PlatformConnectionService $platform_connection_service = null;

	/**
	 * Rate account boundary.
	 *
	 * @var WooPaymentsNativeAccountAdapter|null
	 */
	private ?WooPaymentsNativeAccountAdapter $rate_account = null;

	/**
	 * Rate API client boundary.
	 *
	 * @var WooPaymentsNativeApiClientAdapter|null
	 */
	private ?WooPaymentsNativeApiClientAdapter $rate_api_client = null;

	/**
	 * Request-local reconciliation failures keyed by blog ID.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $reconciliation_memo = array();

	/**
	 * Request-local header versions of the installed WooPayments extension, keyed by plugin file ('' when unreadable).
	 *
	 * @var array<string,string>
	 */
	private array $installed_plugin_versions = array();

	/**
	 * Initialize the service instance.
	 *
	 * The check collaborators are resolved when a preflight runs, so registering the cutover surfaces loads none of them.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter      Runtime owner arbiter.
	 * @param LegacyProxy               $legacy_proxy Legacy proxy.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy ): void {
		$this->arbiter      = $arbiter;
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Invalidate request-local facts for the current blog.
	 */
	public function invalidate_current_blog_memoization(): void {
		$blog_id = get_current_blog_id();
		unset( $this->reconciliation_memo[ $blog_id ] );
		$this->installed_plugin_versions = array();
	}

	/**
	 * Get the conditions that block the cutover on this site.
	 *
	 * @return string[] Failure codes.
	 */
	public function get_failures(): array {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->reconciliation_memo ) ) {
			$this->reconciliation_memo[ $blog_id ] = $this->evaluate_failures();
		}
		return $this->reconciliation_memo[ $blog_id ];
	}

	/**
	 * Evaluate the cutover failures for the current site.
	 *
	 * @return string[] Failure codes.
	 */
	private function evaluate_failures(): array {
		if ( ! $this->arbiter->is_builtin_enabled() ) {
			return array( self::FAILURE_BUILTIN_RUNTIME_DISABLED );
		}

		$failures = array();
		if ( ! $this->is_woopayments_plugin_version_supported() ) {
			$failures[] = self::FAILURE_PLUGIN_VERSION_UNSUPPORTED;
		}
		if ( ! $this->is_builtin_transport_ready() ) {
			$failures[] = self::FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE;
		}
		$failures = array_merge( $failures, $this->get_platform_connection_service()->get_preflight_failures() );
		if ( $this->has_unavailable_multi_currency_rate_provider() ) {
			$failures[] = self::FAILURE_MULTI_CURRENCY_RATES_UNAVAILABLE;
		}
		if ( array() !== $this->get_unhandled_queue_hooks() ) {
			$failures[] = self::FAILURE_QUEUE_HOOKS_UNHANDLED;
		}
		if ( $this->get_bundled_subscriptions_guard()->is_bundled_subscriptions_store() ) {
			$failures[] = self::FAILURE_BUNDLED_SUBSCRIPTIONS;
		}
		if ( array() !== $this->get_renewal_plugins()->get_active_plugin_files() ) {
			$failures[] = self::FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE;
		}

		return self::normalize_string_list( $failures );
	}

	/**
	 * Get pending or running WooPayments Action Scheduler actions (wcpay_ and woocommerce_woopayments_ hooks), one per hook and group.
	 *
	 * Callers only decide by hook and group, so a queue of thousands of same-hook actions is read as one row. Its due time is
	 * the latest of the group's pending and running actions, so one queued far ahead counts even when another is due now;
	 * its earliest due time is the first of them, so one long overdue counts even when others are due now. A zero date sorts
	 * first, so it is the earliest and reads as none; the earliest is also none when any action in the group has no date.
	 * On a database without identifier placeholders or a failed query, returns one sentinel row (action_id 0, no due times)
	 * whose hook is one of the *_HOOK constants, which blocks the switch.
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string,due_at:int|null,earliest_due_at:int|null}> One row per hook and group, ordered by action ID.
	 */
	public function get_queued_woopayments_actions(): array {
		$wpdb = $this->get_database();
		if ( empty( $wpdb->actionscheduler_actions ) || empty( $wpdb->actionscheduler_groups ) ) {
			return array();
		}
		if ( ! $wpdb->has_cap( 'identifier_placeholders' ) ) {
			return array(
				array(
					'action_id'       => 0,
					'hook'            => self::IDENTIFIER_PLACEHOLDERS_UNAVAILABLE_HOOK,
					'group'           => '',
					'due_at'          => null,
					'earliest_due_at' => null,
				),
			);
		}
		$query   = $wpdb->prepare(
			'SELECT MIN( actions.action_id ) AS action_id, actions.hook, action_groups.slug AS action_group, MAX( actions.scheduled_date_gmt ) AS due_gmt, MIN( actions.scheduled_date_gmt ) AS earliest_gmt, COUNT( * ) - COUNT( actions.scheduled_date_gmt ) AS undated FROM %i AS actions LEFT JOIN %i AS action_groups ON actions.group_id = action_groups.group_id WHERE actions.status IN ( %s, %s ) AND ( actions.hook LIKE %s OR actions.hook LIKE %s ) GROUP BY actions.hook, action_groups.slug ORDER BY action_id ASC',
			$wpdb->actionscheduler_actions,
			$wpdb->actionscheduler_groups,
			\ActionScheduler_Store::STATUS_PENDING,
			\ActionScheduler_Store::STATUS_RUNNING,
			$wpdb->esc_like( 'wcpay_' ) . '%',
			$wpdb->esc_like( 'woocommerce_woopayments_' ) . '%'
		);
		$actions = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One request-memoized indexed operational preflight scan.
		if ( ! is_array( $actions ) || '' !== $wpdb->last_error ) {
			return array(
				array(
					'action_id'       => 0,
					'hook'            => self::OPERATIONAL_QUEUE_QUERY_FAILED_HOOK,
					'group'           => '',
					'due_at'          => null,
					'earliest_due_at' => null,
				),
			);
		}

		return array_map(
			static function ( array $action ): array {
				return array(
					'action_id'       => (int) $action['action_id'],
					'hook'            => (string) $action['hook'],
					'group'           => isset( $action['action_group'] ) ? (string) $action['action_group'] : '',
					'due_at'          => self::parse_due_at( $action['due_gmt'] ?? null ),
					'earliest_due_at' => 0 === (int) ( $action['undated'] ?? 1 ) ? self::parse_due_at( $action['earliest_gmt'] ?? null ) : null,
				);
			},
			$actions
		);
	}

	/**
	 * Read an Action Scheduler due date, stored in UTC as Y-m-d H:i:s.
	 *
	 * Rows written by an older Action Scheduler copy can carry a zero date for an async action. A date that does not read
	 * back as itself, as a zero date does not, has no due time.
	 *
	 * @param mixed $value Stored due date.
	 * @return int|null Unix timestamp, or null when the date is zero or unreadable.
	 */
	private static function parse_due_at( $value ): ?int {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * Get operational actions after excluding the cutover job itself.
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string,due_at:int|null,earliest_due_at:int|null}> Actions ordered by ID.
	 */
	public function get_queued_operational_actions(): array {
		return array_values( array_filter( $this->get_queued_woopayments_actions(), array( $this, 'is_not_cutover_action' ) ) );
	}

	/**
	 * Resolve the active WooPayments plugin file.
	 *
	 * @return string Plugin file, or an empty string when unresolved.
	 */
	public function get_active_woopayments_plugin_file(): string {
		foreach ( (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		foreach ( array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		return '';
	}

	/**
	 * Tell whether WooPayments is active for the current site.
	 *
	 * @since 11.2.0
	 */
	public function is_woopayments_site_active(): bool {
		foreach ( (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell whether WooPayments is active network-wide.
	 *
	 * A single site has no network plugin list, so it is not read there.
	 */
	public function is_woopayments_network_active(): bool {
		if ( ! $this->legacy_proxy->call_function( 'is_multisite' ) ) {
			return false;
		}
		foreach ( array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell whether WooPayments is in this site's stored active plugin list.
	 *
	 * The switch's own checks read the stored lists: a plugin load manager can filter the list in the queue requests the
	 * switch runs in, and the stored list is what every later request starts from.
	 *
	 * @since 11.4.0
	 *
	 * @return bool|null Whether the stored list holds WooPayments, or null when it could not be read.
	 */
	public function is_woopayments_stored_site_active(): ?bool {
		return $this->holds_woopayments( $this->read_stored_site_plugins() );
	}

	/**
	 * Tell whether WooPayments is in this network's stored active plugin list. A single site has no network list.
	 *
	 * @since 11.4.0
	 *
	 * @return bool|null Whether the stored list holds WooPayments, or null when it could not be read.
	 */
	public function is_woopayments_stored_network_active(): ?bool {
		return $this->holds_woopayments( $this->read_stored_network_plugins() );
	}

	/**
	 * Tell whether WooPayments is in this site's or this network's stored active plugin list: the switch's reading of
	 * whether the extension is back after the hand-over.
	 *
	 * @since 11.4.0
	 *
	 * @return bool|null True when either list holds WooPayments, false when both were read without it, or null when a list
	 *                   that could hold it could not be read.
	 */
	public function is_woopayments_in_stored_lists(): ?bool {
		$site = $this->holds_woopayments( $this->read_stored_site_plugins() );
		if ( true === $site ) {
			return true;
		}
		$network = $this->holds_woopayments( $this->read_stored_network_plugins() );
		if ( true === $network ) {
			return true;
		}

		return null === $site || null === $network ? null : false;
	}

	/**
	 * Tell whether this process runs the WooPayments extension's code while neither stored plugin list holds it, so the
	 * switch's deactivation could not turn it off.
	 *
	 * Only a request that began after the lists were written can read this as the site's setup, so the admin side withholds
	 * Start on it and the switch's own preflight does not ask: a queue process can have loaded the code before the merchant
	 * deactivated the extension. The stored lists are read only when the code is loaded. A list that could not be read
	 * withholds nothing: finalize reads the lists again before it deactivates anything.
	 *
	 * @since 11.4.0
	 *
	 * @return bool
	 */
	public function is_woopayments_loaded_outside_plugin_lists(): bool {
		return $this->arbiter->is_extension_code_loaded() && false === $this->is_woopayments_in_stored_lists();
	}

	/**
	 * Deactivate WooPayments on the current site only, against the stored active plugin list.
	 *
	 * WordPress's deactivate_plugins() reads active_plugins through get_option() and writes back what it read. In a queue
	 * request a plugin load manager can filter that list, so the call would skip a hidden extension, or write the list back
	 * without the plugins hidden in that request and so deactivate them for the whole store. For this one call each read of
	 * active_plugins queries the current blog's stored row. Every read that works gives that row, unless a read filter that
	 * runs after this one replaces the answer. WordPress's write is not atomic against a nested plugin change: it writes the
	 * list it read before its hooks ran. When a read fails, arrives while this filter's own query runs, or arrives while
	 * WordPress logs a query, WordPress answers as if this filter were not there.
	 *
	 * An extension that is not active counts as deactivated: a retry after a crash that followed a successful deactivation
	 * finds nothing to deactivate. An extension active for every site stays active, and this reports false. So does a stored
	 * list that could not be read, before or after the call.
	 */
	public function deactivate_woopayments_plugin(): bool {
		// WordPress 6.8+ loads this file on every request (#62244); kept as WooCommerce's own pattern (see src/Admin/PluginsHelper.php).
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$site_plugins = $this->read_stored_site_plugins();
		if ( null === $site_plugins ) {
			return false;
		}
		$plugin_file = $this->find_woopayments_plugin_file( $site_plugins );
		if ( '' === $plugin_file ) {
			$network_plugins = $this->read_stored_network_plugins();
			if ( null === $network_plugins ) {
				return false;
			}
			$plugin_file = $this->find_woopayments_plugin_file( $network_plugins );
		}
		if ( '' === $plugin_file ) {
			return true;
		}
		// get_option() runs the pre_option_{$option} filter and then the generic pre_option filter (WordPress 6.1+), and a value
		// from it skips every option_active_plugins callback. Write-side filters stay, so a tool that guards its own writes still does.
		// Each read queries the stored row of the blog current at that moment, before and after WordPress's write alike.
		// A read that fails returns the value this filter was given, so WordPress answers as it would without the filter.
		// A listener on the database's query filter that reads plugin activity would re-enter this callback from inside its
		// query, without end. The flag only marks that query as running; a read that arrives meanwhile gets the value it was given.
		// WordPress runs its query log hook after a query runs and before it reads that query's result, so a query of ours there
		// would replace the result; a read that arrives while that hook runs gets the value it was given too.
		$stored_view = function ( $pre, $option ) {
			static $querying = false;
			if ( 'active_plugins' !== $option || $querying || doing_filter( 'log_query_custom_data' ) ) {
				return $pre;
			}
			$querying = true;
			try {
				$stored = $this->read_stored_site_plugins();
			} finally {
				$querying = false;
			}
			return $stored ?? $pre;
		};
		add_filter( 'pre_option', $stored_view, PHP_INT_MAX, 2 );
		try {
			$this->legacy_proxy->call_function( 'deactivate_plugins', $plugin_file, false, false );
		} finally {
			remove_filter( 'pre_option', $stored_view, PHP_INT_MAX );
		}
		// A write that failed, or that a guard turned back, leaves the extension in the stored list, which these reads find.
		return false === $this->is_woopayments_stored_site_active() && false === $this->is_woopayments_stored_network_active();
	}

	/**
	 * Find the WooPayments plugin file in a plugin list.
	 *
	 * @param array<int|string,mixed> $plugin_files Plugin files.
	 * @return string The plugin file, or an empty string when the list does not hold it.
	 */
	private function find_woopayments_plugin_file( array $plugin_files ): string {
		foreach ( $plugin_files as $plugin_file ) {
			if ( is_string( $plugin_file ) && $this->is_woopayments_plugin_file( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		return '';
	}

	/**
	 * Tell whether a stored plugin list holds WooPayments.
	 *
	 * The start notice asks on every admin page of an extension store, so the canonical path is looked for in the whole list
	 * first; the installed plugin registry is read, once, only when the list does not hold it.
	 *
	 * @param array<int|string,mixed>|null $plugin_files Plugin files, or null for a list that could not be read.
	 * @return bool|null Whether the list holds WooPayments, or null when it could not be read.
	 */
	private function holds_woopayments( ?array $plugin_files ): ?bool {
		if ( null === $plugin_files ) {
			return null;
		}
		if ( in_array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, $plugin_files, true ) ) {
			return true;
		}
		$plugins = null;
		foreach ( $plugin_files as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}
			$plugins = $plugins ?? $this->get_installed_plugins();
			if ( self::has_woopayments_header( $plugins, $plugin_file ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read this site's active plugin list as stored, by SQL, so no filter or cache answers.
	 *
	 * A failed query is told apart from an empty list by the database's last error, as get_queued_woopayments_actions() does.
	 *
	 * @return array<int|string,mixed>|null The stored list, empty when the row is missing; null when the query failed.
	 */
	private function read_stored_site_plugins(): ?array {
		$wpdb  = $this->get_database();
		$value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored value is the point: filters and caches can differ from it.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		$value = is_string( $value ) ? maybe_unserialize( $value ) : array();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read this network's active plugin list as stored, by SQL; WordPress keys it by plugin file.
	 *
	 * @return array<int|string,mixed>|null Plugin files, empty on a single site or when the row is missing; null when the
	 *                                      query failed.
	 */
	private function read_stored_network_plugins(): ?array {
		// A single site has no sitemeta table. Not read through the legacy proxy: a stand-in answer would point the query at a table the install lacks.
		if ( ! is_multisite() ) {
			return array();
		}
		$wpdb  = $this->get_database();
		$value = $wpdb->get_var( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = " . absint( get_current_network_id() ) . " AND meta_key = 'active_sitewide_plugins' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored value is the point: filters and caches can differ from it.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		$value = is_string( $value ) ? maybe_unserialize( $value ) : array();
		return is_array( $value ) ? array_keys( $value ) : array();
	}

	/**
	 * Tell whether the WooPayments extension supports the switch: the version this store last ran, and the code a rollback
	 * would run.
	 *
	 * An extension downgraded by hand never lowers the version it recorded, so the lower of the two decides. Without a
	 * running version or readable files the recorded version decides alone.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_woopayments_plugin_version_supported(): bool {
		$version = get_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION, '' );
		if ( ! is_string( $version ) || 1 !== preg_match( '/^\\d+(?:\\.\\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/D', trim( $version ) ) || version_compare( trim( $version ), self::MINIMUM_CUTOVER_PLUGIN_VERSION, '<' ) ) {
			return false;
		}

		$code_version = $this->get_woopayments_code_version();
		return '' === $code_version || version_compare( $code_version, self::MINIMUM_CUTOVER_PLUGIN_VERSION, '>=' );
	}

	/**
	 * Get the version of the WooPayments code a rollback would run. While the extension owns payments that is the version it
	 * loaded, which a retry in the request that updated it also still runs; otherwise, or when the loaded extension declares
	 * no version, the installed files' header.
	 *
	 * @return string The version, or an empty string when neither can be read.
	 */
	private function get_woopayments_code_version(): string {
		// Only while the extension owns payments: the built-in WooPayments' compatibility facades define the constant too.
		if ( $this->arbiter->is_extension_owner() && $this->legacy_proxy->call_function( 'defined', 'WCPAY_VERSION_NUMBER' ) ) {
			$loaded_version = $this->legacy_proxy->call_function( 'constant', 'WCPAY_VERSION_NUMBER' );
			if ( is_scalar( $loaded_version ) && '' !== trim( (string) $loaded_version ) ) {
				return trim( (string) $loaded_version );
			}
		}

		return $this->get_installed_woopayments_plugin_version();
	}

	/**
	 * Read the header version of the installed WooPayments extension: the active plugin file, or the standard one when the
	 * extension is inactive. An inactive extension in another folder is not looked for, since that takes reading every
	 * installed plugin's header.
	 *
	 * @return string Header version, or an empty string when the file cannot be read.
	 */
	private function get_installed_woopayments_plugin_version(): string {
		$plugin_file = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$active      = (array) $this->legacy_proxy->call_function( 'get_option', 'active_plugins', array() );
		// A single site has no network plugin list; reading it would query a missing option.
		if ( $this->legacy_proxy->call_function( 'is_multisite' ) ) {
			$active = array_merge( $active, array_keys( (array) $this->legacy_proxy->call_function( 'get_site_option', 'active_sitewide_plugins', array() ) ) );
		}
		// Matched by the main file name, as the runtime arbiter does, so no installed plugin's header is read to find it.
		foreach ( $active as $active_file ) {
			if ( is_string( $active_file ) && WooPaymentsRuntimeArbiter::is_woopayments_plugin_file( $active_file ) ) {
				$plugin_file = $active_file;
				break;
			}
		}
		if ( ! array_key_exists( $plugin_file, $this->installed_plugin_versions ) ) {
			// WordPress 6.8+ loads this file on every request (#62244); kept as WooCommerce's own pattern (see src/Admin/PluginsHelper.php).
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$path    = WP_PLUGIN_DIR . '/' . $plugin_file;
			$header  = $this->legacy_proxy->call_function( 'is_readable', $path ) ? $this->legacy_proxy->call_function( 'get_plugin_data', $path, false, false ) : array();
			$version = is_array( $header ) && isset( $header['Version'] ) && is_scalar( $header['Version'] ) ? trim( (string) $header['Version'] ) : '';

			$this->installed_plugin_versions[ $plugin_file ] = $version;
		}

		return $this->installed_plugin_versions[ $plugin_file ];
	}

	/**
	 * Determine whether the built-in transport is ready.
	 *
	 * @return bool
	 */
	private function is_builtin_transport_ready(): bool {
		try {
			return $this->get_provider()->can_process_payments();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Determine whether automatic multi-currency rates lack a provider.
	 *
	 * @return bool
	 */
	private function has_unavailable_multi_currency_rate_provider(): bool {
		if ( ! $this->has_automatic_multi_currency_rate_currencies() ) {
			return false;
		}
		return ! ( $this->get_rate_api_client()->is_server_connected() && $this->get_rate_account()->is_provider_connected() && ! $this->get_rate_account()->is_account_rejected() );
	}

	/**
	 * Determine whether any enabled currencies use automatic rates.
	 *
	 * @return bool
	 */
	private function has_automatic_multi_currency_rate_currencies(): bool {
		if ( '1' !== (string) get_option( MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '1' ) ) {
			return false;
		}
		$enabled_currencies = get_option( StateBuilder::ENABLED_CURRENCIES_OPTION, array() );
		if ( ! is_array( $enabled_currencies ) || array() === $enabled_currencies ) {
			return false;
		}
		$store_currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		foreach ( $enabled_currencies as $currency_code ) {
			if ( ! is_scalar( $currency_code ) ) {
				continue;
			}
			$currency_code = strtoupper( trim( (string) $currency_code ) );
			if ( '' !== $currency_code && $store_currency !== $currency_code && 'manual' !== (string) get_option( 'wcpay_multi_currency_exchange_rate_' . strtolower( $currency_code ), 'automatic' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the queued WooPayments hooks the built-in WooPayments does not handle, without running the other checks.
	 *
	 * The reconciliation job asks again just before finalize, for an extension action queued since the preflight ran.
	 *
	 * @return string[]
	 */
	public function get_unhandled_queue_hooks(): array {
		return self::normalize_string_list( array_column( $this->get_unhandled_queue_actions(), 'hook' ) );
	}

	/**
	 * Get the queued WooPayments actions the built-in WooPayments does not handle, one row per hook and group, with their
	 * due times; a sentinel row when the queue could not be read.
	 *
	 * @since 11.4.0
	 *
	 * @return array<int,array{action_id:int,hook:string,group:string,due_at:int|null,earliest_due_at:int|null}>
	 */
	public function get_unhandled_queue_actions(): array {
		return array_values(
			array_filter(
				$this->get_queued_operational_actions(),
				static fn( array $action ): bool => '' !== trim( $action['hook'] ) && ! in_array( trim( $action['hook'] ), self::BUILTIN_HANDLED_QUEUE_HOOKS, true )
			)
		);
	}

	/**
	 * Determine whether an action is outside the active cutover job identity.
	 *
	 * @param array{hook:string,group:string} $action Action Scheduler identity.
	 * @return bool
	 */
	private function is_not_cutover_action( array $action ): bool {
		if ( CutoverActionScheduler::ACTION_GROUP !== $action['group'] ) {
			return true;
		}
		return CutoverActionScheduler::ACTION_HOOK !== $action['hook'];
	}

	/**
	 * Determine whether a plugin file belongs to WooPayments.
	 *
	 * Stricter than WooPaymentsRuntimeArbiter::is_woopayments_plugin_file(): a file outside the canonical folder also needs the
	 * WooPayments header, because the switch deactivates and updates the file this finds.
	 *
	 * @param string $plugin_file Plugin file identifier.
	 * @return bool
	 */
	private function is_woopayments_plugin_file( string $plugin_file ): bool {
		if ( WooPaymentsRuntimeArbiter::PLUGIN_FILE === $plugin_file ) {
			return true;
		}
		return self::has_woopayments_header( $this->get_installed_plugins(), $plugin_file );
	}

	/**
	 * Read the installed plugin registry.
	 *
	 * @return mixed The registry keyed by plugin file, as get_plugins() returns it.
	 */
	private function get_installed_plugins() {
		// WordPress 6.8+ loads this file on every request (#62244); kept as WooCommerce's own pattern (see src/Admin/PluginsHelper.php).
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return $this->legacy_proxy->call_function( 'get_plugins' );
	}

	/**
	 * Tell whether the installed plugin registry gives a plugin file the WooPayments name or text domain.
	 *
	 * @param mixed  $plugins     The installed plugin registry.
	 * @param string $plugin_file Plugin file identifier.
	 * @return bool
	 */
	private static function has_woopayments_header( $plugins, string $plugin_file ): bool {
		if ( ! is_array( $plugins ) || ! isset( $plugins[ $plugin_file ] ) || ! is_array( $plugins[ $plugin_file ] ) ) {
			return false;
		}
		$plugin_data = $plugins[ $plugin_file ];
		$name        = isset( $plugin_data['Name'] ) && is_scalar( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';
		$text_domain = isset( $plugin_data['TextDomain'] ) && is_scalar( $plugin_data['TextDomain'] ) ? (string) $plugin_data['TextDomain'] : '';
		return 'WooPayments' === $name || 'woocommerce-payments' === $text_domain;
	}

	/**
	 * Get the WordPress database connection.
	 *
	 * @return \wpdb
	 */
	protected function get_database(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Normalize scalar values into a unique non-empty string list.
	 *
	 * @param array<mixed> $values Values to normalize.
	 * @return string[]
	 */
	private static function normalize_string_list( array $values ): array {
		$normalized = array();
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value && ! in_array( $value, $normalized, true ) ) {
				$normalized[] = $value;
			}
		}
		return $normalized;
	}

	/**
	 * Get the built-in WooPayments provider, resolving it on first use.
	 *
	 * @return WooPaymentsProvider
	 */
	private function get_provider(): WooPaymentsProvider {
		if ( null === $this->provider ) {
			$this->provider = wc_get_container()->get( WooPaymentsProvider::class );
		}

		return $this->provider;
	}

	/**
	 * Get the plugins that charge renewals through the WooPayments extension, resolving them on first use.
	 *
	 * @return ExtensionBoundRenewalPlugins
	 */
	private function get_renewal_plugins(): ExtensionBoundRenewalPlugins {
		if ( null === $this->renewal_plugins ) {
			$this->renewal_plugins = wc_get_container()->get( ExtensionBoundRenewalPlugins::class );
		}

		return $this->renewal_plugins;
	}

	/**
	 * Get the bundled subscriptions guard, resolving it on first use.
	 *
	 * @return BundledSubscriptionsGuard
	 */
	private function get_bundled_subscriptions_guard(): BundledSubscriptionsGuard {
		if ( null === $this->bundled_subscriptions_guard ) {
			$this->bundled_subscriptions_guard = wc_get_container()->get( BundledSubscriptionsGuard::class );
		}

		return $this->bundled_subscriptions_guard;
	}

	/**
	 * Get the platform connection readiness service, resolving it on first use.
	 *
	 * @return PlatformConnectionService
	 */
	private function get_platform_connection_service(): PlatformConnectionService {
		if ( null === $this->platform_connection_service ) {
			$this->platform_connection_service = wc_get_container()->get( PlatformConnectionService::class );
		}

		return $this->platform_connection_service;
	}

	/**
	 * Get the rate account boundary, resolving it on first use.
	 *
	 * @return WooPaymentsNativeAccountAdapter
	 */
	private function get_rate_account(): WooPaymentsNativeAccountAdapter {
		if ( null === $this->rate_account ) {
			$this->rate_account = wc_get_container()->get( WooPaymentsNativeAccountAdapter::class );
		}

		return $this->rate_account;
	}

	/**
	 * Get the rate API client boundary, resolving it on first use.
	 *
	 * @return WooPaymentsNativeApiClientAdapter
	 */
	private function get_rate_api_client(): WooPaymentsNativeApiClientAdapter {
		if ( null === $this->rate_api_client ) {
			$this->rate_api_client = wc_get_container()->get( WooPaymentsNativeApiClientAdapter::class );
		}

		return $this->rate_api_client;
	}
}
