<?php
/**
 * CryptoCheckCommand class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCheck;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use RuntimeException;
use WP_CLI;

/**
 * The `wp wc paypal-wallet crypto-check` command: ask PayPal whether the platform merchant can take Pay with Crypto.
 *
 * POC only, to drop before upstreaming: the shipping shape is a merchant-facing "Check availability" action on the
 * Pay with Crypto feature card, a follow-up whose copy is Vlad's.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CryptoCheckCommand {

	/**
	 * The Pay with Crypto check.
	 *
	 * @var CryptoCheck
	 */
	private CryptoCheck $check;

	/**
	 * Constructor.
	 *
	 * @param CryptoCheck $check The Pay with Crypto check.
	 */
	public function __construct( CryptoCheck $check ) {
		$this->check = $check;
	}

	/**
	 * Create one unapproved Pay with Crypto order for the platform merchant and record whether PayPal accepts it.
	 *
	 * The order is never approved and expires unpaid; its custom ID is `wc-paypal-wallet-crypto-check`. No WooCommerce
	 * order is created.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the order body and send nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc paypal-wallet crypto-check --dry-run
	 *     wp wc paypal-wallet crypto-check
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function crypto_check( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = (bool) filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ), FILTER_VALIDATE_BOOLEAN ); // @phpstan-ignore function.notFound (WP-CLI is not installed when PHPStan runs.)

		foreach ( $this->lines( $dry_run ) as list( $level, $text ) ) {
			switch ( $level ) {
				case 'success':
					WP_CLI::success( $text ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
					break;
				case 'warning':
					WP_CLI::warning( $text ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
					break;
				case 'error':
					WP_CLI::error( $text ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
					break;
				default:
					WP_CLI::log( $text ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
			}
		}
	}

	/**
	 * Run the check, or build its body for a dry run, and describe the result as output lines. An error line is always
	 * the last.
	 *
	 * @param bool $dry_run Whether to print the body and send nothing.
	 * @return array<int, array{0: string, 1: string}> The lines, each a level (`log`, `success`, `warning` or `error`) and
	 *                                                 its text.
	 */
	public function lines( bool $dry_run ): array {
		try {
			if ( $dry_run ) {
				return array(
					array( 'log', (string) wp_json_encode( $this->check->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ),
					array( 'success', 'Dry run: nothing was sent to PayPal.' ),
				);
			}
			$result = $this->check->run();
		} catch ( RuntimeException $exception ) {
			return array( array( 'error', $exception->getMessage() ) );
		}

		$lines = array();
		if ( '' !== $result['order_id'] ) {
			$lines[] = array( 'log', 'PayPal order ID: ' . $result['order_id'] );
		}
		if ( '' !== $result['debug_id'] ) {
			$lines[] = array( 'log', 'PayPal debug ID: ' . $result['debug_id'] );
		}

		if ( CryptoCreateAnswer::UNKNOWN !== $result['outcome'] && ! $result['recorded'] ) {
			$lines[] = array( 'error', 'The answer could not be stored: the platform connection has no merchant ID or environment.' );

			return $lines;
		}

		switch ( $result['outcome'] ) {
			case CryptoCreateAnswer::ACCEPTED:
				$lines[] = array( 'success', 'PayPal accepted the check order, so the merchant can take Pay with Crypto.' );
				break;
			case CryptoCreateAnswer::REFUSED:
				$lines[] = array( 'warning', 'PayPal refused the check order, so the merchant cannot take Pay with Crypto yet. Run the check again once PayPal finishes setting it up, which can take up to two business days.' );
				break;
			default:
				$lines[] = array( 'error', 'The check could not tell whether the merchant can take Pay with Crypto, so the last result is kept. ' . $this->describe_failure( $result ) );
		}

		return $lines;
	}

	/**
	 * What went wrong, for an answer that says nothing about the merchant.
	 *
	 * @param array $result The check's result.
	 * @return string
	 */
	private function describe_failure( array $result ): string {
		if ( '' !== $result['error'] ) {
			// The wallet's endpoints escape PayPal's description for HTML; a terminal shows it as text.
			return html_entity_decode( $result['error'], ENT_QUOTES );
		}
		if ( '' !== $result['order_status'] ) {
			return sprintf( 'PayPal answered HTTP %1$d with the order status %2$s.', $result['http_status'], $result['order_status'] );
		}

		return sprintf( 'PayPal answered HTTP %d.', $result['http_status'] );
	}
}
