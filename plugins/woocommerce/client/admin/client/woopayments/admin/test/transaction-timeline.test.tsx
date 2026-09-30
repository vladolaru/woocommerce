/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionTimeline } from '../money-movement/transaction-timeline';
import type { WooPaymentsTimelineEvent } from '../money-movement/types';

// Recorded read-only from the native :8889 store; see the fixture's `_meta`.
const recordedEurCapture: {
	response: { data: WooPaymentsTimelineEvent[] };
} = JSON.parse(
	fs.readFileSync(
		path.resolve(
			__dirname,
			'fixtures/recorded-timeline-eur-bancontact-capture.json'
		),
		'utf8'
	)
);

// Expected copy comes from client 11.1.0 client/payment-details/timeline (map-events.js,
// envelope/compose.js, mappings.ts) and its __tests__ fixtures and snapshots. Amounts use the
// non-explicit formatting the client applies when `shouldUseExplicitPrice` is off.

const renderTimeline = (
	events: WooPaymentsTimelineEvent[],
	props: { bankName?: string } = {}
) =>
	render( <WooPaymentsTransactionTimeline events={ events } { ...props } /> );

// Reads core's `Timeline` markup: one `.woocommerce-timeline-item` per line, newest first.
const getRows = () =>
	Array.from( document.querySelectorAll( '.woocommerce-timeline-item' ) ).map(
		( row ) => ( {
			element: row as HTMLElement,
			message: row.querySelector(
				'.woocommerce-timeline-item__headline > span'
			)?.textContent,
			body: Array.from(
				row.querySelectorAll(
					'.woocommerce-timeline-item__body > span'
				)
			).map( ( line ) => line.textContent ),
		} )
	);

const getMessages = () => getRows().map( ( row ) => row.message );

const getRow = ( message: string ) => {
	const row = getRows().find(
		( candidate ) => candidate.message === message
	);

	if ( ! row ) {
		throw new Error(
			`No timeline row "${ message }" in ${ JSON.stringify(
				getMessages()
			) }`
		);
	}

	return row;
};

const singleCurrencyDetails = {
	customer_currency: 'USD',
	customer_amount: 6300,
	customer_amount_captured: 6300,
	customer_fee: 350,
	store_currency: 'USD',
	store_amount: 6300,
	store_amount_captured: 6300,
	store_fee: 350,
};

const capturedBase = {
	amount: 6300,
	amount_captured: 6300,
	currency: 'USD',
	datetime: 1585751874,
	deposit: {
		arrival_date: 1585838274,
		id: 'dummy_po_5eaada696b281',
	},
	fee: 350,
	type: 'captured',
	transaction_details: singleCurrencyDetails,
};

const feeHistory = [
	{
		type: 'base',
		percentage_rate: 0.014,
		fixed_rate: 20,
		currency: 'gbp',
	},
	{
		type: 'additional',
		additional_type: 'international',
		percentage_rate: 0.014999999999999998,
		fixed_rate: 0,
		currency: 'gbp',
	},
	{
		type: 'additional',
		additional_type: 'fx',
		percentage_rate: 0.020000000000000004,
		fixed_rate: 0,
		currency: 'gbp',
	},
	{
		type: 'discount',
		percentage_rate: -0.049,
		fixed_rate: -20,
		currency: 'gbp',
	},
];

describe( 'WooPaymentsTransactionTimeline fraud outcomes', () => {
	it( 'renders automatic review outcomes with the rules that placed the payment in review', () => {
		// Source: map-events.js:734-773,1393-1394; mappings.ts fraudOutcomeRulesetMapping.
		renderTimeline( [
			{
				type: 'fraud_outcome_review',
				datetime: 1585751874,
				ruleset_results: {
					address_mismatch: 'review',
					avs_verification: 'allow',
					purchase_price_threshold: 'review',
				},
			},
		] );

		expect(
			getRows().map( ( { message, body } ) => ( { message, body } ) )
		).toEqual( [
			{
				message:
					'Payment was screened by your fraud filters and placed in review.',
				body: [
					'Place in review if the shipping address country differs from the billing address country',
					'Place in review if the purchase price is not in your defined range',
				],
			},
		] );
	} );

	it( 'renders automatic block outcomes with the blocking rules', () => {
		// Source: map-events.js:734-773,1395-1396; mappings.ts fraudOutcomeRulesetMapping.
		renderTimeline( [
			{
				type: 'fraud_outcome_block',
				datetime: 1585751874,
				ruleset_results: {
					international_ip_address: 'block',
					order_items_threshold: 'allow',
				},
			},
		] );

		expect(
			getRows().map( ( { message, body } ) => ( { message, body } ) )
		).toEqual( [
			{
				message:
					'Payment was screened by your fraud filters and blocked.',
				body: [
					'Block if the country resolved from customer IP is not listed in your selling countries',
				],
			},
		] );
	} );

	it.each( [
		[ 'fraud_outcome_manual_approve', 'Payment was approved by admin' ],
		[ 'fraud_outcome_manual_block', 'Payment was blocked by admin' ],
	] )( 'links the reviewing user on %s events', ( type, expectedMessage ) => {
		// Source: map-events.js:697-732 getManualFraudOutcomeTimelineItem.
		renderTimeline( [
			{
				type,
				datetime: 1585751874,
				user: { id: 7, username: 'admin' },
			},
		] );

		expect( getMessages() ).toEqual( [ expectedMessage ] );
		expect( screen.getByRole( 'link', { name: 'admin' } ) ).toHaveAttribute(
			'href',
			'user-edit.php?user_id=7'
		);
	} );

	it( 'renders no line for the allowed screenings the platform records', () => {
		// The client has no case for fraud_outcome_allow (map-events.js:1397 default returns []), so its
		// Timeline card shows the component's empty text (timeline/index.js:46).
		const [ allowEvent ] = recordedEurCapture.response.data.filter(
			( event ) => event.type === 'fraud_outcome_allow'
		);

		renderTimeline( [ allowEvent ] );

		expect( getRows() ).toEqual( [] );
		expect( screen.getByText( 'No data to display' ) ).toBeInTheDocument();
	} );
} );

describe( 'WooPaymentsTransactionTimeline failed payments', () => {
	it.each( [
		[
			'card_declined',
			'A payment of $77.00 failed: The card was declined by the bank.',
		],
		[
			'insufficient_funds',
			'A payment of $77.00 failed: The card has insufficient funds to complete the purchase.',
		],
		[ 'expired_card', 'A payment of $77.00 failed: The card has expired.' ],
		[
			'invalid_cvc',
			'A payment of $77.00 failed: The security code is invalid.',
		],
		[
			'unknown_reason',
			'A payment of $77.00 failed: The payment was declined.',
		],
	] )( 'shows the %s failure reason', ( reason, expectedMessage ) => {
		// Source: map-events.js:996-1019 and map-events.test.js "different error codes".
		renderTimeline( [
			{
				amount: 7700,
				currency: 'USD',
				datetime: 1585712113,
				reason,
				type: 'failed',
			},
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Failed.',
			expectedMessage,
		] );
	} );

	it( 'reads the failure reason from `reason`, not `failure_reason`', () => {
		renderTimeline( [
			{
				amount: 7700,
				currency: 'EUR',
				datetime: 1585712113,
				failure_reason: 'insufficient_funds',
				reason: 'card_declined',
				type: 'failed',
			},
		] );

		expect(
			screen.getByText(
				'A payment of €77.00 failed: The card was declined by the bank.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'WooPaymentsTransactionTimeline captured payments', () => {
	it( 'adds the payout line and the fee lines without fee details', () => {
		// Source: map-events.test.js "single currency events formats captured events without fee details".
		renderTimeline( [ capturedBase ] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Paid.',
			'$59.50 was added to your April 2, 2020 payout.',
			'A payment of $63.00 was successfully charged.',
		] );
		expect(
			screen.getByRole( 'link', { name: 'April 2, 2020 payout' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining(
				'path=%2Fwoopayments%2Fpayouts%2Fdetails&id=dummy_po_5eaada696b281'
			)
		);
		expect(
			getRow( 'A payment of $63.00 was successfully charged.' ).body
		).toEqual( [ 'Fee: $3.50', 'Net payout: $59.50' ] );
	} );

	it( 'promises a future payout when the capture is not paid out yet', () => {
		renderTimeline( [ { ...capturedBase, deposit: null } ] );

		expect( getMessages() ).toContain(
			'$59.50 will be added to a future payout.'
		);
	} );

	it( 'renders the fee rate, fee breakdown and discount split', () => {
		// Source: map-events.test.js "single currency events formats captured events with fee details".
		renderTimeline( [
			{
				...capturedBase,
				fee_rates: {
					percentage: 0.0195,
					fixed: 15,
					fixed_currency: 'USD',
					history: feeHistory,
				},
			},
		] );

		const row = getRow( 'A payment of $63.00 was successfully charged.' );

		expect( row.body[ 0 ] ).toBe( 'Fee (1.95% + $0.15): -$3.50' );
		expect( row.body[ 2 ] ).toBe( 'Net payout: $59.50' );
		expect( row.body ).toHaveLength( 3 );
		expect(
			Array.from(
				row.element.querySelectorAll( '.fee-breakdown-list > li' ),
				( item ) => item.firstChild?.textContent
			)
		).toEqual( [
			'Base fee: 1.4% + £0.20',
			'International card fee: 1.5%',
			'Currency conversion fee: 2%',
			'Discount',
		] );
		expect(
			Array.from(
				row.element.querySelectorAll( '.discount-split-list > li' ),
				( item ) => item.textContent
			)
		).toEqual( [ 'Variable fee: -4.9%', 'Fixed fee: -£0.20' ] );
	} );

	it( 'labels a lone base fee and hides the breakdown', () => {
		// Source: map-events.test.js "formats captured events with just the base fee".
		renderTimeline( [
			{
				...capturedBase,
				fee_rates: {
					percentage: 0.0195,
					fixed: 15,
					fixed_currency: 'USD',
					history: [ feeHistory[ 0 ] ],
				},
			},
		] );

		expect(
			getRow( 'A payment of $63.00 was successfully charged.' ).body
		).toEqual( [
			'Base fee (1.95% + $0.15): -$3.50',
			'Net payout: $59.50',
		] );
	} );

	it( 'renders the fee tax line', () => {
		// Source: map-events.test.js "formats captured events with fee details and tax".
		renderTimeline( [
			{
				...capturedBase,
				fee_rates: {
					percentage: 0.0195,
					fixed: 15,
					fixed_currency: 'USD',
					tax: {
						amount: 10,
						currency: 'EUR',
						percentage_rate: 0.21,
						description: 'ES VAT',
					},
					history: feeHistory,
				},
			},
		] );

		expect(
			getRow( 'A payment of $63.00 was successfully charged.' ).body[ 2 ]
		).toBe( 'Tax ES VAT (21.00%): -€0.10' );
	} );

	it( 'renders tap to pay fees', () => {
		// Source: map-events.test.js "in person payments - tap to pay".
		renderTimeline( [
			{
				...capturedBase,
				amount: 1980,
				amount_captured: 1980,
				fee: 61,
				fee_rates: {
					percentage: 0.026,
					fixed: 20,
					fixed_currency: 'USD',
					history: [
						{
							type: 'base',
							percentage_rate: 0.026,
							fixed_rate: 10,
							currency: 'usd',
						},
						{
							type: 'additional',
							additional_type: 'device',
							percentage_rate: 0,
							fixed_rate: 10,
							currency: 'usd',
						},
					],
				},
				transaction_details: {
					...singleCurrencyDetails,
					customer_amount: 1980,
					customer_amount_captured: 1980,
					customer_fee: 61,
					store_amount: 1980,
					store_amount_captured: 1980,
					store_fee: 61,
				},
			},
		] );

		const row = getRow( 'A payment of $19.80 was successfully charged.' );

		expect( row.body[ 0 ] ).toBe( 'Fee (2.6% + $0.20): -$0.61' );
		expect(
			Array.from(
				row.element.querySelectorAll( '.fee-breakdown-list > li' ),
				( item ) => item.textContent
			)
		).toEqual( [
			'Base fee: 2.6% + $0.10',
			'Tap to pay transaction fee: 0% + $0.10',
		] );
		expect( getMessages() ).toContain(
			'$19.19 was added to your April 2, 2020 payout.'
		);
	} );

	it( 'renders the conversion line and store-currency fee for converted charges', () => {
		// Source: map-events.test.js "Multi-Currency events formats captured events with fee details".
		renderTimeline( [
			{
				amount: 1800,
				amount_captured: 1800,
				currency: 'EUR',
				datetime: 1585751874,
				deposit: {
					arrival_date: 1585838274,
					id: 'dummy_po_5eaada696b281',
				},
				fee: 52,
				fee_rates: {
					percentage: 0.029,
					fixed: 30,
					fixed_currency: 'USD',
					fee_exchange_rate: {
						from_currency: 'EUR',
						to_currency: 'USD',
						from_amount: 52,
						to_amount: 62,
						rate: 0.8387096774193548,
					},
				},
				type: 'captured',
				transaction_details: {
					customer_amount: 1800,
					customer_amount_captured: 1800,
					customer_currency: 'EUR',
					customer_fee: 52,
					store_amount: 2159,
					store_amount_captured: 2159,
					store_currency: 'USD',
					store_fee: 62,
				},
			},
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Paid.',
			'$20.97 was added to your April 2, 2020 payout.',
			'A payment of €18.00 was successfully charged.',
		] );
		expect(
			getRow( 'A payment of €18.00 was successfully charged.' ).body
		).toEqual( [
			'€1.00 → 1.19944 USD: $21.59',
			'Fee (2.9% + $0.30): -$0.62',
			'Net payout: $20.97',
		] );
	} );

	it( 'renders the recorded fee_breakdown_v1 envelope of a converted charge', () => {
		// Recorded :8889 platform response; composition from envelope/compose.js.
		renderTimeline( recordedEurCapture.response.data );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Paid.',
			'$11.69 will be added to a future payout.',
			'A payment of €10.99 was successfully charged.',
			'Payment status changed to Started.',
		] );

		const row = getRow( 'A payment of €10.99 was successfully charged.' );

		expect( row.body ).toEqual( [
			'€1.00 → 1.13467 USD: $12.47',
			'Fee (3.9% + $0.30): -$0.78',
			'Base fee: 1.4% + $0.30International card fee: 1.5%Currency conversion fee: 1%',
			'Net payout: $11.69',
		] );
		expect(
			Array.from(
				row.element.querySelectorAll( '.fee-breakdown-list > li' ),
				( item ) => item.textContent
			)
		).toEqual( [
			'Base fee: 1.4% + $0.30',
			'International card fee: 1.5%',
			'Currency conversion fee: 1%',
		] );
	} );

	const envelopeWithDiscount = {
		type: 'captured',
		datetime: 1585751874,
		amount: 1000,
		amount_captured: 1000,
		currency: 'usd',
		fee_breakdown_v1: {
			rows: [
				{
					key: 'base',
					kind: 'fee',
					label: null,
					amount: 59,
					currency: 'usd',
					rate: {
						percentage: 0.029,
						fixed: 30,
						fixed_currency: 'usd',
						percentage_display: '2.9%',
					},
					meta: null,
					display_amount: -59,
				},
				{
					key: 'additional.international',
					kind: 'fee',
					label: null,
					amount: 0,
					currency: 'usd',
					rate: {
						percentage: 0.015,
						fixed: 0,
						fixed_currency: 'usd',
						percentage_display: '1.5%',
					},
					meta: null,
					display_amount: 0,
				},
				{
					key: 'discount.wcpay-promo-2023',
					kind: 'adjustment',
					label: null,
					amount: 0,
					currency: 'usd',
					rate: {
						percentage: -0.0015,
						fixed: -2,
						fixed_currency: 'usd',
						percentage_display: '-0.15%',
					},
					meta: { fee_id: 'wcpay-promo-2023' },
					display_amount: 0,
				},
			],
			totals: {
				fee: {
					key: null,
					amount: 57,
					display_amount: -57,
					currency: 'usd',
					rate: {
						percentage: 0.0425,
						fixed: 28,
						fixed_currency: 'usd',
						percentage_display: '4.25%',
					},
				},
				tax: { amount: 0, display_amount: 0, currency: 'usd' },
				net: { amount: 943, currency: 'usd' },
				capture_net: { amount: 943, currency: 'usd' },
				gross: { amount: 1000, currency: 'usd' },
			},
			notes: [],
		},
	};

	it( 'splits an envelope discount row into signed variable and fixed parts', () => {
		// Source: envelope/__tests__/compose.test.js "discount row renders as parent label + signed Variable/Fixed sub-bullets".
		renderTimeline( [ envelopeWithDiscount ] );

		const row = getRow( 'A payment of $10.00 was successfully charged.' );

		expect( row.body[ 0 ] ).toBe( 'Fee (4.25% + $0.28): -$0.57' );
		expect( row.body[ 2 ] ).toBe( 'Net payout: $9.43' );
		expect(
			Array.from(
				row.element.querySelectorAll( '.fee-breakdown-list > li' ),
				( item ) => item.firstChild?.textContent
			)
		).toEqual( [
			'Base fee: 2.9% + $0.30',
			'International card fee: 1.5%',
			'Discount',
		] );
		expect(
			Array.from(
				row.element.querySelectorAll( '.discount-split-list > li' ),
				( item ) => item.textContent
			)
		).toEqual( [ 'Variable fee: -0.15%', 'Fixed fee: -$0.02' ] );
	} );

	it( 'renders the envelope fee label, tax line and application fee refund note', () => {
		// Source: envelope/compose.js composeTaxLineFromBreakdown, fee-breakdown-label-map.ts.
		renderTimeline( [
			{
				...envelopeWithDiscount,
				fee_breakdown_v1: {
					...envelopeWithDiscount.fee_breakdown_v1,
					rows: [
						envelopeWithDiscount.fee_breakdown_v1.rows[ 0 ],
						{
							key: 'tax_on_fee',
							kind: 'tax',
							label: 'IE VAT',
							amount: 14,
							currency: 'usd',
							rate: { percentage: 0.23 },
						},
					],
					totals: {
						...envelopeWithDiscount.fee_breakdown_v1.totals,
						fee: {
							...envelopeWithDiscount.fee_breakdown_v1.totals.fee,
							key: 'processing_fee',
						},
						tax: {
							amount: 14,
							display_amount: -14,
							currency: 'usd',
						},
					},
					notes: [
						{ code: 'internal_only_code' },
						{
							code: 'application_fee_refunded',
							meta: {
								refunded_amount: 20,
								original_amount: 57,
								refunded_currency: 'usd',
							},
						},
					],
				},
			},
		] );

		expect(
			getRow( 'A payment of $10.00 was successfully charged.' ).body
		).toEqual( [
			'Processing fee (4.25% + $0.28): -$0.57',
			'Tax IE VAT (23.00%): -$0.14',
			'Net payout: $9.43',
			'WooPayments refunded $0.20 of its $0.57 application fee on this transaction.',
		] );
	} );
} );

describe( 'WooPaymentsTransactionTimeline disputes', () => {
	it( 'explains that no funds were withdrawn when a dispute has no amount', () => {
		// Source: map-events.test.js "formats dispute_needs_response events with no amount".
		renderTimeline( [
			{
				amount: null,
				currency: null,
				datetime: 1585793174,
				deposit: null,
				dispute_id: 'some_id',
				evidence_due_by: 1585879574,
				fee: null,
				reason: 'fraudulent',
				type: 'dispute_needs_response',
			},
		] );

		expect(
			getRows().map( ( { message, body } ) => ( { message, body } ) )
		).toEqual( [
			{
				message: 'Payment status changed to Disputed: Needs response.',
				body: [],
			},
			{
				message: 'No funds have been withdrawn yet.',
				body: [
					"The cardholder's bank is requesting more information to decide whether to return these funds to the cardholder.",
				],
			},
			{
				message: 'Payment disputed as Transaction unauthorized.',
				body: [],
			},
		] );
	} );

	it( 'renders the disputed amount, fee and payout impact of a needs-response dispute', () => {
		// Source: map-events.test.js "single currency events formats dispute_needs_response events".
		renderTimeline( [
			{
				amount: 9500,
				currency: 'USD',
				datetime: 1585793174,
				deposit: null,
				dispute_id: 'some_id',
				evidence_due_by: 1585879574,
				fee: 1500,
				reason: 'fraudulent',
				type: 'dispute_needs_response',
			},
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Disputed: Needs response.',
			'$110.00 will be deducted from a future payout.',
			'Payment disputed as Transaction unauthorized.',
		] );
		expect(
			getRow( '$110.00 will be deducted from a future payout.' ).body
		).toEqual( [ 'Disputed amount: $95.00', 'Fee: $15.00' ] );
	} );

	it( 'renders the customer-currency amount and conversion line of a converted dispute', () => {
		// Source: map-events.test.js "Multi-Currency events formats dispute_needs_response events".
		renderTimeline( [
			{
				amount: -2160,
				currency: 'USD',
				datetime: 1585793174,
				deposit: null,
				dispute_id: 'some_id',
				evidence_due_by: 1585879574,
				fee: 1500,
				fee_rates: {
					fee_exchange_rate: {
						from_currency: 'EUR',
						to_currency: 'USD',
						from_amount: null,
						to_amount: 1500,
						rate: null,
					},
				},
				reason: 'fraudulent',
				type: 'dispute_needs_response',
				transaction_details: {
					customer_amount: 1800,
					customer_currency: 'EUR',
					customer_fee: null,
					store_amount: -2160,
					store_currency: 'USD',
					store_fee: 1500,
				},
			},
		] );

		expect(
			getRow( '$36.60 will be deducted from a future payout.' ).body
		).toEqual( [
			'Disputed amount: €18.00',
			'€1.00 → 1.2 USD: $21.60',
			'Fee: $15.00',
		] );
	} );

	it( 'prefers the envelope payout impact and generic headline for unknown reasons', () => {
		// Source: envelope/compose.js getEnvelopeDepositImpact; map-events.js:1021-1031.
		renderTimeline( [
			{
				amount: 9500,
				currency: 'USD',
				datetime: 1585793174,
				deposit: {
					arrival_date: 1586103650,
					id: 'po_dispute',
				},
				fee: 1500,
				reason: 'not_a_known_reason',
				type: 'dispute_needs_response',
				fee_breakdown_v1: {
					totals: { net: { amount: -11200, currency: 'usd' } },
				},
			},
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Disputed: Needs response.',
			'$112.00 was deducted from your April 5, 2020 payout.',
			'Payment disputed',
		] );
	} );

	it( 'renders submitted evidence for in-review disputes', () => {
		// Source: map-events.test.js "formats dispute_in_review events".
		renderTimeline( [
			{ datetime: 1585859207, type: 'dispute_in_review', user_id: 1 },
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Disputed: In review.',
			'Challenge evidence submitted.',
		] );
	} );

	it( 'renders the reversal, fee refund and payout of a won dispute', () => {
		// Source: map-events.test.js "single currency events formats dispute_won events".
		renderTimeline( [
			{
				amount: 10000,
				currency: 'USD',
				datetime: 1586017250,
				deposit: {
					arrival_date: 1586103650,
					id: 'dummy_po_5eaada696b2d3',
				},
				fee: 1500,
				type: 'dispute_won',
			},
		] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Disputed: Won.',
			'$115.00 was added to your April 5, 2020 payout.',
			'Dispute won! The bank ruled in your favor.',
		] );
		expect(
			getRow( '$115.00 was added to your April 5, 2020 payout.' ).body
		).toEqual( [ 'Dispute reversal: $100.00', 'Fee refund: $15.00' ] );
		expect(
			screen.getByRole( 'link', { name: 'April 5, 2020 payout' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'id=dummy_po_5eaada696b2d3' )
		);
	} );

	it( 'renders the bank decision of a lost dispute', () => {
		// Source: map-events.test.js "single currency events formats dispute_lost events".
		const lostEvent = {
			amount: 10000,
			currency: 'USD',
			balance_currency: 'USD',
			datetime: 1586055370,
			deposit: {
				arrival_date: 1586141770,
				id: 'dummy_po_5eaada696b2ef',
			},
			fee: 1500,
			type: 'dispute_lost',
		};
		const { rerender } = renderTimeline( [ lostEvent ] );

		expect( getMessages() ).toEqual( [
			'Payment status changed to Disputed: Lost.',
			"Dispute lost. Your customer's bank reviewed the evidence and decided in the customer's favor.",
		] );

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [ lostEvent ] }
				bankName="Example Bank"
			/>
		);
		expect( getMessages()[ 1 ] ).toBe(
			"Dispute lost. Your customer's bank, Example Bank, reviewed the evidence and decided in the customer's favor."
		);
		expect(
			within( getRows()[ 1 ].element ).getByText( 'Example Bank' ).tagName
		).toBe( 'STRONG' );

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [ { ...lostEvent, reason: 'noncompliant' } ] }
				bankName="Example Bank"
			/>
		);
		expect( getMessages()[ 1 ] ).toBe(
			"Dispute lost. Visa reviewed the evidence and decided in the customer's favor."
		);
	} );

	it( 'renders the network cost of a lost dispute before its status lines', () => {
		// Source: map-events.test.js "formats dispute_lost events with network cost" and the cross-currency variant.
		const lostEvent = {
			amount: 10000,
			currency: 'USD',
			datetime: 1586055370,
			deposit: {
				arrival_date: 1586141770,
				id: 'dummy_po_5eaada696b2ef',
			},
			fee: { amount: 1500, currency: 'usd' },
			network_cost: { amount: 500, currency: 'usd' },
			type: 'dispute_lost',
		};
		const { rerender } = renderTimeline( [ lostEvent ] );

		expect( getRows()[ 0 ] ).toMatchObject( {
			// 1586141770 is Apr 6 in the UTC test site timezone; the client snapshot ran in a US timezone.
			message: '$5.00 was deducted from your April 6, 2020 payout.',
			body: [ 'Network cost for the dispute.' ],
		} );
		expect( getMessages()[ 1 ] ).toBe(
			'Payment status changed to Disputed: Lost.'
		);

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [
					{
						...lostEvent,
						currency: 'EUR',
						network_cost: { amount: 500, currency: 'USD' },
						reason: 'noncompliant',
					},
				] }
			/>
		);
		expect( getRows()[ 0 ] ).toMatchObject( {
			message:
				'$5.00 in your account currency was deducted from your April 6, 2020 payout.',
			body: [
				'Network costs associated with resolving Visa compliance disputes.',
			],
		} );
	} );

	it.each( [
		[
			'dispute_warning_closed',
			'Dispute inquiry closed. The bank chose not to pursue this dispute.',
		],
		[ 'dispute_charge_refunded', 'The disputed charge has been refunded.' ],
	] )( 'renders %s events', ( type, expectedMessage ) => {
		// Source: map-events.test.js "formats dispute_warning_closed events" and "dispute_charge_refunded".
		renderTimeline( [ { datetime: 1585793174, type } ] );

		expect( getMessages() ).toEqual( [ expectedMessage ] );
	} );
} );

describe( 'WooPaymentsTransactionTimeline financing paydowns', () => {
	it( 'renders the payout deduction and the loan link', () => {
		// Source: map-events.test.js "formats financing paydown events".
		const paydown = {
			type: 'financing_paydown',
			datetime: 1643717044,
			amount: -11000,
			loan_id: 'flxln_1KOKzdR4ByxURRrFX9A65q40',
		};
		const { rerender } = renderTimeline( [ paydown ] );

		expect(
			getRows().map( ( { message, body } ) => ( { message, body } ) )
		).toEqual( [
			{
				message: '$110.00 will be subtracted from a future payout.',
				body: [ 'Loan repayment: Loan flxln_1KOKzdR4ByxURRrFX9A65q40' ],
			},
		] );
		expect(
			screen.getByRole( 'link', {
				name: 'Loan flxln_1KOKzdR4ByxURRrFX9A65q40',
			} )
		).toHaveAttribute(
			'href',
			expect.stringContaining(
				'path=%2Fwoopayments%2Ftransactions&loan_id_is=flxln_1KOKzdR4ByxURRrFX9A65q40'
			)
		);

		rerender(
			<WooPaymentsTransactionTimeline
				events={ [
					{
						...paydown,
						deposit: { arrival_date: 1643803444, id: 'po_loan' },
					},
				] }
			/>
		);
		expect( getMessages() ).toEqual( [
			'$110.00 was subtracted from your February 2, 2022 payout.',
		] );
	} );
} );
