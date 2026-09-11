/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import * as moneyMovementData from '../money-movement/data';
import {
	closeWooPaymentsDispute,
	getWooPaymentsDispute,
	getWooPaymentsDisputeFileDetails,
	getWooPaymentsDisputes,
	getWooPaymentsDisputesExportUrl,
	getWooPaymentsCharge,
	getWooPaymentsPaymentIntent,
	getWooPaymentsTransaction,
	getWooPaymentsTimeline,
	getWooPaymentsTransactionsExportUrl,
	getWooPaymentsFraudOutcomeTransactions,
	getWooPaymentsFraudOutcomeTransactionsSummary,
	getWooPaymentsFraudOutcomeTransactionsExport,
	getWooPaymentsFraudOutcomeTransactionSearch,
	getWooPaymentsReaderChargeSummary,
	getWooPaymentsTransactionSearch,
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsSummary,
	requestWooPaymentsDisputesExport,
	requestWooPaymentsTransactionsExport,
	updateWooPaymentsDispute,
	uploadWooPaymentsDisputeFile,
} from '../money-movement/data';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const getCurrentUserTimezone = () => {
	const offset = -new Date().getTimezoneOffset();
	const sign = offset >= 0 ? '+' : '-';
	const absoluteOffset = Math.abs( offset );

	return `${ sign }${ String( Math.floor( absoluteOffset / 60 ) ).padStart(
		2,
		'0'
	) }:${ String( absoluteOffset % 60 ).padStart( 2, '0' ) }`;
};

describe( 'WooPayments money movement data helpers', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValue( {} );
	} );

	it( 'preserves transactions endpoint paths and query names', async () => {
		const readerSummaryAbortController = new AbortController();
		const encodedUserTimezone = encodeURIComponent(
			getCurrentUserTimezone()
		);

		await getWooPaymentsTransactions( {
			page: 2,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			store_currency_is: 'usd',
		} );
		await getWooPaymentsTransaction( 'txn_test' );
		await getWooPaymentsCharge( 'ch_test' );
		await getWooPaymentsPaymentIntent( 'pi_test' );
		await getWooPaymentsTimeline( 'pi_test' );
		await getWooPaymentsReaderChargeSummary( 'txn_reader_fee_123', {
			signal: readerSummaryAbortController.signal,
		} );
		await getWooPaymentsTransactionSearch( 'Ada' );
		await requestWooPaymentsTransactionsExport( {
			deposit_id: 'po_test',
		} );
		await getWooPaymentsTransactionsExportUrl( 'export_test' );

		expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: `/wc/v3/payments/transactions?page=2&pagesize=25&sort=date&direction=desc&store_currency_is=usd&user_timezone=${ encodedUserTimezone }`,
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc/v3/payments/transactions/txn_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 3, {
			path: '/wc/v3/payments/charges/ch_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 4, {
			path: '/wc/v3/payments/payment_intents/pi_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 5, {
			path: '/wc/v3/payments/timeline/pi_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 6, {
			path: '/wc/v3/payments/readers/charges/txn_reader_fee_123',
			method: 'GET',
			signal: readerSummaryAbortController.signal,
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 7, {
			path: '/wc/v3/payments/transactions/search?search_term=Ada',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 8, {
			path: `/wc/v3/payments/transactions/download?deposit_id=po_test&user_timezone=${ encodedUserTimezone }`,
			method: 'POST',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 9, {
			path: '/wc/v3/payments/transactions/download/export_test',
			method: 'GET',
		} );
	} );

	it( 'normalizes date and array filters only at settled transaction API boundaries', async () => {
		const timezoneSpy = jest
			.spyOn( Date.prototype, 'getTimezoneOffset' )
			.mockReturnValue( -180 );

		try {
			await getWooPaymentsTransactions( {
				date_after: '2026-07-08',
				type_is: [ 'charge', 'refund' ],
			} );
			await getWooPaymentsTransactionsSummary( {
				date_before: '2026-07-08',
			} );
			await requestWooPaymentsTransactionsExport( {
				date_between: [ '2026-07-09', '2026-07-08' ],
				search: [ 'Ada', 'Order #1520' ],
			} );
			await getWooPaymentsFraudOutcomeTransactions( {
				search: [ 'Ada', 'Grace' ],
			} );
			await getWooPaymentsDisputes( {
				status_is: [ 'needs_response', 'under_review' ],
			} );

			const calls = mockApiFetch.mock.calls.map(
				( [ request ] ) =>
					new URL(
						( request as { path: string } ).path,
						'https://example.com'
					)
			);
			const [ list, summary, exportRequest, fraudOutcomes, disputes ] =
				calls;

			expect( list.pathname ).toBe( '/wc/v3/payments/transactions' );
			expect( list.searchParams.get( 'date_after' ) ).toBe(
				'2026-07-07 21:00:00'
			);
			expect( list.searchParams.getAll( 'type_is[]' ) ).toEqual( [
				'charge',
				'refund',
			] );
			expect( list.searchParams.getAll( 'type_is' ) ).toEqual( [] );
			expect( list.searchParams.get( 'user_timezone' ) ).toBe( '+03:00' );

			expect( summary.pathname ).toBe(
				'/wc/v3/payments/transactions/summary'
			);
			expect( summary.searchParams.get( 'date_before' ) ).toBe(
				'2026-07-08 20:59:59'
			);
			expect( summary.searchParams.get( 'user_timezone' ) ).toBe(
				'+03:00'
			);

			expect( exportRequest.pathname ).toBe(
				'/wc/v3/payments/transactions/download'
			);
			expect(
				exportRequest.searchParams.getAll( 'date_between[]' )
			).toEqual( [ '2026-07-07 21:00:00', '2026-07-09 20:59:59' ] );
			expect( exportRequest.searchParams.getAll( 'search[]' ) ).toEqual( [
				'Ada',
				'Order #1520',
			] );
			expect( exportRequest.searchParams.get( 'user_timezone' ) ).toBe(
				'+03:00'
			);

			expect( fraudOutcomes.searchParams.getAll( 'search' ) ).toEqual( [
				'Ada',
				'Grace',
			] );
			expect( fraudOutcomes.searchParams.getAll( 'search[]' ) ).toEqual(
				[]
			);
			expect( disputes.searchParams.getAll( 'status_is' ) ).toEqual( [
				'needs_response',
				'under_review',
			] );
			expect( disputes.searchParams.getAll( 'status_is[]' ) ).toEqual(
				[]
			);
		} finally {
			timezoneSpy.mockRestore();
		}
	} );

	it( 'preserves authorizations endpoint paths and action routes', async () => {
		const dataHelpers = moneyMovementData as unknown as Record<
			string,
			( ...args: unknown[] ) => Promise< unknown >
		>;

		expect( typeof dataHelpers.getWooPaymentsAuthorizations ).toBe(
			'function'
		);
		expect( typeof dataHelpers.getWooPaymentsAuthorization ).toBe(
			'function'
		);
		expect( typeof dataHelpers.getWooPaymentsAuthorizationsSummary ).toBe(
			'function'
		);
		expect( typeof dataHelpers.captureWooPaymentsAuthorization ).toBe(
			'function'
		);
		expect( typeof dataHelpers.cancelWooPaymentsAuthorization ).toBe(
			'function'
		);

		await dataHelpers.getWooPaymentsAuthorizations( {
			page: 2,
			pagesize: 25,
			sort: 'capture_by',
			direction: 'desc',
			search: 'Ada',
			loan_id_is: 'loan_test',
			deposit_id: 'po_test',
			store_currency_is: 'usd',
		} );
		await dataHelpers.getWooPaymentsAuthorization( 'pi_test' );
		await dataHelpers.getWooPaymentsAuthorizationsSummary( {
			sort: 'capture_by',
			direction: 'asc',
			type_is: 'charge',
		} );
		await dataHelpers.captureWooPaymentsAuthorization( 123, 'pi_test' );
		await dataHelpers.cancelWooPaymentsAuthorization( 123, 'pi_test' );

		expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/wc/v3/payments/authorizations?page=2&pagesize=25&sort=created&direction=desc&search=Ada',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc/v3/payments/authorizations/pi_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 3, {
			path: '/wc/v3/payments/authorizations/summary?sort=created&direction=asc',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 4, {
			path: '/wc/v3/payments/orders/123/capture_authorization',
			method: 'POST',
			data: {
				payment_intent_id: 'pi_test',
			},
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 5, {
			path: '/wc/v3/payments/orders/123/cancel_authorization',
			method: 'POST',
			data: {
				payment_intent_id: 'pi_test',
			},
		} );
	} );

	it( 'preserves fraud outcome endpoint paths and query names', async () => {
		await getWooPaymentsFraudOutcomeTransactions( {
			status: 'block',
			page: 2,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			search: 'Ada',
		} );
		await getWooPaymentsFraudOutcomeTransactionsSummary( {
			status: 'block',
		} );
		await getWooPaymentsFraudOutcomeTransactionSearch( 'Ada' );
		await getWooPaymentsFraudOutcomeTransactionsExport( {
			status: 'block',
		} );

		expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/wc/v3/payments/transactions/fraud-outcomes?status=block&page=2&pagesize=25&sort=date&direction=desc&search=Ada',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc/v3/payments/transactions/fraud-outcomes/summary?status=block',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 3, {
			path: '/wc/v3/payments/transactions/fraud-outcomes/search?search_term=Ada',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 4, {
			path: '/wc/v3/payments/transactions/fraud-outcomes/download?status=block',
			method: 'GET',
		} );
	} );

	it( 'preserves disputes endpoint paths and query names', async () => {
		await getWooPaymentsDisputes( {
			page: 1,
			pagesize: 25,
			status_is: 'needs_response',
		} );
		await getWooPaymentsDispute( 'dp_test' );
		await updateWooPaymentsDispute( 'dp_test', {
			evidence: { customer_name: 'Ada' },
			submit: true,
			metadata: { order_id: 123 },
		} );
		await closeWooPaymentsDispute( 'dp_test' );
		await requestWooPaymentsDisputesExport( {
			status_is: 'needs_response',
		} );
		await getWooPaymentsDisputesExportUrl( 'export_test' );

		expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/wc/v3/payments/disputes?page=1&pagesize=25&status_is=needs_response',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc/v3/payments/disputes/dp_test',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 3, {
			path: '/wc/v3/payments/disputes/dp_test',
			method: 'POST',
			data: {
				evidence: { customer_name: 'Ada' },
				submit: true,
				metadata: { order_id: 123 },
			},
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 4, {
			path: '/wc/v3/payments/disputes/dp_test/close',
			method: 'POST',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 5, {
			path: '/wc/v3/payments/disputes/download?status_is=needs_response',
			method: 'POST',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 6, {
			path: '/wc/v3/payments/disputes/download/export_test',
			method: 'GET',
		} );
	} );

	it( 'posts dispute evidence file uploads as form data', async () => {
		const formData = new FormData();
		formData.append(
			'file',
			new File( [ 'receipt' ], 'receipt.pdf', {
				type: 'application/pdf',
			} )
		);
		formData.append( 'purpose', 'dispute_evidence' );
		mockApiFetch.mockResolvedValueOnce( {
			id: 'file_test',
			filename: 'receipt.pdf',
			size: 7,
		} );

		const response = await uploadWooPaymentsDisputeFile( formData );

		expect( response ).toMatchObject( {
			id: 'file_test',
			filename: 'receipt.pdf',
			size: 7,
		} );
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/file',
			method: 'POST',
			body: formData,
		} );
	} );

	it( 'fetches dispute evidence file details by file ID', async () => {
		mockApiFetch.mockResolvedValueOnce( {
			id: 'file_test',
			filename: 'receipt.pdf',
			size: 7,
		} );

		const response = await getWooPaymentsDisputeFileDetails( 'file_test' );

		expect( response ).toMatchObject( {
			id: 'file_test',
			filename: 'receipt.pdf',
			size: 7,
		} );
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/file/file_test/details',
			method: 'GET',
		} );
	} );
} );
