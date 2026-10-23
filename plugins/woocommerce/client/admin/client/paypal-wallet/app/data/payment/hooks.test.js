import { renderHook } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import { usePaymentMethods } from './hooks';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

const method = ( id ) => ( { id, enabled: false } );

const PAYPAL_GROUP = {
	'ppcp-gateway': method( 'ppcp-gateway' ),
	venmo: method( 'venmo' ),
	'pay-later': method( 'pay-later' ),
};

const renderWithPersistentData = ( persistentData ) => {
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			persistentData: () => persistentData,
		} ) )
	);
	useDispatch.mockReturnValue( {} );

	return renderHook( () => usePaymentMethods() );
};

describe( 'usePaymentMethods()', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	test( 'returns Pay with Crypto as the alternative payment methods when the store holds it', () => {
		const { result } = renderWithPersistentData( {
			...PAYPAL_GROUP,
			'ppcp-pwc': method( 'ppcp-pwc' ),
		} );

		expect( result.current.apm.map( ( { id } ) => id ) ).toEqual( [
			'ppcp-pwc',
		] );
	} );

	test( 'returns no alternative payment methods when the store holds none', () => {
		const { result } = renderWithPersistentData( {
			...PAYPAL_GROUP,
			'ppcp-pwc': {},
		} );

		expect( result.current.apm ).toEqual( [] );
		expect( result.current.paypal.map( ( { id } ) => id ) ).toEqual( [
			'ppcp-gateway',
			'venmo',
			'pay-later',
		] );
	} );

	test( 'lists the PayPal group, then the alternative payment methods, in all', () => {
		const { result } = renderWithPersistentData( {
			...PAYPAL_GROUP,
			'ppcp-pwc': method( 'ppcp-pwc' ),
		} );

		expect( result.current.all.map( ( { id } ) => id ) ).toEqual( [
			'ppcp-gateway',
			'venmo',
			'pay-later',
			'ppcp-pwc',
		] );
	} );
} );
