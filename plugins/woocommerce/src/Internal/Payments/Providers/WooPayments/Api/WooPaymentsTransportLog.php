<?php
/**
 * WooPaymentsTransportLog class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted platform requests and responses to the WooPayments log when debug logging is on.
 *
 * Logging is on in dev mode or with the gateway's `enable_logging` setting, the one gate every WooPayments log line
 * uses (WooPaymentsLogger::can_log()). Lines are written through WooPaymentsLogger, so they carry its request context
 * and source, and a line that fails to write is dropped. Redaction runs only when a line is written; info() takes a
 * context its caller already passed through redact(). warning() is the one line written whatever the setting.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTransportLog {

	/**
	 * Common keys in API requests/responses that must be redacted before logging.
	 *
	 * Ported from the plugin's API_KEYS_TO_REDACT; the list is the logging
	 * redaction contract and must not be re-derived. Native-only additions are
	 * commented inline — redacting MORE than the plugin is always acceptable.
	 */
	private const API_KEYS_TO_REDACT = array(
		'client_secret',
		'email',
		'name',
		'first_name',
		'last_name',
		'phone',
		'company',
		'address_1',
		'address_2',
		'line1',
		'line2',
		'postal_code',
		'postcode',
		'state',
		'city',
		'country',
		'customer_name',
		'customer_email',
		// Free-text refund reason can contain merchant-entered PII, so keep it out of logs.
		'merchant_refund_reason',
		// Address autocomplete JWT is a credential; keep it out of logs.
		'token',
		// WooPay webhook signing secret is a credential; a logged copy would let a log reader forge order-status deliveries. Native-only hardening: the plugin's list does not carry it.
		'webhook_secret',
		// Native-only: session and checkout keys and credentials the plugin's list leaves out (monitor ruling 2026-10-05).
		'platform_checkout_key',
		'session',
		'session_id',
		'woopay_session',
		'access_token',
		'blog_token',
		'user_token',
		'signature',
		'sig',
		'secret',
		'authorization',
		'cookie',
		// Native-only: the received-webhook line logs whole platform objects (Codex review 75). Tax IDs and free-text
		// descriptions can carry personal data; the *_email and *_phone endings below cover receipt_email and customer_phone.
		'customer_tax_ids',
		'description',
		'custom_fields',
		'footer',
		// A dispute's evidence holds the shopper's addresses, email and purchase IP plus merchant free text; the issuer's
		// evidence is free text about the cardholder.
		'evidence',
		'issuer_evidence',
		// The Stripe Billing transaction update sends the shopper's name and country (StripeBillingInvoiceService::update_transaction_details()).
		'customer_first_name',
		'customer_last_name',
		'customer_country',
	);

	/**
	 * Metadata keys the transport log keeps (native-only): order and customer references and payment kind flags.
	 */
	private const METADATA_KEYS_TO_KEEP = array( 'order_id', 'order_number', 'customer_id', 'subscription_id', 'payment_type', 'gateway_type', 'ipp_channel', 'paid_on_woopay' );

	/**
	 * Key endings redacted like API_KEYS_TO_REDACT (native-only): any `*_secret` or `*_key` (monitor ruling 2026-10-05),
	 * and any `*_email` or `*_phone`, such as a PaymentIntent's receipt_email or an invoice's customer_phone (Codex review 75).
	 */
	private const API_KEY_SUFFIXES_TO_REDACT = array( '_secret', '_key', '_email', '_phone' );

	/**
	 * Logged in place of a redacted key's value, a value shaped like a Stripe secret, or everything after a URL's host.
	 */
	private const REDACTED = '(redacted)';

	/**
	 * Maximum recursion depth when redacting nested payloads for logging.
	 */
	private const REDACT_MAX_ARRAY_DEPTH = 10;

	/**
	 * Longest string value the transport log inspects; a longer one is redacted whole before any pattern runs.
	 */
	private const REDACT_MAX_STRING_BYTES = 2048;

	/**
	 * WooPayments logger, whose gate and source the transport log uses.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLogger $logger WooPayments logger.
	 */
	final public function init( WooPaymentsLogger $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Tell whether transport lines are written.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->logger->can_log();
	}

	/**
	 * Write an info line when logging is on.
	 *
	 * @param string              $message Line text.
	 * @param array<string,mixed> $context Context, already redacted.
	 */
	public function info( string $message, array $context = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->write( 'info', $message, $context );
	}

	/**
	 * Write one debug line with a payload, redacted, when transport logging is enabled.
	 *
	 * The received-event line of the webhook path uses it: client 11.1.0 writes 'WEBHOOK RECEIVED: <type> <id>' with the
	 * redacted body for every event it receives (class-wc-payments-webhook-processing-service.php:155-160), through its
	 * gated logger. A failure to write the line never reaches the caller.
	 *
	 * @since 11.2.0
	 *
	 * @param string              $label   Line text.
	 * @param array<string,mixed> $payload Payload, redacted here before it is written.
	 */
	public function debug_payload( string $label, array $payload ): void {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			$this->write( 'debug', $label, array( 'body' => $this->redact( $payload ) ) );
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Log the platform error line, "<message> (<code>)" as the client writes it, when transport logging is enabled.
	 *
	 * The message and the code are redacted each on its own, so a message replaced whole keeps its code.
	 *
	 * @param string $error_message Platform error message.
	 * @param string $error_code    Platform error code.
	 */
	public function error( string $error_message, string $error_code ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->write( 'error', sprintf( '%s (%s)', (string) $this->redact( $error_message ), (string) $this->redact( $error_code ) ) );
	}

	/**
	 * Write a warning line whatever the logging setting, for a request a callback tried to change on the money path.
	 *
	 * @since 11.2.0
	 *
	 * @param string $message Line text; it names keys, never their values.
	 */
	public function warning( string $message ): void {
		$this->write( 'warning', $message, array(), true );
	}

	/**
	 * Write a line through the WooPayments logger, which adds the request context and the source.
	 *
	 * A line that fails to write, from a throwing log handler or a hook the request context runs, is dropped without
	 * another logger call: it must never reach a platform request, where it would make an answered request look failed.
	 *
	 * @param string              $level   Log level.
	 * @param string              $message Line text.
	 * @param array<string,mixed> $context Context, already redacted.
	 * @param bool                $always  Whether to write whatever the logging setting.
	 */
	private function write( string $level, string $message, array $context = array(), bool $always = false ): void {
		try {
			if ( $always ) {
				$this->logger->log_always( $message, $level, $context );
			} else {
				$this->logger->log( $message, $level, $context );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Redact what the gated transport log writes: a request's params, a response body, or the platform error's message or code.
	 *
	 * Called only when that log is written: redaction looks at every string value, so a store with logging off does none of
	 * this work.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $input Params, body, message or code.
	 * @return mixed
	 */
	public function redact( $input ) {
		return self::redact_array( $input, self::API_KEYS_TO_REDACT );
	}

	/**
	 * Redact sensitive keys from a payload before logging.
	 *
	 * Ported from the plugin's redact_array: matching keys are replaced with
	 * '(redacted)' at any depth, objects log as their class name, and deep
	 * recursion is cut off. Native also matches keys whatever their case and
	 * by ending (API_KEY_SUFFIXES_TO_REDACT), and cleans every string value
	 * with redact_string().
	 *
	 * @param mixed    $input          Payload to redact.
	 * @param string[] $keys_to_redact Keys to redact.
	 * @param int      $level          Current recursion depth.
	 * @return mixed
	 */
	private static function redact_array( $input, array $keys_to_redact, int $level = 0 ) {
		if ( is_object( $input ) ) {
			return get_class( $input ) . '()';
		}

		if ( is_string( $input ) ) {
			return self::redact_string( $input );
		}

		if ( ! is_array( $input ) ) {
			return $input;
		}

		if ( self::REDACT_MAX_ARRAY_DEPTH <= $level ) {
			return '(recursion limit reached)';
		}

		$result = array();

		foreach ( $input as $key => $value ) {
			if ( self::is_key_to_redact( $key, $keys_to_redact ) ) {
				$result[ $key ] = self::REDACTED;
				continue;
			}

			$result[ $key ] = is_string( $key ) && 'metadata' === strtolower( $key ) && is_array( $value )
				? self::redact_metadata( $value )
				: self::redact_array( $value, $keys_to_redact, $level + 1 );
		}

		return $result;
	}

	/**
	 * Redact a platform object's metadata, which can hold any text a store or extension put there.
	 *
	 * Only the references support needs to find an order, and the payment kind flags, are kept (METADATA_KEYS_TO_KEEP,
	 * none of them a key redacted elsewhere), and only when the value is a short token; every other value, or a kept key
	 * with free text, is replaced whole (native-only, Codex reviews 76 and 77).
	 *
	 * @param array<int|string,mixed> $metadata Metadata.
	 * @return array<int|string,mixed>
	 */
	private static function redact_metadata( array $metadata ): array {
		$result = array();
		foreach ( $metadata as $key => $value ) {
			$is_kept = is_string( $key )
				&& in_array( strtolower( $key ), self::METADATA_KEYS_TO_KEEP, true )
				&& is_scalar( $value )
				&& 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', (string) $value );
			// A kept value is still cleaned like any logged string, so a secret-shaped token under a kept key is redacted.
			$result[ $key ] = $is_kept ? self::redact_string( (string) $value ) : self::REDACTED;
		}

		return $result;
	}

	/**
	 * Tell whether a payload key is redacted: a listed key, or one ending in a listed suffix, whatever its case.
	 *
	 * @param int|string $key            Payload key.
	 * @param string[]   $keys_to_redact Keys to redact.
	 * @return bool
	 */
	private static function is_key_to_redact( $key, array $keys_to_redact ): bool {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$key = strtolower( $key );
		if ( in_array( $key, $keys_to_redact, true ) ) {
			return true;
		}

		foreach ( self::API_KEY_SUFFIXES_TO_REDACT as $suffix ) {
			if ( strlen( $key ) > strlen( $suffix ) && substr( $key, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clean a logged string value, whatever key it sits under.
	 *
	 * A value is replaced whole when it is longer than REDACT_MAX_STRING_BYTES, holds a percent-encoded sequence or a JSON
	 * escaped slash, starts with `{` or `[`, or holds a Stripe secret or restricted key (`sk_live_…`, `rk_test_…`) or a
	 * client secret (`pi_…_secret_…`): encoded or embedded text cannot be cleaned in place. Otherwise every URL in it keeps
	 * only its scheme and host, followed by `/(redacted)`: Stripe login and onboarding links carry their credential in the
	 * path, and queries and fragments can carry session keys and tokens.
	 *
	 * @param string $value Logged value.
	 * @return string
	 */
	private static function redact_string( string $value ): string {
		if ( strlen( $value ) > self::REDACT_MAX_STRING_BYTES ) {
			return self::REDACTED;
		}

		$trimmed = ltrim( $value );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			return self::REDACTED;
		}

		if ( false !== strpos( $value, '\\/' ) || 1 === preg_match( '/%[0-9A-Fa-f]{2}|[sr]k_(?:live|test)_[A-Za-z0-9]|[a-z]_[A-Za-z0-9]++_secret_[A-Za-z0-9]/', $value ) ) {
			return self::REDACTED;
		}

		return (string) preg_replace( '#(https?://)(?:[^\s/?\#"\'<>@]*+@)?([^\s/?\#"\'<>@:]*+)[^\s"\'<>]*+#i', '$1$2/' . self::REDACTED, $value );
	}
}
