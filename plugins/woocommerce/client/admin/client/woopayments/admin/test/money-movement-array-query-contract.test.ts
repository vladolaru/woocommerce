/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import fs from 'fs';
import path from 'path';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsAuthorizations,
	getWooPaymentsDisputes,
	getWooPaymentsFraudOutcomeTransactions,
	getWooPaymentsTransactions,
} from '../money-movement/data';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

/**
 * The PHPUnit side (WooPaymentsMoneyMovementArrayQueryContractTest) replays these request
 * paths through the REST controllers, so the serializers and PHP query parsing are tested
 * together. Regenerate with WOOPAYMENTS_UPDATE_ARRAY_QUERY_FIXTURE=1.
 */
const FIXTURE_PATH = path.resolve(
	__dirname,
	'../../../../../../tests/php/src/Internal/Payments/Providers/WooPayments/Fixtures/money-movement-array-queries.json'
);

const ARRAY_FILTERS = {
	search: [ 'Ada Lovelace', 'Order #1521' ],
	status_is: [ 'needs_response', 'under_review' ],
	date_between: [ '2026-06-01', '2026-06-19' ],
};

const recordPath = async (
	request: () => Promise< unknown >
): Promise< string > => {
	mockApiFetch.mockClear();
	await request();

	return ( mockApiFetch.mock.calls[ 0 ][ 0 ] as { path: string } ).path;
};

describe( 'WooPayments money movement array query contract', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValue( {} );
	} );

	it( 'matches the committed request fixture the PHP contract test replays', async () => {
		const timezoneSpy = jest
			.spyOn( Date.prototype, 'getTimezoneOffset' )
			.mockReturnValue( 0 );

		try {
			const requests = {
				transactions: await recordPath( () =>
					getWooPaymentsTransactions( ARRAY_FILTERS )
				),
				fraud_outcomes: await recordPath( () =>
					getWooPaymentsFraudOutcomeTransactions( {
						status: 'block',
						...ARRAY_FILTERS,
					} )
				),
				disputes: await recordPath( () =>
					getWooPaymentsDisputes( ARRAY_FILTERS )
				),
				authorizations: await recordPath( () =>
					getWooPaymentsAuthorizations( ARRAY_FILTERS )
				),
			};
			const fixture = {
				filters: ARRAY_FILTERS,
				requests,
			};

			if ( process.env.WOOPAYMENTS_UPDATE_ARRAY_QUERY_FIXTURE ) {
				fs.writeFileSync(
					FIXTURE_PATH,
					JSON.stringify( fixture, null, '\t' ) + '\n'
				);
			}

			expect(
				JSON.parse( fs.readFileSync( FIXTURE_PATH, 'utf8' ) )
			).toEqual( fixture );
		} finally {
			timezoneSpy.mockRestore();
		}
	} );
} );
