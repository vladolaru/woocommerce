/**
 * Internal dependencies
 */
import {
	getChargeDisputes,
	getDisputeBalanceAdjustments,
	getDisputeOrdinals,
	getPrimaryDispute,
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
