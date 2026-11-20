<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

/**
 * Reads and seeds the queued events the reliability service keeps, by their stored names: the option row
 * `woocommerce_woopayments_queued_event_` plus the event ID's md5, and the copy under the extension's transient name
 * `wcpay_failed_event_` plus the same md5, which events the extension or an earlier build queued must keep.
 */
class QueuedEventRows {

	/**
	 * Get the extension-name transient name of an event.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	public static function name( string $event_id ): string {
		return 'wcpay_failed_event_' . md5( $event_id );
	}

	/**
	 * Get the option row name of an event.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	public static function row_name( string $event_id ): string {
		return 'woocommerce_woopayments_queued_event_' . md5( $event_id );
	}

	/**
	 * Seed a queued event in its row, as the service stores it.
	 *
	 * @param string              $event_id  Event ID.
	 * @param array<string,mixed> $event     Event payload.
	 * @param int|null            $stored_at When the row was last written; now when null.
	 */
	public function set_event( string $event_id, array $event, ?int $stored_at = null ): void {
		global $wpdb;

		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => self::row_name( $event_id ),
				'option_value' => maybe_serialize(
					array(
						'event'     => $event,
						'stored_at' => $stored_at ?? time(),
					)
				),
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Read a queued event from its row, by SQL.
	 *
	 * @param string $event_id Event ID.
	 * @return array<string,mixed>|null
	 */
	public function get_event( string $event_id ): ?array {
		$row = $this->get_row( $event_id );

		return null !== $row && is_array( $row['value']['event'] ?? null ) ? $row['value']['event'] : null;
	}

	/**
	 * Read an event's row as stored: its unserialized value and its autoload flag, by SQL.
	 *
	 * @param string $event_id Event ID.
	 * @return array{value:mixed,autoload:string}|null
	 */
	public function get_row( string $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", self::row_name( $event_id ) ), ARRAY_A );

		return is_array( $row ) ? array(
			'value'    => maybe_unserialize( $row['option_value'] ),
			'autoload' => (string) $row['autoload'],
		) : null;
	}

	/**
	 * Delete a queued event's row.
	 *
	 * @param string $event_id Event ID.
	 */
	public function delete_event( string $event_id ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::row_name( $event_id ) ) );
	}

	/**
	 * Seed an event under the extension's transient name only, as the extension or an earlier build queued it.
	 *
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $event    Event payload.
	 */
	public function set_transient_event( string $event_id, array $event ): void {
		set_transient( self::name( $event_id ), $event, DAY_IN_SECONDS );
	}

	/**
	 * Read the copy kept under the extension's transient name.
	 *
	 * @param string $event_id Event ID.
	 * @return array<string,mixed>|null
	 */
	public function get_transient_event( string $event_id ): ?array {
		$event = get_transient( self::name( $event_id ) );

		return is_array( $event ) ? $event : null;
	}
}
