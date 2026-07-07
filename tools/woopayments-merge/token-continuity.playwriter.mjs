// SEPA token-continuity browser evidence driver.
//
// The shell gate validates this file's JSON evidence and fails closed unless it
// reports a real pass. The current local driver captures the relevant pages and
// records incomplete evidence; a submit-capable browser flow must replace the
// incomplete state before the Phase 4 live gate can pass.

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

const phase = requiredEnv( 'TOKEN_CONTINUITY_GATE_PHASE' );
const baseUrl = requiredEnv( 'TOKEN_CONTINUITY_GATE_BASE_URL' ).replace( /\/+$/, '' );
const method = requiredEnv( 'TOKEN_CONTINUITY_GATE_METHOD' );
const gatewayId = requiredEnv( 'TOKEN_CONTINUITY_GATE_GATEWAY_ID' );
const stripePaymentMethodType = requiredEnv( 'TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE' );
const tokenType = requiredEnv( 'TOKEN_CONTINUITY_GATE_TOKEN_TYPE' );
const customerId = Number.parseInt( requiredEnv( 'TOKEN_CONTINUITY_GATE_CUSTOMER_ID' ), 10 );
const tokenId = env.TOKEN_CONTINUITY_GATE_TOKEN_ID ? Number.parseInt( env.TOKEN_CONTINUITY_GATE_TOKEN_ID, 10 ) : null;
const evidencePath = requiredEnv( 'TOKEN_CONTINUITY_GATE_EVIDENCE_PATH' );
const startedAt = new Date().toISOString();

function assertLocalBase( value ) {
	const parsed = new URL( value );
	const host = parsed.hostname;
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		throw new Error( `Token-continuity base must be http(s), got ${ value }` );
	}
	if ( host !== 'localhost' && host !== '127.0.0.1' && ! host.endsWith( '.localhost' ) ) {
		throw new Error( `Token-continuity base must stay local, got ${ value }` );
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

function phaseUrl() {
	if ( phase === 'save_sepa_token' ) {
		return `${ baseUrl }/checkout/?token-continuity-gate=save-sepa`;
	}
	if ( phase === 'render_payment_methods' ) {
		return `${ baseUrl }/my-account/payment-methods/?token-continuity-gate=render-sepa&token_id=${ encodeURIComponent(
			tokenId || ''
		) }`;
	}
	throw new Error( `Unsupported token-continuity phase: ${ phase }` );
}

function writeEvidence( payload ) {
	ensureDir( path.dirname( evidencePath ) );
	fs.writeFileSync(
		evidencePath,
		`${ JSON.stringify(
			{
				schema: 'woopayments_token_continuity_browser_evidence.v1',
				status: 'incomplete',
				phase,
				base_url: baseUrl,
				url: phaseUrl(),
				method,
				gateway_id: gatewayId,
				stripe_payment_method_type: stripePaymentMethodType,
				token_type: tokenType,
				customer_id: customerId,
				token_id: tokenId,
				payment_method_id: null,
				token_visible: false,
				started_at: startedAt,
				last_updated_at: new Date().toISOString(),
				failures: [ 'Submit-capable SEPA token-continuity browser flow is not implemented yet.' ],
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
	const selectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		'.woocommerce-MyAccount-paymentMethods',
		'.woocommerce-PaymentMethod',
		'.payment-method',
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
		selectors: await Promise.all(
			selectors.map( async ( selector ) => ( {
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
		state.tokenContinuityPage = gatePage;
	}

	await gatePage.setViewportSize( { width: 1280, height: 900 } );
	await gatePage.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await gatePage.goto( phaseUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page: gatePage, timeout: 20000, minWait: 1000 } );

	const pageEvidence = await capturePageEvidence( gatePage );
	writeEvidence( {
		page: pageEvidence,
	} );
	wroteEvidence = true;
	throw new Error( `Token-continuity driver reached ${ phase } evidence capture but cannot complete it yet. See ${ evidencePath }` );
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
