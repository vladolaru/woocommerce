/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { StoreLevelSettings } from '../store-settings';

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/settings', () => ( {
	getSetting: ( name: string, fallback?: unknown ) =>
		name === 'homeUrl' ? 'https://example.com/store' : fallback,
} ) );
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
		// N-085: client 11.1.0 store-settings/index.js:231
		// (`disabled={ isSaving || ! isDirty }`) keeps the Save control disabled
		// until something is dirty; loading the settings must not itself POST any
		// change.
		expect(
			screen.getByRole( 'button', { name: 'Save changes' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'announces that the store settings are loading', async () => {
		mockApiFetch.mockResolvedValueOnce( storeSettingsResponse );

		render( <StoreLevelSettings /> );

		expect( speak ).toHaveBeenCalledWith(
			'Loading store settings…',
			'polite'
		);
		expect(
			await screen.findByRole( 'heading', { name: 'Store settings' } )
		).toBeInTheDocument();
	} );

	// Client 11.1.0 store-settings/index.js:28-48 (section description),
	// 147-155 (rendering mode help) and 182-215 (Storefront switcher help).
	it( 'explains the store settings with the client help text and links', async () => {
		mockApiFetch.mockResolvedValueOnce( storeSettingsResponse );

		render( <StoreLevelSettings /> );

		expect(
			await screen.findByText(
				/^Store settings allow your customers to choose which currency they would like to use when shopping at your store\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Learn more/ } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/#store-settings'
		);
		expect(
			screen.getByText(
				'Choose how multi-currency prices are rendered. "Optimized for caching" outputs identical HTML for all visitors and converts prices client-side, allowing hosting providers to cache pages effectively.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', {
				name: /Add a currency switcher to the Storefront theme/i,
			} )
		).toHaveAccessibleDescription(
			'A currency switcher is also available in your widgets. Configure now'
		);
		expect(
			screen.getByRole( 'link', { name: 'Configure now' } )
		).toHaveAttribute( 'href', 'widgets.php' );
	} );

	// Client 11.1.0 store-settings/index.js:101-118 and
	// components/preview-modal/index.js:25-46: the help under the automatic
	// switch opens a Preview dialog with the store page in the simulation that
	// shows the banner. Native derives the page from the home URL rather than
	// the domain root, so subdirectory installs work (AGENTS.md, install layout).
	it( 'previews the automatic currency switch banner', async () => {
		mockApiFetch.mockResolvedValueOnce( storeSettingsResponse );

		render( <StoreLevelSettings /> );

		const autoSwitch = await screen.findByRole( 'checkbox', {
			name: /Automatically switch customers to their local currency/i,
		} );
		expect( autoSwitch ).toHaveAccessibleDescription(
			'Customers will be notified via store alert banner. Preview'
		);

		await click( screen.getByRole( 'button', { name: 'Preview' } ) );

		const dialog = screen.getByRole( 'dialog', { name: 'Preview' } );
		expect( dialog ).toBeInTheDocument();
		expect( screen.getByTitle( 'Preview' ) ).toHaveAttribute(
			'src',
			'https://example.com/store/shop?is_mc_onboarding_simulation=1&enable_storefront_switcher=false&enable_auto_currency=true'
		);
		// Opening the preview is not a settings change.
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'button', { name: 'Save changes', hidden: true } )
		).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	// Client 11.1.0 store-settings/index.js:87-216: automatic switch, the
	// caching recommendation, the rendering mode, then the Storefront switcher.
	it( 'orders the store settings like the client', async () => {
		mockApiFetch.mockResolvedValueOnce( {
			...storeSettingsResponse,
			should_recommend_cache_mode: true,
		} );

		render( <StoreLevelSettings /> );

		const inOrder = [
			await screen.findByRole( 'checkbox', {
				name: /Automatically switch customers/i,
			} ),
			screen.getByText( /We detected that your store uses page caching/, {
				selector: 'p',
			} ),
			screen.getByRole( 'radio', {
				name: 'Optimized for speed (default)',
			} ),
			screen.getByRole( 'checkbox', {
				name: /Add a currency switcher to the Storefront theme/i,
			} ),
			screen.getByRole( 'button', { name: 'Save changes' } ),
		];
		inOrder.slice( 1 ).forEach( ( element, index ) => {
			expect( inOrder[ index ].compareDocumentPosition( element ) ).toBe(
				window.Node.DOCUMENT_POSITION_FOLLOWING
			);
		} );
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

	it( 'persists automatic geolocation opt-in with companion settings preserved', async () => {
		const disabledResponse = {
			...storeSettingsResponse,
			wcpay_multi_currency_enable_auto_currency: false,
		};
		const save = createDeferred< typeof storeSettingsResponse >();
		mockApiFetch
			.mockResolvedValueOnce( disabledResponse )
			.mockReturnValueOnce( save.promise );

		render( <StoreLevelSettings /> );

		const optIn = await screen.findByRole( 'checkbox', {
			name: /Automatically switch customers to their local currency/i,
		} );
		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		// WooPayments 11.1.0 multi-currency-on-boarding.spec.ts:165 supplies
		// the opt-in capability. DECISIONS.md (2026-08-08) places its native
		// equivalent in these persisted store settings.
		expect( optIn ).not.toBeChecked();
		expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );

		await click( optIn );

		expect( optIn ).toBeChecked();
		expect( saveButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );

		await click( saveButton );

		await waitFor( () => {
			expect( mockApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc/v3/payments/multi-currency/update-settings',
				method: 'POST',
				data: {
					wcpay_multi_currency_enable_auto_currency: 'yes',
					wcpay_multi_currency_enable_storefront_switcher: 'no',
					wcpay_multi_currency_rendering_mode: 'speed',
					wcpay_multi_currency_cache_recommendation_dismissed: 'no',
				},
			} );
		} );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );

		await act( async () => {
			save.resolve( {
				...disabledResponse,
				wcpay_multi_currency_enable_auto_currency: true,
			} );
			await save.promise;
		} );

		expect( optIn ).toBeChecked();
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Store settings saved.'
		);
		await waitFor( () => {
			expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );
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

	// Client 11.1.0 `multi-currency/client/data/resolvers.js:59-68`: one snackbar in the client's words, nothing repeated in the card.
	it( 'reports a failed store settings read once, in the client wording', async () => {
		mockApiFetch.mockRejectedValueOnce(
			new Error( 'Internal Server Error' )
		);

		render( <StoreLevelSettings /> );

		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'Error retrieving store settings.'
			);
		} );
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'heading', { name: 'Store settings' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText( /Unable to load store settings/ )
		).not.toBeInTheDocument();
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
