import { expect, test } from '@playwright/test';

import {
	NativeStoreCatalogRunner,
	withGuaranteedRestoration,
} from './multi-currency-catalog';

test( 'scenario state is restored when a currency assertion fails', async () => {
	let enabled = [ 'USD', 'EUR' ];
	const original = [ ...enabled ];
	await expect(
		withGuaranteedRestoration(
			async () => {
				enabled = [ ...enabled, 'CHF' ];
				throw new Error( 'currency assertion failed' );
			},
			async () => {
				enabled = [ ...original ];
			}
		)
	).rejects.toThrow( 'currency assertion failed' );
	expect( enabled ).toEqual( [ 'USD', 'EUR' ] );
} );

test( 'native catalog commands select the configured wp-env store', async () => {
	const calls: Array< {
		command: string;
		args: string[];
		options: { cwd: string };
	} > = [];
	const previousConfig = process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG;
	process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG = '.wp-env.e2e.json';
	try {
		const runner = new NativeStoreCatalogRunner( {
			storeDirectory: '/fixture/store',
			execFile: async ( command, args, options ) => {
				calls.push( { command, args, options } );
				return '{"ok":true}\n';
			},
		} );

		await runner.run( {
			operation: 'capture-catalog',
			input: { baseURL: 'http://localhost:18086' },
		} );
	} finally {
		if ( previousConfig === undefined ) {
			delete process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG;
		} else {
			process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG = previousConfig;
		}
	}

	expect( calls ).toHaveLength( 1 );
	expect( calls[ 0 ].command ).toBe( 'pnpm' );
	expect( calls[ 0 ].args.slice( 0, 7 ) ).toEqual( [
		'exec',
		'wp-env',
		'--config',
		'.wp-env.e2e.json',
		'run',
		'cli',
		'wp',
	] );
	expect( calls[ 0 ].options ).toEqual( { cwd: '/fixture/store' } );
} );

test( 'native catalog commands require the explicit wp-env config', async () => {
	const previousConfig = process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG;
	delete process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG;
	try {
		const runner = new NativeStoreCatalogRunner( {
			storeDirectory: '/fixture/store',
			execFile: async () => '{"ok":true}\n',
		} );
		await expect(
			runner.run( {
				operation: 'capture-catalog',
				input: { baseURL: 'http://localhost:18086' },
			} )
		).rejects.toThrow( 'E2E_WOOPAYMENTS_WP_ENV_CONFIG is required' );
	} finally {
		if ( previousConfig !== undefined ) {
			process.env.E2E_WOOPAYMENTS_WP_ENV_CONFIG = previousConfig;
		}
	}
} );
