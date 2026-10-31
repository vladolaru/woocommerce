/**
 * External dependencies
 */
import { act, render, waitFor } from '@testing-library/react';
import type { ReactElement } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsPayouts } from '../payouts';
import { WooPaymentsDocumentsPage } from '../documents/page';
import {
	getWooPaymentsDisputes,
	getWooPaymentsDisputesSummary,
} from '../money-movement/data';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import {
	getWooPaymentsDocuments,
	getWooPaymentsDocumentsAccount,
	getWooPaymentsDocumentsSummary,
} from '../documents/data';
import { setMockUserPreferences } from './helpers/user-preferences';
import {
	SettingsShellHistoryBridge,
	shellHistory,
} from './helpers/settings-shell-history';

type MockFilter = { field: string; operator: string; value?: unknown };
type MockView = { filters?: MockFilter[] };

let mockView: MockView = {};
let mockOnChangeView: ( view: MockView ) => void = () => undefined;

// Keeps the view each list hands to DataViews and the callback DataViews calls on a change.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		view,
		onChangeView,
	}: {
		view: MockView;
		onChangeView: ( view: MockView ) => void;
	} ) => {
		mockView = view;
		mockOnChangeView = onChangeView;

		return null;
	},
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () =>
		jest.requireActual( './helpers/settings-shell-history' ).shellHistory,
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsDisputes: jest.fn(),
	getWooPaymentsDisputesSummary: jest.fn(),
	getWooPaymentsDisputesExportUrl: jest.fn(),
	requestWooPaymentsDisputesExport: jest.fn(),
} ) );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );

jest.mock( '../documents/data', () => ( {
	buildWooPaymentsDocumentUrl: jest.fn(),
	getWooPaymentsDocuments: jest.fn(),
	getWooPaymentsDocumentsAccount: jest.fn(),
	getWooPaymentsDocumentsSummary: jest.fn(),
	saveWooPaymentsVatDetails: jest.fn(),
	validateWooPaymentsVatNumber: jest.fn(),
} ) );

const mocked = ( fn: unknown ) => fn as jest.Mock;

beforeEach( () => {
	setMockUserPreferences( {} );
	window.wcSettings = {
		adminUrl: 'http://example.com/wp-admin/',
	} as typeof window.wcSettings;
	shellHistory.push.mockClear();
	shellHistory.replace.mockClear();
	// Empty lists and summaries are deliberate sentinels: the rows only need each list to load.
	// The summaries carry `count` alone, a partial shape (client 11.1.0 `types/disputes.d.ts:157-161`,
	// `types/deposits.d.ts:44-48`; Documents reads its summary as returned, `data/documents/resolvers.js:41-76`).
	mocked( getWooPaymentsDisputes ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	mocked( getWooPaymentsDisputesSummary ).mockResolvedValue( { count: 0 } );
	mocked( getWooPaymentsDeposits ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	mocked( getWooPaymentsDepositsSummary ).mockResolvedValue( { count: 0 } );
	// The native wrapper's `{ documents }` shape (`documents/data.ts:61-74`, from `SharedSettings.php:184-188`), whose
	// flags mirror client 11.1.0 `includes/class-wc-payments-account.php:383-384`; `country` is left out on purpose.
	mocked( getWooPaymentsDocumentsAccount ).mockResolvedValue( {
		documents: { enabled: true, has_submitted_vat_data: true },
	} );
	mocked( getWooPaymentsDocuments ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	mocked( getWooPaymentsDocumentsSummary ).mockResolvedValue( { count: 0 } );
} );

const RANGE = 'filter=advanced&date_between=2026-07-08&date_between=2026-07-09';

// DataViews 18.0.0 (Gutenberg #81150) commits a range with one bound empty while the merchant
// types the other; the list keeps that draft in its view, as the transactions list does, and
// leaves the applied URL and its request alone until the range is complete.
describe( 'A half-typed date range', () => {
	it.each( [
		[
			'disputes',
			`/woopayments/disputes?${ RANGE }`,
			() => <WooPaymentsDisputesPage />,
			getWooPaymentsDisputes,
			'created',
		],
		[
			'payouts',
			`/woopayments/payouts?${ RANGE }`,
			() => <WooPaymentsPayouts />,
			getWooPaymentsDeposits,
			'date',
		],
		[
			'documents',
			`/woopayments/documents?${ RANGE }`,
			() => <WooPaymentsDocumentsPage />,
			getWooPaymentsDocuments,
			'date',
		],
	] as Array< [ string, string, () => ReactElement, unknown, string ] > )(
		'stays as a draft in the %s list without a new request',
		async ( _name, route, element, request, field ) => {
			render(
				<MemoryRouter initialEntries={ [ route ] }>
					<SettingsShellHistoryBridge />
					{ element() }
				</MemoryRouter>
			);
			await waitFor( () => expect( request ).toHaveBeenCalled() );
			expect( mockView.filters ).toContainEqual(
				expect.objectContaining( { field, operator: 'between' } )
			);
			const requests = mocked( request ).mock.calls.length;
			const draft = {
				field,
				operator: 'between',
				value: [ '2026-07-08', '' ],
			};

			act( () =>
				mockOnChangeView( {
					...mockView,
					filters: ( mockView.filters || [] ).map( ( filter ) =>
						filter.field === field ? draft : filter
					),
				} )
			);

			expect( mockView.filters ).toContainEqual( draft );
			expect( shellHistory.push ).not.toHaveBeenCalled();
			expect( request ).toHaveBeenCalledTimes( requests );
		}
	);
} );
