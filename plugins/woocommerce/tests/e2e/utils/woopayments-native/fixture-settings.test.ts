import { expect, test } from '@playwright/test';

import {
	FixtureManualCaptureScope,
	isolatedBrowserContextOptions,
	openFixtureAdminSession,
} from './fixture-settings';

test( 'isolated role contexts explicitly discard inherited warmed admin state', () => {
	expect( isolatedBrowserContextOptions ).toBeInstanceOf( Function );
	expect( isolatedBrowserContextOptions( 'http://store.test' ) ).toEqual( {
		baseURL: 'http://store.test',
		storageState: { cookies: [], origins: [] },
	} );
} );

test( 'fixture readonly uses warmed admin state without a fresh UI login', async () => {
	const events: string[] = [];
	const page = {
		goto: async ( path: string ) => events.push( `goto:${ path }` ),
		url: () => 'http://store.test/wp-admin/',
		locator: ( selector: string ) => ( {
			count: async () => ( selector === '#wpadminbar' ? 1 : 0 ),
		} ),
	};

	await openFixtureAdminSession( {
		baseURL: 'http://store.test',
		fixtureEnabled: true,
		page,
		login: async () => events.push( 'login' ),
	} );

	expect( events ).toEqual( [ 'goto:/wp-admin/' ] );
} );

test( 'connected profile retains its explicit admin login', async () => {
	const events: string[] = [];
	await openFixtureAdminSession( {
		baseURL: 'http://store.test',
		fixtureEnabled: false,
		page: {
			goto: async () => events.push( 'unexpected-goto' ),
			url: () => 'http://store.test/wp-login.php',
			locator: () => ( { count: async () => 0 } ),
		},
		login: async () => events.push( 'login' ),
	} );

	expect( events ).toEqual( [ 'login' ] );
} );

test( 'fixture manual capture is restored after a transaction assertion fails', async () => {
	let manualCapture = false;
	const writes: boolean[] = [];
	const scope = new FixtureManualCaptureScope();
	const access = {
		read: async () => manualCapture,
		write: async ( enabled: boolean ) => {
			writes.push( enabled );
			manualCapture = enabled;
		},
	};

	await scope.before( { fixtureEnabled: true, ...access } );
	expect( manualCapture ).toBe( true );
	await expect(
		Promise.reject( new Error( 'transaction assertion failed' ) )
	).rejects.toThrow( 'transaction assertion failed' );
	await scope.after( access );

	expect( writes ).toEqual( [ true, false ] );
	expect( manualCapture ).toBe( false );
} );

test( 'connected profiles do not change manual capture state', async () => {
	const writes: boolean[] = [];
	const scope = new FixtureManualCaptureScope();
	const access = {
		read: async () => false,
		write: async ( enabled: boolean ) => {
			writes.push( enabled );
		},
	};

	await scope.before( { fixtureEnabled: false, ...access } );
	await scope.after( access );

	expect( writes ).toEqual( [] );
} );
