<?php
/**
 * CollectCommand class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use InvalidArgumentException;
use RuntimeException;
use WP_CLI;

/**
 * Puts the store in the collecting state from the command line.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectCommand {

	/**
	 * The collecting state the command writes.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * Builds the transport, whose environment the payee is entered in; null when there is none to ask.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param CollectingState $state     The collecting state the command writes.
	 * @param callable|null   $transport Returns the PlatformTransport, built only when the command runs; null for none.
	 */
	public function __construct( CollectingState $state, ?callable $transport = null ) {
		$this->state     = $state;
		$this->transport = $transport;
	}

	/**
	 * Start collecting PayPal payments for a payee, before the merchant has a PayPal account.
	 *
	 * ## OPTIONS
	 *
	 * --email=<payee>
	 * : The PayPal email address buyers pay.
	 *
	 * [--sandbox]
	 * : Use the PayPal sandbox instead of production. Without it, the environment the platform transport serves; it must
	 * match that environment when the transport is configured.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc paypal-wallet collect --email=payee@example.com --sandbox
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function collect( array $args, array $assoc_args ): void {
		unset( $args );
		$email = isset( $assoc_args['email'] ) && is_string( $assoc_args['email'] ) ? $assoc_args['email'] : '';

		// A bare --sandbox is true; --sandbox=false must not turn the sandbox on. No flag at all leaves the transport to decide.
		$sandbox = array_key_exists( 'sandbox', $assoc_args ) ? filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'sandbox', false ), FILTER_VALIDATE_BOOLEAN ) : null; // @phpstan-ignore function.notFound (WP-CLI is not installed when PHPStan runs.)

		try {
			$summary = $this->enter_collecting( $email, $sandbox );
		} catch ( InvalidArgumentException | RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
			return;
		}

		WP_CLI::success( 'The store is collecting PayPal payments.' ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
		foreach ( $summary as $label => $value ) {
			WP_CLI::line( $label . ': ' . $value ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
		}
	}

	/**
	 * Enter the collecting state and describe it.
	 *
	 * Without a sandbox flag the payee is entered in the environment the configured transport serves, or production when
	 * none is configured. A flag that names another environment than the configured transport's is refused, since the
	 * transport would talk to the other one.
	 *
	 * @param string    $email   The payee email.
	 * @param bool|null $sandbox Whether to use the sandbox, or null to take the transport's environment.
	 *
	 * @return array<string, string> The payee, tracking ID and environment, labelled.
	 *
	 * @throws InvalidArgumentException When the email is not valid.
	 * @throws RuntimeException         When the environment does not match the transport's, the store is platform
	 *                                  connected, a payee is already bound (another one, or in another environment), or
	 *                                  orders are held for another payee (a HeldForAnotherPayeeException, whose message
	 *                                  says what to do).
	 */
	public function enter_collecting( string $email, ?bool $sandbox ): array {
		$this->require_valid_email( $email );

		$this->state->enter( $email, $this->environment( $sandbox ) );

		return array(
			'Payee'       => $this->state->payee_email(),
			'Tracking ID' => $this->state->tracking_id(),
			'Environment' => $this->state->environment(),
		);
	}

	/**
	 * The environment to enter: the flag's, or the configured transport's without a flag, or production when no transport
	 * is configured.
	 *
	 * @param bool|null $sandbox Whether to use the sandbox, or null to take the transport's environment.
	 * @return string
	 *
	 * @throws RuntimeException When the flag names another environment than the configured transport's.
	 */
	private function environment( ?bool $sandbox ): string {
		$transport   = null === $this->transport ? null : ( $this->transport )();
		$served      = $transport instanceof PlatformTransport && $transport->is_ready() ? $transport->environment() : null;
		$environment = null === $sandbox ? ( $served ?? CollectingState::ENVIRONMENT_PRODUCTION ) : ( $sandbox ? CollectingState::ENVIRONMENT_SANDBOX : CollectingState::ENVIRONMENT_PRODUCTION );
		if ( null !== $served && $served !== $environment ) {
			throw new RuntimeException( sprintf( 'The platform transport serves the %1$s environment, not %2$s.', esc_html( $served ), esc_html( $environment ) ) );
		}

		return $environment;
	}

	/**
	 * Require the --email value to be a valid email as given, without the silent clean-up sanitize_email() would do.
	 *
	 * @param string $email The --email value.
	 *
	 * @throws InvalidArgumentException When the email is not valid.
	 */
	private function require_valid_email( string $email ): void {
		if ( ! is_email( $email ) ) {
			throw new InvalidArgumentException( 'The --email value is not a valid email address.' );
		}
	}
}
