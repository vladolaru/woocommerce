/**
 * External dependencies
 */
import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
} from '@testing-library/react';
import type { PaymentsProviderNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { ProviderNotice } from '../provider-notice';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const stored = () =>
	Promise.resolve( {
		ok: true,
		status: 200,
		json: () => Promise.resolve( { success: true } ),
	} );

// Lets the promise callbacks of a settled request run.
const settle = () =>
	act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );

const notice: PaymentsProviderNotice = {
	title: 'Complete setup to receive your payment',
	text: 'A customer placed an order and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.',
	action_label: 'Complete setup',
	action_url: 'https://example.com/wp-admin/admin.php?page=wc-settings',
	dismissible: true,
	dismiss_url: 'https://example.com/?wc-ajax=wc_paypal_wallet_dismiss_notice',
};

describe( 'ProviderNotice', () => {
	const originalFetch = window.fetch;

	// Resets a speak implementation a test swapped in, too.
	beforeEach( () => jest.resetAllMocks() );

	afterEach( () => {
		window.fetch = originalFetch;
	} );

	it( 'renders nothing without a notice', () => {
		const { container } = render( <ProviderNotice /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the title, the text and the action as a button link', () => {
		render( <ProviderNotice notice={ notice } /> );

		expect( screen.getByText( notice.title ) ).toBeInTheDocument();
		expect( screen.getByText( notice.text ) ).toBeInTheDocument();
		const action = screen.getByRole( 'link', { name: 'Complete setup' } );
		expect( action ).toHaveAttribute( 'href', notice.action_url );
		// A secondary button, as the mock shows, not the Notice's default text link.
		expect( action ).toHaveClass( 'is-secondary' );
		expect( action ).not.toHaveClass( 'is-link' );
	} );

	it( 'requests the dismissal URL and hides the notice', () => {
		const fetchMock = jest.fn( stored );
		window.fetch = fetchMock;
		render( <ProviderNotice notice={ notice } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( fetchMock ).toHaveBeenCalledWith( notice.dismiss_url, {
			method: 'POST',
			credentials: 'same-origin',
		} );
		expect( screen.queryByText( notice.title ) ).not.toBeInTheDocument();
	} );

	it( 'restores the notice and announces the failure when the store refuses the dismissal', async () => {
		const speak = jest.requireMock( '@wordpress/a11y' ).speak as jest.Mock;
		window.fetch = jest
			.fn()
			.mockResolvedValue( { ok: false, status: 403 } );
		render( <ProviderNotice notice={ notice } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( await screen.findByText( notice.title ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith(
			'The notice could not be dismissed.',
			'assertive'
		);
		expect( screen.getByRole( 'button', { name: 'Close' } ) ).toHaveFocus();
	} );

	it( 'restores the notice when the request fails', async () => {
		window.fetch = jest.fn().mockRejectedValue( new Error( 'offline' ) );
		render( <ProviderNotice notice={ notice } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( await screen.findByText( notice.title ) ).toBeInTheDocument();
	} );

	it.each( [
		[
			'an empty body',
			() =>
				Promise.reject(
					new SyntaxError( 'Unexpected end of JSON input' )
				),
		],
		[ 'a 0 body', () => Promise.resolve( 0 ) ],
		[ 'a failure body', () => Promise.resolve( { success: false } ) ],
	] )(
		'restores the notice when a 200 answer carries %s',
		async ( _label, json ) => {
			const speak = jest.requireMock( '@wordpress/a11y' )
				.speak as jest.Mock;
			window.fetch = jest
				.fn()
				.mockResolvedValue( { ok: true, status: 200, json } );
			render( <ProviderNotice notice={ notice } /> );

			fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

			expect(
				await screen.findByText( notice.title )
			).toBeInTheDocument();
			expect( speak ).toHaveBeenCalledWith(
				'The notice could not be dismissed.',
				'assertive'
			);
			expect( speak ).not.toHaveBeenCalledWith( 'Notice dismissed.' );
		}
	);

	it( 'speaks only the failure message when it restores the notice', async () => {
		const speak = jest.requireMock( '@wordpress/a11y' ).speak as jest.Mock;
		// The Notice speaks through its own copy of @wordpress/a11y, which the mock does not replace, into this region.
		const polite = () => document.getElementById( 'a11y-speak-polite' );
		window.fetch = jest.fn().mockRejectedValue( new Error( 'offline' ) );
		render( <ProviderNotice notice={ notice } /> );
		expect( polite() ).toHaveTextContent( notice.title );
		polite()!.textContent = '';

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( await screen.findByText( notice.title ) ).toBeInTheDocument();
		await settle();
		expect( polite()?.textContent ).toBe( '' );
		expect( speak.mock.calls ).toEqual( [
			[ 'The notice could not be dismissed.', 'assertive' ],
		] );
	} );

	it( 'keeps the notice hidden when announcing a stored dismissal throws', async () => {
		const speak = jest.requireMock( '@wordpress/a11y' ).speak as jest.Mock;
		speak.mockImplementation( ( message: string ) => {
			if ( message === 'Notice dismissed.' ) {
				throw new Error( 'speak failed' );
			}
		} );
		window.fetch = jest.fn( stored );
		render( <ProviderNotice notice={ notice } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		await waitFor( () =>
			expect( speak ).toHaveBeenCalledWith( 'Notice dismissed.' )
		);
		await settle();
		expect( screen.queryByText( notice.title ) ).not.toBeInTheDocument();
	} );

	it( 'announces the dismissal and moves focus to the row actions', async () => {
		const speak = jest.requireMock( '@wordpress/a11y' ).speak as jest.Mock;
		window.fetch = jest.fn( stored );
		render(
			<div className="woocommerce-list__item">
				<div className="woocommerce-list__item-after__actions">
					<button>Menu</button>
				</div>
				<ProviderNotice notice={ notice } />
			</div>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( screen.getByRole( 'button', { name: 'Menu' } ) ).toHaveFocus();
		await waitFor( () =>
			expect( speak ).toHaveBeenCalledWith( 'Notice dismissed.' )
		);
		expect( screen.queryByText( notice.title ) ).not.toBeInTheDocument();
	} );

	it( 'moves focus to the row actions, not to an earlier link in the row', () => {
		window.fetch = jest.fn( stored );
		render(
			<div className="woocommerce-list__item">
				<a href="#manage">Manage</a>
				<div className="woocommerce-list__item-after__actions">
					<button>Menu</button>
				</div>
				<ProviderNotice notice={ notice } />
			</div>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( screen.getByRole( 'button', { name: 'Menu' } ) ).toHaveFocus();
	} );

	it( 'skips a disabled button in the row actions', () => {
		window.fetch = jest.fn( stored );
		render(
			<div className="woocommerce-list__item">
				<div className="woocommerce-list__item-after__actions">
					<button disabled>Enable</button>
					<button>Menu</button>
				</div>
				<ProviderNotice notice={ notice } />
			</div>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( screen.getByRole( 'button', { name: 'Menu' } ) ).toHaveFocus();
	} );

	it( 'shows no dismiss button when the notice is not dismissible', () => {
		render(
			<ProviderNotice notice={ { ...notice, dismissible: false } } />
		);

		expect(
			screen.queryByRole( 'button', { name: 'Close' } )
		).not.toBeInTheDocument();
	} );
} );
