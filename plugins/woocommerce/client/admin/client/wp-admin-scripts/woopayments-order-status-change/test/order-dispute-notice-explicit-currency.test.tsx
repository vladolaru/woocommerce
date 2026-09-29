/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, waitFor } from '@testing-library/react';

// Client 11.1.0 `components/disputed-order-notice/index.js:147,183`: the notice amounts go through
// `formatExplicitCurrency()`, with the flag the order screen localizes (`should_use_explicit_price`).

let mockReadyCallback: () => void = () => {};
const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );
jest.mock( '@wordpress/dom-ready', () => ( callback: () => void ) => {
	mockReadyCallback = callback;
} );

import '../index';
import { WooPaymentsOrderDisputeNotice } from '../order-dispute-notice';

const oneDispute = {
	disputes: [
		{
			id: 'dp_1',
			status: 'needs_response',
			reason: 'fraudulent',
			amount: 1550,
			currency: 'usd',
			evidence_details: { due_by: 1698672000 },
		},
	],
};

const twoDisputes = {
	disputes: [
		...oneDispute.disputes,
		{
			id: 'dp_2',
			status: 'needs_response',
			amount: 1000,
			currency: 'usd',
			evidence_details: { due_by: 1698672000 },
		},
	],
};

describe( 'WooPayments order dispute notice explicit currency', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2023-10-20T00:00:00Z' ) );
		mockApiFetch.mockReset();
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it.each( [
		[ true, '$15.50 USD' ],
		[ false, '$15.50' ],
	] )(
		'with the flag %s shows the single dispute amount as %s',
		async ( flag, amount ) => {
			mockApiFetch.mockResolvedValue( oneDispute );

			render(
				<WooPaymentsOrderDisputeNotice
					chargeId="ch_123"
					onDisableOrderRefund={ jest.fn() }
					shouldUseExplicitPrice={ flag }
				/>
			);

			expect(
				await screen.findByText(
					`This order has a payment dispute for ${ amount } for the reason 'Transaction unauthorized'.`
				)
			).toBeInTheDocument();
		}
	);

	it.each( [
		[ true, '$25.50 USD' ],
		[ false, '$25.50' ],
	] )(
		'with the flag %s shows the combined amount as %s',
		async ( flag, amount ) => {
			mockApiFetch.mockResolvedValue( twoDisputes );

			render(
				<WooPaymentsOrderDisputeNotice
					chargeId="ch_123"
					onDisableOrderRefund={ jest.fn() }
					shouldUseExplicitPrice={ flag }
				/>
			);

			expect(
				await screen.findByText(
					`This order has 2 payment disputes totaling ${ amount }.`
				)
			).toBeInTheDocument();
		}
	);

	it( 'passes the localized should_use_explicit_price flag from the order screen to the notice', async () => {
		(
			global as { IS_REACT_ACT_ENVIRONMENT?: boolean }
		 ).IS_REACT_ACT_ENVIRONMENT = true;
		const orderScreen = document.createElement( 'div' );
		orderScreen.innerHTML =
			'<p class="form-field"><select id="order_status"><option value="wc-processing">Processing</option></select></p>';
		document.body.appendChild( orderScreen );
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: true,
			refund_amount: 15.5,
			formatted_refund_amount: '$15.50',
			refunded_amount: 0,
			charge_id: 'ch_123',
			has_open_authorization: false,
			should_use_explicit_price: true,
		};
		mockApiFetch.mockResolvedValue( twoDisputes );

		act( () => {
			mockReadyCallback();
		} );

		await waitFor( () =>
			expect( orderScreen ).toHaveTextContent(
				'This order has 2 payment disputes totaling $25.50 USD.'
			)
		);

		orderScreen.remove();
		delete window.woocommerceWooPaymentsOrderStatusChange;
		(
			global as { IS_REACT_ACT_ENVIRONMENT?: boolean }
		 ).IS_REACT_ACT_ENVIRONMENT = false;
	} );
} );
