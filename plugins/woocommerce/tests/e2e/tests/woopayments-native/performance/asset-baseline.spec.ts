/**
 * Records the payments asset baseline of one store (N-299 Task 6.7): per page, the native script and
 * style tags the document prints, every payments script and style the page loads (lazy admin chunks
 * included) with transferred, decoded and gzip bytes, and FCP, LCP and CLS. Run it once against the
 * native store and once against a WooPayments 11.1.0 store, then compare the attached JSON files:
 *
 *   BASE_URL=http://localhost:8097 WCPAY_RUNTIME=client pnpm exec playwright test \
 *     --config=tests/e2e/envs/woopayments-native/playwright.config.ts \
 *     --project=woopayments-native-readonly \
 *     tests/e2e/tests/woopayments-native/performance/asset-baseline.spec.ts
 *
 * Optional: ASSET_BASELINE_RUNTIME (native|client admin routes, default WCPAY_RUNTIME), ASSET_BASELINE_OUTPUT (also write the JSON there, with a -shopper/-admin suffix),
 * ASSET_BASELINE_TRIALS (loads per page, default 3: one cold, the rest warm reloads),
 * ASSET_BASELINE_CART_PATH and ASSET_BASELINE_CHECKOUT_PATH.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { gzipSync } from 'node:zlib';

import type {
	Browser,
	BrowserContext,
	Page,
	Response,
	TestInfo,
} from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

// Kept identical to NATIVE_ASSET_PATTERN in perf-compare.sh, so both measurements count the same files.
const NATIVE_ASSET_PATTERN =
	/woopayments|wcpay|woocommerce-payments|js\.stripe\.com/i;

type Runtime = 'native' | 'client';

// ASSET_BASELINE_RUNTIME picks the admin routes when it differs from WCPAY_RUNTIME: with
// WCPAY_RUNTIME=client the readonly global setup only logs in, so a native store that must not be
// seeded is measured with ASSET_BASELINE_RUNTIME=native.
const runtime = ( process.env.ASSET_BASELINE_RUNTIME ??
	process.env.WCPAY_RUNTIME ) as Runtime;
const trials = Math.max( 1, Number( process.env.ASSET_BASELINE_TRIALS ) || 3 );
const output = process.env.ASSET_BASELINE_OUTPUT ?? '';
const cartPath = process.env.ASSET_BASELINE_CART_PATH ?? '/cart/';
const checkoutPath = process.env.ASSET_BASELINE_CHECKOUT_PATH ?? '/checkout/';

const NAVIGATION_TIMEOUT_MS = 60_000;
const SETTLE_TIMEOUT_MS = 20_000;
const GUEST_STATE = { cookies: [], origins: [] };

function nativeRoute( path: string ): string {
	return `/wp-admin/admin.php?page=wc-settings&tab=checkout&path=${ path }`;
}

function clientRoute( path: string ): string {
	return `/wp-admin/admin.php?page=wc-admin&path=${ encodeURIComponent(
		path
	) }`;
}

// The seven admin surfaces at each runtime's own URL: native under the Payments settings page, the
// client under wc-admin.
const ADMIN_ROUTES: Record<
	Runtime,
	Record< string, ( paymentId: string ) => string >
> = {
	native: {
		overview: () => nativeRoute( '/woopayments/overview' ),
		settings: () => nativeRoute( '/woopayments/settings' ),
		transactions: () => nativeRoute( '/woopayments/transactions' ),
		payouts: () => nativeRoute( '/woopayments/payouts' ),
		disputes: () => nativeRoute( '/woopayments/disputes' ),
		documents: () => nativeRoute( '/woopayments/documents' ),
		payment_details: ( id ) =>
			`${ nativeRoute(
				'/woopayments/transactions/details'
			) }&id=${ encodeURIComponent( id ) }`,
	},
	client: {
		overview: () => clientRoute( '/payments/overview' ),
		settings: () =>
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments',
		transactions: () => clientRoute( '/payments/transactions' ),
		payouts: () => clientRoute( '/payments/payouts' ),
		disputes: () => clientRoute( '/payments/disputes' ),
		documents: () => clientRoute( '/payments/documents' ),
		payment_details: ( id ) =>
			`${ clientRoute(
				'/payments/transactions/details'
			) }&id=${ encodeURIComponent( id ) }`,
	},
};

interface DocumentTag {
	tag: string;
	id: string;
	url: string;
}

interface AssetFile {
	url: string;
	type: string;
	transferred: number;
	decoded: number;
	gzip: number;
}

interface AssetTotals {
	files: number;
	transferred: number;
	decoded: number;
	gzip: number;
}

interface PaintMetrics {
	ttfb: number;
	fcp: number | null;
	lcp: number | null;
	cls: number;
	domContentLoaded: number;
	load: number;
}

interface PageRecord {
	page: string;
	path: string;
	skipped?: string;
	error?: string;
	status?: number;
	finalUrl?: string;
	settled?: boolean;
	documentTags?: DocumentTag[];
	nativeFiles?: AssetFile[];
	nativeTotals?: AssetTotals;
	allScriptsAndStyles?: AssetTotals;
	coldLoad?: PaintMetrics;
	warmMedian?: PaintMetrics;
}

/**
 * Resolves a store path against the base URL, keeping a subdirectory install's prefix. Absolute URLs,
 * such as a product permalink, pass through.
 */
function storeUrl( baseURL: string, path: string ): string {
	return new URL( path.replace( /^\/+/, '' ), baseURL ).toString();
}

/**
 * The script, style and asset-loading link tags the document prints whose id or URL names a native
 * asset. Other links (canonical, alternate) carry page URLs, which may contain the word through a slug.
 */
function nativeDocumentTags( html: string ): DocumentTag[] {
	const found: DocumentTag[] = [];
	for ( const [ element, name, attributes ] of html.matchAll(
		/<(script|style|link)\b([^>]*)>/gi
	) ) {
		const values: Record< string, string > = {};
		for ( const [ , key, , value ] of attributes.matchAll(
			/\b(id|src|href|rel)\s*=\s*(["'])(.*?)\2/gi
		) ) {
			values[ key.toLowerCase() ] = value.replace(
				/&#0?38;|&amp;/g,
				'&'
			);
		}
		if (
			/^<link\b/i.test( element ) &&
			! /\b(stylesheet|preload|modulepreload)\b/i.test( values.rel ?? '' )
		) {
			continue;
		}
		const tag = name.toLowerCase();
		const url = ( tag === 'script' ? values.src : values.href ) ?? '';
		const id = values.id ?? '';
		if (
			NATIVE_ASSET_PATTERN.test( id ) ||
			NATIVE_ASSET_PATTERN.test( url )
		) {
			found.push( { tag, id, url } );
		}
	}
	return found;
}

/**
 * Paint and layout metrics of the current document, in milliseconds from navigation start, read from
 * buffered performance entries once the page has settled.
 */
async function readPaintMetrics( page: Page ): Promise< PaintMetrics > {
	return await page.evaluate( async () => {
		const buffered = ( type: string ) =>
			new Promise< PerformanceEntry[] >( ( resolve ) => {
				const entries: PerformanceEntry[] = [];
				try {
					const observer = new PerformanceObserver( ( list ) =>
						entries.push( ...list.getEntries() )
					);
					observer.observe( { type, buffered: true } );
					setTimeout( () => {
						observer.disconnect();
						resolve( entries );
					}, 100 );
				} catch {
					resolve( entries );
				}
			} );
		const navigation = performance.getEntriesByType(
			'navigation'
		)[ 0 ] as PerformanceNavigationTiming;
		const fcp = performance
			.getEntriesByType( 'paint' )
			.find( ( entry ) => entry.name === 'first-contentful-paint' );
		const lcp = ( await buffered( 'largest-contentful-paint' ) ).at( -1 );
		const cls = ( await buffered( 'layout-shift' ) ).reduce(
			( sum, entry ) => {
				const shift = entry as PerformanceEntry & {
					value: number;
					hadRecentInput: boolean;
				};
				return shift.hadRecentInput ? sum : sum + shift.value;
			},
			0
		);
		return {
			ttfb: navigation.responseStart - navigation.startTime,
			fcp: fcp ? fcp.startTime : null,
			lcp: lcp ? lcp.startTime : null,
			cls,
			domContentLoaded: navigation.domContentLoadedEventEnd,
			load: navigation.loadEventEnd,
		};
	} );
}

function median( values: number[] ): number {
	const sorted = [ ...values ].sort( ( a, b ) => a - b );
	return sorted[ Math.floor( sorted.length / 2 ) ];
}

function medianMetrics( samples: PaintMetrics[] ): PaintMetrics {
	const pick = ( key: keyof PaintMetrics ) =>
		samples
			.map( ( sample ) => sample[ key ] )
			.filter( ( value ): value is number => value !== null );
	const nullableMedian = ( key: keyof PaintMetrics ) => {
		const values = pick( key );
		return values.length ? median( values ) : null;
	};
	return {
		ttfb: median( pick( 'ttfb' ) ),
		fcp: nullableMedian( 'fcp' ),
		lcp: nullableMedian( 'lcp' ),
		cls: median( pick( 'cls' ) ),
		domContentLoaded: median( pick( 'domContentLoaded' ) ),
		load: median( pick( 'load' ) ),
	};
}

function totals( files: AssetFile[] ): AssetTotals {
	return {
		files: files.length,
		transferred: files.reduce( ( sum, file ) => sum + file.transferred, 0 ),
		decoded: files.reduce( ( sum, file ) => sum + file.decoded, 0 ),
		gzip: files.reduce( ( sum, file ) => sum + file.gzip, 0 ),
	};
}

/**
 * Waits until the page has had no network request for 500 ms, so lazy admin chunks are loaded, but at
 * most 20 seconds: a page that keeps polling is measured as it stands and recorded as not settled.
 */
async function settle( page: Page ): Promise< boolean > {
	try {
		// eslint-disable-next-line playwright/no-networkidle -- The measurement needs the lazy chunks a page fetches after load; a page that keeps polling is recorded as not settled.
		await page.waitForLoadState( 'networkidle', {
			timeout: SETTLE_TIMEOUT_MS,
		} );
		return true;
	} catch {
		return false;
	}
}

/**
 * Every unique script and style response of the cold load, with the body size the browser transferred,
 * the decoded size and the gzip-9 size of the decoded body.
 */
async function assetFiles( responses: Response[] ): Promise< AssetFile[] > {
	const files: AssetFile[] = [];
	const seen = new Set< string >();
	for ( const response of responses ) {
		const url = response.url();
		if ( seen.has( url ) || response.status() >= 300 ) {
			continue;
		}
		seen.add( url );
		const body = await response.body().catch( () => null );
		if ( ! body ) {
			continue;
		}
		const sizes = await response.request().sizes();
		files.push( {
			url,
			type: response.request().resourceType(),
			transferred: sizes.responseBodySize,
			decoded: body.length,
			gzip: gzipSync( body, { level: 9 } ).length,
		} );
	}
	return files;
}

/**
 * Loads one page cold in a context of its own, recording every script and style response, then reloads
 * it for the warm trials.
 */
async function measurePage(
	context: BrowserContext,
	baseURL: string,
	name: string,
	path: string
): Promise< PageRecord > {
	const page = await context.newPage();
	const responses: Response[] = [];
	const onResponse = ( response: Response ) => {
		const type = response.request().resourceType();
		if ( type === 'script' || type === 'stylesheet' ) {
			responses.push( response );
		}
	};
	page.on( 'response', onResponse );
	try {
		const navigation = await page.goto( storeUrl( baseURL, path ), {
			waitUntil: 'load',
			timeout: NAVIGATION_TIMEOUT_MS,
		} );
		const settled = await settle( page );
		page.off( 'response', onResponse );
		const status = navigation?.status();
		const finalUrl = page.url();
		if ( ! navigation || navigation.status() >= 400 ) {
			return { page: name, path, status, finalUrl, settled };
		}
		const coldLoad = await readPaintMetrics( page );
		const documentTags = nativeDocumentTags( await navigation.text() );
		const files = await assetFiles( responses );
		const nativeFiles = files.filter( ( file ) =>
			NATIVE_ASSET_PATTERN.test( file.url )
		);

		const warm: PaintMetrics[] = [];
		for ( let trial = 1; trial < trials; trial++ ) {
			await page.reload( {
				waitUntil: 'load',
				timeout: NAVIGATION_TIMEOUT_MS,
			} );
			await settle( page );
			warm.push( await readPaintMetrics( page ) );
		}

		return {
			page: name,
			path,
			status,
			finalUrl,
			settled,
			documentTags,
			nativeFiles,
			nativeTotals: totals( nativeFiles ),
			allScriptsAndStyles: totals( files ),
			coldLoad,
			warmMedian: warm.length ? medianMetrics( warm ) : undefined,
		};
	} catch ( error ) {
		return { page: name, path, error: String( error ) };
	} finally {
		page.off( 'response', onResponse );
	}
}

/**
 * Shopper pages as a guest, each in a fresh context so every cold load starts with an empty cache.
 * Cart and checkout carry one in-stock simple product.
 */
async function measureShopperPages(
	browser: Browser,
	baseURL: string
): Promise< PageRecord[] > {
	const lookup = await browser.newContext( {
		baseURL,
		storageState: GUEST_STATE,
	} );
	const products = ( await (
		await lookup.request.get(
			storeUrl(
				baseURL,
				'/wp-json/wc/store/v1/products?per_page=1&type=simple&stock_status=instock'
			)
		)
	)
		.json()
		.catch( () => [] ) ) as Array< { id: number; permalink: string } >;
	await lookup.close();
	const product = products[ 0 ];

	// Name, path, and whether the page needs the product.
	const pages: Array< [ string, string, boolean ] > = [
		[ 'front', '/', false ],
		[ 'shop', '/?post_type=product', false ],
		[ 'product', product?.permalink ?? '', true ],
		[ 'cart', cartPath, true ],
		[ 'checkout', checkoutPath, true ],
	];
	const records: PageRecord[] = [];
	for ( const [ name, path, needsProduct ] of pages ) {
		if ( needsProduct && ! product ) {
			records.push( {
				page: name,
				path,
				error: 'no in-stock simple product in the Store API',
			} );
			continue;
		}
		const context = await browser.newContext( {
			baseURL,
			storageState: GUEST_STATE,
		} );
		try {
			if ( name === 'cart' || name === 'checkout' ) {
				await context.request.get(
					storeUrl(
						baseURL,
						`/?add-to-cart=${ product.id }&quantity=1`
					)
				);
			}
			records.push( await measurePage( context, baseURL, name, path ) );
		} finally {
			await context.close();
		}
	}
	return records;
}

/**
 * The id of the latest transaction, whose details page is one of the measured admin routes.
 */
async function latestPaymentId(
	browser: Browser,
	baseURL: string
): Promise< string > {
	const context = await browser.newContext( {
		baseURL,
		storageState: ADMIN_STATE_PATH,
	} );
	try {
		const nonce = await (
			await context.request.get(
				storeUrl(
					baseURL,
					'/wp-admin/admin-ajax.php?action=rest-nonce'
				)
			)
		).text();
		const transactions = await (
			await context.request.get(
				storeUrl(
					baseURL,
					'/wp-json/wc/v3/payments/transactions?per_page=1'
				),
				{ headers: { 'X-WP-Nonce': nonce } }
			)
		)
			.json()
			.catch( () => null );
		const latest = transactions?.data?.[ 0 ] ?? {};
		return latest.payment_intent_id || latest.charge_id || '';
	} finally {
		await context.close();
	}
}

/**
 * The runtime's seven admin routes, each in a fresh context under the saved admin session.
 */
async function measureAdminPages(
	browser: Browser,
	baseURL: string
): Promise< PageRecord[] > {
	const paymentId = await latestPaymentId( browser, baseURL );
	const records: PageRecord[] = [];
	for ( const [ name, route ] of Object.entries( ADMIN_ROUTES[ runtime ] ) ) {
		if ( name === 'payment_details' && ! paymentId ) {
			records.push( {
				page: name,
				path: '',
				skipped: 'no transaction to open',
			} );
			continue;
		}
		const context = await browser.newContext( {
			baseURL,
			storageState: ADMIN_STATE_PATH,
		} );
		try {
			records.push(
				await measurePage( context, baseURL, name, route( paymentId ) )
			);
		} finally {
			await context.close();
		}
	}
	return records;
}

/**
 * Attaches the records to the test and, when ASSET_BASELINE_OUTPUT is set, writes them next to it with
 * the group inserted before `.json`.
 */
async function publish(
	testInfo: TestInfo,
	group: string,
	baseURL: string,
	records: PageRecord[]
): Promise< void > {
	const body =
		JSON.stringify(
			{
				baseUrl: baseURL,
				runtime,
				trials,
				measuredAt: new Date().toISOString(),
				records,
			},
			null,
			2
		) + '\n';
	await testInfo.attach( 'asset-baseline.json', {
		body,
		contentType: 'application/json',
	} );
	if ( output ) {
		const file = output.replace( /(\.json)?$/i, `-${ group }.json` );
		mkdirSync( dirname( file ), { recursive: true } );
		writeFileSync( file, body );
	}
}

/**
 * Soft checks that every measured page loaded and was not bounced to the login form, so a broken
 * session or route cannot pass as a page with no payments assets.
 */
function expectPagesLoaded( records: PageRecord[] ): void {
	for ( const record of records.filter( ( item ) => ! item.skipped ) ) {
		expect.soft( record.error, `${ record.page } error` ).toBeUndefined();
		expect
			.soft( record.status, `${ record.page } status` )
			.toBeLessThan( 400 );
		expect
			.soft( record.finalUrl, `${ record.page } final URL` )
			.not.toContain( 'wp-login.php' );
	}
}

function nativeFileCount( records: PageRecord[], name: string ): number {
	return (
		records.find( ( record ) => record.page === name )?.nativeTotals
			?.files ?? 0
	);
}

test.describe( 'Payments asset baseline', () => {
	test.setTimeout( 20 * 60_000 );

	test(
		'records the payments asset footprint and web vitals of the shopper pages',
		{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
		async ( { browser, baseURL }, testInfo ) => {
			const records = await measureShopperPages(
				browser,
				baseURL as string
			);
			await publish( testInfo, 'shopper', baseURL as string, records );

			expectPagesLoaded( records );
			expect(
				nativeFileCount( records, 'checkout' ),
				'native scripts and styles loaded on checkout'
			).toBeGreaterThan( 0 );
		}
	);

	test(
		'records the payments asset footprint and web vitals of the admin pages',
		{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
		async ( { browser, baseURL }, testInfo ) => {
			const records = await measureAdminPages(
				browser,
				baseURL as string
			);
			await publish( testInfo, 'admin', baseURL as string, records );

			expectPagesLoaded( records );
			expect(
				nativeFileCount( records, 'overview' ),
				'native scripts and styles loaded on the overview'
			).toBeGreaterThan( 0 );
		}
	);
} );
