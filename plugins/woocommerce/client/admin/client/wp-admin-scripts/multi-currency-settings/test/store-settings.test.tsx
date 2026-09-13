/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { StoreLevelSettings } from '../store-settings';

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

const click = async (
	element: Parameters< typeof userEvent.click >[ 0 ]
): Promise< void > => userEvent.click( element );

const createDeferred = < T, >() => {
	let resolve: ( value: T ) => void;
	let reject: ( reason?: unknown ) => void;
	const promise = new Promise< T >( ( resolvePromise, rejectPromise ) => {
		resolve = resolvePromise;
		reject = rejectPromise;
	} );

	return { promise, resolve, reject };
};

const storeSettingsResponse = {
	wcpay_multi_currency_enable_auto_currency: true,
	wcpay_multi_currency_enable_storefront_switcher: false,
	wcpay_multi_currency_rendering_mode: 'speed',
	should_recommend_cache_mode: false,
	cache_recommendation_dismissed: false,
	is_cache_optimized_feature_enabled: true,
	site_theme: 'Storefront',
	date_format: 'F j, Y',
	time_format: 'g:i a',
	store_url: 'shop',
};

describe( 'StoreLevelSettings', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'loads and renders store-level settings', async () => {
		mockApiFetch.mockResolvedValueOnce( storeSettingsResponse );

		render( <StoreLevelSettings /> );

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/multi-currency/get-settings',
		} );
		expect(
			await screen.findByRole( 'heading', { name: 'Store settings' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', {
				name: /Automatically switch customers to their local currency/i,
			} )
		).toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: /Add a currency switcher to the Storefront theme/i,
			} )
		).not.toBeChecked();
		expect(
			screen.getByRole( 'radio', {
				name: 'Optimized for speed (default)',
			} )
		).toBeChecked();
	} );

	it( 'saves store settings with preserved REST option keys', async () => {
		const save = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( storeSettingsResponse )
			.mockReturnValueOnce( save.promise );

		render( <StoreLevelSettings /> );

		await click(
			await screen.findByRole( 'checkbox', {
				name: /Automatically switch customers to their local currency/i,
			} )
		);
		await click( screen.getByRole( 'button', { name: 'Save changes' } ) );

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-settings',
				method: 'POST',
				data: {
					wcpay_multi_currency_enable_auto_currency: 'no',
					wcpay_multi_currency_enable_storefront_switcher: 'no',
					wcpay_multi_currency_rendering_mode: 'speed',
					wcpay_multi_currency_cache_recommendation_dismissed: 'no',
				},
			} );
		} );
		await act( async () => {
			save.resolve( {
				...storeSettingsResponse,
				wcpay_multi_currency_enable_auto_currency: false,
			} );
			await save.promise;
		} );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Store settings saved.'
		);
		await waitFor( () => {
			expect(
				screen.getByRole( 'button', { name: 'Save changes' } )
			).toHaveAttribute( 'aria-disabled', 'true' );
		} );
	} );

	it( 'hides conditional settings when the store does not support them', async () => {
		mockApiFetch.mockResolvedValueOnce( {
			...storeSettingsResponse,
			is_cache_optimized_feature_enabled: false,
			site_theme: 'Twenty Twenty-Four',
		} );

		render( <StoreLevelSettings /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Store settings' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'checkbox', {
				name: /Add a currency switcher to the Storefront theme/i,
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'radio', { name: 'Optimized for caching' } )
		).not.toBeInTheDocument();
	} );

	it( 'uses caching mode from the page-cache recommendation immediately', async () => {
		const save = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( {
				...storeSettingsResponse,
				should_recommend_cache_mode: true,
			} )
			.mockReturnValueOnce( save.promise );

		render( <StoreLevelSettings /> );

		const recommendation = await screen.findByText(
			'We detected that your store uses page caching. Switching Multi-Currency to the caching-optimized rendering mode lets your host cache pages effectively.'
		);
		expect(
			recommendation.closest( '.components-notice' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Information notice' ) ).toBeInTheDocument();
		expect(
			recommendation
				.closest( '.components-notice' )
				?.querySelector( '[aria-hidden="true"]' )
		).toBeInTheDocument();

		await click(
			screen.getByRole( 'button', { name: 'Use caching mode' } )
		);

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-settings',
				method: 'POST',
				data: {
					wcpay_multi_currency_enable_auto_currency: 'yes',
					wcpay_multi_currency_enable_storefront_switcher: 'no',
					wcpay_multi_currency_rendering_mode: 'cache',
					wcpay_multi_currency_cache_recommendation_dismissed: 'no',
				},
			} );
		} );
		await act( async () => {
			save.resolve( {
				...storeSettingsResponse,
				wcpay_multi_currency_rendering_mode: 'cache',
			} );
			await save.promise;
		} );
		await waitFor( () => {
			expect(
				screen.queryByRole( 'button', { name: 'Use caching mode' } )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'keeps focus and prevents duplicate caching-mode saves while busy', async () => {
		let resolveSave: ( value: typeof storeSettingsResponse ) => void;
		const savePromise = new Promise< typeof storeSettingsResponse >(
			( resolve ) => {
				resolveSave = resolve;
			}
		);
		mockApiFetch
			.mockResolvedValueOnce( {
				...storeSettingsResponse,
				should_recommend_cache_mode: true,
			} )
			.mockReturnValueOnce( savePromise );

		render( <StoreLevelSettings /> );

		const action = await screen.findByRole( 'button', {
			name: 'Use caching mode',
		} );
		act( () => action.focus() );
		await click( action );
		await click( action );

		await waitFor( () => {
			expect( action ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
		expect( action ).toHaveFocus();
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );

		await act( async () => {
			resolveSave( {
				...storeSettingsResponse,
				wcpay_multi_currency_rendering_mode: 'cache',
			} );
			await savePromise;
		} );
		await waitFor( () => {
			expect(
				screen.queryByRole( 'button', { name: 'Use caching mode' } )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'keeps the caching recommendation retryable after a failed action save', async () => {
		const failedSave = createDeferred< typeof storeSettingsResponse >();
		const retrySave = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( {
				...storeSettingsResponse,
				should_recommend_cache_mode: true,
			} )
			.mockReturnValueOnce( failedSave.promise )
			.mockReturnValueOnce( retrySave.promise );

		render( <StoreLevelSettings /> );

		const action = await screen.findByRole( 'button', {
			name: 'Use caching mode',
		} );
		await click( action );
		await act( async () => {
			failedSave.reject( new Error( 'Nope' ) );
			try {
				await failedSave.promise;
			} catch {
				// The production handler reports the rejected request as a notice.
			}
		} );
		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error saving store settings.'
			);
		} );
		expect(
			screen.getByRole( 'button', { name: 'Use caching mode' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByRole( 'radio', { name: 'Optimized for caching' } )
		).toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );

		await click(
			screen.getByRole( 'button', { name: 'Use caching mode' } )
		);
		await act( async () => {
			retrySave.resolve( {
				...storeSettingsResponse,
				wcpay_multi_currency_rendering_mode: 'cache',
			} );
			await retrySave.promise;
		} );
		await waitFor( () => {
			expect(
				screen.queryByRole( 'button', { name: 'Use caching mode' } )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'keeps the caching recommendation retryable after a failed dismissal save', async () => {
		const failedSave = createDeferred< typeof storeSettingsResponse >();
		const retrySave = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( {
				...storeSettingsResponse,
				should_recommend_cache_mode: true,
			} )
			.mockReturnValueOnce( failedSave.promise )
			.mockReturnValueOnce( retrySave.promise );

		render( <StoreLevelSettings /> );

		await click( await screen.findByRole( 'button', { name: 'Close' } ) );
		await act( async () => {
			failedSave.reject( new Error( 'Nope' ) );
			try {
				await failedSave.promise;
			} catch {
				// The production handler reports the rejected request as a notice.
			}
		} );
		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error saving store settings.'
			);
		} );
		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/wc/v3/payments/multi-currency/update-settings',
			method: 'POST',
			data: {
				wcpay_multi_currency_enable_auto_currency: 'yes',
				wcpay_multi_currency_enable_storefront_switcher: 'no',
				wcpay_multi_currency_rendering_mode: 'speed',
				wcpay_multi_currency_cache_recommendation_dismissed: 'yes',
			},
		} );
		expect(
			screen.getByRole( 'button', { name: 'Use caching mode' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );

		await click( screen.getByRole( 'button', { name: 'Close' } ) );
		await act( async () => {
			retrySave.resolve( {
				...storeSettingsResponse,
				cache_recommendation_dismissed: true,
			} );
			await retrySave.promise;
		} );
		await waitFor( () => {
			expect(
				screen.queryByRole( 'button', { name: 'Use caching mode' } )
			).not.toBeInTheDocument();
		} );
	} );

	it( 'shows an error notice when saving store settings fails', async () => {
		const save = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( storeSettingsResponse )
			.mockReturnValueOnce( save.promise );

		render( <StoreLevelSettings /> );

		await click(
			await screen.findByRole( 'checkbox', {
				name: /Automatically switch customers to their local currency/i,
			} )
		);
		await click( screen.getByRole( 'button', { name: 'Save changes' } ) );
		await act( async () => {
			save.reject( new Error( 'Nope' ) );
			try {
				await save.promise;
			} catch {
				// The production handler reports the rejected request as a notice.
			}
		} );

		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error saving store settings.'
			);
		} );
	} );

	it( 'keeps save focus visible while saving store settings', async () => {
		const save = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( storeSettingsResponse )
			.mockReturnValueOnce( save.promise );

		render( <StoreLevelSettings /> );

		await click(
			await screen.findByRole( 'checkbox', {
				name: /Automatically switch customers to their local currency/i,
			} )
		);

		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		saveButton.focus();
		await click( saveButton );

		await waitFor( () => {
			expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );
		} );
		expect( saveButton ).toHaveFocus();

		await act( async () => {
			save.resolve( {
				...storeSettingsResponse,
				wcpay_multi_currency_enable_auto_currency: false,
			} );
			await save.promise;
		} );
	} );
} );
