<?php
/**
 * CollectingState class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PerAppBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use InvalidArgumentException;
use RuntimeException;

/**
 * The lifecycle of the collecting state: a store sells with PayPal before the merchant has a PayPal account.
 *
 * The state lives in two options. The collecting option holds the payee the buyers pay and a tracking ID that
 * ties the later onboarding to it. The platform option holds the finished connection. Only this class writes
 * them; every reader is read-only.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectingState {

	/**
	 * The sandbox environment.
	 *
	 * @since 11.3.0
	 */
	public const ENVIRONMENT_SANDBOX = 'sandbox';

	/**
	 * The production environment.
	 *
	 * @since 11.3.0
	 */
	public const ENVIRONMENT_PRODUCTION = 'production';

	/**
	 * The merchant turned the PayPal gateway off.
	 *
	 * @since 11.3.0
	 */
	public const ABANDON_DISABLED = 'disabled';

	/**
	 * The PayPal Payments extension was activated and took over.
	 *
	 * @since 11.3.0
	 */
	public const ABANDON_TAKEOVER = 'takeover';

	/**
	 * The merchant connected a PayPal account through the first-party flow.
	 *
	 * @since 11.3.0
	 */
	public const ABANDON_FIRST_PARTY = 'first_party';

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The held-orders count.
	 *
	 * @var HeldOrdersCount
	 */
	private HeldOrdersCount $held_orders;

	/**
	 * The switch that turns the wallet gateway on.
	 *
	 * @var GatewaySwitch
	 */
	private GatewaySwitch $gateway;

	/**
	 * Constructor.
	 *
	 * @param Options            $options     The option reader.
	 * @param HeldOrdersCount    $held_orders The held-orders count.
	 * @param GatewaySwitch|null $gateway     The switch that turns the wallet gateway on; the settings API one by default.
	 */
	public function __construct( Options $options, HeldOrdersCount $held_orders, ?GatewaySwitch $gateway = null ) {
		$this->options     = $options;
		$this->held_orders = $held_orders;
		$this->gateway     = $gateway ?? new GatewaySwitch();
	}

	/**
	 * Whether the collecting option holds a payee. It does not look at the platform option: a store cannot hold both,
	 * because enter() refuses a platform-connected store and complete() deletes the collecting option.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_collecting(): bool {
		return '' !== $this->string_value( $this->options->collecting(), 'payee_email' );
	}

	/**
	 * Whether PayPal confirmed a merchant ID for the store.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_platform_connected(): bool {
		return '' !== $this->merchant_id();
	}

	/**
	 * The payee buyers pay. While collecting it is the collecting option's; after the connection it is the platform's.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function payee_email(): string {
		return $this->from_active_option( 'payee_email' );
	}

	/**
	 * The tracking ID that ties the onboarding to the collecting payee.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function tracking_id(): string {
		return $this->from_active_option( 'tracking_id' );
	}

	/**
	 * The PayPal environment, `sandbox` or `production`. Anything else reads as production.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function environment(): string {
		return self::ENVIRONMENT_SANDBOX === $this->from_active_option( 'environment' ) ? self::ENVIRONMENT_SANDBOX : self::ENVIRONMENT_PRODUCTION;
	}

	/**
	 * The merchant ID PayPal confirmed, or an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function merchant_id(): string {
		return $this->string_value( $this->options->platform(), 'merchant_id' );
	}

	/**
	 * Whether the payee email may still change: only while collecting and until the payee is bound to a buyer's payment.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function can_change_payee_email(): bool {
		return $this->is_collecting() && ! $this->is_payee_bound();
	}

	/**
	 * Enter the collecting state with a fresh payee and tracking ID, and turn the wallet gateway on.
	 *
	 * Entering binds the payee when orders are held, because they were paid to it. The same unbound payee in the same
	 * environment keeps its tracking ID, so a referral already opened still matches. The same bound payee only turns the
	 * gateway back on and writes nothing to the collecting option. A store that was not collecting also resets the Inbox
	 * note state, so a note actioned by an earlier connection comes back.
	 *
	 * Refused, with nothing written: a platform-connected store; a different payee, or the same one in another
	 * environment, once bound; and a payee other than the one orders are held for.
	 *
	 * @since 11.3.0
	 *
	 * @param string $payee_email  The payee buyers pay.
	 * @param string $environment  `sandbox` or `production`.
	 *
	 * @throws InvalidArgumentException       When the email or the environment is not valid.
	 * @throws HeldForAnotherPayeeException   When a held order records another payee.
	 * @throws RuntimeException               When the store is platform connected, a different payee (or the same one in
	 *                                        another environment) is already bound, or the gateway cannot be turned on.
	 */
	public function enter( string $payee_email, string $environment ): void {
		$payee_email = $this->valid_email( $payee_email );
		if ( ! in_array( $environment, array( self::ENVIRONMENT_SANDBOX, self::ENVIRONMENT_PRODUCTION ), true ) ) {
			throw new InvalidArgumentException( 'The environment must be sandbox or production.' );
		}
		if ( $this->is_platform_connected() ) {
			throw new RuntimeException( 'The store is already platform connected; it cannot collect.' );
		}
		$current = $this->options->collecting();
		$same    = $this->is_collecting() && $this->payee_email() === $payee_email && $this->string_value( $current, 'environment' ) === $environment;
		if ( $this->is_collecting() && $this->is_payee_bound() ) {
			if ( $same ) {
				$this->gateway->turn_on();
				return;
			}
			throw new RuntimeException( 'The payee is already bound; it cannot be replaced or moved to another environment.' );
		}

		// A first-party connection deletes the state but not the held orders. They were paid to a payee, so it binds.
		$bound = $this->held_orders->count() > 0;
		if ( $bound && $this->held_orders->count_for_other_payee( $payee_email ) > 0 ) {
			throw new HeldForAnotherPayeeException();
		}

		$tracking_id    = $same ? $this->string_value( $current, 'tracking_id' ) : '';
		$was_collecting = $this->is_collecting();

		// The gateway first: when it cannot be turned on, the store is left as it was, not collecting with PayPal off.
		$this->gateway->turn_on();
		update_option(
			Options::COLLECTING,
			array(
				'payee_email' => $payee_email,
				'tracking_id' => '' !== $tracking_id ? $tracking_id : bin2hex( random_bytes( 16 ) ),
				'environment' => $environment,
				'payee_bound' => $bound,
			),
			true
		);
		if ( ! $was_collecting ) {
			// A store entering afresh may have had its setup note actioned by an earlier connection; let the note return.
			delete_option( Options::NOTE_STATE );
		}
	}

	/**
	 * Change the payee email. An invalid email throws an InvalidArgumentException.
	 *
	 * @since 11.3.0
	 *
	 * @param string $email The new payee.
	 *
	 * @throws RuntimeException When the payee cannot change any more.
	 */
	public function set_payee_email( string $email ): void {
		$email = $this->valid_email( $email );

		// Read the option as late as possible, so a bind_payee() that ran since the caller looked is not overwritten.
		$data = $this->options->collecting();
		if ( '' === $this->string_value( $data, 'payee_email' ) || ! empty( $data['payee_bound'] ) ) {
			throw new RuntimeException( 'The payee email cannot change: the store is not collecting or the payee is bound.' );
		}

		$data['payee_email'] = $email;
		update_option( Options::COLLECTING, $data, true );
	}

	/**
	 * Mark the payee as bound to a buyer's payment, so it can no longer change. Does nothing when not collecting.
	 *
	 * @since 11.3.0
	 */
	public function bind_payee(): void {
		if ( ! $this->is_collecting() ) {
			return;
		}

		$data                = $this->options->collecting();
		$data['payee_bound'] = true;
		update_option( Options::COLLECTING, $data, true );
	}

	/**
	 * Claim the first-order slot for an order. Only the first caller wins.
	 *
	 * Uses add_option(), which fails when the row exists, so concurrent orders cannot both claim it. The row is
	 * autoloaded, so the surfaces that ask whether a wallet order ever existed run no query on a store that has none. The
	 * collecting option is never touched.
	 *
	 * @since 11.3.0
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return bool True when this call claimed the slot.
	 */
	public function claim_first_order( int $order_id ): bool {
		return add_option( Options::FIRST_ORDER, $order_id, '', true );
	}

	/**
	 * Record the merchant ID PayPal confirmed: write the platform option and leave the collecting state.
	 *
	 * @since 11.3.0
	 *
	 * @param string $merchant_id The confirmed merchant ID.
	 *
	 * @throws InvalidArgumentException When the merchant ID is empty.
	 * @throws RuntimeException         When the store is not collecting.
	 */
	public function complete( string $merchant_id ): void {
		if ( '' === $merchant_id ) {
			throw new InvalidArgumentException( 'The merchant ID must not be empty.' );
		}
		if ( ! $this->is_collecting() ) {
			throw new RuntimeException( 'The store is not collecting.' );
		}

		update_option(
			Options::PLATFORM,
			array(
				'merchant_id'  => $merchant_id,
				'tracking_id'  => $this->tracking_id(),
				'payee_email'  => $this->payee_email(),
				'connected_at' => time(),
				'environment'  => $this->environment(),
			),
			true
		);
		delete_option( Options::COLLECTING );
		delete_option( Options::SELLER_STATUS );
	}

	/**
	 * Leave the collecting state without a connection.
	 *
	 * A takeover or a disabled gateway keeps the state while orders are still held for the payee, so they can be
	 * settled later; the state is deleted once none are held. A first-party connection always deletes it, and the
	 * platform connection with it, held orders or not: first-party credentials win over everything. An unknown reason is
	 * refused. Deleting the state also deletes the platform apps' cached tokens and the recorded runtime owner, which only
	 * a store the platform serves has.
	 *
	 * @since 11.3.0
	 *
	 * @param string $reason `disabled`, `takeover` or `first_party`.
	 *
	 * @throws InvalidArgumentException When the reason is unknown.
	 */
	public function abandon( string $reason ): void {
		switch ( $reason ) {
			case self::ABANDON_FIRST_PARTY:
				delete_option( Options::PLATFORM );
				$this->delete_collecting_state();
				return;
			case self::ABANDON_TAKEOVER:
			case self::ABANDON_DISABLED:
				if ( $this->held_orders->count() <= 0 ) {
					$this->delete_collecting_state();
				}
				return;
		}

		throw new InvalidArgumentException( 'Unknown abandon reason.' );
	}

	/**
	 * Delete the collecting option, the cached seller status, the recorded runtime owner and the platform apps' cached
	 * tokens, which only the collecting state used.
	 */
	private function delete_collecting_state(): void {
		delete_option( Options::COLLECTING );
		delete_option( Options::SELLER_STATUS );
		delete_option( PayPalWalletBootstrap::LAST_OWNER_OPTION );
		PerAppBearer::forget_stored_tokens();
	}

	/**
	 * Whether the collecting payee is bound.
	 *
	 * @return bool
	 */
	private function is_payee_bound(): bool {
		return ! empty( $this->options->collecting()['payee_bound'] );
	}

	/**
	 * A key of the collecting option, falling back to the platform option's.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 */
	private function from_active_option( string $key ): string {
		$value = $this->string_value( $this->options->collecting(), $key );

		return '' !== $value ? $value : $this->string_value( $this->options->platform(), $key );
	}

	/**
	 * A string value from an option array; anything else reads as an empty string.
	 *
	 * @param array  $data The option array.
	 * @param string $key  The key.
	 *
	 * @return string
	 */
	private function string_value( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}

	/**
	 * Sanitize an email and require it to be valid.
	 *
	 * @param string $email The email.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the email is not valid.
	 */
	private function valid_email( string $email ): string {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			throw new InvalidArgumentException( 'The payee email is not a valid email address.' );
		}

		return $email;
	}
}
