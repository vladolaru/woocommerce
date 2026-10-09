<?php
/**
 * CollectCommand class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
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
	 * Constructor.
	 *
	 * @param CollectingState $state The collecting state the command writes.
	 */
	public function __construct( CollectingState $state ) {
		$this->state = $state;
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
	 * : Use the PayPal sandbox instead of production.
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

		// A bare --sandbox is true; --sandbox=false must not turn the sandbox on.
		$sandbox = filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'sandbox', false ), FILTER_VALIDATE_BOOLEAN ); // @phpstan-ignore function.notFound (WP-CLI is not installed when PHPStan runs.)

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
	 * @param string $email   The payee email.
	 * @param bool   $sandbox Whether to use the sandbox.
	 *
	 * @return array<string, string> The payee, tracking ID and environment, labelled.
	 *
	 * @throws InvalidArgumentException When the email is not valid.
	 * @throws RuntimeException         When the current payee is already bound.
	 */
	public function enter_collecting( string $email, bool $sandbox ): array {
		$this->require_valid_email( $email );

		$this->state->enter( $email, $sandbox ? CollectingState::ENVIRONMENT_SANDBOX : CollectingState::ENVIRONMENT_PRODUCTION );

		return array(
			'Payee'       => $this->state->payee_email(),
			'Tracking ID' => $this->state->tracking_id(),
			'Environment' => $this->state->environment(),
		);
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
