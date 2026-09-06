import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin, customer } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use, so a store
// with non-default admin credentials drives the browser half and the API half
// with one identity.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

// The suite's run-ownership stamp, the same key the pilot runtime writes on
// products it creates and orders it claims.
const RUN_META_KEY = '_e2e_woopayments_run_id';

const DECIMAL_SEPARATOR_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_decimal_sep';
const NUM_DECIMALS_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_num_decimals';

// The two run-owned lines every case is built on. The first line is the one
// each case drives an invalid value into; the second exists so a per-line
// violation can be driven while the aggregate refund amount stays inside the
// order's remaining refundable amount. Without it, every per-line case would
// be intercepted by the aggregate guard, which is what the client suite's
// version of these rows actually exercised.
const DRIVEN_LINE_PRICE = 10;
const DRIVEN_LINE_QUANTITY = 2;
const SPARE_LINE_PRICE = 50;

// The smallest amount the store can express, so every out-of-bounds value is
// derived from the fixture's own boundary rather than from a hard-coded
// excess. The ledger names the hard-coded `100` on the client rows as a
// recorded weakness: it only exceeded that suite's sample total by accident.
const SMALLEST_INCREMENT = 0.01;

// The exact server-side rejections, from `WC_AJAX::refund_line_items()`. Every
// one of them is raised before `wc_create_refund()` runs, so no refund object
// and no gateway dispatch can exist on any of these paths.
const AGGREGATE_REJECTION = 'Invalid refund amount';
const LINE_QUANTITY_ABOVE_REMAINING =
	'Line item quantity cannot be greater than the remaining refundable quantity.';
const LINE_QUANTITY_NEGATIVE = 'Line item quantity must be non-negative.';
const LINE_TOTAL_ABOVE_REMAINING =
	'Refund total cannot be greater than the remaining refundable amount for this line item.';
const LINE_TOTAL_WRONG_SIGN =
	'Refund total has the wrong sign for this line item.';

// The failure shapes an admin screen must not carry. A fatal replaces the
// document; leaked notice output corrupts it. The notice pattern requires the
// "on line N" tail so ordinary admin copy cannot trip it.
const FATAL_TEXT = /fatal error|parse error|there has been a critical error/i;
const NOTICE_TEXT = /\b(?:Warning|Notice|Deprecated):[^\n]*\bon line \d+/;

// Hosts that carry payment exchanges. The provider's script host is
// deliberately excluded elsewhere in this suite because loading a script
// creates nothing; on the order screen not even that is expected.
const PROVIDER_TRANSACTING_HOSTS = [ 'api.stripe.com', 'm.stripe.com' ];

interface FixtureLine {
	itemId: number;
	productId: number;
	name: string;
	quantity: number;
	total: number;
}

interface RefundFixture {
	orderId: number;
	orderTotal: number;
	orderStatus: string;
	drivenLine: FixtureLine;
	spareLine: FixtureLine;
}

interface RefundInput {
	/** Value typed into the driven line's refund quantity box, if any. */
	drivenQuantity?: string;
	/** Value typed into the driven line's refund total box, if any. */
	drivenTotal?: ( fixture: RefundFixture ) => string;
	/** Value typed into the spare line's refund total box, if any. */
	spareTotal?: ( fixture: RefundFixture ) => string;
}

interface RefundScenario {
	expectedError: string;
	input: RefundInput;
	/** The aggregate the editor must derive before the attempt is submitted. */
	expectedAggregate: ( fixture: RefundFixture ) => string;
	/**
	 * Which guard this case is asserting fires. An `aggregate-*` case must
	 * submit an out-of-range total; a `line` case must submit an in-range
	 * total, so the per-line rule under test is demonstrably the one that
	 * rejected rather than the aggregate guard standing in front of it.
	 */
	guard: 'aggregate-above' | 'aggregate-negative' | 'line';
}

function money( value: number ): string {
	return value.toFixed( 2 );
}

async function readJson< Result = Record< string, unknown > >(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

/**
 * Assert the store formats money the way this spec's boundary arithmetic
 * assumes. Every out-of-bounds value below is a two-decimal string typed into
 * a `wc_input_price` field, which the admin script parses with the store's own
 * monetary decimal separator. A store configured differently must fail here,
 * loudly, rather than submit a value that means something else.
 */
async function assertMoneyFormatAssumptions(
	adminApi: APIRequestContext
): Promise< void > {
	const decimalSeparator = await readJson(
		await adminApi.get( DECIMAL_SEPARATOR_API ),
		'Price decimal separator read'
	);
	expect(
		decimalSeparator.value,
		'this spec types two-decimal amounts with a dot separator'
	).toBe( '.' );
	const numDecimals = await readJson(
		await adminApi.get( NUM_DECIMALS_API ),
		'Price decimals read'
	);
	expect(
		String( numDecimals.value ),
		'this spec derives its boundaries at two decimal places'
	).toBe( '2' );
}

/**
 * Collect failed responses for the store's own REST API, so an admin screen
 * that renders its markup while a fetch behind it quietly errors is not
 * mistaken for a healthy load. The order screen is server-rendered and may
 * legitimately issue no REST requests at all, so no minimum-observed guard
 * applies; screen identity is carried by the DOM assertions instead.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): () => string[] {
	const failures: string[] = [];
	const restPrefix = `${ new URL( baseUrl ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	const storeRestPath = ( url: string ): string | null => {
		if ( ! url.startsWith( baseUrl ) ) {
			return null;
		}
		const { pathname, searchParams } = new URL( url );
		if (
			! pathname.startsWith( restPrefix ) &&
			! searchParams.has( 'rest_route' )
		) {
			return null;
		}
		return pathname;
	};

	page.on( 'response', ( response ) => {
		const path = storeRestPath( response.url() );
		if ( path && response.status() >= 400 ) {
			failures.push( `${ response.status() } ${ path }` );
		}
	} );
	page.on( 'requestfailed', ( request ) => {
		const errorText = request.failure()?.errorText ?? 'unknown';
		if ( errorText === 'net::ERR_ABORTED' ) {
			return;
		}
		const path = storeRestPath( request.url() );
		if ( path ) {
			failures.push( `failed ${ path } (${ errorText })` );
		}
	} );

	return () => [ ...failures ];
}

/**
 * Record browser-side requests to the provider's transacting hosts. Nothing on
 * this journey may reach them: every rejection below happens in
 * `WC_AJAX::refund_line_items()` before a refund object exists, and the order
 * is paid by a non-provider method so no gateway refund control is even
 * rendered.
 */
function trackProviderClientRequests( page: Page ): () => string[] {
	const providerRequests: string[] = [];
	page.on( 'request', ( request ) => {
		try {
			const { hostname } = new URL( request.url() );
			if ( PROVIDER_TRANSACTING_HOSTS.includes( hostname ) ) {
				providerRequests.push( `${ request.method() } ${ hostname }` );
			}
		} catch {
			// Unparsable URLs cannot be provider requests.
		}
	} );
	return () => [ ...providerRequests ];
}

interface CapturedDialog {
	type: string;
	message: string;
}

/**
 * Capture and accept the two native dialogs the classic refund flow uses: the
 * confirmation the merchant must accept, and the alert that reports a server
 * rejection. Playwright dismisses unhandled dialogs, which would silently
 * cancel the confirmation and make the whole attempt a no-op — the exact shape
 * of vacuous pass these rows are being rewritten to eliminate.
 */
function captureDialogs( page: Page ): () => CapturedDialog[] {
	const captured: CapturedDialog[] = [];
	page.on( 'dialog', async ( dialog ) => {
		captured.push( { type: dialog.type(), message: dialog.message() } );
		await dialog.accept();
	} );
	return () => [ ...captured ];
}

async function expectNoPhpErrors( page: Page ): Promise< void > {
	await expect( page.getByText( FATAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( NOTICE_TEXT ) ).toHaveCount( 0 );
}

async function createRunOwnedProduct(
	adminApi: APIRequestContext,
	runId: string,
	label: string,
	price: number
): Promise< { id: number; name: string } > {
	const name = `WooPayments refund validation ${ label } ${ runId }`;
	const product = await readJson< { id?: unknown } >(
		await adminApi.post( '/wp-json/wc/v3/products', {
			data: {
				name,
				type: 'simple',
				virtual: true,
				regular_price: money( price ),
				// No tax on the fixture, so the order total is exactly the sum
				// of its line totals and every boundary below is exact.
				tax_status: 'none',
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			},
		} ),
		`Run-owned ${ label } product creation`
	);
	if ( typeof product.id !== 'number' ) {
		throw new Error(
			`Run-owned ${ label } product response did not contain a numeric ID.`
		);
	}
	return { id: product.id, name };
}

/**
 * Build this case's own refundable order. Provider-free by construction: the
 * order is paid by Cash on delivery, so `wc_get_payment_gateway_by_order()`
 * resolves to a gateway that does not support refunds and the screen renders
 * no gateway refund control at all. Nothing here touches the WooPayments
 * account, and nothing is shared with another case — the client suite's
 * version of these rows reused one mutable order across all six, which the
 * ledger records as amplifying any accidental leakage.
 */
async function createRefundFixture(
	adminApi: APIRequestContext,
	runId: string
): Promise< RefundFixture & { productIds: number[] } > {
	const drivenProduct = await createRunOwnedProduct(
		adminApi,
		runId,
		'driven',
		DRIVEN_LINE_PRICE
	);
	const spareProduct = await createRunOwnedProduct(
		adminApi,
		runId,
		'spare',
		SPARE_LINE_PRICE
	);

	const order = await readJson< {
		id?: unknown;
		total?: unknown;
		status?: unknown;
		line_items?: Array< {
			id?: unknown;
			product_id?: unknown;
			name?: unknown;
			quantity?: unknown;
			total?: unknown;
		} >;
	} >(
		await adminApi.post( '/wp-json/wc/v3/orders', {
			data: {
				status: 'processing',
				payment_method: 'cod',
				payment_method_title: 'Cash on delivery',
				// Mapped field by field rather than spread. The shared
				// fixture is shaped for checkout form fills, so it carries
				// `address` and `zip`, which the orders REST schema does not
				// define. A stray `address` key is worse than ignored: the
				// controller calls `set_billing_<key>` for whatever it is
				// posted, so it reaches the bulk `set_billing_address()`
				// setter with a string and fatals the request.
				billing: {
					first_name: customer.billing.us.first_name,
					last_name: customer.billing.us.last_name,
					address_1: customer.billing.us.address,
					city: customer.billing.us.city,
					state: customer.billing.us.state,
					postcode: customer.billing.us.zip,
					country: customer.billing.us.country,
					phone: customer.billing.us.phone,
					email: customer.email,
				},
				line_items: [
					{
						product_id: drivenProduct.id,
						quantity: DRIVEN_LINE_QUANTITY,
					},
					{ product_id: spareProduct.id, quantity: 1 },
				],
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			},
		} ),
		'Run-owned refundable order creation'
	);

	if (
		typeof order.id !== 'number' ||
		typeof order.total !== 'string' ||
		typeof order.status !== 'string' ||
		! Array.isArray( order.line_items )
	) {
		throw new Error(
			'Run-owned order response did not contain an ID, a total, a status and line items.'
		);
	}

	const toLine = ( productId: number, name: string ): FixtureLine => {
		const match = order.line_items?.find(
			( line ) => line.product_id === productId
		);
		if (
			! match ||
			typeof match.id !== 'number' ||
			typeof match.quantity !== 'number' ||
			typeof match.total !== 'string'
		) {
			throw new Error(
				`Run-owned order is missing a usable line for product ${ productId }.`
			);
		}
		return {
			itemId: match.id,
			productId,
			name,
			quantity: match.quantity,
			total: Number( match.total ),
		};
	};

	const drivenLine = toLine( drivenProduct.id, drivenProduct.name );
	const spareLine = toLine( spareProduct.id, spareProduct.name );
	const orderTotal = Number( order.total );

	// The fixture is what this spec's boundaries are derived from, so a store
	// that priced it differently must fail here rather than silently move
	// every boundary. Taxes are off on both products, so the order total is
	// exactly the two line totals.
	expect( drivenLine.quantity ).toBe( DRIVEN_LINE_QUANTITY );
	expect( drivenLine.total ).toBeCloseTo(
		DRIVEN_LINE_PRICE * DRIVEN_LINE_QUANTITY,
		2
	);
	expect( spareLine.total ).toBeCloseTo( SPARE_LINE_PRICE, 2 );
	expect( orderTotal ).toBeCloseTo( drivenLine.total + spareLine.total, 2 );

	return {
		orderId: order.id,
		orderTotal,
		orderStatus: order.status,
		drivenLine,
		spareLine,
		productIds: [ drivenProduct.id, spareProduct.id ],
	};
}

async function deleteRunOwnedResource(
	adminApi: APIRequestContext,
	resourcePath: string,
	resourceId: number,
	description: string
): Promise< void > {
	const response = await adminApi.delete(
		`/wp-json/wc/v3/${ resourcePath }/${ resourceId }`,
		{ data: { force: true }, failOnStatusCode: false }
	);
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } cleanup failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
}

async function logInAsAdmin( page: Page ): Promise< void > {
	// Clear first, matching the harness's own admin login: a stale session
	// cookie redirects wp-login.php and leaves the form fill hunting a field
	// that is not there.
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USERNAME );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( ADMIN_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( '**/wp-admin/**' );
}

/**
 * Open the classic order edit screen. Stores keeping orders in the dedicated
 * tables edit them under `wc-orders`; stores still on the posts table edit
 * them through `post.php`. The order-items meta box is the screen's identity
 * either way, and it carries no landmark role of its own.
 */
async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< void > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	const itemsBox = page.locator( '#woocommerce-order-items' );
	if ( ( await itemsBox.count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( itemsBox ).toBeVisible();
	await expectNoPhpErrors( page );
}

function refundPanel( page: Page ): Locator {
	// The refund editor is a toggled data row inside the items meta box; it
	// exposes no landmark or accessible name, so it is addressed structurally
	// and everything read out of it below is addressed semantically.
	return page.locator( '.wc-order-refund-items' );
}

function refundSummaryValue( page: Page, label: string ): Locator {
	return refundPanel( page )
		.getByRole( 'row' )
		.filter( { hasText: label } )
		.getByRole( 'cell' )
		.last();
}

async function openRefundEditor( page: Page ): Promise< void > {
	// Exact, because "Refund %s manually" also starts with the same word.
	await page.getByRole( 'button', { name: 'Refund', exact: true } ).click();
	await expect( refundPanel( page ) ).toBeVisible();
}

/**
 * Fill one of the refund editor's per-line inputs. They carry no label and no
 * accessible name — a real gap in the classic screen's markup — but they do
 * carry the `name` attribute the request is built from, which addresses the
 * exact line under test without depending on row order.
 *
 * `change` is dispatched explicitly because the admin script recomputes the
 * aggregate refund amount on that event and Playwright's fill does not
 * guarantee one.
 */
async function fillRefundInput(
	page: Page,
	inputName: string,
	value: string
): Promise< void > {
	const input = page.locator( `input[name="${ inputName }"]` );
	await expect( input ).toBeVisible();
	await input.fill( value );
	await input.dispatchEvent( 'change' );
}

interface RejectedAttempt {
	submitted: URLSearchParams;
	serverError: string;
	alertMessage: string;
	confirmations: number;
}

/**
 * Submit the refund and prove the attempt completed and was rejected, without
 * reading anything out of the still-settling document.
 *
 * This is the fix for the finding the deferral packet recorded against the
 * previous adaptation of these rows: it synchronized on response headers and
 * then asserted a jQuery-rendered DOM that had not necessarily updated, so
 * "no refund happened" could pass on a page that had simply not changed yet.
 * Three independent completions are awaited here instead:
 *
 * 1. The refund request's response **body** is parsed, so the server's own
 *    verdict is read rather than inferred.
 * 2. The `window.alert` the error branch raises is observed. It is only
 *    reachable from inside the success callback, after the body has been
 *    parsed, so it proves the browser processed the rejection.
 * 3. The follow-up `woocommerce_load_order_items` request the error branch
 *    fires — the one that re-renders the meta box — is awaited to completion,
 *    so no in-flight re-render is still racing the assertions that follow.
 *
 * The caller then reloads the page and asserts a discriminating server
 * rendered state, so nothing depends on the in-page re-render at all.
 */
async function submitRejectedRefund(
	page: Page,
	dialogs: () => CapturedDialog[]
): Promise< RejectedAttempt > {
	const isRefundRequest = ( postData: string ): boolean =>
		postData.includes( 'action=woocommerce_refund_line_items' );
	const isReloadRequest = ( postData: string ): boolean =>
		postData.includes( 'action=woocommerce_load_order_items' );

	const refundResponse = page.waitForResponse(
		( response ) =>
			response.url().includes( 'admin-ajax.php' ) &&
			response.request().method() === 'POST' &&
			isRefundRequest( response.request().postData() ?? '' )
	);
	const itemsReRendered = page.waitForResponse(
		( response ) =>
			response.url().includes( 'admin-ajax.php' ) &&
			response.request().method() === 'POST' &&
			isReloadRequest( response.request().postData() ?? '' )
	);
	// An assertion below can abandon this wait — a refund that unexpectedly
	// succeeded never triggers the re-render. Marking it handled keeps that
	// real failure legible instead of burying it under an unhandled rejection
	// when the page closes; the await further down still surfaces a genuine
	// timeout.
	void itemsReRendered.catch( () => {} );

	await page.getByRole( 'button', { name: /^Refund .* manually$/ } ).click();

	const response = await refundResponse;
	const submitted = new URLSearchParams(
		response.request().postData() ?? ''
	);
	const body = ( await response.json() ) as {
		success?: unknown;
		data?: { error?: unknown };
	};
	expect(
		body.success,
		'the refund request must have been rejected by the server'
	).toBe( false );
	const serverError = body.data?.error;
	if ( typeof serverError !== 'string' ) {
		throw new Error(
			'The rejected refund response carried no error message.'
		);
	}

	// The merchant-visible half of the rejection. Polled because dialog events
	// are delivered asynchronously; the alert itself is raised synchronously
	// inside the response callback.
	await expect
		.poll(
			() =>
				dialogs().filter( ( dialog ) => dialog.type === 'alert' )
					.length,
			{
				message:
					'the rejected refund must be reported to the merchant in an alert',
			}
		)
		.toBe( 1 );
	const alertMessage =
		dialogs().find( ( dialog ) => dialog.type === 'alert' )?.message ?? '';
	const confirmations = dialogs().filter(
		( dialog ) => dialog.type === 'confirm'
	).length;

	// The re-render the error branch triggers has finished, so nothing is
	// still in flight when the page is reloaded below.
	await itemsReRendered;

	return { submitted, serverError, alertMessage, confirmations };
}

interface RefundContractFixtures {
	adminApi: APIRequestContext;
	page: Page;
	baseURL: string | undefined;
	runId: string;
}

interface RejectedRefundOutcome {
	serverError: string;
	alertMessage: string;
}

/**
 * Drive one invalid refund through the merchant's own order screen and prove
 * the three things every one of these contracts asks for: a comprehensible
 * error, an unchanged refundable amount, and no provider refund. The
 * rejection the merchant and the server saw is returned so each row can
 * restate the exact message it claims, beside the contract it closes.
 */
async function runRejectedRefundContract(
	{ adminApi, page, baseURL, runId }: RefundContractFixtures,
	scenario: RefundScenario
): Promise< RejectedRefundOutcome > {
	const storeBase = requireBaseUrl( baseURL );
	await assertMoneyFormatAssumptions( adminApi );

	const fixture = await createRefundFixture( adminApi, runId );
	const { orderId, drivenLine, spareLine } = fixture;
	const freshnessMarker = `E2E order screen marker ${ runId }`;
	let primaryError: unknown;
	let outcome: RejectedRefundOutcome | undefined;

	try {
		const restFailures = trackFailedRestResponses( page, storeBase );
		const providerRequests = trackProviderClientRequests( page );
		const dialogs = captureDialogs( page );

		await logInAsAdmin( page );
		await openOrderEditScreen( page, orderId );

		// Nothing on this screen can reach the provider: the order is paid by
		// a method whose gateway does not support refunds, so the only refund
		// control offered is the manual one. The absent gateway control is
		// asserted on the class the template gives it, because a control that
		// is not rendered has no accessible name to query.
		await openRefundEditor( page );
		await expect(
			page.locator( '.refund-actions button.do-api-refund' )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: /^Refund .* manually$/ } )
		).toBeVisible();

		// The rendered financial state before the attempt. These are read back
		// verbatim after the reload below, so the no-mutation oracle compares
		// real values rather than only asserting the absence of things.
		const availableCell = refundSummaryValue(
			page,
			'Total available to refund'
		);
		const alreadyRefundedCell = refundSummaryValue(
			page,
			'Amount already refunded'
		);
		const availableBefore = ( await availableCell.innerText() ).trim();
		const alreadyRefundedBefore = (
			await alreadyRefundedCell.innerText()
		).trim();
		// Guard the oracle itself: comparing two empty strings after the
		// reload would prove nothing.
		expect(
			availableBefore,
			'the refund editor must render a refundable amount to compare against'
		).toMatch( /\d/ );
		expect( alreadyRefundedBefore ).toMatch( /\d/ );

		// The merchant's invalid input, typed into the editor. Order matters:
		// entering a refund quantity makes the admin script rewrite that
		// line's refund total from it, so the driven total is always typed
		// last and the independent spare line first.
		if ( scenario.input.spareTotal ) {
			await fillRefundInput(
				page,
				`refund_line_total[${ spareLine.itemId }]`,
				scenario.input.spareTotal( fixture )
			);
		}
		if ( scenario.input.drivenQuantity !== undefined ) {
			await fillRefundInput(
				page,
				`refund_order_item_qty[${ drivenLine.itemId }]`,
				scenario.input.drivenQuantity
			);
		}
		if ( scenario.input.drivenTotal ) {
			await fillRefundInput(
				page,
				`refund_line_total[${ drivenLine.itemId }]`,
				scenario.input.drivenTotal( fixture )
			);
		}

		// The aggregate the editor derived from those inputs. This is what
		// makes each case provably about the rule it names: a `line` case must
		// carry an in-range aggregate, so the aggregate guard demonstrably did
		// not fire in front of the per-line rule under test, and an
		// `aggregate-*` case must carry an out-of-range one.
		const expectedAggregate = scenario.expectedAggregate( fixture );
		await expect( page.locator( '#refund_amount' ) ).toHaveValue(
			expectedAggregate
		);
		const aggregate = Number( expectedAggregate );
		if ( scenario.guard === 'line' ) {
			expect( aggregate ).toBeGreaterThanOrEqual( 0 );
			expect( aggregate ).toBeLessThanOrEqual( fixture.orderTotal );
		} else if ( scenario.guard === 'aggregate-negative' ) {
			expect( aggregate ).toBeLessThan( 0 );
		} else {
			expect( aggregate ).toBeGreaterThan( fixture.orderTotal );
		}

		const attempt = await submitRejectedRefund( page, dialogs );

		// The merchant confirmed exactly one refund attempt, and it carried
		// the inputs this case is about — including `api_refund=false`, the
		// manual path that never asks a gateway for anything.
		expect( attempt.confirmations ).toBe( 1 );
		expect( attempt.submitted.get( 'refund_amount' ) ).toBe(
			expectedAggregate
		);
		expect( attempt.submitted.get( 'api_refund' ) ).toBe( 'false' );
		if ( scenario.input.drivenQuantity !== undefined ) {
			expect(
				JSON.parse( attempt.submitted.get( 'line_item_qtys' ) ?? '{}' )
			).toEqual( {
				[ drivenLine.itemId ]: scenario.input.drivenQuantity,
			} );
		}

		// Contract, first half: a comprehensible error, both in the server's
		// verdict and in what the merchant actually reads. Asserted here so a
		// wrong rejection is reported at the point it happened, and returned
		// so the owning row restates it too.
		expect( attempt.serverError ).toBe( scenario.expectedError );
		expect( attempt.alertMessage ).toBe( scenario.expectedError );
		outcome = {
			serverError: attempt.serverError,
			alertMessage: attempt.alertMessage,
		};

		// A marker written after the rejection and before the reload.
		// Everything asserted below is asserted against a document that
		// contains it, which is what makes the no-refund assertions
		// non-vacuous: a page that had silently not re-rendered could not show
		// this note, and this assertion would fail rather than let the
		// absence assertions pass against a stale DOM.
		await readJson(
			await adminApi.post( `/wp-json/wc/v3/orders/${ orderId }/notes`, {
				data: { note: freshnessMarker, customer_note: false },
			} ),
			'Freshness marker note creation'
		);

		await page.reload();
		await expectNoPhpErrors( page );
		const orderNotes = page.locator( 'ul.order_notes' );
		await expect(
			orderNotes.getByText( freshnessMarker ),
			'the reloaded screen must show the marker written after the rejection'
		).toBeVisible();

		// Contract, second half: a discriminating post-reload state. Every one
		// of these would look different had the refund succeeded.
		await expect( page.locator( '#order_refunds tr.refund' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( '.wc-order-totals .refunded-total' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'table.woocommerce_order_items small.refunded' )
		).toHaveCount( 0 );
		await expect( orderNotes.getByText( /refund/i ) ).toHaveCount( 0 );

		// The refundable amounts render exactly as they did before the
		// attempt — the positive half of the no-mutation oracle.
		await openRefundEditor( page );
		expect(
			(
				await refundSummaryValue(
					page,
					'Total available to refund'
				).innerText()
			).trim()
		).toBe( availableBefore );
		expect(
			(
				await refundSummaryValue(
					page,
					'Amount already refunded'
				).innerText()
			).trim()
		).toBe( alreadyRefundedBefore );

		// The authoritative record, independent of anything rendered.
		const refunds = await readJson< unknown[] >(
			await adminApi.get( `/wp-json/wc/v3/orders/${ orderId }/refunds` ),
			'Order refunds read'
		);
		expect( refunds ).toEqual( [] );
		const orderAfter = await readJson< {
			total?: unknown;
			status?: unknown;
			refunds?: unknown;
			line_items?: Array< { id?: unknown; total?: unknown } >;
		} >(
			await adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			'Order read after the rejected refund'
		);
		expect( Number( orderAfter.total ) ).toBeCloseTo(
			fixture.orderTotal,
			2
		);
		expect( orderAfter.status ).toBe( fixture.orderStatus );
		expect( orderAfter.refunds ).toEqual( [] );
		expect(
			Object.fromEntries(
				( orderAfter.line_items ?? [] ).map( ( line ) => [
					String( line.id ),
					line.total,
				] )
			)
		).toEqual( {
			[ drivenLine.itemId ]: money( drivenLine.total ),
			[ spareLine.itemId ]: money( spareLine.total ),
		} );

		// No provider exchange was opened from the browser, and no store REST
		// request behind the screen failed.
		expect( providerRequests() ).toEqual( [] );
		expect( restFailures() ).toEqual( [] );
	} catch ( error ) {
		primaryError = error;
		throw error;
	} finally {
		const cleanupErrors: Error[] = [];
		const remove = async (
			resourcePath: string,
			resourceId: number,
			description: string
		): Promise< void > => {
			try {
				await deleteRunOwnedResource(
					adminApi,
					resourcePath,
					resourceId,
					description
				);
			} catch ( cleanupError ) {
				cleanupErrors.push(
					cleanupError instanceof Error
						? cleanupError
						: new Error( String( cleanupError ) )
				);
			}
		};

		await remove( 'orders', orderId, 'Run-owned order' );
		for ( const productId of fixture.productIds ) {
			await remove( 'products', productId, 'Run-owned product' );
		}

		if ( cleanupErrors.length > 0 ) {
			if ( primaryError !== undefined ) {
				for ( const cleanupError of cleanupErrors ) {
					console.error(
						'Run-owned fixture cleanup failed after the primary test failure:',
						cleanupError
					);
				}
			} else if ( cleanupErrors.length === 1 ) {
				throw cleanupErrors[ 0 ];
			} else {
				throw new AggregateError(
					cleanupErrors,
					'Run-owned fixture cleanup failed.'
				);
			}
		}
	}

	if ( ! outcome ) {
		throw new Error(
			'The rejected refund journey completed without recording an outcome.'
		);
	}
	return outcome;
}

// One more of the driven product than the merchant bought. The aggregate stays
// inside the order because the spare line is not being refunded.
const QUANTITY_ABOVE_REMAINING_SCENARIO: RefundScenario = {
	expectedError: LINE_QUANTITY_ABOVE_REMAINING,
	guard: 'line',
	input: { drivenQuantity: String( DRIVEN_LINE_QUANTITY + 1 ) },
	expectedAggregate: ( fixture ) =>
		money(
			( fixture.drivenLine.total / fixture.drivenLine.quantity ) *
				( DRIVEN_LINE_QUANTITY + 1 )
		),
};

// A merchant refunding the spare line in full who mistypes a negative quantity
// on the other. The valid line keeps the aggregate non-negative, so the
// negative quantity is demonstrably what the server rejected.
const QUANTITY_NEGATIVE_SCENARIO: RefundScenario = {
	expectedError: LINE_QUANTITY_NEGATIVE,
	guard: 'line',
	input: {
		drivenQuantity: '-1',
		spareTotal: ( fixture ) => money( fixture.spareLine.total ),
	},
	expectedAggregate: ( fixture ) =>
		money(
			fixture.spareLine.total -
				fixture.drivenLine.total / fixture.drivenLine.quantity
		),
};

// One increment past the driven line's own refundable total, while the
// aggregate stays well inside the order's.
const LINE_TOTAL_ABOVE_REMAINING_SCENARIO: RefundScenario = {
	expectedError: LINE_TOTAL_ABOVE_REMAINING,
	guard: 'line',
	input: {
		drivenTotal: ( fixture ) =>
			money( fixture.drivenLine.total + SMALLEST_INCREMENT ),
	},
	expectedAggregate: ( fixture ) =>
		money( fixture.drivenLine.total + SMALLEST_INCREMENT ),
};

// A negative amount against a positive line item, with the spare line keeping
// the aggregate non-negative so the per-line sign rule is what rejects.
const LINE_TOTAL_NEGATIVE_SCENARIO: RefundScenario = {
	expectedError: LINE_TOTAL_WRONG_SIGN,
	guard: 'line',
	input: {
		drivenTotal: () => money( -SMALLEST_INCREMENT ),
		spareTotal: ( fixture ) => money( fixture.spareLine.total ),
	},
	expectedAggregate: ( fixture ) =>
		money( fixture.spareLine.total - SMALLEST_INCREMENT ),
};

// One increment past the whole order, derived from the fixture's own total
// rather than from a hard-coded excess.
const AGGREGATE_ABOVE_REMAINING_SCENARIO: RefundScenario = {
	expectedError: AGGREGATE_REJECTION,
	guard: 'aggregate-above',
	input: {
		drivenTotal: ( fixture ) =>
			money( fixture.orderTotal + SMALLEST_INCREMENT ),
	},
	expectedAggregate: ( fixture ) =>
		money( fixture.orderTotal + SMALLEST_INCREMENT ),
};

// The smallest negative amount the store can express, with nothing offsetting
// it, so the aggregate the merchant submits is itself negative.
const AGGREGATE_NEGATIVE_SCENARIO: RefundScenario = {
	expectedError: AGGREGATE_REJECTION,
	guard: 'aggregate-negative',
	input: { drivenTotal: () => money( -SMALLEST_INCREMENT ) },
	expectedAggregate: () => money( -SMALLEST_INCREMENT ),
};

test(
	'a refund line quantity above the remaining refundable quantity is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid quantity › should fail refund attempt when quantity is greater than maximum',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			QUANTITY_ABOVE_REMAINING_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( LINE_QUANTITY_ABOVE_REMAINING );
		expect( outcome.alertMessage ).toBe( LINE_QUANTITY_ABOVE_REMAINING );
	}
);

test(
	'a negative refund line quantity is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid quantity › should fail refund attempt when quantity is negative',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			QUANTITY_NEGATIVE_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( LINE_QUANTITY_NEGATIVE );
		expect( outcome.alertMessage ).toBe( LINE_QUANTITY_NEGATIVE );
	}
);

test(
	'a refund line total above that line remaining refundable amount is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid refund amount in line item › should fail refund attempt when refund amount in line item is greater than maximum',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			LINE_TOTAL_ABOVE_REMAINING_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( LINE_TOTAL_ABOVE_REMAINING );
		expect( outcome.alertMessage ).toBe( LINE_TOTAL_ABOVE_REMAINING );
	}
);

test(
	'a negative refund line total against a positive line item is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid refund amount in line item › should fail refund attempt when refund amount in line item is negative',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			LINE_TOTAL_NEGATIVE_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( LINE_TOTAL_WRONG_SIGN );
		expect( outcome.alertMessage ).toBe( LINE_TOTAL_WRONG_SIGN );
	}
);

test(
	'an aggregate refund amount above the order remaining refundable amount is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid total refund amount › should fail refund attempt when total refund amount is greater than maximum',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			AGGREGATE_ABOVE_REMAINING_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( AGGREGATE_REJECTION );
		expect( outcome.alertMessage ).toBe( AGGREGATE_REJECTION );
	}
);

test(
	'a negative aggregate refund amount is rejected with a comprehensible error, leaves the order refundable amount unchanged, and dispatches no provider refund',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-refund-failures.spec.ts:100::Order › Refund Failure › Invalid total refund amount › should fail refund attempt when total refund amount is negative',
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL, runId } ) => {
		const outcome = await runRejectedRefundContract(
			{ adminApi, page, baseURL, runId },
			AGGREGATE_NEGATIVE_SCENARIO
		);
		// The exact rejection this row claims, restated beside its
		// contract id: the server's verdict and the merchant's alert.
		expect( outcome.serverError ).toBe( AGGREGATE_REJECTION );
		expect( outcome.alertMessage ).toBe( AGGREGATE_REJECTION );
	}
);
