import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';
import { wpCLI } from '../../../utils/cli';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * The release-1 default funnel (N-495): a store with the WooPayments feature off and no WooPayments extension is offered
 * the extension exactly as trunk offers it. Every expectation is trunk's rendering (a1caf7b9200), not native's:
 *
 * - Payments settings: the WooPayments suggestion keeps its `plugin` (`PaymentsExtensionSuggestions.php:4457`), so the
 *   list item's action reads "Install" (`payment-extension-suggestion-list-item.tsx:83`).
 * - The profiler's extensions step lists "Get paid with WooPayments" from the default and remote bundles
 *   (`RemoteFreeExtensions/DefaultFreeExtensions.php:40,82`, `RemoteFreeExtensions/Init.php:65`).
 * - The Features page shows the WooPayments row off, with both choices enabled and the plain description.
 *
 * The secretless fixture store runs with the feature on, so the case first finds the built-in badge in the WooPayments
 * row (the badge selector is not vacuous), then turns the feature off. It records every option it touches first and puts
 * each one back afterwards, the way `performance/disabled-store-assets.spec.ts` does.
 */

const FEATURE_OPTION = 'woocommerce_feature_woopayments_enabled';
const ABSENT_MARKER = '__woopayments_native_e2e_feature_off_option_absent__';
// Walking the profiler to its extensions step writes the store details and the onboarding profile (seen on a program
// store); nothing is completed or installed.
const RECORDED_OPTIONS = [
	FEATURE_OPTION,
	'woocommerce_onboarding_profile',
	'woocommerce_onboarding_profile_progress',
	'blogname',
	'woocommerce_default_country',
	'woocommerce_currency_pos',
	'woocommerce_price_decimal_sep',
	'woocommerce_price_thousand_sep',
	'woocommerce_dimension_unit',
	'woocommerce_weight_unit',
	'woocommerce_allow_tracking',
	'woocommerce_allow_tracking_first_optin',
	'woocommerce_allow_tracking_last_modified',
	'wc_has_tracked_default_theme',
	'woocommerce_payments_provider_state_snapshots',
];

const PROVIDERS_API =
	/\/wp-json\/wc-admin\/settings\/payments\/providers(\?.*)?$/;
const PLUGIN_INSTALL_API = /\/wc-admin\/plugins\/(install|activate)\b/;

test.describe( 'Store with the WooPayments feature off and no extension', () => {
	const recorded = new Map< string, string >();

	test.beforeAll( async () => {
		// The case is about a store without the extension; a store that has it is not this funnel.
		const extension = await wpCLI( [
			'wp',
			'plugin',
			'is-active',
			'woocommerce-payments',
		] ).catch( () => null );
		expect(
			extension,
			'the WooPayments extension must not be active'
		).toBeNull();

		for ( const option of RECORDED_OPTIONS ) {
			recorded.set(
				option,
				(
					await wpCLI( [
						'wp',
						'eval',
						// Absent options echo the marker; stored values travel as base64 of their serialized form.
						`$value = get_option( '${ option }', '${ ABSENT_MARKER }' ); echo '${ ABSENT_MARKER }' === $value ? $value : base64_encode( maybe_serialize( $value ) );`,
					] )
				).stdout.trim()
			);
		}
	} );

	test.afterAll( async () => {
		for ( const [ option, value ] of recorded ) {
			if ( value === ABSENT_MARKER ) {
				await wpCLI( [ 'wp', 'option', 'delete', option ] );
			} else {
				await wpCLI( [
					'wp',
					'eval',
					`update_option( '${ option }', maybe_unserialize( base64_decode( '${ value }' ) ) );`,
				] );
			}
		}
	} );

	test(
		'A merchant without the WooPayments extension is offered it in Payments settings, the profiler and the Features page',
		{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
		async ( { page } ) => {
			// Nothing is installed: an Install click or a profiler Continue would ask for these.
			const installRequests: string[] = [];
			await page.route(
				( url ) =>
					PLUGIN_INSTALL_API.test(
						decodeURIComponent( url.toString() )
					),
				async ( route ) => {
					installRequests.push( route.request().url() );
					await route.abort();
				}
			);

			const wooPaymentsRow = page
				.locator( '.woocommerce-list__item' )
				.filter( { hasText: 'Accept payments with Woo' } );
			// The built-in badge by its test id (`native-badge.tsx`), not by its label, which domain g renames (N-344).
			const builtinBadge = wooPaymentsRow.getByTestId(
				'woocommerce-builtin-badge'
			);

			await test.step( 'With the feature on, the WooPayments row carries the built-in badge', async () => {
				await page.goto(
					'wp-admin/admin.php?page=wc-settings&tab=checkout'
				);
				await expect( builtinBadge ).toHaveCount( 1, {
					timeout: 30000,
				} );
			} );

			await wpCLI( [ 'wp', 'option', 'update', FEATURE_OPTION, 'no' ] );

			await test.step( 'Payments settings offers the extension to install', async () => {
				const providersResponse = page.waitForResponse(
					( response ) =>
						PROVIDERS_API.test( response.url() ) && response.ok()
				);
				await page.goto(
					'wp-admin/admin.php?page=wc-settings&tab=checkout'
				);
				const { providers } = ( await (
					await providersResponse
				).json() ) as {
					providers: Array< {
						id: string;
						_native?: boolean;
						plugin?: { slug?: string };
						onboarding?: { _links?: Record< string, unknown > };
					} >;
				};
				const wooPayments = providers.find(
					( provider ) => provider.id === '_wc_pes_woopayments'
				);
				expect( wooPayments?.plugin?.slug ).toBe(
					'woocommerce-payments'
				);
				expect( wooPayments?._native ).toBeUndefined();

				await expect(
					wooPaymentsRow.getByRole( 'button', { name: /^Install$/ } )
				).toBeVisible( {
					timeout: 30000,
				} );
				await expect( builtinBadge ).toHaveCount( 0 );
				await expect(
					wooPaymentsRow.getByRole( 'button', { name: 'Set up' } )
				).toHaveCount( 0 );
			} );

			await test.step( "The profiler's extensions step lists WooPayments", async () => {
				await page.goto(
					'wp-admin/admin.php?page=wc-admin&path=%2Fsetup-wizard'
				);
				await page
					.getByRole( 'checkbox', {
						name: 'I agree to share my data',
					} )
					.uncheck();
				await page
					.getByRole( 'button', { name: 'Set up my store' } )
					.click();
				await page
					.getByRole( 'radio' )
					.filter( { hasText: 'just starting my business' } )
					.click();
				await page.getByRole( 'button', { name: 'Continue' } ).click();
				await expect(
					page.getByRole( 'heading', {
						name: 'Tell us a bit about your store',
					} )
				).toBeVisible();
				// A store in a country WooPayments supports, as the core profiler spec picks one.
				await page.getByRole( 'combobox' ).last().click();
				await page.getByRole( 'combobox' ).last().fill( 'California' );
				await page
					.getByRole( 'option', {
						name: 'United States (US) — California',
					} )
					.click();
				await page.getByRole( 'button', { name: 'Continue' } ).click();
				await expect(
					page.getByRole( 'heading', {
						name: 'Get a boost with our free features',
					} )
				).toBeVisible();
				await expect(
					page.getByRole( 'heading', {
						name: 'Get paid with WooPayments',
					} )
				).toBeVisible();
			} );

			await test.step( 'The Features page shows the WooPayments row off and changeable', async () => {
				await page.goto(
					'wp-admin/admin.php?page=wc-settings&tab=advanced&section=features'
				);
				const off = page.locator(
					`input[name="${ FEATURE_OPTION }"][value="no"]`
				);
				const on = page.locator(
					`input[name="${ FEATURE_OPTION }"][value="yes"]`
				);
				await expect( off ).toBeChecked();
				await expect( off ).toBeEnabled();
				await expect( on ).toBeEnabled();
				await expect(
					page.getByText(
						'WooPayments is taking payments on this store.'
					)
				).toHaveCount( 0 );
			} );

			expect( installRequests ).toEqual( [] );
		}
	);
} );
