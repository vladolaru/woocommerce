import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import Notice from './Notice';

describe( 'Notice Component', () => {
	it( 'renders with the default params', () => {
		render( <Notice>Test</Notice> );
		const element = screen.getByText( 'Test' );

		expect( element ).toBeInTheDocument();
		expect( element.tagName ).toBe( 'SPAN' );
		expect( element ).toHaveClass( 'ppcp--notice' );
		expect( element ).toHaveClass( 'type--info' );
	} );

	it( 'loads the type param in the class', () => {
		render( <Notice type="syde">Test</Notice> );
		const element = screen.getByText( 'Test' );

		expect( element ).toBeInTheDocument();
		expect( element ).toHaveClass( 'type--syde' );
		expect( element ).not.toHaveClass( 'type--info' );
	} );

	it( 'loads custom classnames', () => {
		render( <Notice className="test">Test</Notice> );
		const element = screen.getByText( 'Test' );

		expect( element ).toBeInTheDocument();
		expect( element ).toHaveClass( 'test' );
	} );
} );
