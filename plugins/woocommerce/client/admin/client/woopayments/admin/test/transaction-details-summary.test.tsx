/**
 * External dependencies
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { WooPaymentsPaymentSummarySection } from '../money-movement/transaction-detail-sections';
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import { WooPaymentsDisputeDetailsRedirect } from '../money-movement/dispute-details';
import {
	getWooPaymentsAuthorization,
	getWooPaymentsCharge,
	getWooPaymentsDispute,
	getWooPaymentsPaymentIntent,
	getWooPaymentsTimeline,
	getWooPaymentsTransaction,
} from '../money-movement/data';
import { SettingsShellHistoryBridge } from './helpers/settings-shell-history';
import type {
	WooPaymentsCharge,
	WooPaymentsTransaction,
} from '../money-movement/types';
import { mockAccountMode } from './helpers/test-mode-account';

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () =>
		jest.requireActual( './helpers/settings-shell-history' ).shellHistory,
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

// The snackbar store wp-admin registers; a failed read raises its error notice.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( storeName: string ) =>
			storeName === 'core/notices'
				? {
						createErrorNotice: jest.fn(),
						createSuccessNotice: jest.fn(),
				  }
				: actual.dispatch( storeName ),
	};
} );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsAuthorization: jest.fn(),
	getWooPaymentsDispute: jest.fn(),
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

// Client 11.1.0 payment-details/summary/__tests__/index.test.js: the fixed "now" of its summary tests
// and the site date/time formats of its `wcpaySettings`.
const NOW = '2023-09-08T12:33:37.000Z';
const CAPTURE_DOC_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/authorize-and-capture/#capturing-authorized-payments';

// Client 11.1.0 payment-details/summary/__tests__/index.test.js `getBaseCharge()`, the fields the
// native summary reads.
const getBaseCharge = (): WooPaymentsCharge => ( {
	id: 'ch_38jdHA39KKA',
	payment_intent: 'pi_abc',
	created: 1568913840,
	amount: 2000,
	amount_refunded: 0,
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
	order: {
		id: 45981,
		number: '45981',
		url: 'https://somerandomorderurl.com/?edit_order=45981',
	},
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

const getSummary = () => screen.getByRole( 'region', { name: 'Summary' } );

const renderDetailsPage = () =>
	render(
		<MemoryRouter
			initialEntries={ [ '/woopayments/transactions/details?id=pi_abc' ] }
		>
			<WooPaymentsTransactionDetailsPage />
		</MemoryRouter>
	);

const mockAuthorizedPayment = ( {
	authorizationCreated,
	fraudReview = false,
}: {
	authorizationCreated: string;
	fraudReview?: boolean;
} ) => {
	const charge = getBaseCharge();
	mockGetPaymentIntent.mockResolvedValue( {
		id: 'pi_abc',
		status: 'requires_capture',
		amount: 2000,
		currency: 'usd',
		created: 1568913840,
		charge: {
			...charge,
			status: 'succeeded',
			captured: false,
			order: {
				id: 123,
				number: '123',
				...( fraudReview ? { fraud_meta_box_type: 'review' } : {} ),
			},
		},
	} );
	mockGetAuthorization.mockResolvedValue( {
		payment_intent_id: 'pi_abc',
		order_id: 123,
		captured: false,
		created: authorizationCreated,
	} );
};

const findCaptureNotice = async () => {
	const lead = await screen.findByText( ( _content, element ) =>
		element?.tagName === 'P' &&
		( element.textContent || '' ).startsWith( 'You must capture' )
			? true
			: false
	);

	// Client 11.1.0 `components/card-notice`: the notice is a footer of the summary card.
	return lead.closest(
		'.woocommerce-woopayments-payment-summary__notice'
	) as typeof lead;
};

describe( 'WooPayments payment details summary parity', () => {
	const originalDateSettings = getSettings();

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
		setSettings( {
			...originalDateSettings,
			formats: {
				...originalDateSettings.formats,
				date: 'M j, Y',
				time: 'g:ia',
			},
			timezone: {
				...originalDateSettings.timezone,
				offset: '0',
				offsetFormatted: '0',
				string: 'UTC',
				abbr: 'UTC',
			},
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		setSettings( originalDateSettings );
	} );

	// Client 11.1.0 payment-details/summary/index.tsx:480-866 and timeline/index.js:33-58: the summary and
	// timeline cards with placeholders while loading, under the "Payment details" title.
	it( 'shows the summary and timeline placeholders while the payment loads', async () => {
		mockGetPaymentIntent.mockReturnValue( new Promise( () => {} ) );

		const { container } = renderDetailsPage();

		expect(
			await screen.findByRole( 'heading', { name: 'Payment details' } )
		).toBeInTheDocument();
		const placeholders = container.querySelectorAll(
			'.woocommerce-woopayments-payment-details-placeholder'
		);
		expect( placeholders ).toHaveLength( 2 );
		placeholders.forEach( ( placeholder ) =>
			expect( placeholder ).toHaveAttribute( 'aria-hidden', 'true' )
		);
		expect(
			screen.queryByText( 'Loading transaction details…', {
				selector: '.woocommerce-woopayments-money-movement__status',
			} )
		).not.toBeInTheDocument();
	} );

	describe( 'capture countdown (client summary/index.tsx:904-967)', () => {
		beforeEach( () => {
			jest.useFakeTimers( { now: new Date( NOW ) } );
		} );

		it( 'shows the time left to capture, the deadline and the capture docs link', async () => {
			// Client snapshot "renders capture section correctly": created now, `<abbr title="Sep 15, 2023 / 12:33pm"><b>7 days</b>`.
			mockAuthorizedPayment( { authorizationCreated: NOW } );
			renderDetailsPage();

			const notice = await findCaptureNotice();
			const timeLeft = within( notice ).getByText( '7 days' );
			expect( timeLeft.tagName ).toBe( 'B' );
			expect( timeLeft.closest( 'abbr' ) ).toHaveAttribute(
				'title',
				'Sep 15, 2023 / 12:33pm'
			);
			expect(
				within( notice ).getByRole( 'link', { name: /capture/ } )
			).toHaveAttribute( 'href', CAPTURE_DOC_URL );
			expect( timeLeft.closest( 'p' ) ).toHaveTextContent(
				/this charge within the next 7 days$/
			);
			expect(
				within( notice ).getByRole( 'button', {
					name: 'Capture authorization for order #123',
				} )
			).toBeInTheDocument();
		} );

		// moment `fromNow( true )` with the client's relative-time strings (summary/index.tsx:433-445).
		it.each( [
			[ '2 days', '2023-09-03T12:33:37Z', 'Sep 10, 2023 / 12:33pm' ],
			[ 'a day', '2023-09-02T12:33:37Z', 'Sep 9, 2023 / 12:33pm' ],
			[ '12 hours', '2023-09-02T00:33:37Z', 'Sep 9, 2023 / 12:33am' ],
			[ 'an hour', '2023-09-01T13:23:37Z', 'Sep 8, 2023 / 1:23pm' ],
			[ '30 minutes', '2023-09-01T13:03:37Z', 'Sep 8, 2023 / 1:03pm' ],
			[ 'a minute', '2023-09-01T12:34:37Z', 'Sep 8, 2023 / 12:34pm' ],
			[ 'a second', '2023-09-01T12:33:47Z', 'Sep 8, 2023 / 12:33pm' ],
			// Past the deadline, the client still reads the absolute distance.
			[ '2 days', '2023-08-30T12:33:37Z', 'Sep 6, 2023 / 12:33pm' ],
		] )(
			'reads "%s" when the authorization was created at %s',
			async ( expected, created, deadline ) => {
				mockAuthorizedPayment( { authorizationCreated: created } );
				renderDetailsPage();

				const notice = await findCaptureNotice();
				const timeLeft = within( notice ).getByText( expected );
				expect( timeLeft.closest( 'abbr' ) ).toHaveAttribute(
					'title',
					deadline
				);
			}
		);

		it( 'keeps the countdown and hides the Capture button during fraud review', async () => {
			// Client test "renders the fraud outcome buttons" and its snapshot.
			mockAuthorizedPayment( {
				authorizationCreated: NOW,
				fraudReview: true,
			} );
			renderDetailsPage();

			const notice = await findCaptureNotice();
			expect(
				within( notice ).getByText( '7 days' ).closest( 'p' )
			).toHaveTextContent(
				/this charge within the next 7 days\. Approving this transaction will capture the charge\.$/
			);
			expect(
				within( notice ).getByText( '7 days' )
			).toBeInTheDocument();
			expect(
				within( notice ).queryByRole( 'button' )
			).not.toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Approve transaction' } )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Block transaction' } )
			).toBeInTheDocument();
		} );
	} );

	describe( 'summary card (client summary/index.tsx:160-277, 448-800)', () => {
		const getTerms = () =>
			Array.from( getSummary().querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			);
		const getValue = ( term: string ) =>
			Array.from( getSummary().querySelectorAll( 'dt' ) ).find(
				( candidate ) => candidate.textContent === term
			)?.nextElementSibling;

		it( 'shows the amount with its status, the IDs and one row of labelled values', () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						getBaseCharge() as unknown as WooPaymentsTransaction
					}
					paymentIntentId="pi_abc"
					chargeId="ch_38jdHA39KKA"
				/>
			);

			const summary = getSummary();
			expect( within( summary ).getByText( 'Paid' ) ).toBeInTheDocument();
			expect(
				within( summary ).getByText( 'pi_abc' ).parentElement
			).toHaveTextContent( 'Payment ID: pi_abc' );
			expect(
				within( summary ).getByText( 'ch_38jdHA39KKA' ).parentElement
			).toHaveTextContent( 'Charge ID: ch_38jdHA39KKA' );
			expect( getTerms() ).toEqual( [
				'Date',
				'Sales channel',
				'Customer',
				'Order',
				'Payment method',
				'Risk evaluation',
			] );
			// `formatDateTimeFromTimestamp( created, { separator: ', ', includeTime: true } )`.
			expect( getValue( 'Date' ) ).toHaveTextContent(
				'Sep 19, 2019, 5:24pm'
			);
			expect( getValue( 'Order' ) ).toHaveTextContent( /^45981$/ );
			expect(
				within( getValue( 'Customer' ) as HTMLElement ).getByRole(
					'link',
					{ name: 'Customer name' }
				)
			).toHaveAttribute(
				'href',
				expect.stringContaining(
					'path=%2Fwoopayments%2Ftransactions&search=Customer+name+%28mock%40example.com%29'
				)
			);
			expect( getValue( 'Risk evaluation' ) ).toHaveTextContent( /^–$/ );
		} );

		it( 'drops the sales channel and dates to the minute for a disputed charge', () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							...getBaseCharge(),
							disputes: [ { id: 'dp_1', status: 'won' } ],
						} as unknown as WooPaymentsTransaction
					}
				/>
			);

			expect( getTerms() ).toEqual( [
				'Date',
				'Customer',
				'Order',
				'Payment method',
				'Risk evaluation',
			] );
			expect( getValue( 'Date' ) ).toHaveTextContent(
				'September 19, 2019 5:24 PM'
			);
			expect(
				within( getSummary() ).getByText( 'Disputed: Won' )
			).toBeInTheDocument();
		} );

		it( 'adds the subscription row when WooCommerce Subscriptions is active', () => {
			window.wcSettings = {
				...window.wcSettings,
				admin: { woopaymentsSettings: { isSubscriptionsActive: true } },
			} as unknown as typeof window.wcSettings;
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						getBaseCharge() as unknown as WooPaymentsTransaction
					}
				/>
			);

			expect( getTerms() ).toContain( 'Subscription' );
			expect( getValue( 'Subscription' ) ).toHaveTextContent( /^–$/ );
		} );
	} );

	describe( 'fee breakdown (client summary/index.tsx:316-338, 573-641)', () => {
		it( 'splits the fees behind a help icon when a dispute fee applies', async () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							...getBaseCharge(),
							balance_transaction: {
								amount: 2000,
								currency: 'usd',
								fee: 70,
							},
							// Client test "sums every dispute fee in the breakdown tooltip on the envelope path".
							fee_breakdown_v1: {
								rows: [],
								totals: {
									fee: { amount: 1570, currency: 'usd' },
									net: { amount: 430, currency: 'usd' },
									gross: { amount: 2000, currency: 'usd' },
								},
								notes: [],
							},
							disputes: [
								{
									id: 'dp_1',
									status: 'needs_response',
									effective_fee: {
										amount: 1500,
										currency: 'usd',
									},
								},
							],
						} as unknown as WooPaymentsTransaction
					}
				/>
			);

			await userEvent.click(
				within( getSummary() ).getByRole( 'button', {
					name: 'Fee breakdown',
				} )
			);

			expect(
				Array.from(
					document.querySelectorAll(
						'.woocommerce-woopayments-payment-summary__fee-breakdown > div'
					)
				).map( ( row ) => row.textContent )
			).toEqual( [
				'Transaction fee$0.70',
				'Dispute fee$15.00',
				'Total fees$15.70',
			] );
		} );

		it( 'has no help icon without a dispute fee', () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						getBaseCharge() as unknown as WooPaymentsTransaction
					}
				/>
			);

			expect(
				within( getSummary() ).queryByRole( 'button', {
					name: 'Fee breakdown',
				} )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'loan repayment (client summary/index.tsx:420-431,646-681)', () => {
		it( 'shows the loan repayment and reduces the net by it', () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							...getBaseCharge(),
							paydown: { amount: -300 },
						} as WooPaymentsTransaction
					}
				/>
			);

			const summary = getSummary();
			expect(
				within( summary ).getByText( 'Loan repayment: -$3.00' )
			).toBeInTheDocument();
			expect(
				within( summary ).getByText( 'Net: $16.30' )
			).toBeInTheDocument();
			// The net shows once, in the line under the amount (client summary/index.tsx:652-676).
			expect( within( summary ).queryByText( '$16.30' ) ).toBeNull();
		} );

		it( 'shows no loan repayment line without a paydown', () => {
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							...getBaseCharge(),
							paydown: null,
						} as WooPaymentsTransaction
					}
				/>
			);

			const summary = getSummary();
			expect(
				within( summary ).queryByText( /Loan repayment/ )
			).not.toBeInTheDocument();
			expect(
				within( summary ).getByText( 'Net: $19.30' )
			).toBeInTheDocument();
		} );

		it( 'carries the platform charge paydown into the payment details page', async () => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_abc',
				status: 'succeeded',
				amount: 2000,
				currency: 'usd',
				charge: {
					...getBaseCharge(),
					paydown: { amount: -300 },
				},
			} );
			renderDetailsPage();

			expect(
				await screen.findByText( 'Loan repayment: -$3.00' )
			).toBeInTheDocument();
			expect(
				within( getSummary() ).getByText( 'Net: $16.30' )
			).toBeInTheDocument();
		} );
	} );
	// Client 11.1.0 `utils/charge/index.ts:201-280` `getChargeAmounts()` and `summary/index.tsx:291-296,
	// 356-367, 384-431, 520-681`: refunds, fees and net in the settlement currency. The expected lines are
	// the client's snapshots and `getChargeAmounts` tests.
	describe( 'refunded, fees and net (client utils/charge getChargeAmounts)', () => {
		const getBreakdown = () =>
			Array.from(
				getSummary().querySelectorAll(
					'.woocommerce-woopayments-payment-summary__breakdown > div'
				)
			).map( ( line ) => line.textContent );
		const renderSummary = ( charge: Record< string, unknown > ) =>
			render(
				<WooPaymentsPaymentSummarySection
					transaction={ charge as unknown as WooPaymentsTransaction }
				/>
			);
		// Client test `getBaseCharge()`: the balance transaction carries no `net`.
		const getClientCharge = () => ( {
			...getBaseCharge(),
			disputed: false,
			dispute: null,
			balance_transaction: { amount: 2000, currency: 'usd', fee: 70 },
			refunds: { data: [] as unknown[] },
		} );
		const getRefund = ( amount: number, currency = 'usd' ) => ( {
			balance_transaction: { amount: -amount, currency },
		} );
		const getDispute = ( balanceTransactions: unknown[] ) => ( {
			id: 'dp_1',
			amount: 2000,
			currency: 'usd',
			status: 'needs_response',
			reason: 'fraudulent',
			created: 1693453017,
			balance_transactions: balanceTransactions,
		} );

		it( 'subtracts a partial refund from the net', () => {
			renderSummary( {
				...getClientCharge(),
				refunded: false,
				amount_refunded: 1200,
				refunds: { data: [ getRefund( 1200 ) ] },
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$12.00',
				'Fees: -$0.70',
				'Net: $7.30',
			] );
		} );

		it( 'leaves only the fee as the net of a full refund', () => {
			renderSummary( {
				...getClientCharge(),
				refunded: true,
				amount_refunded: 2000,
				refunds: { data: [ getRefund( 2000 ) ] },
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$20.00',
				'Fees: -$0.70',
				'Net: -$0.70',
			] );
		} );

		it( 'reads the net from the fee breakdown envelope of a refunded charge', () => {
			// Recorded from a fully refunded :8889 charge (`wc/v3/payments/charges/{id}`); ids scrubbed.
			renderSummary( {
				...getClientCharge(),
				amount: 1099,
				refunded: true,
				amount_refunded: 1099,
				balance_transaction: {
					amount: 1099,
					currency: 'usd',
					fee: 62,
					net: 1037,
				},
				refunds: { data: [ getRefund( 1099 ) ] },
				fee_breakdown_v1: {
					rows: [],
					totals: {
						fee: { amount: 62, currency: 'usd' },
						tax: { amount: 0, currency: 'usd' },
						net: { amount: -62, currency: 'usd' },
						capture_net: { amount: 1037, currency: 'usd' },
						gross: { amount: 1099, currency: 'usd' },
						fee_plus_tax: { amount: 62, currency: 'usd' },
					},
					notes: [],
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$10.99',
				'Fees: -$0.62',
				'Net: -$0.62',
			] );
		} );

		it( 'reads a refunded buy now, pay later charge from its envelope', () => {
			renderSummary( {
				...getClientCharge(),
				amount: 10000,
				refunded: true,
				amount_refunded: 10000,
				payment_method_details: { type: 'affirm', affirm: {} },
				balance_transaction: {
					amount: 10000,
					currency: 'usd',
					fee: 630,
					net: 9370,
				},
				refunds: { data: [ getRefund( 10000 ) ] },
				fee_breakdown_v1: {
					rows: [],
					totals: {
						fee: { amount: 630, currency: 'usd' },
						tax: { amount: 0, currency: 'usd' },
						net: { amount: -630, currency: 'usd' },
						gross: { amount: 10000, currency: 'usd' },
						fee_plus_tax: { amount: 630, currency: 'usd' },
					},
					notes: [],
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$100.00',
				'Fees: -$6.30',
				'Net: -$6.30',
			] );
		} );

		it( 'counts the envelope tax in the fees', () => {
			renderSummary( {
				...getClientCharge(),
				fee_breakdown_v1: {
					rows: [],
					totals: {
						fee: { amount: 70, currency: 'usd' },
						tax: { amount: 14, currency: 'usd' },
						net: { amount: 1916, currency: 'usd' },
						gross: { amount: 2000, currency: 'usd' },
					},
					notes: [],
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Fees: -$0.84',
				'Net: $19.16',
			] );
		} );

		it( 'keeps the envelope net when a loan repayment applies, since the server folded it in', () => {
			renderSummary( {
				...getClientCharge(),
				paydown: { amount: -300 },
				fee_breakdown_v1: {
					rows: [],
					totals: {
						fee: { amount: 70, currency: 'usd' },
						net: { amount: 1630, currency: 'usd' },
						gross: { amount: 2000, currency: 'usd' },
					},
					notes: [],
				},
			} );

			expect( getBreakdown() ).toEqual(
				expect.arrayContaining( [
					'Loan repayment: -$3.00',
					'Net: $16.30',
				] )
			);
		} );

		it( 'ignores an envelope whose currency disagrees with the balance transaction', () => {
			renderSummary( {
				...getClientCharge(),
				amount_refunded: 500,
				refunds: { data: [ getRefund( 500 ) ] },
				fee_breakdown_v1: {
					rows: [],
					totals: {
						fee: { amount: 1, currency: 'eur' },
						net: { amount: 1, currency: 'eur' },
						gross: { amount: 1, currency: 'eur' },
					},
					notes: [],
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$5.00',
				'Fees: -$0.70',
				'Net: $14.30',
			] );
		} );

		// Client 11.1.0 `summary/index.tsx:559-566`: the fees use `formatCurrency()`, so they carry no currency code.
		it( 'shows a multi-currency refund in the settlement currency', () => {
			renderSummary( {
				...getClientCharge(),
				amount: 1800,
				application_fee_amount: 82,
				amount_refunded: 1500,
				balance_transaction: { currency: 'eur', amount: 1482, fee: 68 },
				refunds: {
					data: [ getRefund( 1000, 'eur' ), getRefund( 500, 'eur' ) ],
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'€14.82 EUR',
				'Refunded: -€15.00',
				'Fees: -€0.68',
				'Net: -€0.86 EUR',
			] );
		} );

		it( 'deducts a lost dispute and its fee on the legacy path', () => {
			renderSummary( {
				...getClientCharge(),
				disputed: true,
				dispute: getDispute( [
					{
						amount: -2000,
						currency: 'usd',
						fee: 1500,
						reporting_category: 'dispute',
					},
				] ),
			} );

			expect( getBreakdown() ).toEqual( [
				'Deducted: -$20.00',
				'Fees: -$15.70',
				'Net: -$15.70',
			] );
		} );

		it( 'nets a reversed dispute back to the charge', () => {
			// Client snapshot "renders the information of a dispute-reversal charge".
			renderSummary( {
				...getClientCharge(),
				disputed: true,
				dispute: {
					...getDispute( [
						{
							amount: -2000,
							fee: 1500,
							currency: 'usd',
							reporting_category: 'dispute',
						},
						{
							amount: 2000,
							fee: -1500,
							currency: 'usd',
							reporting_category: 'dispute_reversal',
						},
					] ),
					status: 'won',
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Fees: -$0.70',
				'Net: $19.30',
			] );
		} );

		it( 'labels a refund on a charge with an inquiry as refunded', () => {
			renderSummary( {
				...getClientCharge(),
				amount_refunded: 1000,
				refunds: { data: [ getRefund( 1000 ) ] },
				disputed: true,
				dispute: {
					...getDispute( [] ),
					status: 'warning_needs_response',
				},
			} );

			expect( getBreakdown() ).toEqual( [
				'Refunded: -$10.00',
				'Fees: -$0.70',
				'Net: $9.30',
			] );
		} );

		it( 'splits the legacy dispute fee out of the total in the breakdown', async () => {
			// Client test "renders the fee breakdown tooltip of a disputed charge".
			renderSummary( {
				...getClientCharge(),
				currency: 'jpy',
				amount: 10000,
				disputed: true,
				dispute: {
					...getDispute( [
						{
							amount: -1500,
							fee: 1500,
							currency: 'usd',
							reporting_category: 'dispute',
						},
					] ),
					amount: 10000,
					status: 'under_review',
				},
			} );

			await userEvent.click(
				within( getSummary() ).getByRole( 'button', {
					name: 'Fee breakdown',
				} )
			);

			expect(
				Array.from(
					document.querySelectorAll(
						'.woocommerce-woopayments-payment-summary__fee-breakdown > div'
					)
				).map( ( row ) => row.textContent )
			).toEqual( [
				'Transaction fee$0.70',
				'Dispute fee$15.00',
				'Total fees$15.70',
			] );
		} );

		it( 'carries the refunds and the envelope into the payment details page', async () => {
			mockGetPaymentIntent.mockResolvedValue( {
				id: 'pi_abc',
				status: 'succeeded',
				amount: 2000,
				currency: 'usd',
				charge: {
					...getClientCharge(),
					refunded: false,
					amount_refunded: 1200,
					refunds: { data: [ getRefund( 1200 ) ] },
					fee_breakdown_v1: {
						rows: [],
						totals: {
							fee: { amount: 70, currency: 'usd' },
							net: { amount: 730, currency: 'usd' },
							gross: { amount: 2000, currency: 'usd' },
						},
						notes: [],
					},
				} as unknown as WooPaymentsCharge,
			} );
			renderDetailsPage();

			expect(
				await screen.findByText( 'Net: $7.30' )
			).toBeInTheDocument();
			expect(
				within( getSummary() ).getByText( 'Refunded: -$12.00' )
			).toBeInTheDocument();
		} );
	} );
	// Client 11.1.0 `payment-details/payment-method/card/index.js:34,175-183`: the Address prints the server's
	// `billing_details.formatted_address` (WooCommerce's country format, no store-country line), never the raw fields.
	it( 'prints the formatted billing address on the payment method card', async () => {
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_abc',
			status: 'succeeded',
			amount: 2000,
			currency: 'usd',
			charge: {
				...getBaseCharge(),
				payment_method: 'pm_card',
				billing_details: {
					name: 'Customer name',
					email: 'mock@example.com',
					address: {
						line1: '60 29th Street',
						line2: '',
						city: 'San Francisco',
						state: 'CA',
						postal_code: '94110',
						country: 'US',
					},
					formatted_address:
						'60 29th Street<br/>San Francisco, CA 94110',
				},
			} as WooPaymentsCharge,
		} );
		renderDetailsPage();

		const paymentMethod = (
			await screen.findByRole( 'heading', {
				name: 'Payment method',
			} )
		).closest( '.components-card' ) as HTMLElement;
		const address = within( paymentMethod )
			.getByText( 'Address', { selector: 'dt' } )
			.closest( 'div' )
			?.querySelector( 'dd' );

		expect(
			Array.from(
				address?.querySelectorAll(
					'.woocommerce-woopayments-money-movement__stacked-value > span'
				) ?? []
			).map( ( line ) => line.textContent )
		).toEqual( [ '60 29th Street', 'San Francisco, CA 94110' ] );
	} );
	// Client 11.1.0 `components/payment-method-details/index.tsx`: the summary's Payment method is the method's logo,
	// followed by its detail (`•••• iban_last4` for bank redirects, the bank for P24).
	describe( 'payment method logo (client components/payment-method-details)', () => {
		const getPaymentMethodValue = () =>
			Array.from( getSummary().querySelectorAll( 'dt' ) ).find(
				( term ) => term.textContent === 'Payment method'
			)?.nextElementSibling as HTMLElement;
		const renderMethod = ( details: Record< string, unknown > ) =>
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							...getBaseCharge(),
							payment_method_details: details,
						} as unknown as WooPaymentsTransaction
					}
				/>
			);

		it.each( [
			[
				'bancontact',
				{ bank_name: 'Belfius', iban_last4: '7061' },
				'Bancontact',
				'•••• 7061',
			],
			[
				'ideal',
				{ bank: 'ing', iban_last4: '5264' },
				'iDEAL',
				'•••• 5264',
			],
			[ 'affirm', {}, 'Affirm', '' ],
			[ 'afterpay_clearpay', {}, 'Afterpay', '' ],
			[ 'alipay', {}, 'Alipay', '' ],
			[ 'klarna', {}, 'Klarna', '' ],
		] )(
			'shows the %s logo and its detail',
			( type, method, logoLabel, detail ) => {
				renderMethod( { type, [ type ]: method } );

				const value = getPaymentMethodValue();
				const logo = within( value ).getByRole( 'img' );
				expect( logo.getAttribute( 'alt' ) ).toMatch(
					new RegExp( `^${ logoLabel }` )
				);
				expect(
					value.querySelector( '[aria-hidden="true"]' )
						?.textContent ?? ''
				).toBe( detail );
			}
		);

		it( 'shows the P24 bank after the logo', () => {
			renderMethod( { type: 'p24', p24: { bank: 'ing' } } );

			const value = getPaymentMethodValue();
			expect(
				within( value ).getByRole( 'img' ).getAttribute( 'alt' )
			).toBe( 'Przelewy24 (P24)' );
			expect( value ).toHaveTextContent( /^ING$/ );
		} );

		it( 'shows a dash when the method carries no details', () => {
			renderMethod( { type: 'bancontact' } );

			expect( getPaymentMethodValue() ).toHaveTextContent( /^–$/ );
		} );
	} );
	// Monitor N-259: native opens payment details from any dispute reference, so a reference the platform cannot find
	// (or a malformed id) must land on the page's error state, not a blank page. Error bodies recorded read-only from
	// the :8889 routes (`wc/v3/payments/charges|payment_intents|transactions/{id}`); the page shows the client's copy
	// (`payment-details/payment-details/index.tsx:50-64`), not the server's message.
	describe( 'unresolvable references', () => {
		const notFound = ( code: string, message: string ) => ( {
			code,
			message,
			data: { status: 404 },
		} );
		const getVisibleError = () =>
			document.querySelector(
				'.woocommerce-woopayments-money-movement__status.is-error'
			);

		it.each( [
			[
				'an unknown charge',
				'ch_3ZZZZZZZZZZZZZZZZZZZZZZZ',
				() =>
					( getWooPaymentsCharge as jest.Mock ).mockRejectedValue(
						notFound(
							'wcpay_bad_request',
							'Error: No such charge: &#039;ch_3ZZZZZZZZZZZZZZZZZZZZZZZ&#039;'
						)
					),
				'Payment details not loaded',
				'Payment details',
			],
			[
				'an unknown payment intent',
				'pi_3ZZZZZZZZZZZZZZZZZZZZZZZ',
				() =>
					mockGetPaymentIntent.mockRejectedValue(
						notFound(
							'resource_missing',
							"Error: No such payment_intent: 'pi_3ZZZZZZZZZZZZZZZZZZZZZZZ'"
						)
					),
				'Payment details not loaded',
				'Payment details',
			],
			[
				'a malformed id',
				'bogus_reference',
				() =>
					(
						getWooPaymentsTransaction as jest.Mock
					 ).mockRejectedValue(
						notFound(
							'resource_missing',
							"Error: No such balance transaction: 'bogus_reference'"
						)
					),
				'Payment details not loaded',
				// Not a payment id, so the page keeps its transaction title.
				'Transaction details',
			],
		] )(
			'lands on the error state for %s',
			async ( _case, id, mockFailure, message, heading ) => {
				mockFailure();
				render(
					<MemoryRouter
						initialEntries={ [
							`/woopayments/transactions/details?id=${ id }`,
						] }
					>
						<WooPaymentsTransactionDetailsPage />
					</MemoryRouter>
				);

				await waitFor( () =>
					expect( getVisibleError() ).toHaveTextContent( message )
				);
				expect(
					screen.getByRole( 'heading', { name: heading } )
				).toBeInTheDocument();
				expect(
					screen.queryByRole( 'region', { name: 'Summary' } )
				).not.toBeInTheDocument();
			}
		);

		it( 'follows a dispute link to the error state when its charge is gone', async () => {
			( getWooPaymentsDispute as jest.Mock ).mockResolvedValue( {
				id: 'du_test',
				charge_id: 'ch_3ZZZZZZZZZZZZZZZZZZZZZZZ',
			} );
			( getWooPaymentsCharge as jest.Mock ).mockRejectedValue(
				notFound(
					'wcpay_bad_request',
					'Error: No such charge: &#039;ch_3ZZZZZZZZZZZZZZZZZZZZZZZ&#039;'
				)
			);

			render(
				<MemoryRouter
					initialEntries={ [
						'/woopayments/disputes/details?id=du_test',
					] }
				>
					<SettingsShellHistoryBridge />
					<Routes>
						<Route
							path="/woopayments/disputes/details"
							element={ <WooPaymentsDisputeDetailsRedirect /> }
						/>
						<Route
							path="/woopayments/transactions/details"
							element={ <WooPaymentsTransactionDetailsPage /> }
						/>
					</Routes>
				</MemoryRouter>
			);

			await waitFor( () =>
				expect( getVisibleError() ).toHaveTextContent(
					'Payment details not loaded'
				)
			);
			expect(
				screen.getByRole( 'heading', { name: 'Payment details' } )
			).toBeInTheDocument();
		} );
	} );
} );
