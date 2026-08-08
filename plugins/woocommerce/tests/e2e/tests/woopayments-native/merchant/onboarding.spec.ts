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
const UPDATE_CURRENCIES_API = `${ MULTI_CURRENCY_API }/update-enabled-currencies`;
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
// WordPress's always-present polite announcement region, which `speak()`
// writes into. Asserting it is the difference between "a description exists
// somewhere" and "the merchant was told".
const POLITE_ANNOUNCEMENT_REGION = '#a11y-speak-polite';

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
		const { pathname, searchParams } = new URL( request.url() );
		// Both REST request forms: the pretty-permalink path and the
		// plain-permalink `rest_route` parameter, whose slashes arrive
		// percent-encoded and would defeat a path-only substring match.
		const restRoute = searchParams.get( 'rest_route' ) ?? '';
		const targetsMultiCurrency =
			pathname.includes( MULTI_CURRENCY_REST_PREFIX ) ||
			restRoute.includes( MULTI_CURRENCY_REST_PREFIX );

		if ( request.method() !== 'GET' && targetsMultiCurrency ) {
			writes.push( `${ request.method() } ${ pathname }${ restRoute }` );
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

/**
 * Put the store's enabled currencies back if this run somehow changed them.
 * Nothing here is supposed to write, but the whole point of the test is that
 * a regression could, and this store is shared by every other native spec:
 * leaving it with the wrong currency set would fail them for reasons that
 * have nothing to do with what they assert. Returns a message describing an
 * unrestorable store, or null when there is nothing to report, so a failure
 * inside the test body is never masked by a failure while cleaning up.
 */
async function restoreEnabledCurrencies(
	adminApi: APIRequestContext,
	expectedCodes: string[]
): Promise< string | null > {
	const actualCodes = await readEnabledCodes(
		adminApi,
		'Store currencies restoration read'
	);
	if ( actualCodes.join( ',' ) === expectedCodes.join( ',' ) ) {
		return null;
	}

	// Restoring the codes is not the same as restoring the configuration: on a
	// store with a working rate provider the re-added currency would come back
	// with a provider rate rather than whatever manual rate it carried before.
	// This store has no such provider, so the failure below is the live case.
	await adminApi.post( UPDATE_CURRENCIES_API, {
		data: { enabled: expectedCodes },
	} );
	const restoredCodes = await readEnabledCodes(
		adminApi,
		'Store currencies restoration re-read'
	);
	if ( restoredCodes.join( ',' ) === expectedCodes.join( ',' ) ) {
		return null;
	}

	// Removing a currency also removes its per-currency settings, and on a
	// store whose rate provider returns nothing those settings are the only
	// reason the currency is available at all, so this is not always
	// recoverable through the product.
	const message = `The run changed the store's enabled currencies and could not put them back: expected ${ expectedCodes.join(
		', '
	) }, found ${ restoredCodes.join(
		', '
	) }. The standing store needs manual restoration before other specs run.`;
	// eslint-disable-next-line no-console
	console.error( message );

	return message;
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
		let restorationFailure: string | null = null;

		try {
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
			const search = modal.getByRole( 'searchbox', {
				name: SEARCH_LABEL,
			} );
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
			// Focus stays where the merchant left it. The hint mounting must
			// not move it, or the announcement below would be all they get and
			// their place in the form would be gone.
			await expect( enabledCurrencyCheckbox ).toBeFocused();
			await expect( updateSelected ).toHaveAttribute(
				'aria-disabled',
				'true'
			);
			// Communicated, not merely present. The description says why the
			// action is unavailable to anyone who reaches the control, and the
			// announcement tells the merchant it just became unavailable while
			// their focus is still two tab stops away from it.
			await expect(
				page.locator( POLITE_ANNOUNCEMENT_REGION )
			).toHaveText( EMPTY_SELECTION_HINT );
			await expect( updateSelected ).toHaveAccessibleDescription(
				EMPTY_SELECTION_HINT
			);
			// Visible too, so the hint cannot regress into a screen-reader-only
			// string and leave a sighted keyboard user with a dimmed button and
			// no explanation - the accessible description would still resolve.
			await expect(
				modal.getByText( EMPTY_SELECTION_HINT )
			).toBeVisible();

			// The control stays reachable, which is the point of an accessibly
			// disabled button: a keyboard user can still land on it and read
			// why. Proven by tabbing to it, anchored on Cancel - both buttons
			// sit in the modal's fixed actions row, so the distance is one
			// press whatever the store's currency catalog looks like. Playwright
			// cannot express this with toBeEnabled, which treats aria-disabled
			// as disabled and so rejects the very state under test.
			await modal.getByRole( 'button', { name: 'Cancel' } ).focus();
			await page.keyboard.press( 'Tab' );
			await expect( updateSelected ).toBeFocused();
			// Kept alongside the Tab press because they localise a failure to
			// its cause: a control that lost reachability by becoming natively
			// disabled, or by leaving the tab order, says which one here rather
			// than reporting a bare focus mismatch.
			await expect( updateSelected ).not.toHaveAttribute(
				'disabled',
				''
			);
			await expect( updateSelected ).not.toHaveAttribute(
				'tabindex',
				'-1'
			);
			// A keyboard user who reached it and presses it changes nothing:
			// no write, the modal stays open, and the store still enables what
			// it enabled before.
			await page.keyboard.press( 'Enter' );
			await page.keyboard.press( 'Space' );
			// The write assertions come before the modal check on purpose. If the
			// guard ever regresses, those key presses submit for real and close
			// the modal, and a modal-visibility failure would report a missing
			// dialog while the write that caused it went unmentioned - and while
			// the shared store silently carried the damage.
			expect( multiCurrencyWrites() ).toEqual( [] );
			expect(
				await readEnabledCodes( adminApi, 'Store currencies re-read' )
			).toEqual( enabledBefore );
			await expect( modal ).toBeVisible();

			// Recovering the selection restores the action, so the guard tracks
			// the selection rather than latching.
			await enabledCurrencyCheckbox.check();
			await expect( updateSelected ).toBeEnabled();

			await modal.getByRole( 'button', { name: 'Cancel' } ).click();
			await expect( modal ).toHaveCount( 0 );
			expect( multiCurrencyWrites() ).toEqual( [] );
			expect(
				await readEnabledCodes(
					adminApi,
					'Store currencies final read'
				)
			).toEqual( enabledBefore );
		} finally {
			// Nothing thrown here may escape. A throw from `finally` replaces
			// whatever the body was failing on, so a store that happened to be
			// unreachable while cleaning up would report itself instead of the
			// regression that made cleaning up necessary.
			restorationFailure = await restoreEnabledCurrencies(
				adminApi,
				enabledBefore
			).catch( ( error ) => {
				const message = `Restoration could not run against the store: ${ error }. The standing store may need manual restoration before other specs run.`;
				// eslint-disable-next-line no-console
				console.error( message );
				return message;
			} );
		}

		expect(
			restorationFailure,
			'the shared store must be left with the enabled currencies it started with'
		).toBeNull();
	}
);
