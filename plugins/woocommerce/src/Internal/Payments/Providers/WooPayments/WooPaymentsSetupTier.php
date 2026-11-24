<?php
/**
 * WooPaymentsSetupTier class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;

/**
 * Stores the built-in WooPayments setup tier, reports the effective tier and lists the classes each request type loads for it.
 *
 * The effective tier is `disabled` while the WooPayments feature is off, the stored tier while the built-in WooPayments
 * owns payments, and `available` otherwise: while the WooPayments extension owns payments, or while a store that ran it
 * waits for its one-click switch (WooPaymentsRuntimeArbiter::is_awaiting_switch()). The stored tier is `disabled` until the
 * built-in WooPayments is first set up (the install value), then `available`, `connected` or `active`. In the two
 * `available` cases the stored tier is not read: it may hold `available`, or the tier of an earlier switch.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsSetupTier {

	/** The option that stores the setup tier. */
	public const OPTION_NAME = 'woocommerce_woopayments_setup_tier';

	/** The built-in WooPayments is unavailable: the feature is off, or the built-in WooPayments owns payments and was never set up. */
	public const DISABLED = 'disabled';

	/** The built-in WooPayments can be offered, or a store on the WooPayments extension can switch to it. */
	public const AVAILABLE = 'available';

	/** A WooPayments account is connected, without checkout enabled. */
	public const CONNECTED = 'connected';

	/**
	 * WooPayments checkout is enabled.
	 *
	 * The card gateway is enabled, so checkout classes load; the gateways offer nothing until the account can take payments.
	 * Client 11.1.0 builds and hooks its checkout code on every request, before KYC (`includes/class-wc-payments.php:647-650`).
	 */
	public const ACTIVE = 'active';

	/**
	 * The Action Scheduler hook of the re-check that reads the account after the tier dropped from connected or active.
	 *
	 * It lives here, not on WooPaymentsSetupTierRecheck, so registering the hook loads no class.
	 */
	public const RECHECK_HOOK = 'woocommerce_woopayments_setup_tier_recheck';

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Request-local setup tiers keyed by blog ID.
	 *
	 * @var array<int,string>
	 */
	private array $tiers = array();

	/**
	 * The provider's classes by setup tier and request type, read once per request.
	 *
	 * @var array<string,array<string,array<int,class-string>>>|null
	 */
	private ?array $classes_by_setup_tier = null;

	/**
	 * Whether the cron class list included the re-check, keyed by blog ID. Neither invalidate() here nor the runtime
	 * arbiter's clears it, so a memo dropped later in the request does not lose a step.
	 *
	 * @var array<int,bool>
	 */
	private array $recheck_listed = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $runtime_arbiter Runtime ownership arbiter.
	 */
	final public function init( WooPaymentsRuntimeArbiter $runtime_arbiter ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Get the validated effective setup tier for the current blog.
	 *
	 * @since 11.2.0
	 *
	 * @return string One of the setup tier constants.
	 */
	public function get_effective_tier(): string {
		if ( ! $this->runtime_arbiter->is_builtin_enabled() ) {
			return self::DISABLED;
		}

		if ( WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $this->runtime_arbiter->get_runtime_owner() ) {
			$stored = $this->get_stored_tier();
			// A store that switched from the extension can still hold the disabled tier of its install; reading it as available
			// keeps the switch's job loaded, and the job writes the tier. A store never set up stays dormant on disabled.
			return self::DISABLED === $stored && $this->runtime_arbiter->has_run_woopayments_extension() ? self::AVAILABLE : $stored;
		}

		// With the feature on, nothing else owns payments only on a store waiting for its switch, which is offered it.
		return self::AVAILABLE;
	}

	/**
	 * Get the classes a request type registers for WooPayments, in registration order.
	 *
	 * A `disabled` tier reads no provider class list.
	 *
	 * On a `disabled` or `available` tier, cron and WP-CLI requests also list the listener that notes a status-change
	 * capture or cancel retry queued while the built-in WooPayments took payments, when the stored tier is not `disabled`:
	 * it leaves `disabled` only through an account sync while the WooPayments feature was on, which every store that could
	 * queue such a retry went through. On a stored `available` tier the built-in owns, it also registers the setup tier
	 * re-check (should_register_recheck()).
	 *
	 * @since 11.2.0
	 *
	 * @param string $request_type Request type: front, admin, ajax, rest, cron or cli.
	 * @return array<int,class-string>
	 */
	public function get_classes_for_request( string $request_type ): array {
		$tier    = $this->get_effective_tier();
		$classes = array();
		if ( self::DISABLED !== $tier ) {
			if ( null === $this->classes_by_setup_tier ) {
				$this->classes_by_setup_tier = WooPaymentsProvider::get_classes_by_setup_tier();
			}

			$classes = $this->classes_by_setup_tier[ $tier ][ $request_type ] ?? array();
		}

		if (
			in_array( $tier, array( self::DISABLED, self::AVAILABLE ), true )
			&& in_array( $request_type, array( RequestTypeUtil::CRON, RequestTypeUtil::CLI ), true )
			&& self::DISABLED !== $this->get_stored_tier()
		) {
			$classes[] = WooPaymentsStatusChangeRetryListener::class;
			// Only memos filled above are read: the stored tier, and the owner get_effective_tier() resolved.
			$this->recheck_listed[ get_current_blog_id() ] = self::AVAILABLE === $tier
				&& self::AVAILABLE === $this->get_stored_tier()
				&& WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $this->runtime_arbiter->get_runtime_owner();
		}

		return $classes;
	}

	/**
	 * Tell whether the status-change retry listener also registers the setup tier re-check: the cron class list was built
	 * for a stored `available` tier while the built-in WooPayments owned payments.
	 *
	 * @since 11.4.0
	 *
	 * @return bool
	 */
	public function should_register_recheck(): bool {
		return $this->recheck_listed[ get_current_blog_id() ] ?? false;
	}

	/**
	 * Register the setup tier re-check on its Action Scheduler hook, once per request.
	 *
	 * The callback names WooPaymentsSetupTierRecheck only as a string, so nothing loads until a step runs.
	 *
	 * @since 11.4.0
	 */
	public static function register_recheck_hook(): void {
		$callback = array( WooPaymentsSetupTierRecheck::class, 'run_step' );
		if ( false === has_action( self::RECHECK_HOOK, $callback ) ) {
			add_action( self::RECHECK_HOOK, $callback, 10, 2 );
		}
	}

	/**
	 * Get the stored setup tier for the current blog, before the WooPayments feature and runtime ownership decide the effective one.
	 *
	 * Support surfaces use it: a connected store keeps its stored tier while its WooPayments feature is off.
	 *
	 * @since 11.2.0
	 *
	 * @return string One of the setup tier constants.
	 */
	public function get_stored_tier(): string {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->tiers ) ) {
			$stored_tier             = get_option( self::OPTION_NAME, self::DISABLED );
			$this->tiers[ $blog_id ] = $this->is_valid_tier( $stored_tier ) ? $stored_tier : self::DISABLED;
		}

		return $this->tiers[ $blog_id ];
	}

	/**
	 * Persist a setup tier and update the current-blog memo only after exact readback.
	 *
	 * An unchanged autoloaded value is not written again. After a changed write, the option value and its autoload flag are
	 * verified before the current-blog memo is updated, because the tier decides which classes the next request loads and
	 * an option filter or a failed write would otherwise go unnoticed. A readback that does not match drops the cached
	 * options, so the next read loads them from the database.
	 *
	 * @since 11.2.0
	 *
	 * @param string $tier Setup tier to persist.
	 * @return bool Whether the exact autoloaded setup tier was read back.
	 */
	public function write_tier( string $tier ): bool {
		if ( ! $this->is_valid_tier( $tier ) ) {
			return false;
		}

		// Every account refresh re-syncs the setup tier; an unchanged autoloaded value needs no write or reread.
		if ( get_option( self::OPTION_NAME, null ) === $tier && array_key_exists( self::OPTION_NAME, wp_load_alloptions() ) ) {
			$this->tiers[ get_current_blog_id() ] = $tier;
			return true;
		}

		update_option( self::OPTION_NAME, $tier, true );
		wp_set_option_autoload_values( array( self::OPTION_NAME => true ) );
		wp_cache_delete( self::OPTION_NAME, 'options' );

		$stored_tier = get_option( self::OPTION_NAME, null );
		$autoloaded  = array_key_exists( self::OPTION_NAME, wp_load_alloptions( true ) );
		if ( $stored_tier !== $tier || ! $autoloaded ) {
			$this->log_write_failure( $tier, $stored_tier, $autoloaded );
			// A cache that disagrees with the database would otherwise outlast every later write of the same tier.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::OPTION_NAME, 'options' );
			return false;
		}

		$this->tiers[ get_current_blog_id() ] = $tier;
		return true;
	}

	/**
	 * Raise a stored `available` tier to `connected` or `active`, only while the database still holds `available`.
	 *
	 * Another writer's tier stands: a tier raised or lowered since it was read is left as it is. The request memo and the
	 * cached options are dropped either way, so the next read sees what is stored.
	 *
	 * @since 11.4.0
	 *
	 * @param string $tier Setup tier to write: connected or active.
	 * @return bool|null True when written, false when the stored tier was no longer `available`, null when the write failed.
	 */
	public function raise_from_available( string $tier ): ?bool {
		if ( ! in_array( $tier, array( self::CONNECTED, self::ACTIVE ), true ) ) {
			return false;
		}

		return $this->raise( $tier, self::AVAILABLE );
	}

	/**
	 * Raise a stored `disabled` tier, or a missing one, to `available`, only while the database still holds that low value.
	 *
	 * An empty account answer moves only a store that was never set up; a tier raised since it was read is left as it is.
	 *
	 * @since 11.4.0
	 *
	 * @return bool|null True when written, false when the stored tier was not `disabled` or missing, null when the write failed.
	 */
	public function raise_to_available(): ?bool {
		if ( add_option( self::OPTION_NAME, self::AVAILABLE, '', true ) ) {
			$this->invalidate();
			return true;
		}

		return $this->raise( self::AVAILABLE, self::DISABLED );
	}

	/**
	 * Write a tier only while the database holds the given lower one, then drop the request memo and the cached options.
	 *
	 * @param string $tier Setup tier to write.
	 * @param string $from Setup tier the database must still hold.
	 * @return bool|null True when written, false when the stored tier was not `$from`, null when the write failed.
	 */
	private function raise( string $tier, string $from ): ?bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$tier,
				self::OPTION_NAME,
				$from
			)
		);
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::OPTION_NAME, 'options' );
		$this->invalidate();

		if ( false === $updated ) {
			$this->log_write_failure( $tier, get_option( self::OPTION_NAME, null ), array_key_exists( self::OPTION_NAME, wp_load_alloptions( true ) ) );
			return null;
		}

		return 0 < $updated;
	}

	/**
	 * Compare the tier in the cached options with the database row, and drop the cached options when they differ.
	 *
	 * The cached options are read past this request's copy where a persistent object cache keeps one. They are dropped, never
	 * written back, since a write would also put back this request's copy of every other autoloaded option. Cached options
	 * without the tier, as when it is not autoloaded, are left alone.
	 *
	 * @since 11.4.0
	 *
	 * @return array{0:string,1:string}|null The cached and the stored tier as printed in a log line when they differed, or null.
	 */
	public function confirm_cached_tier(): ?array {
		global $wpdb;

		$this->invalidate();
		$cached = wp_cache_get( 'alloptions', 'options', true );
		if ( ! is_array( $cached ) || ! array_key_exists( self::OPTION_NAME, $cached ) ) {
			return null;
		}

		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_NAME ) );
		if ( $cached[ self::OPTION_NAME ] === $stored ) {
			return null;
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::OPTION_NAME, 'options' );

		return array( $this->print_tier( $cached[ self::OPTION_NAME ] ), $this->print_tier( $stored ) );
	}

	/**
	 * Invalidate one blog's memoized setup tier.
	 *
	 * @since 11.2.0
	 *
	 * @param int|null $blog_id Blog ID, or null for the current blog.
	 */
	public function invalidate( ?int $blog_id = null ): void {
		unset( $this->tiers[ $blog_id ?? get_current_blog_id() ] );
	}

	/**
	 * Log a setup tier write whose readback did not match, with the requested tier and what was read back: a tier, `none`
	 * when the option is missing, or the PHP type of anything else, since a filter or another writer can put any value there.
	 *
	 * @param string $requested_tier Setup tier that was written.
	 * @param mixed  $stored_tier    Value read back from the option.
	 * @param bool   $autoloaded      Whether the option is autoloaded.
	 */
	private function log_write_failure( string $requested_tier, $stored_tier, bool $autoloaded ): void {
		wc_get_logger()->error(
			sprintf(
				'WooPayments setup tier write failed: requested %1$s, stored %2$s, autoloaded %3$s.',
				$requested_tier,
				$this->print_tier( $stored_tier ),
				$autoloaded ? 'yes' : 'no'
			),
			array( 'source' => WooPaymentsLogger::SOURCE )
		);
	}

	/**
	 * Print a stored value for a log line: the tier, `none` when the option is missing, or the PHP type of anything else.
	 *
	 * @param mixed $stored_tier Value read from the option.
	 * @return string
	 */
	private function print_tier( $stored_tier ): string {
		if ( null === $stored_tier ) {
			return 'none';
		}

		return $this->is_valid_tier( $stored_tier ) ? $stored_tier : gettype( $stored_tier );
	}

	/**
	 * Tell whether a value is an exact setup tier.
	 *
	 * @param mixed $tier Candidate setup tier.
	 * @return bool
	 */
	private function is_valid_tier( $tier ): bool {
		return is_string( $tier ) && in_array( $tier, array( self::DISABLED, self::AVAILABLE, self::CONNECTED, self::ACTIVE ), true );
	}
}
