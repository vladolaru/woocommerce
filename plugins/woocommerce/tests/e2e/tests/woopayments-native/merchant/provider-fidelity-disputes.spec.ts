import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import {
	expectSettledCardPayment,
	fillCardDetails,
	getCharge,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `dispute-lifecycle` provider-fidelity family (T.4 Batch P2 rewrite),
 * as fixed in `FIDELITY-CLAIMS.md`.
 *
 * `DP-nav`, the family's one retained browser smoke: a real provider-raised
 * dispute keeps its exact dispute, charge and order identities from checkout
 * through the `on-hold` transition and the dispute-created order note, and
 * the note's link reaches the native dispute details surface. Voluntary
 * acceptance, winning evidence and losing evidence (`DP1`-`DP3`) moved below
 * the browser in T.1 batch 5b.
 *
 * **Nothing here creates a dispute locally.** The only lever is the
 * provider's disputed-card fixture, `TEST_CARDS.dispute`: a captured charge
 * on it is disputed by the provider, asynchronously, as `fraudulent`. Every
 * native effect - the `on-hold` transition and the created note - is written
 * only when `WooPaymentsEventIngestor` ingests the matching provider event.
 * This case never returns early on a half-observed graph: a store whose
 * event listener is not delivering `charge.dispute.*` fails at the
 * convergence budget below with an explicit message.
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:dispute-lifecycle',
];

const CONTRACT_DP_NAV =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-view-details-via-order-notice.spec.ts:40::Disputes › View dispute details via disputed order notice › should navigate to dispute details when disputed order notice button clicked';

const PAYMENTS_SETTINGS_ROUTE = 'wc/v3/payments/settings';
const STORE_CURRENCY_ROUTE = 'wc/v3/settings/general/woocommerce_currency';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

/** The claim's fixture: one USD 50.00 disputed charge. */
const PRICE = '50.00';
const AMOUNT_MINOR = 5000;
const CURRENCY = 'USD';
const DISPUTE_REASON = 'fraudulent';
const CREATED_STATUS = 'needs_response';

const CHECKOUT_BUDGET_MS = 60_000;
const CREATION_BUDGET_MS = 180_000;
const CREATION_INTERVAL_MS = 5_000;
const DETAILS_BUDGET_MS = 60_000;

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

function relatedObjectId( value: unknown ): string {
	if ( typeof value === 'object' && value !== null && 'id' in value ) {
		return String( ( value as { id?: unknown } ).id ?? '' );
	}
	return typeof value === 'string' ? value : '';
}

interface PaidOrder {
	productId: number;
	orderId: number;
	chargeId: string;
}

/**
 * The billing phone must still hold a phone number, and not a card. A
 * regression once stored `<phone><card PAN>` in `billing_phone`; this fails
 * at the point of corruption (and if the field cannot even be found) instead
 * of a receipt that never arrives.
 */
async function expectUncorruptedBillingPhone( page: Page ): Promise< void > {
	const phone = page.locator( '#billing-phone' );
	await expect(
		phone,
		'the Blocks billing phone field must be present to guard against PAN corruption'
	).toHaveCount( 1 );
	const value = ( await phone.inputValue().catch( () => '' ) ) ?? '';
	expect(
		value.includes( TEST_CARDS.dispute.number ),
		'the billing phone field must not carry the card number'
	).toBe( false );
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readNewOrders(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< Array< { id: number } > > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number } >;
	return orders.filter( ( order ) => order.id > baselineOrderId );
}

/**
 * Drive one guest Blocks checkout with the disputed-card fixture, prove it is
 * the one order this submission created, and converge on the settled
 * provider graph (order/intent/charge amount and currency, the linkage, the
 * payment method, one charge occurrence, one capture) - the fixture every
 * assertion in this case is measured against.
 */
async function createDisputableCardOrder(
	page: Page,
	restApi: ApiClient
): Promise< PaidOrder > {
	const productId = (
		(
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments dispute fidelity ${ random() }`,
				type: 'simple',
				virtual: true,
				regular_price: PRICE,
				status: 'publish',
			} )
		).data as { id: number }
	 ).id;
	const baselineOrderId = await readHighestOrderId( restApi );

	await page.goto( `?post_type=product&p=${ productId }` );
	await page
		.getByRole( 'button', { name: 'Add to cart', exact: true } )
		.click();
	await page.goto( 'checkout/' );
	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ random() }-dispute@example.com` );
	await fillBillingCheckoutBlocks( page, {
		country: 'US',
		firstName: 'E2E',
		lastName: 'WooPayments',
		address: '123 Test Street',
		city: 'San Francisco',
		state: 'CA',
		zip: '94107',
		phone: '5555550100',
	} );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.check();
	await fillCardDetails( page, TEST_CARDS.dispute, 'blocks' );
	await expectUncorruptedBillingPhone( page );
	await page.getByRole( 'button', { name: /place order/i } ).click();
	await page.waitForURL( /\/order-received\/[1-9]\d*/, {
		timeout: CHECKOUT_BUDGET_MS,
	} );
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i )
	).toBeVisible();
	const orderId = Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );

	expect(
		( await readNewOrders( restApi, baselineOrderId ) ).map(
			( order ) => order.id
		),
		'one checkout submission must create exactly one order'
	).toEqual( [ orderId ] );

	// Not asserted: the order's status the instant settlement converges.
	// Verified live (order 4988, 2026-09-26): the order really is briefly
	// `processing` right after the charge succeeds, but the disputed-card
	// fixture's dispute typically fires within single-digit seconds - often
	// before `expectSettledCardPayment`'s own two-stable-reads convergence
	// finishes - so this read can just as validly observe `on-hold` already.
	// `waitForCreatedDispute` below is what actually proves the transition.
	const settled = await expectSettledCardPayment( restApi, orderId, {
		amountMinor: AMOUNT_MINOR,
		currency: CURRENCY,
	} );

	return { productId, orderId, chargeId: settled.chargeId };
}

interface CreatedDispute {
	id: string;
	orderId: number;
}

/**
 * Whether `error` is the provider's transient "another request holds this
 * object" answer, routine while the provider is adjudicating this same
 * charge into a dispute.
 */
function isLockTimeout( error: unknown ): boolean {
	const status =
		typeof error === 'object' && error !== null && 'response' in error
			? ( error as { response?: { status?: unknown } } ).response?.status
			: undefined;
	return status === 429;
}

/**
 * Poll the charge for a provider dispute, then the order, until both the
 * provider's dispute record and native's own effects (the `on-hold`
 * transition and exactly one created note) agree. The provider record alone
 * proves the dispute exists; the order effects prove the event reached and
 * was ingested by this store. Tolerates 429 `lock_timeout` on any read by
 * continuing the poll until the deadline.
 */
async function waitForCreatedDispute(
	restApi: ApiClient,
	paid: PaidOrder
): Promise< CreatedDispute > {
	const deadline = Date.now() + CREATION_BUDGET_MS;
	const linksToCharge = new RegExp(
		`id=${ escapeRegExp( paid.chargeId ) }\\b`
	);

	for (;;) {
		try {
			const charge = await getCharge( restApi, paid.chargeId );
			const disputeId = relatedObjectId( charge.dispute );
			if ( disputeId ) {
				const dispute = (
					await restApi.get(
						`wc/v3/payments/disputes/${ encodeURIComponent(
							disputeId
						) }`
					)
				).data as Record< string, unknown >;
				const order = (
					await restApi.get( `${ ORDERS_ROUTE }/${ paid.orderId }` )
				).data as Record< string, unknown >;
				const notes = (
					await restApi.get(
						`${ ORDERS_ROUTE }/${ paid.orderId }/notes?context=edit&per_page=100`
					)
				).data as Array< { note?: unknown } >;
				const createdNotes = notes.filter(
					( entry ) =>
						typeof entry.note === 'string' &&
						entry.note.includes(
							'Payment has been disputed for'
						) &&
						linksToCharge.test( entry.note )
				);
				if ( createdNotes.length > 1 ) {
					throw new Error(
						`Order ${ paid.orderId } carries ${ createdNotes.length } dispute-created notes; one provider event must produce exactly one.`
					);
				}
				if ( order.status === 'on-hold' && createdNotes.length === 1 ) {
					const evidenceDetails =
						( dispute.evidence_details as Record<
							string,
							unknown
						> ) ?? {};
					const enrichedOrder =
						( dispute.order as Record< string, unknown > ) ?? {};
					expect(
						dispute.id,
						'the dispute must be the one just read off the charge'
					).toBe( disputeId );
					expect( relatedObjectId( dispute.charge ) ).toBe(
						paid.chargeId
					);
					expect( dispute.status ).toBe( CREATED_STATUS );
					expect( dispute.reason ).toBe( DISPUTE_REASON );
					expect( dispute.amount ).toBe( AMOUNT_MINOR );
					expect( String( dispute.currency ).toUpperCase() ).toBe(
						CURRENCY
					);
					expect(
						evidenceDetails.due_by,
						'a dispute in needs_response must carry a response deadline'
					).toBeGreaterThan( 0 );
					expect( evidenceDetails.submission_count ).toBe( 0 );
					expect( evidenceDetails.has_evidence ).toBe( false );
					expect(
						enrichedOrder.id,
						"native's own order enrichment must resolve to this exact order"
					).toBe( paid.orderId );
					return {
						id: disputeId,
						orderId: Number( enrichedOrder.id ),
					};
				}
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}

		if ( Date.now() >= deadline ) {
			throw new Error(
				`Charge ${ paid.chargeId } did not reach a created dispute with its native on-hold effect within ${ CREATION_BUDGET_MS }ms. Dispute creation is delivered to this store as a provider event; a run whose platform event listener is not forwarding charge.dispute.created will always stop here.`
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, CREATION_INTERVAL_MS )
		);
	}
}

/**
 * Wait for the surface the notice reached to declare itself. An unrouted
 * legacy deep link renders the admin app's not-allowed card after a delay,
 * so the refusal text is watched alongside the heading rather than only
 * timing out on it.
 */
async function expectDisputeDetailsSurface( details: Page ): Promise< void > {
	const heading = details.getByRole( 'heading', {
		name: /^(Payment details|Transaction details)$/,
	} );
	const deadline = Date.now() + DETAILS_BUDGET_MS;
	for (;;) {
		if ( await heading.isVisible() ) {
			return;
		}
		const refused = details.getByText(
			/Sorry, you are not allowed to access this page\.|Your current account status does not allow access to this page\./
		);
		if ( await refused.isVisible() ) {
			throw new Error(
				`A dispute-created notice landed on a surface that refuses access (${ details.url() }); the legacy deep link did not reach a native dispute details route.`
			);
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`A dispute-created notice did not reach a dispute details surface within ${ DETAILS_BUDGET_MS }ms; it stopped at ${ details.url() }.`
			);
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
}

/**
 * Follow one disputed order's dispute-created notice to the dispute details
 * surface and prove it landed on that row's exact dispute and order. The
 * notice opens in a new tab, so the popup is followed rather than the
 * current page.
 */
async function followDisputeNotice(
	page: Page,
	paid: PaidOrder,
	dispute: CreatedDispute
): Promise< void > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ paid.orderId }`
	);
	const notesPanel = page.locator( '#woocommerce-order-notes' );
	if ( ( await notesPanel.count() ) === 0 ) {
		await page.goto(
			`wp-admin/post.php?post=${ paid.orderId }&action=edit`
		);
	}
	await expect( notesPanel ).toBeVisible();

	const notice = notesPanel.getByRole( 'link', {
		name: /^Response due by /,
	} );
	await expect(
		notice,
		`order ${ paid.orderId } must carry exactly one dispute-created notice linking to its dispute`
	).toHaveCount( 1 );
	await expect( notice ).toHaveAttribute(
		'href',
		new RegExp( `id=${ escapeRegExp( paid.chargeId ) }\\b` )
	);

	const popup = page.waitForEvent( 'popup' ).catch( () => null );
	await notice.click();
	const details = ( await popup ) ?? page;

	try {
		await details.waitForLoadState( 'domcontentloaded' );
		await expectDisputeDetailsSurface( details );
		await expect(
			details.getByRole( 'heading', { name: 'Dispute details' } )
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
		await expect(
			details.getByText( dispute.id, { exact: true } ),
			`the dispute details surface must present dispute ${ dispute.id }`
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
		await expect(
			details.getByRole( 'link', {
				name: `Order #${ paid.orderId }`,
				exact: true,
			} ),
			`the dispute details surface must present order ${ paid.orderId }`
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
	} finally {
		if ( details !== page ) {
			await details.close();
		}
	}
}

/** This family pays with a card and reads a dispute; it mutates no store configuration. */
async function readStoreConfigurationSnapshot(
	restApi: ApiClient
): Promise< string > {
	const settings = ( await restApi.get( PAYMENTS_SETTINGS_ROUTE ) )
		.data as Record< string, unknown >;
	const currency = ( await restApi.get( STORE_CURRENCY_ROUTE ) ).data as {
		value?: unknown;
	};
	return JSON.stringify( {
		enabled_payment_method_ids: settings.enabled_payment_method_ids,
		is_wcpay_enabled: settings.is_wcpay_enabled,
		is_manual_capture_enabled: settings.is_manual_capture_enabled,
		is_test_mode_enabled: settings.is_test_mode_enabled,
		store_currency: currency.value,
	} );
}

test.beforeAll( async ( { restApi } ) => {
	await requireTestModeAccount( restApi );
} );

test(
	'Each disputed order carries one dispute-created notice whose link reaches the dispute details surface for that exact dispute and order',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_DP_NAV },
		],
		tag: FAMILY_TAGS,
	},
	async ( { page, restApi } ) => {
		// One checkout, one 180-second creation convergence and one
		// navigation, with headroom.
		test.setTimeout( 600_000 );

		const recordedConfiguration =
			await readStoreConfigurationSnapshot( restApi );
		const paid = await createDisputableCardOrder( page, restApi );
		try {
			const dispute = await waitForCreatedDispute( restApi, paid );

			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password );
			await followDisputeNotice( page, paid, dispute );

			// Looking at the dispute changed nothing: the order still
			// carries exactly one creation effect.
			const notes = (
				await restApi.get(
					`${ ORDERS_ROUTE }/${ paid.orderId }/notes?context=edit&per_page=100`
				)
			).data as Array< { note?: unknown } >;
			const linksToCharge = new RegExp(
				`id=${ escapeRegExp( paid.chargeId ) }\\b`
			);
			expect(
				notes.filter(
					( entry ) =>
						typeof entry.note === 'string' &&
						entry.note.includes(
							'Payment has been disputed for'
						) &&
						linksToCharge.test( entry.note )
				)
			).toHaveLength( 1 );
		} finally {
			await restApi.delete( `${ PRODUCTS_ROUTE }/${ paid.productId }`, {
				force: true,
			} );
			const observed = await readStoreConfigurationSnapshot( restApi );
			expect(
				observed,
				'the dispute-lifecycle case must leave store configuration unchanged'
			).toBe( recordedConfiguration );
		}
	}
);
