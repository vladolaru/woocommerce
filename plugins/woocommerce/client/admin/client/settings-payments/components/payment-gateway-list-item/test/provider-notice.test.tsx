/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import type { PaymentsProviderNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { ProviderNotice } from '../provider-notice';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

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

	beforeEach( () => jest.clearAllMocks() );

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
		const fetchMock = jest.fn().mockResolvedValue( { ok: true } );
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

	it( 'announces the dismissal and moves focus to the row actions', async () => {
		const speak = jest.requireMock( '@wordpress/a11y' ).speak as jest.Mock;
		window.fetch = jest.fn().mockResolvedValue( { ok: true } );
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
		window.fetch = jest.fn().mockResolvedValue( { ok: true } );
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

	it( 'shows no dismiss button when the notice is not dismissible', () => {
		render(
			<ProviderNotice notice={ { ...notice, dismissible: false } } />
		);

		expect(
			screen.queryByRole( 'button', { name: 'Close' } )
		).not.toBeInTheDocument();
	} );
} );
