import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// Native Core ships no first-run multi-currency wizard. Its equivalent of the
// client plugin's stepped onboarding is the multi-currency settings screen,
// whose "Add enabled currencies" modal is a search-filtered add/remove list -
// the 2026-08-08 entry in DECISIONS.md settles that mapping, and this spec is
// the onboarding-equivalent surface it names.

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-on-boarding.spec.ts:';
const EMPTY_SELECTION_CONTRACT = `${ CONTRACT_PREFIX }59::Multi-currency on-boarding › Currency selection and management › should disable the submit button when no currencies are selected`;

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const CURRENCIES_API = `${ MULTI_CURRENCY_API }/currencies`;
// The whole multi-currency write surface rather than the single enabled-
// currencies route: the same screen also writes store settings and per-
// currency settings, and none of them belongs to a merchant who only opened
// a modal and closed it again.
const MULTI_CURRENCY_REST_PREFIX = 'payments/multi-currency/';
const MULTI_CURRENCY_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';

const ADD_CURRENCIES_BUTTON = 'Add/remove currencies';
const MODAL_TITLE = 'Add enabled currencies';
const UPDATE_SELECTED_BUTTON = 'Update selected';
const SEARCH_LABEL = 'Search currencies';
const EMPTY_SELECTION_HINT = /Select at least one currency/;
// A search term no currency name or code can contain, so an empty result is
// the filter working rather than a coincidence about this store's catalog.
const UNMATCHABLE_SEARCH = 'zzzznotacurrency';

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

async function logInAsAdmin( page: Page ): Promise< void > {
	// Clear first, matching the harness's own admin login: a stale session
	// cookie redirects wp-login.php to wp-admin and leaves the form fill
	// below hunting a field that is not there.
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
 * Collect the multi-currency writes this screen issues. A response-status
 * oracle cannot make the claim below: a write that succeeded looks healthy,
 * and what the contract asserts is that this moment issues no write at all.
 */
function trackMultiCurrencyWrites( page: Page ): () => string[] {
	const writes: string[] = [];

	page.on( 'request', ( request ) => {
		const url = request.url();
		if (
			request.method() !== 'GET' &&
			url.includes( MULTI_CURRENCY_REST_PREFIX )
		) {
			writes.push( `${ request.method() } ${ new URL( url ).pathname }` );
		}
	} );

	return () => [ ...writes ];
}

/**
 * Read the store's currency configuration from the authoritative route rather
 * than from the screen, so the "nothing was written" claim below is joined to
 * stored state instead of to what the page happens to still be rendering.
 */
async function readCurrencyState(
	adminApi: APIRequestContext,
	description: string
): Promise< { enabledCodes: string[]; additionalCodes: string[] } > {
	const currencies = await readJson(
		await adminApi.get( CURRENCIES_API ),
		description
	);
	const defaultCode = (
		( currencies.default ?? {} ) as Record< string, unknown >
	 ).code;
	const enabledCodes = Object.keys(
		( currencies.enabled ?? {} ) as Record< string, unknown >
	).toSorted();

	return {
		enabledCodes,
		additionalCodes: enabledCodes.filter(
			( code ) => code !== defaultCode
		),
	};
}

async function readEnabledCodes(
	adminApi: APIRequestContext,
	description: string
): Promise< string[] > {
	return ( await readCurrencyState( adminApi, description ) ).enabledCodes;
}

function modalOf( page: Page ): Locator {
	// Matched by accessible name, not bare role: this screen renders a second
	// dialog for per-currency settings, and the name is what proves the title
	// names this modal to assistive technology.
	return page.getByRole( 'dialog', { name: MODAL_TITLE } );
}

test(
	'the add-currencies modal refuses an empty selection and explains why',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: EMPTY_SELECTION_CONTRACT,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page } ) => {
		// Precondition: the guard is about clearing a selection, so the store
		// must have something enabled beyond its default to clear. Without
		// this the modal would open with nothing checked and the positive
		// control below could not run at all.
		const { enabledCodes: enabledBefore, additionalCodes } =
			await readCurrencyState( adminApi, 'Store currencies read' );
		expect(
			additionalCodes,
			'the store must enable at least one currency beyond its default for the empty-selection guard to be reachable'
		).not.toEqual( [] );
		const [ additionalCode ] = additionalCodes;

		const multiCurrencyWrites = trackMultiCurrencyWrites( page );

		await logInAsAdmin( page );
		await page.goto( MULTI_CURRENCY_SETTINGS_PATH );
		await expect(
			page.getByRole( 'heading', {
				name: 'Enabled currencies',
				exact: true,
			} )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: ADD_CURRENCIES_BUTTON } )
			.click();
		const modal = modalOf( page );
		await expect( modal ).toBeVisible();

		// The enabled currency is offered exactly once and already selected:
		// native represents an enabled currency as a single pre-checked
		// control rather than hiding it, so there is no second, addable
		// control that would let a merchant add what they already have.
		const enabledCurrencyCheckbox = modal.getByRole( 'checkbox', {
			name: new RegExp( `\\b${ additionalCode }$` ),
		} );
		await expect( enabledCurrencyCheckbox ).toHaveCount( 1 );
		await expect( enabledCurrencyCheckbox ).toBeChecked();

		// The list is search-filtered, which is how a merchant locates a
		// currency on this screen.
		const search = modal.getByRole( 'searchbox', { name: SEARCH_LABEL } );
		await search.fill( additionalCode );
		await expect( enabledCurrencyCheckbox ).toHaveCount( 1 );
		await search.fill( UNMATCHABLE_SEARCH );
		await expect( modal.getByRole( 'checkbox' ) ).toHaveCount( 0 );
		await search.fill( '' );

		// Positive control: with a selection the primary action is available,
		// so the non-actionable state proven below is the guard and not a
		// permanently dead button.
		const updateSelected = modal.getByRole( 'button', {
			name: UPDATE_SELECTED_BUTTON,
		} );
		await expect( updateSelected ).toBeEnabled();

		// Contract: with nothing selected the submission is refused and the
		// merchant is told why, through a description the control references
		// rather than a message only a sighted user would connect to it.
		await enabledCurrencyCheckbox.uncheck();
		await expect( updateSelected ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		await expect( updateSelected ).toHaveAccessibleDescription(
			EMPTY_SELECTION_HINT
		);

		// The control stays reachable, which is the point of an accessibly
		// disabled button, and a keyboard user who reaches it and presses it
		// changes nothing: no write, the modal stays open, and the store still
		// enables what it enabled before.
		await updateSelected.focus();
		await expect( updateSelected ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.press( 'Space' );
		await expect( modal ).toBeVisible();
		expect( multiCurrencyWrites() ).toEqual( [] );
		expect(
			await readEnabledCodes( adminApi, 'Store currencies re-read' )
		).toEqual( enabledBefore );

		// Recovering the selection restores the action, so the guard tracks
		// the selection rather than latching.
		await enabledCurrencyCheckbox.check();
		await expect( updateSelected ).toBeEnabled();

		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( modal ).toHaveCount( 0 );
		expect( multiCurrencyWrites() ).toEqual( [] );
		expect(
			await readEnabledCodes( adminApi, 'Store currencies final read' )
		).toEqual( enabledBefore );
	}
);
