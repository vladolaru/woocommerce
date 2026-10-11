/**
 * External dependencies
 */
import { renderHook } from '@testing-library/react';

/**
 * Internal dependencies
 */
import {
	confirmWooPaymentsExport,
	runWooPaymentsExport,
	triggerWooPaymentsExportDownload,
	useWooPaymentsExport,
} from '../money-movement/export';
import { setExportRecipient } from './helpers/export-recipient';

const mockNotices: Array< { status: string; content: string } > = [];

// Records the snackbars the export raises; other stores keep the real dispatch.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( store: string | { name: string } ) =>
			( typeof store === 'string' ? store : store.name ) ===
			'core/notices'
				? {
						createSuccessNotice: ( content: string ) =>
							mockNotices.push( { status: 'success', content } ),
						createErrorNotice: ( content: string ) =>
							mockNotices.push( { status: 'error', content } ),
				  }
				: actual.dispatch( store ),
	};
} );

const getNotices = () => [ ...mockNotices ];

// Client 11.1.0 `hooks/use-report-export.ts` and the list `onDownload` handlers that call it.
describe( 'WooPayments list export', () => {
	let restoreRecipient: () => void;

	beforeEach( () => {
		restoreRecipient = setExportRecipient(
			'merchant@example.test',
			'en_US'
		);
		mockNotices.length = 0;
	} );

	afterEach( () => {
		restoreRecipient();
	} );

	it( 'downloads the file once the export is ready', async () => {
		const getExportUrl = jest.fn().mockResolvedValue( {
			status: 'success',
			download_url: 'https://example.com/export.csv',
		} );
		const triggerDownload = jest.fn();

		await runWooPaymentsExport( {
			requestExport: jest
				.fn()
				.mockResolvedValue( { export_id: 'export_test' } ),
			getExportUrl,
			triggerDownload,
			pollDelayMs: 0,
		} );

		expect( getExportUrl ).toHaveBeenCalledWith( 'export_test' );
		expect( triggerDownload ).toHaveBeenCalledWith(
			'https://example.com/export.csv?force_download=true'
		);
	} );

	it( 'tells the merchant at once that the export is processing and will be emailed', async () => {
		const pendingRequest: {
			resolve?: ( value: Record< string, unknown > ) => void;
		} = {};
		const exporting = runWooPaymentsExport( {
			requestExport: () =>
				new Promise( ( resolve ) => {
					pendingRequest.resolve = resolve;
				} ),
			getExportUrl: jest.fn(),
			pollDelayMs: 0,
		} );

		expect( getNotices() ).toEqual( [
			{
				status: 'success',
				content:
					'We’re processing your export. 🎉 The file will download automatically and be emailed to merchant@example.test.',
			},
		] );

		pendingRequest.resolve?.( {} );
		await exporting;
	} );

	it( 'retries failed and unfinished checks five times, then leaves the file to the email', async () => {
		const getExportUrl = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'Internal Server Error' ) )
			.mockResolvedValueOnce( { status: 'failed' } )
			.mockResolvedValue( { status: 'pending' } );
		const triggerDownload = jest.fn();

		await runWooPaymentsExport( {
			requestExport: jest
				.fn()
				.mockResolvedValue( { export_id: 'export_test' } ),
			getExportUrl,
			triggerDownload,
			pollDelayMs: 0,
		} );

		expect( getExportUrl ).toHaveBeenCalledTimes( 5 );
		expect( triggerDownload ).not.toHaveBeenCalled();
		// Only the processing snackbar, which already says the file will be emailed.
		expect( getNotices().map( ( { status } ) => status ) ).toEqual( [
			'success',
		] );
	} );

	it( 'reports a failed export request with the client error notice', async () => {
		const getExportUrl = jest.fn();

		await runWooPaymentsExport( {
			requestExport: jest.fn().mockRejectedValue( new Error( 'boom' ) ),
			getExportUrl,
			pollDelayMs: 0,
		} );

		expect( getExportUrl ).not.toHaveBeenCalled();
		expect( getNotices() ).toContainEqual( {
			status: 'error',
			content: 'There was a problem generating your export.',
		} );
	} );

	it.each( [
		[ 'transactions', 10000, { type_is: 'charge' } ],
		[ 'disputes', 1000, { status_is: 'needs_response' } ],
		[ 'deposits', 1000, { store_currency_is: 'usd' } ],
	] as const )(
		'asks before an unfiltered export of %s at the client threshold',
		( noun, threshold, filteredQuery ) => {
			const list = noun === 'deposits' ? 'payouts' : noun;
			const confirm = jest
				.spyOn( window, 'confirm' )
				.mockReturnValue( false );

			expect( confirmWooPaymentsExport( list, threshold, {} ) ).toBe(
				false
			);
			expect( confirm ).toHaveBeenCalledWith(
				`You are about to export ${ threshold } ${ noun }. If you'd like to reduce the size of your export, you can use one or more filters. Would you like to continue?`
			);

			confirm.mockClear();
			expect( confirmWooPaymentsExport( list, threshold - 1, {} ) ).toBe(
				true
			);
			expect(
				confirmWooPaymentsExport( list, threshold, filteredQuery )
			).toBe( true );
			expect( confirm ).not.toHaveBeenCalled();

			confirm.mockRestore();
		}
	);

	// Client 11.1.0 `hooks/use-report-export.ts:45-52`: unmounting clears the pending check.
	it( 'stops checking for the file once the page that started the export unmounts', async () => {
		const page: { leave?: () => void } = {};
		// The merchant leaves the page while the first check is in flight.
		const getExportUrl = jest.fn( async () => {
			page.leave?.();
			return { status: 'pending' };
		} );
		const triggerDownload = jest.fn();
		const { result, unmount } = renderHook( () => useWooPaymentsExport() );
		page.leave = unmount;

		await result.current( {
			requestExport: jest
				.fn()
				.mockResolvedValue( { export_id: 'export_test' } ),
			getExportUrl,
			triggerDownload,
			pollDelayMs: 0,
		} );

		expect( getExportUrl ).toHaveBeenCalledTimes( 1 );
		expect( triggerDownload ).not.toHaveBeenCalled();
		// Only the processing snackbar raised before leaving.
		expect( getNotices().map( ( { status } ) => status ) ).toEqual( [
			'success',
		] );
	} );

	it( 'uses a temporary anchor for browser downloads', () => {
		const click = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation();

		triggerWooPaymentsExportDownload( 'https://example.com/export.csv' );

		expect( click ).toHaveBeenCalledTimes( 1 );
		expect(
			document.querySelector( 'a[href="https://example.com/export.csv"]' )
		).not.toBeInTheDocument();

		click.mockRestore();
	} );
} );
