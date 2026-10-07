import { render, screen, within } from '@testing-library/react';

import PaymentMethodsBlock from './PaymentMethodsBlock';

const mockChangePaymentSettings = jest.fn();

jest.mock( '../../../data', () => ( {
	PaymentHooks: {
		useStore: () => ( {
			changePaymentSettings: mockChangePaymentSettings,
		} ),
	},
} ) );

const PAYMENTS_LIST_URL = '/wp-admin/admin.php?page=wc-settings&tab=checkout';

const method = ( id, itemTitle, enabled, extra = {} ) => ( {
	id,
	itemTitle,
	itemDescription: `${ itemTitle } description`,
	enabled,
	...extra,
} );

// The toggles carry no accessible name (ToggleControl drops aria-label), so find them by their method card.
const toggleOf = ( container, methodId ) =>
	within( container.querySelector( `#${ methodId }` ) ).getByRole(
		'checkbox'
	);

const renderBlock = ( paypalEnabled ) =>
	render(
		<PaymentMethodsBlock
			paymentMethods={ [
				method( 'ppcp-gateway', 'PayPal', paypalEnabled, {
					fields: { paypalShowLogo: {} },
				} ),
				method( 'venmo', 'Venmo', true ),
			] }
			onTriggerModal={ jest.fn() }
		/>
	);

describe( 'PaymentMethodsBlock', () => {
	beforeEach( () => {
		window.ppcpSettings = { wcPaymentsTabUrl: PAYMENTS_LIST_URL };
	} );

	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it.each( [ true, false ] )(
		'shows the PayPal gateway state (%s) with a disabled toggle',
		( enabled ) => {
			const { container } = renderBlock( enabled );

			const toggle = toggleOf( container, 'ppcp-gateway' );
			expect( toggle ).toBeDisabled();
			expect( toggle.checked ).toBe( enabled );
		}
	);

	it( 'points to the Payments list for turning PayPal Wallet on or off', () => {
		renderBlock( true );

		expect(
			screen.getByText( /Turn PayPal Wallet on or off from the/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Payments list' } )
		).toHaveAttribute( 'href', PAYMENTS_LIST_URL );
	} );

	it( 'keeps the PayPal settings button and the other toggles working', () => {
		const { container } = renderBlock( true );

		expect(
			screen.getByRole( 'button', { name: 'Configure PayPal settings' } )
		).toBeEnabled();
		expect( toggleOf( container, 'venmo' ) ).toBeEnabled();
		expect(
			screen.queryAllByText( /Turn PayPal Wallet on or off/ )
		).toHaveLength( 1 );
	} );
} );
