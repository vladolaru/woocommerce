/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
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
	} )
);

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
	provider: Record< string, unknown >,
	activePlugins: string[],
	installedPlugins: string[]
) =>
	( useSelect as jest.Mock ).mockImplementation( ( selector ) =>
		selector( ( store: unknown ) => {
			if ( store === paymentSettingsStore ) {
				return {
					isFetching: () => false,
					getPaymentProviders: () => [ provider ],
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
