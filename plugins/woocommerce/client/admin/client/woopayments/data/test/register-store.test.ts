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

type StoreModule = {
	store: Parameters<
		typeof import( '../register-store' ).registerStoreUnlessTaken
	>[ 0 ];
	STORE_NAME: string;
};

describe.each( [
	[
		'settings',
		() =>
			import( '../../settings/data/store' ) as unknown as Promise< StoreModule >,
	],
	[
		'promotions',
		() =>
			import( '../../promotions/data/store' ) as unknown as Promise< StoreModule >,
	],
] )(
	'registerStoreUnlessTaken with the %s store',
	( storeLabel, loadStore ) => {
		beforeEach( () => {
			jest.resetModules();
			mockRegister.mockClear();
			Object.keys( mockRegisteredStores ).forEach( ( storeName ) => {
				delete mockRegisteredStores[ storeName ];
			} );
		} );

		it( `does not register the ${ storeLabel } store when the store module is imported`, async () => {
			await loadStore();

			expect( mockRegister ).not.toHaveBeenCalled();
		} );

		it( `registers the ${ storeLabel } store once and is idempotent`, async () => {
			const { registerStoreUnlessTaken } =
				await import( '../register-store' );
			const { store, STORE_NAME } = await loadStore();

			expect( select( STORE_NAME ) ).toBeUndefined();

			registerStoreUnlessTaken( store );
			registerStoreUnlessTaken( store );

			expect( select( STORE_NAME ) ).toBeDefined();
			expect( mockRegister ).toHaveBeenCalledTimes( 1 );
		} );

		it( `does not clobber a pre-existing ${ storeLabel } store with the same name`, async () => {
			const { registerStoreUnlessTaken } =
				await import( '../register-store' );
			const { store, STORE_NAME } = await loadStore();
			mockRegisteredStores[ STORE_NAME ] = {
				getForeignMarker: () => 'plugin',
			};

			registerStoreUnlessTaken( store );

			expect( mockRegister ).not.toHaveBeenCalled();
			expect(
				(
					select( STORE_NAME ) as {
						getForeignMarker: () => string;
					}
				 ).getForeignMarker()
			).toBe( 'plugin' );
		} );
	}
);
