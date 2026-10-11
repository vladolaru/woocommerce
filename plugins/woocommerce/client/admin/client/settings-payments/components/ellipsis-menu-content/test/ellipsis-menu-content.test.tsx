/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import { PaymentsProvider, PaymentsProviderLink } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { EllipsisMenuContent } from '../';

const links: PaymentsProviderLink[] = [
	{
		_type: 'about',
		url: 'http://example.com/about',
	},
	{
		_type: 'terms',
		url: 'http://example.com/terms',
	},
	{
		_type: 'pricing',
		url: 'http://example.com/pricing',
	},
	{
		_type: 'documentation',
		url: 'http://example.com/docs',
	},
	{
		_type: 'support',
		url: 'http://example.com/get-support',
	},
];

describe( 'EllipsisMenuContent component', () => {
	it( 'renders the correct links for the suggestion', () => {
		const { getByText } = render(
			<EllipsisMenuContent
				isSuggestion={ true }
				suggestionId={ 'bogus' }
				links={ links }
				providerId={ 'bogus' }
				pluginFile={ 'bogus/bogus' }
				onToggle={ function (): void {
					throw new Error( 'Function not implemented.' );
				} }
			/>
		);
		expect( getByText( 'See pricing & fees' ) ).toBeInTheDocument();
		expect( getByText( 'Learn more' ) ).toBeInTheDocument();
		expect( getByText( 'See Terms of Service' ) ).toBeInTheDocument();
		expect( getByText( 'Hide suggestion' ) ).toBeInTheDocument();
	} );

	it( 'renders the correct links for the non-enabled gateway', () => {
		const { getByText } = render(
			<EllipsisMenuContent
				isSuggestion={ false }
				links={ links }
				providerId={ 'bogus' }
				pluginFile={ 'bogus/bogus' }
				onToggle={ function (): void {
					throw new Error( 'Function not implemented.' );
				} }
			/>
		);
		expect( getByText( 'See pricing & fees' ) ).toBeInTheDocument();
		expect( getByText( 'Learn more' ) ).toBeInTheDocument();
		expect( getByText( 'See Terms of Service' ) ).toBeInTheDocument();
		expect( getByText( 'Deactivate' ) ).toBeInTheDocument();
	} );

	it( 'renders the correct links for the enabled gateway', () => {
		const { getByText } = render(
			<EllipsisMenuContent
				isSuggestion={ false }
				isEnabled={ true }
				links={ links }
				providerId={ 'bogus' }
				pluginFile={ 'bogus/bogus' }
				onToggle={ function (): void {
					throw new Error( 'Function not implemented.' );
				} }
			/>
		);
		expect( getByText( 'See pricing & fees' ) ).toBeInTheDocument();
		expect( getByText( 'View documentation' ) ).toBeInTheDocument();
		expect( getByText( 'Get support' ) ).toBeInTheDocument();
		expect( getByText( 'Disable' ) ).toBeInTheDocument();
	} );

	it( 'renders the correct links for the enabled gateway with sandbox account that can be reset', () => {
		const { getByText } = render(
			<EllipsisMenuContent
				isSuggestion={ false }
				isEnabled={ true }
				canResetAccount={ true }
				links={ links }
				providerId={ 'bogus' }
				pluginFile={ 'bogus/bogus' }
				onToggle={ function (): void {
					throw new Error( 'Function not implemented.' );
				} }
			/>
		);
		expect( getByText( 'See pricing & fees' ) ).toBeInTheDocument();
		expect( getByText( 'View documentation' ) ).toBeInTheDocument();
		expect( getByText( 'Get support' ) ).toBeInTheDocument();
		expect( getByText( 'Reset account' ) ).toBeInTheDocument();
		expect( getByText( 'Disable' ) ).toBeInTheDocument();
	} );

	it( 'does not offer Deactivate for the built-in WooPayments gateway', () => {
		const { getByText, queryByText } = render(
			<EllipsisMenuContent
				provider={ { id: 'woocommerce_payments' } as PaymentsProvider }
				isSuggestion={ false }
				isEnabled={ false }
				links={ links }
				pluginFile={ 'woocommerce/woocommerce' }
				onToggle={ () => {} }
			/>
		);
		expect( getByText( 'See pricing & fees' ) ).toBeInTheDocument();
		expect( queryByText( 'Deactivate' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the disabled Deactivate item for other bundled gateways', () => {
		const { getByRole } = render(
			<EllipsisMenuContent
				provider={ { id: 'paypal' } as PaymentsProvider }
				isSuggestion={ false }
				isEnabled={ false }
				links={ links }
				pluginFile={ 'woocommerce/woocommerce' }
				onToggle={ () => {} }
			/>
		);
		expect( getByRole( 'button', { name: 'Deactivate' } ) ).toBeDisabled();
	} );
} );
