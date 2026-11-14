/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { BuiltinBadge } from '../builtin-badge';

jest.mock( '~/utils/admin-settings', () => ( {
	WC_ASSET_URL: 'https://example.test/',
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

const openPopover = async () => {
	render( <BuiltinBadge suggestionId="woopayments" /> );
	fireEvent.click( screen.getByTestId( 'woocommerce-builtin-badge' ) );
	return screen.findByRole( 'link', { name: /Learn more/ } );
};

describe( 'BuiltinBadge component', () => {
	afterEach( () => jest.clearAllMocks() );

	it( 'shows the built-in label, sentence and link, with the official badge events', async () => {
		const link = await openPopover();

		expect(
			screen.getByRole( 'button', {
				name: 'Built-in WooPayments badge Built in',
			} )
		).toHaveAttribute( 'data-testid', 'woocommerce-builtin-badge' );
		expect(
			screen.getByText( /WooPayments is built into WooCommerce\./ )
		).toContainElement( link );
		expect( link ).toHaveAttribute(
			'href',
			'https://woocommerce.com/payments/'
		);
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( recordEvent ).toHaveBeenCalledWith(
			'settings_payments_official_badge_click',
			expect.objectContaining( { suggestion_id: 'woopayments' } )
		);

		fireEvent.click( link );
		expect( recordEvent ).toHaveBeenCalledWith(
			'settings_payments_official_badge_learn_more_click',
			expect.objectContaining( { suggestion_id: 'woopayments' } )
		);
	} );

	it.each( [
		[ 'Enter', 'Enter' ],
		[ 'Space', ' ' ],
	] )(
		'allows %s on the popover link without preventing its default action',
		async ( _name, key ) => {
			const link = await openPopover();
			link.focus();
			const event = new window.KeyboardEvent( 'keydown', {
				key,
				bubbles: true,
				cancelable: true,
			} );

			fireEvent( link, event );

			expect( event.defaultPrevented ).toBe( false );
			expect( link ).toBeInTheDocument();
		}
	);
} );
