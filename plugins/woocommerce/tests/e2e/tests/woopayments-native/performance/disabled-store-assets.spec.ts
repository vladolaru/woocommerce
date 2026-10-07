import type { APIResponse } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';
import { wpCLI } from '../../../utils/cli';

test.use( { storageState: ADMIN_STATE_PATH } );

// A store that has not set native payments up loads nothing of it (owner
// dormancy rule, N-299). The tier is the stored state option, so this describe
// block sets it to `disabled` and restores whatever the store had before.
const NATIVE_PAYMENTS_STATE_OPTION = 'woocommerce_native_payments_state';
const NATIVE_PAYMENTS_STATE_ABSENT_MARKER =
	'__woopayments_native_e2e_native_payments_state_absent__';
const STORE_PRODUCTS_API = '/wp-json/wc/store/v1/products?per_page=1';

// A native script or style is recognized by its handle (the tag id WordPress
// prints, `-js-extra`/`-js-after` inline data included) or by its URL, so a
// handle renamed to something neutral is still caught by the file it loads.
const NATIVE_ASSET_PATTERN =
	/woopayments|wcpay|woocommerce-payments|fingerprintjs|js\.stripe\.com/i;

const SHOP_PATHS = [ '/', '/shop/', '/cart/', '/checkout/' ];
const ADMIN_PATHS = [
	'/wp-admin/',
	'/wp-admin/admin.php?page=wc-admin',
	'/wp-admin/admin.php?page=wc-settings&tab=checkout',
	'/wp-admin/edit.php?post_type=shop_order',
	'/wp-admin/edit.php?post_type=product',
];

/**
 * The id, src and href of every script, style and asset-loading link tag whose
 * value names a native asset. Other links (canonical, alternate, oEmbed) carry
 * page URLs, and a product slug may legitimately contain the word.
 */
function nativeAssetReferences( html: string ): string[] {
	const references = new Set< string >();
	for ( const [ tag ] of html.matchAll(
		/<(?:script|link|style)\b[^>]*>/gi
	) ) {
		if (
			/^<link\b/i.test( tag ) &&
			! /\brel\s*=\s*["']?(?:stylesheet|preload|modulepreload)\b/i.test(
				tag
			)
		) {
			continue;
		}
		for ( const [ , value ] of tag.matchAll(
			/\b(?:id|src|href)\s*=\s*["']([^"']*)["']/gi
		) ) {
			if ( NATIVE_ASSET_PATTERN.test( value ) ) {
				references.add( value );
			}
		}
	}
	return [ ...references ];
}

async function readHtml(
	response: APIResponse,
	path: string
): Promise< string > {
	expect( response.status(), `${ path } status` ).toBe( 200 );
	return await response.text();
}

// Positive control: the empty results below prove nothing unless the extractor finds native tags in markup shaped as
// WordPress prints it (wp_print_script_tag(), wp_print_inline_script_tag() and style_loader_tag ids).
test(
	'the native asset extractor finds script, inline data and stylesheet tags',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	() => {
		const sample = [
			'<link rel="canonical" href="https://example.test/product/woopayments-hoodie/" />',
			"<link rel='stylesheet' id='wc-payment-method-woopayments-css' href='https://example.test/wp-content/plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.css?ver=1' media='all' />",
			'<script id="wc-payment-method-woopayments-js-extra">var x = 1;</script>',
			'<script src="https://js.stripe.com/v3/" id="stripe-js"></script>',
			'<script src="https://example.test/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>',
		].join( '\n' );

		expect( nativeAssetReferences( sample ) ).toEqual( [
			'wc-payment-method-woopayments-css',
			'https://example.test/wp-content/plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.css?ver=1',
			'wc-payment-method-woopayments-js-extra',
			'https://js.stripe.com/v3/',
		] );
	}
);

test.describe( 'Native payments disabled store assets', () => {
	let initialNativePaymentsState: string;

	test.beforeAll( async () => {
		initialNativePaymentsState = (
			await wpCLI( [
				'wp',
				'eval',
				`echo get_option( '${ NATIVE_PAYMENTS_STATE_OPTION }', '${ NATIVE_PAYMENTS_STATE_ABSENT_MARKER }' );`,
			] )
		).stdout.trim();
		await wpCLI( [
			'wp',
			'option',
			'update',
			NATIVE_PAYMENTS_STATE_OPTION,
			'disabled',
		] );
	} );

	test.afterAll( async () => {
		if (
			initialNativePaymentsState === NATIVE_PAYMENTS_STATE_ABSENT_MARKER
		) {
			await wpCLI( [
				'wp',
				'option',
				'delete',
				NATIVE_PAYMENTS_STATE_OPTION,
			] );
		} else {
			await wpCLI( [
				'wp',
				'option',
				'update',
				NATIVE_PAYMENTS_STATE_OPTION,
				initialNativePaymentsState,
			] );
		}
	} );

	test(
		'shop pages of a store without native payments set up carry no native script or style',
		{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
		async ( { playwright, baseURL } ) => {
			// A guest shopper: no admin session on these requests.
			const guest = await playwright.request.newContext( {
				baseURL,
				storageState: { cookies: [], origins: [] },
			} );
			try {
				const products = await guest.get( STORE_PRODUCTS_API );
				expect( products.ok() ).toBe( true );
				const [ product ] = ( await products.json() ) as Array< {
					permalink: string;
				} >;
				expect( product?.permalink ).toBeTruthy();

				for ( const path of [
					...SHOP_PATHS,
					new URL( product.permalink ).pathname,
				] ) {
					const html = await readHtml(
						await guest.get( path ),
						path
					);
					expect( nativeAssetReferences( html ), path ).toEqual( [] );
				}
			} finally {
				await guest.dispose();
			}
		}
	);

	test(
		'admin pages of a store without native payments set up carry no native script or style',
		{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
		async ( { page } ) => {
			for ( const path of ADMIN_PATHS ) {
				const response = await page.request.get( path );
				const html = await readHtml( response, path );
				// The admin session must hold, or a login form passes vacuously.
				expect( response.url(), path ).not.toContain( 'wp-login.php' );
				expect( html, path ).toMatch( /<body[^>]*\bwp-admin\b/ );
				expect( nativeAssetReferences( html ), path ).toEqual( [] );
			}
		}
	);
} );
