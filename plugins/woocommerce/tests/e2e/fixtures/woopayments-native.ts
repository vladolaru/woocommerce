import { randomUUID } from 'node:crypto';

import {
	expect,
	request as playwrightRequest,
	type APIRequestContext,
	type Page,
} from '@playwright/test';

import { test as baseTest } from './fixtures';
import { admin } from '../test-data/data';
import {
	assertRuntimeReady,
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

export class WooPaymentsPilotRuntime {
	private readonly adminApi: APIRequestContext;
	private readonly runtime: WooPaymentsRuntime;
	private readonly runId: string;
	private readonly baseURL: string;
	private readonly wpcomBlogId: number;
	private readonly storeId: string;
	private readonly accountId: string;
	private readonly lockDir?: string;
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
		lockDir?: string
	) {
		this.adminApi = adminApi;
		this.runtime = runtime;
		this.runId = runId;
		this.baseURL = baseURL;
		this.wpcomBlogId = wpcomBlogId;
		this.storeId = storeId;
		this.accountId = accountId;
		this.lockDir = lockDir;
	}

	public requireApprovedProviderFixture( capability: string ): void {
		throw new Error(
			`WooPayments ${ capability } pilot is fail-closed: no owner-approved provider fixture interface is available.`
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
			page.getByRole( 'button', { name: /add to cart/i } ).click()
		);
		await page.getByRole( 'link', { name: /checkout/i } ).click();
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

		this.requireApprovedProviderFixture( 'basic-card-entry' );
		await this.performWrite( () =>
			page.getByRole( 'button', { name: /place order/i } ).click()
		);
		await expect(
			page.getByText( 'Your order has been received' )
		).toBeVisible();

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
		await page.goto( 'my-account/payment-methods/' );
		await page.getByRole( 'link', { name: /add payment method/i } ).click();
		await page.getByLabel( /card label/i ).fill( label );
		throw new Error(
			'Owner-approved saved-card fixture has not supplied durable token and payment-method IDs.'
		);
	}

	public async makeSavedCardDefault(
		page: Page,
		tokenId: number
	): Promise< void > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'saved-card-default' );
		await page.goto( 'my-account/payment-methods/' );
		const action = page.locator(
			`a.button.default[href*="/set-default-payment-method/${ tokenId }/"]`
		);
		const href = await action.getAttribute( 'href' );
		if (
			! href ||
			! new URL( href, this.baseURL ).pathname.endsWith(
				`/set-default-payment-method/${ tokenId }/`
			)
		) {
			throw new Error(
				`Saved-card default action is not bound to local token ${ tokenId }.`
			);
		}
		await this.performWrite( () => action.click() );
	}

	public async softCutOverEphemeralStore( page: Page ): Promise< void > {
		await this.assertCanWrite();
		this.requireEphemeralTransitionAllocation();
		this.requireApprovedProviderFixture( 'soft-cutover' );
		await page.goto( 'wp-admin/' );
		await page
			.getByRole( 'link', { name: /WooCommerce/i } )
			.first()
			.click();
		await this.performWrite( () =>
			page
				.getByRole( 'button', {
					name: /switch to native WooPayments/i,
				} )
				.click()
		);
		await this.assertCurrentRuntimeReady( 'native' );
	}

	public async getSavedCardState(
		card: SavedCardIdentity
	): Promise< SavedCardState > {
		await this.assertCanWrite();
		this.requireApprovedProviderFixture( 'saved-card-state' );
		throw new Error(
			`Owner-approved saved-card state fixture has not proved local token ${ card.tokenId } and provider default ${ card.paymentMethodId }.`
		);
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
		await page
			.getByRole( 'link', {
				name:
					checkout === 'classic' ? /classic checkout/i : /checkout/i,
			} )
			.click();
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
				.getByRole( 'button', { name: 'Apply', exact: true } )
				.click()
		);
	}

	public async expectCapturedOrderState(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		await expect(
			page.getByText( new RegExp( evidence.chargeId ) )
		).toBeVisible();
		await expect(
			page.getByText( /successfully captured.*WooPayments/i )
		).toBeVisible();
		await expect(
			page.getByText( /order status.*processing/i )
		).toBeVisible();
	}

	public async openExactMerchantTransaction(
		page: Page,
		evidence: PaymentEvidence
	): Promise< void > {
		await this.assertCanWrite();
		await this.logInAsAdmin( page );
		await page.goto( 'wp-admin/' );

		const paymentsLink =
			this.runtime === 'client'
				? page.getByRole( 'link', {
						name: /Payments/i,
				  } )
				: page.getByRole( 'link', {
						name: /WooPayments/i,
				  } );
		await paymentsLink.first().click();
		await page.getByRole( 'link', { name: /transactions/i } ).click();
		await page
			.getByRole( 'searchbox', { name: /search transactions/i } )
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
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( admin.username );
		await page.getByLabel( 'Password' ).fill( admin.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
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
			try {
				if ( ! response.ok() ) {
					throw new Error(
						`Runtime ownership recheck failed: HTTP ${ response.status() }.`
					);
				}
				assertRuntimeReady(
					runtime,
					( await response.json() ) as RuntimeStatus,
					{
						siteUrl: this.baseURL,
						wpcomBlogId: this.wpcomBlogId,
						accountId: this.accountId,
					}
				);
				return;
			} catch ( error ) {
				lastFailure =
					error instanceof Error
						? error
						: new Error( String( error ) );
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
}

interface WooPaymentsNativeFixtures {
	adminApi: APIRequestContext;
	pilotRuntime: WooPaymentsPilotRuntime;
	runId: string;
	runtimeReadiness: void;
}

export const test = baseTest.extend< WooPaymentsNativeFixtures >( {
	adminApi: async ( { baseURL }, use ) => {
		if ( ! baseURL ) {
			throw new Error( 'BASE_URL is required for WooPayments pilots.' );
		}
		const adminApi = await playwrightRequest.newContext( {
			baseURL,
			httpCredentials: {
				username: admin.username,
				password: admin.password,
			},
		} );
		await use( adminApi );
		await adminApi.dispose();
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
				siteUrl: requireValue( 'BASE_URL', baseURL ),
				wpcomBlogId: requireNumber( 'E2E_WOOPAYMENTS_WPCOM_BLOG_ID' ),
				accountId: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ID' ),
			};
			const response = await adminApi.get(
				'/wp-json/wc-native-payments-e2e/v1/status'
			);
			if ( ! response.ok() ) {
				throw new Error(
					`Runtime readiness route failed: HTTP ${ response.status() }.`
				);
			}
			const status = ( await response.json() ) as RuntimeStatus;
			assertRuntimeReady( runtime, status, expected );

			const allocation = {
				storeId: requireValue( 'E2E_WOOPAYMENTS_STORE_ID' ),
				accountId: expected.accountId,
				accountAlias: requireValue( 'E2E_WOOPAYMENTS_ACCOUNT_ALIAS' ),
			};
			assertAccountSeparation( allocation, requireAllocations(), {
				isCI: !! process.env.CI,
				ciAccountAlias: requireValue(
					'E2E_WOOPAYMENTS_CI_ACCOUNT_ALIAS'
				),
				ciAccountId: requireValue( 'E2E_WOOPAYMENTS_CI_ACCOUNT_ID' ),
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
