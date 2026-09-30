/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';

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
