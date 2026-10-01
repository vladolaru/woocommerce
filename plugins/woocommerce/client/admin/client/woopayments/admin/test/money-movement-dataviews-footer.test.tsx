/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPaymentsMoneyMovementDataViews } from '../money-movement/dataviews';
import { isStyledBy } from './helpers/compiled-rules';

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: () => <div />,
} ) );

test( "centres the list summary in the card footer, as the client's TableCard does", () => {
	render(
		<WooPaymentsMoneyMovementDataViews
			fields={ [] }
			rows={ [] }
			view={ { type: 'table', fields: [] } }
			onChangeView={ () => {} }
			total={ 3 }
			isLoading={ false }
			searchLabel="Search"
			summary={ [ { label: 'transactions', value: '3' } ] }
		/>
	);
	const footer = document.querySelector(
		'.components-card__footer'
	) as HTMLElement;

	// Client 11.1.0 `@woocommerce/components` `.woocommerce-table__summary { text-align: center }`.
	expect( footer ).toContainElement(
		document.querySelector( '.woocommerce-table__summary' ) as HTMLElement
	);
	expect(
		isStyledBy( footer, 'dataviews.scss', 'justify-content', 'center' )
	).toBe( true );
} );
