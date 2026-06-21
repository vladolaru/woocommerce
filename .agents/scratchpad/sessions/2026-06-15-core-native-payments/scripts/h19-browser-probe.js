const fs = require( 'node:fs' );
const { chromium } = require( '@playwright/test' );

const sessionRoot =
	'/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments';
const outDir = `${ sessionRoot }/data`;
const scenario = process.env.H19_SCENARIO || 'card-only';
const targetBase = process.env.H19_TARGET_BASE || 'http://store8889.localhost:8889';
const productId = process.env.H19_PRODUCT_ID || '';
const orderPayUrl = process.env.H19_ORDER_PAY_URL || '';
const billingEmail = process.env.H19_BILLING_EMAIL || 'shopper@example.test';

fs.mkdirSync( outDir, { recursive: true } );

function isInterestingAsset( url ) {
	return /woopayments|woocommerce_payments|stripe|wc-payment-method/.test( url );
}

async function settle( page ) {
	await page.waitForLoadState( 'domcontentloaded', { timeout: 45000 } );
	await page.waitForLoadState( 'networkidle', { timeout: 10000 } ).catch( () => {} );
	await page.waitForTimeout( 2000 );
}

async function openCheckout( page ) {
	if ( ! productId ) {
		throw new Error( 'H19_PRODUCT_ID is required for checkout probe.' );
	}

	await page.goto( `${ targetBase }/?add-to-cart=${ productId }`, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await settle( page );
	await page.goto( `${ targetBase }/checkout/`, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await settle( page );
}

async function openOrderPay( page ) {
	if ( ! orderPayUrl ) {
		throw new Error( 'H19_ORDER_PAY_URL is required for order-pay probe.' );
	}

	await page.goto( orderPayUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await settle( page );

	const emailInput = page
		.locator( 'input[name="billing_email"], input[type="email"]' )
		.first();
	if ( await emailInput.count() ) {
		await emailInput.fill( billingEmail );
		await page.locator( 'button[type="submit"], input[type="submit"]' ).first().click();
		await settle( page );
	}
}

async function collectPage( browser, name, opener ) {
	const context = await browser.newContext( {
		viewport: { width: 1366, height: 900 },
	} );
	const page = await context.newPage();
	const consoleMessages = [];
	const pageErrors = [];
	const badResponses = [];

	page.on( 'console', ( message ) => {
		consoleMessages.push( {
			type: message.type(),
			text: message.text(),
			location: message.location(),
		} );
	} );
	page.on( 'pageerror', ( error ) => {
		pageErrors.push( error.message );
	} );
	page.on( 'response', ( response ) => {
		const status = response.status();
		if ( status >= 400 ) {
			badResponses.push( {
				status,
				url: response.url(),
				requestMethod: response.request().method(),
				resourceType: response.request().resourceType(),
			} );
		}
	} );

	await opener( page );

	const facts = await page.evaluate( () => {
		const scripts = Array.from( document.scripts )
			.map( ( script ) => script.src )
			.filter( Boolean );
		const styles = Array.from( document.querySelectorAll( 'link[rel~="stylesheet"]' ) )
			.map( ( link ) => link.href )
			.filter( Boolean );
		const blocksSettings =
			window.wc?.wcSettings?.getSetting?.( 'woocommerce_payments_data' ) || null;
		const classicParams = window.wcpayExpressCheckoutParams || null;
		const expressParams =
			classicParams || blocksSettings?.expressCheckoutParams || null;
		const resources = performance
			.getEntriesByType( 'resource' )
			.map( ( entry ) => ( {
				name: entry.name,
				initiatorType: entry.initiatorType,
				duration: Number( entry.duration.toFixed( 2 ) ),
				transferSize: entry.transferSize,
				encodedBodySize: entry.encodedBodySize,
			} ) )
			.filter( ( entry ) =>
				/woopayments|woocommerce_payments|stripe|wc-payment-method/.test(
					entry.name
				)
			);

		return {
			url: window.location.href,
			title: document.title,
			hasWooPaymentsGateway:
				Boolean( document.querySelector( 'input[value="woocommerce_payments"]' ) ) ||
				document.body.textContent.includes( 'WooPayments' ) ||
				document.body.textContent.includes( 'Card' ),
			hasClassicExpressElement: Boolean(
				document.querySelector( '#wcpay-express-checkout-element' )
			),
			hasBlocksExpressParams: Boolean( blocksSettings?.expressCheckoutParams ),
			hasClassicExpressParams: Boolean( classicParams ),
			expressParams,
			woopaymentsScripts: scripts.filter( ( src ) =>
				/woopayments|woocommerce_payments|wc-payment-method/.test( src )
			),
			woopaymentsStyles: styles.filter( ( href ) =>
				/woopayments|woocommerce_payments|wc-payment-method/.test( href )
			),
			resourceSummaries: resources,
		};
	} );

	const screenshotPath = `${ outDir }/h19-${ scenario }-${ name }.png`;
	await page.screenshot( { path: screenshotPath, fullPage: true } );
	await context.close();

	return {
		name,
		scenario,
		observedAt: new Date().toISOString(),
		screenshotPath,
		consoleWarningsAndErrors: consoleMessages.filter( ( message ) =>
			[ 'warning', 'warn', 'error' ].includes( message.type )
		),
		pageErrors,
		badResponses: badResponses.filter( ( response ) =>
			isInterestingAsset( response.url ) || response.url.includes( targetBase )
		),
		...facts,
	};
}

( async () => {
	const browser = await chromium.launch( { headless: true } );
	const results = [];

	try {
		results.push( await collectPage( browser, 'blocks-checkout', openCheckout ) );
		results.push( await collectPage( browser, 'classic-order-pay', openOrderPay ) );
	} finally {
		await browser.close();
	}

	const outPath = `${ outDir }/h19-${ scenario }-browser-evidence.json`;
	fs.writeFileSync( outPath, JSON.stringify( results, null, 2 ) );
	console.log( JSON.stringify( { outPath, results }, null, 2 ) );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
