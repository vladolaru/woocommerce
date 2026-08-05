import { expect, test } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import type { JsonValue, ResourceLock } from '../resource-locks';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	NativeStoreWpCliRunner,
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
	baseURL?: string;
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
		runtime: 'native',
		runId: RUN_ID,
		baseURL: options.baseURL ?? BASE_URL,
		requireApprovedProviderFixture( capability: string ) {
			events.push( `capability:${ capability }` );
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
		Record< string, unknown >
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
