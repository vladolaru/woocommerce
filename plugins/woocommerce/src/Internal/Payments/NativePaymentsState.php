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
	public const OPTION_NAME = 'woocommerce_woopayments_setup_tier';

	/** Native payments is unavailable. */
	public const DISABLED = 'disabled';

	/** Native payments can be offered or migrated to. */
	public const AVAILABLE = 'available';

	/** A native account exists without checkout enabled. */
	public const CONNECTED = 'connected';

	/**
	 * Native checkout is enabled.
	 *
	 * Onboarding enables the card gateway when it applies the payment-method picks, so a store reaches this tier once its account is cached, before KYC, as the client loads its checkout code then.
	 * Checkout still offers nothing until the account can take payments.
	 */
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
		$state = $this->get_stored_state();
		$owner = $this->runtime_arbiter->get_runtime_owner();
		if ( NativePaymentsRuntimeArbiter::OWNER_NONE === $owner ) {
			return self::DISABLED;
		}

		if ( NativePaymentsRuntimeArbiter::OWNER_EXTENSION === $owner && in_array( $state, array( self::CONNECTED, self::ACTIVE ), true ) ) {
			return self::AVAILABLE;
		}

		return $state;
	}

	/**
	 * Get the stored state for the current blog, before runtime ownership clamps it.
	 *
	 * Support surfaces use it: a connected store keeps its stored tier while the kill switch disables it.
	 *
	 * @since 11.2.0
	 *
	 * @return string One of the state constants.
	 */
	public function get_stored_state(): string {
		$blog_id = get_current_blog_id();
		if ( ! array_key_exists( $blog_id, $this->states ) ) {
			$stored_state             = get_option( self::OPTION_NAME, self::DISABLED );
			$this->states[ $blog_id ] = $this->is_valid_state( $stored_state ) ? $stored_state : self::DISABLED;
		}

		return $this->states[ $blog_id ];
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

		// Every account refresh re-syncs the state; an unchanged autoloaded value needs no write or reread.
		if ( get_option( self::OPTION_NAME, null ) === $state && array_key_exists( self::OPTION_NAME, wp_load_alloptions() ) ) {
			$this->states[ get_current_blog_id() ] = $state;
			return true;
		}

		update_option( self::OPTION_NAME, $state, true );
		wp_set_option_autoload_values( array( self::OPTION_NAME => true ) );
		wp_cache_delete( self::OPTION_NAME, 'options' );

		$stored_state = get_option( self::OPTION_NAME, null );
		$autoloaded   = array_key_exists( self::OPTION_NAME, wp_load_alloptions( true ) );
		if ( $stored_state !== $state || ! $autoloaded ) {
			$this->log_write_failure( $state, $stored_state, $autoloaded );
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
	 * Log a state write whose readback did not match, with the requested and stored values.
	 *
	 * @param string $requested_state State that was written.
	 * @param mixed  $stored_state    Value read back from the option.
	 * @param bool   $autoloaded      Whether the option is autoloaded.
	 */
	private function log_write_failure( string $requested_state, $stored_state, bool $autoloaded ): void {
		wc_get_logger()->error(
			sprintf(
				'Native payments state write failed: requested %1$s, stored %2$s, autoloaded %3$s.',
				$requested_state,
				(string) wp_json_encode( $stored_state ),
				$autoloaded ? 'yes' : 'no'
			),
			array( 'source' => 'native-payments' )
		);
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
