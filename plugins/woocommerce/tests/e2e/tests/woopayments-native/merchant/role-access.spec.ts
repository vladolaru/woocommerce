import type { BrowserContext } from '@playwright/test';
import { WP_API_PATH } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';

const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/merchant/non-admin-wp-admin-access.spec.ts:43::Non-admin WP-Admin access › should be able to access wp-admin of fully onboarded WooPayments site',
	'default::chromium::tests/e2e/specs/wcpay/merchant/non-admin-wp-admin-access.spec.ts:58::Non-admin WP-Admin access › should be able to access wp-admin before and after onboarding',
];

const RUNTIME_STATUS_API = 'wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
// The editor's own denial read goes through a raw, cookie-authenticated
// request context rather than the shared basic-auth client, so it needs the
// route's full site-relative path.
const PAYMENTS_SETTINGS_ROUTE = '/wp-json/wc/v3/payments/settings';
const PAYMENTS_ADMIN_PATH =
	'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview';
const PAYMENTS_SETTINGS_TAB_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout';

function isolatedContextOptions( baseURL: string | undefined ) {
	return {
		baseURL,
		storageState: { cookies: [], origins: [] },
	};
}

/**
 * Establish a logged-in session in an isolated browser context through a
 * throwaway page, so the caller can then open fresh pages from that context
 * already authenticated.
 *
 */
async function establishSession(
	context: BrowserContext,
	username: string,
	password: string
): Promise< void > {
	const page = await context.newPage();
	try {
		await page.goto( 'wp-login.php' );
		await expect(
			page.getByLabel( 'Username or Email Address' )
		).toBeVisible();
		await logIn( page, username, password );
	} finally {
		await page.close();
	}
}

/**
 * A fresh REST nonce for the context's logged-in user, through the core
 * admin-ajax rest-nonce action, so REST capability checks run as that user.
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

test(
	'editor wp-admin access stays intact on a connected store while payment administration stays gated',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, browser, baseURL } ) => {
		const editor = getFakeUser( 'editor' );
		const created = (
			await restApi.post< { id?: unknown } >(
				`${ WP_API_PATH }/users`,
				editor
			)
		).data;
		if ( typeof created.id !== 'number' ) {
			throw new Error(
				'Smoke editor creation response did not contain a numeric ID.'
			);
		}
		// The create endpoint ignores the singular `role` field and defaults
		// to subscriber; the role has to be set with a follow-up `roles`
		// array, the same two-step shape `utils/data.ts` consumers use
		// elsewhere in this suite.
		try {
			await restApi.put( `${ WP_API_PATH }/users/${ created.id }`, {
				roles: [ 'editor' ],
			} );

			// Precondition guard: the first contract is about a *fully onboarded*
			// store, so the run must fail loudly here if the standing account
			// connection or the gateway ever degrades, instead of proving editor
			// access on a store whose payments never activated.
			const runtimeStatus = (
				await restApi.get< Record< string, unknown > >(
					RUNTIME_STATUS_API
				)
			).data;
			expect( runtimeStatus.account_connected ).toBe( true );
			expect( runtimeStatus.gateway_enabled ).toBe( true );

			// Positive control for the REST denial oracle below: the payments
			// settings route genuinely exists and answers an authorized caller,
			// so the editor's rejection is a capability denial rather than a
			// missing route.
			await restApi.get( PAYMENTS_SETTINGS_API );

			// Positive control for the page denial oracle: an administrator
			// genuinely reaches the payments admin surface at the same URL the
			// editor is denied on, so the denial assertions cannot pass against
			// a broken or nonexistent page.
			const adminContext = await browser.newContext(
				isolatedContextOptions( baseURL )
			);
			try {
				await establishSession(
					adminContext,
					admin.username,
					admin.password
				);
				const adminPage = await adminContext.newPage();
				await adminPage.goto( PAYMENTS_ADMIN_PATH );
				await expect(
					adminPage.getByRole( 'heading', {
						name: 'Overview',
						exact: true,
					} )
				).toBeVisible();
				await expect(
					adminPage.getByRole( 'heading', {
						name: 'Not allowed',
						exact: true,
					} )
				).toHaveCount( 0 );
				await adminPage.close();
			} finally {
				await adminContext.close();
			}

			// Contract: an editor's ordinary wp-admin access works on the fully
			// onboarded store — no payments interception, no fatal — while every
			// payment administration surface denies without the capability.
			const editorContext = await browser.newContext(
				isolatedContextOptions( baseURL )
			);
			try {
				await establishSession(
					editorContext,
					editor.username,
					editor.password
				);
				const editorPage = await editorContext.newPage();

				// Ordinary wp-admin: the dashboard renders and the URL path is
				// the dashboard itself — nothing intercepted the request into an
				// onboarding or payments flow. A trailing query string from an
				// unrelated admin notice is tolerated; a path change is not.
				await editorPage.goto( '/wp-admin/' );
				await expect( editorPage ).toHaveURL( /\/wp-admin\/?(\?|$)/ );
				await expect(
					editorPage.getByRole( 'heading', {
						name: 'Dashboard',
						exact: true,
						level: 1,
					} )
				).toBeVisible();
				// The admin is operable, not merely titled: the main navigation
				// landmark is exposed to assistive technology (its wrapper has
				// no box of its own, so attachment is the honest check) and
				// carries a usable, visible menu entry.
				const mainMenu = editorPage.getByRole( 'navigation', {
					name: 'Main menu',
				} );
				await expect( mainMenu ).toBeAttached();
				await expect(
					mainMenu.getByRole( 'link', { name: 'Posts', exact: true } )
				).toBeVisible();

				// An ordinary editing surface works.
				await editorPage.goto( '/wp-admin/edit.php' );
				await expect(
					editorPage.getByRole( 'heading', {
						name: 'Posts',
						exact: true,
						level: 1,
					} )
				).toBeVisible();

				// Payment administration is denied, in the two shapes the two
				// surfaces use: the classic settings screen dies with the core
				// permissions message, and the payments admin app renders its
				// not-allowed screen.
				await editorPage.goto( PAYMENTS_SETTINGS_TAB_PATH );
				await expect(
					editorPage.getByText(
						'Sorry, you are not allowed to access this page.'
					)
				).toBeVisible();
				await editorPage.goto( PAYMENTS_ADMIN_PATH );
				await expect(
					editorPage.getByRole( 'heading', {
						name: 'Not allowed',
						exact: true,
					} )
				).toBeVisible();
				// The denial the user actually reads, not just the layout
				// chrome's route title.
				await expect(
					editorPage.getByText(
						'Sorry, you are not allowed to access this page.'
					)
				).toBeVisible();
				await expect(
					editorPage.getByRole( 'heading', {
						name: 'Overview',
						exact: true,
					} )
				).toHaveCount( 0 );

				// The payments REST surface denies the editor with a capability
				// error under a genuine authenticated nonce, mirroring the
				// gating the retained lower-layer integration tests prove.
				const nonce = await restNonce( editorContext );
				const editorSettings = await editorContext.request.get(
					PAYMENTS_SETTINGS_ROUTE,
					{ headers: { 'X-WP-Nonce': nonce } }
				);
				expect( editorSettings.status() ).toBe( 403 );
				const denial = ( await editorSettings.json() ) as {
					code?: string;
				};
				expect( denial.code ).toBe( 'woocommerce_rest_cannot_view' );
			} finally {
				await editorContext.close();
			}
		} finally {
			await restApi.delete( `${ WP_API_PATH }/users/${ created.id }`, {
				force: true,
				reassign: 1,
			} );
		}
	}
);
