import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { WithScriptParams } from './with-script-params';

const mockUseScriptParams = jest.fn();

jest.mock( '../hooks/script-params', () => ( {
	useScriptParams: ( ...args ) => mockUseScriptParams( ...args ),
} ) );

const requestConfig = { endpoint: '/wp-json/ppcp/cart-script-params' };

describe( 'WithScriptParams', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the fallback and not the children while the params are loading', () => {
		mockUseScriptParams.mockReturnValue( null );
		const children = jest.fn( () => <p>Preview</p> );

		render(
			<WithScriptParams
				requestConfig={ requestConfig }
				fallback={ <p>Loading</p> }
			>
				{ children }
			</WithScriptParams>
		);

		expect( mockUseScriptParams ).toHaveBeenCalledWith( requestConfig );
		expect( screen.getByText( 'Loading' ) ).toBeInTheDocument();
		expect( children ).not.toHaveBeenCalled();
	} );

	it( 'calls the children with false when the request failed', () => {
		mockUseScriptParams.mockReturnValue( false );
		const children = jest.fn( () => <p>Preview</p> );

		render(
			<WithScriptParams
				requestConfig={ requestConfig }
				fallback={ <p>Loading</p> }
			>
				{ children }
			</WithScriptParams>
		);

		expect( children ).toHaveBeenCalledWith( false );
		expect( screen.getByText( 'Preview' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Loading' ) ).not.toBeInTheDocument();
	} );

	it( 'calls the children with the params when the request succeeded', () => {
		const params = { url_params: { 'client-id': 'test' } };
		mockUseScriptParams.mockReturnValue( params );
		const children = jest.fn( () => <p>Preview</p> );

		render(
			<WithScriptParams
				requestConfig={ requestConfig }
				fallback={ <p>Loading</p> }
			>
				{ children }
			</WithScriptParams>
		);

		expect( children ).toHaveBeenCalledWith( params );
		expect( screen.getByText( 'Preview' ) ).toBeInTheDocument();
	} );
} );
