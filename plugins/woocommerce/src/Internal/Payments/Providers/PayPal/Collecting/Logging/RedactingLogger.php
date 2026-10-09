<?php
/**
 * RedactingLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Logging;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerTrait;
use JsonSerializable;
use stdClass;
use WP_Error;

/**
 * The wallet's logger for a store the platform serves: it removes credentials from every record before passing it on.
 *
 * The wallet's endpoints log a failed request's arguments, Authorization header included. On a served store that
 * header carries a platform app's access token, so the value of any Authorization or PayPal-Auth-Assertion key, and
 * any bearer, basic or assertion credential inside a string, is replaced before the record reaches the log.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class RedactingLogger implements LoggerInterface {
	use LoggerTrait;

	/**
	 * What a redacted value is replaced with.
	 */
	private const REDACTED = '[redacted]';

	/**
	 * The keys whose values are credentials, lower case. A generic key such as `code` is left out: the wallet's contexts
	 * carry HTTP status codes and error codes under it.
	 */
	private const CREDENTIAL_KEYS = array(
		'authorization',
		'paypal-auth-assertion',
		'access_token',
		'refresh_token',
		'id_token',
		'client_secret',
		'code_verifier',
	);

	/**
	 * Credentials inside a string, and their replacements, all case-insensitive:
	 * - a bearer token and basic credentials, matched on the token alphabet so a quote or comma after them survives, the
	 *   basic ones only when long enough to be base64 credentials, so "Basic plan" stays;
	 * - an assertion header written out;
	 * - the value of a credential key in JSON, escaped JSON included, or in a form-encoded body, keeping the key.
	 */
	private const CREDENTIAL_PATTERNS = array(
		'/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i'         => '$1' . self::REDACTED,
		'/(Basic\s+)[A-Za-z0-9._~+\/-]{16,}={0,2}/i' => '$1' . self::REDACTED,
		'/(PayPal-Auth-Assertion[\'"]?\s*[:=]\s*[\'"]?)[^\s\'",]+/i' => '$1' . self::REDACTED,
		'/(\\\\?"(?:access_token|refresh_token|id_token|client_secret|code_verifier)\\\\?"\s*:\s*\\\\?")[^"\\\\]*/i' => '$1' . self::REDACTED,
		'/(\b(?:access_token|refresh_token|id_token|client_secret|code_verifier)=)[^&\s"\']+/i' => '$1' . self::REDACTED,
	);

	/**
	 * How deep a context is followed; anything deeper is replaced, so a cycle cannot recurse forever.
	 */
	private const MAX_DEPTH = 16;

	/**
	 * The logger records are passed on to.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $inner;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $inner The logger records are passed on to.
	 */
	public function __construct( LoggerInterface $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Pass the record on with its credentials removed.
	 *
	 * @param mixed  $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function log( $level, $message, array $context = array() ) {
		$text = is_string( $message ) || ( is_object( $message ) && method_exists( $message, '__toString' ) ) ? (string) $message : '';
		$this->inner->log( $level, $this->redact_string( $text ), (array) $this->redact( $context, 0 ) );
	}

	/**
	 * A value with its credentials removed. An object with something to remove comes back as a copy, never changed.
	 *
	 * @param mixed $value The value.
	 * @param int   $depth How deep the value sits in the context.
	 * @return mixed
	 */
	private function redact( $value, int $depth ) {
		if ( is_string( $value ) ) {
			return $this->redact_string( $value );
		}
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return $value;
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return self::REDACTED;
		}

		if ( is_array( $value ) ) {
			return $this->redact_array( $value, $depth );
		}
		if ( $value instanceof WP_Error ) {
			return $this->redact_error( $value, $depth );
		}

		// An object reaches the log through what it serializes to, or its public properties. A plain object stays one;
		// another one is replaced by its redacted properties, as it has no setter this class could trust.
		$visible  = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
		$redacted = $this->redact( $visible, $depth + 1 );
		if ( $redacted === $visible ) {
			return $value;
		}

		return $value instanceof stdClass && is_array( $redacted ) ? (object) $redacted : $redacted;
	}

	/**
	 * An array with the values of credential keys replaced and every other value redacted.
	 *
	 * @param array $values The array.
	 * @param int   $depth  How deep the array sits in the context.
	 * @return array
	 */
	private function redact_array( array $values, int $depth ): array {
		foreach ( $values as $key => $item ) {
			$values[ $key ] = is_string( $key ) && in_array( strtolower( $key ), self::CREDENTIAL_KEYS, true ) && null !== $item && '' !== $item
				? self::REDACTED
				: $this->redact( $item, $depth + 1 );
		}

		return $values;
	}

	/**
	 * A copy of an error with its messages and data redacted, code by code.
	 *
	 * @param WP_Error $error The error.
	 * @param int      $depth How deep the error sits in the context.
	 * @return WP_Error
	 */
	private function redact_error( WP_Error $error, int $depth ): WP_Error {
		$copy = new WP_Error();
		foreach ( $error->get_error_codes() as $code ) {
			foreach ( $error->get_error_messages( $code ) as $message ) {
				$copy->add( $code, $this->redact_string( (string) $message ) );
			}
			foreach ( $error->get_all_error_data( $code ) as $data ) {
				$copy->add_data( $this->redact( $data, $depth + 1 ), $code );
			}
		}

		return $copy;
	}

	/**
	 * A string with the credentials inside it replaced.
	 *
	 * @param string $text The string.
	 * @return string
	 */
	private function redact_string( string $text ): string {
		return (string) preg_replace( array_keys( self::CREDENTIAL_PATTERNS ), array_values( self::CREDENTIAL_PATTERNS ), $text );
	}
}
