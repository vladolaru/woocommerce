import { mkdtemp, readFile, readdir, rm, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import {
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
} from '@playwright/test';

import { WooPaymentsPilotRuntime } from '../../fixtures/woopayments-native';

interface RequestCall {
	method: 'GET' | 'POST';
	url: string;
	data?: unknown;
}

function response( body: unknown, status = 200 ): APIResponse {
	return {
		ok: () => status >= 200 && status < 300,
		status: () => status,
		json: async () => body,
	} as APIResponse;
}

async function removeOwnedLock(
	lockDir: string,
	kind: 'feature-setting' | 'record-event'
): Promise< void > {
	const lockFiles = ( await readdir( lockDir ) ).filter( ( file ) =>
		file.endsWith( '.lock' )
	);
	for ( const file of lockFiles ) {
		const path = join( lockDir, file );
		const payload = JSON.parse( await readFile( path, 'utf8' ) ) as {
			key?: unknown;
		};
		if (
			typeof payload.key === 'string' &&
			payload.key.includes( `/${ kind }:` )
		) {
			await unlink( path );
			return;
		}
	}
	throw new Error( `Unable to find active ${ kind } lock.` );
}

function runtime(
	lockDir: string,
	calls: RequestCall[],
	options: {
		manualCapture?: boolean;
		throwAfterManualCaptureUpdate?: boolean;
		updateStatus?: number;
		loseFeatureAfterSettingsRead?: boolean;
	} = {}
): WooPaymentsPilotRuntime {
	let manualCapture = options.manualCapture ?? false;
	let updateCount = 0;
	const api = {
		get: async ( url: string ) => {
			calls.push( { method: 'GET', url } );
			if ( url === '/wp-json/wc/v3/payments/settings' ) {
				if ( options.loseFeatureAfterSettingsRead ) {
					await removeOwnedLock( lockDir, 'feature-setting' );
				}
				return response( {
					is_manual_capture_enabled: manualCapture,
				} );
			}
			throw new Error( `Unexpected GET ${ url }` );
		},
		post: async ( url: string, requestOptions?: { data?: unknown } ) => {
			calls.push( {
				method: 'POST',
				url,
				data: requestOptions?.data,
			} );
			if ( url === '/wp-json/wc/v3/products' ) {
				return response( { id: 73 } );
			}
			if ( url !== '/wp-json/wc/v3/payments/settings' ) {
				throw new Error( `Unexpected POST ${ url }` );
			}
			const data = requestOptions?.data as {
				is_manual_capture_enabled?: unknown;
			};
			manualCapture = data.is_manual_capture_enabled as boolean;
			updateCount += 1;
			if ( options.throwAfterManualCaptureUpdate && updateCount === 1 ) {
				throw new Error( 'Response lost after settings application.' );
			}
			return response(
				{ is_manual_capture_enabled: manualCapture },
				updateCount === 1 ? options.updateStatus : 200
			);
		},
	} as APIRequestContext;

	class ApprovedPilotRuntime extends WooPaymentsPilotRuntime {
		public override requireApprovedProviderFixture(): void {}
	}

	return new ApprovedPilotRuntime(
		api,
		'native',
		'run-pilot-runtime',
		'http://native.test',
		123,
		'native-store',
		'acct_native',
		lockDir
	);
}

async function lockDirectory(): Promise< string > {
	return mkdtemp( join( tmpdir(), 'woopayments-pilot-runtime-test-' ) );
}

test( 'fails before a provider helper call when account and store locks are not owned', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );

	try {
		await expect(
			pilotRuntime.createOwnedProduct( '10.99' )
		).rejects.toThrow( /account.*store.*locks/i );
		expect( calls ).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'owns account, store, and record locks before a provider helper call', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-payment' },
			async () => {
				const files = await readdir( directory );
				expect(
					files.filter( ( file ) => file.endsWith( '.lock' ) )
				).toHaveLength( 3 );
				await pilotRuntime.createOwnedProduct( '10.99' );
			}
		);
		expect( calls ).toEqual( [
			{
				method: 'POST',
				url: '/wp-json/wc/v3/products',
				data: {
					name: 'WooPayments native E2E run-pilot-runtime',
					type: 'simple',
					regular_price: '10.99',
					meta_data: [
						{
							key: '_e2e_woopayments_run_id',
							value: 'run-pilot-runtime',
						},
					],
				},
			},
		] );
		const files = await readdir( directory );
		expect( files.filter( ( file ) => file.endsWith( '.lock' ) ) ).toEqual(
			[]
		);
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'routes every provider-writing pilot through a lock-owning wrapper', async () => {
	const pilotDirectory = resolve(
		process.cwd(),
		'tests/e2e/tests/woopayments-native/pilots'
	);
	const pilotContracts = [
		{
			file: 'shopper-card-payment.spec.ts',
			wrapper: 'pilotRuntime.withProviderWriteLocks',
			firstProviderAction: 'pilotRuntime.createOwnedProduct',
		},
		{
			file: 'saved-method-cutover.spec.ts',
			wrapper: 'pilotRuntime.withProviderWriteLocks',
			firstProviderAction: 'pilotRuntime.requireApprovedProviderFixture',
		},
		{
			file: 'merchant-transaction-navigation.spec.ts',
			wrapper: 'pilotRuntime.withProviderWriteLocks',
			firstProviderAction: 'pilotRuntime.requireApprovedProviderFixture',
		},
		{
			file: 'merchant-manual-capture.spec.ts',
			wrapper: 'pilotRuntime.withCapturedManualCaptureSetting',
			firstProviderAction:
				"pilotRuntime.requireApprovedProviderFixture( 'manual-capture' )",
		},
	];

	for ( const contract of pilotContracts ) {
		const source = await readFile(
			join( pilotDirectory, contract.file ),
			'utf8'
		);
		const wrapperIndex = source.indexOf( contract.wrapper );
		const actionIndex = source.indexOf( contract.firstProviderAction );

		expect(
			wrapperIndex,
			`${ contract.file } lock wrapper`
		).toBeGreaterThan( -1 );
		expect(
			actionIndex,
			`${ contract.file } first provider action`
		).toBeGreaterThan( -1 );
		expect( wrapperIndex ).toBeLessThan( actionIndex );
	}
} );

test( 'fails before a helper write when the active record lock is lost', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'record-ownership-loss' },
				async () => {
					await removeOwnedLock( directory, 'record-event' );
					await pilotRuntime.createOwnedProduct( '10.99' );
				}
			)
		).rejects.toThrow( /record-event.*ownership/i );
		expect( calls ).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'fails before a manual-setting write when the feature lock is lost', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		loseFeatureAfterSettingsRead: true,
	} );

	try {
		await expect(
			pilotRuntime.withCapturedManualCaptureSetting( async () => {} )
		).rejects.toThrow( /feature-setting.*ownership/i );
		expect( calls.filter( ( call ) => call.method === 'POST' ) ).toEqual(
			[]
		);
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'uses the exact aggregate settings contract for mutation and restore', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );

	try {
		await pilotRuntime.withCapturedManualCaptureSetting( async () => {} );
		expect( calls ).toEqual( [
			{
				method: 'GET',
				url: '/wp-json/wc/v3/payments/settings',
			},
			{
				method: 'POST',
				url: '/wp-json/wc/v3/payments/settings',
				data: { is_manual_capture_enabled: true },
			},
			{
				method: 'POST',
				url: '/wp-json/wc/v3/payments/settings',
				data: { is_manual_capture_enabled: false },
			},
		] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'restores the aggregate setting when the update applies and then throws', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		throwAfterManualCaptureUpdate: true,
	} );

	try {
		await expect(
			pilotRuntime.withCapturedManualCaptureSetting( async () => {
				throw new Error( 'The pilot callback must not run.' );
			} )
		).rejects.toThrow( /response lost/i );
		expect( calls ).toEqual( [
			{
				method: 'GET',
				url: '/wp-json/wc/v3/payments/settings',
			},
			{
				method: 'POST',
				url: '/wp-json/wc/v3/payments/settings',
				data: { is_manual_capture_enabled: true },
			},
			{
				method: 'POST',
				url: '/wp-json/wc/v3/payments/settings',
				data: { is_manual_capture_enabled: false },
			},
		] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'rejects a non-200 aggregate update response and still restores', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, { updateStatus: 201 } );

	try {
		await expect(
			pilotRuntime.withCapturedManualCaptureSetting( async () => {} )
		).rejects.toThrow( /HTTP 201/i );
		expect(
			calls.filter(
				( call ) =>
					call.method === 'POST' &&
					call.url === '/wp-json/wc/v3/payments/settings'
			)
		).toHaveLength( 2 );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );
