/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useLocation, useNavigate } from 'react-router-dom';

// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import type { Field } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import {
	buildWooPaymentsDocumentUrl,
	getWooPaymentsDocuments,
	getWooPaymentsDocumentsAccount,
	getWooPaymentsDocumentsSummary,
} from './data';
import {
	buildDocumentsRoutePath,
	dataViewsViewToDocumentsQuery,
	documentsQueryToDataViewsView,
	parseDocumentsQuery,
} from './query';
import type {
	WooPaymentsDocument,
	WooPaymentsDocumentsAccountResponse,
	WooPaymentsDocumentsDataView,
	WooPaymentsDocumentsSummary,
	WooPaymentsVatDetails,
} from './types';
import { WooPaymentsVatModal } from './vat-modal';
import { formatSiteDateTime } from '../money-movement/utils';
import { usePersistedHiddenFields } from '../money-movement/view-preferences';
import { WooPaymentsMoneyMovementDataViews } from '../money-movement/dataviews';
import {
	getListMatchFilter,
	getListShowFilter,
	getQueryForShowFilter,
	withListShowFilter,
	WooPaymentsListFilters,
	type WooPaymentsListShowFilter,
} from '../money-movement/list-filters';
import { SpotlightPromotion } from '../../promotions/spotlight';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';

type DocumentsAccountState = {
	enabled: boolean;
	hasSubmittedVatData: boolean;
	country: string;
};

const DOCUMENT_FIELDS = [ 'date', 'type', 'description', 'actions' ];
// Client 11.1.0 `documents/filters/config.ts:35-51`: "Show" all documents or the advanced filters.
const DOCUMENTS_SHOW_FILTERS: WooPaymentsListShowFilter[] = [
	'all',
	'advanced',
];
// The client names the download column `download`.
const DOCUMENT_COLUMN_KEYS = { actions: 'download' };

type PendingDownload = {
	document: WooPaymentsDocument;
	target: '_blank' | '_self';
};

const getDocumentId = ( document: WooPaymentsDocument ) =>
	document.document_id || document.id || '';

const isVatInvoice = ( document: WooPaymentsDocument ) =>
	document.type === 'vat_invoice';

const getErrorMessage = ( error: unknown, fallback: string ): string => {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}

	return fallback;
};

const getDocumentsAccountState = (
	response: WooPaymentsDocumentsAccountResponse
): DocumentsAccountState => {
	return {
		enabled: !! response.documents?.enabled,
		hasSubmittedVatData: !! response.documents?.has_submitted_vat_data,
		country: response.documents?.country || '',
	};
};

const getDocumentTypeLabel = ( type?: string ) => {
	if ( type === 'vat_invoice' ) {
		return __( 'Tax Invoice', 'woocommerce' );
	}

	if ( ! type ) {
		return __( 'Document', 'woocommerce' );
	}

	return type
		.replace( /_/g, ' ' )
		.replace( /\b\w/g, ( match ) => match.toUpperCase() );
};

const getDocumentDescription = ( document: WooPaymentsDocument ) => {
	if ( isVatInvoice( document ) ) {
		return sprintf(
			/* translators: 1: Period start date. 2: Period end date. */
			__( 'Tax invoice for %1$s to %2$s', 'woocommerce' ),
			formatSiteDateTime( document.period_from, false ),
			formatSiteDateTime( document.period_to, false )
		);
	}

	if ( typeof document.description === 'string' && document.description ) {
		return document.description;
	}

	return '-';
};

const getSummaryCount = (
	summary: WooPaymentsDocumentsSummary,
	totalCount: number
) => {
	if ( typeof summary.count === 'number' ) {
		return summary.count;
	}

	if ( typeof summary.total_count === 'number' ) {
		return summary.total_count;
	}

	return totalCount;
};

const getDirectDownloadDocument = (
	search: string
): WooPaymentsDocument | null => {
	const params = new URLSearchParams( search || '' );
	const documentId = params.get( 'document_id' );
	const documentType = params.get( 'document_type' );

	if ( ! documentId || ! documentType ) {
		return null;
	}

	return {
		document_id: documentId,
		type: documentType,
	};
};

export const WooPaymentsDocumentsPage = () => {
	const location = useLocation();
	const navigate = useNavigate();
	const showFilter = getListShowFilter(
		location.search || '',
		DOCUMENTS_SHOW_FILTERS
	);
	const isAdvanced = showFilter === 'advanced';
	const routeQuery = useMemo(
		() => parseDocumentsQuery( location.search || '' ),
		[ location.search ]
	);
	// The advanced filters' "match any" only applies under Show: Advanced filters.
	const query = useMemo( () => {
		if ( isAdvanced || ! routeQuery.match ) {
			return routeQuery;
		}

		const { match, ...rest } = routeQuery;

		return rest;
	}, [ isAdvanced, routeQuery ] );
	const [ documents, setDocuments ] = useState< WooPaymentsDocument[] >( [] );
	const [ summary, setSummary ] = useState< WooPaymentsDocumentsSummary >(
		{}
	);
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ accountState, setAccountState ] =
		useState< DocumentsAccountState | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ pendingDownload, setPendingDownload ] =
		useState< PendingDownload | null >( null );
	const directDownloadAttempted = useRef( false );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_documents_hidden_columns',
		DOCUMENT_FIELDS,
		DOCUMENT_COLUMN_KEYS
	);
	const view = useMemo(
		() => ( {
			...documentsQueryToDataViewsView( query ),
			fields: visibleFields,
		} ),
		[ query, visibleFields ]
	);

	const openDocument = useCallback(
		( document: WooPaymentsDocument, target: '_blank' | '_self' ) => {
			const documentId = getDocumentId( document );

			if ( ! documentId ) {
				return;
			}

			if (
				isVatInvoice( document ) &&
				! accountState?.hasSubmittedVatData
			) {
				setPendingDownload( {
					document,
					target,
				} );
				return;
			}

			window.open( buildWooPaymentsDocumentUrl( documentId ), target );
		},
		[ accountState?.hasSubmittedVatData ]
	);

	useEffect( () => {
		let isMounted = true;

		setIsLoading( true );
		setErrorMessage( null );

		getWooPaymentsDocumentsAccount()
			.then( async ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				const nextAccountState = getDocumentsAccountState( response );
				setAccountState( nextAccountState );

				if ( ! nextAccountState.enabled ) {
					setDocuments( [] );
					setSummary( {} );
					setTotalCount( 0 );
					setIsLoading( false );
					return;
				}

				const [ listResponse, summaryResponse ] = await Promise.all( [
					getWooPaymentsDocuments( query ),
					getWooPaymentsDocumentsSummary( query ),
				] );

				if ( ! isMounted ) {
					return;
				}

				setDocuments( listResponse.data || [] );
				setTotalCount( listResponse.total_count || 0 );
				setSummary( summaryResponse || {} );
				setIsLoading( false );
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}

				setErrorMessage(
					getErrorMessage(
						error,
						__(
							'There was a problem loading WooPayments documents.',
							'woocommerce'
						)
					)
				);
				setIsLoading( false );
			} );

		return () => {
			isMounted = false;
		};
	}, [ query ] );

	useEffect( () => {
		if (
			directDownloadAttempted.current ||
			isLoading ||
			! accountState?.enabled
		) {
			return;
		}

		const document = getDirectDownloadDocument( location.search );

		if ( ! document ) {
			return;
		}

		directDownloadAttempted.current = true;
		openDocument( document, '_self' );
	}, [ accountState?.enabled, isLoading, location.search, openDocument ] );

	const fields = useMemo< Field< WooPaymentsDocument >[] >(
		() => [
			{
				id: 'date',
				type: 'date',
				label: __( 'Date', 'woocommerce' ),
				enableHiding: true,
				// Client 11.1.0 `documents/filters/config.ts`: the date and type filters sit under Advanced filters.
				filterBy: isAdvanced
					? {
							operators: [ 'before', 'after', 'between' ],
							isPrimary: true,
					  }
					: false,
				// Client 11.1.0 `documents/list/index.tsx:205-208`: the site date format.
				render: ( { item }: { item: WooPaymentsDocument } ) =>
					formatSiteDateTime( item.date, false ),
			},
			{
				id: 'type',
				type: 'text',
				label: __( 'Type', 'woocommerce' ),
				enableHiding: false,
				filterBy: isAdvanced
					? { operators: [ 'is', 'isNot' ], isPrimary: true }
					: false,
				elements: [
					{
						label: __( 'Tax Invoice', 'woocommerce' ),
						value: 'vat_invoice',
					},
				],
				render: ( { item }: { item: WooPaymentsDocument } ) =>
					getDocumentTypeLabel( item.type ),
			},
			{
				id: 'description',
				label: __( 'Description', 'woocommerce' ),
				enableHiding: true,
				render: ( { item }: { item: WooPaymentsDocument } ) =>
					getDocumentDescription( item ),
			},
			{
				id: 'actions',
				label: __( 'Download', 'woocommerce' ),
				enableHiding: false,
				render: ( { item }: { item: WooPaymentsDocument } ) => {
					const documentId = getDocumentId( item );
					const documentType = getDocumentTypeLabel( item.type );

					return (
						<Button
							variant="link"
							disabled={ ! documentId }
							aria-label={ sprintf(
								/* translators: 1: Document type. 2: Document ID. */
								__( 'Download %1$s %2$s', 'woocommerce' ),
								documentType,
								documentId
							) }
							onClick={ () => openDocument( item, '_blank' ) }
						>
							{ __( 'Download', 'woocommerce' ) }
						</Button>
					);
				},
			},
		],
		[ openDocument, isAdvanced ]
	);

	const navigateTo = (
		nextQuery: typeof query,
		nextShowFilter = showFilter
	) =>
		navigate(
			withListShowFilter(
				buildDocumentsRoutePath( '/woopayments/documents', nextQuery ),
				nextShowFilter
			)
		);
	const handleChangeView = ( nextView: WooPaymentsDocumentsDataView ) => {
		saveFields( nextView.fields );

		// DataViews adds a filter without a value first; wait for the value.
		if (
			nextView.filters?.some( ( filter ) => filter.value === undefined )
		) {
			return;
		}

		navigateTo( dataViewsViewToDocumentsQuery( nextView, query ) );
	};

	const handleVatCompleted = ( details: WooPaymentsVatDetails ) => {
		setAccountState( ( current ) =>
			current
				? {
						...current,
						hasSubmittedVatData: true,
				  }
				: current
		);
		setPendingDownload( ( current ) => {
			if ( current ) {
				window.open(
					buildWooPaymentsDocumentUrl(
						getDocumentId( current.document )
					),
					current.target
				);
			}

			return null;
		} );

		return details;
	};

	if ( isLoading && ! accountState ) {
		return (
			<div role="status" aria-live="polite" aria-busy="true">
				{ __( 'Loading Documents…', 'woocommerce' ) }
			</div>
		);
	}

	if ( errorMessage ) {
		return (
			<div className="woocommerce-woopayments-documents">
				<WooPaymentsTestModeNotice currentPage="documents" />
				<h1>{ __( 'Documents', 'woocommerce' ) }</h1>
				<Notice status="error" isDismissible={ false }>
					{ errorMessage }
				</Notice>
			</div>
		);
	}

	if ( ! accountState?.enabled ) {
		return (
			<div className="woocommerce-woopayments-documents">
				<WooPaymentsTestModeNotice currentPage="documents" />
				<h1>{ __( 'Documents', 'woocommerce' ) }</h1>
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Documents are not available for this WooPayments account.',
						'woocommerce'
					) }
				</Notice>
			</div>
		);
	}

	const summaryCount = getSummaryCount( summary, totalCount );
	const listFilters = [
		{
			id: 'show',
			label: __( 'Show', 'woocommerce' ),
			value: showFilter,
			options: [
				{ label: __( 'All documents', 'woocommerce' ), value: 'all' },
				{
					label: __( 'Advanced filters', 'woocommerce' ),
					value: 'advanced',
				},
			],
			onChange: ( value: string ) => {
				const nextFilter = getListShowFilter(
					`filter=${ value }`,
					DOCUMENTS_SHOW_FILTERS
				);

				navigateTo(
					getQueryForShowFilter( routeQuery, nextFilter ),
					nextFilter
				);
			},
		},
		...( isAdvanced
			? [
					getListMatchFilter(
						__( 'Documents match', 'woocommerce' ),
						query.match === 'any' ? 'any' : 'all',
						( value ) =>
							navigateTo( {
								...query,
								match: value === 'any' ? 'any' : undefined,
							} )
					),
			  ]
			: [] ),
	];

	return (
		<div className="woocommerce-woopayments-documents">
			{ /* Client 11.1.0 documents/index.tsx:17. */ }
			<WooPaymentsTestModeNotice currentPage="documents" />
			<WooPaymentsListFilters filters={ listFilters } />
			{ /* Client 11.1.0 `documents/list/index.tsx:241-290`: a TableCard titled Documents with the count in its footer. */ }
			<WooPaymentsMoneyMovementDataViews
				view={ view }
				onChangeView={ handleChangeView }
				fields={ fields }
				rows={ documents }
				isLoading={ isLoading }
				search={ false }
				searchLabel={ __( 'Search documents', 'woocommerce' ) }
				title={ __( 'Documents', 'woocommerce' ) }
				summary={
					isLoading
						? []
						: [
								{
									label: _n(
										'document',
										'documents',
										summaryCount,
										'woocommerce'
									),
									value: String( summaryCount ),
								},
						  ]
				}
				numericFields={ [ 'actions' ] }
				total={ totalCount }
				loadingMessage={ __( 'Loading Documents…', 'woocommerce' ) }
				empty={ __( 'No data to display', 'woocommerce' ) }
				getItemId={ getDocumentId }
			/>
			<SpotlightPromotion />
			{ pendingDownload && (
				<WooPaymentsVatModal
					country={ accountState.country }
					onClose={ () => setPendingDownload( null ) }
					onCompleted={ handleVatCompleted }
				/>
			) }
		</div>
	);
};

export default WooPaymentsDocumentsPage;
