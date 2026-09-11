import { expect, test } from '@playwright/test';

import {
	ensureReadonlyCatalog,
	READONLY_CATALOG_PRODUCTS,
	type ReadonlyCatalogApi,
} from './readonly-catalog';

function response(
	value: unknown,
	ok = true
): Awaited< ReturnType< ReadonlyCatalogApi[ 'get' ] > > {
	return {
		ok: () => ok,
		status: () => ( ok ? 200 : 500 ),
		json: async () => value,
		text: async () => JSON.stringify( value ),
	};
}

test( 'fresh readonly catalog creates each exact stable product once', async () => {
	const creates: unknown[] = [];
	const api: ReadonlyCatalogApi = {
		get: async () => response( [] ),
		post: async ( _url, options ) => {
			creates.push( options.data );
			return response( {
				id: creates.length,
				slug: ( options.data as { slug: string } ).slug,
			} );
		},
	};

	await ensureReadonlyCatalog( api );

	expect( creates ).toEqual(
		READONLY_CATALOG_PRODUCTS.map( ( product ) => ( {
			...product,
			status: 'publish',
			type: 'simple',
			virtual: true,
		} ) )
	);
} );

test( 'matching readonly catalog is idempotent', async () => {
	let postCount = 0;
	const api: ReadonlyCatalogApi = {
		get: async ( url ) => {
			const slug = new URL( url, 'http://store.test' ).searchParams.get(
				'slug'
			);
			const product = READONLY_CATALOG_PRODUCTS.find(
				( candidate ) => candidate.slug === slug
			);
			return response( [
				{
					id: 1,
					...product,
					status: 'publish',
					type: 'simple',
					virtual: true,
				},
			] );
		},
		post: async () => {
			postCount += 1;
			return response( {} );
		},
	};

	await ensureReadonlyCatalog( api );
	expect( postCount ).toBe( 0 );
} );

test( 'readonly catalog fails closed on an occupied or drifted slug', async () => {
	const api: ReadonlyCatalogApi = {
		get: async () =>
			response( [
				{
					id: 41,
					name: 'Foreign product',
					slug: READONLY_CATALOG_PRODUCTS[ 0 ].slug,
					status: 'draft',
					type: 'simple',
					virtual: true,
					regular_price: '99.00',
				},
			] ),
		post: async () => response( {} ),
	};

	await expect( ensureReadonlyCatalog( api ) ).rejects.toThrow(
		/readonly catalog product drift/i
	);
} );
