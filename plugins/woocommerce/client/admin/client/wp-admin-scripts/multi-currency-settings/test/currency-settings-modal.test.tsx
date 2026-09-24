/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { CurrencySettingsModal } from '../currency-settings-modal';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

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

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const usdCurrency = {
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
};

const euroCurrency = {
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
};

const swissFrancCurrency = {
	...euroCurrency,
	id: 'chf',
	code: 'CHF',
	name: 'Swiss franc',
	symbol: 'CHF',
};

const poundSterlingCurrency = {
	...euroCurrency,
	id: 'gbp',
	code: 'GBP',
	name: 'British pound sterling',
	symbol: '£',
};

const automaticSettingsResponse = {
	exchange_rate_type: 'automatic',
	manual_rate: null,
	price_rounding: null,
	price_charm: null,
};

const manualSettingsResponse = {
	exchange_rate_type: 'manual',
	manual_rate: 0.95,
	price_rounding: 1,
	price_charm: -0.01,
};

const renderModal = ( props = {} ) => {
	const onClose = jest.fn();
	const onSaved = jest.fn();

	render(
		<CurrencySettingsModal
			currency={ euroCurrency }
			defaultCurrency={ usdCurrency }
			onClose={ onClose }
			onSaved={ onSaved }
			automaticRates={ { available: true, source: 'woopayments' } }
			{ ...props }
		/>
	);

	return { onClose, onSaved };
};

const createDeferredPromise = < T, >() => {
	let resolve!: ( value: T ) => void;
	const promise = new Promise< T >( ( resolvePromise ) => {
		resolve = resolvePromise;
	} );

	return { promise, resolve };
};

describe( 'CurrencySettingsModal', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'loads and renders currency settings', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal();

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/multi-currency/currencies/EUR',
		} );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Manage Euro settings',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'radio', { name: 'Fetch rates automatically' } )
		).toBeChecked();
		expect(
			screen.queryByLabelText( 'Manual rate' )
		).not.toBeInTheDocument();
	} );

	it( 'omits the manual rate when saving automatic currency settings', async () => {
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal();

		await screen.findByRole( 'radio', {
			name: 'Fetch rates automatically',
		} );
		fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
			target: { value: '5.00' },
		} );
		fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
			target: { value: '-0.05' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => {
			const request = mockApiFetch.mock.calls.at( -1 )?.[ 0 ];

			expect( request ).toMatchObject( {
				path: '/wc/v3/payments/multi-currency/currencies/EUR',
				method: 'POST',
				data: {
					exchange_rate_type: 'automatic',
					price_rounding: 5,
					price_charm: -0.05,
				},
			} );
			expect(
				Object.prototype.hasOwnProperty.call(
					request?.data ?? {},
					'manual_rate'
				)
			).toBe( false );
		} );
	} );

	it( 'requires a finite positive manual rate when no automatic source is registered', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );
		const inactiveCurrency = { ...euroCurrency, rate: null };

		renderModal( {
			currency: inactiveCurrency,
			automaticRates: { available: false, source: null },
		} );

		expect( await screen.findByLabelText( 'Manual rate' ) ).toHaveValue(
			''
		);
		expect(
			screen.getByRole( 'radio', { name: 'Fetch rates automatically' } )
		).toBeDisabled();
		expect(
			screen.getByText( 'No automatic-rate provider is available.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'radio', { name: 'Manual' } )
		).toHaveAccessibleDescription(
			'No automatic-rate provider is available.'
		);
		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByText( 'Enter a positive exchange rate.' )
		).toBeInTheDocument();

		fireEvent.change( screen.getByLabelText( 'Manual rate' ), {
			target: { value: '1.25' },
		} );
		mockApiFetch.mockResolvedValueOnce( manualSettingsResponse );
		fireEvent.click( saveButton );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					data: expect.objectContaining( { manual_rate: 1.25 } ),
				} )
			)
		);
	} );

	it.each( [
		[ 'whitespace', ' ' ],
		[ 'Infinity', 'Infinity' ],
		[ 'NaN', 'NaN' ],
		[ 'zero', '0' ],
		[ 'negative number', '-1' ],
	] )( 'blocks %s as a manual rate', async ( _description, manualRate ) => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal( {
			currency: { ...euroCurrency, rate: null },
			automaticRates: { available: false, source: null },
		} );

		const manualRateInput = await screen.findByLabelText( 'Manual rate' );
		await userEvent.type( manualRateInput, manualRate );

		expect( manualRateInput ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( manualRateInput ).toHaveAccessibleDescription(
			'Enter a positive exchange rate.'
		);
		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );

		await userEvent.click( saveButton );

		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps automatic selection available during a named provider outage', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal( {
			automaticRates: { available: false, source: 'woopayments' },
		} );

		expect(
			await screen.findByRole( 'radio', {
				name: 'Fetch rates automatically',
			} )
		).toBeChecked();
		expect(
			screen.getAllByText(
				'WooPayments automatic rates are temporarily unavailable. You can use manual rates.'
			)
		).not.toHaveLength( 0 );
	} );

	it( 'describes a WooPayments outage when automatic cache has no rate', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal( {
			currency: { ...euroCurrency, rate: null },
			automaticRates: { available: false, source: 'woopayments' },
		} );

		expect(
			await screen.findByRole( 'radio', {
				name: 'Fetch rates automatically',
			} )
		).toBeChecked();
		expect(
			screen.getByText(
				'WooPayments automatic rates are temporarily unavailable. You can use manual rates.'
			)
		).toBeInTheDocument();
	} );

	it.each( [
		[ 'a cached automatic rate', euroCurrency.rate ],
		[ 'no cached automatic rate', null ],
	] )(
		'keeps automatic selection available and describes a generic provider outage with %s',
		async ( _rateDescription, rate ) => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

			renderModal( {
				currency: { ...euroCurrency, rate },
				automaticRates: {
					available: false,
					source: 'custom-provider',
				},
			} );

			const automaticRateOption = await screen.findByRole( 'radio', {
				name: 'Fetch rates automatically',
			} );
			expect( automaticRateOption ).toBeEnabled();
			expect( automaticRateOption ).toBeChecked();
			expect(
				screen.getByText(
					'Automatic rates from custom-provider are temporarily unavailable. You can use manual rates.'
				)
			).toBeInTheDocument();

			await userEvent.click(
				screen.getByRole( 'radio', { name: 'Manual' } )
			);
			expect(
				screen.getByRole( 'radio', { name: 'Manual' } )
			).toBeChecked();

			await userEvent.click( automaticRateOption );
			expect( automaticRateOption ).toBeEnabled();
			expect( automaticRateOption ).toBeChecked();
		}
	);

	it( 'saves manual currency settings with preserved REST keys', async () => {
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockResolvedValueOnce( manualSettingsResponse );
		const { onClose, onSaved } = renderModal();

		fireEvent.click(
			await screen.findByRole( 'radio', { name: 'Manual' } )
		);
		fireEvent.change( screen.getByLabelText( 'Manual rate' ), {
			target: { value: '0.95' },
		} );
		fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
			target: { value: '1.00' },
		} );
		fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
			target: { value: '-0.01' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/currencies/EUR',
				method: 'POST',
				data: {
					exchange_rate_type: 'manual',
					manual_rate: 0.95,
					price_rounding: 1,
					price_charm: -0.01,
				},
			} );
		} );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Currency settings saved.'
		);
		expect( onSaved ).toHaveBeenCalledWith( 'EUR', 0.95 );
		expect( onClose ).toHaveBeenCalled();
	} );

	// Source: WooPayments client 11.1.0, tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:122.
	it( 'serializes the pinned CHF manual charm settings once and closes after success', async () => {
		const saveResponse = {
			exchange_rate_type: 'manual',
			manual_rate: 1,
			price_rounding: 0,
			price_charm: -0.01,
		};
		const { promise: savePromise, resolve: resolveSave } =
			createDeferredPromise< typeof saveResponse >();
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockReturnValueOnce( savePromise );
		const { onClose, onSaved } = renderModal( {
			currency: swissFrancCurrency,
		} );

		await userEvent.click(
			await screen.findByRole( 'radio', { name: 'Manual' } )
		);
		fireEvent.change( screen.getByLabelText( 'Manual rate' ), {
			target: { value: '1' },
		} );
		fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
			target: { value: '0' },
		} );
		fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
			target: { value: '-0.01' },
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/currencies/CHF',
				method: 'POST',
				data: {
					exchange_rate_type: 'manual',
					manual_rate: 1,
					price_rounding: 0,
					price_charm: -0.01,
				},
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
		await act( async () => {
			resolveSave( saveResponse );
			await savePromise;
		} );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Currency settings saved.'
		);
		expect( onSaved ).toHaveBeenCalledWith( 'CHF', 1 );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	// Source: WooPayments client 11.1.0, tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:159.
	it( 'serializes the pinned CHF manual rounding settings once and closes after success', async () => {
		const saveResponse = {
			exchange_rate_type: 'manual',
			manual_rate: 1.2,
			price_rounding: 0.5,
			price_charm: 0,
		};
		const { promise: savePromise, resolve: resolveSave } =
			createDeferredPromise< typeof saveResponse >();
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockReturnValueOnce( savePromise );
		const { onClose, onSaved } = renderModal( {
			currency: swissFrancCurrency,
		} );

		await userEvent.click(
			await screen.findByRole( 'radio', { name: 'Manual' } )
		);
		fireEvent.change( screen.getByLabelText( 'Manual rate' ), {
			target: { value: '1.2' },
		} );
		fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
			target: { value: '0.50' },
		} );
		fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
			target: { value: '0.00' },
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/currencies/CHF',
				method: 'POST',
				data: {
					exchange_rate_type: 'manual',
					manual_rate: 1.2,
					price_rounding: 0.5,
					price_charm: 0,
				},
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
		await act( async () => {
			resolveSave( saveResponse );
			await savePromise;
		} );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Currency settings saved.'
		);
		expect( onSaved ).toHaveBeenCalledWith( 'CHF', 1.2 );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	// Source: WooPayments client 11.1.0, tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:207.
	it( 'renders the decimal option set for GBP', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal( { currency: poundSterlingCurrency } );

		const roundingSelect = await screen.findByLabelText( 'Price rounding' );
		const charmSelect = screen.getByLabelText( 'Charm pricing' );
		expect( roundingSelect ).toHaveValue( '1.00' );
		expect(
			roundingSelect.querySelector( 'option[value="0.50"]' )
		).toBeInTheDocument();
		expect(
			roundingSelect.querySelector( 'option[value="500"]' )
		).not.toBeInTheDocument();
		expect(
			charmSelect.querySelector( 'option[value="-0.01"]' )
		).toBeInTheDocument();
		expect(
			charmSelect.querySelector( 'option[value="-1"]' )
		).not.toBeInTheDocument();
	} );

	it( 'shows an error notice when saving currency settings fails', async () => {
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockRejectedValueOnce( new Error( 'Nope' ) );

		renderModal();

		fireEvent.click(
			await screen.findByRole( 'radio', { name: 'Manual' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error saving currency settings.'
			);
		} );
		expect(
			screen.getByRole( 'heading', {
				name: 'Manage Euro settings',
			} )
		).toBeInTheDocument();
	} );

	it( 'keeps save focus visible while saving currency settings', async () => {
		let resolveSave = ( value: typeof manualSettingsResponse ) => value;
		const savePromise = new Promise< typeof manualSettingsResponse >(
			( resolve ) => {
				resolveSave = resolve;
			}
		);
		mockApiFetch
			.mockResolvedValueOnce( automaticSettingsResponse )
			.mockReturnValueOnce( savePromise );

		renderModal();

		fireEvent.click(
			await screen.findByRole( 'radio', { name: 'Manual' } )
		);

		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		saveButton.focus();
		fireEvent.click( saveButton );

		await waitFor( () => {
			expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
		expect( saveButton ).toHaveFocus();

		await act( async () => {
			resolveSave( manualSettingsResponse );
			await savePromise;
		} );
	} );
} );
