<?php
/**
 * NativePaymentsState class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Stores the durable native WooPayments dormancy tier.
 *
 * @since 11.2.0
 * @internal
 */
final class NativePaymentsState {

	/** The persisted state option. */
	public const OPTION_NAME = 'woocommerce_native_payments_state';

	/** Native payments is unavailable. */
	public const DISABLED = 'disabled';

	/** Native payments can be offered or migrated to. */
	public const AVAILABLE = 'available';

	/** A native account exists without checkout enabled. */
	public const CONNECTED = 'connected';

	/** Native checkout is enabled. */
	public const ACTIVE = 'active';

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * Request-local states keyed by blog ID.
	 *
	 * @var array<int,string>
	 */
	private array $states = array();

	/**
	 * Initialize the state store.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $runtime_arbiter Runtime ownership arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $runtime_arbiter ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Get the validated effective state for the current blog.
	 *
	 * @since 11.2.0
	 *
	 * @return string One of the state constants.
	 */
	public function get_state(): string {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->states ) ) {
			$stored_state             = get_option( self::OPTION_NAME, self::DISABLED );
			$this->states[ $blog_id ] = $this->is_valid_state( $stored_state ) ? $stored_state : self::DISABLED;
		}

		$state = $this->states[ $blog_id ];
		$owner = $this->runtime_arbiter->get_runtime_owner();
		if ( NativePaymentsRuntimeArbiter::OWNER_NONE === $owner ) {
			return self::DISABLED;
		}

		if ( NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $owner && in_array( $state, array( self::CONNECTED, self::ACTIVE ), true ) ) {
			return self::AVAILABLE;
		}

		return $state;
	}

	/**
	 * Persist a state and update the current-blog memo only after exact readback.
	 *
	 * @since 11.2.0
	 *
	 * @param string $state State to persist.
	 * @return bool Whether the exact autoloaded state was read back.
	 */
	public function write_state( string $state ): bool {
		if ( ! $this->is_valid_state( $state ) ) {
			return false;
		}

		update_option( self::OPTION_NAME, $state, true );
		wp_set_option_autoload_values( array( self::OPTION_NAME => 'yes' ) );
		wp_cache_delete( self::OPTION_NAME, 'options' );

		if ( get_option( self::OPTION_NAME, null ) !== $state || ! array_key_exists( self::OPTION_NAME, wp_load_alloptions( true ) ) ) {
			return false;
		}

		$this->states[ get_current_blog_id() ] = $state;
		return true;
	}

	/**
	 * Invalidate one blog's memoized state.
	 *
	 * @since 11.2.0
	 *
	 * @param int|null $blog_id Blog ID, or null for the current blog.
	 */
	public function invalidate( ?int $blog_id = null ): void {
		unset( $this->states[ $blog_id ?? get_current_blog_id() ] );
	}

	/**
	 * Tell whether a value is an exact native state.
	 *
	 * @param mixed $state Candidate state.
	 * @return bool
	 */
	private function is_valid_state( $state ): bool {
		return is_string( $state ) && in_array( $state, array( self::DISABLED, self::AVAILABLE, self::CONNECTED, self::ACTIVE ), true );
	}
}
