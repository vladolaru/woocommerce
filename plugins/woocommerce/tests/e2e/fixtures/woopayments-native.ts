import { randomUUID } from 'node:crypto';
import { join } from 'node:path';

import {
	expect,
	type APIRequestContext,
	type BrowserContext,
	type Page,
	type TestInfo,
} from '@playwright/test';

import { tags, test as baseTest } from './fixtures';
import { admin, customer } from '../test-data/data';
import {
	assertRuntimeOwnership,
	assertRuntimeReady,
	readRuntimeStatusArtifact,
	type RuntimeStatus,
	type WooPaymentsRuntime,
} from '../utils/woopayments-native/runtime-readiness';
import {
	assertAccountSeparation,
	assertNoDisplacedProviderLocks,
	resourceLockKey,
	ResourceLockManager,
	ResourceQuarantineRequiredError,
	type ResourceLock,
	type StoreAccountAllocation,
} from '../utils/woopayments-native/resource-locks';
import {
	assertResourcesUsable,
	quarantineResources,
	RESOURCE_QUARANTINE_ANNOTATION,
	type ResourceQuarantineReceipt,
} from '../utils/woopayments-native/resource-quarantine';
import { assertApprovedProviderFixture } from '../utils/woopayments-native/provider-fixture';
import {
	findUnresolvedProviderWriteAttempts,
	openProviderWriteAttempt,
	ProviderSubmissionNotStartedError,
	resolveProviderWriteAttempt,
} from '../utils/woopayments-native/provider-write-journal';
import { assertTransitionAllocation } from '../utils/woopayments-native/transition-allocation';

export { tags } from './fixtures';
export { ResourceQuarantineRequiredError };
export { ProviderSubmissionNotStartedError } from '../utils/woopayments-native/provider-write-journal';
export {
	getBlocksCardFrameSelector,
	submitBlocksCheckout,
} from '../utils/woopayments-native/drivers/checkout';
export type { SavedCardIdentity } from '../utils/woopayments-native/drivers/saved-cards';

const PROVIDER_INVOLVEMENT_TAGS: readonly string[] = [
	tags.WOOPAYMENTS_PROVIDER,
	tags.WOOPAYMENTS_TRANSITION,
];

/**
 * Whether a test involves the payment provider. Provider readiness — account
 * identity, allocations, callback proof, and provider-resource quarantine —
 * applies exactly to these tests. The predicate is the tags, not the project
 * name: the readonly project is defined as "everything carrying neither tag",
 * and a provider-free mutating spec legitimately lands there.
 */
export function testInvolvesProvider(
	testInfo: Pick< TestInfo, 'tags' >
): boolean {
	return testInfo.tags.some( ( tag ) =>
		PROVIDER_INVOLVEMENT_TAGS.includes( tag )
	);
}

export interface OwnedProduct {
	id: number;
	name: string;
	amount: string;
}

export interface ProviderWriteLockOptions {
	featureSetting?: string;
	recordEvent?: string;
}

interface ProviderWriteLocks {
	account: ResourceLock;
	store: ResourceLock;
	featureSetting?: ResourceLock;
	recordEvent?: ResourceLock;
}

interface ProviderSubmissionScope {
	queueTail: Promise< void >;
	inFlight: Set< Promise< unknown > >;
	blockedError?: unknown;
	firstError?: unknown;
}

function providerResourceKeys( accountId: string, storeId: string ): string[] {
	return [
		{ kind: 'account', resource: 'provider-writes' } as const,
		{ kind: 'store', resource: storeId } as const,
	].map( ( { kind, resource } ) =>
		resourceLockKey( {
			providerAccountId: accountId,
			storeId,
			kind,
			resource,
		} )
	);
}

function getRuntime(): WooPaymentsRuntime {
	const runtime = process.env.WCPAY_RUNTIME;
	if (
		runtime !== 'client' &&
		runtime !== 'native' &&
		runtime !== 'transition'
	) {
		throw new Error(
			'WCPAY_RUNTIME must be client, native, or transition.'
		);
	}
	return runtime;
}

function requireValue( name: string, fallback?: string ): string {
	const value = process.env[ name ] ?? fallback;
	if ( ! value ) {
		throw new Error( `${ name } is required for WooPayments pilots.` );
	}
	return value;
}

function requireUrlValue( name: string, fallback?: string ): string {
	return requireValue( name, fallback ).replace( /\/+$/, '' );
}

function getWooPaymentsAdminCredentials(): {
	username: string;
	password: string;
} {
	return {
		username: requireValue(
			'E2E_WOOPAYMENTS_ADMIN_USERNAME',
			admin.username
		),
		password: requireValue(
			'E2E_WOOPAYMENTS_ADMIN_PASSWORD',
			admin.password
		),
	};
}

function requireNumber( name: string ): number {
	const value = Number( requireValue( name ) );
	if ( ! Number.isSafeInteger( value ) || value <= 0 ) {
		throw new Error( `${ name } must be a positive integer.` );
	}
	return value;
}

function requireAllocations(): StoreAccountAllocation[] {
	const raw = requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ALLOCATIONS' );
	const allocations = JSON.parse( raw ) as StoreAccountAllocation[];
	if ( ! Array.isArray( allocations ) ) {
		throw new Error(
			'E2E_WOOPAYMENTS_ACCOUNT_ALLOCATIONS must be a JSON array.'
		);
	}
	return allocations;
}

export async function authenticateAdminContext(
	context: BrowserContext,
	credentials: { username: string; password: string }
): Promise< void > {
	const page = await context.newPage();
	try {
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( credentials.username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( credentials.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( '**/wp-admin/**' );
		const nonce = await page.evaluate( () => {
			const settings = (
				window as Window & {
					wpApiSettings?: { nonce?: unknown };
				}
			 ).wpApiSettings;
			return typeof settings?.nonce === 'string' ? settings.nonce : '';
		} );
		if ( ! nonce ) {
			throw new Error(
				'Authenticated WordPress admin session did not expose a REST nonce.'
			);
		}
		await context.setExtraHTTPHeaders( {
			'X-WP-Nonce': nonce,
		} );
	} finally {
		await page.close();
	}
}

export function adminContextOptions(
	baseURL: string,
	projectName: string,
	warmedStatePath?: string
): { baseURL: string; storageState?: string } {
	if ( projectName !== 'woopayments-native-readonly' ) {
		return { baseURL };
	}
	if ( ! warmedStatePath ) {
		throw new Error(
			'Readonly admin API contexts require the globally warmed storage state.'
		);
	}
	return { baseURL, storageState: warmedStatePath };
}

export async function hydrateAdminRestNonce(
	context: BrowserContext
): Promise< void > {
	const page = await context.newPage();
	try {
		await page.goto( 'wp-admin/' );
		if ( ! page.url().includes( '/wp-admin/' ) ) {
			throw new Error(
				'Warmed WordPress admin state did not reach wp-admin.'
			);
		}
		const nonce = await page.evaluate( () => {
			const settings = (
				window as Window & {
					wpApiSettings?: { nonce?: unknown };
				}
			 ).wpApiSettings;
			return typeof settings?.nonce === 'string' ? settings.nonce : '';
		} );
		if ( ! nonce ) {
			throw new Error(
				'Warmed WordPress admin session did not expose a REST nonce.'
			);
		}
		await context.setExtraHTTPHeaders( {
			'X-WP-Nonce': nonce,
		} );
	} finally {
		await page.close();
	}
}

export async function loadInitialRuntimeStatus(
	runtime: WooPaymentsRuntime,
	adminApi: APIRequestContext,
	diagnosticsDir?: string
): Promise< RuntimeStatus > {
	if ( runtime !== 'transition' ) {
		if ( ! diagnosticsDir ) {
			throw new Error(
				'E2E_WOOPAYMENTS_DIAGNOSTICS_DIR is required for standing-store runtime readiness.'
			);
		}

		return readRuntimeStatusArtifact(
			join( diagnosticsDir, runtime, 'runtime-status.json' )
		);
	}

	const response = await adminApi.get(
		'/wp-json/wc-native-payments-e2e/v1/status'
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Runtime readiness route failed: HTTP ${ response.status() }.`
		);
	}

	return ( await response.json() ) as RuntimeStatus;
}

export interface ProviderWriteSession {
	readonly adminApi: APIRequestContext;
	readonly runtime: WooPaymentsRuntime;
	readonly runId: string;
	readonly baseURL: string;
	requireApprovedProviderFixture: ( capability: string ) => void;
	requireEphemeralTransitionAllocation: () => void;
	assertCurrentRuntimeReady: (
		runtime: WooPaymentsRuntime
	) => Promise< void >;
	assertCanWrite: () => Promise< void >;
	performWrite: < Result >(
		write: () => Promise< Result >
	) => Promise< Result >;
	withProviderSubmissionJournal: < Result >(
		description: string,
		submit: () => Promise< Result >
	) => Promise< Result >;
	withProviderWriteLocks: < Result >(
		options: ProviderWriteLockOptions,
		callback: () => Promise< Result >
	) => Promise< Result >;
	getActiveFeatureSettingLock: () => ResourceLock | undefined;
	logInAsAdmin: ( page: Page ) => Promise< void >;
	logInAsCustomer: ( page: Page ) => Promise< void >;
	createOwnedProduct: ( amount: string ) => Promise< OwnedProduct >;
	setOrderRunId: ( orderId: number, runId: string ) => Promise< void >;
	getOrderIdFromUrl: ( url: string ) => number;
}

interface PilotRuntimeOptions {
	lockDir?: string;
	onResourceQuarantined?: (
		receipt: ResourceQuarantineReceipt
	) => void | Promise< void >;
}

export class WooPaymentsPilotRuntime implements ProviderWriteSession {
	public readonly adminApi: APIRequestContext;
	public readonly runtime: WooPaymentsRuntime;
	public readonly runId: string;
	public readonly baseURL: string;
	private readonly wpcomBlogId: number;
	private readonly storeId: string;
	private readonly accountId: string;
	private readonly lockDir?: string;
	private readonly onResourceQuarantined?: (
		receipt: ResourceQuarantineReceipt
	) => void | Promise< void >;
	private readonly ownedProductIds: number[] = [];
	private activeProviderWriteLocks?: ProviderWriteLocks;
	private activeProviderSubmissionScope?: ProviderSubmissionScope;

	public constructor(
		adminApi: APIRequestContext,
		runtime: WooPaymentsRuntime,
		runId: string,
		baseURL: string,
		wpcomBlogId: number,
		storeId: string,
		accountId: string,
		options: PilotRuntimeOptions = {}
	) {
		this.adminApi = adminApi;
		this.runtime = runtime;
		this.runId = runId;
		this.baseURL = baseURL;
		this.wpcomBlogId = wpcomBlogId;
		this.storeId = storeId;
		this.accountId = accountId;
		this.lockDir = options.lockDir;
		this.onResourceQuarantined = options.onResourceQuarantined;
	}

	public requireApprovedProviderFixture( capability: string ): void {
		assertApprovedProviderFixture(
			process.env.E2E_WOOPAYMENTS_PROVIDER_FIXTURE,
			{
				runtime: this.runtime,
				storeId: this.storeId,
				siteUrl: this.baseURL,
				wpcomBlogId: this.wpcomBlogId,
				accountId: this.accountId,
				accountAlias: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ALIAS' ),
				isCI: !! process.env.CI,
			},
			capability
		);
	}

	public requireEphemeralTransitionAllocation(): void {
		if ( this.runtime !== 'transition' ) {
			throw new Error(
				'Saved-method cutover requires the transition runtime.'
			);
		}
		const allocation = process.env.E2E_TRANSITION_ALLOCATION;
		if ( ! allocation ) {
			throw new Error(
				'Saved-method cutover requires the emitted ephemeral transition allocation.'
			);
		}

		assertTransitionAllocation( allocation, {
			baseUrl: this.baseURL,
			storeId: this.storeId,
			tempRoot: requireValue( 'TMPDIR' ),
			runId: requireValue( 'E2E_TRANSITION_RUN_ID' ),
		} );
	}

	public async createOwnedProduct( amount: string ): Promise< OwnedProduct > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'product/payment' );
		const name = `WooPayments native E2E ${ this.runId }`;
		const response = await this.performWrite( () =>
			this.adminApi.post( '/wp-json/wc/v3/products', {
				data: {
					name,
					type: 'simple',
					virtual: true,
					regular_price: amount,
					meta_data: [
						{
							key: '_e2e_woopayments_run_id',
							value: this.runId,
						},
					],
				},
			} )
		);
		if ( ! response.ok() ) {
			throw new Error(
				`Unable to create run-owned product: HTTP ${ response.status() }.`
			);
		}
		const product = ( await response.json() ) as { id?: unknown };
		if ( typeof product.id !== 'number' ) {
			throw new Error(
				'Run-owned product response did not contain a numeric ID.'
			);
		}
		this.ownedProductIds.push( product.id );
		return { id: product.id, name, amount };
	}

	public getActiveFeatureSettingLock(): ResourceLock | undefined {
		return this.activeProviderWriteLocks?.featureSetting;
	}

	public async withProviderWriteLocks< Result >(
		options: ProviderWriteLockOptions,
		callback: () => Promise< Result >
	): Promise< Result > {
		if ( this.activeProviderWriteLocks ) {
			throw new Error( 'WooPayments provider write locks cannot nest.' );
		}
		const manager = new ResourceLockManager( {
			lockDir: this.getProviderLockDirectory(),
			runId: this.runId,
		} );
		const locks: ResourceLock[] = [];
		const teardownErrors: Error[] = [];
		let primaryError: unknown;
		let result: Result | undefined;

		try {
			const account = await manager.acquire( {
				providerAccountId: this.accountId,
				storeId: this.storeId,
				kind: 'account',
				resource: 'provider-writes',
				diagnosticPath: `test-results/${ this.runId }`,
			} );
			locks.push( account );
			const store = await manager.acquire( {
				providerAccountId: this.accountId,
				storeId: this.storeId,
				kind: 'store',
				resource: this.storeId,
				diagnosticPath: `test-results/${ this.runId }`,
			} );
			locks.push( store );
			const featureSetting = options.featureSetting
				? await manager.acquire( {
						providerAccountId: this.accountId,
						storeId: this.storeId,
						kind: 'feature-setting',
						resource: options.featureSetting,
						diagnosticPath: `test-results/${ this.runId }`,
				  } )
				: undefined;
			if ( featureSetting ) {
				locks.push( featureSetting );
			}
			const recordEvent = options.recordEvent
				? await manager.acquire( {
						providerAccountId: this.accountId,
						storeId: this.storeId,
						kind: 'record-event',
						resource: options.recordEvent,
						diagnosticPath: `test-results/${ this.runId }`,
				  } )
				: undefined;
			if ( recordEvent ) {
				locks.push( recordEvent );
			}

			assertNoDisplacedProviderLocks( locks );
			const unresolvedAttempts =
				await findUnresolvedProviderWriteAttempts(
					this.getProviderLockDirectory(),
					[ account.payload.key, store.payload.key ],
					this.runId
				);
			if ( unresolvedAttempts.length > 0 ) {
				throw new ResourceQuarantineRequiredError(
					`A prior provider write on these resources has no proven outcome (${ unresolvedAttempts.length } unresolved attempt(s)).`,
					'uncertain-provider-write'
				);
			}

			this.activeProviderWriteLocks = {
				account,
				store,
				featureSetting,
				recordEvent,
			};
			this.activeProviderSubmissionScope = {
				queueTail: Promise.resolve(),
				inFlight: new Set(),
			};
			result = await callback();
		} catch ( error ) {
			try {
				assertNoDisplacedProviderLocks( locks );
				primaryError = error;
			} catch ( displacementError ) {
				primaryError = displacementError;
			}
		}

		const submissionScope = this.activeProviderSubmissionScope;
		if ( submissionScope ) {
			await Promise.allSettled( submissionScope.inFlight );
			if (
				primaryError === undefined &&
				submissionScope.firstError !== undefined
			) {
				primaryError = submissionScope.firstError;
			}
		}

		let quarantineAttempted = false;
		const publishQuarantine = async (
			reasonCode: ResourceQuarantineReceipt[ 'reasonCode' ]
		): Promise< void > => {
			if ( quarantineAttempted ) {
				return;
			}
			quarantineAttempted = true;

			const receipts = await quarantineResources(
				locks.map( ( lock ) => lock.payload.key ),
				reasonCode,
				`test-results/${ this.runId }`,
				this.lockDir
			);
			for ( const receipt of receipts ) {
				console.error( 'WooPayments resource quarantined:', {
					resourceKeyHash: receipt.resourceKeyHash,
					evidencePath: receipt.evidencePath,
				} );
				await this.onResourceQuarantined?.( receipt );
			}
		};

		this.activeProviderSubmissionScope = undefined;
		this.activeProviderWriteLocks = undefined;
		let quarantineReason =
			primaryError instanceof ResourceQuarantineRequiredError
				? primaryError.reasonCode
				: undefined;
		if (
			quarantineReason === undefined &&
			submissionScope?.blockedError instanceof
				ResourceQuarantineRequiredError
		) {
			quarantineReason = submissionScope.blockedError.reasonCode;
		}
		const unownedLocks = new Set< ResourceLock >();
		for ( const lock of locks ) {
			try {
				if ( await lock.isOwned() ) {
					continue;
				}
				teardownErrors.push(
					new Error(
						`WooPayments resource lock ${ lock.payload.key } lost ownership before release.`
					)
				);
			} catch ( error ) {
				teardownErrors.push(
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
			unownedLocks.add( lock );
			quarantineReason ??= 'lock-ownership-lost';
		}
		if ( quarantineReason ) {
			await publishQuarantine( quarantineReason );
		}

		for ( const lock of locks.toReversed() ) {
			if ( unownedLocks.has( lock ) ) {
				continue;
			}
			try {
				const released = await lock.release();
				if ( ! released ) {
					teardownErrors.push(
						new Error(
							`WooPayments resource lock ${ lock.payload.key } was not released because ownership was lost.`
						)
					);
					await publishQuarantine( 'lock-ownership-lost' );
				}
			} catch ( error ) {
				teardownErrors.push(
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
				await publishQuarantine( 'lock-ownership-lost' );
			}
		}

		if ( primaryError !== undefined ) {
			for ( const teardownError of teardownErrors ) {
				console.error(
					'WooPayments pilot teardown failed after the primary test failure:',
					teardownError
				);
			}
			throw primaryError instanceof ResourceQuarantineRequiredError &&
				primaryError.primaryError !== undefined
				? primaryError.primaryError
				: primaryError;
		}
		if ( teardownErrors.length > 0 ) {
			throw new AggregateError(
				teardownErrors,
				'WooPayments pilot teardown failed.'
			);
		}
		return result as Result;
	}

	public async cleanup(): Promise< void > {
		if ( this.ownedProductIds.length === 0 ) {
			return;
		}

		await this.withProviderWriteLocks(
			{ recordEvent: 'owned-product-cleanup' },
			async () => {
				try {
					while ( this.ownedProductIds.length > 0 ) {
						const productId = this.ownedProductIds[ 0 ];
						const response = await this.performWrite( () =>
							this.adminApi.delete(
								`/wp-json/wc/v3/products/${ productId }`,
								{
									data: { force: true },
									failOnStatusCode: false,
								}
							)
						);
						if ( ! response.ok() ) {
							throw new Error(
								`Unable to clean up run-owned product ${ productId }: HTTP ${ response.status() }.`
							);
						}
						this.ownedProductIds.shift();
					}
				} catch ( error ) {
					throw new ResourceQuarantineRequiredError(
						error instanceof Error
							? error.message
							: 'Run-owned product cleanup failed.',
						'cleanup-failed',
						error
					);
				}
			}
		);
	}

	private getProviderLockDirectory(): string {
		const lockDir = this.lockDir ?? process.env.E2E_WOOPAYMENTS_LOCK_DIR;
		if ( ! lockDir ) {
			throw new Error(
				'E2E_WOOPAYMENTS_LOCK_DIR is required for provider-writing tests.'
			);
		}
		return lockDir;
	}

	public withProviderSubmissionJournal< Result >(
		description: string,
		submit: () => Promise< Result >
	): Promise< Result > {
		const locks = this.activeProviderWriteLocks;
		const submissionScope = this.activeProviderSubmissionScope;
		if ( ! locks || ! submissionScope ) {
			throw new Error(
				'WooPayments provider submissions require active provider write locks.'
			);
		}

		const queuedSubmission = submissionScope.queueTail.then( async () => {
			if ( submissionScope.blockedError !== undefined ) {
				throw submissionScope.blockedError;
			}

			let attemptPath: string;
			try {
				attemptPath = await openProviderWriteAttempt(
					this.getProviderLockDirectory(),
					{
						version: 1,
						runId: this.runId,
						resourceKeys: [
							locks.account.payload.key,
							locks.store.payload.key,
						],
						description,
						startedAt: Date.now(),
					}
				);
			} catch ( journalError ) {
				submissionScope.blockedError = journalError;
				throw journalError;
			}

			let submissionResult: Result;
			try {
				submissionResult = await submit();
			} catch ( submissionError ) {
				if (
					submissionError instanceof ProviderSubmissionNotStartedError
				) {
					try {
						await resolveProviderWriteAttempt( attemptPath );
					} catch ( journalError ) {
						submissionScope.blockedError = journalError;
						throw journalError;
					}
					throw submissionError;
				}
				const quarantineError =
					submissionError instanceof ResourceQuarantineRequiredError
						? submissionError
						: new ResourceQuarantineRequiredError(
								`WooPayments provider submission ${ description } has no proven outcome.`,
								'uncertain-provider-write',
								submissionError
						  );
				submissionScope.blockedError = quarantineError;
				throw quarantineError;
			}

			try {
				await resolveProviderWriteAttempt( attemptPath );
			} catch ( journalError ) {
				submissionScope.blockedError = journalError;
				throw journalError;
			}
			return submissionResult;
		} );
		const trackedSubmission = queuedSubmission.catch(
			( submissionError: unknown ) => {
				submissionScope.firstError ??= submissionError;
				throw submissionError;
			}
		);
		submissionScope.queueTail = trackedSubmission.then(
			() => {},
			() => {}
		);
		submissionScope.inFlight.add( trackedSubmission );
		void trackedSubmission.catch( () => {} );
		void trackedSubmission.then(
			() => submissionScope.inFlight.delete( trackedSubmission ),
			() => submissionScope.inFlight.delete( trackedSubmission )
		);
		return trackedSubmission;
	}

	public async assertCanWrite(): Promise< void > {
		const locks = this.activeProviderWriteLocks;
		if ( ! locks ) {
			throw new Error(
				'WooPayments provider helpers require owned account and store locks.'
			);
		}

		const activeLocks = [
			{ kind: 'account', lock: locks.account },
			{ kind: 'store', lock: locks.store },
			{ kind: 'feature-setting', lock: locks.featureSetting },
			{ kind: 'record-event', lock: locks.recordEvent },
		];
		for ( const active of activeLocks ) {
			if ( ! active.lock ) {
				continue;
			}

			let isOwned: boolean;
			try {
				isOwned = await active.lock.isOwned();
			} catch ( error ) {
				throw new Error(
					`WooPayments provider helpers lost active ${ active.kind } lock ownership.`,
					{ cause: error }
				);
			}
			if ( ! isOwned ) {
				throw new Error(
					`WooPayments provider helpers lost active ${ active.kind } lock ownership.`
				);
			}
		}
	}

	public async performWrite< Result >(
		write: () => Promise< Result >
	): Promise< Result > {
		await this.assertCanWrite();
		return write();
	}

	public async logInAsAdmin( page: Page ): Promise< void > {
		const credentials = getWooPaymentsAdminCredentials();
		await page.context().clearCookies();
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( credentials.username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( credentials.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
	}

	public async logInAsCustomer( page: Page ): Promise< void > {
		await page.context().clearCookies();
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( customer.username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( customer.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.goto( 'my-account/edit-account/' );
		await expect(
			page.getByRole( 'textbox', { name: /Email address/i } )
		).toHaveValue( customer.email );
	}

	public async assertCurrentRuntimeReady(
		runtime: WooPaymentsRuntime
	): Promise< void > {
		const deadline = Date.now() + 30_000;
		let lastFailure: Error | undefined;

		for (;;) {
			const response = await this.adminApi.get(
				'/wp-json/wc-native-payments-e2e/v1/status'
			);
			let status: RuntimeStatus | undefined;
			try {
				if ( ! response.ok() ) {
					throw new Error(
						`Runtime ownership recheck failed: HTTP ${ response.status() }.`
					);
				}
				const candidate = ( await response.json() ) as RuntimeStatus;
				// Callback readiness is proved once, at setup, against the
				// diagnostics artifact that carries it. This route cannot
				// report it: the probe registers a callback and calls it from
				// outside the store, and the store never learns the result, so
				// the live payload always answers registered false, reachable
				// false, blog 0. Demanding it here made the recheck
				// unsatisfiable for a standing store rather than strict. What
				// this recheck is for is the volatile half - that the expected
				// runtime still owns the store, on the same account, mid-run.
				assertRuntimeReady(
					runtime,
					candidate,
					{
						siteUrl: this.baseURL,
						wpcomBlogId: this.wpcomBlogId,
						accountId: this.accountId,
					},
					{ requireCallback: false }
				);
				status = candidate;
			} catch ( error ) {
				lastFailure =
					error instanceof Error
						? error
						: new Error( String( error ) );
			}

			if ( status ) {
				return;
			}

			const remaining = deadline - Date.now();
			if ( remaining <= 0 ) {
				throw new Error(
					'Native runtime ownership was not proved before the cutover deadline.',
					{ cause: lastFailure }
				);
			}
			await new Promise( ( resolve ) =>
				setTimeout( resolve, Math.min( 500, remaining ) )
			);
		}
	}

	public getOrderIdFromUrl( url: string ): number {
		const match = url.match( /order-received\/(\d+)/ );
		if ( ! match ) {
			throw new Error(
				`Checkout confirmation did not expose a durable order ID: ${ url }`
			);
		}
		return Number( match[ 1 ] );
	}

	public async setOrderRunId(
		orderId: number,
		runId: string
	): Promise< void > {
		const response = await this.performWrite( () =>
			this.adminApi.put( `/wp-json/wc/v3/orders/${ orderId }`, {
				data: {
					meta_data: [
						{
							key: '_e2e_woopayments_run_id',
							value: runId,
						},
					],
				},
			} )
		);
		if ( ! response.ok() ) {
			throw new Error(
				`Unable to attach the run ID to order ${ orderId }: HTTP ${ response.status() }.`
			);
		}
	}
}

interface WooPaymentsNativeFixtures {
	adminApi: APIRequestContext;
	pilotRuntime: WooPaymentsPilotRuntime;
	runId: string;
	runtimeReadiness: void;
}

export const test = baseTest.extend< WooPaymentsNativeFixtures >( {
	adminApi: async ( { baseURL, browser }, use, testInfo ) => {
		// Provider-resource quarantine guards provider work. A provider-free
		// test declares no provider resources, so it has nothing to check
		// here; provider specs are additionally re-checked per resource at
		// lock acquisition.
		if ( testInvolvesProvider( testInfo ) ) {
			await assertResourcesUsable(
				providerResourceKeys(
					requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' ),
					requireValue( 'E2E_WOOPAYMENTS_STORE_ID' )
				)
			);
		}
		if ( ! baseURL ) {
			throw new Error( 'BASE_URL is required for WooPayments pilots.' );
		}
		const warmedStatePath =
			typeof testInfo.project.metadata.woopaymentsAdminStatePath ===
			'string'
				? testInfo.project.metadata.woopaymentsAdminStatePath
				: undefined;
		const contextOptions = adminContextOptions(
			baseURL,
			testInfo.project.name,
			warmedStatePath
		);
		const adminContext = await browser.newContext( contextOptions );
		if ( contextOptions.storageState ) {
			await hydrateAdminRestNonce( adminContext );
		} else {
			await authenticateAdminContext(
				adminContext,
				getWooPaymentsAdminCredentials()
			);
		}
		await use( adminContext.request );
		await adminContext.close();
	},
	runId: async ( { baseURL }, use ) => {
		if ( ! baseURL ) {
			throw new Error( 'BASE_URL is required for WooPayments pilots.' );
		}
		await use( `woopayments-${ randomUUID() }` );
	},
	runtimeReadiness: [
		async ( { adminApi, baseURL }, use, testInfo ) => {
			const runtime = getRuntime();
			const siteUrl = requireUrlValue(
				'E2E_WOOPAYMENTS_SITE_URL',
				baseURL
			);
			const status = await loadInitialRuntimeStatus(
				runtime,
				adminApi,
				process.env.E2E_WOOPAYMENTS_DIAGNOSTICS_DIR
			);

			if ( ! testInvolvesProvider( testInfo ) ) {
				// A provider-free test still needs the expected runtime kind
				// to actually own the store it drives; the provider account,
				// callback proof, and account allocations are not its
				// concern.
				assertRuntimeOwnership( runtime, status, { siteUrl } );
				await use();
				return;
			}

			const expected = {
				siteUrl,
				wpcomBlogId: requireNumber( 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID' ),
				accountId: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' ),
			};
			assertRuntimeReady( runtime, status, expected, {
				requireCallback: runtime !== 'transition',
			} );

			const allocation = {
				storeId: requireValue( 'E2E_WOOPAYMENTS_STORE_ID' ),
				accountId: expected.accountId,
				accountAlias: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ALIAS' ),
			};
			assertAccountSeparation( allocation, requireAllocations(), {
				isCI: !! process.env.CI,
				ciAccountAlias: process.env.E2E_WOOPAYMENTS_CI_ACCOUNT_ALIAS,
				ciAccountId: process.env.E2E_WOOPAYMENTS_CI_ACCOUNT_ID,
			} );
			await use();
		},
		{ auto: true },
	],
	pilotRuntime: async ( { adminApi, baseURL, runId }, use, testInfo ) => {
		if ( ! baseURL ) {
			throw new Error( 'BASE_URL is required for WooPayments pilots.' );
		}
		const runtime = new WooPaymentsPilotRuntime(
			adminApi,
			getRuntime(),
			runId,
			baseURL.replace( /\/+$/, '' ),
			requireNumber( 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID' ),
			requireValue( 'E2E_WOOPAYMENTS_STORE_ID' ),
			requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' ),
			{
				onResourceQuarantined: ( receipt ) => {
					testInfo.annotations.push( {
						type: RESOURCE_QUARANTINE_ANNOTATION,
						description: receipt.resourceKeyHash,
					} );
				},
			}
		);
		await use( runtime );
		await runtime.cleanup();
	},
} );

export { expect };
