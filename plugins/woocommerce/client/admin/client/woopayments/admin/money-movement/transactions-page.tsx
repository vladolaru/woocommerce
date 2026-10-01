/**
 * External dependencies
 */
import { Button, TabPanel } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getHistory } from '@woocommerce/navigation';
import { recordEvent } from '@woocommerce/tracks';
import moment from 'moment';
import type { ReactElement, ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	captureWooPaymentsAuthorization,
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorizationsSummary,
} from './data';
import type {
	WooPaymentsAuthorization,
	WooPaymentsAuthorizationsSummary,
	WooPaymentsMoneyMovementDataView,
	WooPaymentsMoneyMovementQuery,
} from './types';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
	sanitizeWooPaymentsAuthorizationsQuery,
} from './query';
import { WooPaymentsMoneyMovementDataViews } from './dataviews';
import { WooPaymentsBlockedTransactions } from './blocked-transactions';
import { WooPaymentsTransactionsList } from './transactions-list';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import { ClickableCell, getRiskLevelLabel } from './transactions-list-fields';
import {
	formatExplicitCurrency,
	formatSiteDateTime,
	getErrorMessage,
	getTransactionDetailsRoute,
} from './utils';
import { LiveStatusMessage, reportListLoadError } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	getSettingsPaymentsProviderAdminPath,
	getSettingsPaymentsProviderRouteUrl,
} from '../utils';
import { SpotlightPromotion } from '../../promotions/spotlight';
import { getWooPaymentsSettingsBootstrap } from '../../settings/bootstrap';
import '../style.scss';

type MoneyMovementSummary = WooPaymentsAuthorizationsSummary;
type WorkingMoneyMovementView = {
	key: string;
	view: WooPaymentsMoneyMovementDataView;
} | null;
type NoticeDispatch = {
	createSuccessNotice: ( message: string ) => void;
	createErrorNotice: ( message: string ) => void;
};

// Client 11.1.0 `transactions/uncaptured/index.tsx:43-108`: the columns, in order.
const AUTHORIZATION_FIELDS = [
	'created',
	'capture_by',
	'order',
	'risk_level',
	'amount',
	'customer_email',
	'customer_country',
	'action',
];
const NO_COLUMN_KEY_CHANGES: Record< string, string > = {};
// The client's columns with `visible: false`.
const AUTHORIZATION_DEFAULT_HIDDEN_COLUMNS = [
	'customer_email',
	'customer_country',
];

const getSummaryCount = ( summary: MoneyMovementSummary ) => {
	const totalCount =
		'total_count' in summary ? summary.total_count : undefined;

	if ( typeof totalCount === 'number' ) {
		return totalCount;
	}

	const count = 'count' in summary ? summary.count : undefined;

	return typeof count === 'number' ? count : undefined;
};

const getSummaryTotal = ( summary: MoneyMovementSummary ) => {
	const total = 'total' in summary ? summary.total : undefined;

	if ( typeof total === 'number' ) {
		return total;
	}

	const gross = 'gross' in summary ? summary.gross : undefined;

	return typeof gross === 'number' ? gross : undefined;
};

const getSummaryCurrency = ( summary: MoneyMovementSummary ) =>
	'currency' in summary && typeof summary.currency === 'string'
		? summary.currency
		: undefined;

// Client 11.1.0 `transactions/uncaptured/index.tsx:231-236`: the total only for one currency.
const shouldShowSummaryTotal = ( summary: MoneyMovementSummary ) =>
	'all_currencies' in summary &&
	Array.isArray( summary.all_currencies ) &&
	summary.all_currencies.length === 1;

const getAuthorizationPaymentIntentId = ( item: WooPaymentsAuthorization ) =>
	item.payment_intent_id || item.id || '';

const getAuthorizationOrderId = ( item: WooPaymentsAuthorization ) => {
	const orderId = item.order_id;

	return typeof orderId === 'number' || typeof orderId === 'string'
		? String( orderId )
		: '';
};

// Client 11.1.0 `transactions/uncaptured/index.tsx:150-161`: seven days after the authorization, read as UTC.
const getAuthorizationCaptureBy = ( value?: string | number ) => {
	if ( ! value ) {
		return '-';
	}

	const created =
		typeof value === 'number'
			? moment.utc( value < 10000000000 ? value * 1000 : value )
			: moment.utc( value );

	return created.isValid()
		? formatSiteDateTime( created.add( 7, 'd' ).toISOString() )
		: '-';
};

/**
 * An uncaptured list cell that opens the payment details.
 * Client 11.1.0 `transactions/uncaptured/index.tsx:128-130`: every `clickable()` cell.
 *
 * @param props          The component props.
 * @param props.item     The authorization row.
 * @param props.children The cell content.
 */
const AuthorizationCell = ( {
	item,
	children,
}: {
	item: WooPaymentsAuthorization;
	children?: ReactNode;
} ) => (
	<ClickableCell
		href={ getSettingsPaymentsProviderRouteUrl(
			getTransactionDetailsRoute( {
				payment_intent_id: getAuthorizationPaymentIntentId( item ),
			} )
		) }
	>
		{ children }
	</ClickableCell>
);

const getNotices = () =>
	dispatch( 'core/notices' ) as unknown as NoticeDispatch;

const buildUncapturedRoute = (
	view: WooPaymentsMoneyMovementDataView,
	currentQuery: WooPaymentsMoneyMovementQuery
) => {
	const route = buildMoneyMovementRoutePath(
		'/woopayments/transactions',
		sanitizeWooPaymentsAuthorizationsQuery(
			dataViewsViewToMoneyMovementQuery( view, currentQuery )
		)
	);

	return `${ route }${ route.includes( '?' ) ? '&' : '?' }view=uncaptured`;
};

const buildTransactionsRoute = ( query: WooPaymentsMoneyMovementQuery ) =>
	buildMoneyMovementRoutePath( '/woopayments/transactions', query );

export const WooPaymentsTransactionsPage = () => {
	const location = useLocation();
	// In the settings shell `tab` is always `checkout`; the view travels as `view`.
	const isUncaptured = useMemo(
		() =>
			new URLSearchParams( location.search ).get( 'view' ) ===
			'uncaptured',
		[ location.search ]
	);
	const isBlocked =
		new URLSearchParams( location.search ).get( 'view' ) === 'blocked';
	const [ authorizations, setAuthorizations ] = useState<
		WooPaymentsAuthorization[]
	>( [] );
	const [ totalCount, setTotalCount ] = useState( 0 );
	const [ summary, setSummary ] = useState< MoneyMovementSummary >( {} );
	const [ uncapturedCount, setUncapturedCount ] = useState< number | null >(
		null
	);
	const [ uncapturedTotal, setUncapturedTotal ] = useState( 0 );
	const isMountedRef = useRef( false );
	const uncapturedCountRequestIdRef = useRef( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasLoadError, setHasLoadError ] = useState( false );
	// The payment intent whose capture is in flight.
	const [ pendingCapture, setPendingCapture ] = useState< string | null >(
		null
	);
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_transactions_uncaptured_hidden_columns',
		AUTHORIZATION_FIELDS,
		NO_COLUMN_KEY_CHANGES,
		AUTHORIZATION_DEFAULT_HIDDEN_COLUMNS
	);
	const [ workingView, setWorkingView ] =
		useState< WorkingMoneyMovementView >( null );
	const canonicalViewKey = location.search;
	const resourceQuery = useMemo(
		() =>
			sanitizeWooPaymentsAuthorizationsQuery(
				parseMoneyMovementQuery( location.search, {
					page: 1,
					pagesize: 25,
					sort: 'created',
					direction: 'desc',
				} )
			),
		[ location.search ]
	);
	const queryView = useMemo(
		() =>
			moneyMovementQueryToDataViewsView( resourceQuery, {
				fields: visibleFields,
			} ),
		[ resourceQuery, visibleFields ]
	);
	const view =
		workingView?.key === canonicalViewKey ? workingView.view : queryView;
	const loadUncapturedCount = useCallback( async () => {
		if ( ! isMountedRef.current ) {
			return;
		}

		const requestId = ++uncapturedCountRequestIdRef.current;
		setUncapturedCount( null );

		try {
			const nextSummary = await getWooPaymentsAuthorizationsSummary( {} );
			const nextCount = getSummaryCount( nextSummary );

			if (
				isMountedRef.current &&
				requestId === uncapturedCountRequestIdRef.current
			) {
				setUncapturedCount(
					typeof nextCount === 'number' ? nextCount : null
				);
				setUncapturedTotal( getSummaryTotal( nextSummary ) ?? 0 );
			}
		} catch {
			if (
				isMountedRef.current &&
				requestId === uncapturedCountRequestIdRef.current
			) {
				// Keep the active transactions view usable when its count fails. Client 11.1.0
				// `transactions/index.tsx:65` reads this summary, whose resolver raises the notice.
				setUncapturedCount( null );
				reportListLoadError(
					__(
						'Error retrieving uncaptured transactions.',
						'woocommerce'
					)
				);
			}
		}
	}, [] );

	useEffect( () => {
		setWorkingView( ( currentView ) =>
			currentView?.key === canonicalViewKey ? currentView : null
		);
	}, [ canonicalViewKey ] );

	useEffect( () => {
		if ( isBlocked ) {
			return;
		}

		recordEvent( 'page_view', {
			path: isUncaptured
				? 'payments_transactions_uncaptured'
				: 'payments_transactions',
		} );
	}, [ isBlocked, isUncaptured ] );

	const loadMoneyMovement = useCallback(
		async ( {
			setLoading = true,
			isCurrent = () => true,
		}: {
			setLoading?: boolean;
			isCurrent?: () => boolean;
		} = {} ) => {
			const canUpdate = () => isCurrent();

			if ( setLoading ) {
				setIsLoading( true );
			}

			// Client 11.1.0 `data/authorizations/resolvers.ts:30-125`: the list and its summary load
			// apart, and each failure raises the same notice.
			const [ listResult, summaryResult ] = await Promise.allSettled( [
				getWooPaymentsAuthorizations( resourceQuery ),
				getWooPaymentsAuthorizationsSummary( resourceQuery ),
			] );

			if ( ! canUpdate() ) {
				return;
			}

			[ listResult, summaryResult ].forEach( ( result ) => {
				if ( result.status === 'rejected' ) {
					reportListLoadError(
						__(
							'Error retrieving uncaptured transactions.',
							'woocommerce'
						)
					);
				}
			} );

			const response =
				listResult.status === 'fulfilled'
					? listResult.value
					: undefined;
			const nextSummary =
				summaryResult.status === 'fulfilled' ? summaryResult.value : {};

			setAuthorizations( response?.data || [] );
			setTotalCount(
				response?.total_count ?? getSummaryCount( nextSummary ) ?? 0
			);
			setSummary( nextSummary );
			setHasLoadError( listResult.status === 'rejected' );

			if ( setLoading ) {
				setIsLoading( false );
			}
		},
		[ resourceQuery ]
	);

	useEffect( () => {
		let isMounted = true;

		if ( ! isUncaptured ) {
			return;
		}

		void loadMoneyMovement( {
			isCurrent: () => isMounted,
		} );

		return () => {
			isMounted = false;
		};
	}, [ isUncaptured, loadMoneyMovement ] );

	useEffect( () => {
		isMountedRef.current = true;

		void loadUncapturedCount();

		return () => {
			isMountedRef.current = false;
			uncapturedCountRequestIdRef.current += 1;
		};
	}, [ loadUncapturedCount ] );

	const handleViewChange = ( nextView: WooPaymentsMoneyMovementDataView ) => {
		saveFields( nextView.fields );
		setWorkingView( {
			key: canonicalViewKey,
			view: nextView,
		} );

		if (
			nextView.filters?.some( ( filter ) => filter.value === undefined )
		) {
			return;
		}

		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				buildUncapturedRoute( nextView, resourceQuery )
			)
		);
	};
	const handleCapture = async ( authorization: WooPaymentsAuthorization ) => {
		const paymentIntentId =
			getAuthorizationPaymentIntentId( authorization );
		const orderId = Number( getAuthorizationOrderId( authorization ) );

		// Client 11.1.0 `transactions/uncaptured/index.tsx:193-198`.
		recordEvent(
			'payments_transactions_uncaptured_list_capture_charge_button_click',
			{ payment_intent_id: paymentIntentId }
		);

		if (
			! paymentIntentId ||
			! Number.isFinite( orderId ) ||
			orderId <= 0
		) {
			getNotices().createErrorNotice(
				__(
					'Unable to process this authorization because the order details are incomplete.',
					'woocommerce'
				)
			);
			return;
		}

		setPendingCapture( paymentIntentId );

		try {
			await captureWooPaymentsAuthorization( orderId, paymentIntentId );
			await Promise.all( [
				loadMoneyMovement( { setLoading: false } ),
				loadUncapturedCount(),
			] );
			setPendingCapture( null );
			getNotices().createSuccessNotice(
				sprintf(
					/* translators: %s: order ID. */
					__(
						'Payment for order #%s captured successfully.',
						'woocommerce'
					),
					String( orderId )
				)
			);
		} catch ( error ) {
			setPendingCapture( null );
			getNotices().createErrorNotice(
				sprintf(
					/* translators: 1: action name, 2: order ID, 3: error message. */
					__(
						'Unable to %1$s authorization for order #%2$s. %3$s',
						'woocommerce'
					),
					'capture',
					String( orderId ),
					getErrorMessage(
						error,
						__(
							'Please refresh the page and try again.',
							'woocommerce'
						)
					)
				)
			);
		}
	};

	const authorizationFields = [
		{
			id: 'created',
			label: __( 'Authorized on', 'woocommerce' ),
			enableHiding: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ formatSiteDateTime( item.created ) }
				</AuthorizationCell>
			),
		},
		{
			id: 'capture_by',
			label: __( 'Capture by', 'woocommerce' ),
			enableHiding: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ getAuthorizationCaptureBy( item.created ) }
				</AuthorizationCell>
			),
		},
		{
			id: 'order',
			label: __( 'Order', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => {
				const orderId = getAuthorizationOrderId( item );

				if ( ! orderId ) {
					return '-';
				}

				// Client 11.1.0 `transactions/uncaptured/index.tsx:166`.
				const orderLabel = item.customer_name
					? `#${ orderId } ${ item.customer_name }`
					: `#${ orderId }`;
				const paymentIntentId = getAuthorizationPaymentIntentId( item );

				if ( ! paymentIntentId ) {
					return orderLabel;
				}

				return (
					<a
						href={ getSettingsPaymentsProviderRouteUrl(
							getTransactionDetailsRoute( {
								payment_intent_id: paymentIntentId,
							} )
						) }
						aria-label={ sprintf(
							/* translators: %s: order ID. */
							__(
								'View payment details for order #%s',
								'woocommerce'
							),
							orderId
						) }
					>
						{ orderLabel }
					</a>
				);
			},
		},
		{
			id: 'risk_level',
			label: __( 'Risk level', 'woocommerce' ),
			enableHiding: true,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ getRiskLevelLabel( item.risk_level ) }
				</AuthorizationCell>
			),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'woocommerce' ),
			enableHiding: true,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ formatExplicitCurrency( item.amount, item.currency ) }
				</AuthorizationCell>
			),
		},
		{
			id: 'customer_email',
			label: __( 'Email', 'woocommerce' ),
			enableHiding: true,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ item.customer_email || '' }
				</AuthorizationCell>
			),
		},
		{
			id: 'customer_country',
			label: __( 'Country', 'woocommerce' ),
			enableHiding: true,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => (
				<AuthorizationCell item={ item }>
					{ item.customer_country || '' }
				</AuthorizationCell>
			),
		},
		{
			id: 'action',
			label: __( 'Action', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => {
				const orderId = getAuthorizationOrderId( item );
				const isCapturing =
					pendingCapture === getAuthorizationPaymentIntentId( item );

				// Client 11.1.0 `components/capture-authorization-button`: one secondary "Capture".
				return (
					<Button
						variant="secondary"
						__next40pxDefaultSize
						isBusy={ isCapturing }
						disabled={ isCapturing }
						onClick={ () => handleCapture( item ) }
						aria-label={
							isCapturing
								? sprintf(
										/* translators: %s: order ID. */
										__(
											'Capturing authorization for order #%s',
											'woocommerce'
										),
										orderId
								  )
								: sprintf(
										/* translators: %s: order ID. */
										__(
											'Capture authorization for order #%s',
											'woocommerce'
										),
										orderId
								  )
						}
					>
						{ __( 'Capture', 'woocommerce' ) }
					</Button>
				);
			},
		},
	];

	let liveStatusMessage: string = __(
		'Uncaptured transactions loaded.',
		'woocommerce'
	);
	const loadingMessage: string = __(
		'Loading uncaptured transactions…',
		'woocommerce'
	);
	const emptyMessage: string = __(
		'No uncaptured transactions found.',
		'woocommerce'
	);

	if ( hasLoadError ) {
		// The error notice announces itself.
		liveStatusMessage = '';
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	} else if ( authorizations.length === 0 ) {
		liveStatusMessage = emptyMessage;
	}

	const summaryCount = getSummaryCount( summary );
	const summaryTotal = getSummaryTotal( summary );
	// Client 11.1.0 `transactions/uncaptured/index.tsx:214-245`: the footer summary, once loaded.
	const summaryItems: Array< { label: string; value: string } > =
		! isLoading && summaryCount !== undefined && summaryTotal !== undefined
			? [
					{
						label: __( 'authorization(s)', 'woocommerce' ),
						value: String( summaryCount ),
					},
			  ]
			: [];

	if (
		summaryItems.length &&
		summaryCount &&
		summaryCount > 0 &&
		shouldShowSummaryTotal( summary )
	) {
		summaryItems.push( {
			label: __( 'total', 'woocommerce' ),
			value: formatExplicitCurrency(
				summaryTotal,
				getSummaryCurrency( summary )
			),
		} );
	}
	let currentTab = 'transactions';

	if ( isUncaptured ) {
		currentTab = 'uncaptured';
	} else if ( isBlocked ) {
		currentTab = 'blocked';
	}

	const uncapturedTabLabel = sprintf(
		/* translators: %1$s: number of uncaptured authorizations, or an ellipsis while loading. */
		__( 'Uncaptured (%1$s)', 'woocommerce' ),
		uncapturedCount === null ? '…' : String( uncapturedCount )
	);
	// Client 11.1.0 `transactions/index.tsx:63-72`: the Uncaptured tab shows with manual capture on
	// or authorizations to capture; it also stays while it is the open view.
	const showUncapturedTab =
		getWooPaymentsSettingsBootstrap().isManualCaptureEnabled === true ||
		uncapturedTotal > 0 ||
		isUncaptured;
	const handleTabSelect = ( tabName: string ) => {
		if ( tabName === currentTab ) {
			return;
		}

		getHistory().push(
			getSettingsPaymentsProviderAdminPath(
				tabName === 'transactions'
					? '/woopayments/transactions'
					: `/woopayments/transactions?view=${ tabName }`
			)
		);
	};

	// Client 11.1.0 `transactions/index.tsx:78-127`: a `TabPanel` over the three lists; the Blocked tab always shows.
	const renderTabs = ( content: ReactElement ) => (
		<TabPanel
			key={ currentTab }
			className="woocommerce-woopayments-money-movement__tabs"
			activeClass="is-active"
			initialTabName={ currentTab }
			onSelect={ handleTabSelect }
			tabs={ [
				{
					name: 'transactions',
					title: __( 'Transactions', 'woocommerce' ),
				},
				...( showUncapturedTab
					? [ { name: 'uncaptured', title: uncapturedTabLabel } ]
					: [] ),
				{ name: 'blocked', title: __( 'Blocked', 'woocommerce' ) },
			] }
		>
			{ () => content }
		</TabPanel>
	);

	if ( isBlocked ) {
		return (
			<div className="woocommerce-woopayments-money-movement">
				{ /* Client 11.1.0 transactions/index.tsx:105, above every tab. */ }
				<WooPaymentsTestModeNotice currentPage="transactions" />
				<SpotlightPromotion />
				{ renderTabs( <WooPaymentsBlockedTransactions /> ) }
			</div>
		);
	}

	if ( ! isUncaptured ) {
		return (
			<div className="woocommerce-woopayments-money-movement">
				<WooPaymentsTestModeNotice currentPage="transactions" />
				<SpotlightPromotion />
				{ renderTabs(
					<WooPaymentsTransactionsList
						buildRoute={ buildTransactionsRoute }
						title={ __( 'Transactions', 'woocommerce' ) }
					/>
				) }
			</div>
		);
	}

	return (
		<div className="woocommerce-woopayments-money-movement">
			<WooPaymentsTestModeNotice currentPage="transactions" />
			<SpotlightPromotion />
			{ renderTabs(
				<section aria-busy={ isLoading }>
					<LiveStatusMessage>{ liveStatusMessage }</LiveStatusMessage>
					<WooPaymentsMoneyMovementDataViews
						fields={ isLoading ? [] : authorizationFields }
						rows={ authorizations }
						view={ view }
						onChangeView={ handleViewChange }
						total={ totalCount || authorizations.length }
						isLoading={ isLoading }
						// Client 11.1.0 `transactions/uncaptured/index.tsx:257-272`: no search on this card.
						search={ false }
						searchLabel={ __(
							'Search uncaptured transactions',
							'woocommerce'
						) }
						title={ __( 'Uncaptured transactions', 'woocommerce' ) }
						summary={ summaryItems }
						// Client 11.1.0 `transactions/uncaptured/index.tsx:81-86`.
						numericFields={ [ 'amount' ] }
						getItemId={ getAuthorizationPaymentIntentId }
					/>
				</section>
			) }
		</div>
	);
};
