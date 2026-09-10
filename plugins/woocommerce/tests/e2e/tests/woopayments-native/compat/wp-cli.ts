import { execFile } from 'node:child_process';
import { resolve } from 'node:path';
import { stripVTControlCharacters } from 'node:util';

const RESULT_MARKER = '__WOOPAYMENTS_EXTENSION_COMPAT_RESULT__';
const DEFAULT_STORE_DIRECTORY = resolve( __dirname, '../../../../..' );

const COMMON_PROBE_PHP = String.raw`
$wcpay_extension_profile = get_option( 'e2e_woopayments_extension_compat_profile', array() );
if ( ! is_array( $wcpay_extension_profile ) || ! isset( $wcpay_extension_profile['pin_set'], $wcpay_extension_profile['versions'] ) || ! is_array( $wcpay_extension_profile['versions'] ) ) {
	throw new RuntimeException( 'The WooPayments extension compatibility profile marker is missing.' );
}
$wcpay_extension_version = static function ( string $extension ) use ( $wcpay_extension_profile ): string {
	$version = $wcpay_extension_profile['versions'][ $extension ] ?? '';
	if ( ! is_string( $version ) || '' === $version ) {
		throw new RuntimeException( sprintf( 'The extension compatibility profile has no %s version.', $extension ) );
	}
	return $version;
};
$wcpay_invoke_private = static function ( object $target, string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $target, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $target, $arguments );
};
$wcpay_fresh_cart = static function ( array $contents ): WC_Cart {
	$cart                = new WC_Cart();
	$cart->cart_contents = $contents;
	WC()->cart           = $cart;
	return $cart;
};
`;

function executeFile(
	command: string,
	args: string[],
	options: { cwd: string }
): Promise< string > {
	return new Promise( ( resolveOutput, reject ) => {
		execFile(
			command,
			args,
			{
				cwd: options.cwd,
				encoding: 'utf8',
				maxBuffer: 10 * 1024 * 1024,
			},
			( error, stdout, stderr ) => {
				if ( error ) {
					error.message = `${ error.message }\n${ stderr }`;
					reject( error );
					return;
				}
				resolveOutput( stdout );
			}
		);
	} );
}

/**
 * Run a provider-free extension integration probe through the selected wp-env
 * service. The PHP body executes inside a closure and must return JSON-safe
 * data. Base64 transport keeps WP-CLI argument parsing from reshaping PHP.
 */
export async function runExtensionCompatProbe< Result >(
	phpBody: string
): Promise< Result > {
	const storeDirectory =
		process.env.E2E_WOOPAYMENTS_EXTENSION_STORE_DIR ??
		DEFAULT_STORE_DIRECTORY;
	const wpEnvConfig =
		process.env.E2E_WOOPAYMENTS_EXTENSION_WP_ENV_CONFIG ??
		'.wp-env.e2e.json';
	const wpEnvService =
		process.env.E2E_WOOPAYMENTS_EXTENSION_WP_ENV_SERVICE ?? 'cli';
	const source = `return ( static function () {\n${ COMMON_PROBE_PHP }\n${ phpBody }\n} )();`;
	const encodedSource = Buffer.from( source, 'utf8' ).toString( 'base64' );
	const commandPhp = `$wcpay_extension_source = base64_decode( '${ encodedSource }', true ); if ( false === $wcpay_extension_source ) { throw new RuntimeException( 'Could not decode the extension compatibility probe.' ); } $wcpay_extension_result = eval( $wcpay_extension_source ); echo "\\n${ RESULT_MARKER }" . wp_json_encode( $wcpay_extension_result ) . "\\n";`;

	const stdout = await executeFile(
		'pnpm',
		[
			'exec',
			'wp-env',
			'--config',
			wpEnvConfig,
			'run',
			wpEnvService,
			'env',
			'PCP_SETTINGS_ENABLED=1',
			'wp',
			'--user=1',
			'eval',
			commandPhp,
		],
		{ cwd: storeDirectory }
	);
	const resultLine = stdout
		.split( /\r?\n/ )
		.map( ( line ) => stripVTControlCharacters( line ).trim() )
		.toReversed()
		.find( ( line ) => line.startsWith( RESULT_MARKER ) );

	if ( ! resultLine ) {
		throw new Error(
			'Extension compatibility WP-CLI output contained no result marker.'
		);
	}

	return JSON.parse( resultLine.slice( RESULT_MARKER.length ) ) as Result;
}
