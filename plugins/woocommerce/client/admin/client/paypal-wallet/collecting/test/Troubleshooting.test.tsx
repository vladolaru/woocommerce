/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Troubleshooting from '../../app/Components/Screens/Settings/Components/Settings/Blocks/Troubleshooting';

jest.mock( '../../app/data', () => ( {
	SettingsHooks: {
		useSettings: () => ( { logging: false, setLogging: jest.fn() } ),
	},
} ) );

jest.mock(
	'../../app/Components/Screens/Settings/Components/Settings/Blocks/ResubscribeBlock',
	() => ( {
		__esModule: true,
		default: () => <button>Resubscribe webhooks</button>,
	} )
);

jest.mock(
	'../../app/Components/Screens/Settings/Components/Settings/Blocks/SimulationBlock',
	() => ( {
		__esModule: true,
		default: () => <button>Simulate webhooks</button>,
	} )
);

jest.mock(
	'../../app/Components/Screens/Settings/Components/Settings/Blocks/HooksListBlock',
	() => ( {
		__esModule: true,
		default: () => <div>Webhook list</div>,
	} )
);

const COLLECTING_NOTE =
	'Webhooks are managed by WooCommerce while PayPal Wallet setup is in progress.';
const CONNECTED_NOTE = 'Webhooks are managed by WooCommerce.';

/**
 * The Troubleshooting block's seam: on a store the platform serves, the webhook tools give way to the note the server
 * sends in `ppcpSettings.collecting.webhooks_note`.
 */
describe( 'Troubleshooting on a store the platform serves', () => {
	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it.each( [
		[ 'collecting', COLLECTING_NOTE ],
		[ 'platform_connected', CONNECTED_NOTE ],
	] )( 'shows the note and no webhook tools while %s', ( state, note ) => {
		window.ppcpSettings = {
			collecting: { state, webhooks_note: note },
		};

		render( <Troubleshooting /> );

		expect( screen.getByText( note ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Resubscribe webhooks',
				hidden: true, // The accordion starts closed.
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Simulate webhooks',
				hidden: true,
			} )
		).not.toBeInTheDocument();
		expect( screen.queryByText( 'Webhook list' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the webhook tools for any other store', () => {
		window.ppcpSettings = {};

		render( <Troubleshooting /> );

		expect( screen.queryByText( COLLECTING_NOTE ) ).not.toBeInTheDocument();
		expect( screen.queryByText( CONNECTED_NOTE ) ).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Resubscribe webhooks',
				hidden: true, // The accordion starts closed.
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Simulate webhooks',
				hidden: true,
			} )
		).toBeInTheDocument();
	} );
} );
