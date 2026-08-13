import type { APIRequestContext, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

// Both WooPay feature-toggle contracts are claimed by this one smoke: a
// valid disable must start from a verified enabled state, and a valid
// enable must start from a verified disabled state, so the two transitions
// prove each other's baseline inside a single causal sequence instead of
// depending on suite ordering the way the source cases did.
const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/merchant/woopay-setup.spec.ts:26::WooPay setup › can disable the WooPay feature',
	'default::chromium::tests/e2e/specs/wcpay/merchant/woopay-setup.spec.ts:30::WooPay setup › can enable the WooPay feature',
];

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const SETTINGS_PAGE_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments';

// Mirrors FEEDBACK_THROTTLE_DAYS + getDaysSinceDate + the throttle rule in
// client/admin/client/woopayments/settings/settings-page.tsx. The disable
// save opens a "WooPay feedback" modal unless a recorded disable date makes
// it throttled; predicting from the same pre-read state the page loaded —
// and asserting the prediction — surfaces drift in that client logic
// instead of silently absorbing whichever modal happens to appear.
const FEEDBACK_THROTTLE_DAYS = 7;

function isWooPayDisableFeedbackThrottled(
	lastDisableDate: string,
	now = new Date()
): boolean {
	if ( lastDisableDate === '' ) {
		return false;
	}
	const parsed = new Date( lastDisableDate );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return false;
	}
	const diffDays = Math.ceil(
		Math.abs( now.getTime() - parsed.getTime() ) / ( 1000 * 60 * 60 * 24 )
	);
	return diffDays < FEEDBACK_THROTTLE_DAYS;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

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

function requireBoolean( value: unknown, description: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		throw new Error(
			`${ description } is not a boolean (received ${ JSON.stringify(
				value
			) }); the authoritative settings contract cannot anchor this smoke.`
		);
	}
	return value;
}

/**
 * Collect failed REST responses the settings surface fetches from the store,
 * so a page that renders its chrome while its settings fetch quietly errors
 * is not mistaken for a healthy load or a healthy save. Scoped to the
 * store's own REST API. Same oracle as the payouts-disputes smoke.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): { failures: () => string[]; observed: () => number } {
	const failures: string[] = [];
	const restPrefix = `${ new URL( baseUrl ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	let observedRestResponses = 0;
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
		if ( ! path ) {
			return;
		}
		observedRestResponses++;
		if ( response.status() >= 400 ) {
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

	return {
		failures: () => [ ...failures ],
		observed: () => observedRestResponses,
	};
}

/**
 * Count browser-originated writes to the payments settings route, so each
 * transition can be attributed to exactly one causal save from the UI —
 * a second write, or a write the test never issued, is a defect signature,
 * not noise. REST repairs and restoration issued through adminApi never
 * pass through the page, so they stay outside this count by construction.
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

test(
	'merchant disables and re-enables WooPay with each persisted state echoed by the authoritative settings contract',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Precondition guard plus snapshot: the gateway must be enabled for
		// the settings surface to exist, the WooPay express-checkout feature
		// flags must not be switched off (the toggle under test only renders
		// when they allow it), and the original WooPay state is recorded
		// before anything mutates so it can be restored afterwards.
		const before = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( before.is_wcpay_enabled ).toBe( true );
		const featureFlags =
			before.feature_flags && typeof before.feature_flags === 'object'
				? ( before.feature_flags as Record< string, unknown > )
				: {};
		expect(
			featureFlags.woopay,
			'the WooPay feature flag must not be disabled for this store'
		).not.toBe( false );
		expect(
			featureFlags.woopayExpressCheckout,
			'the WooPay express checkout feature flag must not be disabled for this store'
		).not.toBe( false );
		const originalWooPayEnabled = requireBoolean(
			before.is_woopay_enabled,
			'Snapshot of is_woopay_enabled'
		);
		const originalLastDisableDate =
			typeof before.woopay_last_disable_date === 'string'
				? before.woopay_last_disable_date
				: '';

		// Seed the enabled baseline explicitly through the documented
		// settings route — the disable half of this smoke is only valid from
		// a verified enabled state, and the source suite's silent beforeAll
		// seeding is exactly the residual risk this migration retires. The
		// original value was captured above, so the seed stays restorable.
		if ( ! originalWooPayEnabled ) {
			await readJson(
				await adminApi.post( PAYMENTS_SETTINGS_API, {
					data: { is_woopay_enabled: true },
				} ),
				'WooPay enabled-baseline seed'
			);
		}
		const seeded = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings baseline re-read'
		);
		expect(
			seeded.is_woopay_enabled,
			'the smoke must start from a verified enabled WooPay state'
		).toBe( true );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const settingsWrites = trackSettingsWrites( page );

		let primaryError: unknown;
		try {
			// Clear first, matching the harness's own admin login: a stale
			// session cookie would redirect wp-login.php to wp-admin and
			// leave the form fill below hunting a field that is not there.
			await page.context().clearCookies();
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

			// The express checkouts section is a labelled region, and the
			// WooPay control inside it is a real checkbox named by its own
			// label; scoping to the region keeps the query off any other
			// WooPay text on the page. The enabled assertion doubles as the
			// Link precondition: the WooPay toggle renders disabled while
			// Link by Stripe is enabled, and that must fail loudly here
			// rather than as an inexplicable click timeout.
			const expressSection = page.getByRole( 'region', {
				name: 'Express checkouts',
			} );
			const wooPayToggle = expressSection.getByRole( 'checkbox', {
				name: 'WooPay',
				exact: true,
			} );
			await expect( wooPayToggle ).toBeVisible();
			await expect(
				wooPayToggle,
				'the WooPay toggle must be actionable — it is rendered disabled while Link by Stripe is enabled'
			).toBeEnabled();
			await expect( wooPayToggle ).toBeChecked();

			// The save bar's live status is the page's own account of the
			// save lifecycle; it carries no ARIA role, so the class hook is
			// the narrowest stable locator for it. The save button is scoped
			// to the same bar because wp-admin renders other submit controls.
			const saveBar = page.locator( '.woopayments-settings-save-bar' );
			const saveButton = saveBar.getByRole( 'button', {
				name: 'Save changes',
			} );
			const saveStatus = saveBar.locator(
				'.woopayments-settings-save-bar__status'
			);

			// Contract: a merchant can disable WooPay and the disabled
			// configuration persists. One causal save, then the
			// authoritative REST contract — not the save notice — is the
			// oracle for the persisted state.
			await wooPayToggle.uncheck();
			await expect( saveStatus ).toHaveText(
				'You have unsaved changes.'
			);
			await saveButton.click();
			await expect( saveStatus ).toHaveText( 'Settings saved.' );

			// The disable save opens the WooPay feedback modal unless a
			// recent recorded disable date throttles it. The expectation is
			// computed from the same state the page loaded, then asserted,
			// so an unexpected modal (or an expected one that never opens)
			// fails as client-logic drift. The modal's open state is settled
			// in the same render batch as the save status above, so the
			// zero-count branch does not race the modal mounting.
			const feedbackDialog = page.getByRole( 'dialog', {
				name: 'WooPay feedback',
			} );
			if (
				! isWooPayDisableFeedbackThrottled( originalLastDisableDate )
			) {
				await expect( feedbackDialog ).toBeVisible();
				await feedbackDialog
					.getByRole( 'button', { name: /^Close( dialog)?$/ } )
					.click();
			}
			await expect( feedbackDialog ).toHaveCount( 0 );

			const afterDisable = await readJson(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'Payments settings read after disable'
			);
			expect(
				afterDisable.is_woopay_enabled,
				'the authoritative settings contract must echo the disabled state'
			).toBe( false );
			expect( settingsWrites() ).toBe( 1 );

			// Persistence beyond the notice: a cold reload of the surface
			// must render the disabled state from storage, which is the
			// exact gap the save-notice-only source oracle left open.
			await page.reload();
			await expect( wooPayToggle ).toBeVisible();
			await expect( wooPayToggle ).not.toBeChecked();

			// Contract: a merchant can enable WooPay and the enabled
			// configuration persists — beginning from the disabled state
			// verified through the REST contract and the reloaded UI above,
			// not from a sibling case's side effect.
			await wooPayToggle.check();
			await expect( saveStatus ).toHaveText(
				'You have unsaved changes.'
			);
			await saveButton.click();
			await expect( saveStatus ).toHaveText( 'Settings saved.' );
			// Enabling never opens the feedback modal.
			await expect( feedbackDialog ).toHaveCount( 0 );

			const afterEnable = await readJson(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'Payments settings read after enable'
			);
			expect(
				afterEnable.is_woopay_enabled,
				'the authoritative settings contract must echo the enabled state'
			).toBe( true );
			expect( settingsWrites() ).toBe( 2 );

			await page.reload();
			await expect( wooPayToggle ).toBeVisible();
			await expect( wooPayToggle ).toBeChecked();

			// No store REST request behind the settings surface failed, and
			// the collector actually observed traffic — an empty failure set
			// means nothing on zero observations.
			expect( restTracker.observed() ).toBeGreaterThan( 0 );
			expect( restTracker.failures() ).toEqual( [] );
		} catch ( error ) {
			primaryError = error;
		}

		// Restoration: return is_woopay_enabled to the snapshot value taken
		// before any mutation, verify the readback, and run this even when
		// the flow above failed so a red run does not also leave the store
		// drifted. A restoration mismatch after a green flow is its own
		// failure, phrased so the store state is treated as quarantined
		// rather than silently trusted by later runs.
		let restorationError: Error | undefined;
		try {
			const current = await readJson(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'Payments settings restoration read'
			);
			if ( current.is_woopay_enabled !== originalWooPayEnabled ) {
				await readJson(
					await adminApi.post( PAYMENTS_SETTINGS_API, {
						data: { is_woopay_enabled: originalWooPayEnabled },
					} ),
					'WooPay setting restoration'
				);
			}
			const restored = await readJson(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'WooPay setting restoration re-read'
			);
			if ( restored.is_woopay_enabled !== originalWooPayEnabled ) {
				restorationError = new Error(
					`WooPay setting was not restored: the store reports is_woopay_enabled ${ JSON.stringify(
						restored.is_woopay_enabled
					) } but the pre-test snapshot recorded ${ JSON.stringify(
						originalWooPayEnabled
					) }. Treat the store's WooPay settings state as quarantined: do not trust subsequent runs against it until the setting is manually verified and restored.`
				);
			}
		} catch ( error ) {
			restorationError =
				error instanceof Error ? error : new Error( String( error ) );
		}

		if ( primaryError !== undefined ) {
			if ( restorationError ) {
				console.error(
					'WooPay settings restoration failed after the primary test failure:',
					restorationError
				);
			}
			throw primaryError;
		}
		if ( restorationError ) {
			throw restorationError;
		}
	}
);
