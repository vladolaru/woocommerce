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
	errors,
	expect,
	test,
	type APIRequestContext,
	type APIResponse,
	type Locator,
	type Page,
} from '@playwright/test';

import {
	loadInitialRuntimeStatus,
	ProviderSubmissionNotStartedError,
	ResourceQuarantineRequiredError,
	submitBlocksCheckout,
	WooPaymentsPilotRuntime,
} from '../../fixtures/woopayments-native';
import WooPaymentsKnownGapsReporter from '../../reporters/woopayments-known-gaps';
import { completeCardCheckout } from './drivers/checkout';
import {
	captureExactOrder,
	withCapturedManualCaptureSetting,
} from './drivers/manual-capture';
import { expectExactMerchantTransaction } from './drivers/merchant-transactions';
import {
	createPluginOwnedSavedCard,
	deleteExactSavedCards,
	getSavedCardState,
	makeSavedCardDefault,
	payWithExactSavedCard,
} from './drivers/saved-cards';
import { softCutOverEphemeralStore } from './drivers/store-transition';
import {
	assertResourcesUsable,
	quarantineResources,
	RESOURCE_QUARANTINE_ANNOTATION,
	type ResourceQuarantineReceipt,
} from './resource-quarantine';
import {
	findUnresolvedProviderWriteAttempts,
	openProviderWriteAttempt,
} from './provider-write-journal';
import { sha256 } from './durable-fs';
import { ResourceLock, ResourceLockManager } from './resource-locks';
import { temporaryLockDirectory, useLockDirectory } from './lock-test-helpers';
import { KNOWN_GAP_ANNOTATION, KNOWN_GAP_SENTINEL } from './known-gap';
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

function transitionStatus( callbackReady: boolean ) {
	return {
		site_url: 'http://transition.test',
		wpcom_blog_id: 321,
		runtime_owner: 'plugin',
		native_enabled: false,
		account_id: 'acct_transition',
		account_connected: true,
		gateway_enabled: true,
		test_mode: true,
		enabled_payment_methods: [ 'card' ],
		last_webhook_fetch: 0,
		callback_probe: {
			registered: callbackReady,
			reachable: callbackReady,
			wpcom_blog_id: callbackReady ? 321 : 0,
		},
	};
}

test( 'loads transition readiness without claiming callback ownership', async () => {
	const api = {
		get: async () => response( transitionStatus( false ) ),
	} as unknown as APIRequestContext;

	await expect(
		loadInitialRuntimeStatus( 'transition', api )
	).resolves.toEqual( transitionStatus( false ) );
} );

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

test( 'loads native readiness from the callback-bound setup artifact without calling the live route', async () => {
	const directory = await mkdtemp(
		join( tmpdir(), 'woopayments-native-readiness-' )
	);
	const nativeDirectory = join( directory, 'native' );
	const status = {
		site_url: 'http://store8889.localhost',
		wpcom_blog_id: 4,
		runtime_owner: 'native',
		native_enabled: true,
		account_id: 'acct_native',
		account_connected: true,
		gateway_enabled: true,
		test_mode: true,
		enabled_payment_methods: [ 'card' ],
		last_webhook_fetch: 0,
		callback_probe: {
			registered: true,
			reachable: true,
			wpcom_blog_id: 4,
		},
	} as const;
	let nativeRouteCalls = 0;
	const api = {
		get: async () => {
			nativeRouteCalls++;
			throw new Error(
				'The native adapter ignored the callback-bound artifact.'
			);
		},
	} as unknown as APIRequestContext;

	try {
		await mkdir( nativeDirectory );
		await writeFile(
			join( nativeDirectory, 'runtime-status.json' ),
			JSON.stringify( status )
		);

		await expect(
			loadInitialRuntimeStatus( 'native', api, directory )
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
		captureEvidenceError?: Error;
		captureEvidenceResponses?: Record< string, unknown >;
		manualCapture?: boolean;
		providerPaymentMethods?: unknown[];
		runtime?: 'native' | 'transition';
		savedCardEvidence?: unknown[];
		throwAfterManualCaptureUpdate?: boolean;
		failManualCaptureRestore?: boolean;
		initialManualCaptureRestoreError?: Error;
		updateStatus?: number;
		loseFeatureAfterSettingsRead?: boolean;
		loseRecordBeforeCleanupDelete?: boolean;
		productCleanupStatus?: number;
		onResourceQuarantined?: ( receipt: ResourceQuarantineReceipt ) => void;
		runtimeStatus?: unknown;
		runtimeStatuses?: unknown[];
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
			return response( {}, options.productCleanupStatus );
		},
		get: async ( url: string ) => {
			calls.push( { method: 'GET', url } );
			if ( url === '/wp-json/wc-native-payments-e2e/v1/status' ) {
				const runtimeStatus =
					options.runtimeStatuses?.shift() ?? options.runtimeStatus;
				if ( runtimeStatus === undefined ) {
					throw new Error(
						'No runtime status fixture is configured.'
					);
				}
				return response( runtimeStatus );
			}
			if (
				url.startsWith(
					'/wp-json/wc-native-payments-e2e/v1/saved-card-evidence'
				)
			) {
				const evidence = options.savedCardEvidence?.shift();
				if ( evidence === undefined ) {
					throw new Error(
						`No saved-card evidence fixture remains for ${ url }`
					);
				}
				return response( evidence );
			}
			if (
				url.startsWith( '/wp-json/wc/v3/payments/customers/' ) &&
				url.endsWith( '/payment_methods' )
			) {
				const paymentMethods = options.providerPaymentMethods?.shift();
				if ( paymentMethods === undefined ) {
					throw new Error(
						`No provider payment-method fixture remains for ${ url }`
					);
				}
				return response( paymentMethods );
			}
			if ( url === '/wp-json/wc/v3/payments/settings' ) {
				if ( options.loseFeatureAfterSettingsRead ) {
					await removeOwnedLock( lockDir, 'feature-setting' );
				}
				return response( {
					is_manual_capture_enabled: manualCapture,
				} );
			}
			if (
				url === '/wp-json/wc/v3/orders/42' &&
				options.captureEvidenceError
			) {
				throw options.captureEvidenceError;
			}
			if (
				Object.hasOwn( options.captureEvidenceResponses ?? {}, url )
			) {
				return response( options.captureEvidenceResponses?.[ url ] );
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
			if (
				options.initialManualCaptureRestoreError &&
				updateCount === 1
			) {
				throw options.initialManualCaptureRestoreError;
			}
			if ( options.failManualCaptureRestore && updateCount === 2 ) {
				throw new Error( 'Manual capture restoration failed.' );
			}
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
		options.runtime ?? 'native',
		'run-pilot-runtime',
		'http://native.test',
		123,
		'native-store',
		'acct_native',
		{
			lockDir,
			onResourceQuarantined: options.onResourceQuarantined,
		}
	);
}

async function lockDirectory(): Promise< string > {
	return temporaryLockDirectory( 'woopayments-pilot-runtime-test-' );
}

function visibleLocator(
	overrides: Partial< {
		all: () => Promise< Locator[] >;
		check: () => Promise< void >;
		click: () => Promise< void >;
		contentFrame: () => unknown;
		fill: ( value: string ) => Promise< void >;
		focus: () => Promise< void >;
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
	const locator: Locator = {
		_apiName: 'Locator',
		_expect: async () => ( { matches: true, received: 'visible' } ),
		all: overrides.all ?? ( async () => [ locator as unknown as Locator ] ),
		check: overrides.check ?? ( async () => {} ),
		click: overrides.click ?? ( async () => {} ),
		contentFrame: overrides.contentFrame,
		fill: overrides.fill ?? ( async () => {} ),
		focus: overrides.focus ?? ( async () => {} ),
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
		isVisible: async () => true,
		toString: () => 'DOM-faithful fixture locator',
	} as unknown as Locator;
	return locator;
}

function hiddenLocator(): Locator {
	return {
		...visibleLocator(),
		_expect: async () => ( { matches: false, received: 'hidden' } ),
	} as unknown as Locator;
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

function capturedPaymentResponses(
	options: {
		chargeCaptured?: boolean;
		timelineEvents?: unknown[];
	} = {}
): Record< string, unknown > {
	return {
		'/wp-json/wc/v3/orders/42': {
			id: 42,
			order_key: 'wc_order_key',
			total: '10.99',
			currency: 'USD',
			status: 'processing',
			meta_data: [
				{
					key: '_e2e_woopayments_run_id',
					value: 'run-pilot-runtime',
				},
				{ key: '_intent_id', value: 'pi_exact' },
				{ key: '_charge_id', value: 'ch_exact' },
				{ key: '_payment_method_id', value: 'pm_exact' },
			],
		},
		'/wp-json/wc/v3/payments/payment_intents/pi_exact': {
			id: 'pi_exact',
			amount: 1099,
			currency: 'usd',
			status: 'succeeded',
			payment_method: 'pm_exact',
			charges: {
				data: [ { id: 'ch_exact' } ],
			},
		},
		'/wp-json/wc/v3/payments/charges/ch_exact': {
			id: 'ch_exact',
			amount: 1099,
			currency: 'usd',
			status: 'succeeded',
			captured: options.chargeCaptured ?? true,
			payment_intent: 'pi_exact',
			payment_method: 'pm_exact',
		},
		'/wp-json/wc/v3/payments/timeline/pi_exact': {
			data: options.timelineEvents ?? [ { type: 'captured' } ],
		},
	};
}

type MockRequest = { method: () => string; url: () => string };
type MockRequestListener = ( request: MockRequest ) => void;
type MockClickOutcome = 'dispatched' | 'not-dispatched';

interface SubmissionJournalRuntime {
	withProviderSubmissionJournal< Result >(
		description: string,
		submit: () => Promise< Result >
	): Promise< Result >;
}

function submissionJournalRuntime(
	pilotRuntime: WooPaymentsPilotRuntime
): SubmissionJournalRuntime {
	return pilotRuntime as unknown as SubmissionJournalRuntime;
}

function deferred(): {
	promise: Promise< void >;
	resolve: () => void;
} {
	let resolvePromise = () => {};
	const promise = new Promise< void >( ( resolvePromiseValue ) => {
		resolvePromise = resolvePromiseValue;
	} );
	return { promise, resolve: resolvePromise };
}

function createBlocksSubmissionPage( options: {
	// requestDelayByDispatch[ n ] fires a Store API checkout POST that many
	// milliseconds after dispatched click n (1-based); undefined = no request.
	requestDelayByDispatch: Array< number | undefined >;
	clickOutcomes?: MockClickOutcome[];
	stateProbeError?: Error;
} ): {
	page: Page;
	callbackAttempts: () => number;
	dispatchedClicks: () => number;
	listenerCount: () => number;
	click: () => Promise< MockClickOutcome >;
} {
	const listeners = new Set< MockRequestListener >();
	let callbackAttempts = 0;
	let dispatchedClicks = 0;
	const emitCheckoutRequest = () => {
		const request: MockRequest = {
			method: () => 'POST',
			url: () => 'http://store.test/wp-json/wc/store/v1/checkout',
		};
		for ( const listener of listeners ) {
			listener( request );
		}
	};
	const page = {
		url: () => 'http://store.test/checkout/',
		on: ( _event: string, listener: MockRequestListener ) => {
			listeners.add( listener );
		},
		off: ( _event: string, listener: MockRequestListener ) => {
			listeners.delete( listener );
		},
		getByRole: () => ( {} as Locator ),
		waitForFunction: (
			_fn: unknown,
			_arg: unknown,
			waitOptions: { timeout: number }
		) => {
			if ( options.stateProbeError ) {
				return Promise.reject( options.stateProbeError );
			}
			return new Promise( ( _resolve, reject ) =>
				setTimeout(
					() =>
						reject(
							new errors.TimeoutError(
								'Checkout state remained idle.'
							)
						),
					waitOptions.timeout
				)
			);
		},
	} as unknown as Page;
	const click = async () => {
		callbackAttempts++;
		const outcome =
			options.clickOutcomes?.[ callbackAttempts - 1 ] ?? 'dispatched';
		if ( outcome === 'not-dispatched' ) {
			return outcome;
		}
		dispatchedClicks++;
		const delay = options.requestDelayByDispatch[ dispatchedClicks - 1 ];
		if ( delay !== undefined ) {
			setTimeout( emitCheckoutRequest, delay );
		}
		return outcome;
	};
	return {
		page,
		callbackAttempts: () => callbackAttempts,
		dispatchedClicks: () => dispatchedClicks,
		listenerCount: () => listeners.size,
		click,
	};
}

function savedCheckoutContractPage(
	expectedSelector: string,
	options: { confirmationError?: Error } = {}
): {
	page: Page;
	selected: () => boolean;
	submissions: () => number;
	visited: () => string[];
	waitedForConfirmation: () => boolean;
} {
	let selected = false;
	let currentUrl = 'http://native.test/checkout/';
	let submissions = 0;
	const visited: string[] = [];
	let waitedForConfirmation = false;
	const page = {
		goto: async ( url: string ) => {
			visited.push( url );
		},
		getByRole: (
			role: string,
			locatorOptions?: { exact?: boolean; name?: string | RegExp }
		) => {
			const name = String( locatorOptions?.name ?? '' );
			if ( role === 'button' && /add to cart/i.test( name ) ) {
				expect( locatorOptions ).toEqual( {
					name: 'Add to cart',
					exact: true,
				} );
				return visibleLocator();
			}
			if ( role === 'button' && /place order/i.test( name ) ) {
				return visibleLocator( {
					click: async () => {
						submissions++;
					},
				} );
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
		on: () => {},
		off: () => {},
		waitForFunction: async () => true,
		waitForURL: async ( matcher: RegExp ) => {
			if ( options.confirmationError ) {
				throw options.confirmationError;
			}
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
		submissions: () => submissions,
		visited: () => visited,
		waitedForConfirmation: () => waitedForConfirmation,
	};
}

function savedCardCreationPage(
	pageOptions: { successBannerVisible?: boolean } = {}
): {
	page: Page;
	submissions: () => number;
} {
	let submissions = 0;
	const frame = {
		getByPlaceholder: () => visibleLocator(),
		getByRole: () =>
			visibleLocator( {
				selectOption: async () => [ 'US' ],
			} ),
		getByLabel: () => visibleLocator(),
	};
	const submit = visibleLocator( {
		click: async () => {
			submissions++;
		},
	} );
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => visibleLocator(),
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => {
			const name = String( options?.name ?? '' );
			if ( role === 'button' && /Add payment method/.test( name ) ) {
				return submit;
			}
			return visibleLocator();
		},
		getByText: ( text: string ) =>
			text === 'Payment method successfully added.' &&
			pageOptions.successBannerVisible === false
				? hiddenLocator()
				: visibleLocator(),
		getByTitle: () =>
			visibleLocator( {
				contentFrame: () => frame,
			} ),
	} as unknown as Page;

	return {
		page,
		submissions: () => submissions,
	};
}

function savedCardDeletionPage(
	tokenId: number,
	options: { successBannerVisible?: boolean } = {}
): {
	deletions: () => number;
	page: Page;
} {
	let deletions = 0;
	const deleteAction = visibleLocator( {
		click: async () => {
			deletions++;
		},
		getAttribute: async ( name ) =>
			name === 'href'
				? `http://native.test/my-account/delete-payment-method/${ tokenId }/?_wpnonce=nonce-${ tokenId }`
				: null,
	} );
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => visibleLocator(),
		getByText: ( text: string ) =>
			text === 'Payment method deleted.' &&
			options.successBannerVisible === false
				? hiddenLocator()
				: visibleLocator(),
		getByRole: (
			role: string,
			roleOptions?: { name?: string | RegExp }
		) => {
			const name = String( roleOptions?.name ?? '' );
			if ( role === 'link' && name === 'Delete' ) {
				return visibleLocator( {
					all: async () => [ deleteAction ],
				} );
			}
			return visibleLocator();
		},
	} as unknown as Page;

	return {
		deletions: () => deletions,
		page,
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
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => loginField,
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => {
			const name = String( options?.name ?? '' );
			if (
				( role === 'button' && name === 'Log In' ) ||
				( role === 'textbox' && name === 'Password' ) ||
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
							name: /^Apply\b/,
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
			focus: async () => {},
			all: async () => [ value ],
			first: () => value,
			getAttribute: async ( attribute: string ) => {
				if (
					attribute === 'href' &&
					( name.includes( 'set-default-payment-method' ) ||
						/make default/i.test( name ) )
				) {
					return 'http://native.test/my-account/set-default-payment-method/73/?_wpnonce=nonce';
				}
				if (
					attribute === 'href' &&
					name.includes( 'Disable WooPayments' )
				) {
					return 'http://native.test/wp-admin/admin.php?wc_woopayments_cutover_action=disable_woopayments&_wc_woopayments_cutover_nonce=nonce';
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
			isVisible: async () => role !== 'group',
			toString: () => `fake locator ${ role } ${ name }`,
		};
		return value;
	};

	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		getByLabel: ( name: string | RegExp ) =>
			locator( 'label', String( name ) ),
		getByRole: ( role: string, roleOptions?: { name?: string | RegExp } ) =>
			locator( role, String( roleOptions?.name ?? role ) ),
		getByText: ( name: string | RegExp ) =>
			locator( 'text', String( name ) ),
		frameLocator: () => ( {
			locator: ( selector: string ) => locator( 'frame-field', selector ),
		} ),
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

test( 'creates two plugin-owned cards from exact token diffs and returns distinct durable IDs', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: true,
					},
				],
			},
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: false,
					},
					{
						token_id: 73,
						payment_method_id: 'pm_first_exact',
						is_default: true,
					},
				],
			},
			{
				creation_ready: false,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: false,
					},
					{
						token_id: 73,
						payment_method_id: 'pm_first_exact',
						is_default: true,
					},
				],
			},
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: false,
					},
					{
						token_id: 73,
						payment_method_id: 'pm_first_exact',
						is_default: true,
					},
				],
			},
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: false,
					},
					{
						token_id: 73,
						payment_method_id: 'pm_first_exact',
						is_default: false,
					},
					{
						token_id: 81,
						payment_method_id: 'pm_second_exact',
						is_default: true,
					},
				],
			},
		],
	} );
	const fixture = savedCardCreationPage();

	try {
		const cards = await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-card-exact-diff' },
			async () => [
				await createPluginOwnedSavedCard(
					pilotRuntime,
					fixture.page,
					'first card'
				),
				await createPluginOwnedSavedCard(
					pilotRuntime,
					fixture.page,
					'second card'
				),
			]
		);

		expect( cards ).toEqual( [
			{ tokenId: 73, paymentMethodId: 'pm_first_exact' },
			{ tokenId: 81, paymentMethodId: 'pm_second_exact' },
		] );
		expect( fixture.submissions() ).toBe( 2 );
		expect( cards[ 0 ] ).not.toEqual( cards[ 1 ] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

const savedCardProviderResourceKeys = [
	'acct_native/account:provider-writes',
	'acct_native/native-store/store:native-store',
];

test( 'keeps an uncertain saved-card creation attempt in the durable journal', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [],
			},
		],
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const fixture = savedCardCreationPage( {
		successBannerVisible: false,
	} );

	try {
		let creationError: unknown;
		try {
			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'uncertain-saved-card-create' },
				async () => {
					try {
						await createPluginOwnedSavedCard(
							pilotRuntime,
							fixture.page,
							'uncertain card'
						);
					} catch ( error ) {
						creationError = error;
						throw error;
					}
				}
			);
		} catch {}

		expect( fixture.submissions() ).toBe( 1 );
		expect( creationError ).toBeInstanceOf(
			ResourceQuarantineRequiredError
		);
		const attempts = await findUnresolvedProviderWriteAttempts(
			directory,
			savedCardProviderResourceKeys,
			'a-later-run'
		);
		expect( attempts ).toHaveLength( 1 );
		expect( attempts[ 0 ] ).toMatchObject( {
			description: 'plugin-saved-card-create',
		} );
		expect( creationError ).toMatchObject( {
			reasonCode: 'uncertain-provider-write',
		} );
		for ( const key of savedCardProviderResourceKeys ) {
			expect( annotated ).toContainEqual(
				expect.objectContaining( {
					reasonCode: 'uncertain-provider-write',
					resourceKeyHash: sha256( key ),
				} )
			);
			await expect(
				assertResourcesUsable( [ key ], directory )
			).rejects.toThrow( /quarantined/i );
		}
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'keeps an uncertain saved-card deletion attempt in the durable journal', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const fixture = savedCardDeletionPage( 73, {
		successBannerVisible: false,
	} );

	try {
		let deletionError: unknown;
		try {
			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'uncertain-saved-card-delete' },
				async () => {
					try {
						await deleteExactSavedCards(
							pilotRuntime,
							fixture.page,
							[
								{
									tokenId: 73,
									paymentMethodId: 'pm_uncertain',
								},
							],
							'cus_exact'
						);
					} catch ( error ) {
						deletionError = error;
						throw error;
					}
				}
			);
		} catch {}

		expect( fixture.deletions() ).toBe( 1 );
		expect( deletionError ).toBeInstanceOf(
			ResourceQuarantineRequiredError
		);
		const attempts = await findUnresolvedProviderWriteAttempts(
			directory,
			savedCardProviderResourceKeys,
			'a-later-run'
		);
		expect( attempts ).toHaveLength( 1 );
		expect( attempts[ 0 ] ).toMatchObject( {
			description: 'plugin-saved-card-delete',
		} );
		expect( deletionError ).toMatchObject( {
			reasonCode: 'uncertain-provider-write',
		} );
		for ( const key of savedCardProviderResourceKeys ) {
			expect( annotated ).toContainEqual(
				expect.objectContaining( {
					reasonCode: 'uncertain-provider-write',
					resourceKeyHash: sha256( key ),
				} )
			);
			await expect(
				assertResourcesUsable( [ key ], directory )
			).rejects.toThrow( /quarantined/i );
		}
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a clean saved-card creation resolves its journal attempt', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [],
			},
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 73,
						payment_method_id: 'pm_created',
						is_default: true,
					},
				],
			},
		],
	} );
	const fixture = savedCardCreationPage();

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'clean-saved-card-create' },
			async () =>
				createPluginOwnedSavedCard(
					pilotRuntime,
					fixture.page,
					'clean card'
				)
		);

		expect( fixture.submissions() ).toBe( 1 );
		expect(
			await readdir( join( directory, 'provider-write-attempts' ) )
		).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

for ( const invalidDiff of [
	{
		name: 'zero new tokens',
		after: [
			{
				token_id: 7,
				payment_method_id: 'pm_existing',
				is_default: true,
			},
		],
	},
	{
		name: 'multiple new tokens',
		after: [
			{
				token_id: 7,
				payment_method_id: 'pm_existing',
				is_default: false,
			},
			{
				token_id: 73,
				payment_method_id: 'pm_new_one',
				is_default: true,
			},
			{
				token_id: 81,
				payment_method_id: 'pm_new_two',
				is_default: false,
			},
		],
	},
] ) {
	test( `rejects ${ invalidDiff.name } after a plugin-owned saved-card submission`, async () => {
		const directory = await lockDirectory();
		const calls: RequestCall[] = [];
		const pilotRuntime = runtime( directory, calls, {
			savedCardEvidence: [
				{
					creation_ready: true,
					tokens: [
						{
							token_id: 7,
							payment_method_id: 'pm_existing',
							is_default: true,
						},
					],
				},
				{
					creation_ready: true,
					tokens: invalidDiff.after,
				},
			],
		} );
		const fixture = savedCardCreationPage();

		try {
			await expect(
				pilotRuntime.withProviderWriteLocks(
					{ recordEvent: 'saved-card-invalid-diff' },
					async () =>
						createPluginOwnedSavedCard(
							pilotRuntime,
							fixture.page,
							invalidDiff.name
						)
				)
			).rejects.toThrow( /exactly one new.*token/i );
			expect( fixture.submissions() ).toBe( 1 );
			for ( const key of [
				'acct_native/account:provider-writes',
				'acct_native/native-store/store:native-store',
				'acct_native/native-store/record-event:saved-card-invalid-diff',
			] ) {
				await expect(
					assertResourcesUsable( [ key ], directory )
				).rejects.toThrow( /quarantined/i );
			}
		} finally {
			await rm( directory, { recursive: true, force: true } );
		}
	} );
}

test( 'rejects malformed saved-card token evidence before submitting', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [
					{
						token_id: '73',
						payment_method_id: 'pm_not_numeric',
						is_default: true,
					},
				],
			},
		],
	} );
	const fixture = savedCardCreationPage();

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'saved-card-malformed-evidence' },
				async () =>
					createPluginOwnedSavedCard(
						pilotRuntime,
						fixture.page,
						'malformed evidence'
					)
			)
		).rejects.toThrow( /token_id.*positive integer/i );
		expect( fixture.submissions() ).toBe( 0 );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'proves both exact saved-card mappings and the second-card default after native cutover', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				provider_customer_id: 'cus_exact',
				tokens: [
					{
						token_id: 41,
						payment_method_id: 'pm_first',
						is_default: false,
					},
					{
						token_id: 73,
						payment_method_id: 'pm_second',
						is_default: true,
					},
				],
			},
		],
		providerPaymentMethods: [
			[
				{ id: 'pm_first', type: 'card' },
				{ id: 'pm_second', type: 'card' },
			],
		],
	} );

	try {
		const state = await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-card-state-exact' },
			async () =>
				getSavedCardState( pilotRuntime, [
					{
						tokenId: 41,
						paymentMethodId: 'pm_first',
					},
					{
						tokenId: 73,
						paymentMethodId: 'pm_second',
					},
				] )
		);

		expect( state ).toEqual( {
			tokenId: 73,
			paymentMethodId: 'pm_second',
			isDefault: true,
			providerCustomerId: 'cus_exact',
		} );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

for ( const invalidState of [
	{
		name: 'a mismatched first local payment-method ID',
		evidence: {
			creation_ready: true,
			provider_customer_id: 'cus_exact',
			tokens: [
				{
					token_id: 41,
					payment_method_id: 'pm_wrong',
					is_default: false,
				},
				{
					token_id: 73,
					payment_method_id: 'pm_second',
					is_default: true,
				},
			],
		},
		providerMethods: [
			{ id: 'pm_first', type: 'card' },
			{ id: 'pm_second', type: 'card' },
		],
		error: /local token 41.*pm_first/i,
	},
	{
		name: 'the first local token still marked default',
		evidence: {
			creation_ready: true,
			provider_customer_id: 'cus_exact',
			tokens: [
				{
					token_id: 41,
					payment_method_id: 'pm_first',
					is_default: true,
				},
				{
					token_id: 73,
					payment_method_id: 'pm_second',
					is_default: true,
				},
			],
		},
		providerMethods: [
			{ id: 'pm_first', type: 'card' },
			{ id: 'pm_second', type: 'card' },
		],
		error: /local token 41.*must not.*default/i,
	},
	{
		name: 'a missing first provider payment method',
		evidence: {
			creation_ready: true,
			provider_customer_id: 'cus_exact',
			tokens: [
				{
					token_id: 41,
					payment_method_id: 'pm_first',
					is_default: false,
				},
				{
					token_id: 73,
					payment_method_id: 'pm_second',
					is_default: true,
				},
			],
		},
		providerMethods: [ { id: 'pm_second', type: 'card' } ],
		error: /exactly one provider payment method.*pm_first/i,
	},
] ) {
	test( `rejects saved-card state with ${ invalidState.name }`, async () => {
		const directory = await lockDirectory();
		const calls: RequestCall[] = [];
		const pilotRuntime = runtime( directory, calls, {
			savedCardEvidence: [ invalidState.evidence ],
			providerPaymentMethods: [ invalidState.providerMethods ],
		} );

		try {
			await expect(
				pilotRuntime.withProviderWriteLocks(
					{ recordEvent: 'saved-card-invalid-state' },
					async () =>
						getSavedCardState( pilotRuntime, [
							{
								tokenId: 41,
								paymentMethodId: 'pm_first',
							},
							{
								tokenId: 73,
								paymentMethodId: 'pm_second',
							},
						] )
				)
			).rejects.toThrow( invalidState.error );
		} finally {
			await rm( directory, { recursive: true, force: true } );
		}
	} );
}

test( 'uses the token-bound semantic My Account action rendered by Core', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const actionHref =
		'http://native.test/my-account/set-default-payment-method/73/?_wpnonce=nonce';
	let clicked = false;
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => visibleLocator(),
		getByRole: ( role: string, options?: { name?: string | RegExp } ) => {
			if (
				role === 'link' &&
				/make default/i.test( String( options?.name ) )
			) {
				return visibleLocator( {
					all: async () => [
						visibleLocator( {
							getAttribute: async ( name ) =>
								name === 'href'
									? 'http://native.test/my-account/set-default-payment-method/22/?_wpnonce=other'
									: null,
						} ),
						visibleLocator( {
							click: async () => {
								clicked = true;
							},
							getAttribute: async ( name ) =>
								name === 'href' ? actionHref : null,
						} ),
					],
				} );
			}
			return visibleLocator();
		},
	} as unknown as Page;

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-card-default-dom' },
			async () => makeSavedCardDefault( pilotRuntime, page, 73 )
		);
		expect( clicked ).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'deletes only the two exact run-owned saved cards through My Account', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		providerPaymentMethods: [ [] ],
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [
					{
						token_id: 7,
						payment_method_id: 'pm_existing',
						is_default: true,
					},
				],
			},
		],
	} );
	const remainingTokenIds = new Set( [ 7, 73, 81 ] );
	const deletedTokenIds: number[] = [];
	const deleteAction = ( tokenId: number ) =>
		visibleLocator( {
			click: async () => {
				deletedTokenIds.push( tokenId );
				remainingTokenIds.delete( tokenId );
			},
			getAttribute: async ( name ) =>
				name === 'href'
					? `http://native.test/my-account/delete-payment-method/${ tokenId }/?_wpnonce=nonce-${ tokenId }`
					: null,
		} );
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => visibleLocator(),
		getByText: () => visibleLocator(),
		getByRole: ( role: string, options?: { name?: string | RegExp } ) => {
			const name = String( options?.name ?? '' );
			if ( role === 'link' && name === 'Delete' ) {
				return visibleLocator( {
					all: async () =>
						[ ...remainingTokenIds ].map( deleteAction ),
				} );
			}
			if (
				( role === 'button' && name === 'Log In' ) ||
				( role === 'textbox' && name === 'Password' ) ||
				( role === 'textbox' && /Email address/i.test( name ) )
			) {
				return visibleLocator();
			}
			throw new Error( `Unexpected role locator: ${ role } ${ name }` );
		},
	} as unknown as Page;

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-card-cleanup' },
			async () =>
				deleteExactSavedCards(
					pilotRuntime,
					page,
					[
						{ tokenId: 73, paymentMethodId: 'pm_first' },
						{ tokenId: 81, paymentMethodId: 'pm_second' },
					],
					'cus_exact'
				)
		);

		expect( deletedTokenIds ).toEqual( [ 81, 73 ] );
		expect( remainingTokenIds ).toEqual( new Set( [ 7 ] ) );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'quarantines saved-card resources while an exact provider payment method remains attached', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		savedCardEvidence: [
			{
				creation_ready: true,
				tokens: [],
			},
		],
		providerPaymentMethods: [ [ { id: 'pm_first' } ] ],
	} );
	const remainingTokenIds = new Set( [ 73, 81 ] );
	const deleteAction = ( tokenId: number ) =>
		visibleLocator( {
			click: async () => {
				remainingTokenIds.delete( tokenId );
			},
			getAttribute: async ( name ) =>
				name === 'href'
					? `http://native.test/my-account/delete-payment-method/${ tokenId }/?_wpnonce=nonce-${ tokenId }`
					: null,
		} );
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () => visibleLocator(),
		getByText: () => visibleLocator(),
		getByRole: ( role: string, options?: { name?: string | RegExp } ) => {
			const name = String( options?.name ?? '' );
			if ( role === 'link' && name === 'Delete' ) {
				return visibleLocator( {
					all: async () =>
						[ ...remainingTokenIds ].map( deleteAction ),
				} );
			}
			if (
				( role === 'button' && name === 'Log In' ) ||
				( role === 'textbox' && name === 'Password' ) ||
				( role === 'textbox' && /Email address/i.test( name ) )
			) {
				return visibleLocator();
			}
			throw new Error( `Unexpected role locator: ${ role } ${ name }` );
		},
	} as unknown as Page;
	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'saved-card-provider-cleanup' },
				async () =>
					deleteExactSavedCards(
						pilotRuntime,
						page,
						[
							{
								tokenId: 73,
								paymentMethodId: 'pm_first',
							},
							{
								tokenId: 81,
								paymentMethodId: 'pm_second',
							},
						],
						'cus_exact'
					)
			)
		).rejects.toThrow( /provider payment method pm_first.*attached/i );
		for ( const key of [
			'acct_native/account:provider-writes',
			'acct_native/native-store/store:native-store',
			'acct_native/native-store/record-event:saved-card-provider-cleanup',
		] ) {
			await expect(
				assertResourcesUsable( [ key ], directory )
			).rejects.toThrow( /quarantined/i );
		}
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
					payWithExactSavedCard(
						pilotRuntime,
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
			expect( fixture.visited() ).toContain(
				contract.checkout === 'classic'
					? 'classic-checkout/'
					: 'checkout/'
			);
			expect( fixture.waitedForConfirmation() ).toBe( true );
		} finally {
			await rm( directory, { recursive: true, force: true } );
		}
	} );
}

test( 'refuses to retry when a dispatched checkout request crosses the observation boundary', async () => {
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [ 150 ],
	} );

	await expect(
		submitBlocksCheckout( mock.page, mock.click, {
			submissionWaitMs: 100,
		} )
	).rejects.toThrow( /Refusing to retry.*provider outcome is uncertain/i );
	await new Promise( ( resolveDelay ) => setTimeout( resolveDelay, 100 ) );

	expect( mock.callbackAttempts() ).toBe( 1 );
	expect( mock.dispatchedClicks() ).toBe( 1 );
	expect( mock.listenerCount() ).toBe( 0 );
} );

test( 'propagates a non-timeout checkout-state failure without retrying', async () => {
	const stateProbeError = new Error( 'Checkout state probe failed.' );
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [ undefined ],
		stateProbeError,
	} );

	await expect(
		submitBlocksCheckout( mock.page, mock.click, {
			submissionWaitMs: 100,
		} )
	).rejects.toBe( stateProbeError );

	expect( mock.callbackAttempts() ).toBe( 1 );
	expect( mock.dispatchedClicks() ).toBe( 1 );
	expect( mock.listenerCount() ).toBe( 0 );
} );

test( 'retries one explicit not-dispatched outcome without a second provider write', async () => {
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [ 10 ],
		clickOutcomes: [ 'not-dispatched', 'dispatched' ],
	} );
	const startedAt = Date.now();

	await submitBlocksCheckout( mock.page, mock.click, {
		submissionWaitMs: 200,
	} );

	expect( Date.now() - startedAt ).toBeLessThan( 150 );
	expect( mock.callbackAttempts() ).toBe( 2 );
	expect( mock.dispatchedClicks() ).toBe( 1 );
	expect( mock.listenerCount() ).toBe( 0 );
} );

test( 'fails after three explicit not-dispatched outcomes without a provider write', async () => {
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [],
		clickOutcomes: [ 'not-dispatched', 'not-dispatched', 'not-dispatched' ],
	} );

	let submissionError: unknown;
	try {
		await submitBlocksCheckout( mock.page, mock.click, {
			submissionWaitMs: 20,
		} );
	} catch ( error ) {
		submissionError = error;
	}
	expect( submissionError ).toBeInstanceOf(
		ProviderSubmissionNotStartedError
	);
	expect( ( submissionError as Error ).message ).toMatch(
		/was not dispatched after 3 explicit not-dispatched attempts/
	);
	expect( mock.callbackAttempts() ).toBe( 3 );
	expect( mock.dispatchedClicks() ).toBe( 0 );
	expect( mock.listenerCount() ).toBe( 0 );
} );

test( 'returns promptly when a slow checkout request starts inside the observation window', async () => {
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [ 50 ],
	} );
	const startedAt = Date.now();

	await submitBlocksCheckout( mock.page, mock.click, {
		submissionWaitMs: 400,
	} );

	expect( Date.now() - startedAt ).toBeLessThan( 300 );
	expect( mock.callbackAttempts() ).toBe( 1 );
	expect( mock.dispatchedClicks() ).toBe( 1 );
	expect( mock.listenerCount() ).toBe( 0 );
} );

test( 'quarantines every held lock when a prior provider write attempt overlaps the account and store', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	let callbackInvoked = false;

	try {
		await openProviderWriteAttempt( directory, {
			version: 1,
			runId: 'dead-run',
			resourceKeys: [
				'acct_native/account:provider-writes',
				'acct_native/native-store/store:native-store',
			],
			description: 'saved-card-classic-checkout',
			startedAt: 1753790000000,
		} );

		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'prior-provider-write-attempt' },
				async () => {
					callbackInvoked = true;
				}
			)
		).rejects.toThrow( /prior provider write.*no proven outcome/i );

		expect( callbackInvoked ).toBe( false );
		expect( annotated ).toHaveLength( 3 );
		expect(
			annotated.every(
				( receipt ) => receipt.reasonCode === 'uncertain-provider-write'
			)
		).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'quarantines every held lock when provider-write acquisition displaces an expired owner', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const displacedKey = 'acct_native/account:provider-writes';
	const expiredOwner = new ResourceLockManager( {
		lockDir: directory,
		runId: 'dead-run',
		leaseMs: 10,
		autoRenew: false,
	} );
	let callbackInvoked = false;

	try {
		await expiredOwner.acquire( {
			providerAccountId: 'acct_native',
			storeId: 'native-store',
			kind: 'account',
			resource: 'provider-writes',
			diagnosticPath: 'test-results/dead-run',
		} );
		await new Promise( ( resolveDelay ) => setTimeout( resolveDelay, 25 ) );

		let quarantineError: unknown;
		try {
			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'displaced-provider-owner' },
				async () => {
					callbackInvoked = true;
				}
			);
		} catch ( error ) {
			quarantineError = error;
		}

		expect( callbackInvoked ).toBe( false );
		expect( quarantineError ).toBeInstanceOf(
			ResourceQuarantineRequiredError
		);
		expect( quarantineError ).toMatchObject( {
			reasonCode: 'uncertain-provider-write',
		} );
		expect( ( quarantineError as Error ).message ).toContain(
			`sha256:${ sha256( displacedKey ) }`
		);
		expect( ( quarantineError as Error ).message ).not.toContain(
			displacedKey
		);
		expect( annotated ).toHaveLength( 3 );
		expect(
			annotated.every(
				( receipt ) => receipt.reasonCode === 'uncertain-provider-write'
			)
		).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'preserves displaced-owner quarantine when a later lock acquisition fails', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const displacedKey = 'acct_native/account:provider-writes';
	const laterKey = 'acct_native/native-store/store:native-store';
	const expiredOwner = new ResourceLockManager( {
		lockDir: directory,
		runId: 'dead-run',
		leaseMs: 10,
		autoRenew: false,
	} );
	const originalAcquire = ResourceLockManager.prototype.acquire;
	let callbackInvoked = false;

	try {
		await expiredOwner.acquire( {
			providerAccountId: 'acct_native',
			storeId: 'native-store',
			kind: 'account',
			resource: 'provider-writes',
			diagnosticPath: 'test-results/dead-run',
		} );
		await new Promise( ( resolveDelay ) => setTimeout( resolveDelay, 25 ) );
		const acquisitionSteps: Array< typeof originalAcquire > = [
			originalAcquire,
			async function () {
				throw new Error(
					`Forced later acquisition failure for ${ laterKey }.`
				);
			},
		];
		let acquisitionStep = 0;
		ResourceLockManager.prototype.acquire = function ( request ) {
			return acquisitionSteps[ acquisitionStep++ ].call( this, request );
		};

		let quarantineError: unknown;
		try {
			await pilotRuntime.withProviderWriteLocks( {}, async () => {
				callbackInvoked = true;
			} );
		} catch ( error ) {
			quarantineError = error;
		}

		expect( callbackInvoked ).toBe( false );
		expect( quarantineError ).toBeInstanceOf(
			ResourceQuarantineRequiredError
		);
		expect( quarantineError ).toMatchObject( {
			reasonCode: 'uncertain-provider-write',
		} );
		expect( ( quarantineError as Error ).message ).toContain(
			`sha256:${ sha256( displacedKey ) }`
		);
		expect( ( quarantineError as Error ).message ).not.toContain(
			displacedKey
		);
		expect( ( quarantineError as Error ).message ).not.toContain(
			laterKey
		);
		expect( annotated ).toHaveLength( 1 );
		expect( annotated[ 0 ].reasonCode ).toBe( 'uncertain-provider-write' );
	} finally {
		ResourceLockManager.prototype.acquire = originalAcquire;
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'removes the provider write attempt after successful submission and confirmation', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const fixture = savedCheckoutContractPage(
		checkoutContracts[ 0 ].selector
	);

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'successful-journaled-checkout' },
			async () =>
				payWithExactSavedCard(
					pilotRuntime,
					fixture.page,
					{ tokenId: 73, paymentMethodId: 'pm_provider_only' },
					'classic',
					'run-pilot-runtime'
				)
		);

		expect(
			await readdir( join( directory, 'provider-write-attempts' ) )
		).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'removes the provider write attempt after three proven not-dispatched outcomes', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const mock = createBlocksSubmissionPage( {
		requestDelayByDispatch: [],
		clickOutcomes: [ 'not-dispatched', 'not-dispatched', 'not-dispatched' ],
	} );
	const journalRuntime = submissionJournalRuntime( pilotRuntime );

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks( {}, async () =>
				journalRuntime.withProviderSubmissionJournal(
					'blocks-not-dispatched',
					async () =>
						submitBlocksCheckout( mock.page, mock.click, {
							submissionWaitMs: 20,
						} )
				)
			)
		).rejects.toThrow(
			/was not dispatched after 3 explicit not-dispatched attempts/
		);
		expect(
			await readdir( join( directory, 'provider-write-attempts' ) )
		).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'keeps a dispatched unconfirmed attempt and quarantines it in the current lock scope', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const fixture = savedCheckoutContractPage(
		checkoutContracts[ 0 ].selector,
		{ confirmationError: new Error( 'Order confirmation timed out.' ) }
	);

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'uncertain-journaled-checkout' },
				async () =>
					payWithExactSavedCard(
						pilotRuntime,
						fixture.page,
						{
							tokenId: 73,
							paymentMethodId: 'pm_provider_only',
						},
						'classic',
						'run-pilot-runtime'
					)
			)
		).rejects.toThrow( /confirmation timed out/i );

		expect(
			await findUnresolvedProviderWriteAttempts(
				directory,
				[
					'acct_native/account:provider-writes',
					'acct_native/native-store/store:native-store',
				],
				'a-later-run'
			)
		).toHaveLength( 1 );
		expect( annotated ).toHaveLength( 3 );
		expect(
			annotated.every(
				( receipt ) => receipt.reasonCode === 'uncertain-provider-write'
			)
		).toBe( true );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'serializes concurrent provider submissions and holds locks until they all settle', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const journalRuntime = submissionJournalRuntime( pilotRuntime );
	const releaseFirst = deferred();
	const releaseSecond = deferred();
	const firstStarted = deferred();
	const secondStarted = deferred();
	const attemptNames = new Set< string >();
	let activeSubmissions = 0;
	let maximumActiveSubmissions = 0;
	let lockScopeSettled = false;
	let firstSubmission: Promise< void > | undefined;
	let secondSubmission: Promise< void > | undefined;
	let lockScope: Promise< void > | undefined;
	let firstLockCount = -1;
	let secondLockCount = -1;
	let settledBeforeFirstRelease = false;
	let settledBeforeSecondRelease = false;

	try {
		lockScope = pilotRuntime.withProviderWriteLocks( {}, async () => {
			firstSubmission = journalRuntime.withProviderSubmissionJournal(
				'concurrent-first',
				async () => {
					activeSubmissions++;
					maximumActiveSubmissions = Math.max(
						maximumActiveSubmissions,
						activeSubmissions
					);
					for ( const entry of await readdir(
						join( directory, 'provider-write-attempts' )
					) ) {
						if ( entry.endsWith( '.json' ) ) {
							attemptNames.add( entry );
						}
					}
					firstStarted.resolve();
					try {
						await releaseFirst.promise;
					} finally {
						activeSubmissions--;
					}
				}
			);
			void firstSubmission.catch( () => {} );
			secondSubmission = journalRuntime.withProviderSubmissionJournal(
				'concurrent-second',
				async () => {
					activeSubmissions++;
					maximumActiveSubmissions = Math.max(
						maximumActiveSubmissions,
						activeSubmissions
					);
					for ( const entry of await readdir(
						join( directory, 'provider-write-attempts' )
					) ) {
						if ( entry.endsWith( '.json' ) ) {
							attemptNames.add( entry );
						}
					}
					secondStarted.resolve();
					try {
						await releaseSecond.promise;
					} finally {
						activeSubmissions--;
					}
				}
			);
			void secondSubmission.catch( () => {} );
		} );
		void lockScope.then(
			() => {
				lockScopeSettled = true;
			},
			() => {
				lockScopeSettled = true;
			}
		);

		await firstStarted.promise;
		settledBeforeFirstRelease = lockScopeSettled;
		firstLockCount = ( await readdir( directory ) ).filter( ( entry ) =>
			entry.endsWith( '.lock' )
		).length;
		releaseFirst.resolve();

		await secondStarted.promise;
		settledBeforeSecondRelease = lockScopeSettled;
		secondLockCount = ( await readdir( directory ) ).filter( ( entry ) =>
			entry.endsWith( '.lock' )
		).length;
		releaseSecond.resolve();

		await lockScope;
		await Promise.all( [ firstSubmission, secondSubmission ] );

		expect( maximumActiveSubmissions ).toBe( 1 );
		expect( attemptNames.size ).toBe( 2 );
		expect( settledBeforeFirstRelease ).toBe( false );
		expect( settledBeforeSecondRelease ).toBe( false );
		expect( firstLockCount ).toBe( 2 );
		expect( secondLockCount ).toBe( 2 );
	} finally {
		releaseFirst.resolve();
		releaseSecond.resolve();
		await Promise.allSettled(
			[ firstSubmission, secondSubmission, lockScope ].filter(
				( value ): value is Promise< void > => value !== undefined
			)
		);
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'blocks queued provider submissions after uncertainty and waits before quarantine teardown', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const journalRuntime = submissionJournalRuntime( pilotRuntime );
	const releaseUncertainSubmission = deferred();
	const uncertainSubmissionStarted = deferred();
	let firstSubmission: Promise< void > | undefined;
	let secondSubmission: Promise< void > | undefined;
	let secondSubmissionClicks = 0;
	let lockScope: Promise< void > | undefined;
	let lockScopeSettled = false;
	let locksHeldWhileSubmissionPending = -1;

	try {
		lockScope = pilotRuntime.withProviderWriteLocks( {}, async () => {
			firstSubmission = journalRuntime.withProviderSubmissionJournal(
				'uncertain-first',
				async () => {
					uncertainSubmissionStarted.resolve();
					await releaseUncertainSubmission.promise;
					throw new Error(
						'First provider submission outcome is uncertain.'
					);
				}
			);
			void firstSubmission.catch( () => {} );
			secondSubmission = journalRuntime.withProviderSubmissionJournal(
				'queued-second',
				async () => {
					secondSubmissionClicks++;
				}
			);
			void secondSubmission.catch( () => {} );
		} );
		void lockScope.then(
			() => {
				lockScopeSettled = true;
			},
			() => {
				lockScopeSettled = true;
			}
		);

		await uncertainSubmissionStarted.promise;
		locksHeldWhileSubmissionPending = ( await readdir( directory ) ).filter(
			( entry ) => entry.endsWith( '.lock' )
		).length;
		expect( lockScopeSettled ).toBe( false );
		releaseUncertainSubmission.resolve();

		await expect( lockScope ).rejects.toThrow(
			/First provider submission outcome is uncertain/
		);
		await Promise.allSettled( [ firstSubmission, secondSubmission ] );

		expect( locksHeldWhileSubmissionPending ).toBe( 2 );
		expect( secondSubmissionClicks ).toBe( 0 );
		expect( annotated ).toHaveLength( 2 );
		expect(
			annotated.every(
				( receipt ) => receipt.reasonCode === 'uncertain-provider-write'
			)
		).toBe( true );
		expect(
			await findUnresolvedProviderWriteAttempts(
				directory,
				[
					'acct_native/account:provider-writes',
					'acct_native/native-store/store:native-store',
				],
				'a-later-run'
			)
		).toHaveLength( 1 );
	} finally {
		releaseUncertainSubmission.resolve();
		await Promise.allSettled(
			[ firstSubmission, secondSubmission, lockScope ].filter(
				( value ): value is Promise< void > => value !== undefined
			)
		);
		await rm( directory, { recursive: true, force: true } );
	}
} );

const manualCaptureResourceKeys = [
	'acct_native/account:provider-writes',
	'acct_native/native-store/store:native-store',
	'acct_native/native-store/feature-setting:manual-capture',
	'acct_native/native-store/record-event:merchant-manual-capture',
];

async function expectUncertainManualCapture(
	directory: string,
	pilotRuntime: WooPaymentsPilotRuntime,
	fixture: ReturnType< typeof captureContractPage >,
	annotated: ResourceQuarantineReceipt[]
): Promise< unknown > {
	let captureError: unknown;
	try {
		await withCapturedManualCaptureSetting( pilotRuntime, async () => {
			await captureExactOrder(
				pilotRuntime,
				fixture.page,
				exactEvidence()
			);
		} );
	} catch ( error ) {
		captureError = error;
	}

	expect( fixture.applied() ).toBe( true );
	expect(
		await findUnresolvedProviderWriteAttempts(
			directory,
			manualCaptureResourceKeys.slice( 0, 2 ),
			'a-later-run'
		)
	).toHaveLength( 1 );
	expect( annotated ).toHaveLength( 4 );
	expect(
		annotated.every(
			( receipt ) => receipt.reasonCode === 'uncertain-provider-write'
		)
	).toBe( true );
	for ( const key of manualCaptureResourceKeys ) {
		await expect(
			assertResourcesUsable( [ key ], directory )
		).rejects.toThrow( /quarantined/i );
	}
	return captureError;
}

test( 'uses the Core order-actions dropdown and Apply button for capture', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		captureEvidenceResponses: capturedPaymentResponses(),
	} );
	const fixture = captureContractPage();

	try {
		const captured: unknown = await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'capture-order-dom' },
			async () =>
				captureExactOrder( pilotRuntime, fixture.page, exactEvidence() )
		);
		expect( fixture.selected() ).toBe( true );
		expect( fixture.applied() ).toBe( true );
		expect( captured ).toEqual( {
			runId: 'run-pilot-runtime',
			orderId: 42,
			orderKey: 'wc_order_key',
			intentId: 'pi_exact',
			chargeId: 'ch_exact',
			paymentMethodId: 'pm_exact',
			amountMinor: 1099,
			currency: 'USD',
			orderStatus: 'processing',
			providerStatus: 'succeeded',
			chargeStatus: 'succeeded',
			chargeCaptured: true,
			occurrenceCount: 1,
			captureOccurrenceCount: 1,
		} );
		expect(
			await findUnresolvedProviderWriteAttempts(
				directory,
				[
					'acct_native/account:provider-writes',
					'acct_native/native-store/store:native-store',
				],
				'a-later-run'
			)
		).toEqual( [] );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'keeps an uncertain manual-capture attempt and quarantines every held resource', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const captureEvidenceError = new Error(
		'Capture proof response was lost.'
	);
	const pilotRuntime = runtime( directory, calls, {
		captureEvidenceError,
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const fixture = captureContractPage();

	try {
		const captureError = await expectUncertainManualCapture(
			directory,
			pilotRuntime,
			fixture,
			annotated
		);
		expect( captureError ).toBe( captureEvidenceError );
	} finally {
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'keeps an uncertain manual-capture attempt when succeeded provider evidence contradicts exact capture state', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		captureEvidenceResponses: capturedPaymentResponses( {
			chargeCaptured: false,
			timelineEvents: [],
		} ),
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const fixture = captureContractPage();

	try {
		const captureError = await expectUncertainManualCapture(
			directory,
			pilotRuntime,
			fixture,
			annotated
		);
		expect( captureError ).toBeInstanceOf( Error );
		expect( ( captureError as Error ).message ).toBe(
			'Manual capture evidence mismatch for chargeCaptured: expected true, received false.'
		);
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
		await expectExactMerchantTransaction( pilotRuntime, page, evidence );
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
					virtual: true,
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
			wrapper: 'withCapturedManualCaptureSetting( pilotRuntime',
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
				await completeCardCheckout(
					pilotRuntime,
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
					await completeCardCheckout(
						pilotRuntime,
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
				await makeSavedCardDefault( pilotRuntime, page, 73 );
			}
		)
	).resolves.toBeUndefined();
} );

test( 'drives the nonce-protected product cutover controller entry point', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const previousAdminUsername = process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME;
	const previousAdminPassword = process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD;
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME = 'standing-store-admin';
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD = 'standing-store-password/';
	const pilotRuntime = runtime( directory, calls, {
		runtime: 'transition',
		runtimeStatuses: [
			{
				site_url: 'http://native.test',
				wpcom_blog_id: 123,
				runtime_owner: 'plugin',
				native_enabled: false,
				account_id: 'acct_native',
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
			},
			{
				site_url: 'http://native.test',
				wpcom_blog_id: 123,
				runtime_owner: 'native',
				native_enabled: true,
				account_id: 'acct_native',
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
			},
		],
	} );
	let cutoverClicked = false;
	const loginValues: string[] = [];
	const cutoverLink = visibleLocator( {
		click: async () => {
			cutoverClicked = true;
		},
		getAttribute: async ( name ) =>
			name === 'href'
				? 'http://native.test/wp-admin/admin.php?wc_woopayments_cutover_action=disable_woopayments&_wc_woopayments_cutover_nonce=nonce'
				: null,
	} );
	const page = {
		context: () => ( {
			clearCookies: async () => {},
		} ),
		goto: async () => {},
		getByLabel: () =>
			visibleLocator( {
				fill: async ( value ) => {
					loginValues.push( value );
				},
			} ),
		getByRole: (
			role: string,
			options?: { exact?: boolean; name?: string | RegExp }
		) => {
			const name = String( options?.name ?? '' );
			if ( role === 'link' && name === 'Disable WooPayments' ) {
				expect( options?.exact ).toBe( true );
				return cutoverLink;
			}
			if (
				( role === 'button' && name === 'Log In' ) ||
				( role === 'textbox' && /Email address/i.test( name ) )
			) {
				return visibleLocator();
			}
			if ( role === 'textbox' && name === 'Password' ) {
				return visibleLocator( {
					fill: async ( value ) => {
						loginValues.push( value );
					},
				} );
			}
			throw new Error( `Unexpected role locator: ${ role } ${ name }` );
		},
	} as unknown as Page;

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'product-cutover-entry-point' },
			async () => softCutOverEphemeralStore( pilotRuntime, page )
		);

		expect( cutoverClicked ).toBe( true );
		expect( loginValues.slice( 0, 2 ) ).toEqual( [
			'standing-store-admin',
			'standing-store-password/',
		] );
		expect(
			calls.filter(
				( call ) =>
					call.url === '/wp-json/wc-native-payments-e2e/v1/status'
			)
		).toHaveLength( 2 );
	} finally {
		if ( previousAdminUsername === undefined ) {
			delete process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME;
		} else {
			process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME = previousAdminUsername;
		}
		if ( previousAdminPassword === undefined ) {
			delete process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD;
		} else {
			process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD = previousAdminPassword;
		}
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'blocks cutover after lock loss during admin preparation', async () => {
	await expect(
		expectMutationBlockedAfterPreparation(
			{
				loseOn: { action: 'expect', name: 'Disable WooPayments' },
				mutation: {
					role: 'link',
					name: 'Disable WooPayments',
				},
			},
			async ( pilotRuntime, page ) => {
				await softCutOverEphemeralStore( pilotRuntime, page );
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
				await payWithExactSavedCard(
					pilotRuntime,
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
				await captureExactOrder( pilotRuntime, page, {
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
			withCapturedManualCaptureSetting( pilotRuntime, async () => {} )
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

test( 'lock release failure quarantines every lock acquired by the scenario', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const calls: RequestCall[] = [];
	const annotated: ResourceQuarantineReceipt[] = [];
	const pilotRuntime = runtime( directory, calls, {
		onResourceQuarantined: ( receipt ) => annotated.push( receipt ),
	} );
	const keys = [
		'acct_native/account:provider-writes',
		'acct_native/native-store/store:native-store',
		'acct_native/native-store/record-event:release-failure',
	];

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'release-failure' },
				async () => {
					await removeOwnedLock( directory, 'record-event' );
				}
			)
		).rejects.toThrow( /pilot teardown failed/i );
		for ( const key of keys ) {
			await expect( assertResourcesUsable( [ key ] ) ).rejects.toThrow(
				/quarantined/i
			);
		}
		expect( annotated ).toHaveLength( keys.length );
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'keeps every owned lock when required quarantine publication fails', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );
	const originalRelease = ResourceLock.prototype.release;
	let releaseCount = 0;

	ResourceLock.prototype.release = async function (
		this: ResourceLock
	): Promise< boolean > {
		releaseCount += 1;
		return originalRelease.call( this );
	};

	try {
		await expect(
			pilotRuntime.withProviderWriteLocks( {}, async () => {
				await writeFile(
					join( directory, 'quarantine' ),
					'not-a-directory'
				);
				await removeOwnedLock( directory, 'account' );
			} )
		).rejects.toThrow( /quarantine/i );
		expect( releaseCount ).toBe( 0 );
	} finally {
		ResourceLock.prototype.release = originalRelease;
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'manual-capture restoration failure quarantines account/store/setting', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		failManualCaptureRestore: true,
	} );
	const keys = [
		'acct_native/account:provider-writes',
		'acct_native/native-store/store:native-store',
		'acct_native/native-store/feature-setting:manual-capture',
	];

	try {
		await expect(
			withCapturedManualCaptureSetting( pilotRuntime, async () => {} )
		).rejects.toThrow( /restoration failed/i );
		for ( const key of keys ) {
			await expect( assertResourcesUsable( [ key ] ) ).rejects.toThrow(
				/quarantined/i
			);
		}
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'publishes restoration-failure quarantine before releasing provider locks', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		failManualCaptureRestore: true,
	} );
	const originalRelease = ResourceLock.prototype.release;
	const releaseObservations: Array< {
		key: string;
		quarantined: boolean;
	} > = [];

	ResourceLock.prototype.release = async function (
		this: ResourceLock
	): Promise< boolean > {
		let quarantined = false;
		try {
			await assertResourcesUsable( [ this.payload.key ], directory );
		} catch ( error ) {
			expect( error ).toBeInstanceOf( Error );
			expect( ( error as Error ).message ).toMatch( /quarantined/i );
			quarantined = true;
		}
		releaseObservations.push( {
			key: this.payload.key,
			quarantined,
		} );
		return originalRelease.call( this );
	};

	try {
		await expect(
			withCapturedManualCaptureSetting( pilotRuntime, async () => {} )
		).rejects.toThrow( /restoration failed/i );
		expect( releaseObservations ).toHaveLength( 4 );
		expect(
			releaseObservations.every(
				( observation ) => observation.quarantined
			)
		).toBe( true );
	} finally {
		ResourceLock.prototype.release = originalRelease;
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'stale-journal recovery failure quarantines resources and preserves the original error', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const originalError = new Error( 'Stale journal recovery failed.' );
	const calls: RequestCall[] = [];
	const staleManager = new ResourceLockManager( {
		lockDir: directory,
		runId: 'run-stale-journal',
		autoRenew: false,
	} );
	const account = await staleManager.acquire( {
		providerAccountId: 'acct_native',
		storeId: 'native-store',
		kind: 'account',
		resource: 'provider-writes',
		diagnosticPath: 'test-results/run-stale-journal',
	} );
	const store = await staleManager.acquire( {
		providerAccountId: 'acct_native',
		storeId: 'native-store',
		kind: 'store',
		resource: 'native-store',
		diagnosticPath: 'test-results/run-stale-journal',
	} );
	const setting = await staleManager.acquire( {
		providerAccountId: 'acct_native',
		storeId: 'native-store',
		kind: 'feature-setting',
		resource: 'manual-capture',
		diagnosticPath: 'test-results/run-stale-journal',
	} );
	await setting.writeRestorationJournal( false );
	await setting.release();
	await store.release();
	await account.release();
	const pilotRuntime = runtime( directory, calls, {
		initialManualCaptureRestoreError: originalError,
	} );
	const keys = [
		'acct_native/account:provider-writes',
		'acct_native/native-store/store:native-store',
		'acct_native/native-store/feature-setting:manual-capture',
	];

	try {
		let thrown: unknown;
		try {
			await withCapturedManualCaptureSetting(
				pilotRuntime,
				async () => {}
			);
		} catch ( error ) {
			thrown = error;
		}

		expect( thrown ).toBe( originalError );
		for ( const key of keys ) {
			await expect( assertResourcesUsable( [ key ] ) ).rejects.toThrow(
				/quarantined/i
			);
		}
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'run-owned product cleanup failure quarantines account/store', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls, {
		productCleanupStatus: 500,
	} );
	const keys = [
		'acct_native/account:provider-writes',
		'acct_native/native-store/store:native-store',
	];

	try {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'create-product-for-cleanup' },
			async () => {
				await pilotRuntime.createOwnedProduct( '10.99' );
			}
		);

		await expect( pilotRuntime.cleanup() ).rejects.toThrow(
			/clean up run-owned product/i
		);
		for ( const key of keys ) {
			await expect( assertResourcesUsable( [ key ] ) ).rejects.toThrow(
				/quarantined/i
			);
		}
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'fixture readiness rejects quarantine before provider approval or browser work', async () => {
	const directory = await lockDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const accountKey = 'acct_native/account:provider-writes';

	try {
		await quarantineResources(
			[ accountKey ],
			'cleanup-failed',
			'test-results/run-readiness'
		);
		const fixtureSource = await readFile(
			resolve(
				process.cwd(),
				'tests/e2e/fixtures/woopayments-native.ts'
			),
			'utf8'
		);
		const quarantineCheck = fixtureSource.indexOf(
			'await assertResourcesUsable('
		);
		const browserWork = fixtureSource.indexOf(
			'await browser.newContext',
			quarantineCheck
		);

		await expect( assertResourcesUsable( [ accountKey ] ) ).rejects.toThrow(
			/quarantined/i
		);
		expect( quarantineCheck ).toBeGreaterThan( -1 );
		expect( quarantineCheck ).toBeLessThan( browserWork );
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a quarantine annotation makes an expected-gap run fail', () => {
	const reporter = new WooPaymentsKnownGapsReporter();
	reporter.onTestEnd(
		{
			title: 'known gap fixture',
			expectedStatus: 'failed',
		} as never,
		{
			annotations: [
				{
					type: KNOWN_GAP_ANNOTATION,
					description: 'WPNATIVE-GAP-0001|refunds',
				},
				{
					type: RESOURCE_QUARANTINE_ANNOTATION,
					description: 'redacted-resource-hash',
				},
			],
			retry: 0,
			status: 'failed',
			errors: [
				{
					message: `${ KNOWN_GAP_SENTINEL }WPNATIVE-GAP-0001] Exact gap.`,
				},
			],
		} as never
	);

	expect( reporter.onEnd() ).toEqual( { status: 'failed' } );
} );

test( 'uses the exact aggregate settings contract for mutation and restore', async () => {
	const directory = await lockDirectory();
	const calls: RequestCall[] = [];
	const pilotRuntime = runtime( directory, calls );

	try {
		await withCapturedManualCaptureSetting( pilotRuntime, async () => {} );
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
			withCapturedManualCaptureSetting( pilotRuntime, async () => {
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
			withCapturedManualCaptureSetting( pilotRuntime, async () => {} )
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
