/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import DisconnectButton from '../../app/Components/Screens/Settings/Components/Settings/Parts/DisconnectButton';

jest.mock( '../../app/data', () => ( {
	CommonHooks: {
		useDisconnectMerchant: () => ( { disconnectMerchant: jest.fn() } ),
	},
} ) );

jest.mock( '../../app/hooks/useNavigation', () => ( {
	useNavigation: () => ( { goToPluginSettings: jest.fn() } ),
} ) );

/**
 * The Disconnect button's seam: a store the platform serves has `ppcpSettings.collecting`, and WooCommerce manages its
 * connection, so the settings app offers no Disconnect.
 */
describe( 'DisconnectButton on a store the platform serves', () => {
	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it.each( [ 'collecting', 'platform_connected' ] )(
		'is hidden while %s',
		( state ) => {
			window.ppcpSettings = { collecting: { state } };

			render( <DisconnectButton /> );

			expect(
				screen.queryByRole( 'button', { name: 'Disconnect' } )
			).not.toBeInTheDocument();
		}
	);

	it( 'is shown for any other store', () => {
		window.ppcpSettings = {};

		render( <DisconnectButton /> );

		expect(
			screen.getByRole( 'button', { name: 'Disconnect' } )
		).toBeInTheDocument();
	} );
} );
