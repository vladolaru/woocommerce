import type { APIRequestContext, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

// Both theme rows keep their client test and pair with native coverage, so
// this is the one native surface smoke rather than a UI-parity assertion.
// Its merchant half lives in this file because the ledger's approved
// client-only target for both rows is this path; the shopper theme rows that
// will join it later are the reason for the directory.
const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-on-boarding.spec.ts:';
const CONTRACT_IDS = [
	`${ CONTRACT_PREFIX }214::Multi-currency on-boarding › Currency Switcher widget › should offer the currency switcher widget while Storefront theme is active`,
	`${ CONTRACT_PREFIX }224::Multi-currency on-boarding › Currency Switcher widget › should not offer the currency switcher widget when an unsupported theme is active`,
];

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const SETTINGS_API = `${ MULTI_CURRENCY_API }/get-settings`;
const CURRENCIES_API = `${ MULTI_CURRENCY_API }/currencies`;
// The whole multi-currency write surface, not just the store-settings route:
// the same screen's currency management writes through a sibling route this
// visit must not touch either, and `get-settings` would not surface that.
const MULTI_CURRENCY_REST_PREFIX = 'payments/multi-currency/';
const MULTI_CURRENCY_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';

const THEMES_API = '/wp-json/wp/v2/themes?status=active';
// The theme whose breadcrumb section is the only placement native offers.
// Everything else is an unsupported theme for that placement. Matched by slug,
// which is the predicate the native Storefront integration itself uses.
const STOREFRONT_THEME_SLUG = 'storefront';
// The section wrapper the store-level settings render into.
const STORE_SETTINGS_SECTION =
	'.woocommerce-multi-currency-settings__store-settings';
const CHECKBOX_SELECTOR = 'input[type="checkbox"]';
// The switcher placement offer, by the two things a merchant can recognize it
// by: the theme it names and the section it would be added to.
const STOREFRONT_PLACEMENT_LABEL = /storefront/i;
const BREADCRUMB_PLACEMENT_LABEL = /breadcrumb/i;
// The theme-independent control that proves the store-settings section
// rendered its own form, so a zero count on the placement offer above cannot
// pass because nothing rendered at all.
const AUTOMATIC_CURRENCY_LABEL =
	'Automatically switch customers to their local currency if it has been enabled';

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

/**
 * Read the active theme's identity from WordPress itself. This is the
 * independent half of the theme precondition: the native Storefront
 * integration decides eligibility from the stylesheet and template slugs,
 * while the settings screen decides from the display name, and only an
 * outside source can say which theme is really active.
 */
async function readActiveTheme(
	adminApi: APIRequestContext
): Promise< { stylesheet: string; template: string; name: string } > {
	const response = await adminApi.get( THEMES_API );
	if ( ! response.ok() ) {
		throw new Error(
			`Active theme read failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	const themes = ( await response.json() ) as Array< {
		stylesheet: string;
		template: string;
		name: { rendered: string };
	} >;
	if ( themes.length !== 1 ) {
		throw new Error(
			`Expected exactly one active theme; WordPress reported ${ themes.length }.`
		);
	}
	const [ theme ] = themes;

	return {
		stylesheet: theme.stylesheet,
		template: theme.template,
		name: theme.name.rendered,
	};
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
 * Count multi-currency write requests the surface issues. The claim is that
 * merely looking at the settings screen under an unsupported theme changes
 * nothing, which a response-status oracle cannot make: a write that succeeded
 * is a healthy response.
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

test(
	'the multi-currency settings screen offers only switcher placements the active theme supports',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page } ) => {
		// Precondition: the negative half only means something under a theme
		// that genuinely cannot carry the Storefront breadcrumb placement. If
		// this store ever runs Storefront, the assertions below would be
		// asserting the wrong side of the contract, so fail loudly instead.
		//
		// Taken from the theme identity WordPress itself publishes, not from
		// the multi-currency settings response: that response's `site_theme`
		// is the very field the conditional under test branches on, so using
		// it alone would let a regression in its derivation satisfy the
		// precondition and the absence assertion at the same time.
		const activeTheme = await readActiveTheme( adminApi );
		expect(
			[ activeTheme.stylesheet, activeTheme.template ],
			'this smoke proves the unsupported-theme half and must not run under Storefront or a Storefront child'
		).not.toContain( STOREFRONT_THEME_SLUG );

		const settingsBefore = await readJson(
			await adminApi.get( SETTINGS_API ),
			'Multi-currency settings read'
		);
		// The join the two identities have to make: the backend decides
		// eligibility from the theme slug while this screen decides from the
		// display name, so the smoke is only asserting about the theme it
		// thinks it is when the two agree. Requiring a non-empty exact match
		// also stops an absent `site_theme` from passing the check above by
		// being undefined.
		expect(
			settingsBefore.site_theme,
			'the settings screen must report the same active theme WordPress does'
		).toBe( activeTheme.name );
		expect(
			settingsBefore.site_theme,
			'an empty site theme would satisfy every check here without naming a theme'
		).not.toBe( '' );
		expect(
			settingsBefore.wcpay_multi_currency_enable_storefront_switcher,
			'the Storefront placement must start off, otherwise the absent offer proves nothing about eligibility'
		).toBe( false );

		// Precondition: the switcher is only a meaningful offer on a store
		// that has something to switch between.
		const currencies = await readJson(
			await adminApi.get( CURRENCIES_API ),
			'Store currencies read'
		);
		const enabledCodes = Object.keys(
			( currencies.enabled ?? {} ) as Record< string, unknown >
		);
		expect(
			enabledCodes.length,
			'the store must enable at least two currencies for a switcher offer to be meaningful'
		).toBeGreaterThanOrEqual( 2 );

		const multiCurrencyWrites = trackMultiCurrencyWrites( page );

		await logInAsAdmin( page );
		await page.goto( MULTI_CURRENCY_SETTINGS_PATH );

		// Scoped by the section's own class rather than by role: this is where
		// the assertions look, not what they assert, and the section is a
		// plain <section> the accessibility tree gives no name to.
		const storeSettings = page.locator( STORE_SETTINGS_SECTION );

		// The store-settings section loaded its own form, not merely the
		// settings chrome: its heading plus the theme-independent control
		// every store gets. Without this the absence assertions below would
		// pass on a section that never rendered.
		await expect(
			page.getByRole( 'heading', { name: 'Store settings', exact: true } )
		).toBeVisible();
		const automaticCurrency = storeSettings.getByRole( 'checkbox', {
			name: AUTOMATIC_CURRENCY_LABEL,
		} );
		await expect( automaticCurrency ).toBeVisible();
		await expect( automaticCurrency ).toBeEnabled();

		// Contract: no placement mechanism the active theme cannot support is
		// advertised. Asserted through the accessibility tree, because an
		// offer a screen-reader user can reach is an offer.
		await expect(
			storeSettings.getByRole( 'checkbox', {
				name: STOREFRONT_PLACEMENT_LABEL,
			} )
		).toHaveCount( 0 );
		await expect(
			storeSettings.getByRole( 'checkbox', {
				name: BREADCRUMB_PLACEMENT_LABEL,
			} )
		).toHaveCount( 0 );
		// ...and not offered outside the accessibility tree either. A
		// checkbox hidden from assistive technology but left in the tab order
		// is still an offer a keyboard user can accept, and the two role
		// queries above cannot see it, so count the section's checkboxes
		// outright: the automatic-currency opt-in is the only one this store
		// may render.
		await expect( storeSettings.locator( CHECKBOX_SELECTOR ) ).toHaveCount(
			1
		);
		await expect(
			storeSettings.getByText( STOREFRONT_PLACEMENT_LABEL )
		).toHaveCount( 0 );
		await expect(
			storeSettings.getByText( BREADCRUMB_PLACEMENT_LABEL )
		).toHaveCount( 0 );

		// Looking is not editing. The save control stays reachable, which is
		// the point of an accessibly-disabled button, and a keyboard user who
		// reaches it and presses it changes nothing.
		const saveChanges = storeSettings.getByRole( 'button', {
			name: 'Save changes',
			exact: true,
		} );
		await expect( saveChanges ).toBeVisible();
		await expect( saveChanges ).toHaveAttribute( 'aria-disabled', 'true' );
		await saveChanges.focus();
		await expect( saveChanges ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.press( 'Space' );

		const settingsAfter = await readJson(
			await adminApi.get( SETTINGS_API ),
			'Multi-currency settings re-read'
		);
		expect( settingsAfter ).toEqual( settingsBefore );
		expect( multiCurrencyWrites() ).toEqual( [] );
	}
);
