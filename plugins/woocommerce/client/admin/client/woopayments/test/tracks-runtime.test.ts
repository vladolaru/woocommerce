/**
 * External dependencies
 */
import { applyFilters, removeFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { registerPaymentsRuntimeTracksProperty } from '../tracks-runtime';

// One hooks registry for the test and every fresh module copy, as `wp.hooks` is one per page.
jest.mock( '@wordpress/hooks', () => {
	const scope = globalThis as typeof globalThis & {
		mockSharedHooks?: unknown;
	};
	scope.mockSharedHooks ??= jest.requireActual( '@wordpress/hooks' );

	return scope.mockSharedHooks;
} );

const EVENT_PROPERTIES_FILTER = 'woocommerce_tracks_client_event_properties';
const FILTER_NAMESPACE = 'woocommerce/woopayments/payments-runtime';

// Core's admin recorder applies this filter to every event with the `wcadmin_` name
// (`includes/tracks/class-wc-site-tracking.php`).
const filterEvent = (
	eventName: string,
	properties: Record< string, unknown >
) => applyFilters( EVENT_PROPERTIES_FILTER, properties, eventName );

const settingsWindow = window as typeof window & {
	wcSettings?: Record< string, unknown >;
};
const initialWcSettings = settingsWindow.wcSettings;

describe( 'payments_runtime on admin Tracks events', () => {
	beforeEach( () => {
		removeFilter( EVENT_PROPERTIES_FILTER, FILTER_NAMESPACE );
	} );

	afterEach( () => {
		settingsWindow.wcSettings = initialWcSettings;
	} );

	it( 'marks native WooPayments admin events as recorded by WooCommerce core', () => {
		registerPaymentsRuntimeTracksProperty();

		expect(
			filterEvent( 'wcadmin_wcpay_deposits_row_click', {
				wc_version: '10.9.0',
			} )
		).toEqual( {
			wc_version: '10.9.0',
			payments_runtime: 'woocommerce_core',
		} );
		expect(
			filterEvent( 'wcadmin_payments_transactions_details_refund_full', {
				payment_intent_id: 'pi_test',
			} )
		).toEqual( {
			payment_intent_id: 'pi_test',
			payments_runtime: 'woocommerce_core',
		} );
		expect(
			filterEvent( 'wcadmin_page_view', { path: 'payments_disputes' } )
		).toEqual( {
			path: 'payments_disputes',
			payments_runtime: 'woocommerce_core',
		} );
	} );

	it( "leaves trunk's own payments events and other page views unchanged", () => {
		registerPaymentsRuntimeTracksProperty();

		expect(
			filterEvent( 'wcadmin_payments_task_stepper_view', {
				payment_method: 'woocommerce_payments',
			} )
		).toEqual( { payment_method: 'woocommerce_payments' } );
		expect(
			filterEvent( 'wcadmin_page_view', { path: 'home_screen' } )
		).toEqual( { path: 'home_screen' } );
		expect(
			filterEvent( 'wcadmin_settings_payments_pageview', {} )
		).toEqual( {} );
	} );

	it( 'adds the filter once however many native entries register it', () => {
		registerPaymentsRuntimeTracksProperty();
		registerPaymentsRuntimeTracksProperty();
		registerPaymentsRuntimeTracksProperty();

		expect(
			removeFilter( EVENT_PROPERTIES_FILTER, FILTER_NAMESPACE )
		).toBe( 1 );
	} );

	describe( 'when the native routes load', () => {
		const loadRoutesModule = () =>
			jest.isolateModulesAsync( async () => {
				await import( '../admin/routes' );
			} );

		// A stale native link on a store the WooPayments plugin owns loads the routes, but core preloads no settings.
		it( "leaves the plugin's events unchanged on a store the plugin owns", async () => {
			settingsWindow.wcSettings = { admin: {} };

			await loadRoutesModule();

			expect(
				filterEvent( 'wcadmin_wcpay_deposits_row_click', {} )
			).toEqual( {} );
		} );

		it( 'marks the same event on a store native owns, where core preloads the WooPayments settings', async () => {
			settingsWindow.wcSettings = { admin: { woopaymentsSettings: {} } };

			await loadRoutesModule();

			expect(
				filterEvent( 'wcadmin_wcpay_deposits_row_click', {} )
			).toEqual( { payments_runtime: 'woocommerce_core' } );
		} );
	} );
} );
