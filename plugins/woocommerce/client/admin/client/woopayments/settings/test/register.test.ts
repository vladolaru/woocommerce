/**
 * External dependencies
 */
import { select } from '@wordpress/data';

const mockRegisteredStores: Record< string, unknown > = {};
const mockRegister = jest.fn( ( store: { name: string } ) => {
	mockRegisteredStores[ store.name ] = store;
} );

jest.mock( '@wordpress/data', () => ( {
	combineReducers: jest.fn( ( reducers ) => reducers ),
	createReduxStore: jest.fn( ( name, config ) => ( {
		name,
		...config,
	} ) ),
	register: ( store: { name: string } ) => mockRegister( store ),
	select: jest.fn(
		( storeName: string ) => mockRegisteredStores[ storeName ]
	),
} ) );

describe( 'registerWooPaymentsSettingsStore', () => {
	beforeEach( () => {
		jest.resetModules();
		mockRegister.mockClear();
		Object.keys( mockRegisteredStores ).forEach( ( storeName ) => {
			delete mockRegisteredStores[ storeName ];
		} );
	} );

	it( 'does not register the settings store when the store module is imported', async () => {
		await import( '../data/store' );

		expect( mockRegister ).not.toHaveBeenCalled();
	} );

	it( 'registers the settings store once and is idempotent', async () => {
		const { registerWooPaymentsSettingsStore, STORE_NAME } = await import(
			'../data/register'
		);

		expect( select( STORE_NAME ) ).toBeUndefined();

		registerWooPaymentsSettingsStore();
		registerWooPaymentsSettingsStore();

		expect( select( STORE_NAME ) ).toBeDefined();
		expect( mockRegister ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not clobber a pre-existing settings store with the same name', async () => {
		const { registerWooPaymentsSettingsStore, STORE_NAME } = await import(
			'../data/register'
		);
		mockRegisteredStores[ STORE_NAME ] = {
			getForeignMarker: () => 'plugin',
		};

		registerWooPaymentsSettingsStore();

		expect( mockRegister ).not.toHaveBeenCalled();
		expect(
			(
				select( STORE_NAME ) as {
					getForeignMarker: () => string;
				}
			 ).getForeignMarker()
		).toBe( 'plugin' );
	} );
} );
