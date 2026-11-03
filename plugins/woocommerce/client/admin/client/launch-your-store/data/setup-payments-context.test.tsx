/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import { paymentSettingsStore, pluginsStore } from '@woocommerce/data';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import {
	SetUpPaymentsProvider,
	useSetUpPaymentsContext,
} from './setup-payments-context';
import { PaymentsSidebar } from '../hub/sidebar/components/payments-sidebar';
import type { SidebarComponentProps } from '../hub/sidebar/xstate';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	paymentSettingsStore: 'paymentSettingsStore',
	pluginsStore: 'pluginsStore',
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	getNewPath: jest.fn( () => 'native-woopayments-onboarding-url' ),
} ) );

jest.mock(
	'~/settings-payments/onboarding/providers/woopayments/steps',
	() => ( {
		LYSPaymentsSteps: [],
	} )
);

jest.mock(
	'~/settings-payments/onboarding/providers/woopayments/data/onboarding-context',
	() => ( {
		OnboardingProvider: ( { children }: { children: ReactNode } ) => (
			<div data-testid="onboarding-provider">{ children }</div>
		),
		// The onboarding steps the provider loads for the payments step's sidebar.
		useOnboardingContext: () => ( {
			steps: [
				{ id: 'payment_methods', label: 'Choose your payment methods' },
				{ id: 'wpcom_connection', label: 'Connect with WordPress.com' },
				{ id: 'activate_payments', label: 'Activate payments' },
			],
			currentStep: null,
			justCompletedStepId: null,
			isLoading: false,
		} ),
	} )
);

jest.mock(
	'@wordpress/edit-site/build-module/components/sidebar-navigation-item',
	() => ( {
		__esModule: true,
		default: ( { children }: { children: ReactNode } ) => (
			<div data-testid="sidebar-navigation-item">{ children }</div>
		),
	} )
);

jest.mock(
	'~/launch-your-store/hub/sidebar/components/sidebar-container',
	() => ( {
		SidebarContainer: ( { children }: { children: ReactNode } ) => (
			<div>{ children }</div>
		),
	} )
);

jest.mock( '~/customize-store/site-hub', () => ( {
	SiteHub: () => null,
} ) );

jest.mock( '~/launch-your-store/hub/sidebar/tasklist', () => ( {
	getPaymentsTaskFromLysTasklist: jest.fn().mockResolvedValue( {
		id: 'payments',
		additionalData: { wooPaymentsIsInstalled: false },
	} ),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

const ContextReader = () => {
	const {
		isWooPaymentsActive,
		isWooPaymentsInstalled,
		isBuiltinWooPayments,
	} = useSetUpPaymentsContext();

	return (
		<div>
			<span data-testid="is-active">
				{ isWooPaymentsActive ? 'true' : 'false' }
			</span>
			<span data-testid="is-installed">
				{ isWooPaymentsInstalled ? 'true' : 'false' }
			</span>
			<span data-testid="is-builtin">
				{ isBuiltinWooPayments ? 'true' : 'false' }
			</span>
		</div>
	);
};

// Trunk's onboarding type for the extension's suggestion, and for the built-in suggestion.
const onboarding = {
	type: 'native_in_context',
	_links: { onboard: { href: 'onboarding-url' } },
};

const mockStores = (
	provider: Record< string, unknown > | null,
	activePlugins: string[],
	installedPlugins: string[],
	suggestions: Record< string, unknown >[] = []
) =>
	( useSelect as jest.Mock ).mockImplementation( ( selector ) =>
		selector( ( store: unknown ) => {
			if ( store === paymentSettingsStore ) {
				return {
					isFetching: () => false,
					getPaymentProviders: () => ( provider ? [ provider ] : [] ),
					getSuggestions: () => suggestions,
				};
			}

			if ( store === pluginsStore ) {
				return {
					getActivePlugins: () => activePlugins,
					getInstalledPlugins: () => installedPlugins,
				};
			}

			return {};
		} )
	);

describe( 'SetUpPaymentsProvider', () => {
	afterEach( () => {
		jest.clearAllMocks();
	} );

	it.each( [
		{
			name: 'the built-in WooPayments gateway',
			provider: {
				id: 'woocommerce_payments',
				plugin: { slug: 'woocommerce', status: 'active' },
				onboarding,
				_native: true,
			},
			activePlugins: [],
			installedPlugins: [],
			expected: [ 'true', 'true', 'true' ],
		},
		{
			name: 'the built-in WooPayments suggestion',
			provider: {
				id: '_wc_pes_woopayments',
				plugin: { file: '', status: 'not_installed' },
				onboarding,
				_native: true,
			},
			activePlugins: [],
			installedPlugins: [],
			expected: [ 'true', 'true', 'true' ],
		},
		{
			name: "the WooPayments extension's suggestion, not installed",
			provider: {
				id: '_wc_pes_woopayments',
				plugin: {
					slug: 'woocommerce-payments',
					status: 'not_installed',
				},
				onboarding,
			},
			activePlugins: [],
			installedPlugins: [],
			expected: [ 'false', 'false', 'false' ],
		},
		{
			name: "the WooPayments extension's suggestion, installed and inactive",
			provider: {
				id: '_wc_pes_woopayments',
				plugin: { slug: 'woocommerce-payments', status: 'installed' },
				onboarding,
			},
			activePlugins: [],
			installedPlugins: [ 'woocommerce-payments' ],
			expected: [ 'false', 'true', 'false' ],
		},
		{
			name: 'the active WooPayments extension',
			provider: {
				id: 'woocommerce_payments',
				plugin: { slug: 'woocommerce-payments', status: 'active' },
				onboarding,
			},
			activePlugins: [ 'woocommerce-payments' ],
			installedPlugins: [ 'woocommerce-payments' ],
			expected: [ 'true', 'true', 'false' ],
		},
	] )(
		'exposes active, installed and built-in for $name',
		( { provider, activePlugins, installedPlugins, expected } ) => {
			mockStores( provider, activePlugins, installedPlugins );

			render(
				<SetUpPaymentsProvider closeModal={ jest.fn() }>
					<ContextReader />
				</SetUpPaymentsProvider>
			);

			const [ isActive, isInstalled, isBuiltin ] = expected;
			expect( screen.getByTestId( 'is-active' ) ).toHaveTextContent(
				isActive
			);
			expect( screen.getByTestId( 'is-installed' ) ).toHaveTextContent(
				isInstalled
			);
			expect( screen.getByTestId( 'is-builtin' ) ).toHaveTextContent(
				isBuiltin
			);
			// The onboarding provider wraps the hub only while WooPayments is active.
			expect( !! screen.queryByTestId( 'onboarding-provider' ) ).toBe(
				isActive === 'true'
			);
		}
	);
} );

describe( "Launch Your Store's payments step", () => {
	const sidebarProps = {
		sendEventToSidebar: jest.fn(),
		sendEventToMainContent: jest.fn(),
		onMobileClose: jest.fn(),
		context: {},
	} as unknown as SidebarComponentProps;

	afterEach( () => {
		jest.clearAllMocks();
	} );

	// The entities as the providers REST response lists them (live on a flag-on and a flag-off store).
	it.each( [
		{
			name: 'the built-in WooPayments suggestion, hidden into the suggestions list',
			provider: null,
			suggestions: [
				{
					id: 'woopayments',
					_type: 'psp',
					plugin: { status: 'not_installed' },
					onboarding,
				},
			],
			items: [
				'Set up WooPayments',
				'Choose your payment methods',
				'Connect with WordPress.com',
				'Activate payments',
			],
		},
		{
			name: "the WooPayments extension's suggestion, no extension installed",
			provider: {
				id: '_wc_pes_woopayments',
				plugin: {
					slug: 'woocommerce-payments',
					status: 'not_installed',
				},
				onboarding,
			},
			suggestions: [],
			items: [ 'Install WooPayments' ],
		},
		{
			name: "the WooPayments extension's suggestion, hidden into the suggestions list",
			provider: null,
			suggestions: [
				{
					id: 'woopayments',
					_type: 'psp',
					plugin: {
						_type: 'wporg',
						slug: 'woocommerce-payments',
						status: 'not_installed',
					},
				},
			],
			items: [ 'Install WooPayments' ],
		},
	] )(
		'lists the steps for $name',
		async ( { provider, suggestions, items } ) => {
			mockStores( provider, [], [], suggestions );

			render(
				<SetUpPaymentsProvider closeModal={ jest.fn() }>
					<PaymentsSidebar { ...sidebarProps } />
				</SetUpPaymentsProvider>
			);

			await waitFor( () =>
				expect(
					screen
						.getAllByTestId( 'sidebar-navigation-item' )
						.map( ( item ) => item.textContent )
				).toEqual( items )
			);
		}
	);
} );
