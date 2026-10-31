/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
	generateCSVFileName,
} from '@woocommerce/csv-export';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsReaderChargeSummary } from './data';
import type {
	WooPaymentsReaderChargeSummaryResponse,
	WooPaymentsReaderChargeSummaryRow,
} from './types';
import { formatExplicitCurrency, formatLabel, getErrorMessage } from './utils';
import { isZeroDecimalCurrency } from '../currency-format';
import { LiveStatusMessage, StatusMessage } from './table';

const READER_CHARGE_SUMMARY_TIMEOUT_MS = 15000;

const getRows = (
	response: WooPaymentsReaderChargeSummaryResponse
): WooPaymentsReaderChargeSummaryRow[] => {
	if ( Array.isArray( response ) ) {
		return response;
	}

	return response.data || response.rows || [];
};

// The platform's row is { reader_id, count, status, fee: { amount, currency } } (wpcom
// service/class-charge-authorization-service.php get_reader_charges_summary_from_readers_transactions_count()), which
// client 11.1.0 reads as is (payment-details/readers/index.js:62-120).
const getFeeAmount = ( row: WooPaymentsReaderChargeSummaryRow ) =>
	row.fee?.amount;

const getFeeCurrency = ( row: WooPaymentsReaderChargeSummaryRow ) =>
	row.fee?.currency;

/**
 * The fee as the client's CSV holds it: a number in the currency's major unit (`formatExportAmount()`).
 *
 * @param row The reader row.
 */
const getExportFee = ( row: WooPaymentsReaderChargeSummaryRow ) => {
	const amount = getFeeAmount( row );
	const currency = getFeeCurrency( row );

	if ( typeof amount !== 'number' || ! currency ) {
		return 0;
	}

	return isZeroDecimalCurrency( currency ) ? amount : amount / 100;
};

/**
 * Download the rows as the client does, through WooCommerce's CSV export, which neutralizes formula-leading cells.
 *
 * Client 11.1.0 `payment-details/readers/index.js:121-131`.
 *
 * @param rows The reader rows.
 */
const downloadCsv = ( rows: WooPaymentsReaderChargeSummaryRow[] ) => {
	const headers = [
		{ key: 'reader_id', label: __( 'Reader id', 'woocommerce' ) },
		{ key: 'status', label: __( 'Status', 'woocommerce' ) },
		{ key: 'count', label: __( 'Transactions', 'woocommerce' ) },
		{ key: 'fee', label: __( 'Fee', 'woocommerce' ) },
	];
	const csvRows = rows.map( ( row ) => [
		{ value: row.reader_id ?? '', display: row.reader_id ?? '' },
		{ value: row.status ?? '', display: row.status ?? '' },
		{ value: row.count ?? '', display: String( row.count ?? '' ) },
		{ value: getExportFee( row ), display: '' },
	] );
	const params = Object.fromEntries(
		Array.from(
			new URLSearchParams( window.location.search ).entries()
		).filter( ( [ key ] ) => ! [ 'page', 'path', 'tab' ].includes( key ) )
	);

	downloadCSVFile(
		generateCSVFileName( 'Card Readers', params ),
		generateCSVDataFromTable( headers, csvRows )
	);
};

export const WooPaymentsCardReaderFeeDetails = ( {
	transactionId,
}: {
	transactionId: string;
} ) => {
	const [ rows, setRows ] = useState< WooPaymentsReaderChargeSummaryRow[] >(
		[]
	);
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasError, setHasError ] = useState( false );
	const [ errorDetail, setErrorDetail ] = useState( '' );

	useEffect( () => {
		let isMounted = true;
		let didTimeout = false;
		const abortController =
			typeof AbortController === 'undefined'
				? null
				: new AbortController();
		const timeoutMessage = __( 'The request timed out.', 'woocommerce' );
		const timeoutId = window.setTimeout( () => {
			didTimeout = true;

			if ( abortController ) {
				abortController.abort();
				return;
			}

			if ( isMounted ) {
				setRows( [] );
				setErrorDetail( timeoutMessage );
				setHasError( true );
				setIsLoading( false );
			}
		}, READER_CHARGE_SUMMARY_TIMEOUT_MS );

		setIsLoading( true );
		setHasError( false );
		setErrorDetail( '' );

		getWooPaymentsReaderChargeSummary( transactionId, {
			...( abortController ? { signal: abortController.signal } : {} ),
		} )
			.then( ( response ) => {
				if ( ! isMounted || didTimeout ) {
					return;
				}

				setRows( getRows( response ) );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setRows( [] );
				setErrorDetail(
					didTimeout ? timeoutMessage : getErrorMessage( error, '' )
				);
				setHasError( true );
			} )
			.finally( () => {
				window.clearTimeout( timeoutId );

				if ( isMounted ) {
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
			window.clearTimeout( timeoutId );
			abortController?.abort();
		};
	}, [ transactionId ] );

	const loadingMessage: string = __(
		'Loading reader details…',
		'woocommerce'
	);
	const errorMessage: string = __(
		'Readers details not loaded',
		'woocommerce'
	);
	const emptyMessage: string = __(
		'No reader details found.',
		'woocommerce'
	);
	let liveMessage: string = __( 'Reader details loaded.', 'woocommerce' );

	if ( hasError ) {
		liveMessage = errorDetail
			? `${ errorMessage }. ${ errorDetail }`
			: errorMessage;
	} else if ( isLoading ) {
		liveMessage = loadingMessage;
	} else if ( ! rows.length ) {
		liveMessage = emptyMessage;
	}

	const handleDownload = rows.length ? () => downloadCsv( rows ) : undefined;

	return (
		<section
			className="woocommerce-woopayments-overview-card"
			aria-labelledby="woocommerce-woopayments-card-readers-heading"
			aria-busy={ isLoading }
		>
			<div className="woocommerce-woopayments-overview-card__header">
				<h3 id="woocommerce-woopayments-card-readers-heading">
					{ __( 'Card readers', 'woocommerce' ) }
				</h3>
				<Button
					variant="secondary"
					disabled={ ! rows.length }
					accessibleWhenDisabled
					onClick={ handleDownload }
				>
					{ __( 'Download', 'woocommerce' ) }
				</Button>
			</div>
			<LiveStatusMessage isError={ hasError }>
				{ liveMessage }
			</LiveStatusMessage>
			{ isLoading && <StatusMessage>{ loadingMessage }</StatusMessage> }
			{ hasError && (
				<StatusMessage isError>
					{ errorDetail
						? `${ errorMessage }. ${ errorDetail }`
						: errorMessage }
				</StatusMessage>
			) }
			{ ! isLoading && ! hasError && ! rows.length && (
				<StatusMessage>{ emptyMessage }</StatusMessage>
			) }
			{ ! isLoading && ! hasError && !! rows.length && (
				<table className="woocommerce-woopayments-money-movement__reader-fees-table">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Reader id', 'woocommerce' ) }
							</th>
							<th scope="col">
								{ __( 'Status', 'woocommerce' ) }
							</th>
							<th scope="col">
								{ __( 'Transactions', 'woocommerce' ) }
							</th>
							<th scope="col">{ __( 'Fee', 'woocommerce' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, index ) => (
							<tr
								key={ `${
									row.reader_id || 'reader'
								}-${ index }` }
							>
								<td>{ row.reader_id || '-' }</td>
								<td>
									{ row.status
										? formatLabel( row.status )
										: '-' }
								</td>
								<td>{ row.count ?? '-' }</td>
								<td>
									{ formatExplicitCurrency(
										getFeeAmount( row ),
										getFeeCurrency( row )
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
};
