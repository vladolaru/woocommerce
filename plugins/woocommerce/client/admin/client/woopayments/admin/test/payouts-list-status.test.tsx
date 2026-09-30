/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsPayouts } from '../payouts';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import { setMockUserPreferences } from './helpers/user-preferences';

type MockField = {
	id: string;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

// Renders only the status cell of each row, keyed by payout id.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: MockField[];
	} ) => {
		const status = fields.find( ( field ) => field.id === 'status' );

		return (
			<div>
				{ data.map( ( item ) => (
					<div
						key={ String( item.id ) }
						data-testid={ `status-${ String( item.id ) }` }
					>
						{ status?.render?.( { item } ) }
					</div>
				) ) }
			</div>
		);
	},
} ) );

const mockGetDeposits = getWooPaymentsDeposits as jest.MockedFunction<
	typeof getWooPaymentsDeposits
>;
const mockGetSummary = getWooPaymentsDepositsSummary as jest.MockedFunction<
	typeof getWooPaymentsDepositsSummary
>;

// Recorded native :8889 paid deposit; see the fixture's `_meta`.
const [ RECORDED_PAID_DEPOSIT ] = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, 'fixtures/recorded-payouts-list.json' ),
		'utf8'
	)
).response.data as Array< Record< string, unknown > >;

const row = ( id: string, type: string, status: string ) => ( {
	...RECORDED_PAID_DEPOSIT,
	id,
	type,
	status,
} );

// Client 11.1.0 `deposits/strings.ts:24-32` via `deposits/list/index.tsx:152` and `components/deposit-status-chip/index.tsx:30-35`.
// Chip colours from client 11.1.0 `components/deposit-status-chip/index.tsx:18-24`.
const CLIENT_STATUS_COPY: Array< [ string, string, string, string ] > = [
	[ 'deposit', 'paid', 'Completed (paid)', 'success' ],
	[ 'withdrawal', 'paid', 'Completed (deducted)', 'success' ],
	[ 'deposit', 'pending', 'Pending', 'warning' ],
	[ 'deposit', 'in_transit', 'In transit', 'primary' ],
	[ 'deposit', 'canceled', 'Canceled', 'info' ],
	[ 'deposit', 'failed', 'Failed', 'error' ],
	[ 'withdrawal', 'pending', 'Pending', 'warning' ],
	[ 'withdrawal', 'failed', 'Failed', 'error' ],
];

describe( 'WooPayments payouts list status copy', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		window.wcSettings = {
			adminUrl: 'https://example.com/wp-admin/',
		} as typeof window.wcSettings;
		mockGetDeposits.mockReset();
		mockGetSummary.mockReset();
		mockGetSummary.mockResolvedValue( {} );
	} );

	it( 'shows the recorded paid deposit as the client does', async () => {
		mockGetDeposits.mockResolvedValue( {
			data: [ RECORDED_PAID_DEPOSIT ] as never,
			total_count: 1,
		} );

		render(
			<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
				<WooPaymentsPayouts />
			</MemoryRouter>
		);

		expect(
			await screen.findByTestId(
				`status-${ String( RECORDED_PAID_DEPOSIT.id ) }`
			)
		).toHaveTextContent( /^Completed \(paid\)$/ );
	} );

	it.each( CLIENT_STATUS_COPY )(
		'shows a %s with status %s as a "%s" %s chip',
		async ( type, status, label, chipType ) => {
			mockGetDeposits.mockResolvedValue( {
				data: [ row( 'po_row', type, status ) ] as never,
				total_count: 1,
			} );

			render(
				<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
					<WooPaymentsPayouts />
				</MemoryRouter>
			);

			expect(
				await screen.findByTestId( 'status-po_row' )
			).toHaveTextContent(
				new RegExp( `^${ label.replace( /[()]/g, '\\$&' ) }$` )
			);
			expect( screen.getByText( label ) ).toHaveClass(
				'woocommerce-status-badge',
				`woocommerce-status-badge--${ chipType }`
			);
		}
	);
} );
