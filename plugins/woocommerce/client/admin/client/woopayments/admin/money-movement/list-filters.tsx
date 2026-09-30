/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export type WooPaymentsListFilter = {
	id: string;
	label: string;
	value: string;
	options: Array< { label: string; value: string } >;
	onChange: ( value: string ) => void;
};

/**
 * The selects above a list, such as "Show" and the currency picker, standing in
 * for the client's `ReportFilters` pickers (client 11.1.0 `disputes/filters/index.tsx`).
 *
 * @param props         The component props.
 * @param props.filters The selects to show, in order.
 */
export const WooPaymentsListFilters = ( {
	filters,
}: {
	filters: WooPaymentsListFilter[];
} ) => (
	<div className="woocommerce-woopayments-money-movement__filters">
		{ filters.map( ( filter ) => (
			<SelectControl
				key={ filter.id }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ filter.label }
				value={ filter.value }
				options={ filter.options }
				onChange={ filter.onChange }
			/>
		) ) }
	</div>
);

/**
 * The client's "Show" choices: `all` is the default and stays out of the URL.
 */
export type WooPaymentsListShowFilter =
	| 'all'
	| 'advanced'
	| 'awaiting_response';

/**
 * Reads the list's `filter` argument; anything the list does not offer shows everything.
 *
 * @param search  The route's query string.
 * @param offered The choices the list offers.
 */
export const getListShowFilter = (
	search: string,
	offered: WooPaymentsListShowFilter[]
): WooPaymentsListShowFilter => {
	const value = new URLSearchParams( search ).get( 'filter' );

	return offered.find( ( filter ) => filter === value ) || 'all';
};

/**
 * Whether the advanced filters match all or any, the client's `match` argument; `all` stays out of the URL.
 */
export type WooPaymentsListMatch = 'all' | 'any';

/**
 * Reads the list's `match` argument.
 *
 * @param search The route's query string.
 */
export const getListMatch = ( search: string ): WooPaymentsListMatch =>
	new URLSearchParams( search ).get( 'match' ) === 'any' ? 'any' : 'all';

/**
 * Adds the "Show" choice, and "match any" for the advanced filters, to a list route,
 * as the client's FilterPicker and AdvancedFilters keep them in the URL.
 *
 * @param route      The list route.
 * @param showFilter The "Show" choice.
 * @param match      Whether the advanced filters match all or any.
 */
export const withListShowFilter = (
	route: string,
	showFilter: WooPaymentsListShowFilter,
	match: WooPaymentsListMatch = 'all'
) => {
	const params = new URLSearchParams();

	if ( showFilter !== 'all' ) {
		params.append( 'filter', showFilter );
	}

	if ( showFilter === 'advanced' && match === 'any' ) {
		params.append( 'match', 'any' );
	}

	const extra = params.toString();

	if ( ! extra ) {
		return route;
	}

	return `${ route }${ route.includes( '?' ) ? '&' : '?' }${ extra }`;
};

/**
 * The advanced filters' "match all or any" select.
 * Client 11.1.0 `AdvancedFilters`, for example "Disputes match <select /> filters" (`disputes/filters/config.ts:104-114`).
 *
 * @param label    The select label, such as "Disputes match".
 * @param match    The current choice.
 * @param onChange Called with the new choice.
 */
export const getListMatchFilter = (
	label: string,
	match: WooPaymentsListMatch,
	onChange: ( value: string ) => void
): WooPaymentsListFilter => ( {
	id: 'match',
	label,
	value: match,
	options: [
		{ label: __( 'All filters', 'woocommerce' ), value: 'all' },
		{ label: __( 'Any filter', 'woocommerce' ), value: 'any' },
	],
	onChange,
} );

// Client 11.1.0 FilterPicker `update()`: any choice but "Advanced filters" drops the advanced filters.
const ADVANCED_FILTER_PARAMS = [
	'match',
	'type_is',
	'type_is_not',
	'status_is',
	'status_is_not',
	'date_after',
	'date_before',
	'date_between',
] as const;

/**
 * The list query for a new "Show" choice: the page, sort, search and currency stay.
 *
 * @param query      The list query.
 * @param showFilter The new "Show" choice.
 */
export const getQueryForShowFilter = <
	Query extends Record< string, unknown >,
>(
	query: Query,
	showFilter: WooPaymentsListShowFilter
): Query => {
	const nextQuery = { ...query };

	if ( showFilter !== 'advanced' ) {
		ADVANCED_FILTER_PARAMS.forEach( ( param ) => {
			delete nextQuery[ param ];
		} );
	}

	return nextQuery;
};
