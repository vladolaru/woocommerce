import { mkdtemp, readFile, readdir, rm, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import {
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
	type Page,
} from '@playwright/test';

import { WooPaymentsPilotRuntime } from '../../fixtures/woopayments-native';

interface RequestCall {
	method: 'DELETE' | 'GET' | 'POST' | 'PUT';
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
		loseRecordBeforeCleanupDelete?: boolean;
	} = {}
): WooPaymentsPilotRuntime {
	let manualCapture = options.manualCapture ?? false;
	let updateCount = 0;
	const api = {
		delete: async ( url: string, requestOptions?: { data?: unknown } ) => {
			calls.push( {
				method: 'DELETE',
				url,
				data: requestOptions?.data,
			} );
			return response( {} );
		},
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
		put: async ( url: string, requestOptions?: { data?: unknown } ) => {
			calls.push( {
				method: 'PUT',
				url,
				data: requestOptions?.data,
			} );
			return response( {} );
		},
	} as APIRequestContext;

	class ApprovedPilotRuntime extends WooPaymentsPilotRuntime {
		public override requireApprovedProviderFixture(): void {}

		public override requireEphemeralTransitionAllocation(): void {}

		public override async withProviderWriteLocks< Result >(
			lockOptions: {
				featureSetting?: string;
				recordEvent?: string;
			},
			callback: () => Promise< Result >
		): Promise< Result > {
			return super.withProviderWriteLocks( lockOptions, async () => {
				if (
					options.loseRecordBeforeCleanupDelete &&
					lockOptions.recordEvent === 'owned-product-cleanup'
				) {
					await removeOwnedLock( lockDir, 'record-event' );
				}
				return callback();
			} );
		}
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

interface LockLossPageOptions {
	loseOn: {
		action: 'goto' | 'click' | 'check' | 'expect';
		name: string;
	};
	mutation: {
		role: string;
		name: string;
	};
}

function lockLossPage(
	lockDir: string,
	options: LockLossPageOptions
): { page: Page; mutationInvocations: () => number } {
	let lockLost = false;
	let mutationCount = 0;

	const maybeLoseLock = async (
		action: LockLossPageOptions[ 'loseOn' ][ 'action' ],
		name: string
	): Promise< void > => {
		if (
			! lockLost &&
			action === options.loseOn.action &&
			name.includes( options.loseOn.name )
		) {
			lockLost = true;
			await removeOwnedLock( lockDir, 'record-event' );
		}
	};

	const locator = ( role: string, name: string ) => {
		const value = {
			_apiName: 'Locator',
			_expect: async () => {
				await maybeLoseLock( 'expect', name );
				return { matches: true };
			},
			check: async () => {
				await maybeLoseLock( 'check', name );
			},
			click: async () => {
				if (
					role === options.mutation.role &&
					name.includes( options.mutation.name )
				) {
					mutationCount += 1;
					return;
				}
				await maybeLoseLock( 'click', name );
			},
			fill: async () => {},
			first: () => value,
			getByRole: (
				childRole: string,
				childOptions?: { name?: string | RegExp }
			) =>
				locator( childRole, String( childOptions?.name ?? childRole ) ),
			toString: () => `fake locator ${ role } ${ name }`,
		};
		return value;
	};

	const page = {
		getByLabel: ( name: string | RegExp ) =>
			locator( 'label', String( name ) ),
		getByRole: ( role: string, roleOptions?: { name?: string | RegExp } ) =>
			locator( role, String( roleOptions?.name ?? role ) ),
		getByText: ( name: string | RegExp ) =>
			locator( 'text', String( name ) ),
		goto: async ( url: string ) => {
			await maybeLoseLock( 'goto', url );
		},
		url: () => 'http://native.test/checkout/order-received/42/',
	} as unknown as Page;

	return {
		page,
		mutationInvocations: () => mutationCount,
	};
}

async function expectMutationBlockedAfterPreparation(
	options: LockLossPageOptions,
	mutate: (
		pilotRuntime: WooPaymentsPilotRuntime,
		page: Page
	) => Promise< void >
): Promise< void > {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const { page, mutationInvocations } = lockLossPage( directory, options );

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'late-ownership-loss' },
				async () => mutate( pilotRuntime, page )
			)
		).rejects.toThrow( /record-event.*ownership/i );
		expect( mutationInvocations() ).toBe( 0 );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
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

test( 'blocks a card payment after lock loss during checkout preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: { action: 'check', name: 'WooPayments|credit card' },
				mutation: { role: 'button', name: 'place order' },
			},
			async ( pilotRuntime, page ) => {
				await pilotRuntime.completeCardCheckout(
					page,
					{ id: 73, name: 'Owned product', amount: '10.99' },
					'run-pilot-runtime'
				);
			}
		)
	).resolves.toBeUndefined();
} );

test( 'blocks the order metadata write after lock loss during confirmation', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const { page } = lockLossPage( directory, {
		loseOn: {
			action: 'expect',
			name: 'Your order has been received',
		},
		mutation: { role: 'unused', name: 'unused' },
	} );

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'order-metadata-ownership-loss' },
				async () => {
					await pilotRuntime.completeCardCheckout(
						page,
						{
							id: 73,
							name: 'Owned product',
							amount: '10.99',
						},
						'run-pilot-runtime'
					);
				}
			)
		).rejects.toThrow( /record-event.*ownership/i );
		expect( calls.filter( ( call ) => call.method === 'PUT' ) ).toEqual(
			[]
		);
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'blocks a saved-card default update after lock loss during preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: {
					action: 'goto',
					name: 'my-account/payment-methods/',
				},
				mutation: { role: 'button', name: 'make default' },
			},
			async ( pilotRuntime, page ) => {
				await pilotRuntime.makeSavedCardDefault( page, 73 );
			}
		)
	).resolves.toBeUndefined();
} );

test( 'blocks cutover after lock loss during admin preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: { action: 'click', name: 'WooCommerce' },
				mutation: {
					role: 'button',
					name: 'switch to native WooPayments',
				},
			},
			async ( pilotRuntime, page ) => {
				await pilotRuntime.softCutOverEphemeralStore( page );
			}
		)
	).resolves.toBeUndefined();
} );

test( 'blocks a saved-card checkout after lock loss during preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: { action: 'check', name: 'pm_saved_card' },
				mutation: { role: 'button', name: 'place order' },
			},
			async ( pilotRuntime, page ) => {
				await pilotRuntime.payWithExactSavedCard(
					page,
					{ tokenId: 73, paymentMethodId: 'pm_saved_card' },
					'classic',
					'run-pilot-runtime'
				);
			}
		)
	).resolves.toBeUndefined();
} );

test( 'blocks capture after lock loss during order preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: { action: 'click', name: '42' },
				mutation: { role: 'button', name: 'capture' },
			},
			async ( pilotRuntime, page ) => {
				await pilotRuntime.captureExactOrder( page, {
					runId: 'run-pilot-runtime',
					orderId: 42,
					orderKey: 'wc_order_key',
					intentId: 'pi_exact',
					chargeId: 'ch_exact',
					paymentMethodId: 'pm_exact',
					amountMinor: 1099,
					currency: 'USD',
					orderStatus: 'on-hold',
					providerStatus: 'requires_capture',
					chargeStatus: 'pending',
					chargeCaptured: false,
					occurrenceCount: 1,
					captureOccurrenceCount: 0,
				} );
			}
		)
	).resolves.toBeUndefined();
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

test( 'blocks product cleanup after loss of the cleanup record lock', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		loseRecordBeforeCleanupDelete: true,
	} );

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'create-product-for-cleanup' },
			async () => {
				await pilotRuntime.createOwnedProduct( '10.99' );
			}
		);

		await expect( pilotRuntime.cleanup() ).rejects.toThrow(
			/record-event.*ownership/i
		);
		expect( calls.filter( ( call ) => call.method === 'DELETE' ) ).toEqual(
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
