/**
 * External dependencies
 */
import { Card, CardBody, CardFooter } from '@wordpress/components';
import { TableSummary, TableSummaryPlaceholder } from '@woocommerce/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import { DataViews, type Field, type View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import '../dataviews.scss';
import { useDataViewsReloadState } from './use-dataviews-reload-state';

export type WooPaymentsMoneyMovementDataViewsProps<
	Item extends { id?: string },
> = {
	fields: Field< Item >[];
	rows: Item[];
	view: View;
	onChangeView: ( view: View ) => void;
	total: number;
	isLoading: boolean;
	/** The card title, like the client's `TableCard` `title`. */
	title?: string;
	/** The card footer's summary, like the client's `TableCard` `summary`. */
	summary?: Array< { label: string; value: string } >;
	/** Fields aligned to the end, like the client's `isNumeric` columns. */
	numericFields?: string[];
	toolbarActions?: ReactNode;
	loadingMessage?: ReactNode;
	getItemId?: ( item: Item ) => string;
};

// Client 11.1.0 `TableCard` pagination: `@woocommerce/components` `DEFAULT_PER_PAGE_OPTIONS`.
const PER_PAGE_SIZES = [ 25, 50, 75, 100 ];

/**
 * A list in a card, standing in for the client's `TableCard`: the title,
 * search and actions share the card's top row above the table.
 */
export function WooPaymentsMoneyMovementDataViews<
	Item extends { id?: string },
>( {
	fields,
	rows,
	view,
	onChangeView,
	total,
	isLoading,
	title,
	summary,
	numericFields,
	toolbarActions,
	loadingMessage,
	getItemId,
}: WooPaymentsMoneyMovementDataViewsProps< Item > ) {
	const perPage = view.perPage || 25;
	const tableView = numericFields?.length
		? {
				...view,
				layout: {
					...view.layout,
					styles: {
						...view.layout?.styles,
						...Object.fromEntries(
							numericFields.map( ( field ) => [
								field,
								{ align: 'end' },
							] )
						),
					},
				},
		  }
		: view;
	const headerContent =
		title || toolbarActions ? (
			<div className="woocommerce-woopayments-money-movement-dataviews__header">
				{ title && (
					<h2 className="woocommerce-woopayments-money-movement-dataviews__title">
						{ title }
					</h2>
				) }
				{ toolbarActions && (
					<div className="woocommerce-woopayments-money-movement-dataviews__actions">
						{ toolbarActions }
					</div>
				) }
			</div>
		) : undefined;
	const resolvedGetItemId =
		getItemId || ( ( item: Item ) => String( item.id || '' ) );
	const { data, listRef } = useDataViewsReloadState< Item, HTMLDivElement >(
		rows,
		isLoading
	);
	return (
		<Card
			ref={ listRef }
			className="woocommerce-woopayments-money-movement-dataviews"
		>
			{ loadingMessage && (
				<div
					role="status"
					aria-live="polite"
					aria-atomic="true"
					aria-busy={ isLoading }
					className="screen-reader-text"
				>
					{ isLoading ? loadingMessage : '' }
				</div>
			) }
			<CardBody>
				<DataViews
					view={ tableView }
					onChangeView={ onChangeView }
					fields={ fields }
					data={ data }
					isLoading={ isLoading }
					// The lists that search do it with their own field (`transaction-search.tsx`), not DataViews' text search.
					search={ false }
					header={ headerContent }
					paginationInfo={ {
						totalItems: total,
						totalPages: Math.ceil( total / perPage ),
					} }
					defaultLayouts={ {
						table: {},
					} }
					config={ { perPageSizes: PER_PAGE_SIZES } }
					getItemId={ resolvedGetItemId }
					empty={
						<p className="woocommerce-woopayments-money-movement-dataviews__empty">
							{
								// Client 11.1.0 `TableCard` without `emptyMessage`: `@woocommerce/components` Table's default.
								__( 'No data to display', 'woocommerce' )
							}
						</p>
					}
				/>
			</CardBody>
			{ /* Client 11.1.0 `@woocommerce/components` TableCard: the footer shows TableSummaryPlaceholder while loading. */ }
			{ isLoading ? (
				<CardFooter>
					<TableSummaryPlaceholder />
				</CardFooter>
			) : (
				summary &&
				summary.length > 0 && (
					<CardFooter>
						<TableSummary data={ summary } />
					</CardFooter>
				)
			) }
		</Card>
	);
}
