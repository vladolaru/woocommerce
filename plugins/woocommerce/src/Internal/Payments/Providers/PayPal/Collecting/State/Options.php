<?php
/**
 * Options class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

/**
 * The names of the options the collecting state keeps, and typed readers for the array options.
 *
 * The readers never write: a missing or malformed option reads as an empty array. heal_autoload() is the one writer: it
 * only sets the autoload flag back, and logs what it did.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class Options {

	/**
	 * The collecting state: the store sells with PayPal before the merchant has a PayPal account.
	 * Keys: payee_email, tracking_id, environment, payee_bound.
	 *
	 * @since 11.3.0
	 */
	public const COLLECTING = 'woocommerce_paypal_wallet_collecting';

	/**
	 * The platform connection: the merchant finished onboarding and PayPal confirmed their merchant ID.
	 * Keys: merchant_id, tracking_id, payee_email, connected_at, environment.
	 *
	 * @since 11.3.0
	 */
	public const PLATFORM = 'woocommerce_paypal_wallet_platform';

	/**
	 * The ID of the first order that was routed to the collecting payee. Written once, with add_option(), autoloaded.
	 *
	 * @since 11.3.0
	 */
	public const FIRST_ORDER = 'woocommerce_paypal_wallet_first_order';

	/**
	 * The transport's own webhook subscriptions, as webhook IDs by platform app. Never the wallet's `ppcp-webhook`.
	 *
	 * @since 11.3.0
	 */
	public const WEBHOOKS = 'woocommerce_paypal_wallet_webhooks';

	/**
	 * The last seller status the reconcile read from PayPal, kept for the order screen. Not autoloaded; deleted when the
	 * collecting state completes or is abandoned.
	 * Keys: payments_receivable, primary_email_confirmed, checked_at.
	 *
	 * @since 11.3.0
	 */
	public const SELLER_STATUS = 'wc_paypal_wallet_seller_status';

	/**
	 * Where the Inbox note stands: `added` once it exists, `actioned` once the store is connected. Absent before the note
	 * is first added. Autoloaded, so the admin requests that check it run no query.
	 *
	 * @since 11.3.0
	 */
	public const NOTE_STATE = 'wc_paypal_wallet_note_state';

	/**
	 * The note state: the note exists and waits for setup.
	 *
	 * @since 11.3.0
	 */
	public const NOTE_ADDED = 'added';

	/**
	 * The note state: the store is connected and the note is actioned.
	 *
	 * @since 11.3.0
	 */
	public const NOTE_ACTIONED = 'actioned';

	/**
	 * The options written autoloaded, which every reader answers from the autoloaded set.
	 *
	 * @since 11.3.0
	 */
	public const AUTOLOADED = array( self::COLLECTING, self::PLATFORM, self::FIRST_ORDER, self::NOTE_STATE );

	/**
	 * The collecting option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function collecting(): array {
		return $this->read( self::COLLECTING );
	}

	/**
	 * The platform option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function platform(): array {
		return $this->read( self::PLATFORM );
	}

	/**
	 * The transport's webhook subscriptions option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function webhooks(): array {
		return $this->read( self::WEBHOOKS );
	}

	/**
	 * The last seller status the reconcile cached; an empty array when none is.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function seller_status(): array {
		return $this->read( self::SELLER_STATUS );
	}

	/**
	 * The email of the PayPal account the store collects for: the collecting payee, else the platform connection's.
	 * An empty string when neither is set.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function payee_email(): string {
		foreach ( array( $this->collecting(), $this->platform() ) as $data ) {
			if ( isset( $data['payee_email'] ) && is_string( $data['payee_email'] ) && '' !== $data['payee_email'] ) {
				return $data['payee_email'];
			}
		}

		return '';
	}

	/**
	 * Whether an autoloaded option exists, answered from the autoloaded set: a row that is absent costs no query.
	 *
	 * An option written without autoload is not seen. The collecting, platform, first-order and note-state options are all
	 * written autoloaded.
	 *
	 * @since 11.3.0
	 *
	 * @param string $name The option name.
	 * @return bool
	 */
	public function has_autoloaded( string $name ): bool {
		return array_key_exists( $name, wp_load_alloptions() );
	}

	/**
	 * The ID of the first order routed to the collecting payee, or 0 when none was. Read from the autoloaded set.
	 *
	 * @since 11.3.0
	 *
	 * @return int
	 */
	public function first_order_id(): int {
		$all = wp_load_alloptions();

		return isset( $all[ self::FIRST_ORDER ] ) ? (int) $all[ self::FIRST_ORDER ] : 0;
	}

	/**
	 * Where the Inbox note stands: `added`, `actioned`, or an empty string before the note was first added. Read from the
	 * autoloaded set.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function note_state(): string {
		$all = wp_load_alloptions();

		return isset( $all[ self::NOTE_STATE ] ) ? (string) $all[ self::NOTE_STATE ] : '';
	}

	/**
	 * Set the autoload flag back on every autoloaded option that exists outside the autoloaded set.
	 *
	 * A tool that flips autoload makes the store read as dormant (the autoloaded set is the authority). This looks the
	 * four names up, one query each, so it runs only where a merchant looks at the wallet or on a store the platform
	 * serves that reads as dormant. It logs a notice per healed name, and a warning per name whose flag write changed
	 * nothing (a failed UPDATE, or another request healed it first).
	 *
	 * @since 11.3.0
	 *
	 * @return string[] The names healed.
	 */
	public function heal_autoload(): array {
		$healed = array();
		foreach ( self::AUTOLOADED as $name ) {
			if ( $this->has_autoloaded( $name ) || null === get_option( $name, null ) ) {
				continue;
			}
			if ( wp_set_option_autoload( $name, true ) ) {
				$healed[] = $name;
				wc_get_logger()->notice( sprintf( 'PayPal wallet option %s was stored without autoload; the flag was set again.', $name ), array( 'source' => 'woocommerce-paypal-wallet' ) );
			} else {
				wc_get_logger()->warning( sprintf( 'PayPal wallet option %s is outside the autoloaded set, and setting its autoload flag again changed nothing.', $name ), array( 'source' => 'woocommerce-paypal-wallet' ) );
			}
		}

		return $healed;
	}

	/**
	 * Read an option as an array; anything that is not an array reads as an empty one.
	 *
	 * The collecting and platform options are answered from the autoloaded set: one that is not in it reads as empty.
	 *
	 * @param string $name The option name.
	 *
	 * @return array
	 */
	private function read( string $name ): array {
		if ( in_array( $name, self::AUTOLOADED, true ) && ! $this->has_autoloaded( $name ) ) {
			return array();
		}
		$value = get_option( $name, array() );

		return is_array( $value ) ? $value : array();
	}
}
