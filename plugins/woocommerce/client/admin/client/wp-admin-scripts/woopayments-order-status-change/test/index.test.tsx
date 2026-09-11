/**
 * External dependencies
 */
import { act, within } from '@testing-library/react';

// Holding on to the ready callback lets every test boot the entry against its
// own DOM and config without re-requiring the module — a fresh require would
// give the entry its own React copy, which Testing Library's `act` cannot flush.
let mockReadyCallback: () => void = () => {};

jest.mock( '@wordpress/dom-ready', () => ( callback: () => void ) => {
	mockReadyCallback = callback;
} );

jest.mock( '../refund-confirmation-modal', () => ( {
	RefundConfirmationModal: ( {
		formattedRefundAmount,
	}: {
		formattedRefundAmount: string;
	} ) => <div>Refund confirmation for { formattedRefundAmount }</div>,
} ) );

jest.mock( '../cancel-confirmation-modal', () => ( {
	CancelConfirmationModal: ( {
		previousStatus,
	}: {
		previousStatus: string;
	} ) => <div>Cancel confirmation from { previousStatus }</div>,
} ) );

import '../index';

const bootEntry = () => {
	act( () => {
		mockReadyCallback();
	} );
};

const selectStatus = ( value: string ) => {
	const field = document.getElementById(
		'order_status'
	) as HTMLSelectElement;

	act( () => {
		field.value = value;
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
};

let orderScreen: HTMLDivElement | null = null;

// Modal stand-ins and the error notice all render inside the order screen, so
// scoping queries there keeps them clear of @wordpress/a11y's live regions,
// which repeat announced text at the end of `document.body`.
const orderScreenQueries = () => within( orderScreen as HTMLElement );

describe( 'woopayments-order-status-change entrypoint', () => {
	// The entry owns its own React root, so nothing here goes through Testing
	// Library's `render()` — which is what normally opts a file into act.
	beforeAll( () => {
		(
			global as { IS_REACT_ACT_ENVIRONMENT?: boolean }
		 ).IS_REACT_ACT_ENVIRONMENT = true;
	} );

	afterAll( () => {
		(
			global as { IS_REACT_ACT_ENVIRONMENT?: boolean }
		 ).IS_REACT_ACT_ENVIRONMENT = false;
	} );

	// Rebuilt per test rather than wiping `document.body`, which would take
	// `@wordpress/a11y`'s live regions with it and silence every announcement.
	beforeEach( () => {
		jest.clearAllMocks();
		orderScreen?.remove();
		orderScreen = document.createElement( 'div' );
		orderScreen.innerHTML = `
			<form>
				<p class="form-field">
					<select id="order_status">
						<option value="wc-processing">Processing</option>
						<option value="wc-cancelled">Cancelled</option>
						<option value="wc-refunded">Refunded</option>
					</select>
				</p>
			</form>
		`;
		document.body.appendChild( orderScreen );
		delete window.woocommerceWooPaymentsOrderStatusChange;
	} );

	it( 'stays out of the way when the order is not a WooPayments order', () => {
		bootEntry();
		selectStatus( 'wc-refunded' );

		expect(
			orderScreenQueries().queryByText( /confirmation/ )
		).not.toBeInTheDocument();
		expect(
			document.querySelector(
				'.woocommerce-woopayments-order-status-change'
			)
		).not.toBeInTheDocument();
	} );

	describe( 'on a refundable WooPayments order', () => {
		beforeEach( () => {
			window.woocommerceWooPaymentsOrderStatusChange = {
				order_status: 'wc-processing',
				can_refund: true,
				refund_amount: 42.5,
				formatted_refund_amount: '$42.50',
				refunded_amount: 0,
			};
		} );

		it( 'confirms before refunding', () => {
			bootEntry();
			selectStatus( 'wc-refunded' );

			expect(
				orderScreenQueries().getByText(
					'Refund confirmation for $42.50'
				)
			).toBeInTheDocument();
		} );

		it( 'confirms before cancelling', () => {
			bootEntry();
			selectStatus( 'wc-cancelled' );

			expect(
				orderScreenQueries().getByText(
					'Cancel confirmation from wc-processing'
				)
			).toBeInTheDocument();
		} );

		it( 'asks nothing for an unrelated status', () => {
			bootEntry();
			selectStatus( 'wc-processing' );

			expect(
				orderScreenQueries().queryByText( /confirmation/ )
			).not.toBeInTheDocument();
		} );

		it( 'clears an open confirmation once the selection goes back', () => {
			bootEntry();
			selectStatus( 'wc-refunded' );
			selectStatus( 'wc-processing' );

			expect(
				orderScreenQueries().queryByText( /confirmation/ )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'shows and announces why a refund is refused', () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: false,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
		};

		bootEntry();
		selectStatus( 'wc-refunded' );

		expect(
			orderScreenQueries().getByText( 'Order cannot be refunded' )
		).toBeInTheDocument();
		// `Notice` announces error content assertively through @wordpress/a11y,
		// so the refusal reaches screen readers as well as the screen.
		expect(
			document.getElementById( 'a11y-speak-assertive' )?.textContent
		).toContain( 'Order cannot be refunded' );
	} );
} );
