/**
 * External dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { Disabled } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import '../index';
import { BLOCK_ATTRIBUTES, BLOCK_NAME, Edit, blockSettings } from '../block';

jest.mock( '@wordpress/blocks', () => ( {
	registerBlockType: jest.fn(),
} ) );

jest.mock( '@wordpress/server-side-render', () => jest.fn( () => null ) );

jest.mock( '@wordpress/components', () => ( {
	...jest.requireActual( '@wordpress/components' ),
	Disabled: jest.fn( ( { children } ) => children ),
} ) );

jest.mock( '@wordpress/block-editor', () => {
	const { createElement, Fragment } =
		jest.requireActual( '@wordpress/element' );

	return {
		ColorPaletteControl: ( { label, onChange, value } ) =>
			createElement( 'input', {
				'aria-label': label,
				onChange: ( event ) => onChange( event.target.value ),
				value,
			} ),
		InspectorControls: ( { children } ) =>
			createElement( Fragment, null, children ),
		useBlockProps: () => ( {} ),
	};
} );

const attributes = {
	symbol: true,
	flag: false,
	fontSize: 14,
	fontLineHeight: 1.5,
	fontColor: '#000000',
	border: true,
	borderRadius: 3,
	borderColor: '#000000',
	backgroundColor: 'transparent',
};

describe( 'Multi-currency switcher block', () => {
	beforeEach( () => {
		Disabled.mockClear();
		ServerSideRender.mockClear();
	} );

	it( 'registers the preserved public block contract', () => {
		expect( BLOCK_NAME ).toBe(
			'woocommerce-payments/multi-currency-switcher'
		);
		expect( BLOCK_ATTRIBUTES ).toEqual( {
			symbol: { type: 'boolean', default: true },
			flag: { type: 'boolean', default: false },
			fontSize: { type: 'integer', default: 14 },
			fontLineHeight: { type: 'number', default: 1.5 },
			fontColor: { type: 'string', default: '#000000' },
			border: { type: 'boolean', default: true },
			borderRadius: { type: 'integer', default: 3 },
			borderColor: { type: 'string', default: '#000000' },
			backgroundColor: { type: 'string', default: 'transparent' },
		} );
		expect( blockSettings.apiVersion ).toBe( 3 );
		expect( blockSettings.attributes ).toBe( BLOCK_ATTRIBUTES );
		expect( blockSettings.title ).toBe( 'Currency switcher' );
		expect( blockSettings.description ).toBe(
			'Let customers switch between enabled currencies.'
		);
		expect( blockSettings.edit ).toBe( Edit );
		expect( blockSettings.save() ).toBeNull();
		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		expect( registerBlockType ).toHaveBeenCalledWith(
			BLOCK_NAME,
			blockSettings
		);
	} );

	it( 'renders a server-side preview with the current attributes', () => {
		render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		expect( ServerSideRender.mock.calls[ 0 ][ 0 ] ).toEqual(
			expect.objectContaining( {
				attributes,
				block: BLOCK_NAME,
			} )
		);
		expect( Disabled ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'link', {
				name: /^Adjust multi-currency settings/,
			} )
		).toHaveAttribute(
			'href',
			'admin.php?page=wc-settings&tab=wcpay_multi_currency'
		);
		expect(
			screen.getByRole( 'checkbox', { name: 'Show border' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'spinbutton', { name: 'Border radius' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'spinbutton', { name: 'Font size' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'spinbutton', { name: 'Line height' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Text color' ) ).toBeInTheDocument();
		expect(
			screen.getByLabelText( 'Background color' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Border color' ) ).toBeInTheDocument();
	} );

	it( 'updates the persisted flag and symbol settings accessibly', async () => {
		const setAttributes = jest.fn();
		const user = userEvent.setup();

		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);

		await user.click(
			screen.getByRole( 'checkbox', { name: 'Display flags' } )
		);
		expect( setAttributes ).toHaveBeenCalledWith( { flag: true } );

		await user.click(
			screen.getByRole( 'checkbox', {
				name: 'Display currency symbols',
			} )
		);
		expect( setAttributes ).toHaveBeenCalledWith( { symbol: false } );
	} );
} );
