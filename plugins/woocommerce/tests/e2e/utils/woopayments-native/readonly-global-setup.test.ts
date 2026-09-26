import { expect, test, type FullConfig } from '@playwright/test';

import {
	authenticatedAdminNonce,
	runReadonlyGlobalSetup,
	type ReadonlySetupRequestContext,
} from '../../envs/woopayments-native/readonly-global-setup';

test( 'admin warm-up rejects login redirects and foreign origins', async () => {
	const login = {
		ok: () => true,
		status: () => 200,
		url: () =>
			'http://store.test/wp-login.php?redirect_to=http%3A%2F%2Fstore.test%2Fwp-admin%2F',
		text: async () => '<form id="loginform"></form>',
	};
	await expect(
		authenticatedAdminNonce( login, 'http://store.test' )
	).rejects.toThrow( /authenticated wp-admin/i );

	const foreign = {
		...login,
		url: () => 'http://foreign.test/wp-admin/',
		text: async () =>
			'<div id="wpadminbar"></div><script>var wpApiSettings = {"nonce":"good123"};</script>',
	};
	await expect(
		authenticatedAdminNonce( foreign, 'http://store.test' )
	).rejects.toThrow( /authenticated wp-admin/i );
} );

test( 'native readonly global setup seeds catalog before persisting warmed state', async () => {
	const events: string[] = [];
	const session = {
		getCalls: 0,
		post: async ( url: string ) => {
			events.push( `post:${ url }` );
			return { ok: () => true };
		},
		get: async ( url: string ) => {
			events.push( `get:${ url }` );
			session.getCalls += 1;
			return {
				ok: () => true,
				status: () => 200,
				url: () =>
					session.getCalls === 1
						? 'http://store.test/wp-login.php'
						: 'http://store.test/wp-admin/',
				text: async () =>
					'<div id="wpadminbar"></div><script>var wpApiSettings = {"nonce":"native123"};</script>',
			};
		},
		storageState: async () => {
			events.push( 'storage-state' );
		},
		dispose: async () => {
			events.push( 'dispose' );
		},
	};
	const config = {
		projects: [
			{
				name: 'woopayments-native-readonly',
				metadata: { woopaymentsReadonlySetup: true },
				use: { baseURL: 'http://store.test' },
			},
		],
	} as unknown as FullConfig;

	await runReadonlyGlobalSetup( config, {
		runtime: 'native',
		seedNative: () => events.push( 'settings-seed' ),
		newRequestContext: async () =>
			session as unknown as ReadonlySetupRequestContext,
		seedCatalog: async ( received, nonce ) => {
			expect( received ).toBe( session );
			events.push( `catalog-seed:${ nonce }` );
		},
	} );

	expect( events ).toEqual( [
		'settings-seed',
		'get:./wp-login.php',
		'post:./wp-login.php',
		'get:./wp-admin/',
		'catalog-seed:native123',
		'storage-state',
		'dispose',
	] );
} );
