<?php
/**
 * ConnectionState class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;

/**
 * Which of the four states the PayPal wallet is in, read from the stored options without building the wallet.
 *
 * Precedence, first match wins: first-party credentials (CONNECTED), a platform merchant ID (PLATFORM_CONNECTED),
 * a collecting payee (COLLECTING), nothing (DORMANT). Reads only; nothing is written.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class ConnectionState {

	/**
	 * The merchant connected a PayPal account through the first-party flow.
	 *
	 * @since 11.3.0
	 */
	public const CONNECTED = 'connected';

	/**
	 * PayPal confirmed a merchant ID through the platform.
	 *
	 * @since 11.3.0
	 */
	public const PLATFORM_CONNECTED = 'platform_connected';

	/**
	 * Buyers pay a collecting payee until the merchant has a PayPal account.
	 *
	 * @since 11.3.0
	 */
	public const COLLECTING = 'collecting';

	/**
	 * Nothing is connected or collecting.
	 *
	 * @since 11.3.0
	 */
	public const DORMANT = 'dormant';

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param Options|null $options The option reader; defaults to the stored options.
	 */
	public function __construct( ?Options $options = null ) {
		$this->options = $options ?? new Options();
	}

	/**
	 * The current state.
	 *
	 * @since 11.3.0
	 *
	 * @return string One of the class constants.
	 */
	public function resolve(): string {
		if ( GeneralSettings::read_connection_from_options()['connected'] ) {
			return self::CONNECTED;
		}

		return $this->resolve_from_platform_options();
	}

	/**
	 * Whether the platform, not the merchant's own credentials, serves the store: platform connected or collecting.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_served_by_platform(): bool {
		return in_array( $this->resolve(), array( self::PLATFORM_CONNECTED, self::COLLECTING ), true );
	}

	/**
	 * Whether the platform or collecting option describes a state, without looking at first-party credentials.
	 *
	 * The read-only platform and collecting half of resolve(), for a caller that has already ruled first-party
	 * credentials out. It is not an efficiency measure: it does not stand in for resolve() when first-party credentials
	 * might exist.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function has_platform_state(): bool {
		return self::DORMANT !== $this->resolve_from_platform_options();
	}

	/**
	 * The state the platform and collecting options describe: platform connected, collecting, or dormant.
	 *
	 * Both options are written autoloaded, and the autoloaded set is the authority: an option is read only when it is in
	 * that set, so a store that has neither, or only one, queries nothing for the other. The shell asks this on every
	 * request of a store with no connection. An option stored without autoload reads as absent.
	 *
	 * @return string
	 */
	private function resolve_from_platform_options(): string {
		if ( $this->options->has_autoloaded( Options::PLATFORM ) && $this->has_value( $this->options->platform(), 'merchant_id' ) ) {
			return self::PLATFORM_CONNECTED;
		}
		if ( $this->options->has_autoloaded( Options::COLLECTING ) && $this->has_value( $this->options->collecting(), 'payee_email' ) ) {
			return self::COLLECTING;
		}

		return self::DORMANT;
	}

	/**
	 * Whether an option array holds a non-empty string under a key.
	 *
	 * @param array  $data The option array.
	 * @param string $key  The key.
	 *
	 * @return bool
	 */
	private function has_value( array $data, string $key ): bool {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ];
	}
}
