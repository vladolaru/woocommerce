import type { APIRequestContext, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { customer } from '../../../test-data/data';

// The two retained release-smoke rows from the client suite's basic project.
// Their chromium-project duplicates are already retired against these
// canonical instances, so these are the only two rows this spec may claim.
const HOME_CONTRACT_ID =
	'default::basic::tests/e2e/specs/basic.spec.ts:8::A basic set of tests to ensure WP, wp-admin and my-account load › Load the home page';
const MY_ACCOUNT_CONTRACT_ID =
	'default::basic::tests/e2e/specs/basic.spec.ts:29::A basic set of tests to ensure WP, wp-admin and my-account load › Sign in as customer › Load customer my account page';

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
// One simple, in-stock product is all the shop-content oracle needs, and
// restricting the type keeps the add-to-cart control's accessible name
// deterministic: simple products render the plain "Add to cart" button.
const STORE_PRODUCTS_API =
	'/wp-json/wc/store/v1/products?type=simple&stock_status=instock&per_page=1';

// The failure shapes a release install smoke exists to catch on the front
// end: a fatal that replaces the document, and leaked PHP notice output that
// corrupts it. The notice pattern requires the "on line N" tail so ordinary
// storefront copy containing the bare words cannot trip it.
const FATAL_TEXT = /fatal error|parse error|there has been a critical error/i;
const NOTICE_TEXT = /\b(?:Warning|Notice|Deprecated):[^\n]*\bon line \d+/;

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

async function readJson(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return await response.json();
}

/**
 * Collect failed responses for the store's own REST API, so a page that
 * renders its markup while a fetch behind it quietly errors is not mistaken
 * for a healthy load. Same shape as the payouts-disputes smoke's tracker, with
 * one honest difference: these storefront and My Account surfaces are
 * server-rendered and may legitimately issue zero REST requests, so no
 * minimum-observed guard applies — the oracle is conditional, and page
 * identity is carried by the DOM assertions instead.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): { failures: () => string[] } {
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
	// A fetch that dies at the network layer never produces a response.
	// Client-cancelled requests are ordinary navigation, not failures.
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

	return { failures: () => [ ...failures ] };
}

/**
 * Assert the current document carries no PHP fatal or leaked notice output.
 * Checked as text, because both failure shapes surface as text: a fatal
 * replaces the document, and display_errors noise is injected around it.
 */
async function expectNoPhpErrors( page: Page ): Promise< void > {
	await expect( page.getByText( FATAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( NOTICE_TEXT ) ).toHaveCount( 0 );
}

test(
	'the public storefront home page renders the store-declared site identity and a purchasable product page renders its shop content without PHP errors or failed store REST requests',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: HOME_CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Role fidelity: this row belongs to the public visitor, so the whole
		// journey runs without a session.
		await page.context().clearCookies();

		// The identity oracle is the store's own declaration, not a seeded
		// title or a theme selector: WordPress publishes the site name and
		// home URL through its REST index, and the home page must render that
		// same identity. This keeps the smoke honest across renames and theme
		// swaps, which is exactly where the old exact-title oracle broke.
		const siteIndex = ( await readJson(
			await page.request.get( '/wp-json/' ),
			'Site index read'
		) ) as Record< string, unknown >;
		const siteName =
			typeof siteIndex.name === 'string' ? siteIndex.name.trim() : '';
		if ( siteName === '' ) {
			throw new Error(
				'The site declares no name; the identity oracle has nothing to assert.'
			);
		}
		const homeUrl = (
			typeof siteIndex.home === 'string' && siteIndex.home !== ''
				? siteIndex.home
				: storeBase
		).replace( /\/+$/, '' );

		// The shop-content half is data-driven the same way: the store's own
		// public Store API names a purchasable product, and its page must
		// render. A store with no published purchasable product cannot carry
		// a release smoke and must fail here, loudly, not pass by skipping.
		const products = ( await readJson(
			await page.request.get( STORE_PRODUCTS_API ),
			'Store products read'
		) ) as Array< {
			name?: unknown;
			permalink?: unknown;
			is_purchasable?: unknown;
		} >;
		if ( ! Array.isArray( products ) || products.length === 0 ) {
			throw new Error(
				'The store publishes no simple in-stock product; the shop-content oracle has nothing to assert.'
			);
		}
		const [ product ] = products;
		if (
			typeof product.name !== 'string' ||
			typeof product.permalink !== 'string' ||
			product.is_purchasable !== true
		) {
			throw new Error(
				"The store's first simple in-stock product is not purchasable with a name and permalink."
			);
		}

		const restTracker = trackFailedRestResponses( page, storeBase );

		// Contract, first half: the home page renders under the declared
		// identity. The banner landmark is asserted strictly — one page, one
		// banner — while the identity link inside it tolerates a theme that
		// repeats the site title (for example separate mobile and desktop
		// headers), because repetition is not an identity failure.
		await page.goto( homeUrl );
		await expectNoPhpErrors( page );
		const banner = page.getByRole( 'banner' );
		await expect( banner ).toBeVisible();
		const identityLink = banner
			.getByRole( 'link', { name: siteName, exact: true } )
			.first();
		await expect( identityLink ).toBeVisible();
		await expect( identityLink ).toHaveAttribute(
			'href',
			new RegExp( `^${ escapeRegExp( homeUrl ) }/?$` )
		);
		// The document carries real content, not an empty shell around a
		// correct header.
		const main = page.getByRole( 'main' );
		await expect( main ).toBeVisible();
		await expect( main ).toContainText( /\S/ );

		// Contract, second half: shop content renders. The old title-only
		// oracle could pass while every product route was broken; visiting
		// the store-declared product and requiring its purchase control
		// closes that gap. Navigation reuses the declared home origin so a
		// permalink emitted under a different canonical host cannot bounce
		// the smoke off the store under test.
		const productUrl = new URL( product.permalink );
		await page.goto(
			`${ homeUrl }${ productUrl.pathname }${ productUrl.search }`
		);
		await expectNoPhpErrors( page );
		await expect(
			page.getByRole( 'heading', { name: product.name, exact: true } )
		).toBeVisible();
		// Exact name: related-product buttons carry the product name inside
		// their accessible names and must not satisfy this.
		await expect(
			page.getByRole( 'button', { name: 'Add to cart', exact: true } )
		).toBeVisible();

		// No store REST request behind either page failed.
		expect( restTracker.failures() ).toEqual( [] );
	}
);

test(
	'an authenticated customer reaches My Account and its account details, payment methods, and orders subpages render terminal content without PHP errors or failed store REST requests',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: MY_ACCOUNT_CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Precondition guard: WooCommerce only offers the Payment methods
		// menu item and the Add payment method control while a gateway that
		// supports saved methods is available. Without an enabled gateway the
		// payment-methods half of this contract would be unreachable, so a
		// degraded store must fail here rather than pass a thinner journey.
		const paymentsSettings = ( await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		) ) as Record< string, unknown >;
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );

		const restTracker = trackFailedRestResponses( page, storeBase );

		// The seeded shared test customer, logged in the same way the harness
		// fixtures do it: clear first, because a stale session cookie
		// redirects wp-login.php and leaves the form fill hunting a field
		// that is not there.
		await page.context().clearCookies();
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( customer.username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( customer.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		// Identity, not just a session: the account-details subpage reports
		// the seeded customer's own email, so everything below is asserted
		// about the right account. This is the same proof the harness's
		// customer login uses, and it doubles as the account-details subpage
		// rendering its form.
		await page.goto( 'my-account/edit-account/' );
		await expect(
			page.getByRole( 'textbox', { name: /Email address/i } )
		).toHaveValue( customer.email );
		await expectNoPhpErrors( page );

		// The My Account entry surface: its own heading plus the account
		// navigation landmark WooCommerce labels itself, which is the
		// theme-independent skeleton every subpage assertion below hangs off.
		await page.goto( 'my-account/' );
		await expectNoPhpErrors( page );
		await expect(
			page.getByRole( 'heading', { name: 'My account' } )
		).toBeVisible();
		const accountNav = page.getByRole( 'navigation', {
			name: 'Account pages',
		} );
		await expect( accountNav ).toBeVisible();

		// Payment methods, reached through the customer's own navigation
		// rather than a typed URL, because the link being offered is part of
		// the contract. Terminal content, not just chrome: either the saved
		// methods table or the explicit empty notice, plus the Add payment
		// method control that must accompany an available gateway. Both
		// terminal states are accepted — the contract asks for a rendering
		// subpage, not for a seeded wallet.
		await accountNav
			.getByRole( 'link', { name: 'Payment methods' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Payment methods' } )
		).toBeVisible();
		const savedMethodsTable = page.locator(
			'table.account-payment-methods-table'
		);
		const noSavedMethods = page.getByText( 'No saved methods found.' );
		await expect(
			savedMethodsTable.or( noSavedMethods ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Add payment method' } )
		).toBeVisible();
		await expectNoPhpErrors( page );

		// Orders, same shape: heading plus a terminal state — the orders
		// table or the explicit empty notice with its Browse products path.
		await accountNav.getByRole( 'link', { name: 'Orders' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Orders' } )
		).toBeVisible();
		const ordersTable = page.locator( 'table.account-orders-table' );
		const noOrders = page.getByText( 'No order has been made yet.' );
		await expect( ordersTable.or( noOrders ).first() ).toBeVisible();
		await expectNoPhpErrors( page );

		// No store REST request behind the whole journey failed.
		expect( restTracker.failures() ).toEqual( [] );
	}
);
