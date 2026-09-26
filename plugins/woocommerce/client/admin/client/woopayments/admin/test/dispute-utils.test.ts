/**
 * Internal dependencies
 */
import {
	getChargeDisputes,
	getDisputeBalanceAdjustments,
	getDisputeOrdinals,
	getPrimaryDispute,
	hasEffectiveDisputeFee,
	isDisputeRefundable,
} from '../money-movement/dispute-utils';

describe( 'WooPayments dispute utilities', () => {
	it( 'prefers an authoritative plural response without mutating its order', () => {
		const newer = { id: 'dp_newer', created: 2000, status: 'won' };
		const older = {
			id: 'dp_older',
			created: 1000,
			status: 'needs_response',
		};
		const source = {
			dispute: { id: 'dp_singular', created: 500 },
			disputes: [ newer, older ],
		};

		expect( getChargeDisputes( source ) ).toEqual( [ newer, older ] );
		expect( getDisputeOrdinals( source ) ).toEqual( {
			orderById: { dp_older: 1, dp_newer: 2 },
			orderedDisputes: [ older, newer ],
			total: 2,
		} );
		expect( source.disputes ).toEqual( [ newer, older ] );
		expect( getPrimaryDispute( source ) ).toBe( older );
	} );

	it.each( [ undefined, [] ] )(
		'falls back to the singular dispute when plural data is %p',
		( disputes ) => {
			const dispute = { id: 'dp_singular', created: 1000 };

			expect( getChargeDisputes( { dispute, disputes } ) ).toEqual( [
				dispute,
			] );
			expect( getDisputeOrdinals( { dispute, disputes } ) ).toEqual( {
				orderById: { dp_singular: 1 },
				orderedDisputes: [ dispute ],
				total: 1,
			} );
		}
	);

	it( 'keeps malformed identities out of the shared ordinal map', () => {
		const first = { created: 'not-a-time' };
		const duplicate = { id: 'dp_shared', created: 1000 };
		const laterDuplicate = { id: 'dp_shared', created: 2000 };

		expect(
			getDisputeOrdinals( {
				disputes: [ first, laterDuplicate, duplicate ],
			} )
		).toEqual( {
			orderById: {},
			orderedDisputes: [ first, duplicate, laterDuplicate ],
			total: 3,
		} );
	} );

	it( 'folds every finite dispute balance adjustment without trusting malformed fields', () => {
		expect(
			getDisputeBalanceAdjustments( {
				disputes: [
					{
						balance_transactions: [
							{ amount: -1000, fee: 1500 },
							{ amount: 'invalid', fee: 25 },
						],
					},
					{
						balance_transactions: [
							{ amount: -800, fee: 1500 },
							{
								amount: Number.NaN,
								fee: Number.POSITIVE_INFINITY,
							},
						],
					},
				],
			} )
		).toEqual( { fee: 3025, refunded: 1800 } );
	} );

	describe( 'effective dispute fee detection', () => {
		const legacyFeeRow = {
			amount: -5000,
			currency: 'usd',
			fee: 1500,
			reporting_category: 'dispute',
		};

		it.each( [
			[
				'positive annotated amount',
				{
					effective_fee: { amount: 1500, currency: 'usd' },
				},
				true,
			],
			[
				'negative annotated amount',
				{
					effective_fee: { amount: -1500, currency: 'usd' },
				},
				true,
			],
			[
				'explicit null annotation with a legacy fee row',
				{
					effective_fee: null,
					balance_transactions: [ legacyFeeRow ],
				},
				false,
			],
			[
				'zero annotated amount',
				{
					effective_fee: { amount: 0, currency: 'usd' },
				},
				false,
			],
			[
				'NaN annotated amount',
				{
					effective_fee: { amount: Number.NaN, currency: 'usd' },
				},
				false,
			],
			[
				'infinite annotated amount',
				{
					effective_fee: {
						amount: Number.POSITIVE_INFINITY,
						currency: 'usd',
					},
				},
				false,
			],
			[
				'annotated fee without an amount',
				{
					effective_fee: { currency: 'usd' },
				},
				false,
			],
			[
				'legacy dispute fee row',
				{ balance_transactions: [ legacyFeeRow ] },
				true,
			],
			[
				'legacy dispute reversal row',
				{
					balance_transactions: [
						legacyFeeRow,
						{
							amount: 5000,
							currency: 'usd',
							fee: -1500,
							reporting_category: 'dispute_reversal',
						},
					],
				},
				false,
			],
			[
				'zero legacy dispute fee',
				{
					balance_transactions: [ { ...legacyFeeRow, fee: 0 } ],
				},
				false,
			],
			[ 'missing fee data', {}, false ],
		] )(
			'detects the effective fee for %s',
			( _label, dispute, expected ) => {
				expect( hasEffectiveDisputeFee( dispute ) ).toBe( expected );
			}
		);
	} );

	it( 'fails refund admission closed for absent, unknown, and active statuses', () => {
		expect( isDisputeRefundable( {} ) ).toBe( false );
		expect( isDisputeRefundable( { status: 'unknown' } ) ).toBe( false );
		expect( isDisputeRefundable( { status: 'needs_response' } ) ).toBe(
			false
		);
		expect(
			isDisputeRefundable( { status: 'warning_under_review' } )
		).toBe( true );
		expect( isDisputeRefundable( { status: 'won' } ) ).toBe( true );
	} );
} );
