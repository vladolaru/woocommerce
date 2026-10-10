/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { CollectingPanel } from '../CollectingPanel';
import {
	checkStatus,
	requestReferral,
	updatePayeeEmail,
	type CollectingData,
} from '../api';

jest.mock( '../api', () => ( {
	checkStatus: jest.fn(),
	requestReferral: jest.fn(),
	updatePayeeEmail: jest.fn(),
} ) );

jest.mock( '@wordpress/date', () => ( {
	...jest.requireActual( '@wordpress/date' ),
	dateI18n: ( _format: string, date: number ) => `DATE(${ date })`,
} ) );

const DEADLINE_1 = 1800000000;
const DEADLINE_2 = 1800086400;

const collecting = (
	overrides: Partial< CollectingData > = {}
): CollectingData => ( {
	state: 'collecting',
	payee_email: 'payee@example.com',
	can_change_payee_email: true,
	merchant_state: 'no_account',
	held_orders: [],
	held_orders_count: 0,
	earliest_deadline: null,
	transport_ready: true,
	...overrides,
} );

const setScriptData = ( data?: CollectingData ) => {
	window.ppcpSettings = data ? { collecting: data } : {};
};

describe( 'CollectingPanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it( 'renders nothing when the settings data has no collecting key', () => {
		setScriptData();

		const { container } = render( <CollectingPanel /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'lets the merchant edit the payee email while it can change', async () => {
		setScriptData( collecting() );
		( updatePayeeEmail as jest.Mock ).mockResolvedValue(
			collecting( { payee_email: 'new@example.com' } )
		);
		render( <CollectingPanel /> );

		const field = screen.getByRole( 'textbox', { name: 'PayPal email' } );
		await userEvent.clear( field );
		await userEvent.type( field, 'new@example.com' );
		await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( updatePayeeEmail ).toHaveBeenCalledWith( 'new@example.com' );
		// The notice also speaks its text through the a11y live region.
		expect(
			await screen.findAllByText( 'The PayPal email was saved.' )
		).not.toHaveLength( 0 );
	} );

	it( 'is laid out as a settings card, with the help describing the email field and the actions in one group', () => {
		setScriptData( collecting() );

		const { container } = render( <CollectingPanel /> );

		expect(
			container.querySelector(
				'.ppcp-r-settings-card.paypal-wallet-collecting-panel'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'textbox', { name: 'PayPal email' } )
		).toHaveAccessibleDescription(
			'Customers pay to this email until setup is complete. You can change it until the first payment.'
		);
		const actions = container.querySelector(
			'.paypal-wallet-collecting-panel__actions'
		);
		expect( actions ).toContainElement(
			screen.getByRole( 'button', { name: 'Complete setup' } )
		);
		expect( actions ).toContainElement(
			screen.getByRole( 'button', { name: 'Check status' } )
		);
	} );

	it( 'shows the payee email read-only once it is bound', () => {
		setScriptData( collecting( { can_change_payee_email: false } ) );

		render( <CollectingPanel /> );

		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Save' } )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'payee@example.com' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'A customer has paid to this email, so it can no longer change.'
			)
		).toBeInTheDocument();
	} );

	it.each( [
		[
			'no_account',
			'PayPal has no account for payee@example.com yet. Complete setup to create one and receive your payments.',
		],
		[
			'email_unconfirmed',
			'Confirm the email PayPal sent to payee@example.com to release the payment.',
		],
		[
			'confirmed_not_connected',
			'Your PayPal account payee@example.com is confirmed. Complete setup to connect it to your store.',
		],
		[ 'connected', 'PayPal Wallet is connected to payee@example.com.' ],
	] )( 'shows the merchant state copy for %s', ( state, copy ) => {
		setScriptData(
			collecting( {
				merchant_state: state as CollectingData[ 'merchant_state' ],
			} )
		);

		render( <CollectingPanel /> );

		expect( screen.getByText( copy ) ).toBeInTheDocument();
	} );

	it( 'lists the held orders with their deadlines', () => {
		setScriptData(
			collecting( {
				held_orders: [
					{
						id: 11,
						number: '11',
						deadline: DEADLINE_1,
						edit_url: 'https://example.org/order-11',
					},
					{
						id: 12,
						number: '12',
						deadline: DEADLINE_2,
						edit_url: 'https://example.org/order-12',
					},
				],
				held_orders_count: 2,
				earliest_deadline: DEADLINE_1,
			} )
		);

		render( <CollectingPanel /> );

		expect(
			screen.getByRole( 'link', { name: 'Order #11' } )
		).toHaveAttribute( 'href', 'https://example.org/order-11' );
		expect(
			screen.getByText(
				`Returned to the customer on DATE(${
					DEADLINE_1 * 1000
				}) if setup is not complete`
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				`Returned to the customer on DATE(${
					DEADLINE_2 * 1000
				}) if setup is not complete`
			)
		).toBeInTheDocument();
	} );

	describe( 'Complete setup', () => {
		const originalLocation = window.location;
		const assign = jest.fn();

		beforeEach( () => {
			assign.mockClear();
			Object.defineProperty( window, 'location', {
				value: { ...originalLocation, assign },
				writable: true,
			} );
		} );

		afterEach( () => {
			Object.defineProperty( window, 'location', {
				value: originalLocation,
				writable: true,
			} );
		} );

		it( 'goes to the PayPal setup link in the same tab', async () => {
			setScriptData( collecting() );
			( requestReferral as jest.Mock ).mockResolvedValue( {
				url: 'https://www.sandbox.paypal.com/referral',
			} );
			render( <CollectingPanel /> );

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Complete setup' } )
			);

			expect( requestReferral ).toHaveBeenCalledTimes( 1 );
			await waitFor( () =>
				expect( assign ).toHaveBeenCalledWith(
					'https://www.sandbox.paypal.com/referral'
				)
			);
		} );

		it( 'shows the error and stays on the page when the setup link cannot be made', async () => {
			setScriptData( collecting() );
			( requestReferral as jest.Mock ).mockRejectedValue( {
				message:
					'PayPal could not start the setup. Please try again later.',
			} );
			render( <CollectingPanel /> );

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Complete setup' } )
			);

			expect(
				await screen.findAllByText(
					'PayPal could not start the setup. Please try again later.'
				)
			).not.toHaveLength( 0 );
			expect( assign ).not.toHaveBeenCalled();
		} );

		it( 'is not offered once the store is connected', () => {
			setScriptData(
				collecting( {
					state: 'platform_connected',
					merchant_state: 'connected',
					can_change_payee_email: false,
				} )
			);

			render( <CollectingPanel /> );

			expect(
				screen.queryByRole( 'button', { name: 'Complete setup' } )
			).not.toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Check status' } )
			).toBeInTheDocument();
		} );
	} );

	it( 'checks the status, shows the new merchant state and says the status was updated', async () => {
		setScriptData( collecting() );
		( checkStatus as jest.Mock ).mockResolvedValue(
			collecting( {
				merchant_state: 'email_unconfirmed',
				check: 'incomplete',
			} )
		);
		render( <CollectingPanel /> );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Check status' } )
		);

		expect( checkStatus ).toHaveBeenCalledTimes( 1 );
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Confirm the email PayPal sent to payee@example.com to release the payment.'
		);
		expect(
			await screen.findAllByText( 'Status updated.' )
		).not.toHaveLength( 0 );
	} );

	it( 'shows an error when the status check could not reach PayPal', async () => {
		setScriptData( collecting() );
		( checkStatus as jest.Mock ).mockResolvedValue(
			collecting( { check: 'failed' } )
		);
		render( <CollectingPanel /> );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Check status' } )
		);

		expect(
			await screen.findAllByText(
				'PayPal could not be reached. Please try again later.'
			)
		).not.toHaveLength( 0 );
		expect(
			screen.queryByText( 'Status updated.' )
		).not.toBeInTheDocument();
	} );

	it( 'keeps keyboard focus on the button while its request runs', async () => {
		setScriptData( collecting() );
		let finish: ( value: CollectingData ) => void = () => undefined;
		( checkStatus as jest.Mock ).mockReturnValue(
			new Promise< CollectingData >( ( resolve ) => {
				finish = resolve;
			} )
		);
		render( <CollectingPanel /> );
		const button = screen.getByRole( 'button', { name: 'Check status' } );

		await userEvent.click( button );

		expect( button ).toHaveFocus();
		expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
		await userEvent.click( button );
		expect( checkStatus ).toHaveBeenCalledTimes( 1 );

		finish( collecting() );
		await waitFor( () =>
			expect( button ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
		expect( button ).toHaveFocus();
	} );

	it( 'names the connected account in the read-only payee note once the store is connected', () => {
		setScriptData(
			collecting( {
				state: 'platform_connected',
				merchant_state: 'connected',
				can_change_payee_email: false,
			} )
		);

		render( <CollectingPanel /> );

		expect(
			screen.getByText(
				'This is the PayPal account connected to your store.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText(
				'A customer has paid to this email, so it can no longer change.'
			)
		).not.toBeInTheDocument();
	} );

	it( 'explains that setup is not available and offers no action when the transport is not configured', () => {
		setScriptData( collecting( { transport_ready: false } ) );

		render( <CollectingPanel /> );

		expect(
			screen.getByText(
				'PayPal Wallet setup is not available on this store yet.'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the error when a request fails', async () => {
		setScriptData( collecting() );
		( checkStatus as jest.Mock ).mockRejectedValue( {
			message:
				'PayPal Wallet is not running on this store, so its status cannot be checked.',
		} );
		render( <CollectingPanel /> );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Check status' } )
		);

		expect(
			await screen.findAllByText(
				'PayPal Wallet is not running on this store, so its status cannot be checked.'
			)
		).not.toHaveLength( 0 );
	} );
} );
