/**
 * External dependencies
 */
import { promisify } from 'util';
import { exec, execFile } from 'child_process';
import { stripVTControlCharacters } from 'node:util';

const execAsync = promisify( exec );
const execFileAsync = promisify( execFile );

const WP_EVAL_JSON_RESULT_MARKER = '__E2E_WP_EVAL_RESULT__';

/**
 * Runs a command in the E2E CLI container. Use an argument array when the command contains dynamic values.
 *
 * Targets `.wp-env.e2e.json` by default; `E2E_WP_ENV_CONFIG` overrides the config file for callers whose disposable environment runs under a different name (for example an isolated audit profile), without changing the default every other caller relies on.
 *
 * Await each call before starting the next, never through `Promise.all`: wp-env rewrites its cache file without locking, and two overlapping calls can drop its `runtime` key so every later wp-env command fails with "Environment not initialized".
 */
const wpCLI = async ( command: string | string[] ) => {
	const wpEnvConfig = process.env.E2E_WP_ENV_CONFIG ?? '.wp-env.e2e.json';
	const { stdout, stderr } = Array.isArray( command )
		? await execFileAsync( 'pnpm', [
				'exec',
				'wp-env',
				'--config',
				wpEnvConfig,
				'run',
				'cli',
				'--',
				...command,
		  ] )
		: await execAsync(
				`pnpm exec wp-env --config "${ wpEnvConfig }" run cli -- ${ command }`
		  );

	return { stdout, stderr };
};

/**
 * Evaluates a PHP body inside the E2E CLI container and returns its JSON-safe return value, for callers that need a real return value back rather than raw command output. The body runs inside a closure (`return ( static function () { … } )();`) and is transported base64-encoded so WP-CLI's own argument parsing cannot reshape it; `envAssignments` (each a literal `KEY=value` string) are set through `env` ahead of `wp --user=1 eval`, for probes that need a process environment variable in place before WordPress bootstraps.
 */
const wpEvalJson = async < Result >(
	phpBody: string,
	envAssignments: string[] = []
): Promise< Result > => {
	const source = `return ( static function () {\n${ phpBody }\n} )();`;
	const encodedSource = Buffer.from( source, 'utf8' ).toString( 'base64' );
	const commandPhp = `$e2e_wp_eval_source = base64_decode( '${ encodedSource }', true ); if ( false === $e2e_wp_eval_source ) { throw new RuntimeException( 'Could not decode the wpEvalJson probe.' ); } $e2e_wp_eval_result = eval( $e2e_wp_eval_source ); echo "\\n${ WP_EVAL_JSON_RESULT_MARKER }" . wp_json_encode( $e2e_wp_eval_result ) . "\\n";`;

	const { stdout } = await wpCLI( [
		'env',
		...envAssignments,
		'wp',
		'--user=1',
		'eval',
		commandPhp,
	] );

	const resultLine = stdout
		.split( /\r?\n/ )
		.map( ( line ) => stripVTControlCharacters( line ).trim() )
		.toReversed()
		.find( ( line ) => line.startsWith( WP_EVAL_JSON_RESULT_MARKER ) );

	if ( ! resultLine ) {
		throw new Error( 'wpEvalJson output contained no result marker.' );
	}

	return JSON.parse(
		resultLine.slice( WP_EVAL_JSON_RESULT_MARKER.length )
	) as Result;
};

export { wpCLI, wpEvalJson };
