<?php
/**
 * Captured timeline events with the "Fee details" note client 11.1.0 rendered for each.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * Reads `rec-n296-captured-event-notes.json`; its `_meta` says how each event was recorded and rendered.
 */
final class ClientRenderedCapturedEvents {

	private const FIXTURE = __DIR__ . '/rec-n296-captured-event-notes.json';

	/**
	 * Get every case by name.
	 *
	 * @return array<string,array{source:string,event:array<string,mixed>,client_html:string}>
	 */
	public static function all(): array {
		return json_decode( (string) file_get_contents( self::FIXTURE ), true )['cases']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
	}

	/**
	 * Get one case by name.
	 *
	 * @param string $name Case name, for example `recorded:pi_...`.
	 * @return array{source:string,event:array<string,mixed>,client_html:string}
	 */
	public static function get( string $name ): array {
		return self::all()[ $name ];
	}
}
