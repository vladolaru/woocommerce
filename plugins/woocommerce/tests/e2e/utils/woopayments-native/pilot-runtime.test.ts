import {
	mkdir,
	mkdtemp,
	readFile,
	readdir,
	rm,
	unlink,
	writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import {
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
	type Locator,
	type Page,
} from '@playwright/test';

import {
	loadInitialRuntimeStatus,
	WooPaymentsPilotRuntime,
} from '../../fixtures/woopayments-native';
import type { PaymentEvidence } from './record-evidence';

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

test( 'loads client readiness from plugin-owned diagnostics without calling the native route', async () => {
	const directory = await mkdtemp(
		join( tmpdir(), 'woopayments-client-readiness-' )
	);
	const clientDirectory = join( directory, 'client' );
	const status = {
		site_url: 'http://localhost',
		wpcom_blog_id: 2,
		runtime_owner: 'plugin',
		native_enabled: false,
		account_id: 'acct_client',
		account_connected: true,
		gateway_enabled: true,
		test_mode: true,
		enabled_payment_methods: [ 'card' ],
		last_webhook_fetch: 0,
		callback_probe: {
			registered: false,
			reachable: false,
			wpcom_blog_id: 0,
		},
	} as const;
	let nativeRouteCalls = 0;
	const api = {
		get: async () => {
			nativeRouteCalls++;
			throw new Error( 'The client adapter called the native route.' );
		},
	} as unknown as APIRequestContext;

	try {
		await mkdir( clientDirectory );
		await writeFile(
			join( clientDirectory, 'runtime-status.json' ),
			JSON.stringify( status )
		);

		await expect(
			loadInitialRuntimeStatus( 'client', api, directory )
		).resolves.toEqual( status );
		expect( nativeRouteCalls ).toBe( 0 );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

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

function visibleLocator(
	overrides: Partial< {
		check: () => Promise< void >;
		click: () => Promise< void >;
		fill: ( value: string ) => Promise< void >;
		getAttribute: ( name: string ) => Promise< string | null >;
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => Locator;
		selectOption: (
			option: string | { label?: string; value?: string }
		) => Promise< string[] >;
	} > = {}
): Locator {
	const locator = {
		_apiName: 'Locator',
		_expect: async () => ( { matches: true, received: 'visible' } ),
		check: overrides.check ?? ( async () => {} ),
		click: overrides.click ?? ( async () => {} ),
		fill: overrides.fill ?? ( async () => {} ),
		first: () => locator,
		getAttribute:
			overrides.getAttribute ?? ( async () => null as string | null ),
		getByRole:
			overrides.getByRole ??
			( () => {
				throw new Error( 'Unexpected nested role locator.' );
			} ),
		selectOption:
			overrides.selectOption ??
			( async () => {
				throw new Error( 'Unexpected select option.' );
			} ),
		toString: () => 'DOM-faithful fixture locator',
	};
	return locator as unknown as Locator;
}

function exactEvidence(): PaymentEvidence {
	return {
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
	};
}

function savedCheckoutContractPage( expectedSelector: string ): {
	page: Page;
	selected: () => boolean;
	waitedForConfirmation: () => boolean;
} {
	let selected = false;
	let currentUrl = 'http://native.test/checkout/';
	let waitedForConfirmation = false;
	const page = {
		goto: async () => {},
		getByRole: ( role: string, options?: { name?: string | RegExp } ) => {
			const name = String( options?.name ?? '' );
			if (
				( role === 'button' &&
					/add to cart|place order/i.test( name ) ) ||
				( role === 'link' && /checkout/i.test( name ) )
			) {
				return visibleLocator();
			}
			throw new Error( `Unexpected role locator: ${ role } ${ name }` );
		},
		getByText: ( text: string ) => {
			expect( text ).toBe( 'Your order has been received' );
			return visibleLocator();
		},
		locator: ( selector: string ) => {
			expect( selector ).toBe( expectedSelector );
			return visibleLocator( {
				check: async () => {
					selected = true;
				},
				getAttribute: async ( name ) =>
					name === 'value' ? '73' : null,
			} );
		},
		url: () => currentUrl,
		waitForURL: async ( matcher: RegExp ) => {
			expect(
				matcher.test(
					'http://native.test/checkout/order-received/42/?key=wc_order_key'
				)
			).toBe( true );
			waitedForConfirmation = true;
			currentUrl =
				'http://native.test/checkout/order-received/42/?key=wc_order_key';
		},
	} as unknown as Page;

	return {
		page,
		selected: () => selected,
		waitedForConfirmation: () => waitedForConfirmation,
	};
}

function captureContractPage(): {
	applied: () => boolean;
	page: Page;
	selected: () => boolean;
} {
	let applied = false;
	let selected = false;
	const loginField = visibleLocator();
	const page = {
		goto: async () => {},
		getByLabel: () => loginField,
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => {
			const name = String( options?.name ?? '' );
			if (
				( role === 'button' && name === 'Log In' ) ||
				( role === 'searchbox' && /search orders/i.test( name ) ) ||
				( role === 'link' && /42/.test( name ) )
			) {
				return visibleLocator();
			}
			throw new Error( `Unexpected role locator: ${ role } ${ name }` );
		},
		locator: ( selector: string ) => {
			if ( selector === 'select[name="wc_order_action"]' ) {
				return visibleLocator( {
					selectOption: async ( option ) => {
						expect( option ).toEqual( {
							label: 'Capture charge',
							value: 'capture_charge',
						} );
						selected = true;
						return [ 'capture_charge' ];
					},
				} );
			}
			if ( selector === '#actions' ) {
				return visibleLocator( {
					getByRole: ( role, options ) => {
						expect( role ).toBe( 'button' );
						expect( options ).toEqual( {
							exact: true,
							name: 'Apply',
						} );
						return visibleLocator( {
							click: async () => {
								applied = true;
							},
						} );
					},
				} );
			}
			throw new Error( `Unexpected selector: ${ selector }` );
		},
	} as unknown as Page;

	return {
		applied: () => applied,
		page,
		selected: () => selected,
	};
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
			getAttribute: async ( attribute: string ) => {
				if (
					attribute === 'href' &&
					name.includes( 'set-default-payment-method' )
				) {
					return 'http://native.test/my-account/set-default-payment-method/73/?_wpnonce=nonce';
				}
				if ( attribute === 'value' && name.includes( 'value="73"' ) ) {
					return '73';
				}
				return null;
			},
			getByRole: (
				childRole: string,
				childOptions?: { name?: string | RegExp }
			) =>
				locator( childRole, String( childOptions?.name ?? childRole ) ),
			selectOption: async () => [ 'capture_charge' ],
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
		locator: ( selector: string ) => locator( 'locator', selector ),
		goto: async ( url: string ) => {
			await maybeLoseLock( 'goto', url );
		},
		url: () => 'http://native.test/checkout/order-received/42/',
		waitForURL: async () => {},
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

test( 'uses the token-bound My Account action link rendered by Core', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const actionHref =
		'http://native.test/my-account/set-default-payment-method/73/?_wpnonce=nonce';
	let clicked = false;
	const page = {
		goto: async () => {},
		locator: ( selector: string ) => {
			expect( selector ).toBe(
				'a.button.default[href*="/set-default-payment-method/73/"]'
			);
			return visibleLocator( {
				click: async () => {
					clicked = true;
				},
				getAttribute: async ( name ) =>
					name === 'href' ? actionHref : null,
			} );
		},
	} as unknown as Page;

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-card-default-dom' },
			async () => pilotRuntime.makeSavedCardDefault( page, 73 )
		);
		expect( clicked ).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

const checkoutContracts = [
	{
		checkout: 'classic',
		selector:
			'input.woocommerce-SavedPaymentMethods-tokenInput[name="wc-woocommerce_payments-payment-token"][value="73"]',
	},
	{
		checkout: 'blocks',
		selector:
			'input.wc-block-components-radio-control__input[name="radio-control-wc-payment-method-saved-tokens"][value="73"]',
	},
] as const;

for ( const contract of checkoutContracts ) {
	test( `selects the exact local token in ${ contract.checkout } checkout markup`, async () => {
		const directory = await lockDirectory();
		const calls: RequestCall[] = [];
		const pilotRuntime = runtime( directory, calls );
		const fixture = savedCheckoutContractPage( contract.selector );

		try {
			const orderId = await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: `saved-card-${ contract.checkout }-dom` },
				async () =>
					pilotRuntime.payWithExactSavedCard(
						fixture.page,
						{
							tokenId: 73,
							paymentMethodId: 'pm_provider_only',
						},
						contract.checkout,
						'run-pilot-runtime'
					)
			);
			expect( orderId ).toBe( 42 );
			expect( fixture.selected() ).toBe( true );
			expect( fixture.waitedForConfirmation() ).toBe( true );
		} finally {
			await rm( directory, { recursive: true, force: true } );
		}
	} );
}

test( 'uses the Core order-actions dropdown and Apply button for capture', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const fixture = captureContractPage();

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'capture-order-dom' },
			async () =>
				pilotRuntime.captureExactOrder( fixture.page, exactEvidence() )
		);
		expect( fixture.selected() ).toBe( true );
		expect( fixture.applied() ).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'asserts the real payment details heading and exact evidence fields', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const evidence = exactEvidence();
	const requested: string[] = [];
	const page = {
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => {
			requested.push(
				`${ role }:${ String( options?.name ) }:${ String(
					options?.exact
				) }`
			);
			return visibleLocator();
		},
		getByText: ( text: string | RegExp, options?: { exact?: boolean } ) => {
			requested.push(
				`text:${ String( text ) }:${ String( options?.exact ) }`
			);
			return visibleLocator();
		},
	} as unknown as Page;

	try {
		await pilotRuntime.expectExactMerchantTransaction( page, evidence );
		expect( requested ).toContain(
			'heading:/^(Payment details|Transaction details)$/:undefined'
		);
		expect( requested ).toContain( 'link:Order #42:true' );
		expect( requested ).toContain( 'text:pi_exact:true' );
		expect( requested ).toContain( 'text:ch_exact:true' );
		expect( requested ).toContain( 'text:USD:true' );
		expect( requested ).toContain( 'text:Authorized:true' );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

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
				mutation: {
					role: 'locator',
					name: 'set-default-payment-method',
				},
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
				loseOn: { action: 'check', name: 'value="73"' },
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
				mutation: { role: 'button', name: 'Apply' },
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
