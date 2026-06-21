const fs = require( 'node:fs' );

const sessionRoot = '/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments';
const outDir = `${ sessionRoot }/data`;

fs.mkdirSync( outDir, { recursive: true } );

const pages = [
	{
		name: 'target-classic-checkout',
		url: 'http://store8889.localhost:8889/codex-classic-checkout/',
	},
	{
		name: 'target-blocks-checkout',
		url: 'http://store8889.localhost:8889/checkout/',
	},
	{
		name: 'reference-blocks-checkout',
		url: 'http://localhost:8082/checkout/',
	},
];

async function collectPage( pageConfig ) {
	const messages = [];
	const page = await context.newPage();

	page.removeAllListeners();
	page.on( 'console', ( message ) => {
		messages.push( {
			type: message.type(),
			text: message.text(),
			location: message.location(),
		} );
	} );
	page.on( 'pageerror', ( error ) => {
		messages.push( {
			type: 'pageerror',
			text: error.message,
		} );
	} );

	await page.goto( pageConfig.url, {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await page.waitForLoadState( 'networkidle', { timeout: 10000 } ).catch( () => {} );
	await page.waitForTimeout( 2000 );

	const capturedMessages = [ ...messages ];
	page.removeAllListeners();

	const details = await page.evaluate( () => {
		const scripts = Array.from( document.scripts )
			.map( ( script ) => script.src )
			.filter( Boolean );
		const styles = Array.from( document.querySelectorAll( 'link[rel~="stylesheet"]' ) )
			.map( ( link ) => link.href )
			.filter( Boolean );
		const resources = performance
			.getEntriesByType( 'resource' )
			.map( ( entry ) => ( {
				name: entry.name,
				initiatorType: entry.initiatorType,
				duration: Number( entry.duration.toFixed( 2 ) ),
				transferSize: entry.transferSize,
				encodedBodySize: entry.encodedBodySize,
			} ) )
			.filter( ( entry ) => /woopayments|woocommerce_payments|stripe|wc-payment-method/.test( entry.name ) );
		const navigation = performance.getEntriesByType( 'navigation' )[ 0 ];
		let blocksSettings = null;

		try {
			blocksSettings = window.wc?.wcSettings?.getSetting?.( 'woocommerce_payments_data' ) || null;
		} catch ( error ) {
			blocksSettings = { error: error.message };
		}

		return {
			url: window.location.href,
			title: document.title,
			hasClassicExpressParams: typeof window.wcpayExpressCheckoutParams !== 'undefined',
			hasClassicExpressElement: Boolean( document.querySelector( '#wcpay-express-checkout-element' ) ),
			hasClassicWooPayElement: Boolean( document.querySelector( '#wcpay-woopay-button' ) ),
			hasBlocksExpressParams: Boolean( blocksSettings?.expressCheckoutParams ),
			blocksSettings,
			scripts: scripts.filter( ( src ) => /woopayments|woocommerce_payments|stripe|wc-payment-method/.test( src ) ),
			styles: styles.filter( ( href ) => /woopayments|woocommerce_payments|wc-payment-method/.test( href ) ),
			classicWooPaymentsScripts: scripts.filter( ( src ) => /assets\/js\/frontend\/woopayments/.test( src ) ),
			classicWooPaymentsStyles: styles.filter( ( href ) => /assets\/css\/woopayments/.test( href ) ),
			blocksWooPaymentsScripts: scripts.filter( ( src ) => /assets\/client\/blocks\/wc-payment-method-woopayments/.test( src ) ),
			blocksWooPaymentsStyles: styles.filter( ( href ) => /assets\/client\/blocks\/wc-payment-method-woopayments/.test( href ) ),
			resourceSummaries: resources,
			navigationTiming: navigation
				? {
						domContentLoadedEventEnd: Number( navigation.domContentLoadedEventEnd.toFixed( 2 ) ),
						loadEventEnd: Number( navigation.loadEventEnd.toFixed( 2 ) ),
						duration: Number( navigation.duration.toFixed( 2 ) ),
						transferSize: navigation.transferSize,
						encodedBodySize: navigation.encodedBodySize,
				  }
				: null,
		};
	} );

	await page.close();

	return {
		name: pageConfig.name,
		configuredUrl: pageConfig.url,
		observedAt: new Date().toISOString(),
		consoleMessages: capturedMessages,
		...details,
	};
}

const results = [];
for ( const pageConfig of pages ) {
	const result = await collectPage( pageConfig );
	results.push( result );
	fs.writeFileSync(
		`${ outDir }/h18-${ pageConfig.name }-final-browser-evidence.json`,
		JSON.stringify( result, null, 2 )
	);
}

const summary = results.map( ( result ) => ( {
	name: result.name,
	configuredUrl: result.configuredUrl,
	observedUrl: result.url,
	hasClassicExpressParams: result.hasClassicExpressParams,
	hasClassicExpressElement: result.hasClassicExpressElement,
	hasClassicWooPayElement: result.hasClassicWooPayElement,
	hasBlocksExpressParams: result.hasBlocksExpressParams,
	classicWooPaymentsScripts: result.classicWooPaymentsScripts,
	classicWooPaymentsStyles: result.classicWooPaymentsStyles,
	blocksWooPaymentsScripts: result.blocksWooPaymentsScripts,
	blocksWooPaymentsStyles: result.blocksWooPaymentsStyles,
	consoleWarningsAndErrors: result.consoleMessages.filter( ( message ) =>
		[ 'warning', 'warn', 'error', 'pageerror' ].includes( message.type )
	),
	navigationTiming: result.navigationTiming,
} ) );

fs.writeFileSync(
	`${ outDir }/h18-final-browser-evidence-summary.json`,
	JSON.stringify( summary, null, 2 )
);

summary;
