/**
 * External dependencies
 */
import { Icon, Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { update } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { WooPaymentsTransaction } from './types';
import {
	formatAmount,
	formatDate,
	formatDateTime,
	getResourceId,
	getTransactionDetailsRoute,
	getTransactionListAmount,
	getTransactionListFees,
	getTransactionListPaymentMethod,
	getTransactionListType,
	getTransactionTypeLabel,
} from './utils';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';

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
const RISK_LEVEL_LABELS = [
	__( 'Normal', 'woocommerce' ),
	__( 'Elevated', 'woocommerce' ),
	__( 'Highest', 'woocommerce' ),
];

// Client 11.1.0 `deposits/strings.ts` `depositStatusLabels`.
const PAYOUT_STATUS_LABELS: Record< string, string > = {
	paid: __( 'Completed (paid)', 'woocommerce' ),
	deducted: __( 'Completed (deducted)', 'woocommerce' ),
	pending: __( 'Pending', 'woocommerce' ),
	in_transit: __( 'In transit', 'woocommerce' ),
	canceled: __( 'Canceled', 'woocommerce' ),
	failed: __( 'Failed', 'woocommerce' ),
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

// Client 11.1.0 `components/risk-level/index.tsx` `calculateRiskMapping()`.
export const getRiskLevelLabel = ( risk?: number | string | null ) =>
	RISK_LEVEL_LABELS[ Number( risk ) ] ?? __( 'N/A', 'woocommerce' );

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
	const formatted = formatAmount(
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
		formatAmount( item.customer_amount ?? 0, fromCurrency )
	);

	return (
		<span className="woocommerce-woopayments-money-movement__converted-amount">
			<Tooltip text={ convertedFrom }>
				<span role="img" aria-label={ convertedFrom }>
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
				{ formatDate( item.available_on ) }
			</a>
		);
	}

	return <>{ __( 'Future payout', 'woocommerce' ) }</>;
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
			render: ( { item }: FieldRenderProps ) =>
				item.transaction_id || '-',
		},
		{
			id: 'date',
			label: __( 'Date', 'woocommerce' ),
			header: __( 'Date / time', 'woocommerce' ),
			type: 'date' as const,
			enableHiding: false,
			filterBy: includeFilters
				? { operators: [ 'before', 'after', 'between' ] as const }
				: ( false as const ),
			getValue: ( { item }: FieldRenderProps ) =>
				item.date || item.created || '',
			render: ( { item }: FieldRenderProps ) =>
				formatDateTime( item.date || item.created ),
		},
		{
			id: 'type',
			label: __( 'Type', 'woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			elements: TRANSACTION_TYPE_FILTER_ELEMENTS,
			filterBy: includeFilters
				? { operators: [ 'is' ] as const }
				: ( false as const ),
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListType( item ),
			render: ( { item }: FieldRenderProps ) => {
				const typeLabel = getTransactionTypeLabel(
					getTransactionListType( item )
				);

				return (
					<a
						href={ getSettingsPaymentsProviderRouteUrl(
							getTransactionDetailsRoute( item )
						) }
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
			render: ( { item }: FieldRenderProps ) =>
				getTransactionChannelLabel( item.channel ),
		},
		{
			id: 'customer_currency',
			label: __( 'Paid currency', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) =>
				toUpperCase( item.customer_currency ),
		},
		{
			id: 'customer_amount',
			label: __( 'Amount paid', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) =>
				formatAmount(
					item.customer_amount ?? undefined,
					item.customer_currency ?? undefined
				),
		},
		{
			id: 'currency',
			label: __( 'Payout currency', 'woocommerce' ),
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) =>
				toUpperCase( item.currency ),
		},
		{
			id: 'amount',
			label: __( 'Amount', 'woocommerce' ),
			type: 'integer' as const,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListAmount( item ) ?? '',
			render: ( { item }: FieldRenderProps ) => (
				<ConvertedAmount item={ item } />
			),
		},
		{
			id: 'fees',
			label: __( 'Fees', 'woocommerce' ),
			type: 'integer' as const,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) =>
				getTransactionListFees( item ) ?? '',
			render: ( { item }: FieldRenderProps ) =>
				formatAmount( getTransactionListFees( item ), item.currency ),
		},
		{
			id: 'net',
			label: __( 'Net', 'woocommerce' ),
			type: 'integer' as const,
			enableHiding: false,
			filterBy: false as const,
			getValue: ( { item }: FieldRenderProps ) => item.net ?? '',
			render: ( { item }: FieldRenderProps ) =>
				formatAmount( item.net, item.currency ),
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
			render: ( { item }: FieldRenderProps ) =>
				getTransactionListPaymentMethod( item ),
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
			render: ( { item }: FieldRenderProps ) =>
				item.customer_country || '-',
		},
		{
			id: 'risk_level',
			label: __( 'Risk level', 'woocommerce' ),
			enableSorting: false,
			filterBy: false as const,
			render: ( { item }: FieldRenderProps ) =>
				getRiskLevelLabel( item.risk_level ),
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
						render: ( { item }: FieldRenderProps ) =>
							( item.deposit_status &&
								PAYOUT_STATUS_LABELS[ item.deposit_status ] ) ||
							'',
					},
			  ]
			: [] ),
	];

	return fields;
};
