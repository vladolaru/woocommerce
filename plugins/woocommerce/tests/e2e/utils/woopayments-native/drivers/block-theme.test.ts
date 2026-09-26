import { expect, test } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	BLOCK_THEME_CAPABILITY,
	withActiveBlockTheme,
	type BlockThemeGateway,
	type InstalledTheme,
} from './block-theme';

const RUN_ID = 'run-block-theme-1';
const BASELINE = 'twentytwentyfive';
const TARGET = 'twentytwentyfour';

function theme(
	stylesheet: string,
	active: boolean,
	isBlockTheme: boolean | undefined = true
): InstalledTheme {
	return {
		stylesheet,
		template: stylesheet,
		name: stylesheet,
		isBlockTheme,
		active,
	};
}

interface FakeGatewayOptions {
	installed?: InstalledTheme[];
	activateError?: ( stylesheet: string ) => Error | undefined;
	/** Swallows the activation without changing the store, to model a no-op write. */
	ignoreActivation?: boolean;
}

class FakeGateway implements BlockThemeGateway {
	public readonly calls: string[] = [];
	private themes: InstalledTheme[];
	private readonly options: FakeGatewayOptions;

	public constructor( options: FakeGatewayOptions = {} ) {
		this.options = options;
		this.themes = options.installed ?? [
			theme( BASELINE, true ),
			theme( TARGET, false ),
		];
	}

	public async listInstalledThemes(): Promise< InstalledTheme[] > {
		this.calls.push( 'list' );
		return this.themes.map( ( entry ) => ( { ...entry } ) );
	}

	public async activateTheme( stylesheet: string ): Promise< void > {
		this.calls.push( `activate:${ stylesheet }` );
		const error = this.options.activateError?.( stylesheet );
		if ( error ) {
			throw error;
		}
		if ( this.options.ignoreActivation ) {
			return;
		}
		this.themes = this.themes.map( ( entry ) => ( {
			...entry,
			active: entry.stylesheet === stylesheet,
		} ) );
	}
}

function makeSession( events: string[] ): ProviderWriteSession {
	return {
		runtime: 'native',
		runId: RUN_ID,
		baseURL: 'http://store8889.localhost:8889',
		requireApprovedProviderFixture( capability: string ) {
			events.push( `capability:${ capability }` );
		},
		async assertCanWrite() {
			events.push( 'assert-can-write' );
		},
	} as unknown as ProviderWriteSession;
}

test( 'a scope activates the target, runs the callback, and restores the baseline', async () => {
	const events: string[] = [];
	const gateway = new FakeGateway();

	const observed = await withActiveBlockTheme(
		makeSession( events ),
		RUN_ID,
		TARGET,
		async ( scope ) => {
			expect( scope.baseline.stylesheet ).toBe( BASELINE );
			expect( scope.active.stylesheet ).toBe( TARGET );
			return ( await gateway.listInstalledThemes() )
				.filter( ( entry ) => entry.active )
				.map( ( entry ) => entry.stylesheet );
		},
		{ gateway }
	);

	expect(
		observed,
		'the callback must run with the target theme active'
	).toEqual( [ TARGET ] );
	expect( gateway.calls ).toEqual( [
		'list',
		`activate:${ TARGET }`,
		'list',
		'list',
		`activate:${ BASELINE }`,
		'list',
	] );
	expect( events ).toContain( `capability:${ BLOCK_THEME_CAPABILITY }` );
} );

test( 'the baseline is restored after the callback fails, and the callback failure is what surfaces', async () => {
	const gateway = new FakeGateway();
	const failure = new Error( 'the case failed' );

	await expect(
		withActiveBlockTheme(
			makeSession( [] ),
			RUN_ID,
			TARGET,
			async () => {
				throw failure;
			},
			{ gateway }
		)
	).rejects.toBe( failure );

	expect(
		( await gateway.listInstalledThemes() ).find(
			( entry ) => entry.active
		)?.stylesheet,
		'a failing case must still leave the store on its own theme'
	).toBe( BASELINE );
} );

/** A store whose Themes screen refuses the activation that puts it back. */
function refusingRestoreGateway(): FakeGateway {
	return new FakeGateway( {
		activateError: ( stylesheet ) =>
			stylesheet === BASELINE
				? new Error( 'the themes screen refused' )
				: undefined,
	} );
}

/**
 * A store whose restore write answers healthily and changes nothing, which is
 * the failure mode a read-back exists to catch.
 */
function silentRestoreGateway(): BlockThemeGateway {
	const gateway = new FakeGateway();
	let activations = 0;

	return {
		listInstalledThemes: () => gateway.listInstalledThemes(),
		activateTheme: async ( stylesheet ) => {
			activations += 1;
			if ( activations > 1 ) {
				return;
			}
			await gateway.activateTheme( stylesheet );
		},
	};
}

test( 'a restore that does not take quarantines rather than returning', async () => {
	const gateway = refusingRestoreGateway();

	const failure = await withActiveBlockTheme(
		makeSession( [] ),
		RUN_ID,
		TARGET,
		async () => undefined,
		{ gateway }
	).catch( ( error: unknown ) => error );

	expect( failure ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( failure as ResourceQuarantineRequiredError ).reasonCode ).toBe(
		'restoration-failed'
	);
} );

test( 'a restore that answers healthily but changes nothing is caught by the verified read', async () => {
	const failure = await withActiveBlockTheme(
		makeSession( [] ),
		RUN_ID,
		TARGET,
		async () => undefined,
		{ gateway: silentRestoreGateway() }
	).catch( ( error: unknown ) => error );

	expect( failure ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( failure as ResourceQuarantineRequiredError ).reasonCode ).toBe(
		'restoration-failed'
	);
} );

test( 'a store already sitting on the target theme is refused before anything is written', async () => {
	const gateway = new FakeGateway( {
		installed: [ theme( TARGET, true ), theme( BASELINE, false ) ],
	} );

	await expect(
		withActiveBlockTheme(
			makeSession( [] ),
			RUN_ID,
			TARGET,
			async () => undefined,
			{ gateway }
		)
	).rejects.toThrow( /already active/ );

	expect(
		gateway.calls,
		'a refused precondition must not activate anything'
	).toEqual( [ 'list' ] );
} );

test( 'an uninstalled target theme is refused before anything is written', async () => {
	const gateway = new FakeGateway( {
		installed: [ theme( BASELINE, true ) ],
	} );

	await expect(
		withActiveBlockTheme(
			makeSession( [] ),
			RUN_ID,
			TARGET,
			async () => undefined,
			{ gateway }
		)
	).rejects.toThrow( /installed on this store/ );

	expect( gateway.calls ).toEqual( [ 'list' ] );
} );

test( 'a target WordPress reports as a classic theme is refused', async () => {
	const gateway = new FakeGateway( {
		installed: [ theme( BASELINE, true ), theme( TARGET, false, false ) ],
	} );

	await expect(
		withActiveBlockTheme(
			makeSession( [] ),
			RUN_ID,
			TARGET,
			async () => undefined,
			{ gateway }
		)
	).rejects.toThrow( /block theme/ );

	expect( gateway.calls ).toEqual( [ 'list' ] );
} );

test( 'an activation that does not take fails plainly, without a quarantine, because the store never moved', async () => {
	const gateway = new FakeGateway( { ignoreActivation: true } );
	let callbackRan = false;

	const failure = await withActiveBlockTheme(
		makeSession( [] ),
		RUN_ID,
		TARGET,
		async () => {
			callbackRan = true;
		},
		{ gateway }
	).catch( ( error: unknown ) => error );

	expect( callbackRan ).toBe( false );
	expect( failure ).not.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( String( failure ) ).toMatch( /did not take/ );
	expect(
		gateway.calls,
		'the restore still runs and proves the store is on its own theme'
	).toEqual( [
		'list',
		`activate:${ TARGET }`,
		'list',
		`activate:${ BASELINE }`,
		'list',
	] );
} );

test( 'a run ID that is not the session run ID is refused', async () => {
	const gateway = new FakeGateway();

	await expect(
		withActiveBlockTheme(
			makeSession( [] ),
			'some-other-run',
			TARGET,
			async () => undefined,
			{ gateway }
		)
	).rejects.toThrow( /active run ID/ );

	expect( gateway.calls ).toEqual( [] );
} );
