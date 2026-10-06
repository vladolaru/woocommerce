/**
 * External dependencies
 */
import {
	Button,
	DropdownMenu,
	ExternalLink,
	MenuGroup,
	MenuItem,
	Modal,
	RadioControl,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { moreVertical } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import moment from 'moment';
import { useLocation } from 'react-router-dom';

/**
 * Internal dependencies
 */
import {
	cancelWooPaymentsAuthorization,
	captureWooPaymentsAuthorization,
	getWooPaymentsAuthorization,
	getWooPaymentsCharge,
	getWooPaymentsPaymentIntent,
	getWooPaymentsTimeline,
	getWooPaymentsTransaction,
	refundWooPaymentsCharge,
} from './data';
import type {
	WooPaymentsAuthorization,
	WooPaymentsCharge,
	WooPaymentsDispute,
	WooPaymentsPaymentIntent,
	WooPaymentsTimelineEvent,
	WooPaymentsTransaction,
} from './types';
import {
	formatAmount,
	getBankName,
	getDisputeId,
	getErrorMessage,
} from './utils';
import {
	getChargeDisputes,
	getDisputeOrdinals,
	isDisputeAwaitingResponse,
	isDisputeInquiry,
	isDisputeRefundable,
} from './dispute-utils';
import { WooPaymentsDisputeOutcome } from './dispute-recommendations';
import { navigateToSettingsPaymentsProviderRoute } from '../utils';
import { WooPaymentsTransactionDisputeDetails } from './transaction-dispute-details';
import {
	isPaymentOrderMissing,
	WooPaymentsMissingOrderNotice,
	WooPaymentsPaymentDetailsPlaceholder,
	WooPaymentsPaymentMethodDetailsSection,
	WooPaymentsPaymentSummarySection,
	WooPaymentsSummaryCardNotice,
} from './transaction-detail-sections';
import { WooPaymentsCardReaderFeeDetails } from './transaction-card-reader-fee-details';
import { LiveStatusMessage, StatusMessage } from './table';
import { WooPaymentsTransactionTimeline } from './transaction-timeline';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import '../style.scss';

type AuthorizationAction = 'capture' | 'cancel';
type RefundReason =
	| 'duplicate'
	| 'fraudulent'
	| 'requested_by_customer'
	| 'other'
	| null;
type PendingAuthorizationAction = {
	action: AuthorizationAction;
	paymentIntentId: string;
	routeKey: string;
} | null;
type PendingRefundAction = {
	paymentIntentId: string;
	routeKey: string;
} | null;
type NoticeDispatch = {
	createSuccessNotice: ( message: string ) => void;
	createErrorNotice: ( message: string ) => void;
};
type LoadTransactionOptions = {
	shouldUpdate?: () => boolean;
	setLoading?: boolean;
};

const CAPTURE_DOCUMENTATION_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/authorize-and-capture/#capturing-authorized-payments';
const DETAIL_ACTION_FOCUS_SELECTOR =
	'.woocommerce-woopayments-payment-summary__review-actions, .woocommerce-woopayments-payment-summary__notice, .woocommerce-woopayments-money-movement__refund-actions, .woocommerce-woopayments-money-movement__refund-modal, .woocommerce-woopayments-money-movement__dispute-response';
const REFUND_DIALOG_ID = 'woocommerce-woopayments-refund-dialog';

const isPaymentIntentId = ( id: string ) => id.startsWith( 'pi_' );

const isChargeId = ( id: string ) =>
	id.startsWith( 'ch_' ) || id.startsWith( 'py_' );

const isTransactionId = ( id: string ) => id.startsWith( 'txn_' );

const getBalanceTransactionId = (
	balanceTransaction?: WooPaymentsCharge[ 'balance_transaction' ]
) => {
	if ( typeof balanceTransaction === 'string' ) {
		return balanceTransaction;
	}

	return balanceTransaction?.id || '';
};

const getIntentCharge = ( intent: WooPaymentsPaymentIntent ) =>
	intent.charge || intent.charges?.data?.[ 0 ] || {};

const getNotices = () =>
	dispatch( 'core/notices' ) as unknown as NoticeDispatch;

const getErrorStatus = ( error: unknown ) => {
	if ( ! error || typeof error !== 'object' ) {
		return undefined;
	}

	if ( 'status' in error && typeof error.status === 'number' ) {
		return error.status;
	}

	if ( 'data' in error && error.data && typeof error.data === 'object' ) {
		const data = error.data;
		if ( 'status' in data && typeof data.status === 'number' ) {
			return data.status;
		}
	}

	return undefined;
};

const isNotFoundError = ( error: unknown ) => getErrorStatus( error ) === 404;

const getPaymentIntentId = (
	transaction: WooPaymentsTransaction,
	fallbackId: string
) =>
	transaction.payment_intent_id ||
	( fallbackId.startsWith( 'pi_' ) ? fallbackId : '' );

const getOrderId = (
	transaction: WooPaymentsTransaction,
	authorization: WooPaymentsAuthorization | null
) => {
	const orderId = Number( authorization?.order_id ?? transaction.order?.id );

	return Number.isFinite( orderId ) && orderId > 0 ? orderId : 0;
};

const getOrderFraudMetaBoxType = ( transaction: WooPaymentsTransaction ) => {
	const fraudMetaBoxType = transaction.order?.fraud_meta_box_type;

	return typeof fraudMetaBoxType === 'string' ? fraudMetaBoxType : '';
};

const isFraudReviewTransaction = ( transaction: WooPaymentsTransaction ) =>
	transaction.status === 'requires_capture' &&
	getOrderFraudMetaBoxType( transaction ) === 'review';

const isAuthorizationEligible = (
	transaction: WooPaymentsTransaction,
	paymentIntentId: string
) => {
	if ( ! paymentIntentId || transaction.captured === true ) {
		return false;
	}

	if (
		transaction.refunded ||
		( typeof transaction.amount_refunded === 'number' &&
			transaction.amount_refunded > 0 )
	) {
		return false;
	}

	return (
		transaction.status === 'requires_capture' ||
		transaction.captured === false
	);
};

const getTransactionOrderId = ( transaction: WooPaymentsTransaction ) => {
	const orderId = Number( transaction.order?.id );

	if ( Number.isFinite( orderId ) && orderId > 0 ) {
		return orderId;
	}

	const orderUrl =
		typeof transaction.order?.url === 'string' ? transaction.order.url : '';

	if ( ! orderUrl ) {
		return 0;
	}

	try {
		const url = new URL( orderUrl, 'http://example.com' );
		const orderIdFromUrl = Number(
			url.searchParams.get( 'id' ) || url.searchParams.get( 'post' )
		);

		return Number.isFinite( orderIdFromUrl ) && orderIdFromUrl > 0
			? orderIdFromUrl
			: 0;
	} catch {
		return 0;
	}
};

const getTransactionOrderUrl = ( transaction: WooPaymentsTransaction ) =>
	typeof transaction.order?.url === 'string' ? transaction.order.url : '';

const isTransactionPartiallyRefunded = (
	transaction: WooPaymentsTransaction
) =>
	typeof transaction.amount_refunded === 'number' &&
	transaction.amount_refunded > 0;

// Client 11.1.0 `payment-details/summary/index.tsx:370-384`: the actions menu needs a captured,
// unrefunded charge whose disputes all allow a refund; a linked order is not required.
const isTransactionRefundEligible = ( transaction: WooPaymentsTransaction ) => {
	if ( transaction.captured !== true || transaction.refunded ) {
		return false;
	}

	return getChargeDisputes( transaction ).every( isDisputeRefundable );
};

const getTimelineId = (
	transaction: WooPaymentsTransaction,
	routeId: string
) => {
	const orderId = transaction.order?.id;

	return (
		getPaymentIntentId( transaction, routeId ) || String( orderId || '' )
	);
};

const normalizeCharge = (
	charge: WooPaymentsCharge,
	fallbackId: string,
	transactionId: string
): WooPaymentsTransaction => {
	const balanceTransactionId = getBalanceTransactionId(
		charge.balance_transaction
	);
	return {
		id: transactionId || balanceTransactionId || charge.id || fallbackId,
		transaction_id: transactionId || balanceTransactionId,
		charge_id: charge.id,
		type: charge.type || 'charge',
		amount: charge.amount,
		currency: charge.currency,
		created: charge.created,
		date: charge.date,
		payment_intent_id: charge.payment_intent,
		billing_details: charge.billing_details,
		customer_name: charge.billing_details?.name,
		customer_email: charge.billing_details?.email,
		metadata: charge.metadata,
		sales_channel: charge.sales_channel,
		order: charge.order,
		payment_method: charge.payment_method,
		payment_method_details: charge.payment_method_details,
		outcome: charge.outcome,
		dispute: charge.dispute,
		disputes: charge.disputes,
		balance_transaction: charge.balance_transaction,
		application_fee_amount: charge.application_fee_amount,
		amount_refunded: charge.amount_refunded,
		refunded: charge.refunded,
		refunds: charge.refunds,
		disputed: charge.disputed,
		fee_breakdown_v1: charge.fee_breakdown_v1,
		captured: charge.captured,
		paydown: charge.paydown,
		status: charge.status,
	};
};

const normalizePaymentIntent = (
	intent: WooPaymentsPaymentIntent,
	fallbackId: string,
	transactionId: string
): WooPaymentsTransaction => {
	const transaction = normalizeCharge(
		getIntentCharge( intent ),
		fallbackId,
		transactionId
	);

	return {
		...transaction,
		id: transaction.id || intent.id || fallbackId,
		type: transaction.type || 'charge',
		amount: transaction.amount ?? intent.amount,
		currency: transaction.currency || intent.currency,
		created: transaction.created || intent.created,
		metadata: {
			...( intent.metadata || {} ),
			...( transaction.metadata || {} ),
		},
		payment_intent_id: intent.id,
		order: transaction.order || intent.order,
		dispute: transaction.dispute || intent.dispute,
		disputes: transaction.disputes?.length
			? transaction.disputes
			: intent.disputes,
		sales_channel:
			transaction.sales_channel || intent.sales_channel || undefined,
		status:
			intent.status === 'requires_capture'
				? intent.status
				: transaction.status || intent.status,
	};
};

// Client 11.1.0 `payment-details/summary/index.tsx:944-959`: an authorization must be captured within 7 days.
const getCaptureDeadline = ( authorization: WooPaymentsAuthorization ) =>
	moment.utc( authorization.created ).add( 7, 'days' );

/**
 * Time left until the capture deadline, as moment's `fromNow( true )` reads it with the client's
 * relative-time strings (client 11.1.0 `payment-details/summary/index.tsx:433-445`). Built here
 * instead of mutating moment's shared `en` locale, which other admin screens also read.
 *
 * @param deadline Capture deadline.
 */
const formatCaptureTimeLeft = ( deadline: moment.Moment ) => {
	const duration = moment.duration( { from: moment(), to: deadline } ).abs();
	const round = moment.relativeTimeRounding();
	const count = ( unit: moment.unitOfTime.Base ) =>
		round( duration.as( unit ) );
	const threshold = ( key: string ) =>
		Number( moment.relativeTimeThreshold( key ) );

	if ( count( 's' ) <= threshold( 'ss' ) ) {
		return __( 'a second', 'woocommerce' );
	}
	if ( count( 's' ) < threshold( 's' ) ) {
		/* translators: %d: number of seconds. */
		return sprintf( __( '%d seconds', 'woocommerce' ), count( 's' ) );
	}
	if ( count( 'm' ) <= 1 ) {
		return __( 'a minute', 'woocommerce' );
	}
	if ( count( 'm' ) < threshold( 'm' ) ) {
		/* translators: %d: number of minutes. */
		return sprintf( __( '%d minutes', 'woocommerce' ), count( 'm' ) );
	}
	if ( count( 'h' ) <= 1 ) {
		return __( 'an hour', 'woocommerce' );
	}
	if ( count( 'h' ) < threshold( 'h' ) ) {
		/* translators: %d: number of hours. */
		return sprintf( __( '%d hours', 'woocommerce' ), count( 'h' ) );
	}
	if ( count( 'd' ) <= 1 ) {
		return __( 'a day', 'woocommerce' );
	}
	if ( count( 'd' ) < threshold( 'd' ) ) {
		/* translators: %d: number of days. */
		return sprintf( __( '%d days', 'woocommerce' ), count( 'd' ) );
	}
	if ( count( 'M' ) <= 1 ) {
		return __( 'a month', 'woocommerce' );
	}
	if ( count( 'M' ) < threshold( 'M' ) ) {
		/* translators: %d: number of months. */
		return sprintf( __( '%d months', 'woocommerce' ), count( 'M' ) );
	}
	if ( count( 'y' ) <= 1 ) {
		return __( 'a year', 'woocommerce' );
	}
	/* translators: %d: number of years. */
	return sprintf( __( '%d years', 'woocommerce' ), count( 'y' ) );
};

// Client 11.1.0 `utils/date-time.ts` `formatDateTimeFromString( ..., { includeTime: true } )`: site formats and timezone.
const formatCaptureDeadline = ( deadline: moment.Moment ) => {
	const { formats } = getDateSettings();

	return dateI18n(
		`${ formats.date } / ${ formats.time }`,
		deadline.toISOString(),
		undefined
	);
};

const setRefundDialogId = ( overlay: HTMLDivElement | null ) => {
	overlay
		?.querySelector( '[role="dialog"]' )
		?.setAttribute( 'id', REFUND_DIALOG_ID );
};

const RefundModal = ( {
	formattedAmount,
	isOpenInquiry,
	isRefundPending,
	orderUrl,
	reason,
	onChangeReason,
	onClose,
	onRefund,
}: {
	formattedAmount: string;
	isOpenInquiry: boolean;
	isRefundPending: boolean;
	orderUrl: string;
	reason: RefundReason;
	onChangeReason: ( value: RefundReason ) => void;
	onClose: () => void;
	onRefund: () => void;
} ) => (
	<Modal
		ref={ setRefundDialogId }
		className="woocommerce-woopayments-money-movement__refund-modal"
		title={ __( 'Refund transaction', 'woocommerce' ) }
		onRequestClose={ onClose }
	>
		{ isOpenInquiry && (
			<p>
				{ __(
					'Issuing a refund will close the inquiry, returning the amount in question back to the cardholder. No additional fees apply.',
					'woocommerce'
				) }
			</p>
		) }
		<p>
			{ createInterpolateElement(
				sprintf(
					/* translators: %s: formatted refund amount. */
					__(
						'This will issue a full refund of <strong>%s</strong> to the customer.',
						'woocommerce'
					),
					formattedAmount
				),
				{
					strong: <strong />,
				}
			) }
		</p>
		<RadioControl
			className="woocommerce-woopayments-money-movement__refund-reason"
			label={ __( 'Select a reason (optional)', 'woocommerce' ) }
			selected={ reason || undefined }
			options={ [
				{
					label: __( 'Duplicate order', 'woocommerce' ),
					value: 'duplicate',
				},
				{
					label: __( 'Fraudulent', 'woocommerce' ),
					value: 'fraudulent',
				},
				{
					label: __( 'Requested by customer', 'woocommerce' ),
					value: 'requested_by_customer',
				},
				{
					label: __( 'Other', 'woocommerce' ),
					value: 'other',
				},
			] }
			onChange={ ( value ) => onChangeReason( value as RefundReason ) }
		/>
		{ orderUrl && (
			<p className="woocommerce-woopayments-money-movement__refund-partial-link">
				{ createInterpolateElement(
					__(
						'Need to refund part of the order? <link>Go to the order</link>.',
						'woocommerce'
					),
					{
						link: (
							<a href={ orderUrl }>
								{ __( 'Go to the order', 'woocommerce' ) }
							</a>
						),
					}
				) }
			</p>
		) }
		<div className="woocommerce-woopayments-money-movement__refund-modal-actions">
			<Button variant="tertiary" onClick={ onClose }>
				{ __( 'Cancel', 'woocommerce' ) }
			</Button>
			<Button
				variant="primary"
				isBusy={ isRefundPending }
				disabled={ isRefundPending }
				accessibleWhenDisabled
				onClick={ isRefundPending ? undefined : onRefund }
				aria-label={
					isRefundPending
						? __( 'Refunding transaction', 'woocommerce' )
						: undefined
				}
			>
				{ __( 'Refund transaction', 'woocommerce' ) }
			</Button>
		</div>
	</Modal>
);

export const WooPaymentsTransactionDetailsPage = () => {
	const [ transaction, setTransaction ] =
		useState< WooPaymentsTransaction | null >( null );
	const [ timelineEvents, setTimelineEvents ] = useState<
		WooPaymentsTimelineEvent[]
	>( [] );
	const [ timelineErrorMessage, setTimelineErrorMessage ] = useState<
		string | null
	>( null );
	const [ authorizationErrorMessage, setAuthorizationErrorMessage ] =
		useState< string | null >( null );
	const [ authorization, setAuthorization ] =
		useState< WooPaymentsAuthorization | null >( null );
	const [ pendingAuthorizationAction, setPendingAuthorizationAction ] =
		useState< PendingAuthorizationAction >( null );
	const [ pendingRefundAction, setPendingRefundAction ] =
		useState< PendingRefundAction >( null );
	const [ isRefundModalOpen, setIsRefundModalOpen ] = useState( false );
	const [ refundTargetDispute, setRefundTargetDispute ] = useState<
		WooPaymentsDispute | undefined
	>( undefined );
	const [ refundReason, setRefundReason ] = useState< RefundReason >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const location = useLocation();
	const query = new URLSearchParams( location.search );
	const id = query.get( 'id' ) || '';
	const transactionId = query.get( 'transaction_id' ) || '';
	const transactionType = query.get( 'transaction_type' ) || '';
	const isCardReaderFeeRoute = transactionType === 'card_reader_fee';
	const routeKey = `${ location.pathname }${ location.search }`;
	const routeKeyRef = useRef( routeKey );
	const paymentDetailsHeadingRef = useRef< HTMLHeadingElement | null >(
		null
	);
	const refundActionsRef = useRef< HTMLDivElement | null >( null );
	const refundModalOpenerRef = useRef< HTMLElement | null >( null );
	const shouldFocusDetailsHeadingRef = useRef( false );

	// Client 11.1.0 `payment-details/index.tsx:24-35`, fraud order-note and meta-box links.
	const fraudLinkStatus = query.get( 'status_is' );
	const fraudLinkType = query.get( 'type_is' );
	useEffect( () => {
		if ( fraudLinkStatus && fraudLinkType ) {
			recordEvent( 'wcpay_fraud_protection_order_details_link_clicked', {
				status: fraudLinkStatus,
				type: fraudLinkType,
			} );
		}
	}, [ fraudLinkStatus, fraudLinkType ] );

	useEffect( () => {
		routeKeyRef.current = routeKey;
		setPendingAuthorizationAction( null );
		setPendingRefundAction( null );
		setIsRefundModalOpen( false );
		setRefundTargetDispute( undefined );
		setRefundReason( null );
	}, [ routeKey ] );

	useEffect( () => {
		if ( ! isCardReaderFeeRoute ) {
			return;
		}

		setTransaction( null );
		setTimelineEvents( [] );
		setTimelineErrorMessage( null );
		setAuthorization( null );
		setAuthorizationErrorMessage( null );
		setErrorMessage( null );
		setIsLoading( false );
	}, [ isCardReaderFeeRoute, routeKey ] );

	useEffect( () => {
		if (
			! shouldFocusDetailsHeadingRef.current ||
			pendingAuthorizationAction ||
			pendingRefundAction
		) {
			return;
		}

		shouldFocusDetailsHeadingRef.current = false;
		const heading = paymentDetailsHeadingRef.current;
		const activeElement = heading?.ownerDocument.activeElement;
		const shouldRestoreFocus =
			activeElement === heading?.ownerDocument.body ||
			( activeElement instanceof HTMLElement &&
				!! activeElement.closest( DETAIL_ACTION_FOCUS_SELECTOR ) );

		if ( shouldRestoreFocus ) {
			heading?.focus();
		}
	}, [
		authorization,
		errorMessage,
		pendingAuthorizationAction,
		pendingRefundAction,
		transaction,
	] );

	const loadTransaction = useCallback(
		async ( options: LoadTransactionOptions = {} ) => {
			const shouldUpdate = options.shouldUpdate || ( () => true );
			const shouldSetLoading = options.setLoading !== false;

			if ( shouldSetLoading ) {
				setIsLoading( true );
			}

			if ( ! id && ! transactionId ) {
				if ( shouldUpdate() ) {
					setAuthorization( null );
					setAuthorizationErrorMessage( null );
					setErrorMessage(
						__( 'A transaction ID is required.', 'woocommerce' )
					);
					setIsLoading( false );
				}
				return;
			}

			try {
				let nextTransaction: WooPaymentsTransaction;
				let nextTimelineEvents: WooPaymentsTimelineEvent[] = [];
				let nextTimelineErrorMessage: string | null = null;
				let nextAuthorization: WooPaymentsAuthorization | null = null;
				let nextAuthorizationErrorMessage: string | null = null;

				if ( isPaymentIntentId( id ) ) {
					nextTransaction = normalizePaymentIntent(
						await getWooPaymentsPaymentIntent( id ),
						id,
						transactionId
					);
				} else if ( isChargeId( id ) ) {
					const charge = await getWooPaymentsCharge( id );
					nextTransaction = normalizeCharge(
						charge,
						id,
						transactionId
					);

					// Client 11.1.0 payment-details/charge-details/index.tsx:47-61: the payment's own URL.
					if ( charge.payment_intent ) {
						navigateToSettingsPaymentsProviderRoute(
							`/woopayments/transactions/details?id=${ encodeURIComponent(
								charge.payment_intent
							) }`,
							{ replace: true }
						);
					}
				} else {
					nextTransaction = await getWooPaymentsTransaction(
						isTransactionId( id ) ? id : transactionId || id
					);
				}

				const nextPaymentIntentId = getPaymentIntentId(
					nextTransaction,
					id
				);
				const loadAuthorization = async () => {
					if (
						! isAuthorizationEligible(
							nextTransaction,
							nextPaymentIntentId
						)
					) {
						return;
					}

					try {
						const loadedAuthorization =
							await getWooPaymentsAuthorization(
								nextPaymentIntentId
							);
						nextAuthorization = loadedAuthorization?.captured
							? null
							: loadedAuthorization;
					} catch ( authorizationError ) {
						nextAuthorization = null;
						// Client 11.1.0 `data/authorizations/resolvers.ts:90-98`: its own copy, not the server's message.
						if ( ! isNotFoundError( authorizationError ) ) {
							nextAuthorizationErrorMessage = __(
								'Error retrieving authorization.',
								'woocommerce'
							);
						}
					}
				};
				const loadTimeline = async () => {
					const timelineId = getTimelineId( nextTransaction, id );
					if ( ! timelineId ) {
						return;
					}

					try {
						const timeline =
							await getWooPaymentsTimeline( timelineId );
						nextTimelineEvents = timeline?.data || [];
					} catch ( timelineError ) {
						nextTimelineErrorMessage = getErrorMessage(
							timelineError,
							__(
								'Unable to load WooPayments payment timeline.',
								'woocommerce'
							)
						);
					}
				};

				// The authorization and the timeline only need the transaction, so they load together, as the client's
				// separate data hooks do.
				await Promise.all( [ loadAuthorization(), loadTimeline() ] );

				if ( shouldUpdate() ) {
					setTransaction( nextTransaction );
					setAuthorization( nextAuthorization );
					setAuthorizationErrorMessage(
						nextAuthorizationErrorMessage
					);
					setTimelineEvents( nextTimelineEvents );
					setTimelineErrorMessage( nextTimelineErrorMessage );
					setErrorMessage( null );
				}
			} catch {
				if ( shouldUpdate() ) {
					setAuthorization( null );
					setAuthorizationErrorMessage( null );
					setTimelineEvents( [] );
					setTimelineErrorMessage( null );
					// Client 11.1.0 `payment-details/payment-details/index.tsx:50-64`: its own copy, not the server's message.
					setErrorMessage(
						__( 'Payment details not loaded', 'woocommerce' )
					);
					// Client 11.1.0 `data/payment-intents/resolvers.ts:18-31` and `data/charges/resolvers.js:16-29`.
					getNotices().createErrorNotice(
						__( 'Error retrieving transaction.', 'woocommerce' )
					);
				}
			} finally {
				if ( shouldUpdate() && shouldSetLoading ) {
					setIsLoading( false );
				}
			}
		},
		[ id, transactionId ]
	);

	useEffect( () => {
		let isMounted = true;

		if ( isCardReaderFeeRoute ) {
			return () => {
				isMounted = false;
			};
		}

		void loadTransaction( {
			shouldUpdate: () => isMounted,
		} );

		return () => {
			isMounted = false;
		};
	}, [ isCardReaderFeeRoute, loadTransaction ] );

	const loadingMessage: string = __(
		'Loading transaction details…',
		'woocommerce'
	);
	let liveStatusMessage: string = __(
		'Transaction details loaded.',
		'woocommerce'
	);

	if ( errorMessage ) {
		liveStatusMessage = errorMessage;
	} else if ( authorizationErrorMessage ) {
		liveStatusMessage = authorizationErrorMessage;
	} else if ( timelineErrorMessage ) {
		liveStatusMessage = timelineErrorMessage;
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	} else if ( transaction && isPaymentOrderMissing( transaction ) ) {
		liveStatusMessage = __(
			'Transaction details loaded. This transaction is not connected to order.',
			'woocommerce'
		);
	}

	const paymentIntentId = transaction
		? getPaymentIntentId( transaction, id )
		: '';
	const chargeId = transaction?.charge_id || '';
	const hasPaymentDetails = !! ( paymentIntentId || chargeId );
	const hasDetailPaymentSurface = isCardReaderFeeRoute || hasPaymentDetails;
	// The client's payment details route only takes payment ids, so it shows the notice while loading
	// and on error too (client payment-details/payment-details/index.tsx:54); a payment id in the URL
	// is known before the transaction loads.
	const isPaymentDetailsPage =
		hasDetailPaymentSurface || /^(pi|ch|py)_/.test( id );
	const showTestModeNotice = isPaymentDetailsPage;
	const orderId = transaction ? getOrderId( transaction, authorization ) : 0;
	const isFraudReview =
		!! transaction && isFraudReviewTransaction( transaction );
	const showAuthorizationActions =
		!! transaction && !! authorization && !! paymentIntentId && orderId > 0;
	const showFraudReviewActions = showAuthorizationActions && isFraudReview;
	// Client 11.1.0 `payment-details/summary/index.tsx:904-967`: the notice stays during fraud review, without its Capture button.
	const showCaptureNotice = showAuthorizationActions;
	const captureDeadline = authorization
		? getCaptureDeadline( authorization )
		: null;
	const wcSettings = window.wcSettings as
		| ( typeof window.wcSettings & {
				countries?: Record< string, string >;
		  } )
		| undefined;
	const countries = wcSettings?.countries || {};
	const refundOrderId = transaction
		? getTransactionOrderId( transaction )
		: 0;
	const refundOrderUrl = transaction
		? getTransactionOrderUrl( transaction )
		: '';
	const disputes = transaction ? getChargeDisputes( transaction ) : [];
	const disputeOrder = transaction
		? getDisputeOrdinals( transaction )
		: { orderById: {}, orderedDisputes: [], total: 0 };
	const defaultRefundInquiry = disputes.find(
		( dispute ) =>
			isDisputeInquiry( dispute ) && isDisputeAwaitingResponse( dispute )
	);
	const refundInquiry = refundTargetDispute || defaultRefundInquiry;
	const isOpenRefundInquiry =
		!! refundInquiry &&
		isDisputeInquiry( refundInquiry ) &&
		isDisputeAwaitingResponse( refundInquiry );
	const currentInquiryId =
		isOpenRefundInquiry && refundInquiry
			? getDisputeId( refundInquiry )
			: '';
	const fullRefundAmount = transaction?.amount;
	const hasValidFullRefundData =
		!! paymentIntentId &&
		!! chargeId &&
		typeof fullRefundAmount === 'number' &&
		Number.isFinite( fullRefundAmount ) &&
		fullRefundAmount > 0;
	const isRefundEligible =
		!! transaction && isTransactionRefundEligible( transaction );
	const isPartiallyRefunded =
		!! transaction && isTransactionPartiallyRefunded( transaction );
	const showFullRefundAction =
		isRefundEligible &&
		! isPartiallyRefunded &&
		hasValidFullRefundData &&
		( ! isOpenRefundInquiry || !! currentInquiryId );
	// Client 11.1.0 `payment-details/summary/index.tsx:376-378`: partial refunds happen on the order
	// page, so they need an order number.
	const showPartialRefundAction =
		isRefundEligible && !! transaction?.order?.number;
	const showRefundActions = showFullRefundAction || showPartialRefundAction;
	const pendingAction =
		pendingAuthorizationAction?.routeKey === routeKey &&
		pendingAuthorizationAction?.paymentIntentId === paymentIntentId
			? pendingAuthorizationAction.action
			: null;
	const isAuthorizationActionPending = !! pendingAction;
	const isRefundPending =
		pendingRefundAction?.routeKey === routeKey &&
		pendingRefundAction?.paymentIntentId === paymentIntentId;

	const focusRefundModalOpener = () => {
		const opener = refundModalOpenerRef.current;
		refundModalOpenerRef.current = null;

		if ( opener?.isConnected ) {
			opener.focus();
			return;
		}

		const actionButton =
			refundActionsRef.current?.querySelector( 'button' );
		if ( actionButton instanceof HTMLButtonElement ) {
			actionButton.focus();
		}
	};

	const handleRefundModalClose = () => {
		if ( isRefundPending ) {
			return;
		}

		setIsRefundModalOpen( false );
		recordEvent( 'payments_transactions_details_refund_modal_close', {
			payment_intent_id: paymentIntentId,
		} );
		window.setTimeout( focusRefundModalOpener, 0 );
	};

	const openRefundModal = ( opener?: HTMLElement ) => {
		refundModalOpenerRef.current = opener || null;
		setRefundReason( null );
		setRefundTargetDispute( undefined );
		setIsRefundModalOpen( true );
	};

	const handleRefundModalOpen = ( opener?: HTMLElement ) => {
		openRefundModal( opener );
		recordEvent( 'payments_transactions_details_refund_modal_open', {
			payment_intent_id: paymentIntentId,
		} );
	};

	const handleInquiryRefundModalOpen = ( dispute: WooPaymentsDispute ) => {
		const ownerDocument =
			refundActionsRef.current?.ownerDocument ||
			paymentDetailsHeadingRef.current?.ownerDocument;
		const activeElement = ownerDocument?.activeElement;
		refundModalOpenerRef.current =
			activeElement instanceof HTMLElement ? activeElement : null;
		setRefundReason( null );
		setRefundTargetDispute( dispute );
		setIsRefundModalOpen( true );
	};

	const handlePartialRefund = () => {
		if ( ! refundOrderUrl ) {
			return;
		}

		recordEvent( 'payments_transactions_details_partial_refund', {
			payment_intent_id: paymentIntentId,
			order_id: refundOrderId,
		} );
		window.location.href = refundOrderUrl;
	};

	const handleRefund = async () => {
		if ( isRefundPending ) {
			return;
		}

		if (
			! transaction ||
			! hasValidFullRefundData ||
			typeof fullRefundAmount !== 'number' ||
			( isOpenRefundInquiry && ! currentInquiryId )
		) {
			getNotices().createErrorNotice(
				__(
					'Unable to process this refund because the payment details are incomplete.',
					'woocommerce'
				)
			);
			return;
		}

		const refundRouteKey = routeKey;
		const isCurrentRefundRoute = () =>
			routeKeyRef.current === refundRouteKey;

		setPendingRefundAction( {
			paymentIntentId,
			routeKey: refundRouteKey,
		} );

		try {
			recordEvent( 'payments_transactions_details_refund_full', {
				payment_intent_id: paymentIntentId,
			} );

			if ( isOpenRefundInquiry && refundInquiry && currentInquiryId ) {
				recordEvent( 'wcpay_dispute_inquiry_refund_click', {
					dispute_id: currentInquiryId,
					dispute_status: refundInquiry.status,
					dispute_reason: refundInquiry.reason,
					on_page: 'transaction_details',
				} );
			}

			await refundWooPaymentsCharge( {
				chargeId,
				amount: fullRefundAmount,
				reason: refundReason === 'other' ? null : refundReason,
				// Client 11.1.0 `data/payment-intents/actions.ts:52-62` sends `charge.order?.id`, so an
				// order-less charge omits it and the route refunds the charge directly.
				orderId: refundOrderId || undefined,
			} );
			if ( ! isCurrentRefundRoute() ) {
				return;
			}

			await loadTransaction( {
				setLoading: false,
				shouldUpdate: isCurrentRefundRoute,
			} );
			if ( ! isCurrentRefundRoute() ) {
				return;
			}

			setIsRefundModalOpen( false );
			setRefundTargetDispute( undefined );
			setRefundReason( null );
			shouldFocusDetailsHeadingRef.current = true;
			getNotices().createSuccessNotice(
				sprintf(
					/* translators: %s: payment intent ID. */
					__( 'Refunded payment #%s.', 'woocommerce' ),
					paymentIntentId
				)
			);
		} catch ( error ) {
			const baseError = sprintf(
				/* translators: %s: payment intent ID. */
				__(
					'There has been an error refunding the payment #%s. Please try again later.',
					'woocommerce'
				),
				paymentIntentId
			);
			const errorDetail = getErrorMessage( error, '' );

			getNotices().createErrorNotice(
				errorDetail ? `${ baseError } ${ errorDetail }` : baseError
			);
		} finally {
			if ( isCurrentRefundRoute() ) {
				setPendingRefundAction( null );
			}
		}
	};

	const handleAuthorizationAction = async ( action: AuthorizationAction ) => {
		if ( isAuthorizationActionPending ) {
			return;
		}

		// Client 11.1.0 `payment-details/summary/index.tsx:685-743,909-925`.
		const tracksProperties = { payment_intent_id: paymentIntentId };
		if ( isFraudReview ) {
			recordEvent(
				action === 'capture'
					? 'wcpay_fraud_protection_transaction_reviewed_merchant_approved'
					: 'wcpay_fraud_protection_transaction_reviewed_merchant_blocked',
				tracksProperties
			);
		}
		recordEvent(
			action === 'capture'
				? 'payments_transactions_details_capture_charge_button_click'
				: 'payments_transactions_details_cancel_charge_button_click',
			tracksProperties
		);

		if ( ! paymentIntentId || ! orderId ) {
			getNotices().createErrorNotice(
				__(
					'Unable to process this authorization because the order details are incomplete.',
					'woocommerce'
				)
			);
			return;
		}

		const actionRouteKey = routeKey;
		const isCurrentActionRoute = () =>
			routeKeyRef.current === actionRouteKey;
		const activeElement =
			paymentDetailsHeadingRef.current?.ownerDocument.activeElement;
		const focusStartedInActionArea =
			activeElement instanceof HTMLElement &&
			!! activeElement.closest( DETAIL_ACTION_FOCUS_SELECTOR );

		setPendingAuthorizationAction( {
			action,
			paymentIntentId,
			routeKey: actionRouteKey,
		} );

		try {
			if ( action === 'capture' ) {
				await captureWooPaymentsAuthorization(
					orderId,
					paymentIntentId
				);
				if ( ! isCurrentActionRoute() ) {
					return;
				}
				await loadTransaction( {
					setLoading: false,
					shouldUpdate: isCurrentActionRoute,
				} );
				if ( ! isCurrentActionRoute() ) {
					return;
				}
				shouldFocusDetailsHeadingRef.current = focusStartedInActionArea;
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
				if ( ! isCurrentActionRoute() ) {
					return;
				}
				await loadTransaction( {
					setLoading: false,
					shouldUpdate: isCurrentActionRoute,
				} );
				if ( ! isCurrentActionRoute() ) {
					return;
				}
				shouldFocusDetailsHeadingRef.current = focusStartedInActionArea;
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
		} finally {
			if ( isCurrentActionRoute() ) {
				setPendingAuthorizationAction( null );
			}
		}
	};

	return (
		<section
			className="woocommerce-woopayments-money-movement woocommerce-woopayments-money-movement--details"
			aria-busy={ isLoading }
		>
			{ /* Client 11.1.0 payment-details/payment-details/index.tsx:54,75 and readers/index.js:37,137: first on the page, error view included. */ }
			{ showTestModeNotice && (
				<WooPaymentsTestModeNotice
					currentPage="payments"
					isDetailsView
				/>
			) }
			<div className="woocommerce-woopayments-money-movement__detail-header">
				<h2 ref={ paymentDetailsHeadingRef } tabIndex={ -1 }>
					{ isPaymentDetailsPage
						? __( 'Payment details', 'woocommerce' )
						: __( 'Transaction details', 'woocommerce' ) }
				</h2>
			</div>
			{ ! isCardReaderFeeRoute && (
				<LiveStatusMessage
					isError={ !! errorMessage || !! authorizationErrorMessage }
				>
					{ liveStatusMessage }
				</LiveStatusMessage>
			) }
			{ isLoading &&
				( isPaymentDetailsPage && ! isCardReaderFeeRoute ? (
					<div className="woocommerce-woopayments-money-movement__detail-sections">
						<WooPaymentsPaymentDetailsPlaceholder />
					</div>
				) : (
					<StatusMessage>{ loadingMessage }</StatusMessage>
				) ) }
			{ errorMessage && (
				<StatusMessage isError>{ errorMessage }</StatusMessage>
			) }
			{ isCardReaderFeeRoute && ! errorMessage && (
				<div className="woocommerce-woopayments-money-movement__detail-sections">
					<WooPaymentsCardReaderFeeDetails
						transactionId={ transactionId || id }
					/>
				</div>
			) }
			{ transaction && ! errorMessage && ! isCardReaderFeeRoute && (
				<>
					{ authorizationErrorMessage && (
						<StatusMessage isError>
							{ authorizationErrorMessage }
						</StatusMessage>
					) }
					<div className="woocommerce-woopayments-money-movement__detail-sections">
						<WooPaymentsPaymentSummarySection
							transaction={ transaction }
							paymentIntentId={ paymentIntentId }
							chargeId={ chargeId }
							actions={
								showRefundActions && (
									<div
										ref={ refundActionsRef }
										className="woocommerce-woopayments-money-movement__refund-actions"
									>
										<DropdownMenu
											icon={ moreVertical }
											label={ __(
												'Transaction actions',
												'woocommerce'
											) }
											popoverProps={ {
												position: 'bottom left',
											} }
										>
											{ ( { onClose } ) => (
												<MenuGroup>
													{ showFullRefundAction && (
														<MenuItem
															onClick={ () => {
																handleRefundModalOpen();
																onClose();
															} }
														>
															{ __(
																'Refund in full',
																'woocommerce'
															) }
														</MenuItem>
													) }
													{ showPartialRefundAction && (
														<MenuItem
															onClick={ () => {
																handlePartialRefund();
																onClose();
															} }
														>
															{ __(
																'Partial refund',
																'woocommerce'
															) }
														</MenuItem>
													) }
												</MenuGroup>
											) }
										</DropdownMenu>
									</div>
								)
							}
							reviewActions={
								showFraudReviewActions && (
									<div className="woocommerce-woopayments-payment-summary__review-actions">
										<Button
											variant="secondary"
											isDestructive
											isBusy={
												pendingAction === 'cancel'
											}
											disabled={
												isAuthorizationActionPending
											}
											accessibleWhenDisabled
											onClick={
												isAuthorizationActionPending
													? undefined
													: () =>
															handleAuthorizationAction(
																'cancel'
															)
											}
											aria-label={
												pendingAction === 'cancel'
													? sprintf(
															/* translators: %s: order ID. */
															__(
																'Blocking transaction for order #%s',
																'woocommerce'
															),
															String( orderId )
													  )
													: undefined
											}
										>
											{ __(
												'Block transaction',
												'woocommerce'
											) }
										</Button>
										<Button
											variant="primary"
											isBusy={
												pendingAction === 'capture'
											}
											disabled={
												isAuthorizationActionPending
											}
											accessibleWhenDisabled
											onClick={
												isAuthorizationActionPending
													? undefined
													: () =>
															handleAuthorizationAction(
																'capture'
															)
											}
											aria-label={
												pendingAction === 'capture'
													? sprintf(
															/* translators: %s: order ID. */
															__(
																'Approving transaction for order #%s',
																'woocommerce'
															),
															String( orderId )
													  )
													: undefined
											}
										>
											{ __(
												'Approve transaction',
												'woocommerce'
											) }
										</Button>
									</div>
								)
							}
						>
							{ disputeOrder.orderedDisputes.map(
								( dispute, index ) => {
									const disputeId = getDisputeId( dispute );

									return (
										<WooPaymentsTransactionDisputeDetails
											key={ `${
												disputeId || 'dispute'
											}-${ index }` }
											transaction={ transaction }
											dispute={ dispute }
											ordinal={
												disputeOrder.orderById[
													disputeId
												] || index + 1
											}
											total={ disputeOrder.total }
											onIssueRefund={
												showFullRefundAction
													? () =>
															handleInquiryRefundModalOpen(
																dispute
															)
													: undefined
											}
										/>
									);
								}
							) }
							<WooPaymentsMissingOrderNotice
								transaction={ transaction }
								// Client 11.1.0 `payment-details/summary/index.tsx:897-903` opens the modal with no open event.
								onRefund={ openRefundModal }
							/>
							{ showCaptureNotice && captureDeadline && (
								<WooPaymentsSummaryCardNotice
									actions={
										! isFraudReview && (
											<Button
												variant="primary"
												isBusy={
													pendingAction === 'capture'
												}
												disabled={
													isAuthorizationActionPending
												}
												accessibleWhenDisabled
												onClick={
													isAuthorizationActionPending
														? undefined
														: () =>
																handleAuthorizationAction(
																	'capture'
																)
												}
												aria-label={
													pendingAction === 'capture'
														? sprintf(
																/* translators: %s: order ID. */
																__(
																	'Capturing authorization for order #%s',
																	'woocommerce'
																),
																String(
																	orderId
																)
														  )
														: sprintf(
																/* translators: %s: order ID. */
																__(
																	'Capture authorization for order #%s',
																	'woocommerce'
																),
																String(
																	orderId
																)
														  )
												}
											>
												{ __(
													'Capture',
													'woocommerce'
												) }
											</Button>
										)
									}
								>
									{ createInterpolateElement(
										__(
											'You must <a>capture</a> this charge within the next',
											'woocommerce'
										),
										{
											a: (
												<ExternalLink
													href={
														CAPTURE_DOCUMENTATION_URL
													}
												>
													<></>
												</ExternalLink>
											),
										}
									) }{ ' ' }
									<abbr
										title={ formatCaptureDeadline(
											captureDeadline
										) }
									>
										<b>
											{ formatCaptureTimeLeft(
												captureDeadline
											) }
										</b>
									</abbr>
									{ isFraudReview &&
										`. ${ __(
											'Approving this transaction will capture the charge.',
											'woocommerce'
										) }` }
								</WooPaymentsSummaryCardNotice>
							) }
						</WooPaymentsPaymentSummarySection>
						{ /* Client 11.1.0 `payment-details/summary/index.tsx:1055-1066`: right after the summary. */ }
						<WooPaymentsDisputeOutcome disputes={ disputes } />
						<WooPaymentsTransactionTimeline
							events={ timelineEvents }
							hasError={ !! timelineErrorMessage }
							disputeOrder={ disputeOrder }
							onRefund={
								showFullRefundAction
									? handleRefundModalOpen
									: undefined
							}
							refundDialogId={ REFUND_DIALOG_ID }
							isRefundDialogOpen={ isRefundModalOpen }
							bankName={ getBankName(
								transaction.payment_method_details
							) }
						/>
						<WooPaymentsPaymentMethodDetailsSection
							transaction={ transaction }
							countries={ countries }
						/>
					</div>
					{ isRefundModalOpen && (
						<RefundModal
							formattedAmount={ formatAmount(
								transaction.amount,
								transaction.currency
							) }
							isOpenInquiry={ isOpenRefundInquiry }
							isRefundPending={ !! isRefundPending }
							orderUrl={ refundOrderUrl }
							reason={ refundReason }
							onChangeReason={ setRefundReason }
							onClose={ handleRefundModalClose }
							onRefund={ handleRefund }
						/>
					) }
				</>
			) }
		</section>
	);
};
