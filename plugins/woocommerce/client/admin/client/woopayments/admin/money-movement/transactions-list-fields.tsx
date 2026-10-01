/**
 * External dependencies
 */
import { Icon, Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { info, update } from '@wordpress/icons';
import type { MouseEventHandler, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsTransaction } from './types';
import {
	formatAmount,
	formatExplicitCurrency,
	formatSiteDateTime,
	getResourceId,
	getTransactionDetailsRoute,
	getTransactionListAmount,
	getTransactionListFees,
	getTransactionListPaymentMethod,
	getTransactionListPaymentMethodDetail,
	getTransactionListType,
	getTransactionSourceIconUrl,
	getTransactionSourceLabel,
	getTransactionTypeLabel,
} from './utils';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { payoutStatusLabels } from '../payout-status';
import { formatCurrencyName } from '../currency';

/**
 * A transactions list row: the platform's list fields plus the order context
 * the REST controller adds (`WooPaymentsMoneyMovementOrderService`).
 */
export type WooPaymentsTransactionListRow = WooPaymentsTransaction & {
	channel?: string | null;
	source_device?: string | null;
	customer_country?: string | null;
	customer_currency?: string | null;
	customer_amount?: number | null;
	deposit_id?: string | null;
	deposit_status?: string | null;
	available_on?: string | null;
	risk_level?: number | string | null;
};

type FieldRenderProps = { item: WooPaymentsTransactionListRow };

export type TransactionListFieldOptions = {
	/** The payout columns, left out inside a payout like the client's `includeDeposit`. */
	includeDeposit: boolean;
	/** The Subscription # column, only with WooCommerce Subscriptions active. */
	includeSubscription: boolean;
	/** DataViews filters; the client shows no filters inside a payout. */
	includeFilters: boolean;
};

/**
 * Client 11.1.0 `transactions/list/index.tsx:136-291`: the columns with
 * `visible: false`, hidden until the merchant shows them.
 */
export const TRANSACTION_LIST_DEFAULT_HIDDEN_COLUMNS = [
	'transaction_id',
	'customer_currency',
	'customer_amount',
	'currency',
	'customer_email',
	'customer_country',
	'risk_level',
	'deposit_id',
	'deposit_status',
];

const TRANSACTION_TYPE_FILTER_ELEMENTS = [
	'charge',
	'payment',
	'payment_failure_refund',
	'payment_refund',
	'refund',
	'refund_failure',
	'dispute',
	'dispute_reversal',
	'card_reader_fee',
	'financing_payout',
	'financing_paydown',
	'fee_refund',
	'network_costs',
].map( ( value ) => ( {
	value,
	label: getTransactionTypeLabel( value ),
} ) );

// Client 11.1.0 `components/risk-level/index.tsx:18` and `strings.ts`.
const RISK_LEVEL_LABELS: Record< string, string > = {
	0: __( 'Normal', 'woocommerce' ),
	1: __( 'Elevated', 'woocommerce' ),
	2: __( 'Highest', 'woocommerce' ),
};

type WooPaymentsSubscriptionsWindow = typeof window & {
	wcSettings?: {
		admin?: {
			woopaymentsSettings?: { isSubscriptionsActive?: boolean };
		};
	};
};

/**
 * Whether WooCommerce Subscriptions 2.2.0+ is active, like the client's
 * `wcpaySettings.isSubscriptionsActive`.
 */
export const isWooPaymentsSubscriptionsActive = () =>
	( window as WooPaymentsSubscriptionsWindow ).wcSettings?.admin
		?.woopaymentsSettings?.isSubscriptionsActive === true;

// The rows the client shows no payment method, customer or payout for.
const isFinancingType = ( item: WooPaymentsTransactionListRow ) =>
	item.type === 'financing_payout' || item.type === 'financing_paydown';

const hasNoCustomer = ( item: WooPaymentsTransactionListRow ) =>
	isFinancingType( item ) ||
	getTransactionListType( item ) === 'card_reader_fee' ||
	item.type === 'network_costs';

const toUpperCase = ( value?: string | null ) =>
	value ? value.toUpperCase() : '-';

// Client 11.1.0 `utils/charge/index.ts:294-303` `getTransactionChannel()`.
export const getTransactionChannelLabel = ( channel?: string | null ) => {
	switch ( channel ) {
		case 'in_person':
			return __( 'In-person', 'woocommerce' );
		case 'in_person_pos':
			return __( 'In-person (POS)', 'woocommerce' );
		default:
			return __( 'Online store', 'woocommerce' );
	}
};

// Client 11.1.0 `components/risk-level/index.tsx` `calculateRiskMapping()`:
// a missing or unknown level reads N/A, never Normal.
export const getRiskLevelLabel = ( risk?: number | string | null ) =>
	Object.prototype.hasOwnProperty.call( RISK_LEVEL_LABELS, String( risk ) )
		? RISK_LEVEL_LABELS[ String( risk ) ]
		: __( 'N/A', 'woocommerce' );

// Client 11.1.0 `components/order-link/index.tsx`.
export const OrderLink = ( {
	order,
}: {
	order?: { number?: number | string; url?: string } | null;
} ) => {
	if ( ! order?.number ) {
		return <span>&ndash;</span>;
	}

	return <a href={ order.url }>{ String( order.number ) }</a>;
};

/**
 * A source's logo, then its detail, such as "•••• 4242". Without a logo, the text label.
 * Client 11.1.0 `transactions/list/index.tsx:473-497` and `disputes/index.tsx:280-290`.
 *
 * @param props        The component props.
 * @param props.source The source, such as `visa` or `sepa_debit`.
 * @param props.detail The text after the logo.
 * @param props.text   The text to show when there is no logo.
 */
export const PaymentSource = ( {
	source,
	detail = '',
	text,
}: {
	source: string;
	detail?: string;
	text?: string;
} ) => {
	const iconUrl = getTransactionSourceIconUrl( source );
	const label = getTransactionSourceLabel( source );

	if ( ! iconUrl ) {
		return <>{ text || label }</>;
	}

	return (
		<span className="woocommerce-woopayments-money-movement__card-summary">
			<img
				className="woocommerce-woopayments-money-movement__card-brand"
				src={ iconUrl }
				alt={ label }
			/>
			{ detail && <span>{ detail }</span> }
		</span>
	);
};

/**
 * A cell that opens the row's details but reads as its text, out of the tab order.
 * Client 11.1.0 `components/clickable-cell`. An empty cell has nothing to click.
 *
 * @param props          The component props.
 * @param props.href     The details URL; without it the cell is plain text.
 * @param props.onClick  Called on a click, such as for the client's Tracks event.
 * @param props.children The cell content.
 */
export const ClickableCell = ( {
	href,
	onClick,
	children,
}: {
	href?: string;
	onClick?: MouseEventHandler< HTMLAnchorElement >;
	children?: ReactNode;
} ) => {
	if (
		! href ||
		children === '' ||
		children === null ||
		children === undefined
	) {
		return <>{ children }</>;
	}

	return (
		<a
			className="woocommerce-woopayments-money-movement__clickable-cell"
			href={ href }
			tabIndex={ -1 }
			onClick={ onClick }
		>
			{ children }
		</a>
	);
};

/**
 * The info icon link that starts a disputes or payouts row. Client 11.1.0 `components/details-link`.
 *
 * @param props       The component props.
 * @param props.href  The details URL.
 * @param props.label The link's accessible name.
 */
export const DetailsLink = ( {
	href,
	label,
}: {
	href: string;
	label: string;
} ) => (
	<a
		className="woocommerce-woopayments-money-movement__details-link"
		href={ href }
		aria-label={ label }
	>
		<Icon icon={ info } size={ 18 } />
	</a>
);

/**
 * The info column's field: its header is for screen readers only, as the client's column has no title.
 */
export const DETAILS_FIELD_BASE = {
	id: 'details',
	label: __( 'Details', 'woocommerce' ),
	header: (
		<span className="screen-reader-text">
			{ __( 'Details', 'woocommerce' ) }
		</span>
	),
	enableHiding: false,
	enableSorting: false,
	filterBy: false as const,
};

// Client 11.1.0 `transactions/list/index.tsx:327-339`: loan disbursements, network costs and loan
// repayments without a charge open no details.
const getTransactionDetailsUrl = ( item: WooPaymentsTransactionListRow ) =>
	item.type === 'financing_payout' ||
	item.type === 'network_costs' ||
	( item.type === 'financing_paydown' && ! item.charge_id )
		? undefined
		: getSettingsPaymentsProviderRouteUrl(
				getTransactionDetailsRoute( item )
		  );

const CustomerLink = ( {
	item,
	value,
}: {
	item: WooPaymentsTransactionListRow;
	value?: string;
} ) => {
	if ( hasNoCustomer( item ) ) {
		return <>{ __( 'N/A', 'woocommerce' ) }</>;
	}

	const customerUrl = item.order?.customer_url;

	return customerUrl ? (
		<a href={ customerUrl }>{ value }</a>
	) : (
		<>{ value || '-' }</>
	);
};

// Client 11.1.0 `transactions/list/converted-amount.tsx`.
const ConvertedAmount = ( { item }: FieldRenderProps ) => {
	const formatted = formatExplicitCurrency(
		getTransactionListAmount( item ),
		item.currency
	);
	const fromCurrency = item.customer_currency;

	if (
		! fromCurrency ||
		! item.currency ||
		fromCurrency.toLowerCase() === item.currency.toLowerCase()
	) {
		return <>{ formatted }</>;
	}

	const convertedFrom = sprintf(
		/* translators: %s: amount in the currency the customer paid. */
		__( 'Converted from %s', 'woocommerce' ),
		formatExplicitCurrency( item.customer_amount ?? 0, fromCurrency )
	);

	return (
		<span className="woocommerce-woopayments-money-movement__converted-amount">
			<Tooltip text={ convertedFrom }>
				<span
					className="woocommerce-woopayments-money-movement__conversion-indicator"
					role="img"
					aria-label={ convertedFrom }
				>
					<Icon icon={ update } size={ 18 } />
				</span>
			</Tooltip>
			{ formatted }
		</span>
	);
};

// Client 11.1.0 `transactions/list/deposit.tsx`.
const PayoutDate = ( { item }: FieldRenderProps ) => {
	if ( isFinancingType( item ) ) {
		return null;
	}

	if ( item.deposit_id && item.available_on ) {
		return (
			<a
				href={ getSettingsPaymentsProviderRouteUrl(
					`/woopayments/payouts/details?id=${ encodeURIComponent(
						item.deposit_id
					) }`
				) }
			>
				{ formatSiteDateTime( item.available_on, false ) }
			</a>
		);
	}

	return <>{ __( 'Future payout', 'woocommerce' ) }</>;
};

type WooPaymentsTransactionsFilterWindow = typeof window & {
	wcSettings?: {
		countries?: Record< string, string >;
		admin?: {
			woopaymentsSettings?: { accountLoans?: { loans?: unknown } };
		};
	};
};

export type TransactionListFilterChoices = {
	/** The summary's `customer_currencies`, the client's Customer currency choices. */
	customerCurrencies: string[];
	/** The summary's `sources`, the client's Payment method choices. */
	sources: string[];
};

const toElements = ( labels: Record< string, string > ) =>
	Object.entries( labels ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) );

// Client 11.1.0 `transactions/filters/config.ts:555-571`: `<loan id>|<status>` as "ID: <id> | <status>".
const getLoanElements = () => {
	const loans = ( window as WooPaymentsTransactionsFilterWindow ).wcSettings
		?.admin?.woopaymentsSettings?.accountLoans?.loans;

	return ( Array.isArray( loans ) ? loans : [] )
		.filter( ( loan ): loan is string => typeof loan === 'string' )
		.map( ( loan ) => {
			const [ id, status ] = loan.split( '|' );

			return {
				value: id,
				label: sprintf(
					/* translators: 1: loan ID, 2: loan status, such as "In Progress". */
					__( 'ID: %1$s | %2$s', 'woocommerce' ),
					id,
					status === 'active'
						? __( 'In Progress', 'woocommerce' )
						: __( 'Paid in Full', 'woocommerce' )
				),
			};
		} );
};

const selectFilter = (
	id: string,
	label: string,
	elements: Array< { value: string; label: string } >,
	operators: Array< 'is' | 'isNot' > = [ 'is', 'isNot' ]
) => ( {
	id,
	label,
	elements,
	enableHiding: false,
	enableSorting: false,
	filterBy: { operators },
	render: () => null,
} );

/**
 * The client's advanced filters that are not columns, as DataViews filter fields. Their ids are
 * the client's URL arguments, so "Is not" becomes `<name>_is_not`; they stay out of the columns.
 * Client 11.1.0 `transactions/filters/config.ts:134-600`, in its add menu's alphabetical order.
 *
 * @param choices The summary's customer currencies and payment methods.
 */
export const getTransactionListFilterFields = (
	choices: TransactionListFilterChoices
) => {
	const loans = getLoanElements();

	return [
		selectFilter(
			'customer_country_is',
			__( 'Customer country', 'woocommerce' ),
			toElements(
				( window as WooPaymentsTransactionsFilterWindow ).wcSettings
					?.countries || {}
			)
		),
		selectFilter(
			'customer_currency_is',
			__( 'Customer currency', 'woocommerce' ),
			choices.customerCurrencies.map( ( currency ) => ( {
				value: currency,
				label: formatCurrencyName( currency ),
			} ) )
		),
		selectFilter(
			'source_device_is',
			__( 'Device type', 'woocommerce' ),
			// Client 11.1.0 `transactions/strings.ts:29-32`.
			toElements( {
				android: __( 'Android', 'woocommerce' ),
				ios: __( 'iPhone', 'woocommerce' ),
			} )
		),
		...( loans.length
			? [
					selectFilter(
						'loan_id_is',
						__( 'Loan', 'woocommerce' ),
						loans,
						[ 'is' ]
					),
			  ]
			: [] ),
		selectFilter(
			'source_is',
			__( 'Payment method', 'woocommerce' ),
			choices.sources.map( ( source ) => ( {
				value: source,
				label: getTransactionSourceLabel( source ),
			} ) )
		),
		selectFilter(
			'risk_level_is',
			__( 'Risk level', 'woocommerce' ),
			toElements( RISK_LEVEL_LABELS )
		),
		selectFilter(
			'channel_is',
			__( 'Sales channel', 'woocommerce' ),
			[ 'online', 'in_person', 'in_person_pos' ].map( ( channel ) => ( {
				value: channel,
				label: getTransactionChannelLabel( channel ),
			} ) )
		),
	];
};

/**
 * The transactions list fields, in the client's column order with its labels,
 * `required` columns as `enableHiding: false` and its sortable columns.
 * Client 11.1.0 `transactions/list/index.tsx:136-291` (columns) and :315-558 (cells).
 *
 * @param options The client's column conditions.
 */
export const getTransactionListFields = (
	options: TransactionListFieldOptions
) => {
	const { includeDeposit, includeSubscription, includeFilters } = options;
	const fields = [
		{
			id: 'transaction_id',
			label: __( 'Transaction ID', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ item.transaction_id || '-' }
				</ClickableCell>
			),
		},
		{
			id: 'date',
			label: __( 'Date', 'woocommerce' ),
			header: __( 'Date / time', 'woocommerce' ),
			type: 'date' as const,
			enableHiding: false,
			filterBy: includeFilters
				? {
						operators: [ 'before', 'after', 'between' ] as const,
						isPrimary: true,
				  }
				: ( false as const ),
			getValue: ( { item }: FieldRenderProps ) =>
				item.date || item.created || '',
			// Client 11.1.0 `transactions/list/index.tsx:453`: site date and time formats.
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ formatSiteDateTime( item.date || item.created ) }
				</ClickableCell>
			),
		},
		{
			id: 'type',
			label: __( 'Type', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			elements: TRANSACTION_TYPE_FILTER_ELEMENTS,
			// Client 11.1.0 `transactions/filters/config.ts:297-348`: "Is" and "Is not" a type.
			filterBy: includeFilters
				? { operators: [ 'is', 'isNot' ] as const, isPrimary: true }
				: ( false as const ),
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListType( item ),
			render: ( { item }: FieldRenderProps ) => {
				const typeLabel = getTransactionTypeLabel(
					getTransactionListType( item )
				);
				const detailsUrl = getTransactionDetailsUrl( item );

				if ( ! detailsUrl ) {
					return typeLabel;
				}

				// Client 11.1.0 `components/clickable-cell`: the cell opens the details but reads as plain
				// text. It stays in the tab order, so each row keeps one keyboard way to its details.
				return (
					<a
						className="woocommerce-woopayments-money-movement__clickable-cell"
						href={ detailsUrl }
						aria-label={ sprintf(
							/* translators: 1: transaction type, 2: transaction ID. */
							__(
								'View transaction details for %1$s transaction %2$s',
								'woocommerce'
							),
							typeLabel,
							getResourceId( item )
						) }
					>
						{ typeLabel }
					</a>
				);
			},
		},
		{
			id: 'channel',
			label: __( 'Sales channel', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ getTransactionChannelLabel( item.channel ) }
				</ClickableCell>
			),
		},
		{
			id: 'customer_currency',
			label: __( 'Paid currency', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ toUpperCase( item.customer_currency ) }
				</ClickableCell>
			),
		},
		{
			id: 'customer_amount',
			label: __( 'Amount paid', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ formatAmount(
						item.customer_amount ?? undefined,
						item.customer_currency ?? undefined
					) }
				</ClickableCell>
			),
		},
		{
			id: 'currency',
			label: __( 'Payout currency', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ toUpperCase( item.currency ) }
				</ClickableCell>
			),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'woocommerce' ),
			type: 'integer' as const,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListAmount( item ) ?? '',
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					<ConvertedAmount item={ item } />
				</ClickableCell>
			),
		},
		{
			id: 'fees',
			label: __( 'Fees', 'woocommerce' ),
			type: 'integer' as const,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListFees( item ) ?? '',
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ formatAmount(
						getTransactionListFees( item ),
						item.currency
					) }
				</ClickableCell>
			),
		},
		{
			id: 'net',
			label: __( 'Net', 'woocommerce' ),
			type: 'integer' as const,
			enableHiding: false,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) => item.net ?? '',
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ formatExplicitCurrency( item.net, item.currency ) }
				</ClickableCell>
			),
		},
		{
			id: 'order',
			label: __( 'Order #', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) =>
				item.order ? (
					<OrderLink order={ item.order } />
				) : (
					__( 'N/A', 'woocommerce' )
				),
		},
		...( includeSubscription
			? [
					{
						id: 'subscriptions',
						label: __( 'Subscription #', 'woocommerce' ),
						enableSorting: false,
						filterBy: false as const,
						render: ( { item }: FieldRenderProps ) => {
							const subscriptions =
								item.order?.subscriptions || [];

							return (
								<>
									{ subscriptions.map(
										( subscription, index ) => (
											<span
												key={ `${
													subscription.url || ''
												}-${ index }` }
											>
												<OrderLink
													order={ subscription }
												/>
												{ index <
												subscriptions.length - 1
													? ', '
													: '' }
											</span>
										)
									) }
								</>
							);
						},
					},
			  ]
			: [] ),
		{
			id: 'source',
			label: __( 'Payment method', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListPaymentMethod( item ),
			render: ( { item }: FieldRenderProps ) => {
				const text = getTransactionListPaymentMethod( item );

				return item.source && text !== '-' ? (
					<ClickableCell href={ getTransactionDetailsUrl( item ) }>
						<PaymentSource
							source={ item.source }
							detail={ getTransactionListPaymentMethodDetail(
								item
							) }
							text={ text }
						/>
					</ClickableCell>
				) : (
					text
				);
			},
		},
		{
			id: 'customer_name',
			label: __( 'Customer', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<CustomerLink item={ item } value={ item.customer_name } />
			),
		},
		{
			id: 'customer_email',
			label: __( 'Email', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<CustomerLink item={ item } value={ item.customer_email } />
			),
		},
		{
			id: 'customer_country',
			label: __( 'Country', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ item.customer_country || '-' }
				</ClickableCell>
			),
		},
		{
			id: 'risk_level',
			label: __( 'Risk level', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) => (
				<ClickableCell href={ getTransactionDetailsUrl( item ) }>
					{ getRiskLevelLabel( item.risk_level ) }
				</ClickableCell>
			),
		},
		...( includeDeposit
			? [
					{
						id: 'deposit_id',
						label: __( 'Payout ID', 'woocommerce' ),
						enableSorting: false,
						filterBy: false as const,
						render: ( { item }: FieldRenderProps ) =>
							item.deposit_id || '',
					},
					{
						id: 'deposit',
						label: __( 'Payout date', 'woocommerce' ),
						enableSorting: false,
						filterBy: false as const,
						render: ( { item }: FieldRenderProps ) => (
							<PayoutDate item={ item } />
						),
					},
					{
						id: 'deposit_status',
						label: __( 'Payout status', 'woocommerce' ),
						enableSorting: false,
						filterBy: false as const,
						// Client 11.1.0 `transactions/list/index.tsx:438-440`: the plain status map, with no withdrawal rule.
						render: ( { item }: FieldRenderProps ) =>
							( item.deposit_status &&
								payoutStatusLabels[ item.deposit_status ] ) ||
							'',
					},
			  ]
			: [] ),
	];

	return fields;
};
