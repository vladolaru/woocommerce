import { render, screen } from '@testing-library/react';

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

const toggleOf = ( name ) =>
	screen.getByRole( 'checkbox', { name: `Enable ${ name }` } );

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
			renderBlock( enabled );

			const toggle = toggleOf( 'PayPal' );
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

	it( 'turns a method on or off from its named toggle', () => {
		renderBlock( true );

		toggleOf( 'Venmo' ).click();

		expect( mockChangePaymentSettings ).toHaveBeenCalledWith( 'venmo', {
			enabled: false,
		} );
	} );

	it( 'keeps the PayPal settings button and the other toggles working', () => {
		renderBlock( true );

		expect(
			screen.getByRole( 'button', { name: 'Configure PayPal settings' } )
		).toBeEnabled();
		expect( toggleOf( 'Venmo' ) ).toBeEnabled();
		expect(
			screen.queryAllByText( /Turn PayPal Wallet on or off/ )
		).toHaveLength( 1 );
	} );
} );
