import type { Browser, BrowserContext } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin, customer } from '../../../test-data/data';

// The two harness authentication rows from the client suite's setup project.
// They are about the authentication contract itself — that a session can be
// created from seeded credentials and then consumed — and not about what the
// authenticated user can subsequently do with payments. The merchant's payments
// surfaces are claimed by `merchant/payouts-disputes-smoke.spec.ts`, and the
// customer's My Account subpages by `shopper/release-basic-load.spec.ts`;
// neither is re-asserted here.
const ADMIN_CONTRACT_ID =
	'default::setup::tests/e2e/specs/auth.setup.ts:41::authenticate as admin';
const CUSTOMER_CONTRACT_ID =
	'default::setup::tests/e2e/specs/auth.setup.ts:99::authenticate as customer';

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const WP_ADMIN_PATH = 'wp-admin/';
const WC_SETTINGS_PATH = 'wp-admin/admin.php?page=wc-settings';
const MY_ACCOUNT_PATH = 'my-account/';
const CURRENT_USER_API = '/wp-json/wp/v2/users/me?context=edit';
const DENIAL_TEXT = 'Sorry, you are not allowed to access this page.';

// Each of these rows asks for one clean pass per runtime. A retry would let an
// unstable login or a slow provisioning path be papered over by a second
// attempt, which the customer row's residual risk names explicitly; the
// project config already sets zero retries, and this pins it at the spec.
test.describe.configure( { retries: 0 } );

type StorageState = Awaited< ReturnType< BrowserContext[ 'storageState' ] > >;

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for these contracts.' );
	}
	return baseURL;
}

/**
 * The WordPress authenticated-session cookies present in a storage state.
 * Their absence is what makes the "fresh" in fresh-state literal, and their
 * appearance is the state the second context is given.
 */
function loggedInCookieNames( state: StorageState ): string[] {
	return state.cookies
		.filter( ( cookie ) =>
			cookie.name.startsWith( 'wordpress_logged_in_' )
		)
		.map( ( cookie ) => cookie.name );
}

/**
 * A fresh REST nonce for the context's logged-in user, through the core
 * admin-ajax rest-nonce action, so the identity read below runs as that user.
 */
async function restNonce( context: BrowserContext ): Promise< string > {
	const response = await context.request.get(
		'/wp-admin/admin-ajax.php?action=rest-nonce'
	);
	if ( ! response.ok() ) {
		throw new Error(
			`REST nonce request failed: HTTP ${ response.status() }`
		);
	}
	const nonce = ( await response.text() ).trim();
	if ( ! nonce || nonce.length > 32 || /[<>\s]/.test( nonce ) ) {
		throw new Error(
			`REST nonce request did not return a plain nonce: ${ nonce.slice(
				0,
				120
			) }`
		);
	}
	return nonce;
}

/**
 * Assert the context's session belongs to the expected seeded account, read
 * from the store rather than inferred from a rendered label. This is the half
 * a markup oracle cannot carry: a page can render an account chrome for the
 * wrong user, or survive as chrome while the session behind it changed.
 */
async function expectSessionIdentity(
	context: BrowserContext,
	expected: { username: string; email?: string }
): Promise< void > {
	const nonce = await restNonce( context );
	const response = await context.request.get( CURRENT_USER_API, {
		headers: { 'X-WP-Nonce': nonce },
	} );
	expect(
		response.status(),
		'the consuming context must be recognised as a logged-in user'
	).toBe( 200 );
	const identity = ( await response.json() ) as {
		username?: unknown;
		email?: unknown;
	};
	expect( identity.username ).toBe( expected.username );
	if ( expected.email !== undefined ) {
		expect( identity.email ).toBe( expected.email );
	}
}

/**
 * Run `work` against a context created with no storage state at all, so the
 * session it establishes cannot have been inherited from the harness's own
 * authenticated fixtures. The context is discarded afterwards: everything the
 * login produced has to survive in the returned storage state or not at all.
 */
async function withStorageStateFreeContext< Result >(
	browser: Browser,
	baseURL: string,
	work: ( context: BrowserContext ) => Promise< Result >
): Promise< Result > {
	const context = await browser.newContext( { baseURL } );
	try {
		// `browser.newContext` takes only the options given to it, so this
		// context starts with no cookies and no origin storage — including
		// none of the admin context the suite's own `adminApi` fixture keeps
		// open alongside it.
		expect(
			loggedInCookieNames( await context.storageState() ),
			'a storage-state-free context must start with no WordPress session'
		).toEqual( [] );
		return await work( context );
	} finally {
		await context.close();
	}
}

test(
	'a storage-state-free browser context authenticates the seeded administrator, and a second context consuming only that state reaches the WordPress Dashboard and WooCommerce administration',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: ADMIN_CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { browser, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Half one — fresh-state login. Nothing cached is consulted: the state
		// this contract is about is built inside the test, so a stale
		// `.auth/merchant.json` cannot conceal changed credentials or changed
		// permissions, which is this row's recorded residual risk.
		const createdState = await withStorageStateFreeContext(
			browser,
			storeBase,
			async ( context ) => {
				const page = await context.newPage();

				// The logged-out precondition, asserted at the very surface the
				// second half will reach. Without it, that access proof could
				// pass on a store that never gated the Dashboard at all.
				await page.goto( WP_ADMIN_PATH );
				await expect( page ).toHaveURL( /wp-login\.php/ );
				await expect(
					page.getByRole( 'button', { name: 'Log In' } )
				).toBeVisible();
				await expect(
					page.getByRole( 'heading', {
						name: 'Dashboard',
						exact: true,
						level: 1,
					} )
				).toHaveCount( 0 );

				await page
					.getByLabel( 'Username or Email Address' )
					.fill( ADMIN_USERNAME );
				await page
					.getByRole( 'textbox', { name: 'Password' } )
					.fill( ADMIN_PASSWORD );
				await page.getByRole( 'button', { name: 'Log In' } ).click();
				await page.waitForURL( '**/wp-admin/**' );

				const state = await context.storageState();
				expect(
					loggedInCookieNames( state ).length,
					'the seeded administrator login must produce a WordPress session'
				).toBeGreaterThan( 0 );
				return state;
			}
		);

		// Half two — fresh-context access. This context never saw the login
		// form; everything it can do comes from the state the first half
		// created, which is what "the runtime can establish a session for
		// later work" actually means.
		const consumingContext = await browser.newContext( {
			baseURL: storeBase,
			storageState: createdState,
		} );
		try {
			const page = await consumingContext.newPage();

			await page.goto( WP_ADMIN_PATH );
			await expect( page ).toHaveURL( /\/wp-admin\/?(\?|$)/ );
			await expect(
				page.getByRole( 'heading', {
					name: 'Dashboard',
					exact: true,
					level: 1,
				} )
			).toBeVisible();
			// Operable, not merely titled: the admin's main navigation is
			// exposed to assistive technology and carries a usable entry. Its
			// wrapper has no box of its own, so attachment is the honest check.
			const mainMenu = page.getByRole( 'navigation', {
				name: 'Main menu',
			} );
			await expect( mainMenu ).toBeAttached();
			await expect(
				mainMenu
					.getByRole( 'link', { name: 'Dashboard', exact: true } )
					.first()
			).toBeVisible();

			// Username only: the administrator credentials are resolved from
			// the environment for this suite, so the seeded address that
			// happens to accompany them is not part of the contract.
			await expectSessionIdentity( consumingContext, {
				username: ADMIN_USERNAME,
			} );

			// The session carries administrator authority and not merely a
			// logged-in identity: WooCommerce administration answers it. This
			// is deliberately the store-management capability that protected
			// payment workflows are gated on, and deliberately not a claim
			// about the WooPayments account — that a Dashboard session proves
			// no WooPayments capability is this row's recorded residual risk,
			// and the payments surfaces are claimed by their own contracts.
			await page.goto( WC_SETTINGS_PATH );
			await expect( page.getByText( DENIAL_TEXT ) ).toHaveCount( 0 );
			await expect(
				page.getByRole( 'button', { name: 'Save changes' } )
			).toBeVisible();
		} finally {
			await consumingContext.close();
		}
	}
);

test(
	'a storage-state-free browser context authenticates the seeded customer, and a second context consuming only that state reaches semantic My Account content with a Log out affordance that ends the session',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CUSTOMER_CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { browser, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		const createdState = await withStorageStateFreeContext(
			browser,
			storeBase,
			async ( context ) => {
				const page = await context.newPage();

				// The logged-out precondition at the shopper's own protected
				// surface: My Account renders its login form, and none of the
				// authenticated content the second half asserts.
				await page.goto( MY_ACCOUNT_PATH );
				await expect(
					page.getByRole( 'textbox', {
						name: /Username or email address/i,
					} )
				).toBeVisible();
				await expect(
					page.getByRole( 'navigation', { name: 'Account pages' } )
				).toHaveCount( 0 );

				// Authenticated the way the harness's own shopper fixtures do
				// it, through wp-login.
				await page.goto( 'wp-login.php' );
				await page
					.getByLabel( 'Username or Email Address' )
					.fill( customer.username );
				await page
					.getByRole( 'textbox', { name: 'Password' } )
					.fill( customer.password );
				await page.getByRole( 'button', { name: 'Log In' } ).click();
				// A failed login re-renders wp-login.php with its error; a
				// successful one leaves it, and WooCommerce redirects the
				// shopper away from wp-admin to their account.
				await page.waitForURL(
					( url ) => ! url.pathname.endsWith( '/wp-login.php' )
				);

				const state = await context.storageState();
				expect(
					loggedInCookieNames( state ).length,
					'the seeded customer login must produce a WordPress session'
				).toBeGreaterThan( 0 );
				return state;
			}
		);

		const consumingContext = await browser.newContext( {
			baseURL: storeBase,
			storageState: createdState,
		} );
		try {
			const page = await consumingContext.newPage();
			await page.goto( MY_ACCOUNT_PATH );

			// Semantic My Account content: the page's own heading and the
			// account navigation landmark WooCommerce labels itself. The
			// account subpages behind it belong to the release smoke and are
			// not re-walked here.
			await expect(
				page.getByRole( 'heading', { name: 'My account' } )
			).toBeVisible();
			const accountNav = page.getByRole( 'navigation', {
				name: 'Account pages',
			} );
			await expect( accountNav ).toBeVisible();
			// The logged-out form is gone, so the authenticated content above
			// is not merely rendering alongside a login prompt.
			await expect(
				page.getByRole( 'textbox', {
					name: /Username or email address/i,
				} )
			).toHaveCount( 0 );

			await expectSessionIdentity( consumingContext, {
				username: customer.username,
				email: customer.email,
			} );

			// The Log out affordance, addressed by its role and accessible
			// name inside the account navigation rather than by the
			// WooCommerce CSS class the client helper keyed on — a class can
			// outlive the behaviour it used to indicate, which is this row's
			// recorded residual risk.
			const logOut = accountNav.getByRole( 'link', { name: 'Log out' } );
			await expect( logOut ).toBeVisible();
			// WooCommerce only logs out on a nonce-carrying request; without
			// one it redirects to a confirmation notice instead. Asserting the
			// nonce keeps the click below a logout rather than a detour.
			await expect( logOut ).toHaveAttribute(
				'href',
				/customer-logout.*_wpnonce=/
			);

			// And it is an affordance that works, not just one that renders:
			// following it ends the session, and the same surface goes back to
			// offering the login form. A label-only oracle cannot tell those
			// two states apart.
			await logOut.click();
			await page.goto( MY_ACCOUNT_PATH );
			await expect(
				page.getByRole( 'textbox', {
					name: /Username or email address/i,
				} )
			).toBeVisible();
			await expect(
				page.getByRole( 'navigation', { name: 'Account pages' } )
			).toHaveCount( 0 );
		} finally {
			await consumingContext.close();
		}
	}
);
