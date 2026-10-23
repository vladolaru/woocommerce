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
 * A store the platform serves has `ppcpSettings.collecting`, and its Disconnect leaves the served state on the server
 * (R275), so the settings app offers Disconnect as it does for any other store.
 */
describe( 'DisconnectButton on a store the platform serves', () => {
	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it.each( [ 'collecting', 'platform_connected' ] )(
		'is shown while %s',
		( state ) => {
			window.ppcpSettings = { collecting: { state } };

			render( <DisconnectButton /> );

			expect(
				screen.getByRole( 'button', { name: 'Disconnect' } )
			).toBeInTheDocument();
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
