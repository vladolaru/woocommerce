import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { __ } from '@wordpress/i18n';
import '@testing-library/jest-dom';
import SavePaymentMethods from './SavePaymentMethods';

// The package this app came from mocked @wordpress/i18n for every suite; this suite asserts on the calls.
jest.mock( '@wordpress/i18n', () => ( {
	__: jest.fn( ( text ) => text ),
} ) );

const mockUseSettings = {
	savePaypalAndVenmo: false,
	setSavePaypalAndVenmo: jest.fn(),
};

jest.mock( '../../../../../../data', () => ( {
	SettingsHooks: {
		useSettings: () => mockUseSettings,
	},
} ) );

const mockUseMerchantInfo = {
	features: {
		save_paypal_and_venmo: {
			enabled: true,
		},
	},
};

jest.mock( '../../../../../../data/common/hooks', () => ( {
	useMerchantInfo: () => mockUseMerchantInfo,
} ) );

jest.mock( '../../../../../ReusableComponents/SettingsBlock', () => {
	return ( { title, description, className, children } ) => (
		<div data-testid="settings-block" className={ className }>
			<h3>{ title }</h3>
			<p>{ description }</p>
			{ children }
		</div>
	);
} );

jest.mock( '../../../../../ReusableComponents/Controls', () => ( {
	ControlToggleButton: ( {
		id,
		label,
		description,
		value,
		onChange,
		disabled,
	} ) => (
		<div data-testid="control-toggle-button">
			<label htmlFor={ id }>{ label }</label>
			<div dangerouslySetInnerHTML={ { __html: description } } />
			<input
				id={ id }
				type="checkbox"
				checked={ value }
				onChange={ ( e ) => onChange( e.target.checked ) }
				disabled={ disabled }
			/>
		</div>
	),
} ) );

describe( 'SavePaymentMethods', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockUseSettings.savePaypalAndVenmo = false;
		mockUseMerchantInfo.features.save_paypal_and_venmo.enabled = true;
	} );

	describe( 'Rendering', () => {
		it( 'renders the component with correct title and description', () => {
			render( <SavePaymentMethods /> );

			expect(
				screen.getByText( 'Save payment methods' )
			).toBeInTheDocument();
			expect(
				screen.getByText( /Securely store customers' payment methods/ )
			).toBeInTheDocument();
			expect( screen.getByTestId( 'settings-block' ) ).toHaveClass(
				'ppcp--save-payment-methods'
			);
		} );

		it( 'renders PayPal and Venmo toggle button', () => {
			render( <SavePaymentMethods /> );

			expect(
				screen.getByText( 'Save PayPal and Venmo' )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					/Securely store your customers' PayPal accounts/
				)
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'checkbox', {
					name: 'Save PayPal and Venmo',
				} )
			).toBeInTheDocument();
		} );

		it( 'Does not render the component when the save_paypal_and_venmo feature is disabled', () => {
			mockUseMerchantInfo.features.save_paypal_and_venmo.enabled = false;

			const { container } = render( <SavePaymentMethods /> );

			expect( container.firstChild ).toBeNull();
		} );

		it( 'renders when the save_paypal_and_venmo feature is enabled', () => {
			mockUseMerchantInfo.features.save_paypal_and_venmo.enabled = true;

			render( <SavePaymentMethods /> );

			expect(
				screen.getByTestId( 'settings-block' )
			).toBeInTheDocument();
		} );
	} );

	describe( 'PayPal and Venmo toggle behavior', () => {
		it( 'displays correct value when feature is enabled', () => {
			mockUseSettings.savePaypalAndVenmo = true;
			mockUseMerchantInfo.features.save_paypal_and_venmo.enabled = true;

			render( <SavePaymentMethods /> );

			const checkbox = screen.getByRole( 'checkbox', {
				name: 'Save PayPal and Venmo',
			} );
			expect( checkbox ).toBeChecked();
		} );

		it( 'is enabled when feature is enabled', () => {
			mockUseMerchantInfo.features.save_paypal_and_venmo.enabled = true;

			render( <SavePaymentMethods /> );

			const checkbox = screen.getByRole( 'checkbox', {
				name: 'Save PayPal and Venmo',
			} );
			expect( checkbox ).not.toBeDisabled();
		} );

		it( 'calls setSavePaypalAndVenmo when toggled', () => {
			render( <SavePaymentMethods /> );

			const checkbox = screen.getByRole( 'checkbox', {
				name: 'Save PayPal and Venmo',
			} );
			fireEvent.click( checkbox );

			expect(
				mockUseSettings.setSavePaypalAndVenmo
			).toHaveBeenCalledWith( true );
		} );
	} );

	describe( 'Internationalization', () => {
		it( 'calls __ function for translatable strings', () => {
			render( <SavePaymentMethods /> );

			expect( __ ).toHaveBeenCalledWith(
				'Save payment methods',
				'woocommerce'
			);
			expect( __ ).toHaveBeenCalledWith(
				'Save PayPal and Venmo',
				'woocommerce'
			);
		} );
	} );

	describe( 'Integration with hooks', () => {
		it( 'uses values from useSettings hook', () => {
			mockUseSettings.savePaypalAndVenmo = true;

			render( <SavePaymentMethods /> );

			expect(
				screen.getByRole( 'checkbox', {
					name: 'Save PayPal and Venmo',
				} )
			).toBeChecked();
		} );
	} );
} );
