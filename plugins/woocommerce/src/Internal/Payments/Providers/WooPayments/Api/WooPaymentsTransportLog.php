<?php
/**
 * WooPaymentsTransportLog class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted platform requests and responses to the WooPayments log when debug logging is on.
 *
 * Logging is on in dev mode or with the gateway's `enable_logging` setting, as WooPaymentsLogger::can_log() decides.
 * Lines are written through WooPaymentsLogger, so they carry its request context and source, and a line that fails to
 * write is dropped. Redaction runs only when a line is written; info() takes a
 * context its caller already passed through redact(). warning() is the one line written whatever the setting.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTransportLog {

	/**
	 * Common keys in API requests/responses that must be redacted before logging.
	 *
	 * Client 11.1.0's API_KEYS_TO_REDACT (wc-payment-api/class-wc-payments-api-client.php:94) is the base of this list,
	 * the logging redaction contract; keys added here are commented inline, and redacting more is always acceptable.
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
		// WooPay webhook signing secret is a credential; a logged copy would let a log reader forge order-status deliveries. Client 11.1.0's list does not carry it.
		'webhook_secret',
		// Session and checkout keys and credentials client 11.1.0's list leaves out.
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
		// The received-webhook line logs whole platform objects. Tax IDs and free-text
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
	 * Metadata keys the transport log keeps: order and customer references and payment kind flags.
	 */
	private const METADATA_KEYS_TO_KEEP = array( 'order_id', 'order_number', 'customer_id', 'subscription_id', 'payment_type', 'gateway_type', 'ipp_channel', 'paid_on_woopay' );

	/**
	 * Key endings redacted like API_KEYS_TO_REDACT: any `*_secret` or `*_key`, and any `*_email` or `*_phone`, such as a
	 * PaymentIntent's receipt_email or an invoice's customer_phone.
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
	 * WooPayments logger, whose logging check and source the transport log uses.
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
	 * logger, which writes only when logging is on.
	 *
	 * @since 11.2.0
	 *
	 * @param string              $label   Line text.
	 * @param array<string,mixed> $payload Payload, redacted here before it is written.
	 */
	public function debug_payload( string $label, array $payload ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->write( 'debug', $label, array( 'body' => $this->redact( $payload ) ) );
	}

	/**
	 * Log the platform error line, "<message> (<code>)" as client 11.1.0 writes it (wc-payment-api/class-wc-payments-api-client.php:2912),
	 * when transport logging is enabled.
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
	 * @param string              $level   Log level.
	 * @param string              $message Line text.
	 * @param array<string,mixed> $context Context, already redacted.
	 * @param bool                $always  Whether to write whatever the logging setting.
	 */
	private function write( string $level, string $message, array $context = array(), bool $always = false ): void {
		if ( $always ) {
			$this->logger->log_always( $message, $level, $context );
		} else {
			$this->logger->log( $message, $level, $context );
		}
	}

	/**
	 * Redact what the transport log writes: a request's params, a response body, or the platform error's message or code.
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
		return self::redact_array( $input );
	}

	/**
	 * Redact sensitive keys from a payload before logging.
	 *
	 * As client 11.1.0's redact_array() (class-wc-payments-utils.php:477): matching keys are replaced with
	 * '(redacted)' at any depth, objects log as their class name, and deep
	 * recursion is cut off. This also matches keys whatever their case and
	 * by ending (API_KEY_SUFFIXES_TO_REDACT), and cleans every string value
	 * with redact_string().
	 *
	 * @param mixed $input Payload to redact.
	 * @param int   $level Current recursion depth.
	 * @return mixed
	 */
	private static function redact_array( $input, int $level = 0 ) {
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
			if ( self::is_key_to_redact( $key ) ) {
				$result[ $key ] = self::REDACTED;
				continue;
			}

			$result[ $key ] = is_string( $key ) && 'metadata' === strtolower( $key ) && is_array( $value )
				? self::redact_metadata( $value )
				: self::redact_array( $value, $level + 1 );
		}

		return $result;
	}

	/**
	 * Redact a platform object's metadata, which can hold any text a store or extension put there.
	 *
	 * Only the references support needs to find an order, and the payment kind flags, are kept (METADATA_KEYS_TO_KEEP,
	 * none of them a key redacted elsewhere), and only when the value is a short token; every other value, or a kept key
	 * with free text, is replaced whole.
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
	 * @param int|string $key Payload key.
	 * @return bool
	 */
	private static function is_key_to_redact( $key ): bool {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$key = strtolower( $key );
		if ( in_array( $key, self::API_KEYS_TO_REDACT, true ) ) {
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
