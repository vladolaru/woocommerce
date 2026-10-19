<?php
/**
 * WooPaymentsSetupTier class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminNoticesPassthrough;

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

	/** The built-in WooPayments is unavailable: the feature is off, nothing owns payments, or it was never set up. */
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
	 * A `disabled` tier lists nothing and reads no provider class list.
	 *
	 * @since 11.2.0
	 *
	 * @param string $request_type Request type: front, admin, ajax, rest, cron or cli.
	 * @return array<int,class-string>
	 */
	public function get_classes_for_request( string $request_type ): array {
		$tier = $this->get_effective_tier();
		if ( self::DISABLED === $tier ) {
			return array();
		}

		if ( null === $this->classes_by_setup_tier ) {
			$this->classes_by_setup_tier = WooPaymentsProvider::get_classes_by_setup_tier();
		}

		$classes = $this->classes_by_setup_tier[ $tier ][ $request_type ] ?? array();
		// A store waiting for its switch keeps the switch notice on the WooPayments pages, which strip other notices.
		if ( 'admin' === $request_type && self::AVAILABLE === $tier && $this->runtime_arbiter->is_awaiting_switch() ) {
			$classes[] = WooPaymentsAdminNoticesPassthrough::class;
		}

		return $classes;
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
	 * an option filter or a failed write would otherwise go unnoticed.
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
			return false;
		}

		$this->tiers[ get_current_blog_id() ] = $tier;
		return true;
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
	 * Log a setup tier write whose readback did not match, with the requested and stored values.
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
				(string) wp_json_encode( $stored_tier ),
				$autoloaded ? 'yes' : 'no'
			),
			array( 'source' => WooPaymentsLogger::SOURCE )
		);
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
