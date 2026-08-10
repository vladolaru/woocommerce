import { execFile } from 'node:child_process';
import { stripVTControlCharacters } from 'node:util';

/**
 * Forced-premise driver for the native multi-currency available-currency
 * catalog.
 *
 * WHY THIS EXISTS
 *
 * `MultiCurrencyStateBuilder::build()` composes the available-currency catalog
 * as the store default, plus the automatic rates held in the provider rate
 * cache (`wcpay_multi_currency_cached_currencies`), plus any already-enabled
 * currency that carries a manual rate. Every widening route
 * (`update-enabled-currencies`, `currencies/{code}`, the single-currency
 * settings write) validates its codes against that catalog first
 * (`MultiCurrencyRestController::validate_available_currency_codes`), and no
 * filter or registrar hook can inject rates.
 *
 * On the standing native store the rate cache holds an empty currency map, so
 * the catalog is one code wide (the USD default plus EUR, which only appears
 * because it is enabled with a manual rate). Nothing else can be enabled, and
 * no merchant currency-management behavior beyond that pair is reachable.
 *
 * WHAT THIS DRIVER DOES, AND WHAT IT DOES NOT PROVE
 *
 * This driver snapshots the raw rate-cache option row, merges the requested
 * codes into the cached rate map, proves the widened catalog through a cold
 * rebuild, and byte-restores the original row with a verified read — the same
 * shape as the account-cache forcing in
 * `drivers/card-testing-protection.ts`, and the same honesty boundary that
 * FIDELITY-CLAIMS.md records for it: a test running inside this scope proves
 * what native does *given* a catalog that offers those currencies. It proves
 * nothing about whether the provider serves rates for them, because the run
 * supplies that premise itself.
 *
 * The premise is narrow rather than invented: the connected account already
 * reports these codes among its supported customer currencies, so the only
 * thing the run substitutes is the FX rate payload the local platform does not
 * serve.
 *
 * The rate cache is restored unconditionally — including after a failed
 * scenario — rather than as a final test step. It is out-of-band run
 * scaffolding, not product state under test, so leaving it behind would
 * silently widen the catalog for every other spec on the standing store.
 * Product state (the enabled set, per-currency settings) keeps the package's
 * fail-closed discipline and is restored by the tests themselves, so a failed
 * scenario deliberately leaves its own residue for diagnosis and the next
 * run's precondition guard refuses to start until it is released.
 */

const CURRENCIES_CACHE_OPTION = 'wcpay_multi_currency_cached_currencies';
const CURRENCY_CODE_PATTERN = /^[A-Z]{3}$/;

type CurrencyCatalogOperation =
	| 'capture-catalog'
	| 'widen-catalog'
	| 'restore-catalog';

type RawOptionRow = Readonly< {
	exists: boolean;
	valueBase64: string | null;
	autoload: string | null;
} >;

export interface CurrencyCatalogRunnerRequest {
	operation: CurrencyCatalogOperation;
	input: Record< string, unknown >;
}

export interface CurrencyCatalogRunner {
	run( request: CurrencyCatalogRunnerRequest ): Promise< unknown >;
}

/**
 * Automatic rates to force into the provider rate cache, keyed by uppercase
 * ISO code. Values are only the catalog's automatic rates; every test that
 * asserts a converted amount sets its own manual rate through the merchant UI.
 */
export interface CurrencyCatalogRates {
	readonly [ code: string ]: number;
}

export interface WidenedCurrencyCatalog {
	/** The codes this scope forced into the catalog, sorted. */
	readonly codes: readonly string[];
	/** Available codes before widening, sorted. */
	readonly availableBefore: readonly string[];
	/** Available codes inside the widened scope, sorted. */
	readonly availableDuring: readonly string[];
}

export interface WidenedCurrencyCatalogOptions {
	baseURL: string | undefined;
	rates: CurrencyCatalogRates;
	runner?: CurrencyCatalogRunner;
	storeDirectory?: string;
}

function invalid( message: string ): never {
	throw new Error( message );
}

function toMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

function isPlainObject( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		( Object.getPrototypeOf( value ) === Object.prototype ||
			Object.getPrototypeOf( value ) === null )
	);
}

function exactKeys( value: object, keys: readonly string[] ): boolean {
	return (
		JSON.stringify( Object.keys( value ).toSorted() ) ===
		JSON.stringify( [ ...keys ].toSorted() )
	);
}

function assertBase64( value: string ): void {
	if (
		! /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(
			value
		) ||
		Buffer.from( value, 'base64' ).toString( 'base64' ) !== value
	) {
		invalid(
			'Currency-catalog snapshot contains invalid raw option bytes.'
		);
	}
}

function assertRawOptionRow( value: unknown ): RawOptionRow {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [ 'autoload', 'exists', 'valueBase64' ] ) ||
		typeof value.exists !== 'boolean'
	) {
		invalid( 'Currency-catalog snapshot contains an invalid option row.' );
	}
	if ( value.exists ) {
		if (
			typeof value.valueBase64 !== 'string' ||
			typeof value.autoload !== 'string'
		) {
			invalid(
				'Currency-catalog snapshot contains an incomplete option row.'
			);
		}
		assertBase64( value.valueBase64 );
	} else if ( value.valueBase64 !== null || value.autoload !== null ) {
		invalid(
			'Currency-catalog snapshot contains an ambiguous absent option row.'
		);
	}
	return {
		exists: value.exists,
		valueBase64: value.valueBase64,
		autoload: value.autoload,
	};
}

function assertCodeList( value: unknown, description: string ): string[] {
	if (
		! Array.isArray( value ) ||
		value.some(
			( code ) =>
				typeof code !== 'string' || ! CURRENCY_CODE_PATTERN.test( code )
		)
	) {
		invalid( `${ description } is not a list of currency codes.` );
	}
	return ( value as string[] ).toSorted();
}

function assertEnvelope( value: unknown, baseURL: string ): unknown {
	if (
		! isPlainObject( value ) ||
		! exactKeys( value, [ 'home', 'payload', 'site' ] ) ||
		value.home !== baseURL ||
		value.site !== baseURL
	) {
		invalid(
			'Native-store WP-CLI URL proof did not match the selected store.'
		);
	}
	return value.payload;
}

function assertPayload(
	value: unknown,
	keys: readonly string[]
): Record< string, unknown > {
	if ( ! isPlainObject( value ) || ! exactKeys( value, keys ) ) {
		invalid(
			'Native-store WP-CLI returned an invalid currency-catalog result.'
		);
	}
	return value;
}

function normalizeRates(
	rates: CurrencyCatalogRates
): Record< string, number > {
	const entries = Object.entries( rates );
	if ( entries.length === 0 ) {
		invalid( 'Widening the currency catalog requires at least one code.' );
	}
	const normalized: Record< string, number > = {};
	for ( const [ code, rate ] of entries ) {
		if ( ! CURRENCY_CODE_PATTERN.test( code ) ) {
			invalid(
				`Currency-catalog code "${ code }" is not an uppercase ISO code.`
			);
		}
		if ( ! Number.isFinite( rate ) || rate <= 0 ) {
			invalid( `Currency-catalog rate for ${ code } must be positive.` );
		}
		normalized[ code ] = rate;
	}
	return normalized;
}

function requireStoreBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		invalid( 'Widening the currency catalog requires the store base URL.' );
	}
	const normalized = baseURL.replace( /\/+$/, '' );
	let parsed: URL;
	try {
		parsed = new URL( normalized );
	} catch {
		invalid( 'Widening the currency catalog requires a valid store URL.' );
	}
	if ( parsed.port === '8082' ) {
		invalid(
			'The currency-catalog driver refuses the reference store on port 8082.'
		);
	}
	return normalized;
}

const NATIVE_STORE_PHP = String.raw`<?php
function wcpay_mc_fail() { throw new RuntimeException( 'WooPayments E2E currency-catalog operation failed.' ); }
function wcpay_mc_exact_keys( $value, $keys ) { if ( ! is_array( $value ) ) { wcpay_mc_fail(); } $actual = array_keys( $value ); sort( $actual ); sort( $keys ); if ( $actual !== $keys ) { wcpay_mc_fail(); } }
function wcpay_mc_row( $name ) { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A ); if ( 1 < count( $rows ) ) { wcpay_mc_fail(); } if ( ! $rows ) { return array( 'exists' => false, 'valueBase64' => null, 'autoload' => null ); } return array( 'exists' => true, 'valueBase64' => base64_encode( (string) $rows[0]['option_value'] ), 'autoload' => (string) $rows[0]['autoload'] ); }
function wcpay_mc_row_equal( $left, $right ) { return $left['exists'] === $right['exists'] && $left['valueBase64'] === $right['valueBase64'] && $left['autoload'] === $right['autoload']; }
function wcpay_mc_invalidate( $name ) { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
function wcpay_mc_available() { $factory = wc_get_container()->get( Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class ); $builder = $factory->create(); $builder->reset(); return array_values( array_keys( $builder->build()->get_available_currencies() ) ); }
function wcpay_mc_decode( $row ) { if ( true !== $row['exists'] ) { return null; } $raw = base64_decode( $row['valueBase64'], true ); if ( false === $raw ) { wcpay_mc_fail(); } if ( ! is_serialized( $raw, true ) ) { return null; } $value = @unserialize( $raw, array( 'allowed_classes' => false ) ); return is_array( $value ) ? $value : null; }
function wcpay_mc_write( $name, $payload, $row ) { global $wpdb; $serialized = maybe_serialize( $payload ); if ( true === $row['exists'] ) { $result = $wpdb->update( $wpdb->options, array( 'option_value' => $serialized ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) ); } else { $result = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $serialized, 'autoload' => 'off' ), array( '%s', '%s', '%s' ) ); } if ( false === $result ) { wcpay_mc_fail(); } wcpay_mc_invalidate( $name ); }
function wcpay_mc_restore( $name, $snapshot ) { global $wpdb; wcpay_mc_exact_keys( $snapshot, array( 'autoload', 'exists', 'valueBase64' ) ); $current = wcpay_mc_row( $name ); if ( true === $snapshot['exists'] ) { if ( ! is_string( $snapshot['valueBase64'] ) || ! is_string( $snapshot['autoload'] ) ) { wcpay_mc_fail(); } $raw = base64_decode( $snapshot['valueBase64'], true ); if ( false === $raw ) { wcpay_mc_fail(); } if ( $current['exists'] ) { $result = $wpdb->update( $wpdb->options, array( 'option_value' => $raw, 'autoload' => $snapshot['autoload'] ), array( 'option_name' => $name ), array( '%s', '%s' ), array( '%s' ) ); } else { $result = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => $snapshot['autoload'] ), array( '%s', '%s', '%s' ) ); } if ( false === $result ) { wcpay_mc_fail(); } } else { if ( null !== $snapshot['valueBase64'] || null !== $snapshot['autoload'] ) { wcpay_mc_fail(); } if ( $current['exists'] && false === $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ) ) { wcpay_mc_fail(); } } wcpay_mc_invalidate( $name ); }
function wcpay_mc_emit( $payload ) { echo wp_json_encode( array( 'home' => get_home_url(), 'site' => get_site_url(), 'payload' => $payload ), JSON_UNESCAPED_SLASHES ) . "\n"; }
try {
	$input_json = base64_decode( $wcpay_mc_input_base64, true ); $operation = base64_decode( $wcpay_mc_operation_base64, true );
	if ( false === $input_json || false === $operation ) { wcpay_mc_fail(); }
	$input = json_decode( $input_json, true, 32, JSON_THROW_ON_ERROR );
	if ( ! is_array( $input ) || ! isset( $input['baseURL'] ) || ! is_string( $input['baseURL'] ) || get_home_url() !== $input['baseURL'] || get_site_url() !== $input['baseURL'] ) { wcpay_mc_fail(); }
	$option_name = 'CACHE_OPTION_NAME';
	if ( 'capture-catalog' === $operation ) {
		wcpay_mc_exact_keys( $input, array( 'baseURL' ) );
		wcpay_mc_emit( array( 'row' => wcpay_mc_row( $option_name ), 'available' => wcpay_mc_available() ) );
	} elseif ( 'widen-catalog' === $operation ) {
		wcpay_mc_exact_keys( $input, array( 'baseURL', 'rates' ) );
		if ( ! is_array( $input['rates'] ) || array() === $input['rates'] ) { wcpay_mc_fail(); }
		$row = wcpay_mc_row( $option_name ); $payload = wcpay_mc_decode( $row );
		$currencies = ( is_array( $payload ) && isset( $payload['data']['currencies'] ) && is_array( $payload['data']['currencies'] ) ) ? $payload['data']['currencies'] : array();
		foreach ( $input['rates'] as $code => $rate ) { if ( ! is_string( $code ) || ! preg_match( '/^[A-Z]{3}$/D', $code ) || ! is_numeric( $rate ) || 0 >= (float) $rate ) { wcpay_mc_fail(); } $currencies[ strtolower( $code ) ] = (float) $rate; }
		$now = time();
		wcpay_mc_write( $option_name, array( 'data' => array( 'currencies' => $currencies, 'updated' => $now ), 'fetched' => $now, 'errored' => false, 'consecutive_errors' => 0 ), $row );
		wcpay_mc_emit( array( 'available' => wcpay_mc_available() ) );
	} elseif ( 'restore-catalog' === $operation ) {
		wcpay_mc_exact_keys( $input, array( 'baseURL', 'snapshot' ) );
		wcpay_mc_restore( $option_name, $input['snapshot'] );
		wcpay_mc_emit( array( 'rowsMatch' => wcpay_mc_row_equal( wcpay_mc_row( $option_name ), $input['snapshot'] ), 'available' => wcpay_mc_available() ) );
	} else { wcpay_mc_fail(); }
} catch ( Throwable $error ) { fwrite( STDERR, "WooPayments E2E currency-catalog operation failed.\n" ); exit( 1 ); }
`.replace( 'CACHE_OPTION_NAME', CURRENCIES_CACHE_OPTION );

function nativeStorePhp( request: CurrencyCatalogRunnerRequest ): string {
	const encode = ( value: string ): string =>
		Buffer.from( value, 'utf8' ).toString( 'base64' );
	const bindings = [
		`$wcpay_mc_operation_base64 = '${ encode( request.operation ) }';`,
		`$wcpay_mc_input_base64 = '${ encode(
			JSON.stringify( request.input )
		) }';`,
	].join( '\n' );

	return NATIVE_STORE_PHP.replace( '<?php', bindings );
}

type NativeStoreExecFile = (
	command: string,
	args: string[],
	options: { cwd: string }
) => Promise< string >;

export interface NativeStoreCatalogRunnerOptions {
	execFile?: NativeStoreExecFile;
	storeDirectory?: string;
}

function executeFile(
	command: string,
	args: string[],
	options: { cwd: string }
): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		execFile(
			command,
			args,
			{ cwd: options.cwd, encoding: 'utf8' },
			( error, stdout ) => {
				if ( error ) {
					reject( error );
					return;
				}
				resolve( stdout );
			}
		);
	} );
}

/**
 * WP-CLI runner for the native store the harness drives. The command shape
 * matches the harness's established raw-state driver: `wp-env run cli wp eval`
 * from the native store directory, with the payload passed as base64 bindings
 * so no shell quoting can reshape it.
 */
export class NativeStoreCatalogRunner implements CurrencyCatalogRunner {
	private readonly execFile: NativeStoreExecFile;
	private readonly storeDirectory: string;

	public constructor( options: NativeStoreCatalogRunnerOptions = {} ) {
		this.execFile = options.execFile ?? executeFile;
		this.storeDirectory =
			options.storeDirectory ??
			process.env.E2E_WOOPAYMENTS_NATIVE_STORE_DIR ??
			'';
	}

	public async run(
		request: CurrencyCatalogRunnerRequest
	): Promise< unknown > {
		if ( ! this.storeDirectory ) {
			throw new Error(
				'E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required for currency-catalog operations.'
			);
		}

		try {
			const stdout = await this.execFile(
				'pnpm',
				[
					'exec',
					'wp-env',
					'run',
					'cli',
					'wp',
					'eval',
					nativeStorePhp( request ),
				],
				{ cwd: this.storeDirectory }
			);
			const jsonLine = stdout
				.split( /\r?\n/ )
				.map( ( line ) => stripVTControlCharacters( line ).trim() )
				.toReversed()
				.find( ( line ) => line.startsWith( '{' ) );
			if ( ! jsonLine ) {
				throw new Error(
					'Native-store WP-CLI output contained no JSON result line.'
				);
			}
			return JSON.parse( jsonLine ) as unknown;
		} catch ( error ) {
			throw new Error(
				`Native-store WP-CLI ${ request.operation } operation failed.`,
				{ cause: error }
			);
		}
	}
}

async function runOperation(
	runner: CurrencyCatalogRunner,
	operation: CurrencyCatalogOperation,
	input: Record< string, unknown >,
	baseURL: string
): Promise< unknown > {
	return assertEnvelope( await runner.run( { operation, input } ), baseURL );
}

/**
 * Run `callback` with the requested currencies forced into the native
 * available-currency catalog, then byte-restore the rate cache and prove the
 * catalog is back to its pre-run contents.
 *
 * Fails loudly, before any write, when a requested code is already available:
 * on this store that means a previous run leaked a forced catalog, which must
 * surface rather than be absorbed.
 *
 * @param options  Store URL, the rates to force, and optional injection seams.
 * @param callback Scenario body, run inside the widened catalog.
 * @return The callback's result.
 */
export async function withWidenedCurrencyCatalog< Result >(
	options: WidenedCurrencyCatalogOptions,
	callback: ( catalog: WidenedCurrencyCatalog ) => Promise< Result >
): Promise< Result > {
	const baseURL = requireStoreBaseUrl( options.baseURL );
	const rates = normalizeRates( options.rates );
	const codes = Object.keys( rates ).toSorted();
	const runner =
		options.runner ??
		new NativeStoreCatalogRunner( {
			storeDirectory: options.storeDirectory,
		} );

	const captured = assertPayload(
		await runOperation( runner, 'capture-catalog', { baseURL }, baseURL ),
		[ 'available', 'row' ]
	);
	const availableBefore = assertCodeList(
		captured.available,
		'The captured available-currency catalog'
	);
	const alreadyAvailable = codes.filter( ( code ) =>
		availableBefore.includes( code )
	);
	if ( alreadyAvailable.length > 0 ) {
		invalid(
			`The store already offers ${ alreadyAvailable.join(
				', '
			) }; a previous run leaked a forced currency catalog and must be released manually.`
		);
	}
	const snapshot = assertRawOptionRow( captured.row );
	const restoreCatalog = async (
		scenarioFailed: boolean
	): Promise< void > => {
		const restored = assertPayload(
			await runOperation(
				runner,
				'restore-catalog',
				{ baseURL, snapshot },
				baseURL
			),
			[ 'available', 'rowsMatch' ]
		);
		const availableAfter = assertCodeList(
			restored.available,
			'The restored available-currency catalog'
		);
		if ( restored.rowsMatch !== true ) {
			invalid(
				'The forced currency-catalog rate cache was not restored to its captured bytes.'
			);
		}
		// A currency the scenario enabled with a manual rate re-enters the
		// catalog on its own, so a widened catalog after a *failed* scenario is
		// the package's fail-closed product-state residue, not a restoration
		// bug: the scenario's own error is the finding, and the next run's
		// precondition guard refuses to start until the residue is released.
		if (
			! scenarioFailed &&
			availableAfter.join( ',' ) !== availableBefore.join( ',' )
		) {
			invalid(
				`The forced currency catalog was not restored: the store now offers ${ availableAfter.join(
					', '
				) } against a captured ${ availableBefore.join( ', ' ) }.`
			);
		}
	};

	const widened = assertPayload(
		await runOperation(
			runner,
			'widen-catalog',
			{ baseURL, rates },
			baseURL
		),
		[ 'available' ]
	);
	const availableDuring = assertCodeList(
		widened.available,
		'The widened available-currency catalog'
	);
	const missing = codes.filter(
		( code ) => ! availableDuring.includes( code )
	);
	const dropped = availableBefore.filter(
		( code ) => ! availableDuring.includes( code )
	);
	if ( missing.length > 0 || dropped.length > 0 ) {
		invalid(
			`Forcing the currency catalog did not take effect (missing ${
				missing.join( ', ' ) || 'none'
			}; dropped ${ dropped.join( ', ' ) || 'none' }).`
		);
	}

	let result: Result | undefined;
	let scenarioError: unknown;
	try {
		result = await callback( {
			codes,
			availableBefore,
			availableDuring,
		} );
	} catch ( error ) {
		scenarioError = error;
	}

	// Restoration must never mask the scenario's own failure: a `finally` that
	// throws replaces the original error, which would hide exactly the finding
	// the run exists to produce.
	let restorationError: unknown;
	try {
		await restoreCatalog( scenarioError !== undefined );
	} catch ( error ) {
		restorationError = error;
	}

	if ( scenarioError !== undefined ) {
		if ( restorationError !== undefined ) {
			throw new Error(
				`${ toMessage(
					scenarioError
				) }\n\nThe forced currency catalog then failed to restore: ${ toMessage(
					restorationError
				) }`,
				{ cause: scenarioError }
			);
		}
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}
