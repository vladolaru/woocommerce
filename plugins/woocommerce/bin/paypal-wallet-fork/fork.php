<?php
/**
 * One-shot fork of the PayPal wallet code from the extension clone into core.
 *
 * Usage: php fork.php --extension=<path> --core=<path> [--dry-run]
 *
 * Copies the kept modules and the plugin root, rewrites namespaces and the text domain, lays the PHP out
 * PSR-4 under core's Wallet directory and the JS under core's client package, deletes the vendored tree,
 * and writes path-map.json plus an audit of what a rename cannot prove safe. Run once; keep as the record.
 * With --dry-run it only counts and audits: it writes no file, including audit.txt and path-map.json.
 *
 * @package WooCommerce\Tools
 */

// This is a command-line maintenance script, not WordPress runtime code: it runs without WordPress loaded, so the
// WordPress filesystem and JSON sniffs do not apply. Plain PHP functions are the only option. The two other sniffs it
// trips (exec and output escaping) are ignored on their own lines below.
// phpcs:disable WordPress.WP.AlternativeFunctions

declare( strict_types = 1 );

$options   = getopt( '', array( 'extension:', 'core:', 'dry-run' ) );
$extension = rtrim( (string) ( $options['extension'] ?? '' ), '/' );
$core      = rtrim( (string) ( $options['core'] ?? '' ), '/' );
$dry_run   = isset( $options['dry-run'] );
if ( ! is_dir( "$extension/modules" ) || ! is_dir( "$core/plugins/woocommerce/src" ) ) {
	fwrite( STDERR, "Usage: php fork.php --extension=<extension clone> --core=<core clone> [--dry-run]\n" );
	exit( 1 );
}

const NS_OLD         = 'WooCommerce\\PayPalCommerce\\';
const NS_NEW         = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\PayPal\\Wallet\\';
const NS_VENDOR_OLD  = 'WooCommerce\\PayPalCommerce\\Vendor\\';
const NS_VENDOR_NEW  = 'Automattic\\WooCommerce\\Vendor\\';
const NS_LOG_OLD     = 'WooCommerce\\WooCommerce\\Logging\\';
const NS_LOG_NEW     = NS_NEW . 'Logging\\';
const NS_PSR_LOG_OLD = 'Psr\\Log\\';
const NS_PSR_LOG_NEW = 'Automattic\\WooCommerce\\Vendor\\Psr\\Log\\';
const TEXTDOMAIN_OLD = 'woocommerce-paypal-payments';
const TEXTDOMAIN_NEW = 'woocommerce';

$plugin_dir   = "$core/plugins/woocommerce";
$wallet_dir   = "$plugin_dir/src/Internal/Payments/Providers/PayPal/Wallet";
$client_dir   = "$plugin_dir/client/paypal-wallet/modules";
$vendored_dir = "$plugin_dir/src/Internal/Payments/Providers/PayPal/woocommerce-paypal-payments";
$module_map   = json_decode( (string) file_get_contents( __DIR__ . '/module-map.json' ), true );

$path_map = array();
$audit    = array();
$counts   = array(
	'php'   => 0,
	'js'    => 0,
	'other' => 0,
);

/**
 * Apply the namespace and text-domain rewrites to a file's contents.
 *
 * @param string $contents File contents.
 * @return string The rewritten contents.
 */
function rewrite( string $contents ): string {
	// Escaped forms first (class names inside single-quoted PHP strings use double backslashes).
	$pairs    = array(
		str_replace( '\\', '\\\\', NS_VENDOR_OLD )  => str_replace( '\\', '\\\\', NS_VENDOR_NEW ),
		NS_VENDOR_OLD                               => NS_VENDOR_NEW,
		str_replace( '\\', '\\\\', NS_LOG_OLD )     => str_replace( '\\', '\\\\', NS_LOG_NEW ),
		NS_LOG_OLD                                  => NS_LOG_NEW,
		str_replace( '\\', '\\\\', NS_OLD )         => str_replace( '\\', '\\\\', NS_NEW ),
		NS_OLD                                      => NS_NEW,
		str_replace( '\\', '\\\\', NS_PSR_LOG_OLD ) => str_replace( '\\', '\\\\', NS_PSR_LOG_NEW ),
	);
	$contents = strtr( $contents, $pairs );

	// Unprefixed PSR-3: core prefixes psr/log 1.1.4 through Mozart. The lookbehind skips names already under a vendor prefix.
	$contents = preg_replace( '/(?<![A-Za-z0-9_\\\\])(\\\\?)Psr\\\\Log\\\\/', '$1' . str_replace( '\\', '\\\\', NS_PSR_LOG_NEW ), $contents );

	// The root namespaces themselves (no trailing separator): the plugin root classes, the logging module class and `@package` tags.
	$contents = preg_replace( '/(namespace |@package )WooCommerce\\\\PayPalCommerce(?=;|\s*$)/m', '$1' . rtrim( str_replace( '\\', '\\\\', NS_NEW ), '\\' ), $contents );
	$contents = preg_replace( '/(namespace |@package )WooCommerce\\\\WooCommerce\\\\Logging(?=;|\s*$)/m', '$1' . rtrim( str_replace( '\\', '\\\\', NS_LOG_NEW ), '\\' ), $contents );

	// Module classes live in src/ and require their wiring from the module root; after the relayout both sit together.
	$contents = str_replace(
		array( "__DIR__ . '/../services.php'", "__DIR__ . '/../extensions.php'", "__DIR__ . '/../factories.php'" ),
		array( "__DIR__ . '/services.php'", "__DIR__ . '/extensions.php'", "__DIR__ . '/factories.php'" ),
		$contents
	);

	// Text domain, as a quoted token. The inbox note source keeps the old string (shared contract).
	$lines = explode( "\n", $contents );
	foreach ( $lines as $i => $line ) {
		if ( false !== strpos( $line, 'set_source(' ) ) {
			continue;
		}
		$lines[ $i ] = str_replace( array( "'" . TEXTDOMAIN_OLD . "'", '"' . TEXTDOMAIN_OLD . '"' ), array( "'" . TEXTDOMAIN_NEW . "'", '"' . TEXTDOMAIN_NEW . '"' ), $line );
	}
	return implode( "\n", $lines );
}

/**
 * Copy one file with rewriting, recording the path map and the audit.
 *
 * @param string $from          Absolute source path in the extension clone.
 * @param string $to            Absolute destination path in the core clone.
 * @param string $relative_from Source path relative to the extension clone (the path-map key).
 * @param string $relative_to   Destination path relative to the core clone (the path-map value).
 */
function copy_file( string $from, string $to, string $relative_from, string $relative_to ): void {
	global $path_map, $audit, $counts, $dry_run;
	$ext                        = pathinfo( $from, PATHINFO_EXTENSION );
	$path_map[ $relative_from ] = $relative_to;
	$text                       = in_array( $ext, array( 'php', 'js', 'jsx', 'ts', 'tsx', 'scss', 'css', 'json', 'md', 'txt' ), true );
	$contents                   = $text ? rewrite( (string) file_get_contents( $from ) ) : null;
	if ( 'php' === $ext ) {
		++$counts['php'];
		audit_php( $contents, $relative_to );
	} elseif ( in_array( $ext, array( 'js', 'jsx', 'ts', 'tsx' ), true ) ) {
		++$counts['js'];
	} else {
		++$counts['other'];
	}
	if ( $dry_run ) {
		return;
	}
	if ( ! is_dir( dirname( $to ) ) ) {
		mkdir( dirname( $to ), 0755, true );
	}
	if ( null === $contents ) {
		copy( $from, $to );
	} else {
		file_put_contents( $to, $contents );
	}
}

/**
 * Record what a rename cannot prove safe in a PHP file.
 *
 * @param string $contents    The rewritten PHP contents.
 * @param string $relative_to Destination path relative to the core clone, used in the audit lines.
 */
function audit_php( string $contents, string $relative_to ): void {
	global $audit;
	$patterns = array(
		'class name in a string'    => '/[\'"](Automattic\\\\\\\\WooCommerce\\\\\\\\Internal|WooCommerce\\\\\\\\PayPalCommerce)/',
		'class_exists/is_a on text' => '/(class_exists|is_a|is_subclass_of|method_exists)\(\s*[\'"]/',
		'plugin path or URL helper' => '/\b(plugin_dir_url|plugin_dir_path|plugins_url|plugin_basename)\(/',
		'relative include'          => '/(require|include)(_once)?\s+__DIR__\s*\.\s*[\'"]\/\.\./',
		'old text domain'           => '/[\'"]' . preg_quote( TEXTDOMAIN_OLD, '/' ) . '[\'"]/',
		'properties service'        => '/ppcp\.path-to-plugin-(folder|main-file)|ppcp\.base-name/',
		'old prefix remains'        => '/WooCommerce\\\\PayPalCommerce/',
	);
	foreach ( explode( "\n", $contents ) as $n => $line ) {
		foreach ( $patterns as $label => $pattern ) {
			if ( preg_match( $pattern, $line ) ) {
				$audit[] = sprintf( '%-28s %s:%d: %s', $label, $relative_to, $n + 1, trim( $line ) );
			}
		}
	}
}

/**
 * Recursively copy a directory, mapping each file through the callback for its destination.
 *
 * @param string   $from_dir          Absolute source directory.
 * @param string   $relative_from_dir Source directory relative to the extension clone.
 * @param callable $destination       Receives a path relative to the source directory; returns the absolute and core-relative destination paths.
 */
function copy_tree( string $from_dir, string $relative_from_dir, callable $destination ): void {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$rel                      = substr( $file->getPathname(), strlen( $from_dir ) + 1 );
		list( $to, $relative_to ) = $destination( $rel );
		copy_file( $file->getPathname(), $to, "$relative_from_dir/$rel", $relative_to );
	}
}

$core_rel = static function ( string $absolute ) use ( $core ): string {
	return substr( $absolute, strlen( $core ) + 1 );
};

// 1. Plugin root: src/ → Wallet/, lib/common/ → Wallet/Common/.
copy_tree(
	"$extension/src",
	'src',
	static function ( string $rel ) use ( $wallet_dir, $core_rel ): array {
		return array( "$wallet_dir/$rel", $core_rel( "$wallet_dir/$rel" ) );
	}
);
copy_tree(
	"$extension/lib/common",
	'lib/common',
	static function ( string $rel ) use ( $wallet_dir, $core_rel ): array {
		return array( "$wallet_dir/Common/$rel", $core_rel( "$wallet_dir/Common/$rel" ) );
	}
);

// 2. Kept modules.
foreach ( $module_map as $dir => $segment ) {
	$module = "$extension/modules/$dir";
	if ( ! is_dir( $module ) ) {
		fwrite( STDERR, "Missing module directory: $module\n" );
		exit( 1 );
	}
	// PHP: src/** → Wallet/<Segment>/**; services.php and extensions.php beside; module.php and composer.json are not needed.
	if ( is_dir( "$module/src" ) ) {
		copy_tree(
			"$module/src",
			"modules/$dir/src",
			static function ( string $rel ) use ( $wallet_dir, $segment, $core_rel ): array {
				return array( "$wallet_dir/$segment/$rel", $core_rel( "$wallet_dir/$segment/$rel" ) );
			}
		);
	}
	foreach ( array( 'services.php', 'extensions.php', 'factories.php' ) as $wiring ) {
		if ( file_exists( "$module/$wiring" ) ) {
			copy_file( "$module/$wiring", "$wallet_dir/$segment/$wiring", "modules/$dir/$wiring", $core_rel( "$wallet_dir/$segment/$wiring" ) );
		}
	}
	// JS, SCSS, static assets, fonts and block.json keep the module's relative shape under the client package.
	foreach ( array( 'resources', 'assets', 'fonts' ) as $sub ) {
		if ( is_dir( "$module/$sub" ) ) {
			copy_tree(
				"$module/$sub",
				"modules/$dir/$sub",
				static function ( string $rel ) use ( $client_dir, $dir, $sub, $core_rel ): array {
					return array( "$client_dir/$dir/$sub/$rel", $core_rel( "$client_dir/$dir/$sub/$rel" ) );
				}
			);
		}
	}
	if ( file_exists( "$module/block.json" ) ) {
		copy_file( "$module/block.json", "$client_dir/$dir/block.json", "modules/$dir/block.json", $core_rel( "$client_dir/$dir/block.json" ) );
	}
}

// 3. The vendored tree goes away.
if ( ! $dry_run && is_dir( $vendored_dir ) ) {
	exec( 'rm -rf ' . escapeshellarg( $vendored_dir ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Command-line script.
}

// 4. Outputs.
ksort( $path_map );
sort( $audit );
if ( $dry_run ) {
	printf( "Dry run: %d PHP, %d JS, %d other files; %d audit lines (nothing written)\n", $counts['php'], $counts['js'], $counts['other'], count( $audit ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Command-line output.
} else {
	file_put_contents( __DIR__ . '/path-map.json', json_encode( $path_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	file_put_contents( __DIR__ . '/audit.txt', implode( "\n", $audit ) . "\n" );
	printf( "Forked: %d PHP, %d JS, %d other files; %d audit lines in audit.txt\n", $counts['php'], $counts['js'], $counts['other'], count( $audit ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- Command-line output.
}
