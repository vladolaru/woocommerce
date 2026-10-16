/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import {
	getWooPaymentsAuthorization,
	getWooPaymentsPaymentIntent,
	getWooPaymentsTimeline,
	refundWooPaymentsCharge,
} from '../money-movement/data';
import type {
	WooPaymentsCharge,
	WooPaymentsPaymentIntent,
} from '../money-movement/types';
import { mockAccountMode } from './helpers/test-mode-account';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) => {
			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
					createErrorNotice: mockCreateErrorNotice,
				};
			}

			return actual.dispatch( storeName );
		} ),
	};
} );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsAuthorization: jest.fn(),
	getWooPaymentsCharge: jest.fn(),
	getWooPaymentsPaymentIntent: jest.fn(),
	getWooPaymentsTimeline: jest.fn(),
	getWooPaymentsTransaction: jest.fn(),
	captureWooPaymentsAuthorization: jest.fn(),
	cancelWooPaymentsAuthorization: jest.fn(),
	refundWooPaymentsCharge: jest.fn(),
} ) );

const mockGetAuthorization = getWooPaymentsAuthorization as jest.MockedFunction<
	typeof getWooPaymentsAuthorization
>;
const mockGetPaymentIntent = getWooPaymentsPaymentIntent as jest.MockedFunction<
	typeof getWooPaymentsPaymentIntent
>;
const mockGetTimeline = getWooPaymentsTimeline as jest.MockedFunction<
	typeof getWooPaymentsTimeline
>;
const mockRefundCharge = refundWooPaymentsCharge as jest.MockedFunction<
	typeof refundWooPaymentsCharge
>;
const mockRecordEvent = recordEvent as jest.MockedFunction<
	typeof recordEvent
>;

// Client 11.1.0 payment-details/summary/__tests__/index.test.js `getBaseCharge()` with `charge.order = null`
// (its "order missing notice" case, :1366-1383).
const getOrderlessCharge = (): WooPaymentsCharge => ( {
	id: 'ch_38jdHA39KKA',
	payment_intent: 'pi_abc',
	created: 1568913840,
	amount: 2000,
	amount_refunded: 0,
	refunded: false,
	application_fee_amount: 70,
	currency: 'usd',
	type: 'charge',
	status: 'succeeded',
	captured: true,
	balance_transaction: {
		amount: 2000,
		currency: 'usd',
		fee: 70,
		net: 1930,
	},
	order: null,
	billing_details: {
		name: 'Customer name',
		email: 'mock@example.com',
	},
	payment_method_details: {
		card: {
			brand: 'visa',
			last4: '4242',
		},
		type: 'card',
	},
} );

const renderPaymentDetails = ( charge: WooPaymentsCharge ) => {
	mockGetPaymentIntent.mockResolvedValue( {
		id: 'pi_abc',
		status: charge.captured ? 'succeeded' : 'requires_capture',
		amount: charge.amount,
		currency: charge.currency,
		created: charge.created,
		charge,
	} );

	render(
		<MemoryRouter
			initialEntries={ [ '/woopayments/transactions/details?id=pi_abc' ] }
		>
			<WooPaymentsTransactionDetailsPage />
		</MemoryRouter>
	);
};

// The screen-reader status line repeats the lead sentence, so match the visible notice paragraph only.
const findMissingOrderNotice = async () =>
	(
		await screen.findByText(
			/^This transaction is not connected to order\./,
			{
				selector: 'section p',
			}
		)
	).closest( 'section' ) as typeof document.body;

const openActionsMenu = async () => {
	const actions = await screen.findByRole( 'button', {
		name: 'Transaction actions',
	} );
	await userEvent.click( actions );
};

const confirmRefundWithReason = async ( reason: string ) => {
	await userEvent.click( await screen.findByLabelText( reason ) );
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Refund transaction' } )
	);
};

// Recorded native :8889 payment intent for a 4242 test-card payment whose order was then permanently
// deleted (sanitized; see the file's `_meta`). The platform sends the missing order as `order: []`.
const RECORDED_ORDERLESS_INTENT = JSON.parse(
	fs.readFileSync(
		path.join(
			__dirname,
			'fixtures/recorded-orderless-payment-intent.json'
		),
		'utf8'
	)
).response as WooPaymentsPaymentIntent;

describe( 'WooPayments refund of a payment without an order', () => {
	beforeEach( () => {
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
			countries: { US: 'United States' },
		} as unknown as typeof window.wcSettings;
		mockAccountMode( false );
		mockGetAuthorization.mockReset();
		mockGetPaymentIntent.mockReset();
		mockGetTimeline.mockReset();
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockRefundCharge.mockReset();
		mockRefundCharge.mockResolvedValue( { id: 're_orderless' } );
		mockRecordEvent.mockClear();
		mockCreateSuccessNotice.mockClear();
		mockCreateErrorNotice.mockClear();
	} );

	describe( 'actions menu (client summary/index.tsx:370-384,781-848)', () => {
		it( 'offers a full refund but no partial refund when the charge has no order', async () => {
			renderPaymentDetails( getOrderlessCharge() );

			await openActionsMenu();

			expect(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'menuitem', { name: 'Partial refund' } )
			).not.toBeInTheDocument();
		} );

		it( 'offers a partial refund only when the order has a number', async () => {
			renderPaymentDetails( {
				...getOrderlessCharge(),
				order: {
					id: 123,
					url: 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123',
				},
			} );

			await openActionsMenu();

			expect(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'menuitem', { name: 'Partial refund' } )
			).not.toBeInTheDocument();
		} );

		it( 'hides the menu for an uncaptured charge without an order', async () => {
			renderPaymentDetails( {
				...getOrderlessCharge(),
				captured: false,
			} );

			await findMissingOrderNotice();

			expect(
				screen.queryByRole( 'button', { name: 'Transaction actions' } )
			).not.toBeInTheDocument();
		} );

		it( 'refunds the charge in full without an order id', async () => {
			renderPaymentDetails( getOrderlessCharge() );

			await openActionsMenu();
			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
			await confirmRefundWithReason( 'Duplicate order' );

			// Client data/payment-intents/actions.ts:52-62: `order_id: charge?.order?.id` is undefined here.
			await waitFor( () =>
				expect( mockRefundCharge ).toHaveBeenCalledWith( {
					chargeId: 'ch_38jdHA39KKA',
					amount: 2000,
					reason: 'duplicate',
					orderId: undefined,
				} )
			);
			await waitFor( () =>
				expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
					'Refunded payment #pi_abc.'
				)
			);
		} );
	} );

	describe( 'missing-order notice (client missing-order-notice/index.tsx:25-66)', () => {
		it( 'shows the client copy and a Refund button that opens the refund modal without an open event', async () => {
			renderPaymentDetails( getOrderlessCharge() );

			const notice = await findMissingOrderNotice();
			expect( notice ).toHaveTextContent(
				'This transaction is not connected to order. Investigate this purchase and refund the transaction as needed.'
			);

			await userEvent.click(
				within( notice ).getByRole( 'button', { name: 'Refund' } )
			);

			const dialog = await screen.findByRole( 'dialog', {
				name: 'Refund transaction',
			} );
			expect( dialog ).toHaveTextContent(
				'This will issue a full refund of $20.00 to the customer.'
			);
			expect(
				within( dialog ).queryByRole( 'link', {
					name: 'Go to the order',
				} )
			).not.toBeInTheDocument();
			expect( mockRecordEvent ).not.toHaveBeenCalledWith(
				'payments_transactions_details_refund_modal_open',
				expect.anything()
			);

			// Client refund-modal/index.tsx:86: "Other" is sent as a null reason.
			await confirmRefundWithReason( 'Other' );

			await waitFor( () =>
				expect( mockRefundCharge ).toHaveBeenCalledWith( {
					chargeId: 'ch_38jdHA39KKA',
					amount: 2000,
					reason: null,
					orderId: undefined,
				} )
			);
			expect( mockRecordEvent ).toHaveBeenCalledWith(
				'payments_transactions_details_refund_full',
				{ payment_intent_id: 'pi_abc' }
			);
		} );

		it( 'keeps the Refund button for an uncaptured charge, as the client does', async () => {
			renderPaymentDetails( {
				...getOrderlessCharge(),
				captured: false,
			} );

			const notice = await findMissingOrderNotice();

			expect(
				within( notice ).getByRole( 'button', { name: 'Refund' } )
			).toBeInTheDocument();
		} );

		it( 'says a refunded charge cannot be disputed and drops the Refund button', async () => {
			renderPaymentDetails( {
				...getOrderlessCharge(),
				amount_refunded: 2000,
				refunded: true,
			} );

			const notice = await findMissingOrderNotice();

			expect( notice ).toHaveTextContent(
				'This transaction is not connected to order. It has been refunded and is not a subject for disputes.'
			);
			expect(
				within( notice ).queryByRole( 'button' )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'missing-order condition (client summary/index.tsx:897-903)', () => {
		it( "hides the notice for the platform's `order: []` and keeps the menu refund", async () => {
			mockGetPaymentIntent.mockResolvedValue( RECORDED_ORDERLESS_INTENT );
			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/transactions/details?id=pi_3UL6CXBzWlxcwgpP1pSl8arF',
					] }
				>
					<WooPaymentsTransactionDetailsPage />
				</MemoryRouter>
			);

			await openActionsMenu();

			// `! charge.order` is false for `[]`, so the client renders no notice.
			expect(
				screen.queryByText(
					/This transaction is not connected to order/
				)
			).not.toBeInTheDocument();
			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				/^Transaction details loaded\.$/
			);
			expect(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'menuitem', { name: 'Partial refund' } )
			).not.toBeInTheDocument();

			await userEvent.click(
				screen.getByRole( 'menuitem', { name: 'Refund in full' } )
			);
			await confirmRefundWithReason( 'Requested by customer' );

			await waitFor( () =>
				expect( mockRefundCharge ).toHaveBeenCalledWith( {
					chargeId: 'ch_3UL6CXBzWlxcwgpP1JZFm6v3',
					amount: 1234,
					reason: 'requested_by_customer',
					orderId: undefined,
				} )
			);
		} );

		it.each( [
			[ 'null', { order: null } ],
			[ 'absent', { order: undefined } ],
		] )(
			'shows the notice with its Refund button when the order is %s',
			async ( _label, override ) => {
				renderPaymentDetails( {
					...getOrderlessCharge(),
					...override,
				} as WooPaymentsCharge );

				const notice = await findMissingOrderNotice();

				expect(
					within( notice ).getByRole( 'button', { name: 'Refund' } )
				).toBeInTheDocument();
				expect( screen.getByRole( 'status' ) ).toHaveTextContent(
					'Transaction details loaded. This transaction is not connected to order.'
				);
			}
		);
	} );
} );
