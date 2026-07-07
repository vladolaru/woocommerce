// Wave 1 WooPayments local payment-method checkout driver.
//
// This Playwriter script is intentionally fail-closed while the submit path is
// being built. It navigates to the local checkout, captures browser evidence,
// and writes an incomplete result instead of pretending an order was submitted.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const env = typeof process !== 'undefined' && process.env ? process.env : {};

function requiredEnv( name ) {
	const value = env[ name ];
	if ( ! value ) {
		throw new Error( `Missing required environment variable ${ name }` );
	}
	return value;
}

const role = requiredEnv( 'LPM_GATE_ROLE' );
const method = requiredEnv( 'LPM_GATE_METHOD' );
const surface = requiredEnv( 'LPM_GATE_SURFACE' );
const baseUrl = requiredEnv( 'LPM_GATE_BASE_URL' ).replace( /\/+$/, '' );
const currency = requiredEnv( 'LPM_GATE_CURRENCY' );
const country = requiredEnv( 'LPM_GATE_COUNTRY' );
const gatewayId = requiredEnv( 'LPM_GATE_GATEWAY_ID' );
const stripePaymentMethodType = requiredEnv( 'LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE' );
const methodFamily = requiredEnv( 'LPM_GATE_METHOD_FAMILY' );
const evidencePath = requiredEnv( 'LPM_GATE_EVIDENCE_PATH' );
const startedAt = new Date().toISOString();
const checkoutUrl = `${ baseUrl }/checkout/?lpm-gate-method=${ encodeURIComponent( method ) }&lpm-gate-surface=${ encodeURIComponent( surface ) }`;

function assertLocalBase( value ) {
	const parsed = new URL( value );
	const host = parsed.hostname;
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		throw new Error( `Checkout base must be http(s), got ${ value }` );
	}
	if ( host !== 'localhost' && host !== '127.0.0.1' && ! host.endsWith( '.localhost' ) ) {
		throw new Error( `Checkout base must stay local, got ${ value }` );
	}
}

function ensureDir( dir ) {
	fs.mkdirSync( dir, { recursive: true } );
}

function safeError( error ) {
	return {
		message: error?.message || String( error ),
		stack: error?.stack || '',
	};
}

function writeEvidence( payload ) {
	ensureDir( path.dirname( evidencePath ) );
	fs.writeFileSync(
		evidencePath,
		`${ JSON.stringify(
			{
				schema: 'woopayments_lpm_checkout_browser_evidence.v1',
				status: 'incomplete',
				role,
				method,
				surface,
				base_url: baseUrl,
				checkout_url: checkoutUrl,
				currency,
				country,
				gateway_id: gatewayId,
				stripe_payment_method_type: stripePaymentMethodType,
				method_family: methodFamily,
				order_id: null,
				order_received_url: null,
				payment_intent_id: null,
				started_at: startedAt,
				last_updated_at: new Date().toISOString(),
				failures: [ 'Submit-capable LPM checkout flow is not implemented yet.' ],
				...payload,
			},
			null,
			2
		) }\n`,
		'utf8'
	);
}

async function countLocator( page, selector ) {
	try {
		return await page.locator( selector ).count();
	} catch ( error ) {
		return 0;
	}
}

async function capturePageEvidence( page ) {
	let logs = [];
	let snapshotText = '';
	const screenshotPath = evidencePath.replace( /\.json$/, '.png' );
	const gatewaySelectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		`[data-gateway-id="${ gatewayId }"]`,
	];

	try {
		logs = await getLatestLogs( { page, sinceLastCall: true } );
	} catch ( error ) {
		logs = [ { type: 'playwriter-log-error', text: error?.message || String( error ) } ];
	}

	try {
		snapshotText = await snapshot( { page } );
	} catch ( error ) {
		snapshotText = `snapshot failed: ${ error?.message || String( error ) }`;
	}

	try {
		await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );
	} catch ( error ) {
		// Screenshot evidence is useful but should not hide the primary page state.
	}

	return {
		final_url: page.url(),
		title: await page.title().catch( () => '' ),
		gateway_selectors: await Promise.all(
			gatewaySelectors.map( async ( selector ) => ( {
				selector,
				count: await countLocator( page, selector ),
			} ) )
		),
		snapshot: snapshotText,
		logs,
		screenshot_path: screenshotPath,
	};
}

assertLocalBase( baseUrl );

let gatePage = null;
let wroteEvidence = false;

try {
	gatePage = await context.newPage();
	if ( typeof state !== 'undefined' ) {
		state.lpmCheckoutPage = gatePage;
	}

	await gatePage.setViewportSize( { width: 1280, height: 900 } );
	await gatePage.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await gatePage.goto( checkoutUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page: gatePage, timeout: 20000, minWait: 1000 } );

	const pageEvidence = await capturePageEvidence( gatePage );
	writeEvidence( {
		page: pageEvidence,
	} );
	wroteEvidence = true;
	throw new Error( `LPM checkout driver for ${ role }/${ method } reached checkout evidence capture but cannot submit yet. See ${ evidencePath }` );
} catch ( error ) {
	if ( ! wroteEvidence ) {
		writeEvidence( {
			error: safeError( error ),
		} );
	}
	throw error;
} finally {
	if ( gatePage ) {
		gatePage.removeAllListeners( 'response' );
		gatePage.removeAllListeners( 'console' );
		gatePage.removeAllListeners( 'pageerror' );
		try {
			await gatePage.close();
		} catch ( error ) {
			// The page may already be closed if navigation crashes; keep the real failure.
		}
	}
}
