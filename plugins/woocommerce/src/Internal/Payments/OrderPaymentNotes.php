<?php
/**
 * OrderPaymentNotes class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Finds a payment note already on an order, and records a note's private identity.
 *
 * A provider tags each payment note with a private identity, stored as its SHA-256 hash in comment meta under the
 * provider's key, so the same note is found again whatever its text reads by then. Finding a note writes nothing; a
 * caller that wants a note found by its text to carry the identity records it.
 *
 * @since 11.2.0
 * @internal
 */
class OrderPaymentNotes {

	/**
	 * Find the order's note that carries an identity.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order             Order object.
	 * @param string   $identity          Note identity, before hashing.
	 * @param string   $identity_meta_key Comment-meta key the provider stores note identities under.
	 * @return int The note's comment ID, or 0 when no note carries the identity.
	 */
	public function find_by_identity( WC_Order $order, string $identity, string $identity_meta_key ): int {
		if ( '' === $identity || '' === $identity_meta_key ) {
			return 0;
		}

		$identity_hash = hash( 'sha256', $identity );
		foreach ( $this->get_notes( $order ) as $order_note ) {
			if ( in_array( $identity_hash, get_comment_meta( $order_note->id, $identity_meta_key, false ), true ) ) {
				return (int) $order_note->id;
			}
		}

		return 0;
	}

	/**
	 * Find the order's note that reads as a note or one of its other renderings.
	 *
	 * When several notes match, the last one the order's notes list returns is the one found.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order            Order object.
	 * @param string   $note             Note text.
	 * @param string[] $equivalent_notes Other exact renderings of the same note.
	 * @return int The note's comment ID, or 0 when no note matches.
	 */
	public function find_by_content( WC_Order $order, string $note, array $equivalent_notes = array() ): int {
		$candidates = array_values( array_unique( array_merge( array( $note ), $equivalent_notes ) ) );
		$note_id    = 0;
		foreach ( $this->get_notes( $order ) as $order_note ) {
			if ( in_array( (string) $order_note->content, $candidates, true ) ) {
				$note_id = (int) $order_note->id;
			}
		}

		return $note_id;
	}

	/**
	 * Record an identity on a note, unless the note already carries it.
	 *
	 * @since 11.2.0
	 *
	 * @param int    $note_id           Note comment ID.
	 * @param string $identity          Note identity, before hashing.
	 * @param string $identity_meta_key Comment-meta key the provider stores note identities under.
	 */
	public function record_identity( int $note_id, string $identity, string $identity_meta_key ): void {
		if ( 0 >= $note_id || '' === $identity || '' === $identity_meta_key ) {
			return;
		}

		$identity_hash = hash( 'sha256', $identity );
		if ( in_array( $identity_hash, get_comment_meta( $note_id, $identity_meta_key, false ), true ) ) {
			return;
		}

		add_comment_meta( $note_id, $identity_meta_key, $identity_hash );
	}

	/**
	 * Get every note of the order, customer notes included.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int,\stdClass>
	 */
	private function get_notes( WC_Order $order ): array {
		return wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
	}
}
