/**
 * External dependencies
 */
import { recordEvent } from '@woocommerce/tracks';
import {
	act,
	render,
	fireEvent,
	screen,
	waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter as Router } from 'react-router-dom';
import { dispatch, select } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';
import type {
	OfflinePaymentMethodProvider,
	PaymentsProvider,
	SuggestedPaymentsExtension,
} from '@woocommerce/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { SettingsPaymentsMain } from '../settings-payments-main';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn().mockResolvedValue( {} ) );

jest.mock( '~/utils/features', () => ( {
	isFeatureEnabled: jest.fn(),
} ) );

jest.mock( '~/settings-payments/components/payment-gateways', () => ( {
	PaymentGateways: () => <div>Payment gateways list</div>,
} ) );

jest.mock( '~/settings-payments/onboarding/providers/woopayments', () => ( {
	__esModule: true,
	default: ( { isOpen }: { isOpen: boolean } ) =>
		isOpen ? <div role="dialog">WooPayments onboarding</div> : null,
} ) );

// The WooPayments suggestion as "Other payment options" lists it once hidden: the REST schema drops its onboarding data.
const hiddenWooPaymentsSuggestion = {
	id: 'woopayments',
	_priority: 1,
	_type: 'psp',
	title: 'Accept payments with Woo',
	description: 'Payments',
	short_description: '',
	plugin: { status: 'not_installed' },
	image: '',
	icon: '',
	links: [],
	tags: [],
	category: '',
	_links: {},
} as unknown as SuggestedPaymentsExtension;
const pspCategory = {
	id: 'psp',
	_priority: 1,
	title: 'Payment providers',
	description: '',
};

const noticeMessage = 'Ready for live payments.';
const noticeProvider = {
	id: 'woocommerce_payments',
	_type: 'gateway',
	_order: 1,
	title: 'WooPayments',
	description: 'Payments',
	icon: '',
	plugin: { slug: '', file: '', status: 'active' },
	state: {
		enabled: true,
		account_connected: true,
		needs_setup: false,
		test_mode: false,
		dev_mode: false,
	},
	onboarding: { type: 'native_in_context' },
	_links: {},
	_admin_notice: {
		id: 'test_to_live',
		message: noticeMessage,
		primary: {
			kind: 'onboard',
			label: 'Turn on live payments',
			href: '/onboard',
		},
		_links: {
			shown: { href: '/shown' },
			dismiss: { href: '/dismiss' },
		},
	},
} as PaymentsProvider;

describe( 'SettingsPaymentsMain', () => {
	afterEach( () => {
		( apiFetch as jest.Mock ).mockReset().mockResolvedValue( {} );
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[],
				[],
				[],
				[]
			);
		} );
		delete window.wcSettings.admin.woocommerce_payments_nox_profile;
	} );

	it( 'does not replay a dismissed notice from cached providers after remount', async () => {
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[ noticeProvider ],
				[],
				[],
				[]
			);
		} );
		let finishDismiss: ( value: { success: boolean } ) => void = () => {};
		let finishProviders: ( value: {
			providers: PaymentsProvider[];
			offline_payment_methods: never[];
			suggestions: never[];
			suggestion_categories: never[];
		} ) => void = () => {};
		const refreshedResponse = {
			providers: [ { ...noticeProvider, _admin_notice: undefined } ],
			offline_payment_methods: [],
			suggestions: [],
			suggestion_categories: [],
		};
		( apiFetch as jest.Mock ).mockImplementation( ( request ) => {
			if ( request.url === '/dismiss' ) {
				return new Promise( ( resolve ) => {
					finishDismiss = resolve;
				} );
			}
			if ( request.path?.includes( '/settings/payments/providers' ) ) {
				return new Promise( ( resolve ) => {
					finishProviders = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const firstRender = render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Dismiss WooPayments notice',
			} )
		);
		await act( async () => {
			finishDismiss( { success: true } );
		} );
		await waitFor( () =>
			expect(
				screen.queryByText( noticeMessage )
			).not.toBeInTheDocument()
		);
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: expect.stringContaining(
						'/settings/payments/providers'
					),
				} )
			)
		);
		expect( select( paymentSettingsStore ).isFetching() ).toBe( true );

		await act( async () => {
			finishProviders( refreshedResponse );
		} );
		await waitFor( () => {
			const paymentSettings = select( paymentSettingsStore );
			expect( paymentSettings.isFetching() ).toBe( false );
			expect(
				paymentSettings.getPaymentProviders()[ 0 ]._admin_notice
			).toBeUndefined();
		} );

		firstRender.unmount();
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect( screen.queryByText( noticeMessage ) ).not.toBeInTheDocument();
	} );

	it( 'shows a WooPayments admin notice before payment gateways', () => {
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[ noticeProvider ],
				[],
				[],
				[]
			);
		} );

		const { container } = render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect( screen.getByText( noticeMessage ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.settings-payments-main__container' )
		).toContainElement( screen.getByText( noticeMessage ) );
		const page = container.querySelector(
			'.settings-payments-main__container'
		);
		expect( page?.children[ 0 ] ).toContainElement(
			screen.getByText( noticeMessage )
		);
		expect( page?.children[ 1 ] ).toHaveTextContent(
			'Payment gateways list'
		);
	} );

	it( 'does not show a provider notice while payment providers load', () => {
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[ noticeProvider ],
				[],
				[],
				[]
			);
			dispatch( paymentSettingsStore ).getPaymentProvidersRequest();
		} );
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect(
			screen.queryByRole( 'button', {
				name: 'Dismiss WooPayments notice',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'does not show a notice when the WooPayments provider omits it', () => {
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[ { ...noticeProvider, _admin_notice: undefined } ],
				[],
				[],
				[]
			);
		} );
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);
		expect( screen.queryByText( noticeMessage ) ).not.toBeInTheDocument();
	} );

	it.each( [
		{
			name: 'with a notice',
			notice: noticeProvider._admin_notice,
			tabIndex: '-1',
		},
		{ name: 'without a notice', notice: undefined, tabIndex: null },
	] )(
		'makes the page container focusable only $name',
		( { notice, tabIndex } ) => {
			act( () => {
				dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
					[ { ...noticeProvider, _admin_notice: notice } ],
					[],
					[],
					[]
				);
			} );
			const { container } = render(
				<Router>
					<SettingsPaymentsMain />
				</Router>
			);

			expect(
				container
					.querySelector( '.settings-payments-main__container' )
					?.getAttribute( 'tabindex' )
			).toBe( tabIndex );
		}
	);

	it( 'ignores notices attached to other gateways', () => {
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[ { ...noticeProvider, id: 'other_gateway' } ],
				[],
				[],
				[]
			);
		} );
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);
		expect( screen.queryByText( noticeMessage ) ).not.toBeInTheDocument();
	} );

	it( 'ignores notices attached to suggestions and offline methods', () => {
		const suggestion = {
			...noticeProvider,
			_type: 'suggestion',
		} as unknown as SuggestedPaymentsExtension;
		const offlineMethod = {
			...noticeProvider,
			_type: 'offline_pm',
		} as unknown as OfflinePaymentMethodProvider;
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[],
				[ offlineMethod ],
				[ suggestion ],
				[]
			);
		} );
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect( screen.queryByText( noticeMessage ) ).not.toBeInTheDocument();
	} );

	describe( 'the WooPayments card in more payment options', () => {
		const renderWithSuggestion = async (
			plugin: Record< string, string >
		) => {
			act( () => {
				dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
					[],
					[],
					[
						{
							...hiddenWooPaymentsSuggestion,
							plugin,
						} as SuggestedPaymentsExtension,
					],
					[ pspCategory ]
				);
			} );
			render(
				<Router>
					<SettingsPaymentsMain />
				</Router>
			);
			await userEvent.click(
				screen.getByRole( 'button', { name: /More payment options/ } )
			);
		};

		afterEach( () => {
			window.history.replaceState( {}, '', '/' );
		} );

		it( 'opens the built-in onboarding for the hidden built-in suggestion and installs nothing', async () => {
			await renderWithSuggestion( { status: 'not_installed' } );

			await userEvent.click(
				screen.getByRole( 'button', { name: /^(Set up|Install)$/ } )
			);

			expect( await screen.findByRole( 'dialog' ) ).toHaveTextContent(
				'WooPayments onboarding'
			);
			expect( apiFetch ).not.toHaveBeenCalledWith(
				expect.objectContaining( {
					path: expect.stringContaining( '/plugins/install' ),
				} )
			);
		} );

		it( "installs the WooPayments extension from the extension's suggestion", async () => {
			// The install stays pending, so the test ends at the request.
			( apiFetch as jest.Mock ).mockImplementation( ( request ) =>
				request.path?.includes( '/plugins/install' )
					? new Promise( () => {} )
					: Promise.resolve( {} )
			);
			await renderWithSuggestion( {
				slug: 'woocommerce-payments',
				status: 'not_installed',
			} );

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Install' } )
			);

			await waitFor( () =>
				expect( apiFetch ).toHaveBeenCalledWith(
					expect.objectContaining( {
						path: expect.stringContaining( '/plugins/install' ),
						data: expect.objectContaining( {
							plugins: 'woocommerce-payments',
						} ),
					} )
				)
			);
			expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
		} );
	} );

	it( 'should record settings_payments_pageview event on load', () => {
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect( recordEvent ).toHaveBeenCalledWith(
			'settings_payments_pageview',
			expect.objectContaining( {
				business_country: expect.any( String ),
			} )
		);
	} );

	it( 'should trigger event recommendations_other_options when clicking the more payment options link', () => {
		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		fireEvent.click( screen.getByText( 'More payment options' ) );

		expect( recordEvent ).toHaveBeenCalledWith(
			'settings_payments_recommendations_other_options',
			expect.objectContaining( {
				available_payment_methods: expect.any( String ),
				business_country: expect.any( String ),
			} )
		);
	} );

	it( 'should navigate to the marketplace when clicking the more payment options link', () => {
		const { isFeatureEnabled } = jest.requireMock( '~/utils/features' );
		( isFeatureEnabled as jest.Mock ).mockReturnValue( true );

		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		const morePaymentOptionsLink = screen.getByText(
			'More payment options'
		);

		// Verify the link has the correct href attribute for external navigation
		expect( morePaymentOptionsLink.closest( 'a' ) ).toHaveAttribute(
			'href',
			'https://woocommerce.com/product-category/woocommerce-extensions/payment-gateways/?utm_source=payments_recommendations'
		);

		// Verify the link opens in a new tab
		expect( morePaymentOptionsLink.closest( 'a' ) ).toHaveAttribute(
			'target',
			'_blank'
		);

		// Verify security attributes are present for external links
		expect( morePaymentOptionsLink.closest( 'a' ) ).toHaveAttribute(
			'rel',
			expect.stringContaining( 'noopener' )
		);

		expect( morePaymentOptionsLink.closest( 'a' ) ).toHaveAttribute(
			'rel',
			expect.stringContaining( 'noreferrer' )
		);
	} );

	it( 'should filter the marketplace link by the selected business location', () => {
		window.wcSettings.admin.woocommerce_payments_nox_profile = {
			business_country_code: 'BR',
		};

		render(
			<Router>
				<SettingsPaymentsMain />
			</Router>
		);

		expect(
			screen.getByRole( 'link', {
				name: 'More payment options (opens in a new tab)',
			} )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/product-category/woocommerce-extensions/payment-gateways/?utm_source=payments_recommendations&country=BR'
		);
	} );
} );
