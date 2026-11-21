/**
 * External dependencies
 */
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import {
	WooPaymentsDisputeOutcome,
	WooPaymentsDisputeRecommendations,
	resetDisputeOutcomeTrackingForTests,
} from '../money-movement/dispute-recommendations';
import { WooPaymentsTransactionDetailsPage } from '../money-movement/transaction-details-page';
import {
	getWooPaymentsPaymentIntent,
	getWooPaymentsTimeline,
} from '../money-movement/data';
import type { WooPaymentsDispute } from '../money-movement/types';

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

const mockRecordEvent = recordEvent as jest.MockedFunction<
	typeof recordEvent
>;
const mockGetPaymentIntent = getWooPaymentsPaymentIntent as jest.MockedFunction<
	typeof getWooPaymentsPaymentIntent
>;
const mockGetTimeline = getWooPaymentsTimeline as jest.MockedFunction<
	typeof getWooPaymentsTimeline
>;

// Client 11.1.0 payment-details/dispute-recommendations/__tests__/index.test.tsx `buildDispute()`.
const buildDispute = (
	overrides: Partial< WooPaymentsDispute > = {}
): WooPaymentsDispute => ( {
	id: 'dp_test',
	amount: 2000,
	currency: 'usd',
	created: 1693453017,
	evidence: {},
	metadata: {},
	payment_intent: 'pi_test',
	reason: 'product_not_received',
	status: 'lost',
	...overrides,
} );

// Client fixture `wonPhysicalShippingProvided()`: positives and tips both fire.
const wonPhysicalShippingProvided = () =>
	buildDispute( {
		status: 'won',
		metadata: { __product_type: 'physical_product' },
		evidence: {
			shipping_tracking_number: '1Z999',
			shipping_carrier: 'UPS',
			shipping_date: '2026-04-15',
			shipping_address: '123 Main St',
			receipt: 'receipt-url',
			customer_communication: 'thread',
		},
	} );

// Client fixture: lost, physical, only a receipt. Four coaching entries, so the fourth overflows.
const lostWithReceipt = ( overrides: Partial< WooPaymentsDispute > = {} ) =>
	buildDispute( {
		status: 'lost',
		metadata: { __product_type: 'physical_product' },
		evidence: { receipt: 'r' },
		...overrides,
	} );

const expandSection = ( name: RegExp ) =>
	userEvent.click( screen.getByRole( 'button', { name } ) );

const eventCalls = ( name: string ) =>
	mockRecordEvent.mock.calls.filter( ( [ event ] ) => event === name );

describe( 'WooPayments dispute recommendations (client payment-details/dispute-recommendations)', () => {
	beforeEach( () => {
		mockRecordEvent.mockClear();
		resetDisputeOutcomeTrackingForTests();
	} );

	it( 'shows both sections on a won dispute and names the coaching one "Tips for future disputes"', () => {
		render(
			<WooPaymentsDisputeRecommendations
				dispute={ wonPhysicalShippingProvided() }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: /what's working well/i } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: /tips for future disputes/i } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', {
				name: /what could help next time/i,
			} )
		).not.toBeInTheDocument();
		// Client AccordionBody under Accordion: collapsed until clicked.
		expect(
			screen.getByRole( 'button', { name: /what's working well/i } )
		).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'keeps "What could help next time" on a lost dispute', () => {
		render(
			<WooPaymentsDisputeRecommendations dispute={ lostWithReceipt() } />
		);

		expect(
			screen.getByRole( 'heading', {
				name: /what could help next time/i,
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: /what's working well/i } )
		).not.toBeInTheDocument();
	} );

	it( 'renders nothing for an inquiry outcome or when no entry matches', () => {
		const { container, rerender } = render(
			<WooPaymentsDisputeRecommendations
				dispute={ buildDispute( {
					status: 'warning_closed',
					metadata: { __product_type: 'physical_product' },
				} ) }
			/>
		);
		expect( container ).toBeEmptyDOMElement();

		rerender(
			<WooPaymentsDisputeRecommendations
				dispute={ buildDispute( {
					status: 'won',
					reason: 'bank_cannot_process',
					metadata: { __product_type: 'physical_product' },
				} ) }
			/>
		);
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows three entries, puts the rest behind "Show 1 more", and links "Learn more"', async () => {
		const { container } = render(
			<WooPaymentsDisputeRecommendations dispute={ lostWithReceipt() } />
		);

		await expandSection( /what could help next time/i );

		expect(
			screen
				.getAllByRole( 'article' )
				.filter( ( el ) => ! el.closest( 'details' ) )
		).toHaveLength( 3 );
		const details = container.querySelector( 'details' );
		if ( ! details ) {
			throw new Error( 'Missing the show more disclosure.' );
		}
		expect(
			within( details ).getByText( 'Show 1 more' )
		).toBeInTheDocument();
		expect(
			within( details ).getByRole( 'heading', {
				name: /include a cover letter with your evidence/i,
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /learn more/i } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/managing-payment-disputes/'
		);
	} );

	it( 'shows only the catch-all when no evidence was submitted', async () => {
		render(
			<WooPaymentsDisputeRecommendations
				dispute={ buildDispute( {
					status: 'lost',
					reason: 'fraudulent',
					metadata: { __product_type: 'physical_product' },
					evidence: {},
				} ) }
			/>
		);

		await expandSection( /what could help next time/i );

		expect(
			screen.getByRole( 'heading', {
				name: /submit evidence with your dispute response/i,
			} )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'article' ) ).toHaveLength( 1 );
	} );

	it( 'reads a legacy "multiple" product type as "other"', () => {
		// Client resolve-product-type.ts: with additional evidence types on (the default), `multiple`
		// becomes `other`, so the physical-only shipping entries do not fire.
		render(
			<WooPaymentsDisputeRecommendations
				dispute={ lostWithReceipt( {
					metadata: { __product_type: 'multiple' },
				} ) }
			/>
		);
		expect(
			eventCalls(
				'wcpay_dispute_outcome_recommendations_section_viewed'
			)[ 0 ][ 1 ]
		).toEqual( expect.objectContaining( { product_type: 'other' } ) );
	} );

	it( 'records one section view per dispute and section, and the Learn more and Show more actions', async () => {
		const dispute = lostWithReceipt();
		const { unmount } = render(
			<WooPaymentsDisputeRecommendations dispute={ dispute } />
		);
		unmount();
		const { container } = render(
			<WooPaymentsDisputeRecommendations dispute={ dispute } />
		);

		const views = eventCalls(
			'wcpay_dispute_outcome_recommendations_section_viewed'
		);
		expect( views ).toHaveLength( 1 );
		expect( views[ 0 ][ 1 ] ).toEqual(
			expect.objectContaining( {
				dispute_id: 'dp_test',
				dispute_status: 'lost',
				dispute_reason: 'product_not_received',
				product_type: 'physical_product',
				section: 'what_could_help',
				recommendation_count: 4,
				visible_count: 3,
			} )
		);

		await expandSection( /what could help next time/i );
		await userEvent.click(
			screen.getByRole( 'link', { name: /learn more/i } )
		);
		const details = container.querySelector( 'details' );
		if ( ! details ) {
			throw new Error( 'Missing the show more disclosure.' );
		}
		details.open = true;
		fireEvent( details, new Event( 'toggle' ) );
		// Collapsing is not engagement.
		details.open = false;
		fireEvent( details, new Event( 'toggle' ) );

		expect(
			eventCalls( 'wcpay_dispute_outcome_action_clicked' ).map(
				( [ , props ] ) => ( props as { action: string } ).action
			)
		).toEqual( [ 'learn_more_clicked', 'show_more_expanded' ] );
	} );

	it( 'shows one card per recommendation set, skips accepted disputes and records each outcome view', () => {
		// Client payment-details/summary/index.tsx:982-1067.
		render(
			<WooPaymentsDisputeOutcome
				disputes={ [
					lostWithReceipt( { id: 'dp_1' } ),
					lostWithReceipt( { id: 'dp_2' } ),
					lostWithReceipt( {
						id: 'dp_3',
						metadata: {
							__product_type: 'physical_product',
							__closed_by_merchant: '1',
						},
					} ),
				] }
			/>
		);

		expect(
			screen.getAllByRole( 'heading', {
				name: /what could help next time/i,
			} )
		).toHaveLength( 1 );
		expect(
			eventCalls( 'wcpay_dispute_outcome_viewed' ).map(
				( [ , props ] ) => props
			)
		).toEqual( [
			expect.objectContaining( {
				dispute_id: 'dp_1',
				has_recommendations: true,
			} ),
			expect.objectContaining( {
				dispute_id: 'dp_2',
				has_recommendations: true,
			} ),
			expect.objectContaining( {
				dispute_id: 'dp_3',
				has_recommendations: false,
			} ),
		] );
	} );

	it( 'shows the card on the payment details page after the summary', async () => {
		mockGetTimeline.mockResolvedValue( { data: [] } );
		mockGetPaymentIntent.mockResolvedValue( {
			id: 'pi_test',
			status: 'succeeded',
			amount: 2000,
			currency: 'usd',
			created: 1693453017,
			charge: {
				id: 'ch_test',
				type: 'charge',
				amount: 2000,
				currency: 'usd',
				created: 1693453017,
				payment_intent: 'pi_test',
				disputed: true,
				dispute: lostWithReceipt(),
			},
		} );

		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions/details?id=pi_test',
				] }
			>
				<WooPaymentsTransactionDetailsPage />
			</MemoryRouter>
		);

		const heading = await screen.findByRole( 'heading', {
			name: /what could help next time/i,
		} );
		const summary = screen.getByRole( 'region', { name: 'Summary' } );
		expect(
			// eslint-disable-next-line no-bitwise -- DOM position flags.
			summary.compareDocumentPosition( heading ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );
} );
