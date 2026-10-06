/**
 * External dependencies
 */
import { Button, Dropdown } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import clsx from 'clsx';

export type WooPaymentsListFilter = {
	id: string;
	label: string;
	value: string;
	options: Array< { label: string; value: string } >;
	onChange: ( value: string ) => void;
};

/**
 * One filter as the client's `FilterPicker` draws it (client 11.1.0 `disputes/filters/index.tsx`,
 * `@woocommerce/components` FilterPicker): a choice applies only when its button is clicked, so
 * moving through the choices with the keyboard never reloads the list.
 *
 * @param props        The component props.
 * @param props.filter The filter to show.
 */
export const WooPaymentsFilterPicker = ( {
	filter,
}: {
	filter: WooPaymentsListFilter;
} ) => {
	const labelId = `woocommerce-woopayments-filter-picker-${ useInstanceId(
		WooPaymentsFilterPicker
	) }`;
	const selected =
		filter.options.find( ( option ) => option.value === filter.value ) ||
		filter.options[ 0 ];

	return (
		<div className="woocommerce-filters-filter">
			<span id={ labelId } className="woocommerce-filters-label">
				{ filter.label }
			</span>
			<Dropdown
				contentClassName="woocommerce-filters-filter__content"
				popoverProps={ { placement: 'bottom' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					// The `@woocommerce/components` DropdownButton markup FilterPicker uses.
					<Button
						id={ `${ labelId }-toggle` }
						className={ clsx( 'woocommerce-dropdown-button', {
							'is-open': isOpen,
						} ) }
						aria-labelledby={ `${ labelId } ${ labelId }-toggle` }
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						<div className="woocommerce-dropdown-button__labels">
							<span>{ selected?.label }</span>
						</div>
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<ul
						className="woocommerce-filters-filter__content-list"
						aria-labelledby={ labelId }
					>
						{ filter.options.map( ( option ) => (
							<li
								key={ option.value }
								className={ clsx(
									'woocommerce-filters-filter__content-list-item',
									{
										'is-selected':
											option.value === filter.value,
									}
								) }
							>
								<Button
									className="woocommerce-filters-filter__button"
									aria-current={
										option.value === filter.value
											? 'true'
											: undefined
									}
									onClick={ () => {
										onClose();
										// FilterPicker `renderButton()`: the current choice only closes the list.
										if ( option.value !== filter.value ) {
											filter.onChange( option.value );
										}
									} }
								>
									{ option.label }
								</Button>
							</li>
						) ) }
					</ul>
				) }
			/>
		</div>
	);
};

/**
 * The filters above a list, such as "Show" and the currency picker, standing in
 * for the client's `ReportFilters` pickers (client 11.1.0 `disputes/filters/index.tsx`).
 *
 * @param props         The component props.
 * @param props.filters The filters to show, in order.
 */
export const WooPaymentsListFilters = ( {
	filters,
}: {
	filters: WooPaymentsListFilter[];
} ) => (
	<div className="woocommerce-woopayments-money-movement__filters">
		{ filters.map( ( filter ) => (
			<WooPaymentsFilterPicker key={ filter.id } filter={ filter } />
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
 * The advanced filters' "match all or any" choice, drawn as a filter picker like "Show".
 * Client 11.1.0 `AdvancedFilters`, for example "Disputes match <select /> filters" (`disputes/filters/config.ts:104-114`).
 *
 * @param label    The filter label, such as "Disputes match".
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
	'customer_country_is',
	'customer_country_is_not',
	'customer_currency_is',
	'customer_currency_is_not',
	'source_device_is',
	'source_device_is_not',
	'source_is',
	'source_is_not',
	'channel_is',
	'channel_is_not',
	'risk_level_is',
	'risk_level_is_not',
	'loan_id_is',
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
