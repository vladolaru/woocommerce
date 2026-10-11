/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionTimeline } from '../money-movement/transaction-timeline';
import type { WooPaymentsTimelineEvent } from '../money-movement/types';

// Every expected icon, headline, bold part and body line below comes from client 11.1.0
// `client/payment-details/timeline/map-events.js` (line numbers per case), never from native output.
// Icons: gridicons render `gridicon gridicons-<name>` plus the class map-events.js passes.
// Amounts use the non-explicit formatting the client applies when `shouldUseExplicitPrice` is off,
// and payout dates the site date format (`formatDateTimeFromTimestamp()`, `F j, Y` by default).

type ExpectedItem = {
	icon: string;
	headline: string;
	strong?: string[];
	body?: string[];
};

const getItems = ( container: ReturnType< typeof render >[ 'container' ] ) =>
	Array.from(
		container.querySelectorAll( '.woocommerce-timeline-item' )
	).map( ( item ) => {
		const headline = item.querySelector(
			'.woocommerce-timeline-item__headline > span'
		);

		return {
			icon:
				item
					.querySelector(
						'.woocommerce-timeline-item__headline > svg'
					)
					?.getAttribute( 'class' ) ?? '',
			headline: headline?.textContent ?? '',
			strong: Array.from(
				headline?.querySelectorAll( 'strong' ) ?? []
			).map( ( strong ) => strong.textContent ),
			body: Array.from(
				item.querySelectorAll(
					'.woocommerce-timeline-item__body > span'
				)
			)
				.map( ( line ) => line.textContent )
				.filter( Boolean ),
		};
	} );

const status = ( label: string ): ExpectedItem => ( {
	icon: 'gridicon gridicons-sync',
	headline: `Payment status changed to ${ label }.`,
	strong: [ label ],
} );

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

const payout = { arrival_date: 1585838274, id: 'po_example' };

const cases: Array< [ string, WooPaymentsTimelineEvent, ExpectedItem[] ] > = [
	[
		// map-events.js:832-838.
		'started',
		{ datetime: 1585751874, type: 'started' },
		[ status( 'Started' ) ],
	],
	[
		// map-events.js:839-858.
		'authorized',
		{
			amount: 7900,
			currency: 'USD',
			datetime: 1585589596,
			type: 'authorized',
		},
		[
			status( 'Authorized' ),
			{
				icon: 'gridicon gridicons-checkmark is-warning',
				headline: 'A payment of $79.00 was successfully authorized.',
			},
		],
	],
	[
		// map-events.js:859-878.
		'authorization_voided',
		{
			amount: 5900,
			currency: 'USD',
			datetime: 1585652279,
			type: 'authorization_voided',
		},
		[
			status( 'Authorization voided' ),
			{
				icon: 'gridicon gridicons-checkmark is-warning',
				headline: 'Authorization for $59.00 was voided.',
			},
		],
	],
	[
		// map-events.js:879-898.
		'authorization_expired',
		{
			amount: 8600,
			currency: 'USD',
			datetime: 1585691920,
			type: 'authorization_expired',
		},
		[
			status( 'Authorization expired' ),
			{
				icon: 'gridicon gridicons-cross is-error',
				headline: 'Authorization for $86.00 expired.',
			},
		],
	],
	[
		// map-events.js:899-936, 86-142.
		'captured',
		{
			amount: 6300,
			amount_captured: 6300,
			currency: 'USD',
			datetime: 1585751874,
			deposit: payout,
			fee: 350,
			type: 'captured',
			transaction_details: singleCurrencyDetails,
		},
		[
			status( 'Paid' ),
			{
				icon: 'gridicon gridicons-plus',
				headline: '$59.50 was added to your April 2, 2020 payout.',
			},
			{
				icon: 'gridicon gridicons-checkmark is-success',
				headline: 'A payment of $63.00 was successfully charged.',
				body: [ 'Fee: $3.50', 'Net payout: $59.50' ],
			},
		],
	],
	[
		// map-events.js:937-974.
		'full_refund',
		{
			amount_refunded: 6300,
			currency: 'USD',
			datetime: 1585751874,
			deposit: payout,
			type: 'full_refund',
			transaction_details: singleCurrencyDetails,
		},
		[
			status( 'Refunded' ),
			{
				icon: 'gridicon gridicons-minus',
				headline: '$63.00 was deducted from your April 2, 2020 payout.',
			},
			{
				icon: 'gridicon gridicons-checkmark is-success',
				headline: 'A payment of $63.00 was successfully refunded.',
			},
		],
	],
	[
		// map-events.js:937-974, 472-482 (reason line).
		'partial_refund',
		{
			amount_refunded: 1000,
			currency: 'USD',
			datetime: 1585751874,
			deposit: null,
			reason: 'requested_by_customer',
			type: 'partial_refund',
			transaction_details: singleCurrencyDetails,
		},
		[
			status( 'Partial refund' ),
			{
				icon: 'gridicon gridicons-minus',
				headline: '$10.00 will be deducted from a future payout.',
			},
			{
				icon: 'gridicon gridicons-checkmark is-success',
				headline: 'A payment of $10.00 was successfully refunded.',
				body: [ 'Reason: Requested by customer' ],
			},
		],
	],
	[
		// map-events.js:975-995, 485-517.
		'refund_failed',
		{
			amount_refunded: 100,
			currency: 'USD',
			datetime: 1585859207,
			type: 'refund_failed',
			acquirer_reference_number_status: 'available',
			acquirer_reference_number: '4785767637658864',
			failure_reason: 'expired_or_canceled_card',
		},
		[
			{
				icon: 'gridicon gridicons-notice-outline is-error',
				headline:
					'$1.00 refund was attempted but failed due to the card being expired or canceled.',
				body: [ 'Acquirer Reference Number (ARN) 4785767637658864' ],
			},
		],
	],
	[
		// map-events.js:996-1019.
		'failed',
		{
			amount: 7700,
			currency: 'USD',
			datetime: 1585712113,
			reason: 'card_declined',
			type: 'failed',
		},
		[
			status( 'Failed' ),
			{
				icon: 'gridicon gridicons-cross is-error',
				headline:
					'A payment of $77.00 failed: The card was declined by the bank.',
			},
		],
	],
	[
		// map-events.js:1020-1105.
		'dispute_needs_response',
		{
			amount: 9500,
			currency: 'USD',
			datetime: 1585793174,
			deposit: null,
			dispute_id: 'dp_example',
			fee: 1500,
			reason: 'fraudulent',
			type: 'dispute_needs_response',
		},
		[
			status( 'Disputed: Needs response' ),
			{
				icon: 'gridicon gridicons-minus',
				headline: '$110.00 will be deducted from a future payout.',
				body: [ 'Disputed amount: $95.00', 'Fee: $15.00' ],
			},
			{
				icon: 'gridicon gridicons-cross is-error',
				headline: 'Payment disputed as Transaction unauthorized.',
			},
		],
	],
	[
		// map-events.js:1034-1049.
		'dispute_needs_response without an amount',
		{
			amount: null,
			currency: null,
			datetime: 1585793174,
			deposit: null,
			dispute_id: 'dp_example',
			fee: null,
			reason: 'fraudulent',
			type: 'dispute_needs_response',
		},
		[
			status( 'Disputed: Needs response' ),
			{
				icon: 'gridicon gridicons-info-outline',
				headline: 'No funds have been withdrawn yet.',
				body: [
					"The cardholder's bank is requesting more information to decide whether to return these funds to the cardholder.",
				],
			},
			{
				icon: 'gridicon gridicons-cross is-error',
				headline: 'Payment disputed as Transaction unauthorized.',
			},
		],
	],
	[
		// map-events.js:1106-1124.
		'dispute_in_review',
		{ datetime: 1585859207, type: 'dispute_in_review' },
		[
			status( 'Disputed: In review' ),
			{
				icon: 'gridicon gridicons-checkmark is-success',
				headline: 'Challenge evidence submitted.',
			},
		],
	],
	[
		// map-events.js:1125-1166.
		'dispute_won',
		{
			amount: 10000,
			currency: 'USD',
			datetime: 1586017250,
			deposit: { arrival_date: 1586103650, id: 'po_won' },
			fee: 1500,
			type: 'dispute_won',
		},
		[
			status( 'Disputed: Won' ),
			{
				icon: 'gridicon gridicons-plus',
				headline: '$115.00 was added to your April 5, 2020 payout.',
				body: [ 'Dispute reversal: $100.00', 'Fee refund: $15.00' ],
			},
			{
				icon: 'gridicon gridicons-notice-outline is-success',
				headline: 'Dispute won! The bank ruled in your favor.',
			},
		],
	],
	[
		// map-events.js:1167-1249.
		'dispute_lost',
		{
			amount: 10000,
			currency: 'USD',
			datetime: 1586055370,
			deposit: { arrival_date: 1586141770, id: 'po_lost' },
			fee: { amount: 1500, currency: 'usd' },
			network_cost: { amount: 500, currency: 'usd' },
			type: 'dispute_lost',
		},
		[
			{
				icon: 'gridicon gridicons-minus',
				headline: '$5.00 was deducted from your April 6, 2020 payout.',
				body: [ 'Network cost for the dispute.' ],
			},
			status( 'Disputed: Lost' ),
			{
				icon: 'gridicon gridicons-cross is-error',
				headline:
					"Dispute lost. Your customer's bank reviewed the evidence and decided in the customer's favor.",
				strong: [ 'Dispute lost.' ],
			},
		],
	],
	[
		// map-events.js:1250-1262.
		'dispute_warning_closed',
		{ datetime: 1585793174, type: 'dispute_warning_closed' },
		[
			{
				icon: 'gridicon gridicons-notice-outline is-success',
				headline:
					'Dispute inquiry closed. The bank chose not to pursue this dispute.',
			},
		],
	],
	[
		// map-events.js:1263-1275.
		'dispute_charge_refunded',
		{ datetime: 1585793174, type: 'dispute_charge_refunded' },
		[
			{
				icon: 'gridicon gridicons-notice-outline is-success',
				headline: 'The disputed charge has been refunded.',
			},
		],
	],
	[
		// map-events.js:1276-1306, 153-192.
		'financing_paydown',
		{
			amount: -11000,
			currency: 'USD',
			datetime: 1643717044,
			loan_id: 'flxln_example',
			type: 'financing_paydown',
		},
		[
			{
				icon: 'gridicon gridicons-minus',
				headline: '$110.00 will be subtracted from a future payout.',
				body: [ 'Loan repayment: Loan flxln_example' ],
			},
		],
	],
	[
		// map-events.js:1307-1388 (no refund handler).
		'early_fraud_warning',
		{
			datetime: 1585859207,
			efw_actionable: true,
			efw_type: 'made_with_stolen_card',
			type: 'early_fraud_warning',
		},
		[
			status( 'Early fraud warning' ),
			{
				icon: 'gridicon gridicons-notice-outline is-warning',
				headline: 'Payment received an early fraud warning',
				body: [
					'The card issuer flagged this payment as likely fraudulent.',
					'Reported reason: Made with stolen card',
					'Refunding this payment now can prevent a dispute.',
				],
			},
		],
	],
	[
		// map-events.js:1318-1343.
		'early_fraud_warning resolved',
		{
			datetime: 1585859207,
			efw_actionable: false,
			efw_type: 'made_with_stolen_card',
			type: 'early_fraud_warning',
		},
		[
			status( 'Early fraud warning resolved' ),
			{
				icon: 'gridicon gridicons-notice-outline',
				headline: 'This early fraud warning is no longer actionable.',
				body: [
					'The payment was refunded or disputed, so no further action is needed to avoid a dispute.',
					'Reported reason: Made with stolen card',
				],
			},
		],
	],
	[
		// map-events.js:697-732, 1389-1390.
		'fraud_outcome_manual_approve',
		{
			datetime: 1585751874,
			type: 'fraud_outcome_manual_approve',
			user: { id: 7, username: 'admin' },
		},
		[
			{
				icon: 'gridicon gridicons-checkmark is-success',
				headline: 'Payment was approved by admin',
			},
		],
	],
	[
		// map-events.js:697-732, 1391-1392.
		'fraud_outcome_manual_block',
		{
			datetime: 1585751874,
			type: 'fraud_outcome_manual_block',
			user: { id: 7, username: 'admin' },
		},
		[
			{
				icon: 'gridicon gridicons-cross is-error',
				headline: 'Payment was blocked by admin',
			},
		],
	],
	[
		// map-events.js:734-773, 1393-1394 (ShieldIcon) and mappings.ts.
		'fraud_outcome_review',
		{
			datetime: 1585751874,
			ruleset_results: {
				address_mismatch: 'review',
				avs_verification: 'allow',
			},
			type: 'fraud_outcome_review',
		},
		[
			{
				icon: 'is-fraud-outcome-review',
				headline:
					'Payment was screened by your fraud filters and placed in review.',
				body: [
					'Place in review if the shipping address country differs from the billing address country',
				],
			},
		],
	],
	[
		// map-events.js:734-773, 1395-1396.
		'fraud_outcome_block',
		{
			datetime: 1585751874,
			ruleset_results: { international_ip_address: 'block' },
			type: 'fraud_outcome_block',
		},
		[
			{
				icon: 'gridicon gridicons-cross is-error',
				headline:
					'Payment was screened by your fraud filters and blocked.',
				body: [
					'Block if the country resolved from customer IP is not listed in your selling countries',
				],
			},
		],
	],
];

describe( 'WooPaymentsTransactionTimeline items (client map-events.js)', () => {
	it.each( cases )(
		'maps %s events to the client icon, headline and body',
		( _type, event, expected ) => {
			const { container } = render(
				<WooPaymentsTransactionTimeline events={ [ event ] } />
			);

			expect( getItems( container ) ).toEqual(
				expected.map( ( item ) => ( {
					icon: item.icon,
					headline: item.headline,
					strong: item.strong ?? [],
					body: item.body ?? [],
				} ) )
			);
		}
	);

	it.each( [
		'dispute.created',
		'dispute.funds_withdrawn',
		'fraud_outcome_allow',
	] )(
		'shows no line for %s events, as the client default case',
		( type ) => {
			// map-events.js:1397-1398: unknown types map to no items.
			const { container } = render(
				<WooPaymentsTransactionTimeline
					events={ [ { datetime: 1585751874, type, amount: 100 } ] }
				/>
			);

			expect( getItems( container ) ).toEqual( [] );
			expect( container ).toHaveTextContent( 'No data to display' );
		}
	);

	it( 'shows the client error text in the card when the timeline fails to load', () => {
		// index.js:39-45.
		const { container } = render(
			<WooPaymentsTransactionTimeline
				events={ [ { datetime: 1585751874, type: 'started' } ] }
				hasError
			/>
		);

		expect( container ).toHaveTextContent(
			'TimelineError while loading timeline'
		);
		expect( getItems( container ) ).toEqual( [] );
	} );

	it( 'groups lines under the site date and shows the site time, newest first', () => {
		// index.js:46 `<Timeline items={ items } timezone="site" />`; the component's day groups and `g:ia` times.
		const { container } = render(
			<WooPaymentsTransactionTimeline
				events={ [
					{ datetime: 1585751874, type: 'started' },
					{
						amount: 7900,
						currency: 'USD',
						datetime: 1585838274,
						type: 'authorized',
					},
				] }
			/>
		);

		expect(
			Array.from(
				container.querySelectorAll(
					'.woocommerce-timeline-group__title'
				)
			).map( ( title ) => title.textContent )
		).toEqual( [ 'April 2, 2020', 'April 1, 2020' ] );
		expect(
			Array.from(
				container.querySelectorAll(
					'.woocommerce-timeline-item__timestamp'
				)
			).map( ( time ) => time.textContent )
		).toEqual( [ '2:37pm', '2:37pm', '2:37pm' ] );
		expect(
			getItems( container ).map( ( item ) => item.headline )
		).toEqual( [
			'Payment status changed to Authorized.',
			'A payment of $79.00 was successfully authorized.',
			'Payment status changed to Started.',
		] );
	} );
} );
