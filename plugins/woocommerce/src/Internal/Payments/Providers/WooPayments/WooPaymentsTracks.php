<?php
/**
 * WooPaymentsTracks class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WC_Tracks;

/**
 * Marks WooPayments Tracks events as sent by WooCommerce core, and records the server-side admin ones.
 *
 * Event names are the WooPayments extension's, so the `payments_runtime` property tells the built-in WooPayments' events
 * from the extension's, and no `wcpay_version` is sent (core's `wc_version` names the release).
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsTracks {

	/**
	 * Event property naming the payments runtime that sent the event.
	 */
	public const RUNTIME_PROPERTY = 'payments_runtime';

	/**
	 * The `payments_runtime` value of every event sent here.
	 */
	public const RUNTIME = 'woocommerce_core';

	/**
	 * Mark event properties with the runtime and drop `wcpay_version`, the WooPayments extension's version property.
	 *
	 * @param array<string,mixed> $properties Event properties.
	 * @return array<string,mixed>
	 */
	public static function with_runtime( array $properties ): array {
		unset( $properties['wcpay_version'] );
		$properties[ self::RUNTIME_PROPERTY ] = self::RUNTIME;

		return $properties;
	}

	/**
	 * Record a server-side admin event through core's Tracks, which adds the `wcadmin_` prefix.
	 *
	 * `wc_admin_record_tracks_event()` also waits for WooCommerce's post types and loads Tracks in REST requests.
	 *
	 * @param string              $event_name Event name without the `wcadmin_` prefix.
	 * @param array<string,mixed> $properties Event properties.
	 */
	public static function record_wcadmin_event( string $event_name, array $properties = array() ): void {
		$properties = self::with_runtime( $properties );

		if ( function_exists( 'wc_admin_record_tracks_event' ) ) {
			wc_admin_record_tracks_event( $event_name, $properties );
		} elseif ( class_exists( WC_Tracks::class ) ) {
			WC_Tracks::record_event( $event_name, $properties );
		}
	}
}
