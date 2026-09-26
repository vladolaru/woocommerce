/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { MultiCurrencySettingsApp } from '../app';
import type { StoreCurrenciesResponse } from '../types';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		useDispatch: jest.fn( () => ( {
			createSuccessNotice: mockCreateSuccessNotice,
			createErrorNotice: mockCreateErrorNotice,
		} ) ),
	};
} );

jest.mock( '../store-settings', () => ( {
	StoreLevelSettings: () => <div>Store settings component</div>,
} ) );

jest.mock( '../currency-settings-modal', () => ( {
	CurrencySettingsModal: ( {
		currency,
		onClose,
		onSaved,
	}: {
		currency: StoreCurrenciesResponse[ 'default' ];
		onClose: () => void;
		onSaved: ( currencyCode: string, manualRate: number | null ) => void;
	} ) => (
		<div>
			<div>Currency settings modal for { currency.code }</div>
			<button
				type="button"
				onClick={ () => {
					onSaved( currency.code, 0.95 );
					onClose();
				} }
			>
				Save { currency.code } manual rate
			</button>
		</div>
	),
} ) );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const currenciesResponse: StoreCurrenciesResponse = {
	available: {
		USD: {
			id: 'usd',
			code: 'USD',
			name: 'United States (US) dollar',
			rate: 1,
			symbol: '$',
			symbol_position: 'left',
			is_zero_decimal: false,
			is_default: true,
			charm: 0,
			rounding: '0',
			last_updated: null,
		},
		EUR: {
			id: 'eur',
			code: 'EUR',
			name: 'Euro',
			rate: 0.92,
			symbol: '€',
			symbol_position: 'left',
			is_zero_decimal: false,
			is_default: false,
			charm: 0,
			rounding: '0',
			last_updated: 1710000000,
		},
		CAD: {
			id: 'cad',
			code: 'CAD',
			name: 'Canadian dollar',
			rate: 1.34,
			symbol: '$',
			symbol_position: 'left',
			is_zero_decimal: false,
			is_default: false,
			charm: 0,
			rounding: '0',
			last_updated: 1710000000,
		},
		GBP: {
			id: 'gbp',
			code: 'GBP',
			name: 'British pound',
			rate: 0.79,
			symbol: '£',
			symbol_position: 'left',
			is_zero_decimal: false,
			charm: 0,
			rounding: '0',
			last_updated: 1710000000,
		},
		AUD: {
			id: 'aud',
			code: 'AUD',
			name: 'Australian dollar',
			rate: 1.52,
			symbol: '$',
			symbol_position: 'left',
			is_zero_decimal: false,
			charm: 0,
			rounding: '0',
			last_updated: 1710000000,
		},
	},
	enabled: {},
	default: {} as StoreCurrenciesResponse[ 'default' ],
	automatic_rates: {
		available: true,
		source: 'woopayments',
	},
};
currenciesResponse.enabled = {
	USD: currenciesResponse.available.USD,
	EUR: currenciesResponse.available.EUR,
};
currenciesResponse.default = currenciesResponse.available.USD;

const updatedResponse: StoreCurrenciesResponse = {
	...currenciesResponse,
	enabled: {
		USD: currenciesResponse.available.USD,
		CAD: currenciesResponse.available.CAD,
	},
};

const removedCurrencyResponse: StoreCurrenciesResponse = {
	...currenciesResponse,
	enabled: {
		USD: currenciesResponse.available.USD,
	},
};

const addedCurrencyResponse: StoreCurrenciesResponse = {
	...currenciesResponse,
	enabled: {
		USD: currenciesResponse.available.USD,
		EUR: currenciesResponse.available.EUR,
		CAD: currenciesResponse.available.CAD,
	},
};

const submittedSelectionResponse: StoreCurrenciesResponse = {
	...currenciesResponse,
	enabled: {
		USD: currenciesResponse.available.USD,
		GBP: currenciesResponse.available.GBP,
		EUR: currenciesResponse.available.EUR,
		CAD: currenciesResponse.available.CAD,
		AUD: currenciesResponse.available.AUD,
	},
};

function mockInitialFetch() {
	mockApiFetch.mockResolvedValueOnce( currenciesResponse );
}

describe( 'MultiCurrencySettingsApp', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockInitialFetch();
	} );

	it( 'loads and renders enabled currencies', async () => {
		render( <MultiCurrencySettingsApp /> );

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/multi-currency/currencies',
		} );

		expect( await screen.findByText( 'Euro' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'United States (US) dollar' )
		).toBeInTheDocument();
		expect(
			screen.getAllByText( 'Default currency' ).length
		).toBeGreaterThan( 0 );
		expect(
			screen.getByRole( 'button', {
				name: 'Remove Euro as an enabled currency',
			} )
		).toBeInTheDocument();
		// N-085: client 11.1.0 enabled-currencies-list/index.js:73-74 renders the
		// "Name" and "Exchange rate" headers asserted here; native also renders
		// "Code" and "Actions" headers the client does not (Task T.7 Step 5, F-COLS),
		// so the exact header list is deliberately not asserted. list-item.js:65
		// (`! isDefault &&`) hides the Edit/Manage action for the default row.
		expect(
			screen.getByRole( 'columnheader', { name: 'Name' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Exchange rate' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Remove United States (US) dollar as an enabled currency',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Manage United States (US) dollar settings',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'removes a non-default enabled currency', async () => {
		mockApiFetch.mockResolvedValueOnce( removedCurrencyResponse );

		render( <MultiCurrencySettingsApp /> );

		const removeButton = await screen.findByRole( 'button', {
			name: 'Remove Euro as an enabled currency',
		} );
		removeButton.focus();
		fireEvent.click( removeButton );

		// WooPayments 11.1.0 multi-currency-setup.spec.ts:57 ("can remove a
		// currency") defines the enabled-set result. DECISIONS.md (2026-08-08)
		// places that capability in this native search-filtered management UI.
		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-enabled-currencies',
				method: 'POST',
				data: { enabled: [ 'USD' ] },
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
		// The same pinned outcome is rendered locally: Euro and CAD are gone,
		// only USD remains enabled, and the default remains protected.
		await waitFor( () =>
			expect(
				screen.queryByRole( 'button', {
					name: 'Remove Euro as an enabled currency',
				} )
			).not.toBeInTheDocument()
		);
		expect( screen.getByText( 'United States (US) dollar' ) ).toBeVisible();
		expect(
			screen.queryByText( 'Canadian dollar' )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Remove United States (US) dollar as an enabled currency',
			} )
		).not.toBeInTheDocument();
		// The pinned client reports a successful enabled-set update; the native
		// equivalent keeps that acknowledgement as a success notice.
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Enabled currencies updated.'
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', {
					name: 'Add/remove currencies',
				} )
			).toHaveFocus()
		);
	} );

	it( 'opens the currency settings modal for a non-default currency', async () => {
		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Manage Euro settings',
			} )
		);

		expect(
			screen.getByText( 'Currency settings modal for EUR' )
		).toBeInTheDocument();
	} );

	it( 'renders inactive enabled currencies safely and restores Manage focus after closing', async () => {
		mockApiFetch.mockReset();
		const inactiveCurrency = {
			...currenciesResponse.available.CAD,
			rate: null,
		};
		mockApiFetch.mockResolvedValueOnce( {
			...currenciesResponse,
			available: {
				...currenciesResponse.available,
				CAD: inactiveCurrency,
			},
			enabled: {
				USD: currenciesResponse.available.USD,
				CAD: inactiveCurrency,
			},
		} );

		render( <MultiCurrencySettingsApp /> );

		expect(
			await screen.findByText( 'Manual rate required' )
		).toBeInTheDocument();
		const manageButton = screen.getByRole( 'button', {
			name: 'Manage Canadian dollar settings',
		} );
		fireEvent.click( manageButton );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save CAD manual rate' } )
		);

		await waitFor( () => expect( manageButton ).toHaveFocus() );
	} );

	it.each( [
		[
			{ available: true, source: 'woopayments' },
			'Automatic rates are provided by WooPayments.',
		],
		[
			{ available: true, source: 'custom-provider' },
			'Automatic rates are provided by custom-provider.',
		],
		[
			{ available: false, source: null },
			'No automatic-rate provider is available. Set manual rates for enabled currencies.',
		],
		[
			{ available: false, source: 'woopayments' },
			'WooPayments automatic rates are temporarily unavailable. You can use manual rates.',
		],
	] )(
		'renders automatic rate availability notice %s',
		async ( automaticRates, notice ) => {
			mockApiFetch.mockReset();
			mockApiFetch.mockResolvedValueOnce( {
				...currenciesResponse,
				automatic_rates: automaticRates,
			} );

			render( <MultiCurrencySettingsApp /> );

			expect( await screen.findAllByText( notice ) ).not.toHaveLength(
				0
			);
		}
	);

	it( 'updates the displayed exchange rate after saving manual currency settings', async () => {
		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Manage Euro settings',
			} )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save EUR manual rate' } )
		);

		expect( screen.getByText( '0.95' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Currency settings modal for EUR' )
		).not.toBeInTheDocument();
		await waitFor( () => {
			expect(
				screen.getByRole( 'button', {
					name: 'Manage Euro settings',
				} )
			).toHaveFocus();
		} );
	} );

	it( 'keeps modal save focus visible while updating currencies', async () => {
		let resolveSave = ( value: StoreCurrenciesResponse ) => value;
		const savePromise = new Promise< StoreCurrenciesResponse >(
			( resolve ) => {
				resolveSave = resolve;
			}
		);
		mockApiFetch.mockReturnValueOnce( savePromise );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		const updateButton = screen.getByRole( 'button', {
			name: 'Update selected',
		} );
		updateButton.focus();
		fireEvent.click( updateButton );

		await waitFor( () => {
			expect( updateButton ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
		expect( updateButton ).toHaveFocus();

		resolveSave( updatedResponse );

		await waitFor( () => {
			expect(
				screen.queryByRole( 'heading', {
					name: 'Add enabled currencies',
				} )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'adds a selected currency and renders the updated enabled set', async () => {
		mockApiFetch.mockResolvedValueOnce( addedCurrencyResponse );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		expect(
			screen.getByRole( 'heading', { name: 'Add enabled currencies' } )
		).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'checkbox', { name: 'Canadian dollar CAD' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Update selected' } )
		);

		// WooPayments 11.1.0 multi-currency-setup.spec.ts:53 ("can add a new
		// currency") defines the enabled-set result. DECISIONS.md (2026-08-08)
		// identifies this settings modal as the native equivalent of that flow.
		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-enabled-currencies',
				method: 'POST',
				data: { enabled: [ 'USD', 'EUR', 'CAD' ] },
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
		expect(
			await screen.findByRole( 'button', {
				name: 'Remove Canadian dollar as an enabled currency',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Remove Euro as an enabled currency',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Remove United States (US) dollar as an enabled currency',
			} )
		).not.toBeInTheDocument();
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Enabled currencies updated.'
		);
		expect(
			screen.queryByRole( 'heading', { name: 'Add enabled currencies' } )
		).not.toBeInTheDocument();
	} );

	it( 'persists every submitted currency and renders the acknowledged enabled set', async () => {
		mockApiFetch.mockResolvedValueOnce( submittedSelectionResponse );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		expect(
			screen.getByRole( 'heading', { name: 'Add enabled currencies' } )
		).toBeInTheDocument();

		for ( const name of [
			'British pound GBP',
			'Canadian dollar CAD',
			'Australian dollar AUD',
		] ) {
			fireEvent.click( screen.getByRole( 'checkbox', { name } ) );
		}
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Update selected' } )
		);

		// WooPayments 11.1.0 multi-currency-on-boarding.spec.ts:139 defines
		// GBP/EUR/CAD/AUD as the submitted selection. DECISIONS.md (2026-08-08)
		// places the native equivalent in this settings modal.
		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-enabled-currencies',
				method: 'POST',
				data: { enabled: expect.any( Array ) },
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
		const { enabled } = mockApiFetch.mock.calls[ 1 ][ 0 ].data as {
			enabled: string[];
		};
		expect( enabled ).toHaveLength( 5 );
		expect( new Set( enabled ).size ).toBe( 5 );
		expect( new Set( enabled ) ).toEqual(
			new Set( [ 'USD', 'GBP', 'EUR', 'CAD', 'AUD' ] )
		);
		for ( const name of [
			'Remove British pound as an enabled currency',
			'Remove Euro as an enabled currency',
			'Remove Canadian dollar as an enabled currency',
			'Remove Australian dollar as an enabled currency',
		] ) {
			expect(
				await screen.findByRole( 'button', { name } )
			).toBeInTheDocument();
		}
		expect(
			screen.queryByRole( 'button', {
				name: 'Remove United States (US) dollar as an enabled currency',
			} )
		).not.toBeInTheDocument();
		// The pinned client reports a successful enabled-set update; the native
		// equivalent keeps that acknowledgement as a success notice.
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Enabled currencies updated.'
		);
		expect(
			screen.queryByRole( 'heading', { name: 'Add enabled currencies' } )
		).not.toBeInTheDocument();
	} );

	it( 'holds multiple independent selections without writing when cancelled', async () => {
		render( <MultiCurrencySettingsApp /> );

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		const canadianDollarCheckbox = screen.getByRole( 'checkbox', {
			name: 'Canadian dollar CAD',
		} );
		const britishPoundCheckbox = screen.getByRole( 'checkbox', {
			name: 'British pound GBP',
		} );
		await userEvent.click( canadianDollarCheckbox );
		await userEvent.click( britishPoundCheckbox );

		// WooPayments 11.1.0 multi-currency-on-boarding.spec.ts:86 ("should
		// allow multiple currencies to be selected") defines independent,
		// simultaneous selection. DECISIONS.md (2026-08-08) maps that capability
		// to this native search-filtered management modal.
		expect( canadianDollarCheckbox ).toBeChecked();
		expect( britishPoundCheckbox ).toBeChecked();
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel', exact: true } )
		);

		// The decision retains a management UI rather than a wizard: abandoning
		// transient selections must not issue the enabled-currencies POST.
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.queryByRole( 'heading', { name: 'Add enabled currencies' } )
		).not.toBeInTheDocument();
	} );

	it( 'refuses to update on open when only the store default is enabled', async () => {
		// The contract's own shape: a store with exactly the default currency
		// enabled meets a refused control before touching anything, which is
		// where the client's first-run wizard started.
		// Replace the shared USD+EUR fixture queued in beforeEach, rather than
		// queueing behind it.
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValueOnce( {
			...currenciesResponse,
			enabled: { USD: currenciesResponse.available.USD },
		} );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		const updateButton = screen.getByRole( 'button', {
			name: 'Update selected',
		} );
		expect( updateButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( updateButton ).toHaveAccessibleDescription(
			/Select at least one currency/
		);
		// Nothing changed, so nothing is announced: the hint is read with the
		// dialog it opened inside.
		expect( speak ).not.toHaveBeenCalled();

		fireEvent.click( updateButton );

		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'refuses to update when no currency is selected and explains why', async () => {
		// WooPayments 11.1.0 proves the original onboarding rule in
		// multi-currency-on-boarding.spec.ts:59, including the empty-selection
		// refusal that this native management modal retains.
		render( <MultiCurrencySettingsApp /> );

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);
		const euroCheckbox = screen.getByRole( 'checkbox', {
			name: 'Euro EUR',
		} );
		await userEvent.click( euroCheckbox );

		const updateButton = screen.getByRole( 'button', {
			name: 'Update selected',
		} );
		expect( updateButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( updateButton ).not.toHaveAttribute( 'disabled' );
		expect( updateButton ).toHaveAccessibleDescription(
			/Select at least one currency/
		);
		expect(
			screen.getByText(
				/Select at least one currency to update your enabled currencies/
			)
		).toBeVisible();
		// Focus stays on the checkbox the merchant just cleared, so nothing
		// carries the button's description to them: the refusal is announced.
		expect( euroCheckbox ).toHaveFocus();
		expect( speak ).toHaveBeenCalledWith(
			expect.stringMatching( /Select at least one currency/ ),
			'polite'
		);

		const cancelButton = screen.getByRole( 'button', { name: 'Cancel' } );
		cancelButton.focus();
		expect( cancelButton ).toHaveFocus();
		await userEvent.tab();
		expect( updateButton ).toHaveFocus();

		await userEvent.keyboard( '{Enter}' );
		await userEvent.keyboard( ' ' );

		// Only the initial currencies read; the empty selection never
		// reaches the update route that would drop every enabled currency.
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'heading', { name: 'Add enabled currencies' } )
		).toBeInTheDocument();
	} );

	it( 'keeps enabled currencies selected in the search-filtered management list', async () => {
		// DECISIONS.md (2026-08-08) defines this search-filtered management list
		// as the native equivalent of WooPayments onboarding rather than a wizard
		// replica, so its selection and filtering behavior belongs in this suite.
		render( <MultiCurrencySettingsApp /> );

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);

		const euroCheckbox = screen.getByRole( 'checkbox', {
			name: 'Euro EUR',
		} );
		expect( euroCheckbox ).toBeChecked();
		expect(
			screen.getAllByRole( 'checkbox', { name: 'Euro EUR' } )
		).toHaveLength( 1 );

		const searchInput = screen.getByRole( 'searchbox', {
			name: 'Search currencies',
		} );
		await userEvent.type( searchInput, 'Euro' );
		expect(
			screen.getAllByRole( 'checkbox', { name: 'Euro EUR' } )
		).toHaveLength( 1 );
		expect( screen.getAllByRole( 'checkbox' ) ).toHaveLength( 1 );
		expect( euroCheckbox ).toBeChecked();

		await userEvent.clear( searchInput );
		await userEvent.type( searchInput, 'zzzznotacurrency' );
		expect( screen.queryAllByRole( 'checkbox' ) ).toHaveLength( 0 );

		await userEvent.clear( searchInput );
		expect( screen.getAllByRole( 'checkbox' ) ).toHaveLength( 4 );
		expect( euroCheckbox ).toBeChecked();
	} );

	it( 'makes the update action available again once a currency is selected', async () => {
		mockApiFetch.mockResolvedValueOnce( updatedResponse );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Add/remove currencies',
			} )
		);
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Euro EUR' } ) );
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: 'Canadian dollar CAD' } )
		);

		const updateButton = screen.getByRole( 'button', {
			name: 'Update selected',
		} );
		expect( updateButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( updateButton ).toHaveAccessibleDescription( '' );
		// Announced once, for the moment the selection passed through empty
		// when Euro was cleared - on the edge, not on every selection change.
		expect( speak ).toHaveBeenCalledTimes( 1 );

		fireEvent.click( updateButton );

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-enabled-currencies',
				method: 'POST',
				data: { enabled: [ 'USD', 'CAD' ] },
			} );
		} );
	} );

	it( 'shows an error notice when updating currencies fails', async () => {
		mockApiFetch.mockRejectedValueOnce( new Error( 'Nope' ) );

		render( <MultiCurrencySettingsApp /> );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Remove Euro as an enabled currency',
			} )
		);

		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error updating enabled currencies.'
			);
		} );
	} );
} );
