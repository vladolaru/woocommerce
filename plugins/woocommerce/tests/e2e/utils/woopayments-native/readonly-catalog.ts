interface ReadonlyCatalogResponse {
	ok: () => boolean;
	status: () => number;
	json: () => Promise< unknown >;
	text: () => Promise< string >;
}

export interface ReadonlyCatalogApi {
	get: (
		url: string,
		options?: { headers?: Record< string, string > }
	) => Promise< ReadonlyCatalogResponse >;
	post: (
		url: string,
		options: {
			data: Record< string, unknown >;
			headers?: Record< string, string >;
		}
	) => Promise< ReadonlyCatalogResponse >;
}

export const READONLY_CATALOG_PRODUCTS = [
	{
		name: 'WooPayments guest-save smoke',
		slug: 'woopayments-guest-save-smoke',
		regular_price: '10.00',
	},
	{
		name: 'WooPayments declines smoke',
		slug: 'woopayments-declines-smoke',
		regular_price: '10.00',
	},
	{
		name: 'WooPayments MC family smoke',
		slug: 'woopayments-mc-family-smoke',
		regular_price: '10.00',
	},
] as const;

type CatalogProduct = ( typeof READONLY_CATALOG_PRODUCTS )[ number ];

function matchesProduct(
	value: Record< string, unknown >,
	expected: CatalogProduct
): boolean {
	return (
		value.name === expected.name &&
		value.slug === expected.slug &&
		value.regular_price === expected.regular_price &&
		value.status === 'publish' &&
		value.type === 'simple' &&
		value.virtual === true
	);
}

async function responseJson(
	response: ReadonlyCatalogResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

export async function ensureReadonlyCatalog(
	api: ReadonlyCatalogApi,
	nonce?: string
): Promise< void > {
	const headers = nonce ? { 'X-WP-Nonce': nonce } : undefined;
	for ( const product of READONLY_CATALOG_PRODUCTS ) {
		const lookup = await responseJson(
			await api.get(
				`/wp-json/wc/v3/products?slug=${ encodeURIComponent(
					product.slug
				) }&status=any`,
				{ headers }
			),
			`Readonly catalog lookup for ${ product.slug }`
		);
		if ( ! Array.isArray( lookup ) ) {
			throw new Error(
				`Readonly catalog lookup for ${ product.slug } returned a non-list response.`
			);
		}
		if ( lookup.length > 0 ) {
			if (
				lookup.length !== 1 ||
				typeof lookup[ 0 ] !== 'object' ||
				lookup[ 0 ] === null ||
				! matchesProduct(
					lookup[ 0 ] as Record< string, unknown >,
					product
				)
			) {
				throw new Error(
					`Readonly catalog product drift for occupied slug ${ product.slug }.`
				);
			}
			continue;
		}

		const payload = {
			...product,
			status: 'publish',
			type: 'simple',
			virtual: true,
		};
		const created = await responseJson(
			await api.post( '/wp-json/wc/v3/products', {
				data: payload,
				headers,
			} ),
			`Readonly catalog creation for ${ product.slug }`
		);
		if (
			typeof created !== 'object' ||
			created === null ||
			! Number.isSafeInteger(
				( created as Record< string, unknown > ).id
			) ||
			( created as Record< string, unknown > ).slug !== product.slug
		) {
			throw new Error(
				`Readonly catalog creation for ${ product.slug } returned the wrong identity.`
			);
		}
	}
}
