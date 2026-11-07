<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

/**
 * Reads and seeds the transients the reliability service keeps failed events in, by their stored name: the
 * `wcpay_failed_event_` prefix and the event ID's md5, which events queued before an update must keep.
 */
class FailedEventTransients {

	/**
	 * Get the stored transient name of an event.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	public static function name( string $event_id ): string {
		return 'wcpay_failed_event_' . md5( $event_id );
	}

	/**
	 * Seed a kept failed event.
	 *
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $event    Event payload.
	 */
	public function set_event( string $event_id, array $event ): void {
		set_transient( self::name( $event_id ), $event, DAY_IN_SECONDS );
	}

	/**
	 * Read a kept failed event.
	 *
	 * @param string $event_id Event ID.
	 * @return array<string,mixed>|null
	 */
	public function get_event( string $event_id ): ?array {
		$event = get_transient( self::name( $event_id ) );

		return is_array( $event ) ? $event : null;
	}

	/**
	 * Delete a kept failed event.
	 *
	 * @param string $event_id Event ID.
	 */
	public function delete_event( string $event_id ): void {
		delete_transient( self::name( $event_id ) );
	}
}
