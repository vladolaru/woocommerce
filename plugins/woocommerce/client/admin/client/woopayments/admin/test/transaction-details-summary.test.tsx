/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { WooPaymentsPaymentSummarySection } from '../money-movement/transaction-detail-sections';
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import {
	getWooPaymentsAuthorization,
	getWooPaymentsPaymentIntent,
	getWooPaymentsTimeline,
} from '../money-movement/data';
import type {
	WooPaymentsCharge,
	WooPaymentsTransaction,
} from '../money-movement/types';
import { mockAccountMode } from './helpers/test-mode-account';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

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
			expect( within( summary ).getByText( 'Paid' ) ).toHaveClass(
				'woocommerce-status-badge--success'
			);
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
			).toHaveClass( 'woocommerce-status-badge--success' );
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
								fee: 1570,
								net: 430,
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
} );
