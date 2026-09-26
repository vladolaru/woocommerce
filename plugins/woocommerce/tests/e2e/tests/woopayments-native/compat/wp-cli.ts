import { execFile } from 'node:child_process';
import { resolve } from 'node:path';
import { stripVTControlCharacters } from 'node:util';

const RESULT_MARKER = '__WOOPAYMENTS_EXTENSION_COMPAT_RESULT__';
const DEFAULT_STORE_DIRECTORY = resolve( __dirname, '../../../../..' );

function executeFile(
	command: string,
	args: string[],
	options: { cwd: string; env?: typeof process.env }
): Promise< string > {
	return new Promise( ( resolveOutput, reject ) => {
		execFile(
			command,
			args,
			{
				cwd: options.cwd,
				env: options.env,
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

/** Run a JSON-returning PHP probe through the selected wp-env allocation. */
export async function runWpCliProbe< Result >(
	phpBody: string,
	options: {
		wpEnvConfig: string;
		wpEnvHome?: string;
		storeDirectory?: string;
		wpEnvService?: string;
	},
	execute = executeFile
): Promise< Result > {
	const source = `return ( static function () {\n${ phpBody }\n} )();`;
	const encodedSource = Buffer.from( source, 'utf8' ).toString( 'base64' );
	const commandPhp = `$wcpay_extension_source = base64_decode( '${ encodedSource }', true ); if ( false === $wcpay_extension_source ) { throw new RuntimeException( 'Could not decode the extension compatibility probe.' ); } $wcpay_extension_result = eval( $wcpay_extension_source ); echo "\\n${ RESULT_MARKER }" . wp_json_encode( $wcpay_extension_result ) . "\\n";`;

	const stdout = await execute(
		'pnpm',
		[
			'exec',
			'wp-env',
			'--config',
			options.wpEnvConfig,
			'run',
			options.wpEnvService ?? 'cli',
			'env',
			'PCP_SETTINGS_ENABLED=1',
			'wp',
			'--user=1',
			'eval',
			commandPhp,
		],
		{
			cwd: options.storeDirectory ?? DEFAULT_STORE_DIRECTORY,
			...( options.wpEnvHome
				? { env: { ...process.env, WP_ENV_HOME: options.wpEnvHome } }
				: {} ),
		}
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
