/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
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
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	cancelWooPaymentsAuthorization,
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
import {
	formatAmount,
	formatDate,
	formatLabel,
	getErrorMessage,
	getTransactionDetailsRoute,
} from './utils';
import { LiveStatusMessage, StatusMessage } from './table';
import { usePersistedHiddenFields } from './view-preferences';
import {
	getSettingsPaymentsProviderAdminPath,
	getSettingsPaymentsProviderRouteUrl,
} from '../utils';
import { SpotlightPromotion } from '../../promotions/spotlight';
import '../style.scss';

type MoneyMovementSummary = WooPaymentsAuthorizationsSummary;
type AuthorizationAction = 'capture' | 'cancel';
type PendingAuthorizationAction = {
	action: AuthorizationAction;
	paymentIntentId: string;
} | null;
type WorkingMoneyMovementView = {
	key: string;
	view: WooPaymentsMoneyMovementDataView;
} | null;
type NoticeDispatch = {
	createSuccessNotice: ( message: string ) => void;
	createErrorNotice: ( message: string ) => void;
};

// The uncaptured list's fields and the client's column names for them.
const AUTHORIZATION_FIELDS = [
	'authorized_date',
	'capture_by',
	'order',
	'risk',
	'amount',
	'customer',
	'actions',
];
const AUTHORIZATION_COLUMN_KEYS = {
	authorized_date: 'created',
	risk: 'risk_level',
	actions: 'action',
};

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

const shouldShowSummaryTotal = ( summary: MoneyMovementSummary ) =>
	! (
		'all_currencies' in summary &&
		Array.isArray( summary.all_currencies ) &&
		summary.all_currencies.length > 1
	);

const getAuthorizationPaymentIntentId = ( item: WooPaymentsAuthorization ) =>
	item.payment_intent_id || item.id || '';

const getAuthorizationOrderId = ( item: WooPaymentsAuthorization ) => {
	const orderId = item.order_id;

	return typeof orderId === 'number' || typeof orderId === 'string'
		? String( orderId )
		: '';
};

const getAuthorizationCaptureBy = ( value?: string | number ) => {
	if ( ! value ) {
		return '-';
	}

	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;
	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	date.setUTCDate( date.getUTCDate() + 7 );

	return formatDate( date.toISOString() );
};

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
	const isUncaptured = useMemo( () => {
		const params = new URLSearchParams( location.search );

		return (
			params.get( 'tab' ) === 'uncaptured' ||
			params.get( 'view' ) === 'uncaptured'
		);
	}, [ location.search ] );
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
	const isMountedRef = useRef( false );
	const uncapturedCountRequestIdRef = useRef( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ pendingAuthorizationAction, setPendingAuthorizationAction ] =
		useState< PendingAuthorizationAction >( null );
	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_transactions_uncaptured_hidden_columns',
		AUTHORIZATION_FIELDS,
		AUTHORIZATION_COLUMN_KEYS
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
				titleField: 'order',
				showTitle: false,
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
			}
		} catch {
			if (
				isMountedRef.current &&
				requestId === uncapturedCountRequestIdRef.current
			) {
				// Keep the active transactions view usable when its count fails.
				setUncapturedCount( null );
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

			try {
				const [ response, nextSummary ] = await Promise.all( [
					getWooPaymentsAuthorizations( resourceQuery ),
					getWooPaymentsAuthorizationsSummary( resourceQuery ),
				] );

				if ( canUpdate() ) {
					setAuthorizations( response.data || [] );
					setTotalCount(
						response.total_count ??
							getSummaryCount( nextSummary ) ??
							0
					);
					setSummary( nextSummary );
					setErrorMessage( null );
				}
			} catch ( error ) {
				if ( canUpdate() ) {
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments uncaptured transactions.',
								'woocommerce'
							)
						)
					);
				}
			} finally {
				if ( setLoading && canUpdate() ) {
					setIsLoading( false );
				}
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
	const handleAuthorizationAction = async (
		authorization: WooPaymentsAuthorization,
		action: AuthorizationAction
	) => {
		const paymentIntentId =
			getAuthorizationPaymentIntentId( authorization );
		const orderId = Number( getAuthorizationOrderId( authorization ) );

		if ( action === 'capture' ) {
			// Client 11.1.0 `transactions/uncaptured/index.tsx:193-198`.
			recordEvent(
				'payments_transactions_uncaptured_list_capture_charge_button_click',
				{ payment_intent_id: paymentIntentId }
			);
		}

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

		setPendingAuthorizationAction( {
			action,
			paymentIntentId,
		} );

		try {
			if ( action === 'capture' ) {
				await captureWooPaymentsAuthorization(
					orderId,
					paymentIntentId
				);
				await Promise.all( [
					loadMoneyMovement( { setLoading: false } ),
					loadUncapturedCount(),
				] );
				setPendingAuthorizationAction( null );
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
			} else {
				await cancelWooPaymentsAuthorization(
					orderId,
					paymentIntentId
				);
				await Promise.all( [
					loadMoneyMovement( { setLoading: false } ),
					loadUncapturedCount(),
				] );
				setPendingAuthorizationAction( null );
				getNotices().createSuccessNotice(
					sprintf(
						/* translators: %s: order ID. */
						__(
							'Payment for order #%s canceled successfully.',
							'woocommerce'
						),
						String( orderId )
					)
				);
			}
		} catch ( error ) {
			setPendingAuthorizationAction( null );
			getNotices().createErrorNotice(
				sprintf(
					/* translators: 1: action name, 2: order ID, 3: error message. */
					__(
						'Unable to %1$s authorization for order #%2$s. %3$s',
						'woocommerce'
					),
					action,
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
			id: 'authorized_date',
			label: __( 'Authorized date', 'woocommerce' ),
			enableHiding: true,
			render: ( { item }: { item: WooPaymentsAuthorization } ) =>
				formatDate( item.created ),
		},
		{
			id: 'capture_by',
			label: __( 'Capture by', 'woocommerce' ),
			enableHiding: true,
			render: ( { item }: { item: WooPaymentsAuthorization } ) =>
				getAuthorizationCaptureBy( item.created ),
		},
		{
			id: 'order',
			label: __( 'Order', 'woocommerce' ),
			enableHiding: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => {
				const orderId = getAuthorizationOrderId( item );

				if ( ! orderId ) {
					return '-';
				}

				const paymentIntentId = getAuthorizationPaymentIntentId( item );

				if ( ! paymentIntentId ) {
					return `#${ orderId }`;
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
						{ `#${ orderId }` }
					</a>
				);
			},
		},
		{
			id: 'risk',
			label: __( 'Risk', 'woocommerce' ),
			enableHiding: true,
			render: ( { item }: { item: WooPaymentsAuthorization } ) =>
				formatLabel(
					item.risk_level === undefined
						? undefined
						: String( item.risk_level )
				),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'woocommerce' ),
			enableHiding: true,
			render: ( { item }: { item: WooPaymentsAuthorization } ) =>
				formatAmount( item.amount, item.currency ),
		},
		{
			id: 'customer',
			label: __( 'Customer', 'woocommerce' ),
			enableHiding: true,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) =>
				item.customer_name || item.customer_email || '-',
		},
		{
			id: 'actions',
			label: __( 'Actions', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			render: ( { item }: { item: WooPaymentsAuthorization } ) => {
				const paymentIntentId = getAuthorizationPaymentIntentId( item );
				const orderId = getAuthorizationOrderId( item );
				const pending =
					pendingAuthorizationAction?.paymentIntentId ===
					paymentIntentId;
				const pendingAction = pending
					? pendingAuthorizationAction?.action
					: null;

				return (
					<div className="woocommerce-woopayments-money-movement__row-actions">
						<Button
							variant="primary"
							isBusy={ pendingAction === 'capture' }
							disabled={ pending }
							onClick={ () =>
								handleAuthorizationAction( item, 'capture' )
							}
							aria-label={
								pendingAction === 'capture'
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
						<Button
							variant="secondary"
							isDestructive
							isBusy={ pendingAction === 'cancel' }
							disabled={ pending }
							onClick={ () =>
								handleAuthorizationAction( item, 'cancel' )
							}
							aria-label={
								pendingAction === 'cancel'
									? sprintf(
											/* translators: %s: order ID. */
											__(
												'Canceling authorization for order #%s',
												'woocommerce'
											),
											orderId
									  )
									: sprintf(
											/* translators: %s: order ID. */
											__(
												'Cancel authorization for order #%s',
												'woocommerce'
											),
											orderId
									  )
							}
						>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
					</div>
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

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	} else if ( authorizations.length === 0 ) {
		liveStatusMessage = emptyMessage;
	}

	const summaryCount = getSummaryCount( summary ) ?? totalCount;
	const summaryTotal = getSummaryTotal( summary );
	const summaryCurrency = getSummaryCurrency( summary );
	const tabTransactionsUrl = getSettingsPaymentsProviderRouteUrl(
		'/woopayments/transactions'
	);
	const tabUncapturedUrl = getSettingsPaymentsProviderRouteUrl(
		'/woopayments/transactions?view=uncaptured'
	);
	const tabBlockedUrl = getSettingsPaymentsProviderRouteUrl(
		'/woopayments/transactions?view=blocked'
	);
	const uncapturedTabLabel = sprintf(
		/* translators: %1$s: number of uncaptured authorizations, or an ellipsis while loading. */
		__( 'Uncaptured (%1$s)', 'woocommerce' ),
		uncapturedCount === null ? '…' : String( uncapturedCount )
	);
	const summaryCountLabel = sprintf(
		/* translators: %d: uncaptured transactions count. */
		__( '%d uncaptured transactions', 'woocommerce' ),
		summaryCount
	);

	// Client 11.1.0 `transactions/index.tsx:78-102`: the Blocked tab always shows.
	const tabsNav = (
		<nav
			className="woocommerce-woopayments-money-movement__tabs"
			aria-label={ __( 'Transaction views', 'woocommerce' ) }
		>
			<a
				href={ tabTransactionsUrl }
				aria-current={ isUncaptured || isBlocked ? undefined : 'page' }
			>
				{ __( 'Transactions', 'woocommerce' ) }
			</a>
			<a
				href={ tabUncapturedUrl }
				aria-current={ isUncaptured ? 'page' : undefined }
			>
				{ uncapturedTabLabel }
			</a>
			<a
				href={ tabBlockedUrl }
				aria-current={ isBlocked ? 'page' : undefined }
			>
				{ __( 'Blocked', 'woocommerce' ) }
			</a>
		</nav>
	);

	if ( isBlocked ) {
		return (
			<div className="woocommerce-woopayments-money-movement">
				{ /* Client 11.1.0 transactions/index.tsx:105, above every tab. */ }
				<WooPaymentsTestModeNotice currentPage="transactions" />
				<SpotlightPromotion />
				<section>
					<h2>{ __( 'Blocked transactions', 'woocommerce' ) }</h2>
					{ tabsNav }
					<WooPaymentsBlockedTransactions />
				</section>
			</div>
		);
	}

	if ( ! isUncaptured ) {
		return (
			<div className="woocommerce-woopayments-money-movement">
				<WooPaymentsTestModeNotice currentPage="transactions" />
				<SpotlightPromotion />
				<section>
					<h2>{ __( 'Transactions', 'woocommerce' ) }</h2>
					{ tabsNav }
					<WooPaymentsTransactionsList
						buildRoute={ buildTransactionsRoute }
					/>
				</section>
			</div>
		);
	}

	return (
		<div className="woocommerce-woopayments-money-movement">
			<WooPaymentsTestModeNotice currentPage="transactions" />
			<SpotlightPromotion />
			<section aria-busy={ isLoading }>
				<h2>{ __( 'Uncaptured transactions', 'woocommerce' ) }</h2>
				{ tabsNav }
				<LiveStatusMessage isError={ !! errorMessage }>
					{ liveStatusMessage }
				</LiveStatusMessage>
				{ isLoading && (
					<StatusMessage>{ loadingMessage }</StatusMessage>
				) }
				{ errorMessage && (
					<StatusMessage isError>{ errorMessage }</StatusMessage>
				) }
				<div className="woocommerce-woopayments-money-movement__summary">
					<span>{ summaryCountLabel }</span>
					{ typeof summaryTotal === 'number' &&
						shouldShowSummaryTotal( summary ) && (
							<span>
								{ formatAmount(
									summaryTotal,
									summaryCurrency
								) }
							</span>
						) }
				</div>
				<WooPaymentsMoneyMovementDataViews
					fields={ isLoading ? [] : authorizationFields }
					rows={ authorizations }
					view={ view }
					onChangeView={ handleViewChange }
					total={ totalCount || authorizations.length }
					isLoading={ isLoading }
					searchLabel={ __(
						'Search uncaptured transactions',
						'woocommerce'
					) }
					empty={ emptyMessage }
					getItemId={ getAuthorizationPaymentIntentId }
				/>
			</section>
		</div>
	);
};
