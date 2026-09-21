import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import type { JsonValue, ResourceLock } from '../resource-locks';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	NativeStoreWpCliRunner,
	composeCardTestingProtectionEvidence,
	validateCardTestingProtectionEvidence,
	withCapturedCardTestingProtectionState,
	type CardTestingProtectionScope,
	type CardTestingProtectionRunner,
	type CardTestingProtectionRunnerRequest,
} from './card-testing-protection';

const BASE_URL = 'http://store8889.localhost:8889';
const RUN_ID = 'run-protection-1';
const COOKIE_NAME = 'wp_woocommerce_session_0123456789abcdef0123456789abcdef';
const CUSTOMER_ID = 't_0123456789abcdef0123456789abcd';
const COOKIE_VALUE = `${ CUSTOMER_ID }|2000000000|1999990000|signature`;
const TOKEN_DIGEST = 'a'.repeat( 64 );

/**
 * The guest session cookie as a real store puts it on the wire.
 *
 * WooCommerce writes it with `setcookie()`, so the `|` field separators arrive
 * percent-encoded and so does the `$generic$` prefix `wp_fast_hash` gives the
 * signature. This is the shape Playwright reports from an actual Classic
 * checkout; the signature body is synthetic, but its alphabet is not.
 */
const WIRE_CUSTOMER_ID = 't_012a23e1851d1e1cad4a1c0dd420b2';
const WIRE_SIGNATURE = '$generic$AbCdEf01-_gHiJkLmNoPqRsTuVwXyZ0123456789';
const WIRE_COOKIE_VALUE = `${ WIRE_CUSTOMER_ID }%7C1786694699%7C1786608299%7C%24generic%24AbCdEf01-_gHiJkLmNoPqRsTuVwXyZ0123456789`;
const DECODED_WIRE_COOKIE_VALUE = `${ WIRE_CUSTOMER_ID }|1786694699|1786608299|${ WIRE_SIGNATURE }`;

const accountRow = {
	exists: true,
	valueBase64: Buffer.from( 'serialized-account' ).toString( 'base64' ),
	autoload: 'on',
} as const;
const forceRow = {
	exists: false,
	valueBase64: null,
	autoload: null,
} as const;

type Operation = CardTestingProtectionRunnerRequest[ 'operation' ];
type ControlledOptionRow = {
	exists: boolean;
	valueBase64: string | null;
	autoload: string | null;
};

function envelope(
	payload: unknown,
	overrides: Record< string, unknown > = {}
) {
	return {
		home: BASE_URL,
		site: BASE_URL,
		payload,
		...overrides,
	};
}

function defaultResult( request: CardTestingProtectionRunnerRequest ): unknown {
	switch ( request.operation ) {
		case 'capture-state':
			return envelope( {
				accountOption: accountRow,
				forceOption: forceRow,
				accountConnected: true,
				effectiveProtection: false,
				classicPageExists: false,
			} );
		case 'mutate-state':
			return envelope( { pageId: 71 } );
		case 'verify-mutated-state':
			return envelope( {
				accountConnected: true,
				accountProtection: true,
				cacheUsable: true,
				forceProtection: true,
				pageMatches: true,
			} );
		case 'read-guest-session':
			return envelope( {
				cookieValid: true,
				sessionExists: true,
				token: { length: 16, sha256: TOKEN_DIGEST },
			} );
		case 'delete-guest-session':
			return envelope( { deleted: true } );
		case 'verify-session-absent':
			return envelope( { rawAbsent: true, cacheAbsent: true } );
		case 'restore-state':
			return envelope( { restored: true, pageAbsent: true } );
		case 'verify-restored-state':
			return envelope( {
				rowsMatch: true,
				effectiveProtection: false,
				pageAbsent: true,
				sessionAbsent: true,
			} );
	}
}

class FakeRunner implements CardTestingProtectionRunner {
	public readonly requests: CardTestingProtectionRunnerRequest[] = [];
	private readonly override?: (
		request: CardTestingProtectionRunnerRequest
	) => unknown | Promise< unknown >;

	public constructor(
		override?: (
			request: CardTestingProtectionRunnerRequest
		) => unknown | Promise< unknown >
	) {
		this.override = override;
	}

	public async run(
		request: CardTestingProtectionRunnerRequest
	): Promise< unknown > {
		this.requests.push( request );
		return ( await this.override?.( request ) ) ?? defaultResult( request );
	}
}

interface FakeLockOptions {
	recoveredValue?: JsonValue;
	restoreOwned?: boolean;
	assertCanWrite?: () => void | Promise< void >;
	assertCurrentRuntimeReady?: () => void | Promise< void >;
	baseURL?: string;
	runtime?: ProviderWriteSession[ 'runtime' ];
}

function makeSession(
	events: string[],
	options: FakeLockOptions = {}
): {
	session: ProviderWriteSession;
	journalValues: JsonValue[];
} {
	let journal = options.recoveredValue;
	const journalValues: JsonValue[] = [];
	const lock = {
		async isOwned() {
			return true;
		},
		async writeRestorationJournal( value: JsonValue ) {
			events.push( 'journal-write' );
			journal = value;
			journalValues.push( value );
		},
		async restoreFromJournalIfOwned(
			restore: ( value: JsonValue ) => Promise< void >
		) {
			if ( journal === undefined ) {
				return false;
			}
			if ( options.restoreOwned === false ) {
				return false;
			}
			events.push( 'journal-restore' );
			await restore( journal );
			journal = undefined;
			return true;
		},
	} as unknown as ResourceLock;
	const session = {
		runtime: options.runtime ?? 'native',
		runId: RUN_ID,
		baseURL: options.baseURL ?? BASE_URL,
		requireApprovedProviderFixture( capability: string ) {
			events.push( `capability:${ capability }` );
		},
		async assertCurrentRuntimeReady( runtime: ProviderWriteSession[ 'runtime' ] ) {
			events.push( `runtime-ready:${ runtime }` );
			await options.assertCurrentRuntimeReady?.();
		},
		async assertCanWrite() {
			events.push( 'assert-can-write' );
			await options.assertCanWrite?.();
		},
		async withProviderWriteLocks(
			lockOptions: unknown,
			callback: () => Promise< unknown >
		) {
			events.push( `locks:${ JSON.stringify( lockOptions ) }` );
			return callback();
		},
		getActiveFeatureSettingLock() {
			return lock;
		},
	} as unknown as ProviderWriteSession;
	return { session, journalValues };
}

function fakeContext( ...cookieSnapshots: string[][] ) {
	const events: string[] = [];
	let cookieRead = 0;
	const snapshots = cookieSnapshots.length
		? cookieSnapshots
		: [ [ COOKIE_VALUE ] ];
	const context = {
		async cookies() {
			const cookieValues =
				snapshots[ Math.min( cookieRead, snapshots.length - 1 ) ];
			cookieRead += 1;
			return cookieValues.map( ( value, index ) => ( {
				name: index === 0 ? COOKIE_NAME : `${ COOKIE_NAME }_${ index }`,
				value,
				domain: 'store8889.localhost',
				path: '/',
			} ) );
		},
		async clearCookies( filter: unknown ) {
			events.push( `clear:${ JSON.stringify( filter ) }` );
		},
		async close() {
			events.push( 'close' );
		},
	};
	return { context, events };
}

function failingInspectionContext() {
	const events: string[] = [];
	let cookieRead = 0;
	const context = {
		async cookies() {
			cookieRead += 1;
			if ( cookieRead === 1 ) {
				return [];
			}
			throw new Error( 'private cookie inspection detail' );
		},
		async clearCookies() {
			events.push( 'clear' );
		},
		async close() {
			events.push( 'close' );
		},
	};
	return { context, events };
}

async function captureFreshGuestSession(
	scope: CardTestingProtectionScope,
	context = fakeContext( [], [ COOKIE_VALUE ] ).context
) {
	await scope.registerFreshContext( context as never );
	return scope.captureGuestSessionToken( context as never );
}

function operationNames( runner: FakeRunner ): Operation[] {
	return runner.requests.map( ( request ) => request.operation );
}

function replaceOperationResult(
	operation: Operation,
	result: unknown
): ( request: CardTestingProtectionRunnerRequest ) => unknown {
	return ( request ) =>
		request.operation === operation ? result : defaultResult( request );
}

function rejectOperation(
	operation: Operation,
	error: Error
): ( request: CardTestingProtectionRunnerRequest ) => unknown {
	return ( request ) => {
		if ( request.operation === operation ) {
			throw error;
		}
		return defaultResult( request );
	};
}

function afterOperation(
	operation: Operation,
	effect: () => void
): ( request: CardTestingProtectionRunnerRequest ) => unknown {
	return ( request ) => {
		const result = defaultResult( request );
		if ( request.operation === operation ) {
			effect();
		}
		return result;
	};
}

function runnerRequest(): CardTestingProtectionRunnerRequest {
	return {
		operation: 'capture-state',
		input: {
			baseURL: BASE_URL,
			marker: 'woopayments-e2e-deadbeefdeadbeef',
			slug: 'classic-checkout',
		},
	};
}

interface NativeOptionRuntimeState {
	options: Record< string, { valueBase64: string; autoload: string } >;
	posts: Array< Record< string, unknown > >;
	nextPostId: number;
}

function initialNativeOptionRuntimeState(): NativeOptionRuntimeState {
	const accountValue = execFileSync(
		'php',
		[
			'-r',
			"echo serialize( array( 'data' => array( 'account_id' => 'acct_native_test', 'card_testing_protection_eligible' => true ), 'fetched' => 41, 'errored' => false, 'consecutive_errors' => 2 ) );",
		],
		{ encoding: 'utf8' }
	);
	return {
		options: {
			wcpay_account_data: {
				valueBase64: Buffer.from( accountValue ).toString( 'base64' ),
				autoload: 'yes',
			},
			wcpaydev_force_card_testing_protection_on: {
				valueBase64: Buffer.from( 'force-original-bytes' ).toString(
					'base64'
				),
				autoload: 'no',
			},
		},
		posts: [],
		nextPostId: 1,
	};
}

function readNativeOptionRuntimeState( path: string ): NativeOptionRuntimeState {
	return JSON.parse( readFileSync( path, 'utf8' ) ) as NativeOptionRuntimeState;
}

function nativeOptionRuntimePhp( statePath: string ): string {
	return String.raw`
const WCPAY_E2E_TEST_STATE = ${ JSON.stringify( statePath ) };
define( 'ARRAY_A', 'ARRAY_A' );
function wcpay_e2e_test_read_state() { return json_decode( file_get_contents( WCPAY_E2E_TEST_STATE ), true, 32, JSON_THROW_ON_ERROR ); }
function wcpay_e2e_test_write_state() { file_put_contents( WCPAY_E2E_TEST_STATE, json_encode( $GLOBALS['wcpay_e2e_test_state'], JSON_UNESCAPED_SLASHES ) ); }
$GLOBALS['wcpay_e2e_test_state'] = wcpay_e2e_test_read_state();
class WcpayE2eTestWpdb {
	public $options = 'options';
	public $posts = 'posts';
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) { return base64_encode( serialize( array( $query, $args ) ) ); }
	public function get_results( $prepared ) {
		$prepared = unserialize( base64_decode( $prepared ), array( 'allowed_classes' => false ) );
		$query = $prepared[0];
		$args = $prepared[1];
		$state = $GLOBALS['wcpay_e2e_test_state'];
		if ( false !== strpos( $query, 'option_value' ) ) {
			$name = $args[1];
			if ( ! isset( $state['options'][$name] ) ) { return array(); }
			$row = $state['options'][$name];
			return array( array( 'option_value' => base64_decode( $row['valueBase64'] ), 'autoload' => $row['autoload'] ) );
		}
		$rows = array();
		foreach ( $state['posts'] as $row ) {
			if ( $row['post_type'] === $args[1] && $row['post_name'] === $args[2] ) { $rows[] = $row; }
		}
		return $rows;
	}
	public function update( $table, $data, $where ) {
		if ( $table !== $this->options || ! isset( $where['option_name'] ) || ! isset( $GLOBALS['wcpay_e2e_test_state']['options'][$where['option_name']] ) ) { return false; }
		$name = $where['option_name'];
		foreach ( $data as $key => $value ) {
			$GLOBALS['wcpay_e2e_test_state']['options'][$name][$key === 'option_value' ? 'valueBase64' : $key] = $key === 'option_value' ? base64_encode( $value ) : $value;
		}
		wcpay_e2e_test_write_state();
		return 1;
	}
	public function insert( $table, $data ) {
		if ( $table !== $this->options || ! isset( $data['option_name'], $data['option_value'], $data['autoload'] ) ) { return false; }
		$GLOBALS['wcpay_e2e_test_state']['options'][$data['option_name']] = array( 'valueBase64' => base64_encode( $data['option_value'] ), 'autoload' => $data['autoload'] );
		wcpay_e2e_test_write_state();
		return 1;
	}
	public function delete( $table, $where ) {
		if ( $table !== $this->options || ! isset( $where['option_name'] ) ) { return false; }
		unset( $GLOBALS['wcpay_e2e_test_state']['options'][$where['option_name']] );
		wcpay_e2e_test_write_state();
		return 1;
	}
	public function get_var() { return 0; }
	public function esc_like( $value ) { return $value; }
}
$wpdb = new WcpayE2eTestWpdb();
function get_home_url() { return '${ BASE_URL }'; }
function get_site_url() { return '${ BASE_URL }'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_cache_delete() { return true; }
function is_serialized( $value ) { return 'b:0;' === $value || false !== @unserialize( $value, array( 'allowed_classes' => false ) ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function is_wp_error() { return false; }
function wp_insert_post( $post ) {
	$id = $GLOBALS['wcpay_e2e_test_state']['nextPostId']++;
	$GLOBALS['wcpay_e2e_test_state']['posts'][] = array( 'ID' => $id, 'post_name' => $post['post_name'], 'post_title' => $post['post_title'], 'post_content' => $post['post_content'], 'post_status' => $post['post_status'], 'post_type' => $post['post_type'] );
	wcpay_e2e_test_write_state();
	return $id;
}
function get_post( $id ) { foreach ( $GLOBALS['wcpay_e2e_test_state']['posts'] as $post ) { if ( $post['ID'] === $id ) { return $post; } } return null; }
function wp_delete_post( $id ) {
	$GLOBALS['wcpay_e2e_test_state']['posts'] = array_values( array_filter( $GLOBALS['wcpay_e2e_test_state']['posts'], function ( $post ) use ( $id ) { return $post['ID'] !== $id; } ) );
	wcpay_e2e_test_write_state();
	return array( 'ID' => $id );
}
`;
}

function nativeOptionRuntimeRunner( statePath: string ): NativeStoreWpCliRunner {
	return new NativeStoreWpCliRunner( {
		storeDirectory: '/controlled/native-store',
		execFile: async ( _command, args ) =>
			execFileSync(
				'php',
				[ '-r', `${ nativeOptionRuntimePhp( statePath ) }\n${ args.at( -1 ) }` ],
				{ encoding: 'utf8' }
			),
	} );
}

function accountProtectionFromRawRow( row: ControlledOptionRow ): boolean {
	const result = execFileSync(
		'php',
		[
			'-r',
			"echo json_encode( unserialize( base64_decode( $argv[1] ), array( 'allowed_classes' => false ) )['data']['card_testing_protection_eligible'] );",
			row.valueBase64 ?? '',
		],
		{ encoding: 'utf8' }
	);
	return result.trim() === 'true';
}

test( 'runs canonical PHP with one standard native-store WP-CLI invocation', async () => {
	const execFileCalls: unknown[][] = [];
	const runner = new NativeStoreWpCliRunner( {
		storeDirectory: '/test/native-store',
		execFile: async ( ...args: unknown[] ) => {
			execFileCalls.push( args );
			return `Starting wp-env\n${ JSON.stringify(
				envelope( { captured: true } )
			) }\n`;
		},
	} );

	await expect( runner.run( runnerRequest() ) ).resolves.toEqual(
		envelope( { captured: true } )
	);
	expect( execFileCalls ).toHaveLength( 1 );
	const [ command, args, options ] = execFileCalls[ 0 ] as [
		string,
		string[],
		Record< string, unknown >,
	];
	expect( command ).toBe( 'pnpm' );
	expect( options ).toEqual( { cwd: '/test/native-store' } );
	expect( args.slice( 0, -1 ) ).toEqual( [
		'exec',
		'wp-env',
		'run',
		'cli',
		'wp',
		'--user=1',
		'eval',
	] );
	const phpSource = args.at( -1 );
	expect( phpSource ).not.toContain( '<?php' );
	expect( phpSource ).toContain( "array( 'option_value' => '1' )" );
	expect( phpSource ).toContain( "'1' === $force_raw" );
	expect( phpSource ).not.toContain( 'maybe_serialize( true )' );
	expect( phpSource ).toContain(
		"true === $wrapper['data']['card_testing_protection_eligible']"
	);
	expect( phpSource ).toContain(
		"array_key_exists( 'card_testing_protection_eligible', $wrapper['data'] ) && ! is_bool( $wrapper['data']['card_testing_protection_eligible'] )"
	);
	expect( phpSource ).toContain(
		"'effectiveProtection' => (bool) ( $wrapper['data']['card_testing_protection_eligible'] ?? false )"
	);
	expect( phpSource ).toContain(
		`$wcpay_e2e_operation_base64 = '${ Buffer.from(
			'capture-state'
		).toString( 'base64' ) }';`
	);
	expect( phpSource ).toContain(
		Buffer.from( JSON.stringify( runnerRequest().input ) ).toString(
			'base64'
		)
	);
} );

test( 'keeps the underlying cause when a WP-CLI operation fails', async () => {
	const underlying = new Error( 'wp-env exited with code 1' );
	const runner = new NativeStoreWpCliRunner( {
		storeDirectory: '/test/native-store',
		execFile: async () => {
			throw underlying;
		},
	} );

	await expect( runner.run( runnerRequest() ) ).rejects.toMatchObject( {
		message: /capture-state operation failed/,
		cause: underlying,
	} );
} );

test( 'explains an absent JSON result line instead of throwing an empty error', async () => {
	const runner = new NativeStoreWpCliRunner( {
		storeDirectory: '/test/native-store',
		execFile: async () => 'Starting wp-env\nPHP Fatal error: whoops\n',
	} );

	await expect( runner.run( runnerRequest() ) ).rejects.toMatchObject( {
		cause: { message: /no JSON result line/i },
	} );
} );

test( 'returns a fixed value-free error for native-store command and output failures', async () => {
	const sensitive = 'private native-store command detail';
	const failingRunner = new NativeStoreWpCliRunner( {
		storeDirectory: '/test/native-store',
		execFile: async () => {
			throw new Error( sensitive );
		},
	} );
	const malformedRunner = new NativeStoreWpCliRunner( {
		storeDirectory: '/test/native-store',
		execFile: async () => sensitive,
	} );

	for ( const runner of [ failingRunner, malformedRunner ] ) {
		let rejection: Error | undefined;
		try {
			await runner.run( runnerRequest() );
		} catch ( error ) {
			rejection = error as Error;
		}
		expect( rejection?.message ).toBe(
			'Native-store WP-CLI capture-state operation failed.'
		);
		expect( rejection?.message ).not.toContain( sensitive );
	}
} );

test( 'journals exact raw rows before mutation and proves state in a fresh operation', async () => {
	const events: string[] = [];
	const { session, journalValues } = makeSession( events );
	const runner = new FakeRunner( ( request ) => {
		events.push( request.operation );
		return defaultResult( request );
	} );

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		async ( scope ) => {
			expect( scope.classicCheckout ).toMatchObject( {
				pageId: 71,
				slug: 'classic-checkout',
				path: 'classic-checkout/',
			} );
			await captureFreshGuestSession( scope );
		},
		{ runner }
	);

	expect( events ).toContain(
		'locks:{"featureSetting":"card-testing-protection","recordEvent":"shopper-card-protection"}'
	);
	expect( events ).toContain( 'capability:card-testing-protection-setting' );
	expect( events ).toContain( 'capability:classic-checkout-page' );
	expect( events.indexOf( 'journal-write' ) ).toBeLessThan(
		events.indexOf( 'mutate-state' )
	);
	expect( journalValues ).toHaveLength( 1 );
	expect( journalValues[ 0 ] ).toMatchObject( {
		accountOption: accountRow,
		forceOption: forceRow,
		classicCheckoutSlug: 'classic-checkout',
		originalEffectiveProtection: false,
	} );
	expect( operationNames( runner ) ).toEqual( [
		'capture-state',
		'mutate-state',
		'verify-mutated-state',
		'read-guest-session',
		'delete-guest-session',
		'verify-session-absent',
		'restore-state',
		'verify-restored-state',
	] );
	const mutation = runner.requests.find(
		( request ) => request.operation === 'mutate-state'
	);
	expect( mutation ).toMatchObject( {
		input: {
			baseURL: BASE_URL,
			slug: 'classic-checkout',
		},
	} );
	expect( mutation?.input ).toHaveProperty( 'marker' );
	const mutationMarker = mutation?.input.marker;
	expect( mutationMarker ).toMatch( /^woopayments-e2e-[a-f0-9]{16}$/ );
	expect( mutation?.input.title ).toBe(
		`WooPayments E2E Classic Checkout ${ mutationMarker }`
	);
	expect( mutation?.input.content ).toBe(
		`<!-- ${ mutationMarker } -->\n[woocommerce_checkout]`
	);
	const proof = runner.requests.find(
		( request ) => request.operation === 'verify-mutated-state'
	);
	expect( proof?.input ).toMatchObject( {
		pageId: 71,
		slug: 'classic-checkout',
	} );
} );

test( 'rejects the standing port-8082 store before locks, journal, or runner work', async () => {
	const events: string[] = [];
	const { session, journalValues } = makeSession( events, {
		baseURL: 'http://store.localhost:8082',
	} );
	const runner = new FakeRunner();

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async () => {},
			{ runner }
		)
	).rejects.toThrow( 'port 8082' );
	expect( events ).toEqual( [] );
	expect( journalValues ).toEqual( [] );
	expect( runner.requests ).toEqual( [] );
} );

test( 'allows transition card protection only after native ownership is rechecked', async () => {
	const transitionEvents: string[] = [];
	const { session: transitionSession } = makeSession( transitionEvents, {
		runtime: 'transition',
	} );
	const transitionRunner = new FakeRunner();

	await withCapturedCardTestingProtectionState(
		transitionSession,
		RUN_ID,
		async ( scope ) => captureFreshGuestSession( scope ),
		{ runner: transitionRunner }
	);

	expect( transitionEvents[ 0 ] ).toBe( 'runtime-ready:native' );
	expect(
		transitionEvents.filter( ( event ) => event.startsWith( 'locks:' ) )
	).toHaveLength( 1 );

	const clientEvents: string[] = [];
	const { session: clientSession } = makeSession( clientEvents, {
		runtime: 'client',
	} );
	const clientRunner = new FakeRunner();
	await expect(
		withCapturedCardTestingProtectionState(
			clientSession,
			RUN_ID,
			async () => {},
			{ runner: clientRunner }
		)
	).rejects.toThrow( 'requires the native runtime' );
	expect( clientEvents ).toEqual( [] );
	expect( clientRunner.requests ).toEqual( [] );

	const pluginOwnedEvents: string[] = [];
	const { session: pluginOwnedTransition } = makeSession(
		pluginOwnedEvents,
		{
			runtime: 'transition',
			assertCurrentRuntimeReady: () => {
				throw new Error( 'post-cutover native ownership is not proved' );
			},
		}
	);
	const pluginOwnedRunner = new FakeRunner();
	await expect(
		withCapturedCardTestingProtectionState(
			pluginOwnedTransition,
			RUN_ID,
			async () => {},
			{ runner: pluginOwnedRunner }
		)
	).rejects.toThrow( 'post-cutover native ownership is not proved' );
	expect( pluginOwnedEvents ).toEqual( [ 'runtime-ready:native' ] );
	expect( pluginOwnedRunner.requests ).toEqual( [] );
} );

test( 'requires a zero-cookie baseline and exact context identity before session capture', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const reused = fakeContext( [ COOKIE_VALUE ] );

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( reused.context as never );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( reused.events ).toEqual( [ 'close' ] );
	expect( operationNames( runner ) ).not.toContain( 'read-guest-session' );
	expect( operationNames( runner ) ).not.toContain( 'delete-guest-session' );

	const secondRunner = new FakeRunner();
	const { session: secondSession } = makeSession( [] );
	const registered = fakeContext( [], [ COOKIE_VALUE ] );
	const different = fakeContext( [], [ COOKIE_VALUE ] );
	await expect(
		withCapturedCardTestingProtectionState(
			secondSession,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( registered.context as never );
				await expect(
					scope.captureGuestSessionToken( different.context as never )
				).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
				await expect(
					scope.captureGuestSessionToken(
						registered.context as never
					)
				).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
			},
			{ runner: secondRunner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( registered.events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
	expect( different.events ).toEqual( [] );
	expect( operationNames( secondRunner ) ).toEqual( [
		'capture-state',
		'mutate-state',
		'verify-mutated-state',
		'read-guest-session',
		'delete-guest-session',
		'verify-session-absent',
		'restore-state',
		'verify-restored-state',
	] );

	const thirdRunner = new FakeRunner();
	const { session: thirdSession } = makeSession( [] );
	const unregistered = fakeContext( [ COOKIE_VALUE ] );
	await expect(
		withCapturedCardTestingProtectionState(
			thirdSession,
			RUN_ID,
			async ( scope ) => {
				await scope.captureGuestSessionToken(
					unregistered.context as never
				);
			},
			{ runner: thirdRunner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( unregistered.events ).toEqual( [] );
	expect( operationNames( thirdRunner ) ).not.toContain(
		'read-guest-session'
	);
} );

test( 'rejects a second fresh-context registration and closes the registered context', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const fresh = fakeContext( [] );

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( fresh.context as never );
				await scope.registerFreshContext( fresh.context as never );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( fresh.events ).toEqual( [ 'close' ] );
} );

test( 'cannot swallow a duplicate registration or capture after valid evidence', async () => {
	for ( const duplicate of [ 'registration', 'capture' ] as const ) {
		const { session } = makeSession( [] );
		const runner = new FakeRunner();
		const fresh = fakeContext( [], [ COOKIE_VALUE ] );

		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( fresh.context as never );
					await scope.captureGuestSessionToken(
						fresh.context as never
					);
					await expect(
						duplicate === 'registration'
							? scope.registerFreshContext(
									fresh.context as never
							  )
							: scope.captureGuestSessionToken(
									fresh.context as never
							  )
					).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
				},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	}
} );

test( 'rejects successful callback completion without registered and captured session evidence', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async () => {},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).not.toContain( 'read-guest-session' );
} );

test( 'deletes an exact session created after registration when the callback fails before capture', async () => {
	const primary = new Error( 'scenario failed before token capture' );
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				throw primary;
			},
			{ runner }
		)
	).rejects.toBe( primary );
	expect( operationNames( runner ) ).toEqual( [
		'capture-state',
		'mutate-state',
		'verify-mutated-state',
		'read-guest-session',
		'delete-guest-session',
		'verify-session-absent',
		'restore-state',
		'verify-restored-state',
	] );
	expect( events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
} );

test( 'quarantines failed callbacks when teardown cannot identify one valid guest session', async () => {
	for ( const cookieValues of [
		[],
		[ COOKIE_VALUE, COOKIE_VALUE ],
		[ 'malformed' ],
	] ) {
		const primary = new Error( 'scenario failed before token capture' );
		const { session } = makeSession( [] );
		const runner = new FakeRunner();
		const { context, events } = fakeContext( [], cookieValues );

		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( context as never );
					throw primary;
				},
				{ runner }
			)
		).rejects.toMatchObject( {
			name: 'ResourceQuarantineRequiredError',
			reasonCode: 'cleanup-failed',
			primaryError: primary,
		} );
		expect( events ).toContain( 'close' );
		expect( operationNames( runner ) ).not.toContain(
			'delete-guest-session'
		);
	}
} );

test( 'quarantines when post-failure cookie inspection fails', async () => {
	const primary = new Error( 'scenario failed before token capture' );
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context, events } = failingInspectionContext();
	let caught: unknown;

	try {
		await withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				throw primary;
			},
			{ runner }
		);
	} catch ( error ) {
		caught = error;
	}
	expect( caught ).toMatchObject( {
		name: 'ResourceQuarantineRequiredError',
		reasonCode: 'cleanup-failed',
		primaryError: primary,
	} );
	expect( String( caught ) ).not.toContain(
		'private cookie inspection detail'
	);
	expect( events ).toEqual( [ 'close' ] );
	expect( operationNames( runner ) ).not.toContain( 'read-guest-session' );
} );

test( 'quarantines when Core cannot verify the post-failure session identity', async () => {
	const primary = new Error( 'scenario failed before token capture' );
	const { session } = makeSession( [] );
	const runner = new FakeRunner(
		replaceOperationResult(
			'read-guest-session',
			envelope( {
				cookieValid: false,
				sessionExists: false,
				token: null,
			} )
		)
	);
	const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				throw primary;
			},
			{ runner }
		)
	).rejects.toMatchObject( {
		name: 'ResourceQuarantineRequiredError',
		reasonCode: 'cleanup-failed',
		primaryError: primary,
	} );
	expect( operationNames( runner ) ).toContain( 'read-guest-session' );
	expect( operationNames( runner ) ).not.toContain( 'delete-guest-session' );
	expect( events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
} );

test( 'quarantines when an identified post-failure session cleanup step fails', async () => {
	for ( const runnerOverride of [
		rejectOperation(
			'delete-guest-session',
			new Error( 'private deletion detail' )
		),
		replaceOperationResult(
			'verify-session-absent',
			envelope( { rawAbsent: false, cacheAbsent: true } )
		),
	] ) {
		const primary = new Error( 'scenario failed before token capture' );
		const { session } = makeSession( [] );
		const runner = new FakeRunner( runnerOverride );
		const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );

		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( context as never );
					throw primary;
				},
				{ runner }
			)
		).rejects.toMatchObject( {
			name: 'ResourceQuarantineRequiredError',
			reasonCode: 'cleanup-failed',
			primaryError: primary,
		} );
		expect( events ).toContain( 'close' );
	}
} );

test( 'rejects wrong store URL and malformed exact capture schemas as quarantine-required', async () => {
	for ( const captureResult of [
		envelope( defaultResult as unknown, {
			home: 'http://wrong.localhost',
		} ),
		envelope( {
			accountOption: { exists: true, valueBase64: null, autoload: 'on' },
			forceOption: forceRow,
			accountConnected: true,
			effectiveProtection: false,
			classicPageExists: false,
		} ),
		envelope( {
			accountOption: accountRow,
			forceOption: forceRow,
			accountConnected: true,
			effectiveProtection: 'false',
			classicPageExists: false,
		} ),
	] ) {
		const { session } = makeSession( [] );
		const runner = new FakeRunner(
			replaceOperationResult( 'capture-state', captureResult )
		);
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async () => {},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
		expect( operationNames( runner ) ).toEqual( [ 'capture-state' ] );
	}
} );

test( 'rejects a pre-existing Classic checkout slug before journalling or mutation', async () => {
	const events: string[] = [];
	const { session, journalValues } = makeSession( events );
	const runner = new FakeRunner(
		replaceOperationResult(
			'capture-state',
			envelope( {
				accountOption: accountRow,
				forceOption: forceRow,
				accountConnected: true,
				effectiveProtection: false,
				classicPageExists: true,
			} )
		)
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async () => {},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( journalValues ).toHaveLength( 0 );
	expect( operationNames( runner ) ).toEqual( [ 'capture-state' ] );
} );

test( 'recovers a structured stale journal before a new capture', async () => {
	const marker = 'woopayments-e2e-deadbeefdeadbeef';
	const recoveredValue = {
		accountOption: accountRow,
		forceOption: forceRow,
		classicCheckoutSlug: 'classic-checkout',
		runMarker: marker,
		originalEffectiveProtection: false,
	};
	const events: string[] = [];
	const { session } = makeSession( events, { recoveredValue } );
	const runner = new FakeRunner();

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		captureFreshGuestSession,
		{ runner }
	);

	expect( operationNames( runner ).slice( 0, 3 ) ).toEqual( [
		'restore-state',
		'verify-restored-state',
		'capture-state',
	] );
	expect( runner.requests[ 0 ].input ).toMatchObject( {
		snapshot: recoveredValue,
		pageId: null,
	} );
} );

test( 'rejects malformed stale journal values without capturing new state', async () => {
	const { session } = makeSession( [], {
		recoveredValue: { accountOption: accountRow },
	} );
	const runner = new FakeRunner();

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async () => {},
			{ runner }
		)
	).rejects.toMatchObject( {
		name: 'ResourceQuarantineRequiredError',
		reasonCode: 'restoration-failed',
	} );
	expect( runner.requests ).toHaveLength( 0 );
} );

test( 'rejects non-Boolean cold state and allocation ambiguity', async () => {
	for ( const payload of [
		{
			accountConnected: true,
			accountProtection: 1,
			cacheUsable: true,
			forceProtection: true,
			pageMatches: true,
		},
		{
			accountConnected: true,
			accountProtection: true,
			cacheUsable: true,
			forceProtection: true,
			pageMatches: false,
		},
		{
			accountConnected: true,
			accountProtection: true,
			cacheUsable: false,
			forceProtection: true,
			pageMatches: true,
		},
	] ) {
		const { session } = makeSession( [] );
		const runner = new FakeRunner(
			replaceOperationResult(
				'verify-mutated-state',
				envelope( payload )
			)
		);
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async () => {},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	}
} );

test( 'does not dispatch mutation after write authorization is lost following capture', async () => {
	let writeAuthorized = true;
	const { session } = makeSession( [], {
		assertCanWrite: () => {
			if ( ! writeAuthorized ) {
				throw new Error( 'write authorization lost' );
			}
		},
	} );
	const runner = new FakeRunner(
		afterOperation( 'capture-state', () => {
			writeAuthorized = false;
		} )
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async () => {},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).toEqual( [ 'capture-state' ] );
} );

test( 'restores raw bytes, autoload, existence, exact page ownership, and cold state', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		captureFreshGuestSession,
		{ runner }
	);

	const restore = runner.requests.find(
		( request ) => request.operation === 'restore-state'
	);
	expect( restore?.input ).toMatchObject( {
		baseURL: BASE_URL,
		pageId: 71,
		snapshot: {
			accountOption: accountRow,
			forceOption: forceRow,
			originalEffectiveProtection: false,
		},
	} );
} );

test( 'quarantines page ownership mismatch and cold restoration mismatch', async () => {
	for ( const runnerOverride of [
		rejectOperation(
			'restore-state',
			new Error( 'page ownership mismatch' )
		),
		replaceOperationResult(
			'verify-restored-state',
			envelope( {
				rowsMatch: false,
				effectiveProtection: false,
				pageAbsent: true,
				sessionAbsent: true,
			} )
		),
	] ) {
		const { session } = makeSession( [] );
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async () => {},
				{ runner: new FakeRunner( runnerOverride ) }
			)
		).rejects.toMatchObject( {
			name: 'ResourceQuarantineRequiredError',
			reasonCode: 'restoration-failed',
		} );
	}
} );

test( 'exposes only the public session token digest and keeps capture one-shot', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context } = fakeContext( [], [ COOKIE_VALUE ] );

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		async ( scope ) => {
			await scope.registerFreshContext( context as never );
			const digest = await scope.captureGuestSessionToken(
				context as never
			);
			expect( digest ).toEqual( { length: 16, sha256: TOKEN_DIGEST } );
			expect( JSON.stringify( digest ) ).not.toContain( COOKIE_VALUE );
		},
		{ runner }
	);
	const read = runner.requests.find(
		( request ) => request.operation === 'read-guest-session'
	);
	expect( read?.input ).toMatchObject( {
		cookieName: COOKIE_NAME,
		cookieValue: COOKIE_VALUE,
		customerId: CUSTOMER_ID,
	} );
} );

test( 'captures the requested card-testing protection state and restores raw options exactly', async () => {
	const existingForceRow = {
		exists: true,
		valueBase64: Buffer.from( 'force-original-bytes' ).toString( 'base64' ),
		autoload: 'no',
	} as const;

	for ( const targetProtection of [ false, true ] as const ) {
		const { session } = makeSession( [] );
		const { context } = fakeContext( [], [ COOKIE_VALUE ] );
		const runner = new FakeRunner( ( request ) => {
			if ( request.operation === 'capture-state' ) {
				return envelope( {
					accountOption: accountRow,
					forceOption: existingForceRow,
					accountConnected: true,
					effectiveProtection: false,
					classicPageExists: false,
				} );
			}
			if ( request.operation === 'verify-mutated-state' ) {
				return envelope( {
					accountConnected: true,
					accountProtection: true,
					cacheUsable: true,
					forceProtection: true,
					pageMatches: true,
				} );
			}
			if (
				request.operation === 'read-guest-session' &&
				targetProtection === false
			) {
				return envelope( {
					cookieValid: true,
					sessionExists: true,
					token: null,
				} );
			}
			return defaultResult( request );
		} );

		let evidence: unknown;
		await withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				evidence = await scope.captureGuestSessionProtection(
					context as never
				);
			},
			{ runner, targetProtection }
		);

		expect( evidence ).toEqual(
			targetProtection
				? {
						eligible: true,
						token: { length: 16, sha256: TOKEN_DIGEST },
						accountEnabled: true,
				  }
				: {
						eligible: false,
						token: null,
						accountEnabled: false,
				  }
		);
		expect(
			runner.requests.find(
				( request ) => request.operation === 'mutate-state'
			)?.input.targetProtection
		).toBe( targetProtection );
		expect(
			runner.requests.find(
				( request ) => request.operation === 'restore-state'
			)?.input.snapshot
		).toMatchObject( {
			accountOption: accountRow,
			forceOption: existingForceRow,
		} );
	}
} );

test( 'defaults card-testing protection capture to enabled token evidence', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context } = fakeContext( [], [ COOKIE_VALUE ] );
	let evidence: unknown;

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		async ( scope ) => {
			await scope.registerFreshContext( context as never );
			evidence = await scope.captureGuestSessionProtection( context as never );
		},
		{ runner }
	);

	expect( evidence ).toEqual( {
		eligible: true,
		token: { length: 16, sha256: TOKEN_DIGEST },
		accountEnabled: true,
	} );
} );

test( 'accepts only complete public card-testing protection evidence branches', () => {
	expect(
		validateCardTestingProtectionEvidence( {
			eligible: false,
			token: null,
			accountEnabled: false,
			renderedField: 'absent',
			submittedTokenSha256: null,
		} )
	).toEqual( {
		eligible: false,
		token: null,
		accountEnabled: false,
		renderedField: 'absent',
		submittedTokenSha256: null,
	} );
	expect( () =>
		validateCardTestingProtectionEvidence( {
			eligible: true,
			token: { length: 16, sha256: TOKEN_DIGEST },
			accountEnabled: true,
			renderedTokenSha256: TOKEN_DIGEST,
			submittedTokenSha256: 'b'.repeat( 64 ),
		} )
	).toThrow( 'digest equality' );
	expect( () =>
		validateCardTestingProtectionEvidence( {
			eligible: false,
			token: null,
			accountEnabled: false,
			renderedField: 'empty',
			submittedTokenSha256: null,
			rawToken: 'private-token',
		} )
	).toThrow( 'exact public fields' );
} );

for ( const [ name, rendered, submitted ] of [
	[ 'rendered digest mismatch', { tokenSha256: 'b'.repeat( 64 ) }, { tokenSha256: TOKEN_DIGEST } ],
	[ 'submitted digest mismatch', { tokenSha256: TOKEN_DIGEST }, { tokenSha256: 'b'.repeat( 64 ) } ],
	[ 'disabled rendered presence', { tokenSha256: TOKEN_DIGEST }, { field: 'absent' } ],
	[ 'disabled submitted presence', { field: 'empty' }, { tokenSha256: TOKEN_DIGEST } ],
] as const ) {
	test( `rejects ${ name }`, () => {
		const disabled = { eligible: false, token: null, accountEnabled: false } as const;
		const enabled = { eligible: true, token: { length: 16, sha256: TOKEN_DIGEST }, accountEnabled: true } as const;
		expect( () => composeCardTestingProtectionEvidence(
			name.startsWith( 'disabled' ) ? disabled : enabled,
			rendered,
			submitted
		) ).toThrow();
	} );
}

test( 'rejects malformed session and wire observations instead of normalizing them', () => {
	const disabled = { eligible: false, token: null, accountEnabled: false } as const;
	const enabled = { eligible: true, token: { length: 16, sha256: TOKEN_DIGEST }, accountEnabled: true } as const;
	for ( const [ session, rendered, submitted ] of [
		[ { eligible: false, token: null, accountEnabled: true }, { field: 'absent' }, { field: 'empty' } ],
		[ { eligible: true, token: { length: 16, sha256: TOKEN_DIGEST }, accountEnabled: true, rawToken: 'secret' }, { tokenSha256: TOKEN_DIGEST }, { tokenSha256: TOKEN_DIGEST } ],
		[ disabled, { field: 'invalid', raw: true }, { field: 'empty' } ],
		[ disabled, { field: 'absent' }, { field: 'invalid', raw: true } ],
		[ enabled, { tokenSha256: TOKEN_DIGEST, raw: true }, { tokenSha256: TOKEN_DIGEST } ],
	] ) {
		expect( () => composeCardTestingProtectionEvidence(
			session as never,
			rendered as never,
			submitted as never
		) ).toThrow();
	}
	expect( composeCardTestingProtectionEvidence( disabled, { field: 'empty' }, { field: 'absent' } ) ).toEqual( {
		eligible: false,
		token: null,
		accountEnabled: false,
		renderedField: 'empty',
		submittedTokenSha256: null,
	} );
} );

test( 'executes native PHP mutations and restores exact controlled option rows for each target', async () => {
	for ( const targetProtection of [ false, true ] as const ) {
		const workspace = mkdtempSync( join( tmpdir(), 'ctp-native-options-' ) );
		const statePath = join( workspace, 'state.json' );
		const initialState = initialNativeOptionRuntimeState();
		writeFileSync( statePath, JSON.stringify( initialState ) );
		const runner = nativeOptionRuntimeRunner( statePath );
		const marker = 'woopayments-e2e-deadbeefdeadbeef';
		const title = 'WooPayments E2E Classic Checkout ' + marker;
		const content = '<!-- ' + marker + ' -->\n[woocommerce_checkout]';

		try {
			const captured = ( await runner.run( {
				operation: 'capture-state',
				input: { baseURL: BASE_URL, marker, slug: 'classic-checkout' },
			} ) ) as { payload: { accountOption: ControlledOptionRow; forceOption: ControlledOptionRow; effectiveProtection: boolean } };
			const snapshot = {
				accountOption: captured.payload.accountOption,
				forceOption: captured.payload.forceOption,
				classicCheckoutSlug: 'classic-checkout',
				runMarker: marker,
				originalEffectiveProtection: captured.payload.effectiveProtection,
			};
			const mutated = ( await runner.run( {
				operation: 'mutate-state',
				input: {
					baseURL: BASE_URL,
					content,
					marker,
					slug: 'classic-checkout',
					targetProtection,
					title,
				},
			} ) ) as { payload: { pageId: number } };
			const stateAfterMutation = readNativeOptionRuntimeState( statePath );
			const mutatedAccount = stateAfterMutation.options.wcpay_account_data;

			expect( mutatedAccount ).not.toEqual(
				initialState.options.wcpay_account_data
			);
			expect(
				accountProtectionFromRawRow( {
					exists: true,
					valueBase64: mutatedAccount.valueBase64,
					autoload: mutatedAccount.autoload,
				} )
			).toBe( targetProtection );
			if ( targetProtection ) {
				expect(
					stateAfterMutation.options
						.wcpaydev_force_card_testing_protection_on
				).toEqual( {
					valueBase64: Buffer.from( '1' ).toString( 'base64' ),
					autoload: 'no',
				} );
			} else {
				expect(
					stateAfterMutation.options
						.wcpaydev_force_card_testing_protection_on
				).toBeUndefined();
			}
			await expect(
				runner.run( {
					operation: 'verify-mutated-state',
					input: {
						baseURL: BASE_URL,
						content,
						marker,
						pageId: mutated.payload.pageId,
						slug: 'classic-checkout',
						targetProtection,
						title,
					},
				} )
			).resolves.toEqual(
				envelope( {
					accountConnected: true,
					accountProtection: true,
					cacheUsable: true,
					forceProtection: true,
					pageMatches: true,
				} )
			);
			await expect(
				runner.run( {
					operation: 'restore-state',
					input: {
						baseURL: BASE_URL,
						content,
						pageId: mutated.payload.pageId,
						snapshot,
						title,
					},
				} )
			).resolves.toEqual( envelope( { restored: true, pageAbsent: true } ) );
			expect( readNativeOptionRuntimeState( statePath ).options ).toEqual(
				initialState.options
			);
			await expect(
				runner.run( {
					operation: 'verify-restored-state',
					input: {
						baseURL: BASE_URL,
						content,
						pageId: mutated.payload.pageId,
						snapshot,
						title,
					},
				} )
			).resolves.toEqual(
				envelope( {
					rowsMatch: true,
					effectiveProtection: true,
					pageAbsent: true,
					sessionAbsent: true,
				} )
			);
		} finally {
			rmSync( workspace, { recursive: true, force: true } );
		}
	}
} );

test( 'rejects a usable disabled card-testing protection token before restoration', async () => {
	const { session } = makeSession( [] );
	const { context } = fakeContext( [], [ COOKIE_VALUE ] );
	const runner = new FakeRunner(
		replaceOperationResult(
			'read-guest-session',
			envelope( {
				cookieValid: true,
				sessionExists: true,
				token: { length: 16, sha256: TOKEN_DIGEST },
			} )
		)
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await scope.captureGuestSessionProtection( context as never );
			},
			{ runner, targetProtection: false }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).toContain( 'restore-state' );
} );

test( 'reads the percent-encoded session cookie a real store puts on the wire', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context } = fakeContext( [], [ WIRE_COOKIE_VALUE ] );

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		async ( scope ) => {
			await scope.registerFreshContext( context as never );
			expect(
				await scope.captureGuestSessionToken( context as never )
			).toEqual( { length: 16, sha256: TOKEN_DIGEST } );
		},
		{ runner }
	);

	// PHP fills `$_COOKIE` with the decoded value, and
	// `WC_Session_Handler::get_session_cookie()` deletes percent-escapes rather
	// than decoding them, so the wire form must never reach the store.
	expect(
		runner.requests.find(
			( request ) => request.operation === 'read-guest-session'
		)?.input
	).toMatchObject( {
		cookieName: COOKIE_NAME,
		cookieValue: DECODED_WIRE_COOKIE_VALUE,
		customerId: WIRE_CUSTOMER_ID,
	} );
	// And the session this run then owns - and deletes - is the one the decoded
	// cookie names, not some other guest's.
	expect(
		runner.requests.find(
			( request ) => request.operation === 'delete-guest-session'
		)?.input
	).toMatchObject( { customerId: WIRE_CUSTOMER_ID } );
} );

test( 'quarantines wire cookies that decode to something unverifiable', async () => {
	for ( const cookieValue of [
		// Decodes cleanly, but to a value with the wrong field count.
		`${ WIRE_CUSTOMER_ID }%7C1786694699`,
		// Decodes cleanly, but names no guest customer.
		`4%7C1786694699%7C1786608299%7C%24generic%24AbCdEf01`,
		// Not a decodable cookie value at all.
		`${ WIRE_CUSTOMER_ID }%7C1786694699%7C1786608299%7C%zz`,
		`${ WIRE_CUSTOMER_ID }%7C1786694699%7C1786608299%7C%`,
	] ) {
		const { session } = makeSession( [] );
		const runner = new FakeRunner();
		const { context } = fakeContext( [], [ cookieValue ] );
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( context as never );
					await scope.captureGuestSessionToken( context as never );
				},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
		// Nothing was read, so nothing may be deleted on this session's behalf.
		expect( operationNames( runner ) ).not.toContain(
			'delete-guest-session'
		);
	}
} );

test( 'decoding a wire cookie cannot stand in for Core verifying the session', async () => {
	const { session } = makeSession( [] );
	const { context } = fakeContext( [], [ WIRE_COOKIE_VALUE ] );
	const runner = new FakeRunner(
		replaceOperationResult(
			'read-guest-session',
			envelope( {
				cookieValid: false,
				sessionExists: false,
				token: null,
			} )
		)
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await scope.captureGuestSessionToken( context as never );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).not.toContain( 'delete-guest-session' );
} );

test( 'rejects missing, duplicate, and malformed WooCommerce session cookies', async () => {
	for ( const cookieValues of [
		[],
		[ COOKIE_VALUE, COOKIE_VALUE ],
		[ 'malformed' ],
		[ 'not-a-guest|2000000000|1999990000|signature' ],
	] ) {
		const { session } = makeSession( [] );
		const runner = new FakeRunner();
		const { context } = fakeContext( [], cookieValues );
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( context as never );
					await scope.captureGuestSessionToken( context as never );
				},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	}
} );

test( 'closes the registered context when session evidence is malformed', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context, events } = fakeContext( [], [] );

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await scope.captureGuestSessionToken( context as never );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( events ).toEqual( [ 'close' ] );
} );

test( 'rejects missing, malformed, or non-16-character session tokens', async () => {
	for ( const payload of [
		{ cookieValid: false, sessionExists: true, token: null },
		{ cookieValid: true, sessionExists: false, token: null },
		{
			cookieValid: true,
			sessionExists: true,
			token: { length: 15, sha256: TOKEN_DIGEST },
		},
		{
			cookieValid: true,
			sessionExists: true,
			token: { length: 16, sha256: 'raw-token' },
		},
	] ) {
		const { session } = makeSession( [] );
		const { context } = fakeContext( [], [ COOKIE_VALUE ] );
		const runner = new FakeRunner(
			replaceOperationResult( 'read-guest-session', envelope( payload ) )
		);
		await expect(
			withCapturedCardTestingProtectionState(
				session,
				RUN_ID,
				async ( scope ) => {
					await scope.registerFreshContext( context as never );
					await scope.captureGuestSessionToken( context as never );
				},
				{ runner }
			)
		).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	}
} );

test( 'deletes a Core-verified exact session even when its token is malformed', async () => {
	const { session } = makeSession( [] );
	const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );
	const runner = new FakeRunner(
		replaceOperationResult(
			'read-guest-session',
			envelope( {
				cookieValid: true,
				sessionExists: true,
				token: null,
			} )
		)
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await scope.captureGuestSessionToken( context as never );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).toContain( 'delete-guest-session' );
	expect( operationNames( runner ) ).toContain( 'verify-session-absent' );
	expect( events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
} );

test( 'cannot turn a caught malformed token into successful evidence', async () => {
	const { session } = makeSession( [] );
	const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );
	const runner = new FakeRunner(
		replaceOperationResult(
			'read-guest-session',
			envelope( {
				cookieValid: true,
				sessionExists: true,
				token: null,
			} )
		)
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await expect(
					scope.captureGuestSessionToken( context as never )
				).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
			},
			{ runner }
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( operationNames( runner ) ).toContain( 'delete-guest-session' );
	expect( operationNames( runner ) ).toContain( 'verify-session-absent' );
	expect( events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
} );

test( 'deletes the exact session, clears its exact cookie, closes context, and cold-proves absence', async () => {
	const { session } = makeSession( [] );
	const runner = new FakeRunner();
	const { context, events } = fakeContext( [], [ COOKIE_VALUE ] );

	await withCapturedCardTestingProtectionState(
		session,
		RUN_ID,
		async ( scope ) => {
			await scope.registerFreshContext( context as never );
			await scope.captureGuestSessionToken( context as never );
		},
		{ runner }
	);

	expect( operationNames( runner ) ).toEqual( [
		'capture-state',
		'mutate-state',
		'verify-mutated-state',
		'read-guest-session',
		'delete-guest-session',
		'verify-session-absent',
		'restore-state',
		'verify-restored-state',
	] );
	expect(
		runner.requests.find(
			( request ) => request.operation === 'delete-guest-session'
		)?.input
	).toMatchObject( { customerId: CUSTOMER_ID } );
	expect( events ).toEqual( [
		'clear:{"name":"wp_woocommerce_session_0123456789abcdef0123456789abcdef","domain":"store8889.localhost","path":"/"}',
		'close',
	] );
} );

test( 'preserves the callback error when session cleanup or restoration also fails', async () => {
	const primary = new Error( 'scenario failed' );
	const { session } = makeSession( [] );
	const { context } = fakeContext( [], [ COOKIE_VALUE ] );
	const runner = new FakeRunner(
		rejectOperation( 'delete-guest-session', new Error( 'cleanup failed' ) )
	);

	await expect(
		withCapturedCardTestingProtectionState(
			session,
			RUN_ID,
			async ( scope ) => {
				await scope.registerFreshContext( context as never );
				await scope.captureGuestSessionToken( context as never );
				throw primary;
			},
			{ runner }
		)
	).rejects.toMatchObject( {
		name: 'ResourceQuarantineRequiredError',
		primaryError: primary,
	} );
} );
