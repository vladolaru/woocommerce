import type { APIRequestContext, BrowserContext } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use, so a store
// with non-default admin credentials drives the UI half and the API half with
// one identity.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/merchant/non-admin-wp-admin-access.spec.ts:43::Non-admin WP-Admin access › should be able to access wp-admin of fully onboarded WooPayments site',
	'default::chromium::tests/e2e/specs/wcpay/merchant/non-admin-wp-admin-access.spec.ts:58::Non-admin WP-Admin access › should be able to access wp-admin before and after onboarding',
];

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const PAYMENTS_ADMIN_PATH =
	'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview';
const PAYMENTS_SETTINGS_TAB_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout';

// One run-stable editor owned by this smoke, established idempotently on
// every run; nothing is deleted. The reset only ever touches the smoke's own
// fixture: a username match with a foreign email means this store is not the
// dedicated one this spec assumes, and the run stops rather than reassign a
// real account's credentials.
const EDITOR_USERNAME = 'woopayments-role-smoke-editor';
const EDITOR_EMAIL = `${ EDITOR_USERNAME }@example.com`;
const EDITOR_PASSWORD = 'woopayments-role-smoke-password';

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

async function ensureSmokeEditor(
	adminApi: APIRequestContext
): Promise< void > {
	const lookup = await adminApi.get(
		`/wp-json/wp/v2/users?search=${ EDITOR_USERNAME }&context=edit`
	);
	if ( ! lookup.ok() ) {
		throw new Error(
			`Smoke editor lookup failed: HTTP ${ lookup.status() } ${ await lookup.text() }`
		);
	}
	const existing = ( await lookup.json() ) as Array< {
		id: number;
		username: string;
		email: string;
		roles: string[];
	} >;
	const match = existing.find(
		( user ) => user.username === EDITOR_USERNAME
	);

	if ( match ) {
		if ( match.email !== EDITOR_EMAIL ) {
			throw new Error(
				'Smoke editor username is taken by an account with a different email; refusing to reset its credentials.'
			);
		}
		// Re-assert both the password the login below depends on and the
		// editor role list. Setting roles rewrites the user's capability
		// meta, clearing leftover user-level capability grants; a
		// capability added to the editor role itself is out of this reset's
		// reach and would instead break the denial assertions loudly.
		await readJson(
			await adminApi.post( `/wp-json/wp/v2/users/${ match.id }`, {
				data: { password: EDITOR_PASSWORD, roles: [ 'editor' ] },
			} ),
			'Smoke editor reset'
		);
		return;
	}

	await readJson(
		await adminApi.post( '/wp-json/wp/v2/users', {
			data: {
				username: EDITOR_USERNAME,
				email: EDITOR_EMAIL,
				password: EDITOR_PASSWORD,
				roles: [ 'editor' ],
				first_name: 'Role',
				last_name: 'Smoke',
			},
		} ),
		'Smoke editor creation'
	);
}

async function logIn(
	context: BrowserContext,
	username: string,
	password: string
): Promise< void > {
	const page = await context.newPage();
	try {
		await page.goto( 'wp-login.php' );
		await page.getByLabel( 'Username or Email Address' ).fill( username );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( '**/wp-admin/**' );
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
	async ( { adminApi, browser, baseURL } ) => {
		await ensureSmokeEditor( adminApi );

		// Precondition guard: the first contract is about a *fully onboarded*
		// store, so the run must fail loudly here if the standing account
		// connection or the gateway ever degrades, instead of proving editor
		// access on a store whose payments never activated.
		const runtimeStatus = await readJson(
			await adminApi.get( RUNTIME_STATUS_API ),
			'Runtime status read'
		);
		expect( runtimeStatus.account_connected ).toBe( true );
		expect( runtimeStatus.gateway_enabled ).toBe( true );

		// Positive control for the REST denial oracle below: the payments
		// settings route genuinely exists and answers an authorized caller,
		// so the editor's rejection is a capability denial rather than a
		// missing route.
		await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read as administrator'
		);

		// Positive control for the page denial oracle: an administrator
		// genuinely reaches the payments admin surface at the same URL the
		// editor is denied on, so the denial assertions cannot pass against
		// a broken or nonexistent page.
		const adminContext = await browser.newContext( { baseURL } );
		try {
			await logIn( adminContext, ADMIN_USERNAME, ADMIN_PASSWORD );
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
		const editorContext = await browser.newContext( { baseURL } );
		try {
			await logIn( editorContext, EDITOR_USERNAME, EDITOR_PASSWORD );
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
				PAYMENTS_SETTINGS_API,
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
	}
);
