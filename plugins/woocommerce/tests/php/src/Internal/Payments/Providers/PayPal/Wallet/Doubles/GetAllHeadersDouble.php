<?php
/**
 * Stand-in for getallheaders(), which PHP defines for web servers but not for the command line the tests run on.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles
 */

declare( strict_types = 1 );

// The function it stands in for is global, so this file has no namespace.

// phpcs:disable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- A global class, as the function it serves.
/**
 * The headers the declared getallheaders() answers with. A global function lives as long as the PHP process, so the
 * function answers from this registry, which is empty until a test fills it, and a test that fills it empties it again
 * with reset() in tearDown(). Other code that asks for the headers, such as the REST authentication of WooCommerce,
 * gets an empty list, which is what it gets when the function does not exist.
 *
 * Nothing is declared when PHP already has the function: use in_effect() to skip a test then, since the real function
 * reads the headers of the real request.
 */
final class GetAllHeadersDouble {

	/**
	 * The headers, by name.
	 *
	 * @var array<string, string>
	 */
	private static array $headers = array();

	/**
	 * Whether the function in use is the one of this file.
	 *
	 * @return bool
	 */
	public static function in_effect(): bool {
		return function_exists( 'getallheaders' ) && ( new ReflectionFunction( 'getallheaders' ) )->getFileName() === __FILE__;
	}

	/**
	 * Make getallheaders() answer with the headers.
	 *
	 * @param array<string, string> $headers The headers, by name.
	 */
	public static function set_headers( array $headers ): void {
		self::$headers = $headers;
	}

	/**
	 * The headers.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return self::$headers;
	}

	/**
	 * Forget the headers.
	 */
	public static function reset(): void {
		self::$headers = array();
	}
}
// phpcs:enable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

if ( ! function_exists( 'getallheaders' ) ) {
	/**
	 * The headers a test registered.
	 *
	 * @return array<string, string>
	 */
	function getallheaders() { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- A stand-in for a PHP function.
		return GetAllHeadersDouble::headers();
	}
}
