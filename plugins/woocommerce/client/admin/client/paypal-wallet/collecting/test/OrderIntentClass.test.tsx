/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import OrderIntent from '../../app/Components/Screens/Settings/Components/Settings/Blocks/OrderIntent';

jest.mock( '../../app/data', () => ( {
	SettingsHooks: {
		useSettings: () => ( {
			authorizeOnly: false,
			setAuthorizeOnly: jest.fn(),
			captureVirtualOnlyOrders: false,
			setCaptureVirtualOnlyOrders: jest.fn(),
		} ),
	},
} ) );

/**
 * POC hack guard: on a store the platform serves, core hides the forked Order Intent block with an inline style on the
 * `ppcp--order-intent` class (Collecting/Surface/SettingsAppData.php). This fails if the forked block loses that class,
 * which would show the Authorize-only toggle again.
 */
describe( 'The forked Order Intent block', () => {
	it( 'keeps the ppcp--order-intent class the served-store style hides', () => {
		const { container } = render( <OrderIntent /> );

		const block = container.querySelector( '.ppcp--order-intent' );
		expect( block ).not.toBeNull();
		expect( block ).toHaveTextContent( 'Authorize Only' );
	} );
} );
