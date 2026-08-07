import type {
	APIRequestContext,
	FrameLocator,
	Locator,
	Page,
} from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	tags,
	test,
} from '../../../fixtures/woopayments-native';

// Three of the six contracts below name the classic checkout and My Account
// card mounts. By the owner-accepted family downscope, this smoke exercises
// the blocks checkout payment element as the family representative; the other
// mounts' core-side handling is each row's retained lower-layer evidence.
const CHECKOUT_FAILURES_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:';
const BLOCKS_FAILURES_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:';
const CONTRACT_IDS = [
	`${ CHECKOUT_FAILURES_PREFIX }89::Shopper › Checkout › Failures with various cards › should throw an error that the card CVV number is invalid`,
	`${ CHECKOUT_FAILURES_PREFIX }155::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to incorrect card number`,
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a declined-incorrect card › it should not add the card',
	`${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card number is invalid.`,
	`${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card’s expiration year is in the past.`,
	`${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card’s security code is incomplete.`,
];

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';

// One run-stable virtual product owned by this smoke, established idempotently
// on every run; nothing is deleted.
const PRODUCT_SLUG = 'woopayments-declines-smoke';
const PRODUCT_NAME = 'WooPayments declines smoke';
const PRODUCT_PRICE = '10.00';

// Inputs that the provider's client-side validation must reject: a
// Luhn-failing number, an expiration date in the past, and an incomplete
// security code. The assertions below are deliberately copy-free — the error
// text belongs to the provider and changes with its releases; what this smoke
// pins is that each rejection is exposed accessibly and is associated with
// the exact field it concerns.
const INVALID_CARD_NUMBER = '4242424242424241';
const VALID_CARD_NUMBER = '4242424242424242';
// Unformatted four-digit expiry values, matching how the suite's checkout
// driver feeds the same masked field elsewhere.
const PAST_EXPIRY = '0120';
const FUTURE_EXPIRY = '1234';
const INCOMPLETE_CVC = '1';
const COMPLETE_CVC = '123';

async function readJson(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Record< string, unknown > > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Record< string, unknown >;
}

function cardFrameRuntime(): 'client' | 'native' {
	const runtime = process.env.WCPAY_RUNTIME;
	if ( runtime !== 'client' && runtime !== 'native' ) {
		throw new Error(
			'WCPAY_RUNTIME must be client or native for this smoke.'
		);
	}
	return runtime;
}

async function ensureSmokeProduct(
	adminApi: APIRequestContext
): Promise< number > {
	const lookup = await adminApi.get(
		`/wp-json/wc/v3/products?slug=${ PRODUCT_SLUG }&status=any`
	);
	if ( ! lookup.ok() ) {
		throw new Error(
			`Smoke product lookup failed: HTTP ${ lookup.status() } ${ await lookup.text() }`
		);
	}
	const existing = ( await lookup.json() ) as Array< {
		id: number;
		status: string;
	} >;
	if ( existing.length > 0 ) {
		// A same-slug product in any other status would make a fresh create
		// silently take a suffixed slug and orphan a product per run; fail at
		// the drift instead.
		if ( existing[ 0 ].status !== 'publish' ) {
			throw new Error(
				`Smoke product slug is occupied by a ${ existing[ 0 ].status } product; expected publish.`
			);
		}
		return existing[ 0 ].id;
	}

	const created = await readJson(
		await adminApi.post( '/wp-json/wc/v3/products', {
			data: {
				name: PRODUCT_NAME,
				slug: PRODUCT_SLUG,
				type: 'simple',
				virtual: true,
				regular_price: PRODUCT_PRICE,
				status: 'publish',
			},
		} ),
		'Smoke product creation'
	);
	return created.id as number;
}

/**
 * Count orders containing the smoke product. Scoping to the run-owned product
 * keeps the counter exact for this smoke even if unrelated activity creates
 * orders on the store concurrently.
 */
async function countSmokeProductOrders(
	adminApi: APIRequestContext,
	productId: number
): Promise< number > {
	const response = await adminApi.get(
		`/wp-json/wc/v3/orders?product=${ productId }&per_page=1`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Order count failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	const total = Number( response.headers()[ 'x-wp-total' ] );
	if ( ! Number.isSafeInteger( total ) || total < 0 ) {
		throw new Error( 'Order count did not return a usable X-WP-Total.' );
	}
	return total;
}

/**
 * Count POSTs to the Store API checkout route so the test can prove that no
 * gesture ever dispatched a checkout submission. Matches the pretty-permalink
 * path and the rest_route query form.
 */
function trackCheckoutSubmissions( page: Page ): () => number {
	let submissionCount = 0;
	page.on( 'request', ( request ) => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		try {
			const url = new URL( request.url() );
			const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
			if (
				url.pathname.replace( /\/+$/, '' ) ===
					'/wp-json/wc/store/v1/checkout' ||
				restRoute.replace( /\/+$/, '' ) === '/wc/store/v1/checkout'
			) {
				submissionCount++;
			}
		} catch {
			// Unparsable URLs cannot be the Store API checkout endpoint.
		}
	} );
	return () => submissionCount;
}

/**
 * Assert the provider's field-level rejection is exposed accessibly: the
 * field is marked invalid, and one of the elements programmatically
 * associated with it through aria-describedby is a non-empty role=alert
 * error element, so the rejection is both field-associated and announced.
 * The whole predicate polls as one unit because the provider may commit the
 * invalid state and the description wiring in separate DOM updates. No
 * provider copy and no provider id-naming convention is asserted.
 */
async function expectAccessibleFieldError(
	frame: FrameLocator,
	field: Locator
): Promise< void > {
	await expect( field ).toHaveAttribute( 'aria-invalid', 'true' );

	await expect
		.poll(
			async () => {
				const describedBy =
					( await field.getAttribute( 'aria-describedby' ) ) ?? '';
				for ( const id of describedBy
					.split( /\s+/ )
					.filter( Boolean ) ) {
					const description = frame.locator(
						`[id="${ id }"][role="alert"]`
					);
					if ( ( await description.count() ) === 0 ) {
						continue;
					}
					const text = ( await description.textContent() ) ?? '';
					if ( text.trim() !== '' ) {
						return id;
					}
				}
				return '';
			},
			{
				message:
					'The invalid field must reference a non-empty alert error element through aria-describedby.',
			}
		)
		.not.toBe( '' );
}

/**
 * Assert the field recovered after correction: it still holds the corrected
 * value (so a remounted empty field cannot masquerade as recovery), is no
 * longer marked invalid, and keeps no lingering alert error description —
 * the error state is genuinely input-driven rather than latched, and a
 * shopper tabbing back onto a corrected field is not still told it is wrong.
 */
async function expectFieldRecovered(
	frame: FrameLocator,
	field: Locator,
	correctedValue: RegExp
): Promise< void > {
	await expect( field ).toHaveValue( correctedValue );
	await expect( field ).toHaveAttribute( 'aria-invalid', 'false' );

	await expect
		.poll(
			async () => {
				const describedBy =
					( await field.getAttribute( 'aria-describedby' ) ) ?? '';
				for ( const id of describedBy
					.split( /\s+/ )
					.filter( Boolean ) ) {
					const description = frame.locator(
						`[id="${ id }"][role="alert"]`
					);
					if ( ( await description.count() ) === 0 ) {
						continue;
					}
					const text = ( await description.textContent() ) ?? '';
					if ( text.trim() !== '' ) {
						return id;
					}
				}
				return '';
			},
			{
				message:
					'A corrected field must not keep a lingering alert error description.',
			}
		)
		.toBe( '' );
}

test(
	'invalid card input yields accessible field-associated errors before any payment dispatch',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page } ) => {
		const productId = await ensureSmokeProduct( adminApi );

		// Precondition guard: the accessible-rejection assertions below are
		// vacuous unless the native runtime actually mounts the provider's
		// validating payment element. A store whose gateway deactivated must
		// fail here, loudly, not pass by never reaching a card field.
		const paymentsSettings = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );

		const ordersBefore = await countSmokeProductOrders(
			adminApi,
			productId
		);
		const submissions = trackCheckoutSubmissions( page );

		await page.goto( `?add-to-cart=${ productId }` );
		await page.goto( 'checkout/' );
		const frameSelector = getBlocksCardFrameSelector( cardFrameRuntime() );
		const cardFrame = page.locator( frameSelector ).first();
		await expect( cardFrame ).toBeVisible();
		await expect( cardFrame ).not.toHaveAttribute( 'aria-hidden', 'true' );
		await expect( cardFrame ).toHaveAttribute( 'title', /\S/ );
		// Derive the frame from the exact element the gate just proved, so the
		// gate and the interaction target are the same iframe by construction.
		const frame = cardFrame.contentFrame();
		const numberField = frame.getByRole( 'textbox', {
			name: /card number/i,
		} );
		const expiryField = frame.getByRole( 'textbox', {
			name: /expir/i,
		} );
		const cvcField = frame.getByRole( 'textbox', {
			name: /security|cvc|cvv/i,
		} );
		// The payment element genuinely mounted with all three card fields:
		// the state every rejection assertion below depends on.
		await expect( numberField ).toBeVisible();
		await expect( expiryField ).toBeVisible();
		await expect( cvcField ).toBeVisible();

		// Gesture 1: a Luhn-failing card number is rejected on the field
		// with an accessible, field-associated announcement, and correcting
		// the input clears the rejection. Each gesture starts from a proven
		// valid baseline so the invalid state it asserts is caused by its
		// own input, and after each blur click focus must rest on the
		// clicked field: an error must not steal the shopper's place in the
		// form.
		await expect( numberField ).toHaveAttribute( 'aria-invalid', 'false' );
		await numberField.click();
		await numberField.fill( INVALID_CARD_NUMBER );
		await expiryField.click();
		await expect( expiryField ).toBeFocused();
		await expectAccessibleFieldError( frame, numberField );
		await numberField.fill( VALID_CARD_NUMBER );
		await expiryField.click();
		await expectFieldRecovered( frame, numberField, /4242.4242.4242.4242/ );

		// Gesture 2: an expiration date in the past.
		await expect( expiryField ).toHaveAttribute( 'aria-invalid', 'false' );
		await expiryField.fill( PAST_EXPIRY );
		await cvcField.click();
		await expect( cvcField ).toBeFocused();
		await expectAccessibleFieldError( frame, expiryField );
		await expiryField.fill( FUTURE_EXPIRY );
		await cvcField.click();
		await expectFieldRecovered( frame, expiryField, /12\s*\/\s*34/ );

		// Gesture 3: an incomplete security code.
		await expect( cvcField ).toHaveAttribute( 'aria-invalid', 'false' );
		await cvcField.fill( INCOMPLETE_CVC );
		await numberField.click();
		await expect( numberField ).toBeFocused();
		await expectAccessibleFieldError( frame, cvcField );
		await cvcField.fill( COMPLETE_CVC );
		await numberField.click();
		await expectFieldRecovered( frame, cvcField, /^123$/ );

		// Zero-dispatch proof: three rejections and three corrections never
		// dispatched a checkout submission, and no order exists for the
		// run-owned product beyond what preceded the run.
		expect( submissions() ).toBe( 0 );
		expect( await countSmokeProductOrders( adminApi, productId ) ).toBe(
			ordersBefore
		);
	}
);
