/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';
import type { PaymentsProviderNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { ProviderNotice } from '../provider-notice';

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

	it( 'calls the dismiss handler and hides the notice', () => {
		const onDismiss = jest.fn();
		render( <ProviderNotice notice={ notice } onDismiss={ onDismiss } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByText( notice.title ) ).not.toBeInTheDocument();
	} );

	it( 'requests the dismissal URL when no handler is given', () => {
		const fetchMock = jest.fn().mockResolvedValue( {} );
		window.fetch = fetchMock;
		render( <ProviderNotice notice={ notice } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

		expect( fetchMock ).toHaveBeenCalledWith( notice.dismiss_url, {
			method: 'POST',
			credentials: 'same-origin',
		} );
		expect( screen.queryByText( notice.title ) ).not.toBeInTheDocument();
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
