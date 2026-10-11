/**
 * External dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { __, _x } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

/**
 * Adds the Multi-Currency customer currency filter, column and report currency to the Orders report.
 *
 * Port of the client's analytics script (client 11.1.0 `includes/multi-currency/client/analytics/index.js`).
 */

type CurrencyOption = {
	label: string;
	value: string;
};

type ReportTableData = {
	endpoint?: string;
	headers: Record< string, unknown >[];
	rows: Record< string, unknown >[][];
	items?: { data?: Record< string, unknown >[] };
};

type AdvancedFilters = {
	filters: Record< string, unknown >;
};

export const getCustomerCurrencies = (): CurrencyOption[] =>
	[ ...getSetting< CurrencyOption[] >( 'customerCurrencies', [] ) ].sort(
		( a, b ) => ( a.label < b.label ? -1 : 1 )
	);

export const addCustomerCurrencyAdvancedFilter = (
	advancedFilters: AdvancedFilters
): AdvancedFilters => ( {
	...advancedFilters,
	filters: {
		currency: {
			labels: {
				add: __( 'Customer currency', 'woocommerce' ),
				remove: __( 'Remove customer currency filter', 'woocommerce' ),
				rule: __(
					'Select a customer currency filter match',
					'woocommerce'
				),
				/* translators: A sentence describing a customer currency filter. */
				title: __(
					'<title>Customer currency</title> <rule/> <filter/>',
					'woocommerce'
				),
				filter: __( 'Select a customer currency', 'woocommerce' ),
			},
			rules: [
				{
					value: 'is',
					/* translators: Sentence fragment, logical, "Is" refers to searching for orders matching a chosen currency. */
					label: _x( 'Is', 'customer currency', 'woocommerce' ),
				},
				{
					value: 'is_not',
					/* translators: Sentence fragment, logical, "Is Not" refers to searching for orders not matching a chosen currency. */
					label: _x( 'Is Not', 'customer currency', 'woocommerce' ),
				},
			],
			input: {
				component: 'SelectControl',
				options: getCustomerCurrencies(),
			},
			allowMultiple: true,
		},
		...advancedFilters.filters,
	},
} );

export const addCustomerCurrencyColumn = (
	tableData: ReportTableData
): ReportTableData => {
	if ( ! tableData.items?.data?.length || tableData.endpoint !== 'orders' ) {
		return tableData;
	}

	const items = tableData.items.data;

	return {
		...tableData,
		headers: [
			...tableData.headers,
			{
				isNumeric: false,
				isSortable: false,
				key: 'customer_currency',
				label: __( 'Customer currency', 'woocommerce' ),
				required: false,
				screenReaderLabel: __( 'Customer currency', 'woocommerce' ),
			},
		],
		rows: tableData.rows.map( ( row, index ) => {
			const currency = String( items[ index ]?.order_currency ?? '' );

			return [ ...row, { display: currency, value: currency } ];
		} ),
	};
};

export const addCustomerCurrencyReportFilter = (
	filters: Record< string, unknown >[]
): Record< string, unknown >[] => [
	{
		label: __( 'Customer currency', 'woocommerce' ),
		param: 'currency',
		staticParams: [],
		showFilters: () => true,
		filters: [
			{
				label: __( 'All currencies', 'woocommerce' ),
				value: 'all',
			},
			...getCustomerCurrencies(),
		],
	},
	...filters,
];

export const applyReportCurrencySymbol = (
	config: Record< string, unknown >,
	{ currency }: { currency?: string } = {}
): Record< string, unknown > => {
	const symbol = currency
		? getSetting< Record< string, string > >(
				'customerCurrencySymbols',
				{}
		  )[ currency ]
		: undefined;

	return symbol ? { ...config, symbol } : config;
};

// The plugin's namespace, kept so code that removes these callbacks by namespace still can (client 11.1.0 `analytics/index.js:18`).
addFilter(
	'woocommerce_admin_orders_report_advanced_filters',
	'woocommerce-payments',
	addCustomerCurrencyAdvancedFilter
);
addFilter(
	'woocommerce_admin_report_table',
	'woocommerce-payments',
	addCustomerCurrencyColumn
);
addFilter(
	'woocommerce_admin_orders_report_filters',
	'woocommerce-payments',
	addCustomerCurrencyReportFilter
);
// Show the selected currency's symbol in the report totals.
addFilter(
	'woocommerce_admin_report_currency',
	'woocommerce-payments',
	applyReportCurrencySymbol
);
