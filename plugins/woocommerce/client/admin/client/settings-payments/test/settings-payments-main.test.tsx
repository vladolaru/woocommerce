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
import { dispatch } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';
import type { PaymentsProvider } from '@woocommerce/data';
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
		( apiFetch as jest.Mock ).mockImplementation( ( request ) => {
			if ( request.url === '/dismiss' ) {
				return new Promise( ( resolve ) => {
					finishDismiss = resolve;
				} );
			}
			if ( request.path?.includes( '/settings/payments/providers' ) ) {
				return Promise.resolve( {
					providers: [
						{ ...noticeProvider, _admin_notice: undefined },
					],
					offline_payment_methods: [],
					suggestions: [],
					suggestion_categories: [],
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
} );
