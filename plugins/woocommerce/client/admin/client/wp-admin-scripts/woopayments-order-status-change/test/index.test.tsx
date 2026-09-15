/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { act, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// Holding on to the ready callback lets every test boot the entry against its
// own DOM and config without re-requiring the module — a fresh require would
// give the entry its own React copy, which Testing Library's `act` cannot flush.
let mockReadyCallback: () => void = () => {};
const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

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
	const field = document.getElementById( 'order_status' );
	if ( ! ( field instanceof window.HTMLSelectElement ) ) {
		throw new Error( 'Expected the order status field.' );
	}

	act( () => {
		field.value = value;
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
};

let orderScreen: ReturnType< typeof document.createElement > | null = null;

// Modal stand-ins and the error notice all render inside the order screen, so
// scoping queries there keeps them clear of @wordpress/a11y's live regions,
// which repeat announced text at the end of `document.body`.
const orderScreenQueries = () => {
	if ( ! orderScreen ) {
		throw new Error( 'Expected the order screen fixture.' );
	}

	return within( orderScreen );
};

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
		mockApiFetch.mockResolvedValue( {} );
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
				<div id="woocommerce-order-items">
					<span>
						<button type="button" class="refund-items">Refund</button>
						<span class="woocommerce-help-tip"></span>
					</span>
				</div>
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

	it( 'opens the inline refund panel when the status field is absent', () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: true,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
			charge_id: '',
		};
		const refundButton = orderScreenQueries().getByRole( 'button', {
			name: 'Refund',
		} );
		const click = jest.fn();
		refundButton.addEventListener( 'click', click );
		document.getElementById( 'order_status' )?.remove();
		const link = document.createElement( 'a' );
		link.href = 'https://example.test/transaction';
		link.className = 'wcpay-efw-refund-link';
		orderScreen?.appendChild( link );

		bootEntry();
		link.dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( click ).toHaveBeenCalledTimes( 1 );
	} );

	describe( 'on a refundable WooPayments order', () => {
		beforeEach( () => {
			window.woocommerceWooPaymentsOrderStatusChange = {
				order_status: 'wc-processing',
				can_refund: true,
				refund_amount: 42.5,
				formatted_refund_amount: '$42.50',
				refunded_amount: 0,
				charge_id: '',
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

		it( 'does not request a charge when PHP projected no active-mode charge ID', () => {
			bootEntry();

			expect( mockApiFetch ).not.toHaveBeenCalled();
		} );
	} );

	it( 'shows and announces why a refund is refused', () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: false,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
			charge_id: '',
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

	it( 'locks order refunds when any fetched dispute blocks them', async () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: true,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
			charge_id: 'ch_disputed',
		};
		mockApiFetch.mockResolvedValue( {
			disputes: [
				{ id: 'dp_won', status: 'won' },
				{ id: 'dp_lost', status: 'lost' },
			],
		} );

		bootEntry();

		const refundButton = orderScreenQueries().getByRole( 'button', {
			name: 'Refund',
		} );
		await waitFor( () => expect( refundButton ).toBeDisabled() );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/charges/ch_disputed',
			method: 'GET',
		} );
		expect( refundButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			orderScreen?.querySelector( '.woocommerce-help-tip' )
		).toHaveAccessibleName(
			'Refunds and order editing have been disabled as a result of a lost dispute.'
		);

		selectStatus( 'wc-refunded' );
		expect(
			orderScreenQueries().getByText( 'Order cannot be refunded' )
		).toBeInTheDocument();
	} );

	it( 'uses a truthful accessible lock reason for an unknown dispute status', async () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: true,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
			charge_id: 'ch_unknown_dispute',
		};
		mockApiFetch.mockResolvedValue( {
			dispute: { id: 'dp_unknown', status: 'future_status' },
		} );

		bootEntry();

		const refundButton = orderScreenQueries().getByRole( 'button', {
			name: 'Refund',
		} );
		await waitFor( () => expect( refundButton ).toBeDisabled() );
		expect(
			orderScreen?.querySelector( '.woocommerce-help-tip' )
		).toHaveAccessibleName(
			'Refunds and order editing are disabled while this payment has a dispute.'
		);
	} );

	it( 'opens the inline refund panel from an actionable early fraud warning link', async () => {
		window.woocommerceWooPaymentsOrderStatusChange = {
			order_status: 'wc-processing',
			can_refund: true,
			refund_amount: 42.5,
			formatted_refund_amount: '$42.50',
			refunded_amount: 0,
			charge_id: '',
		};
		const refundButton = orderScreenQueries().getByRole( 'button', {
			name: 'Refund',
		} );
		const click = jest.fn();
		const scrollIntoView = jest.fn();
		refundButton.addEventListener( 'click', click );
		const refundPanel = orderScreen?.querySelector(
			'#woocommerce-order-items'
		);
		if ( refundPanel instanceof window.HTMLElement ) {
			refundPanel.scrollIntoView = scrollIntoView;
		}
		const link = document.createElement( 'a' );
		link.href = 'https://example.test/transaction';
		link.className = 'wcpay-efw-refund-link';
		link.textContent = 'Refund this payment';
		orderScreen?.appendChild( link );

		bootEntry();
		await userEvent.click(
			orderScreenQueries().getByRole( 'link', {
				name: 'Refund this payment',
			} )
		);

		expect( click ).toHaveBeenCalledTimes( 1 );
		expect( scrollIntoView ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [ 'missing', 'disabled' ] )(
		'preserves early fraud warning link navigation when the refund button is %s',
		( refundButtonState ) => {
			window.woocommerceWooPaymentsOrderStatusChange = {
				order_status: 'wc-processing',
				can_refund: true,
				refund_amount: 42.5,
				formatted_refund_amount: '$42.50',
				refunded_amount: 0,
				charge_id: '',
			};
			const refundButton = orderScreenQueries().getByRole( 'button', {
				name: 'Refund',
			} );
			if ( refundButtonState === 'missing' ) {
				refundButton.remove();
			} else {
				refundButton.disabled = true;
			}
			const link = document.createElement( 'a' );
			link.href = 'https://example.test/transaction';
			link.className = 'wcpay-efw-refund-link';
			link.textContent = 'Refund this payment';
			orderScreen?.appendChild( link );

			bootEntry();
			const click = new window.MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
			} );
			link.dispatchEvent( click );

			expect( click.defaultPrevented ).toBe( false );
		}
	);
} );
