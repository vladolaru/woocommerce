import {
	errors,
	expect,
	type Locator,
	type Page,
	type Request,
} from '@playwright/test';

import type { WooPaymentsRuntime } from '../runtime-readiness';
import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';

export function getBlocksCardFrameSelector(
	runtime: WooPaymentsRuntime
): string {
	return runtime === 'client'
		? '#payment-method .wcpay-payment-element iframe[name^="__privateStripeFrame"]'
		: '#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]';
}

export async function submitBlocksCheckout(
	page: Page,
	click: ( button: Locator ) => Promise< 'dispatched' | 'not-dispatched' >,
	options: { submissionWaitMs?: number } = {}
): Promise< void > {
	const submissionWaitMs = options.submissionWaitMs ?? 10_000;
	const checkoutUrl = page.url();
	const button = page.getByRole( 'button', { name: /place order/i } );
	let checkoutRequestObserved = false;
	let resolveCheckoutRequest = () => {};
	const checkoutRequestStarted = new Promise< void >( ( resolveRequest ) => {
		resolveCheckoutRequest = resolveRequest;
	} );
	const countCheckoutRequest = ( request: Request ): void => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		try {
			if (
				new URL( request.url() ).pathname.replace( /\/+$/, '' ) ===
				'/wp-json/wc/store/v1/checkout'
			) {
				checkoutRequestObserved = true;
				resolveCheckoutRequest();
			}
		} catch {
			// Unparsable URLs cannot be the Store API checkout endpoint.
		}
	};
	page.on( 'request', countCheckoutRequest );

	try {
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			const outcome = await click( button );
			if ( outcome === 'not-dispatched' ) {
				if ( checkoutRequestObserved || page.url() !== checkoutUrl ) {
					return;
				}
				continue;
			}

			const checkoutStateOrNavigationStarted = page.waitForFunction(
				( initialCheckoutUrl ) => {
					if ( window.location.href !== initialCheckoutUrl ) {
						return true;
					}
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
				checkoutUrl,
				{ timeout: submissionWaitMs }
			);
			try {
				await Promise.race( [
					checkoutRequestStarted,
					checkoutStateOrNavigationStarted,
				] );
				return;
			} catch ( error ) {
				if ( page.url() !== checkoutUrl ) {
					return;
				}
				if ( ! ( error instanceof errors.TimeoutError ) ) {
					throw error;
				}
				throw new Error(
					`WooPayments Blocks checkout was dispatched, but no checkout request, Core state, or navigation signal was observed within ${ submissionWaitMs }ms. Refusing to retry because the provider outcome is uncertain.`
				);
			}
		}
	} finally {
		page.off( 'request', countCheckoutRequest );
	}

	throw new ProviderSubmissionNotStartedError(
		'WooPayments Blocks checkout was not dispatched after 3 explicit not-dispatched attempts.'
	);
}

async function fillCheckoutDetails(
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

	await page.getByRole( 'textbox', { name: /first name/i } ).fill( 'E2E' );
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
	await page.getByRole( 'textbox', { name: /phone/i } ).fill( '5555550100' );
	await page
		.getByRole( 'textbox', { name: /email/i } )
		.fill( `woopayments-${ runId }@example.com` );
	await page.getByLabel( /WooPayments|credit card/i ).check();
	return false;
}

async function fillBasicTestCard(
	session: ProviderWriteSession,
	page: Page,
	isBlockCheckout: boolean
): Promise< void > {
	if ( isBlockCheckout ) {
		const frame = page.frameLocator(
			getBlocksCardFrameSelector( session.runtime )
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

async function getBlocksCheckoutDiagnostics( page: Page ): Promise< unknown > {
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

export async function completeCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	runId: string
): Promise< number > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'basic-card' );

	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page
			.getByRole( 'button', {
				name: 'Add to cart',
				exact: true,
			} )
			.click()
	);
	await page.goto( 'checkout/' );
	const isBlockCheckout = await fillCheckoutDetails( page, runId );

	session.requireApprovedProviderFixture( 'basic-card-entry' );
	await fillBasicTestCard( session, page, isBlockCheckout );
	await session.withProviderSubmissionJournal(
		`basic-card-${ isBlockCheckout ? 'blocks' : 'classic' }-checkout`,
		async () => {
			if ( isBlockCheckout ) {
				await submitBlocksCheckout( page, async ( button ) => {
					await session.performWrite( () => button.click() );
					return 'dispatched';
				} );
			} else {
				await session.performWrite( () =>
					page.getByRole( 'button', { name: /place order/i } ).click()
				);
			}
			try {
				await page.waitForURL(
					/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/,
					{ timeout: 60_000 }
				);
				await expect(
					page.getByText(
						/^(Your order has been received|Order received)$/i
					)
				).toBeVisible();
			} catch ( error ) {
				const diagnostics = isBlockCheckout
					? await getBlocksCheckoutDiagnostics( page )
					: undefined;
				throw new ResourceQuarantineRequiredError(
					`WooPayments checkout submission has no proven outcome${
						diagnostics
							? `: ${ JSON.stringify( diagnostics ) }`
							: '.'
					}`,
					'uncertain-provider-write',
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
		}
	);

	const orderId = session.getOrderIdFromUrl( page.url() );
	await session.setOrderRunId( orderId, runId );
	return orderId;
}
