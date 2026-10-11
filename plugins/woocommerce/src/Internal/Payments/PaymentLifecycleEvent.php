<?php
/**
 * PaymentLifecycleEvent class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use InvalidArgumentException;

/**
 * Describes the order changes a payment provider event asks for: status, meta, and a note added once.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentLifecycleEvent {

	/**
	 * Lifecycle status: payment completed.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Lifecycle status: payment authorized and awaiting capture.
	 *
	 * @var string
	 */
	public const STATUS_AUTHORIZED = 'authorized';

	/**
	 * Lifecycle status: payment failed.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Lifecycle status: payment canceled.
	 *
	 * @var string
	 */
	public const STATUS_CANCELED = 'canceled';

	/**
	 * Lifecycle status: capture authorization expired.
	 *
	 * @var string
	 */
	public const STATUS_CAPTURE_EXPIRED = 'capture_expired';

	/**
	 * Lifecycle status: the payment is not settled. It waits for the shopper or the provider, or a capture or cancel
	 * failed and the authorization stays. The order status does not change.
	 *
	 * @var string
	 */
	public const STATUS_STARTED = 'started';

	/**
	 * Note type: completion note without payment details, such as a zero-total order's.
	 *
	 * Note types name a note's kind whatever its text or language; the runtime keys a typed lifecycle note's identity on its
	 * type (an untyped note's on its text), and itself compares only payment complete, payment success and capture expired.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_PAYMENT_COMPLETE = 'payment_complete';

	/**
	 * Note type: completion note with the payment's details.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_PAYMENT_SUCCESS = 'payment_success';

	/**
	 * Note type: payment authorization.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';

	/**
	 * Note type: payment failure.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_PAYMENT_FAILED = 'payment_failed';

	/**
	 * Note type: payment started.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_PAYMENT_STARTED = 'payment_started';

	/**
	 * Note type: capture success.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_CAPTURE_SUCCESS = 'capture_success';

	/**
	 * Note type: capture failure.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_CAPTURE_FAILED = 'capture_failed';

	/**
	 * Note type: capture authorization canceled.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_CAPTURE_CANCELED = 'capture_canceled';

	/**
	 * Note type: capture authorization expired.
	 *
	 * @var string
	 */
	public const NOTE_TYPE_CAPTURE_EXPIRED = 'capture_expired';

	/**
	 * Lifecycle status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Provider payment reference, such as the provider's payment or charge ID.
	 *
	 * @var string|null
	 */
	private ?string $payment_reference;

	/**
	 * Order meta keys and values to update.
	 *
	 * @var array<string,string>
	 */
	private array $meta_to_update;

	/**
	 * Order meta keys to delete.
	 *
	 * @var string[]
	 */
	private array $meta_to_delete;

	/**
	 * Order note to add once.
	 *
	 * @var string|null
	 */
	private ?string $note;

	/**
	 * Note type the note's identity is keyed on, whatever the note's text or language.
	 *
	 * @var string|null
	 */
	private ?string $note_type;

	/**
	 * Other texts of the same note the order may already carry, such as the note in another language or without its
	 * currency code, so the note is added once.
	 *
	 * @var string[]
	 */
	private array $note_equivalents;

	/**
	 * Whether this event must leave the order status untouched.
	 *
	 * @var bool
	 */
	private bool $preserve_order_status;

	/**
	 * Constructor.
	 *
	 * @since 11.0.0
	 *
	 * @param string              $status            Lifecycle status.
	 * @param string|null         $payment_reference Provider payment reference.
	 * @param array<string,mixed> $meta_to_update    Order meta to update.
	 * @param array<int,string>   $meta_to_delete    Order meta keys to delete.
	 * @param string|null         $note              Order note to add.
	 * @param string|null         $note_type         Stable note type.
	 * @param array<int,mixed>    $note_equivalents  Other texts of the same note.
	 * @param bool                $preserve_order_status Whether the order status must stay untouched.
	 * @throws InvalidArgumentException When an unknown status is supplied.
	 */
	public function __construct( string $status, ?string $payment_reference = null, array $meta_to_update = array(), array $meta_to_delete = array(), ?string $note = null, ?string $note_type = null, array $note_equivalents = array(), bool $preserve_order_status = false ) {
		if ( ! in_array( $status, $this->get_allowed_statuses(), true ) ) {
			throw new InvalidArgumentException( esc_html( sprintf( 'Unknown payment lifecycle status: %s', $status ) ) );
		}

		$this->status                = $status;
		$this->payment_reference     = $payment_reference;
		$this->meta_to_update        = $this->normalize_meta_to_update( $meta_to_update );
		$this->meta_to_delete        = array_values( array_map( 'strval', $meta_to_delete ) );
		$this->note                  = $note;
		$this->note_type             = null === $note_type || '' === $note_type ? null : $note_type;
		$this->note_equivalents      = $this->normalize_note_equivalents( $note_equivalents );
		$this->preserve_order_status = $preserve_order_status;
	}

	/**
	 * Get the lifecycle status a provider outcome asks for.
	 *
	 * @since 11.2.0
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string One of the STATUS_* constants; an unknown outcome status is a failure.
	 */
	public static function status_for_outcome( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return self::STATUS_COMPLETED;

			case PaymentOutcome::STATUS_AUTHORIZED:
				return self::STATUS_AUTHORIZED;

			case PaymentOutcome::STATUS_FAILED:
				return self::STATUS_FAILED;

			case PaymentOutcome::STATUS_CANCELED:
				return self::STATUS_CANCELED;

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return self::STATUS_STARTED;
		}

		return self::STATUS_FAILED;
	}

	/**
	 * Tell whether this event must leave the order status untouched.
	 *
	 * A payment refused before processing (for example by fraud screening)
	 * records its meta and note effects while the merchant decides the status.
	 *
	 * @return bool
	 */
	public function should_preserve_order_status(): bool {
		return $this->preserve_order_status;
	}

	/**
	 * Get the lifecycle status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get the payment reference.
	 *
	 * @return string|null
	 */
	public function get_payment_reference(): ?string {
		return $this->payment_reference;
	}

	/**
	 * Get order meta updates.
	 *
	 * @return array<string,string>
	 */
	public function get_meta_to_update(): array {
		return $this->meta_to_update;
	}

	/**
	 * Get order meta deletes.
	 *
	 * @return string[]
	 */
	public function get_meta_to_delete(): array {
		return $this->meta_to_delete;
	}

	/**
	 * Get the order note.
	 *
	 * @return string|null
	 */
	public function get_note(): ?string {
		return $this->note;
	}

	/**
	 * Get the stable order note type.
	 *
	 * @return string|null
	 */
	public function get_note_type(): ?string {
		return $this->note_type;
	}

	/**
	 * Get the other texts of the same order note.
	 *
	 * @return string[]
	 *
	 * @since 11.0.0
	 */
	public function get_note_equivalents(): array {
		return $this->note_equivalents;
	}

	/**
	 * Get the private identity of the event's order note: its payment reference, status and note type, or its text when
	 * it has no type.
	 *
	 * The identity names the note across deliveries of the same event, whatever the note's text reads.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_note_identity(): string {
		return 'payment_lifecycle:' . (string) $this->payment_reference . '|' . $this->status . '|' . ( $this->note_type ?? (string) $this->note );
	}

	/**
	 * Get supported lifecycle statuses.
	 *
	 * @return string[]
	 */
	private function get_allowed_statuses(): array {
		return array(
			self::STATUS_COMPLETED,
			self::STATUS_AUTHORIZED,
			self::STATUS_FAILED,
			self::STATUS_CANCELED,
			self::STATUS_CAPTURE_EXPIRED,
			self::STATUS_STARTED,
		);
	}

	/**
	 * Turn meta updates into the string values get_meta_to_update() returns (scalars and null cast to strings, null to '',
	 * other values JSON-encoded), sorted by key so an event writes its meta in the same order whatever order the provider
	 * built it in.
	 *
	 * @param array<string,mixed> $meta_to_update Raw meta updates.
	 * @return array<string,string>
	 */
	private function normalize_meta_to_update( array $meta_to_update ): array {
		$normalized = array();

		foreach ( $meta_to_update as $key => $value ) {
			$normalized[ (string) $key ] = $this->normalize_meta_value( $value );
		}

		ksort( $normalized );

		return $normalized;
	}

	/**
	 * Normalize a meta value to a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_meta_value( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Keep each other text of the note once.
	 *
	 * @param array<int,mixed> $note_equivalents Raw other texts.
	 * @return string[]
	 */
	private function normalize_note_equivalents( array $note_equivalents ): array {
		$normalized = array();

		foreach ( $note_equivalents as $note_equivalent ) {
			if ( ! is_string( $note_equivalent ) || '' === $note_equivalent || in_array( $note_equivalent, $normalized, true ) ) {
				continue;
			}

			$normalized[] = $note_equivalent;
		}

		return $normalized;
	}
}
