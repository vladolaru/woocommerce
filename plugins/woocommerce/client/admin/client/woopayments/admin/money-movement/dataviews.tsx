/**
 * External dependencies
 */
import { Card, CardBody, CardFooter } from '@wordpress/components';
import { TableSummary } from '@woocommerce/components';
import type { ReactNode } from 'react';

// @ts-expect-error - Use the WordPress-bundled DataViews entry in wp-admin builds.
import { DataViews, type Field, type View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import '../dataviews.scss';

export type WooPaymentsMoneyMovementDataViewsProps<
	Item extends { id?: string },
> = {
	fields: Field< Item >[];
	rows: Item[];
	view: View;
	onChangeView: ( view: View ) => void;
	total: number;
	isLoading: boolean;
	search?: boolean;
	searchLabel: string;
	/** The card title, like the client's `TableCard` `title`. */
	title?: string;
	/** The card footer's summary, like the client's `TableCard` `summary`. */
	summary?: Array< { label: string; value: string } >;
	toolbarActions?: ReactNode;
	empty?: ReactNode;
	loadingMessage?: ReactNode;
	getItemId?: ( item: Item ) => string;
};

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
	search = true,
	searchLabel,
	title,
	summary,
	toolbarActions,
	empty,
	loadingMessage,
	getItemId,
}: WooPaymentsMoneyMovementDataViewsProps< Item > ) {
	const perPage = view.perPage || 25;
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

	return (
		<Card className="woocommerce-woopayments-money-movement-dataviews">
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
					view={ view }
					onChangeView={ onChangeView }
					fields={ fields }
					data={ rows }
					isLoading={ isLoading }
					search={ search }
					searchLabel={ searchLabel }
					header={ headerContent }
					paginationInfo={ {
						totalItems: total,
						totalPages: Math.ceil( total / perPage ),
					} }
					defaultLayouts={ {
						table: {},
					} }
					getItemId={ resolvedGetItemId }
					empty={ empty }
				/>
			</CardBody>
			{ summary && summary.length > 0 && (
				<CardFooter>
					<TableSummary data={ summary } />
				</CardFooter>
			) }
		</Card>
	);
}
