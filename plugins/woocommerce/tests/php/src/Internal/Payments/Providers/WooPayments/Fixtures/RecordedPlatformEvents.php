<?php
/**
 * Loader for the platform event bodies recorded from real deliveries.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

use InvalidArgumentException;

/**
 * Reads one recorded event body from a fixture file in this folder.
 *
 * Each file holds `_meta` (where and how the bodies were recorded) and `entries`, each with a `pair` name and the
 * `body` the store received.
 */
final class RecordedPlatformEvents {

	/**
	 * Return the recorded body of an entry.
	 *
	 * @param string $file Fixture file name in this folder.
	 * @param string $pair The entry's pair name.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the file has no such entry.
	 */
	public static function body( string $file, string $pair ): array {
		$recording = json_decode( (string) file_get_contents( __DIR__ . '/' . $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		foreach ( $recording['entries'] ?? array() as $entry ) {
			if ( $pair === $entry['pair'] ) {
				return $entry['body'];
			}
		}

		throw new InvalidArgumentException( esc_html( "No recorded entry {$pair} in {$file}." ) );
	}
}
