/**
 * External dependencies
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { setSiteDateFormats } from './helpers/site-date-formats';
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import { getRiskLevelLabel } from '../money-movement/transactions-list-fields';
import {
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorizationsSummary,
} from '../money-movement/data';
import { setMockUserPreferences } from './helpers/user-preferences';

type MockField = {
	id: string;
	label?: ReactNode;
	enableHiding?: boolean;
	enableSorting?: boolean;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

let mockLastFields: MockField[] = [];

beforeAll( setSiteDateFormats );

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () => ( { push: jest.fn() } ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

// Keeps the fields the page hands to DataViews so the column contract can be read directly.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		fields = [],
		view = {},
	}: {
		fields?: MockField[];
		view?: { fields?: string[] };
	} ) => {
		if ( fields.length > 0 ) {
			mockLastFields = fields;
		}

		return (
			<div
				data-testid="uncaptured-dataviews"
				data-visible-fields={ view.fields?.join( ',' ) }
			/>
		);
	},
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsAuthorizations: jest.fn(),
	getWooPaymentsAuthorizationsSummary: jest.fn(),
	captureWooPaymentsAuthorization: jest.fn(),
	cancelWooPaymentsAuthorization: jest.fn(),
} ) );

// Client 11.1.0 `transactions/uncaptured/__tests__/index.test.tsx` mock authorization.
const AUTHORIZATION = {
	created: '2020-01-02 17:46:02',
	captured: false,
	order_id: 24,
	risk_level: 2,
	amount: 1455,
	customer_email: 'good_boy@doge.com',
	customer_country: 'Kingdom of Dogs',
	customer_name: 'Good boy',
	payment_intent_id: 'pi_4242',
	charge_id: 'ch_mock',
	currency: 'usd',
};

const renderUncaptured = async () => {
	render(
		<MemoryRouter
			initialEntries={ [ '/woopayments/transactions?view=uncaptured' ] }
		>
			<WooPaymentsTransactionsPage />
		</MemoryRouter>
	);

	await waitFor( () => expect( mockLastFields.length ).toBeGreaterThan( 0 ) );
};

const getField = ( id: string ) => {
	const field = mockLastFields.find( ( candidate ) => candidate.id === id );

	if ( ! field?.render ) {
		throw new Error( `No rendered field ${ id }` );
	}

	return field as Required< Pick< MockField, 'render' > > & MockField;
};

const renderCell = ( id: string, item: Record< string, unknown > ) => {
	const { container } = render(
		<div>{ getField( id ).render( { item } ) }</div>
	);

	return container.textContent;
};

describe( 'WooPayments uncaptured transactions list', () => {
	beforeEach( () => {
		mockLastFields = [];
		setMockUserPreferences( {} );
		(
			getWooPaymentsAuthorizations as jest.MockedFunction<
				typeof getWooPaymentsAuthorizations
			>
		 ).mockResolvedValue( { data: [ AUTHORIZATION ], total_count: 1 } );
		(
			getWooPaymentsAuthorizationsSummary as jest.MockedFunction<
				typeof getWooPaymentsAuthorizationsSummary
			>
		 ).mockResolvedValue( {
			count: 1,
			total: 1455,
			currency: 'usd',
			all_currencies: [ 'usd' ],
		} );
	} );

	// Client 11.1.0 `transactions/uncaptured/index.tsx:43-108`.
	it( 'has the client column set, labels, required flags and sorting', async () => {
		await renderUncaptured();

		expect(
			mockLastFields.map(
				( { id, label, enableHiding, enableSorting } ) => ( {
					id,
					label,
					required: enableHiding === false,
					sortable: enableSorting !== false,
				} )
			)
		).toEqual( [
			{
				id: 'created',
				label: 'Authorized on',
				required: true,
				sortable: true,
			},
			{
				id: 'capture_by',
				label: 'Capture by',
				required: true,
				sortable: true,
			},
			{ id: 'order', label: 'Order', required: true, sortable: false },
			{
				id: 'risk_level',
				label: 'Risk level',
				required: false,
				sortable: false,
			},
			{ id: 'amount', label: 'Amount', required: false, sortable: true },
			{
				id: 'customer_email',
				label: 'Email',
				required: false,
				sortable: false,
			},
			{
				id: 'customer_country',
				label: 'Country',
				required: false,
				sortable: false,
			},
			{ id: 'action', label: 'Action', required: true, sortable: false },
		] );
	} );

	it( 'hides Email and Country until the merchant shows them', async () => {
		await renderUncaptured();

		expect( screen.getByTestId( 'uncaptured-dataviews' ) ).toHaveAttribute(
			'data-visible-fields',
			'created,capture_by,order,risk_level,amount,action'
		);
	} );

	it( 'shows Email and Country when the stored preference hides nothing', async () => {
		setMockUserPreferences( {
			wc_payments_transactions_uncaptured_hidden_columns: [],
		} );

		await renderUncaptured();

		expect( screen.getByTestId( 'uncaptured-dataviews' ) ).toHaveAttribute(
			'data-visible-fields',
			'created,capture_by,order,risk_level,amount,customer_email,customer_country,action'
		);
	} );

	it( 'maps the risk level to the client labels', async () => {
		await renderUncaptured();

		expect( renderCell( 'risk_level', { risk_level: 0 } ) ).toBe(
			'Normal'
		);
		expect( renderCell( 'risk_level', { risk_level: 1 } ) ).toBe(
			'Elevated'
		);
		expect( renderCell( 'risk_level', { risk_level: 2 } ) ).toBe(
			'Highest'
		);
		expect( renderCell( 'risk_level', { risk_level: 99 } ) ).toBe( 'N/A' );
	} );

	it( 'shows the order number with the customer name, like the client', async () => {
		await renderUncaptured();

		expect( renderCell( 'order', AUTHORIZATION ) ).toBe( '#24 Good boy' );
		expect(
			renderCell( 'order', { ...AUTHORIZATION, customer_name: '' } )
		).toBe( '#24' );
	} );

	it( 'shows the email and country cells', async () => {
		await renderUncaptured();

		expect( renderCell( 'customer_email', AUTHORIZATION ) ).toBe(
			'good_boy@doge.com'
		);
		expect( renderCell( 'customer_country', AUTHORIZATION ) ).toBe(
			'Kingdom of Dogs'
		);
	} );

	it( "offers the client's one secondary Capture button and opens the details from every other cell", async () => {
		await renderUncaptured();

		const { container } = render(
			<div>
				{ getField( 'action' ).render( { item: AUTHORIZATION } ) }
			</div>
		);

		// Client 11.1.0 `transactions/uncaptured/index.tsx:187-202` and
		// `components/capture-authorization-button`: one secondary "Capture", no cancel.
		expect(
			within( container )
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Capture' ] );
		expect( within( container ).getByRole( 'button' ) ).toHaveClass(
			'is-secondary'
		);

		// Client 11.1.0 `transactions/uncaptured/index.tsx:128-186`: the `clickable()` cells.
		[
			'created',
			'capture_by',
			'risk_level',
			'amount',
			'customer_email',
			'customer_country',
		].forEach( ( id ) => {
			const cell = render(
				<div>{ getField( id ).render( { item: AUTHORIZATION } ) }</div>
			);
			const link = within( cell.container ).getByRole( 'link' );

			expect( link ).toHaveAttribute(
				'href',
				expect.stringContaining(
					'path=%2Fwoopayments%2Ftransactions%2Fdetails&id=pi_4242'
				)
			);
			expect( link ).toHaveAttribute( 'tabindex', '-1' );
			cell.unmount();
		} );
	} );

	it( 'shows the authorized and capture-by dates with their time', async () => {
		await renderUncaptured();

		// Client 11.1.0 `transactions/uncaptured/index.tsx:141-161`: site formats, capture by seven days later.
		expect( renderCell( 'created', AUTHORIZATION ) ).toBe(
			'January 2, 2020 / 5:46 pm'
		);
		expect( renderCell( 'capture_by', AUTHORIZATION ) ).toBe(
			'January 9, 2020 / 5:46 pm'
		);
	} );
} );

// Client 11.1.0 `components/risk-level/index.tsx` indexes `riskOrder[ risk ]`.
describe( 'getRiskLevelLabel', () => {
	it.each( [
		[ 0, 'Normal' ],
		[ '1', 'Elevated' ],
		[ 2, 'Highest' ],
		[ 3, 'N/A' ],
		[ null, 'N/A' ],
		[ undefined, 'N/A' ],
		[ '', 'N/A' ],
	] )( 'maps %p to %s', ( risk, label ) => {
		expect( getRiskLevelLabel( risk ) ).toBe( label );
	} );
} );
