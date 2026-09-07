import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-payment-settings-manual-capture.spec.ts:';
const CONTRACT_IDS = [
	`${ CONTRACT_PREFIX }22::As a merchant, I should be prompted a confirmation modal when I try to activate the manual capture › should show the confirmation dialog when enabling the manual capture`,
	`${ CONTRACT_PREFIX }34::As a merchant, I should be prompted a confirmation modal when I try to activate the manual capture › should not show the confirmation dialog when disabling the manual capture`,
	`${ CONTRACT_PREFIX }48::As a merchant, I should be prompted a confirmation modal when I try to activate the manual capture › should show the non-card methods disabled when manual capture is enabled`,
];

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const FIXTURE_AUDIT_API =
	'/wp-json/wc-native-payments-e2e/v1/provider-fixture-audit';
const SETTINGS_PAGE_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments';

const MANUAL_CAPTURE_TOGGLE_LABEL =
	'Issue an authorization on checkout and capture later';
const MODAL_TITLE = 'Enable manual capture';
// The payment-methods list container; scoping list-item queries to it keeps
// them off unrelated wp-admin list markup.
const METHODS_LIST_SELECTOR = '.woopayments-settings-payment-methods-list';
// The confirmation copy this family exists to pin: the merchant must be told
// about the capture workflow deadline before enabling, and why non-card
// methods will become unavailable. Both strings are authored by core.
const DEADLINE_WARNING = /within 7 days of authorization/;
const INCOMPATIBILITY_NOTICE = /available for card payments only/;
const INCOMPATIBLE_CHIP = 'Unavailable with manual capture';

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
 * Idempotent baseline: manual capture must start disabled — the modal under
 * test only guards the off-to-on transition. A drifted baseline is repaired
 * through the documented settings route rather than failing the family for a
 * prior run's leftovers.
 */
async function ensureManualCaptureDisabled(
	adminApi: APIRequestContext,
	paymentsSettings: Record< string, unknown >
): Promise< void > {
	if ( paymentsSettings.is_manual_capture_enabled !== true ) {
		return;
	}
	await readJson(
		await adminApi.post( PAYMENTS_SETTINGS_API, {
			data: { is_manual_capture_enabled: false },
		} ),
		'Manual capture baseline reset'
	);
}

/**
 * The method label of a payment-method list row, read from the row's own
 * heading rather than parsed out of the whole row's concatenated text — the
 * chip, description, and fee copy share the row with no separators.
 */
async function methodNameOfRow( row: Locator ): Promise< string > {
	return (
		( await row.getByRole( 'heading' ).first().textContent() ) ?? ''
	).trim();
}

/**
 * Count POSTs to the payments settings route so the test can prove the whole
 * modal interaction never persisted anything: the confirmation flow under
 * test is client-side state ahead of an explicit save the test never issues.
 */
function trackSettingsWrites( page: Page ): () => number {
	let writeCount = 0;
	page.on( 'request', ( request ) => {
		if ( request.method() === 'GET' ) {
			return;
		}
		try {
			const url = new URL( request.url() );
			const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
			if (
				url.pathname
					.replace( /\/+$/, '' )
					.endsWith( '/wc/v3/payments/settings' ) ||
				restRoute.replace( /\/+$/, '' ) === '/wc/v3/payments/settings'
			) {
				writeCount++;
			}
		} catch {
			// Unparsable URLs cannot be the settings route.
		}
	} );
	return () => writeCount;
}

async function proveSecretlessProviderSettingsRoundTrip(
	adminApi: APIRequestContext,
	paymentSettings: Record< string, unknown >
): Promise< void > {
	if ( process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE !== 'true' ) {
		return;
	}
	const originalName = paymentSettings.account_business_name;
	if ( typeof originalName !== 'string' || originalName === '' ) {
		throw new Error( 'Fixture settings exposed no account business name.' );
	}
	const changedName = 'Native CI REST provider proof';
	try {
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { account_business_name: changedName },
			} ),
			'Fixture provider-backed settings update'
		);
		const reread = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Fixture provider-backed settings re-read'
		);
		expect( reread.account_business_name ).toBe( changedName );
		const audit = await readJson(
			await adminApi.get( FIXTURE_AUDIT_API ),
			'Fixture provider request audit'
		);
		const requests = audit.requests;
		expect( Array.isArray( requests ) ).toBe( true );
		expect( requests ).toContainEqual( {
			method: 'POST',
			path: '/wpcom/v2/sites/777/wcpay/accounts',
			query: expect.objectContaining( {
				token: expect.any( String ),
				timestamp: expect.any( String ),
				nonce: expect.any( String ),
				signature: expect.any( String ),
			} ),
			body: {
				business_name: changedName,
				test_mode: true,
			},
		} );
	} finally {
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { account_business_name: originalName },
			} ),
			'Fixture provider-backed settings restoration'
		);
		const restored = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Fixture provider-backed settings restored-state read'
		);
		expect( restored.account_business_name ).toBe( originalName );
	}
}

test(
	'manual capture warns before enabling, flags incompatible methods, and disables without ceremony',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page } ) => {
		// Precondition guard plus idempotent baseline: the gateway must be
		// active for the settings surface to exist, and manual capture must
		// start disabled — the modal under test only guards the off-to-on
		// transition. A drifted baseline is repaired through the documented
		// settings route rather than failing the family for a prior run's
		// leftovers.
		const paymentsSettings = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
		await proveSecretlessProviderSettingsRoundTrip(
			adminApi,
			paymentsSettings
		);
		await ensureManualCaptureDisabled( adminApi, paymentsSettings );

		const settingsWrites = trackSettingsWrites( page );
		await page.goto( 'wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( ADMIN_USERNAME );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( ADMIN_PASSWORD );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( '**/wp-admin/**' );
		await page.goto( SETTINGS_PAGE_PATH );

		const toggle = page.getByRole( 'checkbox', {
			name: MANUAL_CAPTURE_TOGGLE_LABEL,
			exact: true,
		} );
		await expect( toggle ).toBeVisible();
		await expect( toggle ).not.toBeChecked();
		const methodsList = page.locator( METHODS_LIST_SELECTOR );

		// Contract: enabling prompts a confirmation dialog whose copy warns
		// about the capture workflow deadline and explains the method
		// incompatibility that will follow. Matching the dialog by its
		// accessible name proves the heading really names the modal, not
		// merely that a heading sits inside some dialog, and disambiguates
		// it from the promotion-badge tooltip dialog this surface also
		// renders.
		await toggle.click();
		const dialog = page.getByRole( 'dialog', { name: MODAL_TITLE } );
		await expect( dialog ).toBeVisible();
		await expect( dialog.getByText( DEADLINE_WARNING ) ).toBeVisible();
		await expect(
			dialog.getByText( INCOMPATIBILITY_NOTICE )
		).toBeVisible();

		// Cancelling is a real choice: the dialog closes and nothing was
		// enabled, so a merchant who reads the warning can walk away.
		await dialog
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( toggle ).not.toBeChecked();

		// Confirming enables the toggle.
		await toggle.click();
		await expect( dialog ).toBeVisible();
		await dialog
			.getByRole( 'button', { name: MODAL_TITLE, exact: true } )
			.click();
		await expect( dialog ).toHaveCount( 0 );
		await expect( toggle ).toBeChecked();

		// Contract: with manual capture enabled, incompatible methods are
		// flagged with the reason and their controls genuinely disabled.
		// The chip count also serves as the breadth precondition: a store
		// listing no incompatible method would make this half vacuous, so
		// zero chips fails here rather than passing silently.
		const chips = methodsList.getByText( INCOMPATIBLE_CHIP );
		await expect( chips.first() ).toBeVisible();
		const chipCount = await chips.count();
		expect( chipCount ).toBeGreaterThan( 0 );
		const flaggedRows = methodsList
			.getByRole( 'listitem' )
			.filter( { hasText: INCOMPATIBLE_CHIP } );
		const flaggedRowCount = await flaggedRows.count();
		expect( flaggedRowCount ).toBeGreaterThan( 0 );
		for ( let index = 0; index < flaggedRowCount; index++ ) {
			const flaggedCheckbox = flaggedRows
				.nth( index )
				.getByRole( 'checkbox' );
			// Removed from the tab order, not merely reported disabled...
			await expect( flaggedCheckbox ).toBeDisabled();
			await expect( flaggedCheckbox ).toHaveAttribute( 'disabled', '' );
			// ...and the reason is programmatically associated with the
			// control, so a screen-reader user hears why it is unavailable.
			await expect( flaggedCheckbox ).toHaveAccessibleDescription(
				new RegExp( INCOMPATIBLE_CHIP )
			);
		}
		// Remember one flagged method to prove re-eligibility after
		// disabling, without hardcoding a store-dependent method name.
		const firstFlaggedMethod = await methodNameOfRow( flaggedRows.first() );
		expect( firstFlaggedMethod ).not.toBe( '' );

		// Contract: disabling asks no irrelevant confirmation, and the
		// methods excluded only by capture incompatibility become eligible
		// again. Anchor on the settled unchecked state first, so the
		// no-dialog assertion is read after the disable takes effect rather
		// than passing on the pre-toggle frame.
		await toggle.click();
		await expect( toggle ).not.toBeChecked();
		await expect( dialog ).toHaveCount( 0 );
		await expect( methodsList.getByText( INCOMPATIBLE_CHIP ) ).toHaveCount(
			0
		);
		await expect(
			methodsList
				.getByRole( 'listitem' )
				.filter( { hasText: firstFlaggedMethod } )
				.first()
				.getByRole( 'checkbox' )
		).toBeEnabled();

		// Zero-persistence proof: the entire confirmation flow is pre-save
		// client state — no settings write was dispatched, and the stored
		// setting still reports manual capture disabled.
		expect( settingsWrites() ).toBe( 0 );
		const settingsAfter = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings re-read'
		);
		expect( settingsAfter.is_manual_capture_enabled ).toBe( false );
	}
);
