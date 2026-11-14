/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	cancelWooPaymentsAuthorization,
	captureWooPaymentsAuthorization,
	closeWooPaymentsDispute,
	getWooPaymentsAuthorization,
	getWooPaymentsCharge,
	getWooPaymentsDispute,
	getWooPaymentsDisputeFileDetails,
	getWooPaymentsDisputesExportUrl,
	getWooPaymentsPaymentIntent,
	getWooPaymentsReaderChargeSummary,
	getWooPaymentsTimeline,
	getWooPaymentsTransaction,
	getWooPaymentsTransactionsExportUrl,
	updateWooPaymentsDispute,
} from '../money-movement/data';
import {
	getWooPaymentsDeposit,
	getWooPaymentsDepositsExportUrl,
} from '../overview/data';
import { getWooPaymentsReportsFeesExportUrl } from '../reports/data';
import {
	buildWooPaymentsDocumentUrl,
	validateWooPaymentsVatNumber,
} from '../documents/data';
import { InvalidPathSegmentError } from '../../path-segment';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const disputeUpdate = {
	evidence: {},
	submit: false,
	metadata: {},
};

// Every admin request that puts an ID in its REST path, as client 11.3.0 routes each through
// `client/utils/path-segment.ts:25-35`.
const idPathRequests: Array<
	[ string, ( id: string ) => Promise< unknown > ]
> = [
	[ 'transaction', ( id ) => getWooPaymentsTransaction( id ) ],
	[ 'charge', ( id ) => getWooPaymentsCharge( id ) ],
	[ 'payment intent', ( id ) => getWooPaymentsPaymentIntent( id ) ],
	[ 'timeline', ( id ) => getWooPaymentsTimeline( id ) ],
	[
		'card reader charge summary',
		( id ) => getWooPaymentsReaderChargeSummary( id ),
	],
	[
		'transactions export URL',
		( id ) => getWooPaymentsTransactionsExportUrl( id ),
	],
	[ 'authorization', ( id ) => getWooPaymentsAuthorization( id ) ],
	[
		'authorization capture',
		( id ) => captureWooPaymentsAuthorization( id, 'pi_test' ),
	],
	[
		'authorization cancel',
		( id ) => cancelWooPaymentsAuthorization( id, 'pi_test' ),
	],
	[ 'dispute', ( id ) => getWooPaymentsDispute( id ) ],
	[ 'disputes export URL', ( id ) => getWooPaymentsDisputesExportUrl( id ) ],
	[
		'dispute update',
		( id ) => updateWooPaymentsDispute( id, disputeUpdate ),
	],
	[
		'dispute file details',
		( id ) => getWooPaymentsDisputeFileDetails( id ),
	],
	[ 'dispute close', ( id ) => closeWooPaymentsDispute( id ) ],
	[ 'payout', ( id ) => getWooPaymentsDeposit( id ) ],
	[ 'payouts export URL', ( id ) => getWooPaymentsDepositsExportUrl( id ) ],
	[
		'fees report export URL',
		( id ) => getWooPaymentsReportsFeesExportUrl( id ),
	],
	[ 'VAT number check', ( id ) => validateWooPaymentsVatNumber( id ) ],
];

describe( 'WooPayments admin requests built from an ID', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValue( {} );
	} );

	it.each( idPathRequests )(
		'%s: an ID that cannot stand as one path segment is refused before any request',
		async ( _label, request ) => {
			await expect( request( '.' ) ).rejects.toBeInstanceOf(
				InvalidPathSegmentError
			);
			expect( mockApiFetch ).not.toHaveBeenCalled();
		}
	);

	it.each( idPathRequests )(
		'%s: a valid ID is sent as one encoded path segment',
		async ( _label, request ) => {
			await request( 'id 1' );

			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
			expect( mockApiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
				'/id%201'
			);
		}
	);

	it( 'refuses a document URL for an ID that cannot stand as one path segment', () => {
		expect( () => buildWooPaymentsDocumentUrl( '.' ) ).toThrow(
			InvalidPathSegmentError
		);
	} );
} );
