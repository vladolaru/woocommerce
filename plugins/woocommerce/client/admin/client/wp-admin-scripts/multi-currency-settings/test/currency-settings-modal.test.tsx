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
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { CurrencySettingsModal } from '../currency-settings-modal';

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

const yenCurrency = {
	...euroCurrency,
	id: 'jpy',
	code: 'JPY',
	name: 'Japanese yen',
	symbol: '¥',
	is_zero_decimal: true,
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
			availableCurrency={ euroCurrency }
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

	it( 'announces that the currency settings are loading', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal();

		expect( speak ).toHaveBeenCalledWith(
			'Loading currency settings…',
			'polite'
		);
		expect(
			await screen.findByRole( 'heading', {
				name: 'Manage Euro settings',
			} )
		).toBeInTheDocument();
	} );

	// Client 11.1.0 single-currency/index.js:76, 157-165 and 256-273: the
	// automatic option describes the fetched rate of `currencies.available`
	// (not the enabled entry, which carries a manual rate when one is set) with
	// its update time in the store's date and time formats, or an error when
	// the rate has never been fetched.
	describe( 'automatic rate description', () => {
		const originalDateSettings = getSettings();

		beforeEach( () => {
			setSettings( {
				...originalDateSettings,
				formats: {
					...originalDateSettings.formats,
					date: 'F j, Y',
					time: 'g:i a',
				},
				timezone: {
					...originalDateSettings.timezone,
					offset: 0,
					string: 'UTC',
				},
			} );
		} );

		afterEach( () => {
			setSettings( originalDateSettings );
		} );

		it( 'shows the fetched rate and when it was last updated', async () => {
			mockApiFetch.mockResolvedValueOnce( manualSettingsResponse );

			renderModal( {
				currency: { ...euroCurrency, rate: 0.95, last_updated: null },
				availableCurrency: euroCurrency,
			} );

			expect(
				await screen.findByText(
					'Current rate: 1 USD = 0.92 EUR (Last updated: March 9, 2024 4:00 pm)'
				)
			).toBeInTheDocument();
		} );

		it( 'says the rate could not be fetched when it has no update time', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

			renderModal( {
				availableCurrency: { ...euroCurrency, last_updated: null },
			} );

			expect(
				await screen.findByText(
					'Error - Unable to fetch automatic rate for this currency'
				)
			).toBeInTheDocument();
		} );
	} );

	// Client 11.1.0 single-currency/index.js:280-290 (manual option
	// description) and 305-307 (manual rate help).
	it( 'describes the manual exchange rate option and field', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal();

		expect(
			await screen.findByText( 'Enter your fixed rate of exchange' )
		).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'radio', { name: 'Manual' } ) );

		expect(
			screen.getByLabelText( 'Manual rate' )
		).toHaveAccessibleDescription(
			'Enter the manual rate you would like to use. Must be a positive number.'
		);
	} );

	// Client 11.1.0 single-currency/index.js:347-364 and 395-412.
	it( 'explains price rounding and charm pricing with Learn more links', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal();

		const roundingSelect = await screen.findByLabelText( 'Price rounding' );
		expect( roundingSelect ).toHaveAccessibleDescription(
			/^Make your EUR prices consistent by rounding them up after they're converted\. Learn more/
		);
		expect(
			screen.getByLabelText( 'Charm pricing' )
		).toHaveAccessibleDescription(
			/^Reduce the converted price for a specific amount\. Learn more/
		);
		expect(
			screen
				.getAllByRole( 'link', { name: /Learn more/ } )
				.map( ( link ) => link.getAttribute( 'href' ) )
		).toEqual( [
			'https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/#price-rounding',
			'https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/#charm-pricing',
		] );
	} );

	// Client 11.1.0 single-currency/index.js:166-184 and 442-457 with
	// currency-preview.js:18-81: a price in the store currency (20 to start) is
	// converted with the selected rate, rounded up to the rounding step, charmed,
	// and formatted in the target currency's own locale format.
	describe( 'price preview', () => {
		const euroWithFormat = {
			...euroCurrency,
			symbol_position: 'right_space',
			thousand_separator: '.',
			decimal_separator: ',',
			num_decimals: 2,
		};

		it( 'converts a store price with the automatic rate and the formatting rules', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

			renderModal( {
				currency: euroWithFormat,
				availableCurrency: euroWithFormat,
			} );

			expect(
				await screen.findByText(
					'Enter a price in your default currency (United States (US) dollar) to see it converted to Euro using the exchange rate and formatting rules above.'
				)
			).toBeInTheDocument();
			const storePrice = screen.getByLabelText(
				'United States (US) dollar'
			);
			expect( storePrice ).toHaveValue( '20' );
			// 20 × 0.92 = 18.40, rounded up to the default 1.00 step.
			expect( screen.getByLabelText( 'Euro' ) ).toHaveValue( '19,00 €' );
			expect( screen.getByLabelText( 'Euro' ) ).toBeDisabled();

			fireEvent.change( storePrice, { target: { value: '100' } } );
			expect( screen.getByLabelText( 'Euro' ) ).toHaveValue( '92,00 €' );

			fireEvent.change( storePrice, { target: { value: 'abc' } } );
			expect( screen.getByLabelText( 'Euro' ) ).toHaveValue(
				'Please enter a valid number'
			);
		} );

		it( 'follows the manual rate, rounding and charm being edited', async () => {
			mockApiFetch.mockResolvedValueOnce( manualSettingsResponse );

			renderModal( {
				currency: euroWithFormat,
				availableCurrency: euroWithFormat,
			} );

			// 20 × 0.95 = 19.00, rounded to 1.00, minus 0.01.
			expect( await screen.findByLabelText( 'Euro' ) ).toHaveValue(
				'18,99 €'
			);

			fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
				target: { value: '5.00' },
			} );
			// 19.00 rounded up to 20.00, minus 0.01.
			expect( screen.getByLabelText( 'Euro' ) ).toHaveValue( '19,99 €' );
		} );

		it( 'formats zero-decimal currencies without decimals', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );
			const yenWithFormat = {
				...yenCurrency,
				thousand_separator: ',',
				decimal_separator: '.',
				num_decimals: 0,
			};

			renderModal( {
				currency: yenWithFormat,
				availableCurrency: yenWithFormat,
			} );

			// 20 × 0.92 = 18.40, rounded up to the default 100 step.
			expect(
				await screen.findByLabelText( 'Japanese yen' )
			).toHaveValue( '¥100' );
		} );
	} );

	// Client 11.1.0 single-currency/index.js:66, 138-151 and 462: Save stays
	// disabled until something changes, and leaving with unsaved changes (the
	// breadcrumb back to the list, which is closing this dialog, or leaving the
	// page) asks first, with the client's message.
	describe( 'unsaved changes', () => {
		const unsavedChangesMessage =
			'There are unsaved changes on this page. Are you sure you want to leave and discard the unsaved changes?';
		let confirmSpy: jest.SpyInstance;

		beforeEach( () => {
			confirmSpy = jest.spyOn( window, 'confirm' );
		} );

		afterEach( () => {
			confirmSpy.mockRestore();
		} );

		const dispatchBeforeUnload = () => {
			const event = new Event( 'beforeunload', { cancelable: true } );
			window.dispatchEvent( event );

			return event;
		};

		it( 'keeps Save disabled until a setting changes', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

			renderModal();

			const saveButton = await screen.findByRole( 'button', {
				name: 'Save changes',
			} );
			expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );

			fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
				target: { value: '-0.05' },
			} );

			expect( saveButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		} );

		it( 'closes without asking when nothing changed', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );
			const { onClose } = renderModal();

			fireEvent.click(
				await screen.findByRole( 'button', { name: 'Cancel' } )
			);

			expect( confirmSpy ).not.toHaveBeenCalled();
			expect( onClose ).toHaveBeenCalledTimes( 1 );
			expect( dispatchBeforeUnload().defaultPrevented ).toBe( false );
		} );

		it( 'asks before discarding unsaved changes', async () => {
			mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );
			const { onClose } = renderModal();

			fireEvent.change( await screen.findByLabelText( 'Charm pricing' ), {
				target: { value: '-0.05' },
			} );

			expect( dispatchBeforeUnload().defaultPrevented ).toBe( true );

			confirmSpy.mockReturnValueOnce( false );
			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
			expect( confirmSpy ).toHaveBeenCalledWith( unsavedChangesMessage );
			expect( onClose ).not.toHaveBeenCalled();

			confirmSpy.mockReturnValueOnce( true );
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Close' } )
			);
			// The dialog's own close button waits for its exit animation.
			await waitFor( () =>
				expect( confirmSpy ).toHaveBeenCalledTimes( 2 )
			);
			expect( onClose ).toHaveBeenCalledTimes( 1 );
		} );
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
			screen.getByText(
				'Enter the manual rate you would like to use. Must be a positive number.'
			)
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
			'Enter the manual rate you would like to use. Must be a positive number.'
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
		// Source: client 11.1.0 single-currency/constants.js:12.
		expect(
			roundingSelect.querySelector( 'option[value="1.00"]' )
		).toHaveTextContent( /^1\.00 \(recommended\)$/ );
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

	// N-085: client 11.1.0 single-currency/constants.js:16-40 defines a distinct
	// zero-decimal rounding/charm option vocabulary ('1'..'1000' recommended '100';
	// '0.00'..'-100'), and index.js:85-95 selects it from `currency.is_zero_decimal`.
	// mc-pricing:464 depends on JPY rendering this option set, not the decimal one.
	it( 'renders the zero-decimal option set for JPY', async () => {
		mockApiFetch.mockResolvedValueOnce( automaticSettingsResponse );

		renderModal( { currency: yenCurrency } );

		const roundingSelect = await screen.findByLabelText( 'Price rounding' );
		const charmSelect = screen.getByLabelText( 'Charm pricing' );
		expect( roundingSelect ).toHaveValue( '100' );
		// Source: client 11.1.0 single-currency/constants.js:21.
		expect(
			roundingSelect.querySelector( 'option[value="100"]' )
		).toHaveTextContent( /^100 \(recommended\)$/ );
		expect(
			roundingSelect.querySelector( 'option[value="10"]' )
		).toBeInTheDocument();
		expect(
			roundingSelect.querySelector( 'option[value="0.50"]' )
		).not.toBeInTheDocument();
		expect(
			charmSelect.querySelector( 'option[value="-1"]' )
		).toBeInTheDocument();
		expect(
			charmSelect.querySelector( 'option[value="-0.01"]' )
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
