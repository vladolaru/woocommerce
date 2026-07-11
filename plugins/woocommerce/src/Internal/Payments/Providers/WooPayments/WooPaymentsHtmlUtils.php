<?php
/**
 * WooPaymentsHtmlUtils class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Escaping helpers for WooPayments-compatible interpolated HTML.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsHtmlUtils {

	/**
	 * Escape text and unknown tags while replacing mapped interpolation elements.
	 *
	 * @param string               $text        Text containing interpolation elements.
	 * @param array<string,string> $element_map Opening HTML elements keyed by interpolation tag.
	 * @return string
	 */
	public static function escape_interpolated_html( string $text, array $element_map ): string {
		$tokenizer    = '/<(\/)?(\w+)\s*(\/)?>/';
		$string_queue = array();
		$token_queue  = array();
		$last_mapped  = true;
		$processed    = $text;

		while ( preg_match( $tokenizer, $processed, $matches ) ) {
			$matched        = $matches[0];
			$token          = $matches[2];
			$is_closing     = ! empty( $matches[1] );
			$is_self_closed = ! empty( $matches[3] );
			$split          = explode( $matched, $processed, 2 );

			if ( $last_mapped ) {
				$string_queue[] = $split[0];
			} else {
				$string_queue[ count( $string_queue ) - 1 ] .= $split[0];
			}
			$processed = $split[1];

			if ( isset( $element_map[ $token ] ) ) {
				preg_match( '/^<(\w+)(\s.+?)?\/?>$/', $element_map[ $token ], $map_matches );
				if ( empty( $map_matches ) ) {
					return esc_html( $text );
				}

				$tag   = $map_matches[1];
				$attrs = $map_matches[2] ?? '';
				if ( $is_closing ) {
					$token_queue[] = '</' . $tag . '>';
				} elseif ( $is_self_closed ) {
					$token_queue[] = '<' . $tag . $attrs . '/>';
				} else {
					$token_queue[] = '<' . $tag . $attrs . '>';
				}
				$last_mapped = true;
			} else {
				$string_queue[ count( $string_queue ) - 1 ] .= $matched;
				$last_mapped = false;
			}
		}

		if ( empty( $token_queue ) || count( $token_queue ) !== count( $string_queue ) ) {
			return esc_html( $text );
		}

		$result = '';
		while ( ! empty( $token_queue ) ) {
			$result .= esc_html( (string) array_shift( $string_queue ) ) . (string) array_shift( $token_queue );
		}

		return $result . esc_html( $processed );
	}
}
