import { randomUUID } from 'node:crypto';
import { execFile } from 'node:child_process';
import { join } from 'node:path';
import { promisify } from 'node:util';

import {
	expect,
	type APIRequestContext,
	type BrowserContext,
	type Locator,
	type Page,
} from '@playwright/test';

import { test as baseTest } from './fixtures';
import { admin, customer } from '../test-data/data';
import {
	assertRuntimeReady,
	readRuntimeStatusArtifact,
	type RuntimeStatus,
	type WooPaymentsRuntime,
} from '../utils/woopayments-native/runtime-readiness';
import {
	assertAccountSeparation,
	ResourceLockManager,
	type ResourceLock,
	type StoreAccountAllocation,
} from '../utils/woopayments-native/resource-locks';
import type { PaymentEvidence } from '../utils/woopayments-native/record-evidence';
import { assertApprovedProviderFixture } from '../utils/woopayments-native/provider-fixture';
import { assertTransitionAllocation } from '../utils/woopayments-native/transition-allocation';

interface OwnedProduct {
	id: number;
	name: string;
	amount: string;
}

interface SavedCardIdentity {
	tokenId: number;
	paymentMethodId: string;
}

interface SavedCardState extends SavedCardIdentity {
	isDefault: boolean;
	providerDefaultPaymentMethodId: string;
}

interface SavedCardTokenEvidence {
	tokenId: number;
	paymentMethodId: string;
	isDefault: boolean;
}

interface SavedCardEvidence {
	creationReady: boolean;
	tokens: SavedCardTokenEvidence[];
	providerCustomerId?: string;
	providerDefaultPaymentMethodId?: string;
}

interface CallbackProbeTarget {
	siteUrl: string;
	wpcomBlogId: number;
}

type CallbackProbeRunner = (
	target: CallbackProbeTarget
) => Promise< unknown >;

interface ProviderWriteLockOptions {
	featureSetting?: string;
	recordEvent?: string;
}

interface ProviderWriteLocks {
	account: ResourceLock;
	store: ResourceLock;
	featureSetting?: ResourceLock;
	recordEvent?: ResourceLock;
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
	return value.replace( /\/+$/, '' );
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

const execFileAsync = promisify( execFile );

async function runWpcomLocalCallbackProbe(
	target: CallbackProbeTarget
): Promise< unknown > {
	const executable = process.env.E2E_WPCOM_LOCAL_BIN ?? 'wpcom-local';
	let stdout: string;

	try {
		( { stdout } = await execFileAsync(
			executable,
			[
				'--json',
				'wcpay',
				'callback',
				'probe',
				'--store-url',
				target.siteUrl,
				'--wpcom-blog-id',
				target.wpcomBlogId.toString(),
			],
			{
				maxBuffer: 1024 * 1024,
				timeout: 60_000,
			}
		) );
		return JSON.parse( stdout );
	} catch {
		throw new Error(
			'The wpcom-local callback probe did not return executable evidence.'
		);
	}
}

function assertExactCallbackProbe(
	result: unknown,
	target: CallbackProbeTarget
): void {
	if ( ! result || typeof result !== 'object' ) {
		throw new Error(
			'The wpcom-local callback probe did not return validated evidence.'
		);
	}

	const proof = result as {
		exit_code?: unknown;
		status?: unknown;
		context?: Record< string, unknown >;
	};
	const context = proof.context;
	if (
		proof.exit_code !== 0 ||
		( proof.status !== 'success' && proof.status !== 'warning' ) ||
		! context ||
		context.store_url !== target.siteUrl ||
		context.wpcom_blog_id !== target.wpcomBlogId.toString() ||
		context.callback_auth_model !== 'jetpack_capability' ||
		context.callback_registered !== 'true' ||
		context.callback_reachable !== 'true' ||
		context.callback_provider_write !== 'false' ||
		context.callback_response_result !== 'success'
	) {
		throw new Error(
			'The wpcom-local callback probe did not return validated evidence.'
		);
	}
}

async function withValidatedCallbackProbe(
	status: RuntimeStatus,
	target: CallbackProbeTarget,
	runCallbackProbe: CallbackProbeRunner
): Promise< RuntimeStatus > {
	const callbackProof = await runCallbackProbe( target );
	assertExactCallbackProbe( callbackProof, target );

	return {
		...status,
		callback_probe: {
			registered: true,
			reachable: true,
			wpcom_blog_id: target.wpcomBlogId,
		},
	};
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

export function getBlocksCardFrameSelector(
	runtime: WooPaymentsRuntime
): string {
	return runtime === 'client'
		? '#payment-method .wcpay-payment-element iframe[name^="__privateStripeFrame"]'
		: '#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]';
}

export async function submitBlocksCheckout(
	page: Page,
	click: ( button: Locator ) => Promise< void >
): Promise< void > {
	const checkoutUrl = page.url();
	const button = page.getByRole( 'button', { name: /place order/i } );

	for ( let attempt = 1; attempt <= 3; attempt++ ) {
		const checkoutRequestStarted = page
			.waitForRequest(
				( request ) => {
					if ( request.method() !== 'POST' ) {
						return false;
					}
					try {
						return (
							new URL( request.url() ).pathname.replace(
								/\/+$/,
								''
							) === '/wp-json/wc/store/v1/checkout'
						);
					} catch {
						return false;
					}
				},
				{ timeout: 2_000 }
			)
			.then(
				() => true,
				() => false
			);
		await click( button );
		const submissionStarted = await page
			.waitForFunction(
				() => {
					type Selector = ( ...args: unknown[] ) => unknown;
					type Store = Record< string, Selector >;
					const wpData = (
						window as Window & {
							wp?: {
								data?: {
									select?: ( key: string ) => Store;
								};
							};
						}
					 ).wp?.data;
					const checkout = wpData?.select?.( 'wc/store/checkout' );
					const payment = wpData?.select?.( 'wc/store/payment' );
					return (
						checkout?.getCheckoutStatus?.() !== 'idle' ||
						checkout?.hasError?.() === true ||
						payment?.isPaymentIdle?.() === false ||
						payment?.hasPaymentError?.() === true
					);
				},
				undefined,
				{ timeout: 2_000 }
			)
			.then(
				() => true,
				() => false
			);
		if (
			( await checkoutRequestStarted ) ||
			submissionStarted ||
			page.url() !== checkoutUrl
		) {
			return;
		}
	}

	throw new Error(
		'WooPayments Blocks checkout did not start after 3 attempts while Core remained idle.'
	);
}

export async function loadInitialRuntimeStatus(
	runtime: WooPaymentsRuntime,
	adminApi: APIRequestContext,
	diagnosticsDir?: string,
	callbackProbeTarget?: CallbackProbeTarget,
	runCallbackProbe: CallbackProbeRunner = runWpcomLocalCallbackProbe
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

	if ( ! callbackProbeTarget ) {
		throw new Error(
			'Transition readiness requires an exact wpcom-local callback probe target.'
		);
	}

	const status = ( await response.json() ) as RuntimeStatus;
	return withValidatedCallbackProbe(
		status,
		callbackProbeTarget,
		runCallbackProbe
	);
}

export class WooPaymentsPilotRuntime {
	private readonly adminApi: APIRequestContext;
	private readonly runtime: WooPaymentsRuntime;
	private readonly runId: string;
	private readonly baseURL: string;
	private readonly wpcomBlogId: number;
	private readonly storeId: string;
	private readonly accountId: string;
	private readonly lockDir?: string;
	private readonly callbackProbeRunner: CallbackProbeRunner;
	private readonly ownedProductIds: number[] = [];
	private activeProviderWriteLocks?: ProviderWriteLocks;

	public constructor(
		adminApi: APIRequestContext,
		runtime: WooPaymentsRuntime,
		runId: string,
		baseURL: string,
		wpcomBlogId: number,
		storeId: string,
		accountId: string,
		lockDir?: string,
		callbackProbeRunner: CallbackProbeRunner = runWpcomLocalCallbackProbe
	) {
		this.adminApi = adminApi;
		this.runtime = runtime;
		this.runId = runId;
		this.baseURL = baseURL;
		this.wpcomBlogId = wpcomBlogId;
		this.storeId = storeId;
		this.accountId = accountId;
		this.lockDir = lockDir;
		this.callbackProbeRunner = callbackProbeRunner;
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

	public async completeCardCheckout(
		page: Page,
		product: OwnedProduct,
		runId: string
	): Promise< number > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'basic-card' );

		await page.goto( `?post_type=product&p=${ product.id }` );
		await this.performWrite( () =>
			page
				.getByRole( 'button', {
					name: 'Add to cart',
					exact: true,
				} )
				.click()
		);
		await page.goto( 'checkout/' );
		const isBlockCheckout = await this.fillCheckoutDetails( page, runId );

		this.requireApprovedProviderFixture( 'basic-card-entry' );
		await this.fillBasicTestCard( page, isBlockCheckout );
		if ( isBlockCheckout ) {
			await submitBlocksCheckout( page, ( button ) =>
				this.performWrite( () => button.click() )
			);
		} else {
			await this.performWrite( () =>
				page.getByRole( 'button', { name: /place order/i } ).click()
			);
		}
		try {
			await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
				timeout: 60_000,
			} );
			await expect(
				page.getByText(
					/^(Your order has been received|Order received)$/i
				)
			).toBeVisible();
		} catch ( error ) {
			const diagnostics = isBlockCheckout
				? await this.getBlocksCheckoutDiagnostics( page )
				: undefined;
			throw new Error(
				`WooPayments checkout did not reach order confirmation${
					diagnostics ? `: ${ JSON.stringify( diagnostics ) }` : '.'
				}`,
				{ cause: error }
			);
		}

		const orderId = this.getOrderIdFromUrl( page.url() );
		await this.setOrderRunId( orderId, runId );
		return orderId;
	}

	public async createPluginOwnedSavedCard(
		page: Page,
		label: string
	): Promise< SavedCardIdentity > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'plugin-owned-saved-card' );
		const before = await this.waitForSavedCardCreationReady();

		await this.logInAsCustomer( page );
		await page.goto( 'my-account/payment-methods/' );
		await page.getByRole( 'link', { name: /add payment method/i } ).click();

		await page.getByText( 'Card', { exact: true } ).click();
		const cardFrame = page
			.getByTitle( 'Secure payment input frame' )
			.contentFrame();
		await cardFrame
			.getByPlaceholder( '1234 1234 1234 1234' )
			.fill( '4242 4242 4242 4242' );
		await cardFrame.getByPlaceholder( 'MM / YY' ).fill( '02 / 45' );
		await cardFrame.getByPlaceholder( 'CVC' ).fill( '123' );
		await cardFrame
			.getByRole( 'combobox', { name: /country/i } )
			.selectOption( 'US' );
		await cardFrame.getByLabel( /ZIP/i ).fill( '90210' );
		await this.performWrite( () =>
			page
				.getByRole( 'button', {
					name: 'Add payment method',
					exact: true,
				} )
				.click()
		);
		await expect(
			page.getByText( 'Payment method successfully added.', {
				exact: true,
			} )
		).toBeVisible();

		const after = await this.getSavedCardEvidence();
		const beforeByTokenId = new Map(
			before.tokens.map( ( token ) => [ token.tokenId, token ] )
		);
		for ( const token of before.tokens ) {
			const preserved = after.tokens.find(
				( candidate ) => candidate.tokenId === token.tokenId
			);
			if (
				! preserved ||
				preserved.paymentMethodId !== token.paymentMethodId
			) {
				throw new Error(
					`Saved-card ${ label } changed the existing local token ${ token.tokenId } mapping.`
				);
			}
		}

		const created = after.tokens.filter(
			( token ) => ! beforeByTokenId.has( token.tokenId )
		);
		if ( created.length !== 1 ) {
			throw new Error(
				`Saved-card ${ label } must create exactly one new local token; found ${ created.length }.`
			);
		}

		return {
			tokenId: created[ 0 ].tokenId,
			paymentMethodId: created[ 0 ].paymentMethodId,
		};
	}

	public async makeSavedCardDefault(
		page: Page,
		tokenId: number
	): Promise< void > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'saved-card-default' );
		await page.goto( 'my-account/payment-methods/' );
		const candidates = await page
			.getByRole( 'link', { name: /make default/i } )
			.all();
		const matchingActions: Locator[] = [];
		for ( const candidate of candidates ) {
			const href = await candidate.getAttribute( 'href' );
			if (
				href &&
				new URL( href, this.baseURL ).pathname.endsWith(
					`/set-default-payment-method/${ tokenId }/`
				)
			) {
				matchingActions.push( candidate );
			}
		}
		if ( matchingActions.length !== 1 ) {
			throw new Error(
				`Saved-card default action is not uniquely bound to local token ${ tokenId }.`
			);
		}
		await this.performWrite( () => matchingActions[ 0 ].click() );
	}

	public async softCutOverEphemeralStore( page: Page ): Promise< void > {
		await this.assertCanWrite();
		this.requireEphemeralTransitionAllocation();
		this.requireApprovedProviderFixture( 'soft-cutover' );
		await this.logInAsAdmin( page );
		await page.goto( 'wp-admin/' );
		const cutoverAction = page.getByRole( 'link', {
			name: 'Disable WooPayments',
			exact: true,
		} );
		await expect( cutoverAction ).toHaveAttribute(
			'href',
			/wc_woopayments_cutover_action=disable_woopayments/
		);
		const href = await cutoverAction.getAttribute( 'href' );
		if ( ! href ) {
			throw new Error(
				'The product WooPayments cutover action has no exact URL.'
			);
		}
		const url = new URL( href, this.baseURL );
		if (
			! url.pathname.endsWith( '/wp-admin/admin.php' ) ||
			url.searchParams.get( 'wc_woopayments_cutover_action' ) !==
				'disable_woopayments' ||
			! url.searchParams.get( '_wc_woopayments_cutover_nonce' )
		) {
			throw new Error(
				'The product WooPayments cutover action is not bound to the nonce-protected controller entry point.'
			);
		}
		await this.performWrite( () => cutoverAction.click() );
		await this.assertCurrentRuntimeReady( 'native' );
		await this.logInAsCustomer( page );
	}

	public async getSavedCardState(
		cards: readonly [
			firstCard: SavedCardIdentity,
			defaultCard: SavedCardIdentity
		]
	): Promise< SavedCardState > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'saved-card-state' );
		const [ firstCard, defaultCard ] = cards;
		if (
			firstCard.tokenId === defaultCard.tokenId ||
			firstCard.paymentMethodId === defaultCard.paymentMethodId
		) {
			throw new Error(
				'The two recorded saved cards must have distinct local and provider identities.'
			);
		}

		const evidence = await this.getSavedCardEvidence( defaultCard );
		for ( const [ index, card ] of cards.entries() ) {
			const localMatches = evidence.tokens.filter(
				( token ) => token.tokenId === card.tokenId
			);
			if (
				localMatches.length !== 1 ||
				localMatches[ 0 ].paymentMethodId !== card.paymentMethodId
			) {
				throw new Error(
					`The local token ${ card.tokenId } is not mapped exactly to ${ card.paymentMethodId }.`
				);
			}
			const shouldBeDefault = index === 1;
			if ( localMatches[ 0 ].isDefault !== shouldBeDefault ) {
				throw new Error(
					shouldBeDefault
						? `The local token ${ card.tokenId } is not the exact default payment method.`
						: `The local token ${ card.tokenId } must not remain the default payment method.`
				);
			}
		}

		if (
			evidence.providerDefaultPaymentMethodId !==
			defaultCard.paymentMethodId
		) {
			throw new Error(
				`The provider default is not the exact payment method ${ defaultCard.paymentMethodId }.`
			);
		}
		if ( ! evidence.providerCustomerId ) {
			throw new Error(
				'Named saved-card evidence did not contain an exact provider customer ID.'
			);
		}

		const paymentMethodsResponse = await this.adminApi.get(
			`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
				evidence.providerCustomerId
			) }/payment_methods`
		);
		if ( ! paymentMethodsResponse.ok() ) {
			throw new Error(
				`Provider payment-method evidence failed: HTTP ${ paymentMethodsResponse.status() }.`
			);
		}
		const paymentMethods = await paymentMethodsResponse.json();
		if ( ! Array.isArray( paymentMethods ) ) {
			throw new Error(
				'Provider payment-method evidence must be an array.'
			);
		}
		const providerIds = paymentMethods.map( ( paymentMethod, index ) => {
			if (
				typeof paymentMethod !== 'object' ||
				paymentMethod === null ||
				typeof ( paymentMethod as { id?: unknown } ).id !== 'string' ||
				( paymentMethod as { id: string } ).id === ''
			) {
				throw new Error(
					`Provider payment-method evidence at index ${ index } has no exact ID.`
				);
			}
			return ( paymentMethod as { id: string } ).id;
		} );
		for ( const card of cards ) {
			const providerMatches = providerIds.filter(
				( paymentMethodId ) => paymentMethodId === card.paymentMethodId
			);
			if ( providerMatches.length !== 1 ) {
				throw new Error(
					`Expected exactly one provider payment method ${ card.paymentMethodId }; found ${ providerMatches.length }.`
				);
			}
		}

		return {
			...defaultCard,
			isDefault: true,
			providerDefaultPaymentMethodId:
				evidence.providerDefaultPaymentMethodId,
		};
	}

	public async payWithExactSavedCard(
		page: Page,
		card: SavedCardIdentity,
		checkout: 'classic' | 'blocks',
		runId: string
	): Promise< number > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( `saved-card-${ checkout }` );
		const product = await this.createOwnedProduct( '10.99' );
		await page.goto( `?post_type=product&p=${ product.id }` );
		await this.performWrite( () =>
			page.getByRole( 'button', { name: /add to cart/i } ).click()
		);
		await page.goto(
			checkout === 'classic' ? 'classic-checkout/' : 'checkout/'
		);
		const token = page.locator(
			checkout === 'classic'
				? `input.woocommerce-SavedPaymentMethods-tokenInput[name="wc-woocommerce_payments-payment-token"][value="${ card.tokenId }"]`
				: `input.wc-block-components-radio-control__input[name="radio-control-wc-payment-method-saved-tokens"][value="${ card.tokenId }"]`
		);
		const localTokenId = await token.getAttribute( 'value' );
		if ( localTokenId !== card.tokenId.toString() ) {
			throw new Error(
				`Saved-card checkout selection is not bound to local token ${ card.tokenId }.`
			);
		}
		await token.check();
		await this.performWrite( () =>
			page.getByRole( 'button', { name: /place order/i } ).click()
		);
		await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/ );
		await expect(
			page.getByText( 'Your order has been received' )
		).toBeVisible();
		const orderId = this.getOrderIdFromUrl( page.url() );
		await this.setOrderRunId( orderId, runId );
		return orderId;
	}

	public async withProviderWriteLocks< Result >(
		options: ProviderWriteLockOptions,
		callback: () => Promise< Result >
	): Promise< Result > {
		if ( this.activeProviderWriteLocks ) {
			throw new Error( 'WooPayments provider write locks cannot nest.' );
		}
		const manager = new ResourceLockManager( {
			lockDir: this.lockDir,
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

			this.activeProviderWriteLocks = {
				account,
				store,
				featureSetting,
				recordEvent,
			};
			result = await callback();
		} catch ( error ) {
			primaryError = error;
		}

		this.activeProviderWriteLocks = undefined;
		for ( const lock of locks.toReversed() ) {
			try {
				const released = await lock.release();
				if ( ! released ) {
					teardownErrors.push(
						new Error(
							`WooPayments resource lock ${ lock.payload.key } was not released because ownership was lost.`
						)
					);
				}
			} catch ( error ) {
				teardownErrors.push(
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
		}

		if ( primaryError !== undefined ) {
			for ( const teardownError of teardownErrors ) {
				console.error(
					'WooPayments pilot teardown failed after the primary test failure:',
					teardownError
				);
			}
			throw primaryError;
		}
		if ( teardownErrors.length > 0 ) {
			throw new AggregateError(
				teardownErrors,
				'WooPayments pilot teardown failed.'
			);
		}
		return result as Result;
	}

	public async withCapturedManualCaptureSetting(
		callback: () => Promise< void >
	): Promise< void > {
		await this.withProviderWriteLocks(
			{
				featureSetting: 'manual-capture',
				recordEvent: 'merchant-manual-capture',
			},
			async () => {
				this.requireApprovedProviderFixture( 'manual-capture-setting' );
				const settingLock =
					this.activeProviderWriteLocks?.featureSetting;
				if ( ! settingLock ) {
					throw new Error(
						'Manual capture requires an owned feature-setting lock.'
					);
				}

				await settingLock.restoreFromJournalIfOwned(
					async ( originalValue ) => {
						await this.setManualCaptureSetting( originalValue );
					}
				);
				const original = await this.getManualCaptureSetting();
				await settingLock.writeRestorationJournal( original );
				let mutationMayHaveApplied = false;
				let primaryError: unknown;
				const teardownErrors: Error[] = [];

				try {
					mutationMayHaveApplied = true;
					await this.setManualCaptureSetting( true );
					await callback();
				} catch ( error ) {
					primaryError = error;
				}

				if ( mutationMayHaveApplied ) {
					try {
						const restored =
							await settingLock.restoreFromJournalIfOwned(
								async ( originalValue ) => {
									await this.setManualCaptureSetting(
										originalValue
									);
								}
							);
						if ( ! restored ) {
							teardownErrors.push(
								new Error(
									'Manual capture setting was not restored because the restoration journal or lock ownership was lost.'
								)
							);
						}
					} catch ( error ) {
						teardownErrors.push(
							error instanceof Error
								? error
								: new Error( String( error ) )
						);
					}
				}

				if ( primaryError !== undefined ) {
					for ( const teardownError of teardownErrors ) {
						console.error(
							'WooPayments pilot teardown failed after the primary test failure:',
							teardownError
						);
					}
					throw primaryError;
				}
				if ( teardownErrors.length > 0 ) {
					throw new AggregateError(
						teardownErrors,
						'WooPayments pilot teardown failed.'
					);
				}
			}
		);
	}

	public async captureExactOrder(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'manual-capture-action' );
		await this.logInAsAdmin( page );
		await page.goto( 'wp-admin/admin.php?page=wc-orders' );
		await page
			.getByRole( 'searchbox', { name: /search orders/i } )
			.fill( evidence.orderId.toString() );
		await page
			.getByRole( 'link', {
				name: new RegExp( evidence.orderId.toString() ),
			} )
			.click();
		await page.locator( 'select[name="wc_order_action"]' ).selectOption( {
			label: 'Capture charge',
			value: 'capture_charge',
		} );
		await this.performWrite( () =>
			page
				.locator( '#actions' )
				.getByRole( 'button', { name: /^Apply\b/ } )
				.click()
		);
	}

	public async expectCapturedOrderState(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		const providerReference =
			this.runtime === 'native'
				? page
						.getByRole( 'link', {
							name: evidence.intentId,
							exact: true,
						} )
						.first()
				: page.getByText( new RegExp( evidence.chargeId ) ).first();
		await expect( providerReference ).toBeVisible();
		await expect(
			page.getByText( /successfully captured.*WooPayments/i )
		).toBeVisible();
		await expect( page.locator( '#order_status' ) ).toHaveValue(
			'wc-processing'
		);
	}

	public async openExactMerchantTransaction(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		await this.assertCanWrite();
		await this.logInAsAdmin( page );
		await page.goto( 'wp-admin/' );

		await page
			.getByRole( 'link', {
				name: 'Payments',
				exact: true,
			} )
			.first()
			.click();
		const transactionsLink = page
			.getByRole( 'link', {
				name: 'Transactions',
				exact: true,
			} )
			.first();
		await expect( transactionsLink ).toHaveAttribute(
			'href',
			/transactions/
		);
		const transactionsUrl = await transactionsLink.getAttribute( 'href' );
		if ( ! transactionsUrl ) {
			throw new Error(
				'The WooPayments Transactions menu link has no destination.'
			);
		}
		await page.goto( transactionsUrl );

		if ( this.runtime === 'native' ) {
			await page
				.locator(
					`a[href*="id=${ encodeURIComponent( evidence.intentId ) }"]`
				)
				.first()
				.click();
			return;
		}

		await page
			.getByLabel( 'Search transactions', { exact: true } )
			.fill( evidence.orderId.toString() );
		await page
			.getByRole( 'link', {
				name: new RegExp(
					`${ evidence.orderId }|${ evidence.chargeId }`
				),
			} )
			.first()
			.click();
	}

	public async expectExactMerchantTransaction(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		await expect(
			page.getByRole( 'heading', {
				name: /^(Payment details|Transaction details)$/,
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', {
				name: `Order #${ evidence.orderId }`,
				exact: true,
			} )
		).toBeVisible();
		await expect(
			page.getByText( evidence.intentId, { exact: true } )
		).toBeVisible();
		await expect(
			page.getByText( evidence.chargeId, { exact: true } )
		).toBeVisible();
		await expect(
			page.getByText( evidence.currency, { exact: true } )
		).toBeVisible();
		await expect(
			page.getByText(
				evidence.providerStatus === 'requires_capture'
					? 'Authorized'
					: evidence.providerStatus
							.replace( /_/g, ' ' )
							.replace( /\b\w/g, ( value ) =>
								value.toUpperCase()
							),
				{ exact: true }
			)
		).toBeVisible();
	}

	public async cleanup(): Promise< void > {
		if ( this.ownedProductIds.length === 0 ) {
			return;
		}

		await this.withProviderWriteLocks(
			{ recordEvent: 'owned-product-cleanup' },
			async () => {
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
			}
		);
	}

	private async getSavedCardEvidence(
		card?: SavedCardIdentity
	): Promise< SavedCardEvidence > {
		const parameters = new URLSearchParams( {
			customer_username: customer.username,
		} );
		if ( card ) {
			parameters.set( 'token_id', card.tokenId.toString() );
			parameters.set( 'payment_method_id', card.paymentMethodId );
		}
		const response = await this.adminApi.get(
			`/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?${ parameters.toString() }`
		);
		if ( ! response.ok() ) {
			throw new Error(
				`Saved-card evidence failed: HTTP ${ response.status() }.`
			);
		}

		return this.parseSavedCardEvidence( await response.json() );
	}

	private parseSavedCardEvidence( value: unknown ): SavedCardEvidence {
		if ( typeof value !== 'object' || value === null ) {
			throw new Error( 'Saved-card evidence must be an object.' );
		}
		const raw = value as {
			creation_ready?: unknown;
			tokens?: unknown;
			provider_customer_id?: unknown;
			provider_default_payment_method_id?: unknown;
		};
		if ( typeof raw.creation_ready !== 'boolean' ) {
			throw new Error(
				'Saved-card evidence creation_ready must be a boolean.'
			);
		}
		if ( ! Array.isArray( raw.tokens ) ) {
			throw new Error( 'Saved-card evidence tokens must be an array.' );
		}

		const tokenIds = new Set< number >();
		const paymentMethodIds = new Set< string >();
		const tokens = raw.tokens.map( ( tokenValue, index ) => {
			if ( typeof tokenValue !== 'object' || tokenValue === null ) {
				throw new Error(
					`Saved-card evidence token ${ index } must be an object.`
				);
			}
			const token = tokenValue as {
				token_id?: unknown;
				payment_method_id?: unknown;
				is_default?: unknown;
			};
			if (
				typeof token.token_id !== 'number' ||
				! Number.isSafeInteger( token.token_id ) ||
				token.token_id <= 0
			) {
				throw new Error(
					`Saved-card evidence token_id at index ${ index } must be a positive integer.`
				);
			}
			if (
				typeof token.payment_method_id !== 'string' ||
				token.payment_method_id === ''
			) {
				throw new Error(
					`Saved-card evidence payment_method_id at index ${ index } must be a non-empty string.`
				);
			}
			if ( typeof token.is_default !== 'boolean' ) {
				throw new Error(
					`Saved-card evidence is_default at index ${ index } must be a boolean.`
				);
			}
			if ( tokenIds.has( token.token_id ) ) {
				throw new Error(
					`Saved-card evidence contains duplicate token_id ${ token.token_id }.`
				);
			}
			if ( paymentMethodIds.has( token.payment_method_id ) ) {
				throw new Error(
					`Saved-card evidence contains duplicate payment_method_id ${ token.payment_method_id }.`
				);
			}
			tokenIds.add( token.token_id );
			paymentMethodIds.add( token.payment_method_id );

			return {
				tokenId: token.token_id,
				paymentMethodId: token.payment_method_id,
				isDefault: token.is_default,
			};
		} );

		const providerCustomerId = raw.provider_customer_id;
		if (
			providerCustomerId !== undefined &&
			( typeof providerCustomerId !== 'string' ||
				providerCustomerId === '' )
		) {
			throw new Error(
				'Saved-card evidence provider_customer_id must be a non-empty string.'
			);
		}
		const providerDefaultPaymentMethodId =
			raw.provider_default_payment_method_id;
		if (
			providerDefaultPaymentMethodId !== undefined &&
			( typeof providerDefaultPaymentMethodId !== 'string' ||
				providerDefaultPaymentMethodId === '' )
		) {
			throw new Error(
				'Saved-card evidence provider_default_payment_method_id must be a non-empty string.'
			);
		}

		return {
			creationReady: raw.creation_ready,
			tokens,
			providerCustomerId,
			providerDefaultPaymentMethodId,
		};
	}

	private async waitForSavedCardCreationReady(): Promise< SavedCardEvidence > {
		const deadline = Date.now() + 25_000;
		let evidence = await this.getSavedCardEvidence();
		while ( ! evidence.creationReady ) {
			const remaining = deadline - Date.now();
			if ( remaining <= 0 ) {
				throw new Error(
					'Core add-payment-method rate limit did not become ready before the saved-card deadline.'
				);
			}
			await new Promise( ( resolve ) =>
				setTimeout( resolve, Math.min( 500, remaining ) )
			);
			evidence = await this.getSavedCardEvidence();
		}
		return evidence;
	}

	private async assertCanWrite(): Promise< void > {
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

	private async performWrite< Result >(
		write: () => Promise< Result >
	): Promise< Result > {
		await this.assertCanWrite();
		return write();
	}

	private async logInAsAdmin( page: Page ): Promise< void > {
		await page.context().clearCookies();
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( admin.username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( admin.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
	}

	private async logInAsCustomer( page: Page ): Promise< void > {
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

	private async assertCurrentRuntimeReady(
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
				status = ( await response.json() ) as RuntimeStatus;
				assertRuntimeReady(
					runtime,
					{
						...status,
						callback_probe: {
							registered: true,
							reachable: true,
							wpcom_blog_id: this.wpcomBlogId,
						},
					},
					{
						siteUrl: this.baseURL,
						wpcomBlogId: this.wpcomBlogId,
						accountId: this.accountId,
					}
				);
			} catch ( error ) {
				lastFailure =
					error instanceof Error
						? error
						: new Error( String( error ) );
			}

			if ( status ) {
				await withValidatedCallbackProbe(
					status,
					{
						siteUrl: this.baseURL,
						wpcomBlogId: this.wpcomBlogId,
					},
					this.callbackProbeRunner
				);
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

	private getOrderIdFromUrl( url: string ): number {
		const match = url.match( /order-received\/(\d+)/ );
		if ( ! match ) {
			throw new Error(
				`Checkout confirmation did not expose a durable order ID: ${ url }`
			);
		}
		return Number( match[ 1 ] );
	}

	private async setOrderRunId(
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

	private async getManualCaptureSetting(): Promise< boolean > {
		const response = await this.adminApi.get(
			'/wp-json/wc/v3/payments/settings'
		);
		if ( response.status() !== 200 ) {
			throw new Error(
				`Unable to read manual capture setting: HTTP ${ response.status() }.`
			);
		}
		const settings = ( await response.json() ) as {
			is_manual_capture_enabled?: unknown;
		};
		if ( typeof settings.is_manual_capture_enabled !== 'boolean' ) {
			throw new Error(
				'Manual capture setting response is not a boolean.'
			);
		}
		return settings.is_manual_capture_enabled;
	}

	private async setManualCaptureSetting( value: boolean ): Promise< void > {
		const response = await this.performWrite( () =>
			this.adminApi.post( '/wp-json/wc/v3/payments/settings', {
				data: { is_manual_capture_enabled: value },
			} )
		);
		if ( response.status() !== 200 ) {
			throw new Error(
				`Unable to update manual capture setting: HTTP ${ response.status() }.`
			);
		}
	}

	private async fillCheckoutDetails(
		page: Page,
		runId: string
	): Promise< boolean > {
		const shippingAddress = page.getByRole( 'group', {
			name: 'Shipping address',
		} );
		const billingAddress = page.getByRole( 'group', {
			name: 'Billing address',
		} );
		const blocksAddress = ( await shippingAddress.isVisible() )
			? shippingAddress
			: billingAddress;
		if ( await blocksAddress.isVisible() ) {
			await page
				.getByRole( 'textbox', { name: 'Email address' } )
				.fill( `woopayments-${ runId }@example.com` );
			await blocksAddress
				.getByRole( 'combobox', { name: 'Country/Region' } )
				.selectOption( 'US' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'First name' } )
				.fill( 'E2E' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'Last name' } )
				.fill( 'WooPayments' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'Address', exact: true } )
				.fill( '123 Test Street' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'City', exact: true } )
				.fill( 'San Francisco' );
			await blocksAddress
				.getByRole( 'combobox', { name: 'State', exact: true } )
				.selectOption( 'CA' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'ZIP Code' } )
				.fill( '94107' );
			await blocksAddress
				.getByRole( 'textbox', { name: 'Phone (optional)' } )
				.fill( '5555550100' );
			await page
				.getByRole( 'group', { name: 'Payment options' } )
				.getByRole( 'radio', { name: /Card/i } )
				.check();
			return true;
		}

		await page
			.getByRole( 'textbox', { name: /first name/i } )
			.fill( 'E2E' );
		await page
			.getByRole( 'textbox', { name: /last name/i } )
			.fill( 'WooPayments' );
		await page
			.getByRole( 'textbox', { name: /street address/i } )
			.fill( '123 Test Street' );
		await page
			.getByRole( 'textbox', { name: /town|city/i } )
			.fill( 'San Francisco' );
		await page
			.getByRole( 'textbox', { name: /zip|postcode/i } )
			.fill( '94107' );
		await page
			.getByRole( 'textbox', { name: /phone/i } )
			.fill( '5555550100' );
		await page
			.getByRole( 'textbox', { name: /email/i } )
			.fill( `woopayments-${ runId }@example.com` );
		await page.getByLabel( /WooPayments|credit card/i ).check();
		return false;
	}

	private async fillBasicTestCard(
		page: Page,
		isBlockCheckout: boolean
	): Promise< void > {
		if ( isBlockCheckout ) {
			const frame = page.frameLocator(
				getBlocksCardFrameSelector( this.runtime )
			);
			await frame
				.getByRole( 'textbox', { name: 'Card number' } )
				.fill( '4242424242424242' );
			await frame
				.getByRole( 'textbox', { name: /Expiration date/i } )
				.fill( '0245' );
			await frame
				.getByRole( 'textbox', { name: 'Security code' } )
				.fill( '424' );
			await page.getByRole( 'button', { name: /place order/i } ).focus();
			return;
		}

		const upeContainer = page.locator(
			'#payment .payment_method_woocommerce_payments .wcpay-upe-element'
		);
		let cardNumber: Locator;
		let expiry: Locator;
		let cvc: Locator;

		if ( await upeContainer.isVisible() ) {
			const frame = page.frameLocator(
				'#payment .payment_method_woocommerce_payments .wcpay-upe-element iframe'
			);
			cardNumber = frame.locator( '[name="number"]' );
			expiry = frame.locator( '[name="expiry"]' );
			cvc = frame.locator( '[name="cvc"]' );
		} else {
			const frame = page.frameLocator(
				'#payment #wcpay-card-element iframe[name^="__privateStripeFrame"]'
			);
			cardNumber = frame.locator( '[name="cardnumber"]' );
			expiry = frame.locator( '[name="exp-date"]' );
			cvc = frame.locator( '[name="cvc"]' );
		}

		await cardNumber.fill( '4242424242424242' );
		await expiry.fill( '0245' );
		await cvc.fill( '424' );
		await page.getByRole( 'button', { name: /place order/i } ).focus();
	}

	private async getBlocksCheckoutDiagnostics(
		page: Page
	): Promise< unknown > {
		const dataStoreState = await page.evaluate( () => {
			type Selector = ( ...args: unknown[] ) => unknown;
			type Store = Record< string, Selector >;
			const wpData = (
				window as Window & {
					wp?: {
						data?: {
							select?: ( key: string ) => Store;
						};
					};
				}
			 ).wp?.data;
			const select = wpData?.select;
			if ( ! select ) {
				return { dataStoresAvailable: false };
			}

			const checkout = select( 'wc/store/checkout' );
			const payment = select( 'wc/store/payment' );
			const validation = select( 'wc/store/validation' );
			const call = ( store: Store, method: string ) =>
				typeof store?.[ method ] === 'function'
					? store[ method ]()
					: undefined;
			const availablePaymentMethods = call(
				payment,
				'getAvailablePaymentMethods'
			);
			const paymentMethodData = call( payment, 'getPaymentMethodData' );
			const validationErrors = call( validation, 'getValidationErrors' );
			return {
				dataStoresAvailable: true,
				checkoutStatus: call( checkout, 'getCheckoutStatus' ),
				checkoutHasError: call( checkout, 'hasError' ),
				checkoutIsCalculating: call( checkout, 'isCalculating' ),
				activePaymentMethod: call( payment, 'getActivePaymentMethod' ),
				paymentIsIdle: call( payment, 'isPaymentIdle' ),
				paymentIsProcessing: call( payment, 'isPaymentProcessing' ),
				paymentIsReady: call( payment, 'isPaymentReady' ),
				paymentHasError: call( payment, 'hasPaymentError' ),
				availablePaymentMethodIds:
					availablePaymentMethods &&
					typeof availablePaymentMethods === 'object'
						? Object.keys( availablePaymentMethods )
						: [],
				paymentMethodDataKeys:
					paymentMethodData && typeof paymentMethodData === 'object'
						? Object.keys( paymentMethodData )
						: [],
				validationErrorIds:
					validationErrors && typeof validationErrors === 'object'
						? Object.keys( validationErrors )
						: [],
			};
		} );
		const visibleNotices = await page
			.locator(
				'[role="alert"]:visible, .wc-block-components-notice-banner:visible'
			)
			.allTextContents();

		return {
			...dataStoreState,
			visibleNotices: visibleNotices.map( ( notice ) =>
				notice.trim().replace( /\s+/g, ' ' )
			),
		};
	}
}

interface WooPaymentsNativeFixtures {
	adminApi: APIRequestContext;
	pilotRuntime: WooPaymentsPilotRuntime;
	runId: string;
	runtimeReadiness: void;
}

export const test = baseTest.extend< WooPaymentsNativeFixtures >( {
	adminApi: async ( { baseURL, browser }, use ) => {
		if ( ! baseURL ) {
			throw new Error( 'BASE_URL is required for WooPayments pilots.' );
		}
		const adminContext = await browser.newContext( {
			baseURL,
		} );
		await authenticateAdminContext( adminContext, {
			username: requireValue(
				'E2E_WOOPAYMENTS_ADMIN_USERNAME',
				admin.username
			),
			password: requireValue(
				'E2E_WOOPAYMENTS_ADMIN_PASSWORD',
				admin.password
			),
		} );
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
		async ( { adminApi, baseURL }, use ) => {
			const runtime = getRuntime();
			const expected = {
				siteUrl: requireValue( 'E2E_WOOPAYMENTS_SITE_URL', baseURL ),
				wpcomBlogId: requireNumber( 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID' ),
				accountId: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' ),
			};
			const status = await loadInitialRuntimeStatus(
				runtime,
				adminApi,
				process.env.E2E_WOOPAYMENTS_DIAGNOSTICS_DIR,
				{
					siteUrl: expected.siteUrl,
					wpcomBlogId: expected.wpcomBlogId,
				}
			);
			assertRuntimeReady( runtime, status, expected );

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
	pilotRuntime: async ( { adminApi, baseURL, runId }, use ) => {
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
			requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' )
		);
		await use( runtime );
		await runtime.cleanup();
	},
} );

export { expect };
