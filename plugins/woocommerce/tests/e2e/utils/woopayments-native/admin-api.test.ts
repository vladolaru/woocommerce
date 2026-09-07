import {
	expect,
	test,
	type BrowserContext,
	type Locator,
	type Page,
} from '@playwright/test';

import {
	adminContextOptions,
	authenticateAdminContext,
	getBlocksCardFrameSelector,
	hydrateAdminRestNonce,
} from '../../fixtures/woopayments-native';
import { ADMIN_STATE_PATH } from '../../playwright.config';

test( 'uses the globally warmed admin state only for the readonly project', () => {
	expect(
		adminContextOptions(
			'http://store.example',
			'woopayments-native-readonly',
			ADMIN_STATE_PATH
		)
	).toEqual( {
		baseURL: 'http://store.example',
		storageState: ADMIN_STATE_PATH,
	} );

	for ( const project of [
		'woopayments-native-provider',
		'woopayments-native-transition',
		'woopayments-native-extension-compat',
	] ) {
		expect(
			adminContextOptions(
				'http://store.example',
				project,
				ADMIN_STATE_PATH
			)
		).toEqual( { baseURL: 'http://store.example' } );
	}
} );

test( 'hydrates a REST nonce from an already authenticated admin state without logging in', async () => {
	const actions: string[] = [];
	let headers: Record< string, string > | undefined;
	const page = {
		close: async () => actions.push( 'close' ),
		evaluate: async () => 'warmed-rest-nonce',
		goto: async ( url: string ) => actions.push( `goto:${ url }` ),
		url: () => 'http://store.example/wp-admin/',
	} as unknown as Page;
	const context = {
		newPage: async () => page,
		setExtraHTTPHeaders: async ( value: Record< string, string > ) => {
			headers = value;
		},
	} as unknown as BrowserContext;

	await hydrateAdminRestNonce( context );

	expect( actions ).toEqual( [ 'goto:wp-admin/', 'close' ] );
	expect( headers ).toEqual( { 'X-WP-Nonce': 'warmed-rest-nonce' } );
} );

test( 'authenticates REST through the exact browser cookie and nonce session', async () => {
	const actions: string[] = [];
	let headers: Record< string, string > | undefined;
	const field = ( name: string ) =>
		( {
			fill: async ( value: string ) => {
				actions.push( `fill:${ name }:${ value }` );
			},
		} ) as Locator;
	const page = {
		close: async () => {
			actions.push( 'close' );
		},
		evaluate: async () => 'rest-nonce',
		getByLabel: ( name: string ) => {
			if ( name !== 'Username or Email Address' ) {
				throw new Error( `Unexpected label locator: ${ name }` );
			}
			return field( name );
		},
		getByRole: ( role: string, options?: { name?: string } ) => {
			if ( role === 'textbox' && options?.name === 'Password' ) {
				return field( options.name );
			}
			return {
				click: async () => {
					actions.push( 'click:Log In' );
				},
			} as Locator;
		},
		goto: async ( url: string ) => {
			actions.push( `goto:${ url }` );
		},
		waitForURL: async ( url: string ) => {
			actions.push( `wait:${ url }` );
		},
	} as unknown as Page;
	const context = {
		newPage: async () => page,
		setExtraHTTPHeaders: async ( value: Record< string, string > ) => {
			headers = value;
		},
	} as unknown as BrowserContext;

	await authenticateAdminContext( context, {
		username: 'pilot-admin',
		password: 'pilot-password',
	} );

	expect( actions ).toEqual( [
		'goto:wp-login.php',
		'fill:Username or Email Address:pilot-admin',
		'fill:Password:pilot-password',
		'click:Log In',
		'wait:**/wp-admin/**',
		'close',
	] );
	expect( headers ).toEqual( {
		'X-WP-Nonce': 'rest-nonce',
	} );
} );

test( 'targets the runtime-owned Blocks card frame', () => {
	expect( getBlocksCardFrameSelector( 'client' ) ).toContain(
		'.wcpay-payment-element'
	);
	expect( getBlocksCardFrameSelector( 'native' ) ).toContain(
		'#wcpay-core-blocks-payment-element'
	);
	expect( getBlocksCardFrameSelector( 'transition' ) ).toContain(
		'#wcpay-core-blocks-payment-element'
	);
} );
