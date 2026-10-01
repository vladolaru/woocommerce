/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardDivider,
	CardFooter,
	CardHeader,
	Flex,
} from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import moment from 'moment';
import type { MouseEvent, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import {
	CARD_BRANDS,
	type WooPaymentsCardBrand,
} from '../../settings/payment-method-definitions';
import type {
	WooPaymentsBillingDetails,
	WooPaymentsPaymentMethodDetails,
	WooPaymentsTransaction,
} from './types';
import {
	formatAmount,
	formatExplicitCurrency,
	formatLabel,
	getChargeChannelLabel,
	getP24BankLabel,
	getTransactionSourceIconUrl,
	getTransactionSourceLabel,
} from './utils';
import {
	DISPUTE_STATUS_LABELS,
	getChargeDisputes,
	getDisputeBalanceAdjustments,
	getEffectiveDisputeFee,
	getPrimaryDispute,
	isDisputeAwaitingResponse,
} from './dispute-utils';
import {
	getChargeAmounts,
	getTransactionFeeBeforeDisputes,
} from './charge-amounts';
import {
	isWooPaymentsSubscriptionsActive,
	OrderLink,
} from './transactions-list-fields';
import {
	StatusChip,
	type StatusChipType,
} from '../overview/components/status-chip';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { HelpPopover } from '../overview/components/help-popover';
import './transaction-details.scss';

type CardDetails = NonNullable< WooPaymentsPaymentMethodDetails[ 'card' ] >;
type CountryMap = Record< string, string >;
type NonCardPaymentMethodDetails = Record< string, unknown >;
type NonCardPaymentMethodDetailField = {
	label: string;
	field: string;
	format?: 'last4' | 'country';
};

const hasDisplayValue = ( value: unknown ) =>
	value !== undefined && value !== null && value !== '';

const DetailRow = ( { label, value }: { label: string; value: ReactNode } ) => (
	<div>
		<dt>{ label }</dt>
		<dd>{ value }</dd>
	</div>
);

const Dash = () => (
	// role="img" gives the aria-label an element that assistive technology
	// reliably announces; a bare inline <span> label is often skipped.
	<span role="img" aria-label={ __( 'Unavailable', 'woocommerce' ) }>
		-
	</span>
);

// Client 11.1.0 `payment-details/summary/missing-order-notice/index.tsx:25-66` and `summary/index.tsx:897`.
// A deleted or unknown order arrives as `order: []`, which is truthy, so only a null or absent order counts as missing.
export const isPaymentOrderMissing = ( transaction: WooPaymentsTransaction ) =>
	! transaction.order;

const getCustomerName = ( transaction: WooPaymentsTransaction ) =>
	transaction.billing_details?.name ||
	transaction.order?.customer_name ||
	transaction.customer_name ||
	'';

const getCustomerEmail = ( transaction: WooPaymentsTransaction ) =>
	transaction.billing_details?.email ||
	transaction.order?.customer_email ||
	transaction.customer_email ||
	'';

// Client 11.1.0 `components/customer-link`: the name links to the transactions list searched for the customer.
const CustomerLink = ( {
	transaction,
}: {
	transaction: WooPaymentsTransaction;
} ) => {
	const name = getCustomerName( transaction );

	if ( ! name ) {
		return <>&ndash;</>;
	}

	const email = getCustomerEmail( transaction );

	return (
		<a
			href={ getSettingsPaymentsProviderRouteUrl(
				addQueryArgs( '/woopayments/transactions', {
					search: email ? `${ name } (${ email })` : name,
				} )
			) }
		>
			{ name }
		</a>
	);
};

const getPaymentMethodCardDetails = (
	method: WooPaymentsPaymentMethodDetails
): CardDetails | undefined => {
	const methodType = method.type;
	const typedDetails =
		methodType &&
		method[ methodType ] &&
		typeof method[ methodType ] === 'object'
			? ( method[ methodType ] as CardDetails )
			: undefined;

	return typedDetails || method.card;
};

const NETWORKS_DISPLAYED_OVER_CARD_BRAND = new Set( [
	'cartes_bancaires',
	'cb',
	'eftpos',
	'eftpos_au',
] );

const getPaymentMethodCardBrand = (
	card?: CardDetails
): WooPaymentsCardBrand | undefined => {
	const network = card?.network?.toLowerCase();
	const brand = card?.brand?.toLowerCase();
	const preferredId =
		network && NETWORKS_DISPLAYED_OVER_CARD_BRAND.has( network )
			? network
			: brand || network;
	const normalizedId =
		preferredId === 'cb' ? 'cartes_bancaires' : preferredId;

	return CARD_BRANDS.find( ( candidate ) => candidate.id === normalizedId );
};

const getPaymentMethodTypedDetails = (
	method: WooPaymentsPaymentMethodDetails
): NonCardPaymentMethodDetails => {
	const methodType = method.type;

	if (
		methodType &&
		method[ methodType ] &&
		typeof method[ methodType ] === 'object'
	) {
		return method[ methodType ] as NonCardPaymentMethodDetails;
	}

	return {};
};

export const getPaymentMethodLabel = (
	transaction: WooPaymentsTransaction
) => {
	const method = transaction.payment_method_details;
	const card = method ? getPaymentMethodCardDetails( method ) : undefined;

	if ( method?.type === 'card' && card?.brand && card.last4 ) {
		return sprintf(
			/* translators: 1: card brand, 2: last four card digits. */
			__( '%1$s ending in %2$s', 'woocommerce' ),
			formatLabel( card.brand ),
			card.last4
		);
	}

	if (
		( method?.type === 'card_present' ||
			method?.type === 'interac_present' ) &&
		card?.last4
	) {
		return sprintf(
			/* translators: %s: last four card digits. */
			__( 'Card ending in %s', 'woocommerce' ),
			card.last4
		);
	}

	return method?.type ? formatLabel( method.type ) : '';
};

const isCardPaymentMethodType = ( type?: string ) =>
	type === 'card' || type === 'card_present' || type === 'interac_present';

// Client 11.1.0 `components/payment-method-details/index.tsx:33-90` `formatDetails()`: the method field printed after "••••".
const LAST4_DETAIL_FIELDS: Record< string, string > = {
	au_becs_debit: 'last4',
	sepa_debit: 'last4',
	bancontact: 'iban_last4',
	ideal: 'iban_last4',
	eps: 'iban_last4',
	sofort: 'iban_last4',
};

const Last4 = ( { last4 }: { last4: string } ) => (
	<>
		<span aria-hidden="true">{ `•••• ${ last4 }` }</span>
		<span className="screen-reader-text">
			{ sprintf(
				/* translators: %s: last four digits of the card or account. */
				__( 'ending in %s', 'woocommerce' ),
				last4
			) }
		</span>
	</>
);

const SourceLogo = ( { source }: { source: string } ) => {
	const iconUrl = getTransactionSourceIconUrl( source );

	return iconUrl ? (
		<img
			className="woocommerce-woopayments-money-movement__card-brand"
			src={ iconUrl }
			alt={ getTransactionSourceLabel( source ) }
		/>
	) : null;
};

/**
 * A non-card payment method as the client's `PaymentMethodDetails` shows it: the method's logo, then its detail.
 * Client 11.1.0 `components/payment-method-details/index.tsx:136-192`.
 *
 * @param props        The component props.
 * @param props.method The charge's payment method details.
 */
const NonCardPaymentMethodSummary = ( {
	method,
}: {
	method: WooPaymentsPaymentMethodDetails;
} ) => {
	const type = method.type || '';
	const details = getPaymentMethodTypedDetails( method ) as Record<
		string,
		unknown
	>;

	if ( ! method[ type ] && type !== 'link' ) {
		return <>&ndash;</>;
	}

	const getText = ( value: unknown ) =>
		typeof value === 'string' || typeof value === 'number'
			? String( value )
			: '';
	const funding = details.funding as
		| { card?: { brand?: string; last4?: string } }
		| undefined;
	const fundingBrand = getText( funding?.card?.brand ).toLowerCase();
	const brand =
		getText( details.brand ) ||
		getText( details.network ) ||
		fundingBrand ||
		type;
	const last4 =
		type === 'amazon_pay'
			? getText( funding?.card?.last4 )
			: getText( details[ LAST4_DETAIL_FIELDS[ type ] ] );
	let detail: ReactNode = last4 ? <Last4 last4={ last4 } /> : null;

	if ( type === 'p24' ) {
		detail = getP24BankLabel( getText( details.bank ) );
	} else if ( type === 'giropay' ) {
		detail = getText( details.bank_code );
	}

	// Amazon Pay is its own wallet logo; its funding card's brand follows only when there is one.
	const logos = [
		type === 'amazon_pay' ? 'amazon_pay' : '',
		type !== 'amazon_pay' || fundingBrand ? brand : '',
	].filter( ( source ) => source && getTransactionSourceIconUrl( source ) );

	if ( ! logos.length ) {
		return <>{ getTransactionSourceLabel( type ) }</>;
	}

	return (
		<span className="woocommerce-woopayments-money-movement__card-summary">
			{ logos.map( ( source ) => (
				<SourceLogo key={ source } source={ source } />
			) ) }
			{ detail }
		</span>
	);
};

const PaymentMethodSummary = ( {
	transaction,
}: {
	transaction: WooPaymentsTransaction;
} ) => {
	const method = transaction.payment_method_details;
	const card = method ? getPaymentMethodCardDetails( method ) : undefined;
	const cardBrand = isCardPaymentMethodType( method?.type )
		? getPaymentMethodCardBrand( card )
		: undefined;

	if ( method?.type && ! isCardPaymentMethodType( method.type ) ) {
		return <NonCardPaymentMethodSummary method={ method } />;
	}

	if ( ! cardBrand || ! card?.last4 ) {
		return getPaymentMethodLabel( transaction ) || null;
	}

	return (
		<span className="woocommerce-woopayments-money-movement__card-summary">
			<img
				className="woocommerce-woopayments-money-movement__card-brand"
				// Client 11.1.0 draws this with the same `payment-method__brand--{brand}` sprite as the lists.
				src={
					getTransactionSourceIconUrl( cardBrand.id ) ||
					cardBrand.iconUrl
				}
				alt={ cardBrand.label }
			/>
			<span aria-hidden="true">{ `•••• ${ card.last4 }` }</span>
			<span className="screen-reader-text">
				{ sprintf(
					/* translators: %s: last four card digits. */
					__( 'ending in %s', 'woocommerce' ),
					card.last4
				) }
			</span>
		</span>
	);
};

const nonCardPaymentMethodDetailFields: Record<
	string,
	NonCardPaymentMethodDetailField[]
> = {
	amazon_pay: [
		{
			label: __( 'Amazon transaction ID', 'woocommerce' ),
			field: 'transaction_id',
		},
	],
	au_becs_debit: [
		{
			label: __( 'BSB', 'woocommerce' ),
			field: 'bsb_number',
		},
		{
			label: __( 'Account', 'woocommerce' ),
			field: 'last4',
			format: 'last4',
		},
	],
	bancontact: [
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank_name',
		},
		{
			label: __( 'BIC', 'woocommerce' ),
			field: 'bic',
		},
		{
			label: __( 'Verified name', 'woocommerce' ),
			field: 'verified_name',
		},
	],
	eps: [
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank',
		},
		{
			label: __( 'Verified name', 'woocommerce' ),
			field: 'verified_name',
		},
	],
	giropay: [
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank_name',
		},
		{
			label: __( 'BIC', 'woocommerce' ),
			field: 'bic',
		},
	],
	ideal: [
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank',
		},
		{
			label: __( 'BIC', 'woocommerce' ),
			field: 'bic',
		},
		{
			label: __( 'IBAN', 'woocommerce' ),
			field: 'iban_last4',
			format: 'last4',
		},
		{
			label: __( 'Verified name', 'woocommerce' ),
			field: 'verified_name',
		},
	],
	klarna: [
		{
			label: __( 'Category', 'woocommerce' ),
			field: 'payment_method_category',
		},
		{
			label: __( 'Preferred locale', 'woocommerce' ),
			field: 'preferred_locale',
		},
	],
	p24: [
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank',
		},
		{
			label: __( 'Reference', 'woocommerce' ),
			field: 'reference',
		},
		{
			label: __( 'Verified name', 'woocommerce' ),
			field: 'verified_name',
		},
	],
	sepa_debit: [
		{
			label: __( 'IBAN', 'woocommerce' ),
			field: 'last4',
			format: 'last4',
		},
		{
			label: __( 'Origin', 'woocommerce' ),
			field: 'country',
			format: 'country',
		},
	],
	sofort: [
		{
			label: __( 'Bank code', 'woocommerce' ),
			field: 'bank_code',
		},
		{
			label: __( 'Bank name', 'woocommerce' ),
			field: 'bank_name',
		},
		{
			label: __( 'BIC', 'woocommerce' ),
			field: 'bic',
		},
		{
			label: __( 'IBAN', 'woocommerce' ),
			field: 'iban_last4',
			format: 'last4',
		},
		{
			label: __( 'Verified name', 'woocommerce' ),
			field: 'verified_name',
		},
		{
			label: __( 'Origin', 'woocommerce' ),
			field: 'country',
			format: 'country',
		},
	],
};

const getCountryName = ( countryCode?: string, countries: CountryMap = {} ) => {
	if ( ! countryCode ) {
		return '';
	}

	return countries[ countryCode ] || countryCode;
};

const getPaymentMethodDetailValue = (
	methodDetails: NonCardPaymentMethodDetails,
	field: string,
	countries: CountryMap = {},
	format?: NonCardPaymentMethodDetailField[ 'format' ]
): ReactNode => {
	const value = methodDetails[ field ];

	if ( ! hasDisplayValue( value ) ) {
		return <Dash />;
	}

	if (
		typeof value === 'string' ||
		typeof value === 'number' ||
		typeof value === 'boolean'
	) {
		if ( format === 'country' ) {
			return getCountryName( String( value ), countries ) || <Dash />;
		}

		if ( format === 'last4' ) {
			return `•••• ${ value }`;
		}

		return String( value );
	}

	return <Dash />;
};

const getPaymentMethodTypeLabel = ( card?: CardDetails ) => {
	const brand = card?.network || card?.brand;
	const funding = card?.funding;

	if ( ! brand && ! funding ) {
		return <Dash />;
	}

	const brandLabel = brand
		? formatLabel( brand )
		: __( 'Card', 'woocommerce' );

	if ( ! funding ) {
		return brandLabel;
	}

	return sprintf(
		/* translators: 1: card brand, 2: card funding type. */
		__( '%1$s %2$s card', 'woocommerce' ),
		brandLabel,
		String( funding ).toLowerCase()
	);
};

// Client 11.1.0 `payment-details/payment-method/card/check.js`.
const getCheckLabel = ( check?: string ) => {
	switch ( check ) {
		case 'pass':
			return __( 'Passed', 'woocommerce' );
		case 'fail':
			return __( 'Failed', 'woocommerce' );
		case 'unavailable':
			return __( 'Unavailable', 'woocommerce' );
		default:
			return __( 'Not checked', 'woocommerce' );
	}
};

const stripTags = ( value: string ) => value.replace( /<[^>]*>/g, '' );

// Client 11.1.0 `payment-method/card/index.js:34,175-183` prints the server's `formatted_address`; the raw fields only
// stand in when a response carries no formatted address.
const getAddressLines = (
	billingDetails?: WooPaymentsBillingDetails,
	countries: CountryMap = {}
) => {
	const formattedLines = ( billingDetails?.formatted_address || '' )
		.split( /<br\s*\/?>/i )
		.map( stripTags )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

	if ( formattedLines.length ) {
		return formattedLines;
	}

	const address = billingDetails?.address;
	if ( ! address ) {
		return [];
	}

	return [
		[ address.line1, address.line2 ].filter( Boolean ).join( ', ' ),
		[ address.city, address.state, address.postal_code ]
			.filter( Boolean )
			.join( ', ' ),
		getCountryName( address.country, countries ),
	].filter( Boolean );
};

const StackedLines = ( { lines }: { lines: ReactNode[] } ) => {
	if ( ! lines.length ) {
		return <Dash />;
	}

	return (
		<span className="woocommerce-woopayments-money-movement__stacked-value">
			{ lines.map( ( line, index ) => (
				<span key={ index }>{ line }</span>
			) ) }
		</span>
	);
};

const getFiniteNumber = ( value: unknown ) =>
	typeof value === 'number' && Number.isFinite( value ) ? value : undefined;

const formatPaymentSummaryAmount = (
	amount: number | undefined,
	currency: string | undefined,
	showCurrencyCode: boolean
) => {
	// Client 11.1.0 `payment-details/summary/index.tsx` uses `formatExplicitCurrency()` for all but the fees.
	const formattedAmount = formatExplicitCurrency( amount, currency );

	return showCurrencyCode &&
		currency &&
		! formattedAmount.includes( currency.toUpperCase() )
		? `${ formattedAmount } ${ currency.toUpperCase() }`
		: formattedAmount;
};

type SummaryStatus = { message: string; type: StatusChipType };

// Client 11.1.0 `components/dispute-status-chip`: red while a response is due, whatever the time left.
const getDisputeStatusChipType = ( status: string ): StatusChipType => {
	if ( [ 'needs_response', 'warning_needs_response' ].includes( status ) ) {
		return 'error';
	}

	if ( status === 'won' ) {
		return 'success';
	}

	return [ 'under_review', 'warning_under_review' ].includes( status )
		? 'primary'
		: 'info';
};

// Client 11.1.0 `summary/index.tsx:293-301`: the status of the dispute awaiting a response, else the first one.
const getDisputeSummaryStatus = (
	transaction: WooPaymentsTransaction
): SummaryStatus | null => {
	const disputes = getChargeDisputes( transaction );
	const dispute =
		disputes.find( isDisputeAwaitingResponse ) ??
		getPrimaryDispute( transaction );
	const disputeStatus = dispute?.status || '';

	if ( ! disputeStatus ) {
		return null;
	}

	const disputeLabel =
		DISPUTE_STATUS_LABELS[ disputeStatus ] || formatLabel( disputeStatus );

	return {
		message: disputeStatus.startsWith( 'warning_' )
			? disputeLabel
			: sprintf(
					/* translators: %s: dispute status, such as Response needed or Won. */
					__( 'Disputed: %s', 'woocommerce' ),
					disputeLabel
			  ),
		type: getDisputeStatusChipType( disputeStatus ),
	};
};

// Client 11.1.0 utils/charge/index.ts:52-71, 146-151 and payment-status-chip/mappings.ts: label and chip colour.
const getPaymentSummaryStatus = (
	transaction: WooPaymentsTransaction
): SummaryStatus => {
	const fraudState = transaction.order?.fraud_meta_box_type || '';

	if (
		transaction.status === 'requires_capture' &&
		fraudState === 'review'
	) {
		return {
			message: __( 'Needs review', 'woocommerce' ),
			type: 'warning',
		};
	}

	if ( [ 'block', 'review_blocked' ].includes( fraudState ) ) {
		return {
			message: __( 'Payment blocked', 'woocommerce' ),
			type: 'error',
		};
	}

	if ( transaction.status === 'failed' ) {
		return {
			message:
				transaction.outcome?.type === 'blocked'
					? __( 'Payment blocked', 'woocommerce' )
					: __( 'Payment failed', 'woocommerce' ),
			type: 'error',
		};
	}

	const disputeStatus = getDisputeSummaryStatus( transaction );

	if ( disputeStatus ) {
		return disputeStatus;
	}

	const authorized = {
		message: __( 'Payment authorized', 'woocommerce' ),
		type: 'primary' as const,
	};

	if ( transaction.status === 'requires_capture' ) {
		return authorized;
	}

	const refundedAmount = Number( transaction.amount_refunded );
	const hasRefundedAmount =
		Number.isFinite( refundedAmount ) && refundedAmount > 0;
	const isSuccessfulCharge = [ 'paid', 'succeeded' ].includes(
		transaction.status || ''
	);

	if ( ! isSuccessfulCharge ) {
		return { message: formatLabel( transaction.status ), type: 'info' };
	}

	if ( ! hasRefundedAmount ) {
		return transaction.captured === true
			? { message: __( 'Paid', 'woocommerce' ), type: 'success' }
			: authorized;
	}

	const chargeAmount = Math.abs( Number( transaction.amount ) );
	const isFullyRefunded =
		transaction.refunded === true ||
		( Number.isFinite( chargeAmount ) &&
			chargeAmount > 0 &&
			refundedAmount >= chargeAmount );

	return {
		message: isFullyRefunded
			? __( 'Refunded', 'woocommerce' )
			: __( 'Partial refund', 'woocommerce' ),
		type: 'info',
	};
};

// Client 11.1.0 `utils/date-time.ts` `formatDateTimeFromTimestamp()`: site formats, read as UTC.
const formatSummaryDate = (
	value: string | number | undefined,
	format: 'withTime' | 'dispute'
) => {
	if ( ! value ) {
		return '–';
	}

	const date =
		typeof value === 'number'
			? moment.utc( value < 10000000000 ? value * 1000 : value )
			: moment.utc( value );

	if ( ! date.isValid() ) {
		return '–';
	}

	const { formats } = getDateSettings();

	return dateI18n(
		format === 'dispute'
			? 'F j, Y g:i A'
			: `${ formats.date }, ${ formats.time }`,
		date.toISOString(),
		undefined
	);
};

// Client 11.1.0 `components/risk-level/strings.ts`.
const RISK_LEVEL_LABELS: Record< string, string > = {
	normal: __( 'Normal', 'woocommerce' ),
	elevated: __( 'Elevated', 'woocommerce' ),
	highest: __( 'Highest', 'woocommerce' ),
};

type SummaryItem = { title: string; content: ReactNode };

// Client 11.1.0 `summary/index.tsx:160-277`: `composePaymentSummaryItems()` and its dispute variant.
const getSummaryItems = (
	transaction: WooPaymentsTransaction
): SummaryItem[] => {
	const isDisputed = getChargeDisputes( transaction ).length > 0;
	const subscriptions = transaction.order?.subscriptions || [];
	const riskLevel = transaction.outcome?.risk_level || '';

	return [
		{
			title: __( 'Date', 'woocommerce' ),
			content: formatSummaryDate(
				transaction.created || transaction.date,
				isDisputed ? 'dispute' : 'withTime'
			),
		},
		! isDisputed && {
			title: __( 'Sales channel', 'woocommerce' ),
			content: getChargeChannelLabel(
				transaction.payment_method_details?.type,
				transaction.metadata || {},
				transaction.sales_channel
			),
		},
		{
			title: __( 'Customer', 'woocommerce' ),
			content: <CustomerLink transaction={ transaction } />,
		},
		{
			title: __( 'Order', 'woocommerce' ),
			content: <OrderLink order={ transaction.order } />,
		},
		isWooPaymentsSubscriptionsActive() && {
			title: __( 'Subscription', 'woocommerce' ),
			content: subscriptions.length ? (
				subscriptions.map( ( subscription, index ) => (
					<span key={ `${ subscription.url || '' }-${ index }` }>
						<OrderLink order={ subscription } />
						{ index < subscriptions.length - 1 ? ', ' : '' }
					</span>
				) )
			) : (
				<OrderLink order={ null } />
			),
		},
		{
			title: __( 'Payment method', 'woocommerce' ),
			content: getPaymentMethodLabel( transaction ) ? (
				<PaymentMethodSummary transaction={ transaction } />
			) : (
				<>&ndash;</>
			),
		},
		{
			title: __( 'Risk evaluation', 'woocommerce' ),
			content: RISK_LEVEL_LABELS[ riskLevel ] || '–',
		},
	].filter( Boolean ) as SummaryItem[];
};

/**
 * The payment summary card: amount, status, money lines, IDs and actions, then one row of labelled values.
 * Client 11.1.0 `payment-details/summary/index.tsx:448-1023`.
 *
 * @param props                 Component props.
 * @param props.transaction     The payment.
 * @param props.paymentIntentId Payment ID shown at the top right.
 * @param props.chargeId        Charge ID shown at the top right.
 * @param props.actions         The actions menu.
 * @param props.reviewActions   The fraud review buttons.
 * @param props.children        Dispute panes and notices at the foot of the card.
 */
const PLACEHOLDER = 'woocommerce-woopayments-payment-details-placeholder';

/**
 * The summary and timeline cards while the payment loads.
 * Client 11.1.0 `payment-details/summary/index.tsx:480-866` and `timeline/index.js:33-58`.
 */
export const WooPaymentsPaymentDetailsPlaceholder = () => (
	<>
		<Card className={ PLACEHOLDER } aria-hidden="true">
			<CardBody>
				<div className={ `${ PLACEHOLDER }__row` }>
					<div>
						<span
							className={ `${ PLACEHOLDER }__block is-amount` }
						/>
						<div className={ `${ PLACEHOLDER }__row is-start` }>
							<span
								className={ `${ PLACEHOLDER }__block is-short` }
							/>
							<span
								className={ `${ PLACEHOLDER }__block is-short` }
							/>
						</div>
					</div>
					<span className={ `${ PLACEHOLDER }__block is-wide` } />
				</div>
			</CardBody>
			<CardDivider />
			<CardBody>
				<span className={ `${ PLACEHOLDER }__block is-tall` } />
			</CardBody>
		</Card>
		<Card className={ PLACEHOLDER } aria-hidden="true">
			<CardHeader>
				<span className={ `${ PLACEHOLDER }__block is-title` } />
			</CardHeader>
			<CardBody className={ `${ PLACEHOLDER }__lines` }>
				{ [ 0, 1, 2, 3 ].map( ( line ) => (
					<span
						key={ line }
						className={ `${ PLACEHOLDER }__block is-line` }
					/>
				) ) }
			</CardBody>
		</Card>
	</>
);

export const WooPaymentsPaymentSummarySection = ( {
	transaction,
	paymentIntentId = '',
	chargeId = '',
	actions,
	reviewActions,
	children,
}: {
	transaction: WooPaymentsTransaction;
	paymentIntentId?: string;
	chargeId?: string;
	actions?: ReactNode;
	reviewActions?: ReactNode;
	children?: ReactNode;
} ) => {
	// Client 11.1.0 `summary/index.tsx:291-296`: amounts in the settlement currency, placeholders without an amount.
	const balance = transaction.amount
		? getChargeAmounts( transaction )
		: { currency: 'USD', amount: 0, fee: 0, net: 0, refunded: 0 };
	const hasDifferentBalanceCurrency = !! (
		transaction.currency &&
		balance.currency.toLowerCase() !== transaction.currency.toLowerCase()
	);
	// Client 11.1.0 `summary/index.tsx:420-431`: the envelope's net already has the loan repayment taken out.
	const paydownAmount = transaction.paydown
		? getFiniteNumber( transaction.paydown.amount )
		: undefined;
	const net =
		! transaction.fee_breakdown_v1?.totals?.net &&
		paydownAmount !== undefined
			? balance.net - Math.abs( paydownAmount )
			: balance.net;
	// Client 11.1.0 `summary/index.tsx:356-367`: "Deducted" only when a dispute moved money.
	const disputeWithdrawnAmount =
		transaction.disputed === true
			? getDisputeBalanceAdjustments( transaction ).refunded
			: 0;
	const refundedAmountLabel = disputeWithdrawnAmount
		? /* translators: %s: formatted withdrawn amount. */
		  __( 'Deducted: %s', 'woocommerce' )
		: /* translators: %s: formatted withdrawn amount. */
		  __( 'Refunded: %s', 'woocommerce' );
	const status = hasDisplayValue( transaction.status )
		? getPaymentSummaryStatus( transaction )
		: null;
	// Client 11.1.0 `summary/index.tsx:316-338, 573-641`: with dispute fees, a help icon splits the fees.
	const disputeFees = getChargeDisputes( transaction )
		.map( getEffectiveDisputeFee )
		.filter( ( disputeFee ) => !! disputeFee );
	const disputeFeeTotal = disputeFees.reduce(
		( total, disputeFee ) => total + ( disputeFee?.amount ?? 0 ),
		0
	);
	const transactionFee = getTransactionFeeBeforeDisputes(
		transaction,
		disputeFeeTotal
	);

	return (
		<Card
			as="section"
			className="woocommerce-woopayments-payment-summary"
			aria-label={ __( 'Summary', 'woocommerce' ) }
		>
			<CardBody>
				<Flex direction="row" align="start">
					<div className="woocommerce-woopayments-payment-summary__main">
						<div className="woocommerce-woopayments-payment-summary__section">
							<div className="woocommerce-woopayments-payment-summary__amount-wrapper">
								<p className="woocommerce-woopayments-payment-summary__amount">
									{ formatAmount(
										transaction.amount,
										transaction.currency
									) }
									<span className="woocommerce-woopayments-payment-summary__amount-currency">
										{ (
											transaction.currency || 'USD'
										).toUpperCase() }
									</span>
								</p>
								{ status && (
									<StatusChip
										message={ status.message }
										type={ status.type }
									/>
								) }
							</div>
							<div className="woocommerce-woopayments-payment-summary__breakdown">
								{ hasDifferentBalanceCurrency && (
									<div className="woocommerce-woopayments-payment-summary__settlement-currency">
										{ formatPaymentSummaryAmount(
											balance.amount,
											balance.currency,
											true
										) }
									</div>
								) }
								{ !! balance.refunded && (
									<div>
										{ sprintf(
											refundedAmountLabel,
											formatExplicitCurrency(
												-balance.refunded,
												balance.currency
											)
										) }
									</div>
								) }
								<div>
									{ sprintf(
										/* translators: %s: formatted fee amount. */
										__( 'Fees: %s', 'woocommerce' ),
										// Client 11.1.0 `summary/index.tsx:559-566`: `formatCurrency()`, so no currency code.
										formatAmount(
											-balance.fee,
											balance.currency
										)
									) }
									{ disputeFees.length > 0 && (
										<HelpPopover
											label={ __(
												'Fee breakdown',
												'woocommerce'
											) }
										>
											<dl className="woocommerce-woopayments-payment-summary__fee-breakdown">
												{ [
													[
														__(
															'Transaction fee',
															'woocommerce'
														),
														transactionFee.fee,
														transactionFee.currency,
													],
													[
														_n(
															'Dispute fee',
															'Dispute fees',
															disputeFees.length,
															'woocommerce'
														),
														disputeFeeTotal,
														balance.currency,
													],
													[
														__(
															'Total fees',
															'woocommerce'
														),
														balance.fee,
														balance.currency,
													],
												].map(
													( [
														label,
														amount,
														currency,
													] ) => (
														<div key={ label }>
															<dt>{ label }</dt>
															<dd>
																{ formatAmount(
																	Number(
																		amount
																	),
																	String(
																		currency
																	)
																) }
															</dd>
														</div>
													)
												) }
											</dl>
										</HelpPopover>
									) }
								</div>
								{ paydownAmount !== undefined && (
									<div>
										{ sprintf(
											/* translators: %s: formatted loan repayment amount. */
											__(
												'Loan repayment: %s',
												'woocommerce'
											),
											formatPaymentSummaryAmount(
												paydownAmount,
												balance.currency,
												hasDifferentBalanceCurrency
											)
										) }
									</div>
								) }
								<div>
									{ sprintf(
										/* translators: %s: formatted net amount. */
										__( 'Net: %s', 'woocommerce' ),
										formatPaymentSummaryAmount(
											net,
											balance.currency,
											hasDifferentBalanceCurrency
										)
									) }
								</div>
							</div>
						</div>
						<div className="woocommerce-woopayments-payment-summary__section">
							{ reviewActions }
							<div className="woocommerce-woopayments-payment-summary__id">
								{ paymentIntentId && (
									<div>
										{ `${ __(
											'Payment ID',
											'woocommerce'
										) }: ` }
										<span className="woocommerce-woopayments-payment-summary__id-value">
											{ paymentIntentId }
										</span>
									</div>
								) }
								{ chargeId && (
									<div>
										{ `${ __(
											'Charge ID',
											'woocommerce'
										) }: ` }
										<span className="woocommerce-woopayments-payment-summary__id-value">
											{ chargeId }
										</span>
									</div>
								) }
							</div>
						</div>
					</div>
					{ actions }
				</Flex>
			</CardBody>
			<CardDivider />
			<CardBody>
				<dl className="woocommerce-woopayments-payment-summary__list">
					{ getSummaryItems( transaction ).map(
						( { title, content } ) => (
							<div key={ title }>
								<dt>{ title }</dt>
								<dd>{ content }</dd>
							</div>
						)
					) }
				</dl>
			</CardBody>
			{ children }
		</Card>
	);
};

/**
 * A notice at the foot of the summary card, text on the left and an action on the right.
 * Client 11.1.0 `components/card-notice`.
 *
 * @param props          Component props.
 * @param props.children The notice text.
 * @param props.actions  The action button.
 */
export const WooPaymentsSummaryCardNotice = ( {
	children,
	actions,
}: {
	children: ReactNode;
	actions?: ReactNode;
} ) => (
	<CardFooter className="woocommerce-woopayments-payment-summary__notice">
		<p className="woocommerce-woopayments-payment-summary__notice-text">
			{ children }
		</p>
		{ actions }
	</CardFooter>
);

// Client 11.1.0 `payment-details/summary/missing-order-notice/index.tsx:25-66`: the notice offers a
// Refund button until the charge is refunded, and then says it can no longer be disputed.
export const WooPaymentsMissingOrderNotice = ( {
	transaction,
	onRefund,
}: {
	transaction: WooPaymentsTransaction;
	onRefund: ( opener: HTMLElement ) => void;
} ) => {
	if ( ! isPaymentOrderMissing( transaction ) ) {
		return null;
	}

	return (
		<WooPaymentsSummaryCardNotice
			actions={
				! transaction.refunded && (
					<Button
						variant="primary"
						__next40pxDefaultSize
						onClick={ ( event: MouseEvent< HTMLButtonElement > ) =>
							onRefund( event.currentTarget )
						}
					>
						{ __( 'Refund', 'woocommerce' ) }
					</Button>
				)
			}
		>
			{ __(
				'This transaction is not connected to order.',
				'woocommerce'
			) }{ ' ' }
			{ transaction.refunded
				? __(
						'It has been refunded and is not a subject for disputes.',
						'woocommerce'
				  )
				: __(
						'Investigate this purchase and refund the transaction as needed.',
						'woocommerce'
				  ) }
		</WooPaymentsSummaryCardNotice>
	);
};

type PaymentMethodRow = { label: string; value: ReactNode };

const PaymentMethodColumns = ( {
	columns,
}: {
	columns: PaymentMethodRow[][];
} ) => (
	<div className="woocommerce-woopayments-payment-method-details">
		{ columns.map( ( rows, index ) => (
			<dl
				key={ index }
				className="woocommerce-woopayments-payment-method-details__column"
			>
				{ rows.map( ( { label, value } ) => (
					<DetailRow key={ label } label={ label } value={ value } />
				) ) }
			</dl>
		) ) }
	</div>
);

// Client 11.1.0 `payment-details/payment-method/index.js:73-93`: a large card titled "Payment method".
const PaymentMethodCard = ( { children }: { children: ReactNode } ) => (
	<Card size="large">
		<CardHeader>
			<h2 className="woocommerce-woopayments-overview-card__title">
				{ __( 'Payment method', 'woocommerce' ) }
			</h2>
		</CardHeader>
		<CardBody>{ children }</CardBody>
	</Card>
);

// Client 11.1.0 `payment-details/payment-method/*/index.js`: each method's two columns, `id` and `owner` standing
// for the ID row and the Owner, Owner email and Address rows. Types missing here get no card, as in the client.
const PAYMENT_METHOD_LAYOUTS: Record< string, [ string[], string[] ] > = {
	affirm: [ [ 'id' ], [ 'owner' ] ],
	afterpay_clearpay: [ [ 'id' ], [ 'owner' ] ],
	alipay: [ [ 'id' ], [ 'owner' ] ],
	amazon_pay: [ [ 'transaction_id', 'id' ], [ 'owner' ] ],
	au_becs_debit: [ [ 'bsb_number', 'last4', 'id' ], [ 'owner' ] ],
	bancontact: [
		[ 'bank_name', 'bic', 'id' ],
		[ 'verified_name', 'owner' ],
	],
	eps: [ [ 'bank', 'id', 'verified_name' ], [ 'owner' ] ],
	giropay: [ [ 'bank_name', 'bic', 'id' ], [ 'owner' ] ],
	grabpay: [ [ 'id' ], [ 'owner' ] ],
	ideal: [
		[ 'id', 'bank', 'bic', 'iban_last4' ],
		[ 'verified_name', 'owner' ],
	],
	klarna: [
		[ 'id', 'payment_method_category', 'preferred_locale' ],
		[ 'owner' ],
	],
	multibanco: [ [ 'id' ], [ 'owner' ] ],
	p24: [ [ 'bank', 'reference', 'id', 'verified_name' ], [ 'owner' ] ],
	sepa_debit: [
		[ 'last4', 'id' ],
		[ 'owner', 'country' ],
	],
	sofort: [
		[ 'id', 'bank_code', 'bank_name', 'bic', 'iban_last4' ],
		[ 'verified_name', 'owner', 'country' ],
	],
	wechat_pay: [ [ 'id' ], [ 'owner' ] ],
};

export const WooPaymentsPaymentMethodDetailsSection = ( {
	transaction,
	countries = {},
}: {
	transaction: WooPaymentsTransaction;
	countries?: CountryMap;
} ) => {
	const method = transaction.payment_method_details;
	const card = method ? getPaymentMethodCardDetails( method ) : undefined;

	if ( ! method?.type ) {
		return null;
	}

	const owner: PaymentMethodRow[] = [
		{
			label: __( 'Owner', 'woocommerce' ),
			value: getCustomerName( transaction ) || <Dash />,
		},
		{
			label: __( 'Owner email', 'woocommerce' ),
			value: getCustomerEmail( transaction ) || <Dash />,
		},
		{
			label: __( 'Address', 'woocommerce' ),
			value: (
				<StackedLines
					lines={ getAddressLines(
						transaction.billing_details,
						countries
					) }
				/>
			),
		},
	];
	const id: PaymentMethodRow = {
		label: __( 'ID', 'woocommerce' ),
		value: transaction.payment_method || <Dash />,
	};

	const layout = PAYMENT_METHOD_LAYOUTS[ method.type ];

	if ( layout ) {
		const methodDetails = getPaymentMethodTypedDetails( method );
		const fields = nonCardPaymentMethodDetailFields[ method.type ] || [];
		const toRows = ( keys: string[] ) =>
			keys.flatMap( ( key ): PaymentMethodRow[] => {
				if ( key === 'id' ) {
					return [ id ];
				}

				if ( key === 'owner' ) {
					return owner;
				}

				const field = fields.find(
					( candidate ) => candidate.field === key
				);

				return field
					? [
							{
								label: field.label,
								value: getPaymentMethodDetailValue(
									methodDetails,
									field.field,
									countries,
									field.format
								),
							},
					  ]
					: [];
			} );

		return (
			<PaymentMethodCard>
				<PaymentMethodColumns
					columns={ [ toRows( layout[ 0 ] ), toRows( layout[ 1 ] ) ] }
				/>
			</PaymentMethodCard>
		);
	}

	if ( method.type !== 'card' && method.type !== 'card_present' ) {
		return null;
	}

	const isCardPresent = method.type === 'card_present';

	// Client 11.1.0 `payment-details/payment-method/card/index.js:107-215`.
	return (
		<PaymentMethodCard>
			<PaymentMethodColumns
				columns={ [
					[
						{
							label: __( 'Number', 'woocommerce' ),
							value: card?.last4 ? (
								`•••• ${ card.last4 }`
							) : (
								<Dash />
							),
						},
						{
							label: __( 'Expires', 'woocommerce' ),
							value:
								card?.exp_month && card.exp_year ? (
									`${ card.exp_month } / ${ card.exp_year }`
								) : (
									<Dash />
								),
						},
						{
							label: __( 'Type', 'woocommerce' ),
							value: getPaymentMethodTypeLabel( card ),
						},
						id,
					],
					[
						...owner,
						{
							label: __( 'Origin', 'woocommerce' ),
							value: getCountryName(
								card?.country,
								countries
							) || <Dash />,
						},
						...( isCardPresent
							? []
							: [
									{
										label: __( 'CVC check', 'woocommerce' ),
										value: getCheckLabel(
											card?.checks?.cvc_check
										),
									},
									{
										label: __(
											'Street check',
											'woocommerce'
										),
										value: getCheckLabel(
											card?.checks?.address_line1_check
										),
									},
									{
										label: __(
											'Postal code check',
											'woocommerce'
										),
										value: getCheckLabel(
											card?.checks
												?.address_postal_code_check
										),
									},
							  ] ),
					],
				] }
			/>
		</PaymentMethodCard>
	);
};
