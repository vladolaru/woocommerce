/**
 * External dependencies
 */
import { applyFilters, removeFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { registerPaymentsRuntimeTracksProperty } from '../tracks-runtime';

// Core's admin recorder applies this filter to every event with the `wcadmin_` name
// (`includes/tracks/class-wc-site-tracking.php`).
const filterEvent = (
	eventName: string,
	properties: Record< string, unknown >
) =>
	applyFilters(
		'woocommerce_tracks_client_event_properties',
		properties,
		eventName
	);

describe( 'payments_runtime on admin Tracks events', () => {
	it( 'marks native WooPayments admin events as recorded by WooCommerce core', () => {
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

		expect(
			removeFilter(
				'woocommerce_tracks_client_event_properties',
				'woocommerce/woopayments/payments-runtime'
			)
		).toBe( 1 );

		registerPaymentsRuntimeTracksProperty();
		expect( filterEvent( 'wcadmin_wcpay_csv_export_click', {} ) ).toEqual( {
			payments_runtime: 'woocommerce_core',
		} );
	} );
} );
