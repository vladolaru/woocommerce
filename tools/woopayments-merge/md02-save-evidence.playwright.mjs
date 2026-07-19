// MD-02 exact dispute draft-save evidence under isolated Playwright.

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const {
	canonicalCustomerNameJson,
	deriveAssertions,
	isAllowedTimelineFailure,
	isAllowedTimelineConsole,
} = require( './md02-browser-assertions.cjs' );

const config = state?.md02SaveEvidenceConfig;
if ( ! config || typeof config !== 'object' ) {
	throw new Error( 'MD-02 browser config is unavailable.' );
}

const required = [
	'store',
	'runStamp',
	'runtimeOwner',
	'baseUrl',
	'disputesPath',
	'disputeId',
	'chargeId',
	'orderId',
	'expectedDescription',
	'expectedCustomerNameHmac',
	'authCookies',
	'evidencePath',
	'screenshotDir',
];
for ( const name of required ) {
	if ( config[ name ] === undefined || config[ name ] === null || config[ name ] === '' ) {
		throw new Error( `MD-02 browser config field is unavailable: ${ name }` );
	}
}
if ( ! Array.isArray( config.authCookies ) || config.authCookies.length !== 3 ) {
	throw new Error( 'MD-02 browser auth cookie set is unavailable.' );
}

const contextKey = process.env.CRITICAL_FLOWS_RUN_CONTEXT_KEY || '';
if ( ! /^[0-9a-f]{64}$/.test( contextKey ) ) {
	throw new Error( 'MD-02 browser context key is unavailable.' );
}

function assertLocalUrl( value ) {
	const parsed = new URL( value );
	if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
		throw new Error( 'MD-02 browser origin must use HTTP(S).' );
	}
	if ( ! [ 'localhost', '127.0.0.1' ].includes( parsed.hostname ) && ! parsed.hostname.endsWith( '.localhost' ) ) {
		throw new Error( 'MD-02 browser origin must stay local.' );
	}
}

function sameOriginUrl( relativePath ) {
	const resolved = new URL( relativePath, `${ config.baseUrl.replace( /\/+$/, '' ) }/` );
	if ( resolved.origin !== new URL( config.baseUrl ).origin || ! resolved.pathname.startsWith( '/wp-admin/' ) ) {
		throw new Error( 'MD-02 browser path escaped its local wp-admin origin.' );
	}
	return resolved.toString();
}

function fingerprint( value ) {
	return `sha256:${ crypto.createHash( 'sha256' ).update( String( value ) ).digest( 'hex' ) }`;
}

function customerNameHmac( value ) {
	return `hmac-sha256:${ crypto
			.createHmac( 'sha256', Buffer.from( contextKey, 'hex' ) )
			.update( `customer-name\0${ canonicalCustomerNameJson( value ) }` )
		.digest( 'hex' ) }`;
}

function normalizedText( value ) {
	return String( value || '' ).replace( /\s+/g, ' ' ).trim();
}

function escapeRegExp( value ) {
	return String( value ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

assertLocalUrl( config.baseUrl );
fs.mkdirSync( config.screenshotDir, { recursive: true } );

const screenshotNames = {
	form: `${ config.store }-md02-form.png`,
	saved: `${ config.store }-md02-saved.png`,
	reloaded: `${ config.store }-md02-reloaded.png`,
};
const facts = {
	authenticatedAdmin: false,
	exactDisputeRow: false,
	responseActionDiscovered: false,
	requestSeen: false,
	requestMethod: '',
	requestPathMatches: false,
	submitFalse: false,
	descriptionMatches: false,
	payloadCustomerNameMatches: false,
	noFilesAttached: false,
	responseOk: false,
	saveFeedback: false,
	reloadedDescriptionMatches: false,
	descriptionEditable: false,
	saveButtonLabel: '',
	customerNameVisible: false,
};
const requestEvidence = {
	method: '',
	path: '',
	submit_false: false,
	description_matches: false,
	customer_name_matches: false,
	files_selected: -1,
};
const responseEvidence = { status: 0, ok: false };
const failed_responses = [];
const diagnostics = [];
const console_errors = [];
const page_errors = [];
const errors = [];
const blockers = [];
let phase = 'initialized';
let page = null;

function browserEvidence() {
	const assertions = deriveAssertions( facts );
	return {
		schema: 'woopayments_md02_browser_raw.v1',
		store: config.store,
		run_stamp: config.runStamp,
		runtime_owner: config.runtimeOwner,
		phase,
		identity: {
			order_id: config.orderId,
			charge_id: config.chargeId,
			dispute_id: config.disputeId,
		},
		facts,
		functional_assertions: assertions.functional,
		ux_assertions: assertions.ux,
		request: requestEvidence,
		response: responseEvidence,
		failed_responses,
		diagnostics,
		console_errors,
		page_errors,
		screenshots: Object.values( screenshotNames ),
		errors,
		blockers,
	};
}

function writeEvidence( nextPhase ) {
	phase = nextPhase;
	fs.mkdirSync( path.dirname( config.evidencePath ), { recursive: true } );
	fs.writeFileSync( config.evidencePath, `${ JSON.stringify( browserEvidence(), null, 2 ) }\n`, 'utf8' );
}

function recordFailedResponse( response ) {
	if ( response.status() < 400 || /favicon\.ico/.test( response.url() ) ) {
		return;
	}
	const parsed = new URL( response.url() );
	const failure = {
		store: config.store,
		status: response.status(),
		method: response.request().method(),
		path: parsed.pathname,
		origin: parsed.origin,
		expectedOrigin: new URL( config.baseUrl ).origin,
		phase,
		chargeId: config.chargeId,
	};
	if ( isAllowedTimelineFailure( failure ) ) {
		diagnostics.push( { ...failure, disposition: 'unrelated_reference_timeline_get' } );
		return;
	}
	failed_responses.push( failure );
}

function recordConsole( message ) {
	if ( message.type() === 'error' ) {
		const event = {
			store: config.store,
			phase,
			type: message.type(),
			text: message.text(),
				url: message.location()?.url || '',
				expectedOrigin: new URL( config.baseUrl ).origin,
				chargeId: config.chargeId,
		};
		if ( isAllowedTimelineConsole( event ) ) {
			diagnostics.push( {
				type: 'console',
				text_sha256: fingerprint( event.text ),
				phase,
				disposition: 'unrelated_reference_timeline_console',
			} );
			return;
		}
		console_errors.push( { type: 'error', text_sha256: fingerprint( message.text() ), phase } );
	}
}

function recordPageError( error ) {
	page_errors.push( { message_sha256: fingerprint( error?.message || error ), phase } );
}

async function exactCustomerNameVisible() {
	const candidates = await page.locator(
		'.wcpay-dispute-evidence-customer-details a, .wcpay-dispute-evidence-customer-details span, [class*="customer-details"] a, [class*="customer-details"] span'
	).allTextContents();
	return candidates.some( ( value ) => customerNameHmac( value ) === config.expectedCustomerNameHmac );
}

async function maskedScreenshot( name ) {
	const piiMasks = page.locator(
		'.wcpay-dispute-evidence-customer-details, [data-testid*="customer"], [class*="customer-details"]'
	);
	await page.screenshot( {
		path: path.join( config.screenshotDir, name ),
		fullPage: false,
		scale: 'css',
		mask: piiMasks,
	} );
}

try {
	await context.addCookies( config.authCookies.map( ( cookie ) => ( {
		name: cookie.name,
		value: cookie.value,
		url: config.baseUrl,
		httpOnly: true,
		sameSite: 'Lax',
	} ) ) );
	page = await context.newPage();
	state.page = page;
	await page.setViewportSize( { width: 1440, height: 1100 } );
	page.on( 'response', recordFailedResponse );
	page.on( 'console', recordConsole );
	page.on( 'pageerror', recordPageError );

	phase = 'list';
	await page.goto( sameOriginUrl( config.disputesPath ), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 1200 } );
	await page.getByRole( 'heading', { name: /^Disputes$/i } ).first().waitFor( { state: 'visible', timeout: 30000 } );
	const rowResult = await page.evaluate( ( expected ) => {
		const clean = ( value ) => String( value || '' ).replace( /\s+/g, ' ' ).trim();
		const boundary = ( text, identifier ) => new RegExp( `(^|[^A-Za-z0-9_])${ identifier.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }([^A-Za-z0-9_]|$)` ).test( text );
		const rows = Array.from( document.querySelectorAll( 'tr, [role="row"]' ) );
		for ( const row of rows ) {
			const text = clean( row.innerText || row.textContent );
			const actions = Array.from( row.querySelectorAll( 'a[href]' ) ).map( ( link ) => ( {
				text: clean( link.innerText || link.textContent ),
				aria: clean( link.getAttribute( 'aria-label' ) ),
				href: link.href,
			} ) );
			const exact = actions.some( ( action ) => boundary( action.aria, expected.disputeId ) ) || boundary( text, String( expected.orderId ) );
			if ( exact ) {
				const response = actions.find( ( action ) => /respond|challenge/i.test( `${ action.text } ${ action.aria }` ) );
				return { found: true, href: response?.href || '' };
			}
		}
		return { found: false, href: '' };
	}, { disputeId: config.disputeId, orderId: config.orderId } );
	facts.authenticatedAdmin = await page.evaluate( () => document.body?.classList.contains( 'wp-admin' ) && Boolean( document.querySelector( '#adminmenu' ) ) && ! document.querySelector( '#loginform' ) );
	facts.exactDisputeRow = rowResult.found === true;
	facts.responseActionDiscovered = Boolean( rowResult.href );
	if ( ! facts.exactDisputeRow || ! facts.responseActionDiscovered ) {
		throw new Error( 'The exact dispute response action is unavailable.' );
	}

	phase = 'details';
	await page.goto( sameOriginUrl( rowResult.href ), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 800 } );
	if ( ! /(?:disputes\/challenge|new-evidence)/.test( page.url() ) ) {
		const challenge = page.getByRole( 'button', { name: /challenge dispute|respond/i } ).or(
			page.getByRole( 'link', { name: /challenge dispute|respond/i } )
		).first();
		if ( await challenge.count() === 0 ) {
			throw new Error( 'The discovered dispute does not expose a challenge action.' );
		}
		await challenge.click();
		await waitForPageLoad( { page, timeout: 30000, minWait: 800 } );
	}

	const description = page.getByLabel( /^(?:PRODUCT OR SERVICE DESCRIPTION|Product description)$/i ).first();
	await description.waitFor( { state: 'visible', timeout: 30000 } );
	facts.customerNameVisible = await exactCustomerNameVisible();
	for ( let attempt = 0; attempt < 10; attempt += 1 ) {
		await description.fill( config.expectedDescription );
		await description.press( 'Tab' );
		if ( await description.inputValue() === config.expectedDescription ) {
			break;
		}
		await page.waitForTimeout( 500 );
	}
	if ( await description.inputValue() !== config.expectedDescription ) {
		throw new Error( 'The exact product description could not be committed to the form.' );
	}
	const selectedFileCount = await page.locator( 'input[type="file"]' ).evaluateAll( ( inputs ) => inputs.reduce( ( count, input ) => count + ( input.files?.length || 0 ), 0 ) );
	facts.noFilesAttached = selectedFileCount === 0;
	await maskedScreenshot( screenshotNames.form );

	const saveButton = page.getByRole( 'button', { name: /^(?:Save for later|Save draft)$/i } ).first();
	await saveButton.waitFor( { state: 'visible', timeout: 10000 } );
	facts.saveButtonLabel = normalizedText( await saveButton.textContent() );
	writeEvidence( 'armed' );

	const responsePromise = page.waitForResponse( ( response ) => {
		const request = response.request();
		const parsed = new URL( response.url() );
		const expectedPath = `/wp-json/wc/v3/payments/disputes/${ encodeURIComponent( config.disputeId ) }`;
		if ( request.method() !== 'POST' || parsed.origin !== new URL( config.baseUrl ).origin || parsed.pathname !== expectedPath ) {
			return false;
		}
		let payload = {};
		try {
			payload = request.postDataJSON() || {};
		} catch {
			payload = {};
		}
		facts.requestSeen = true;
		facts.requestMethod = request.method();
		facts.requestPathMatches = true;
		facts.submitFalse = payload.submit === false;
		facts.descriptionMatches = payload?.evidence?.product_description === config.expectedDescription;
		facts.payloadCustomerNameMatches = customerNameHmac( payload?.evidence?.customer_name || '' ) === config.expectedCustomerNameHmac;
		requestEvidence.method = request.method();
		requestEvidence.path = parsed.pathname;
		requestEvidence.submit_false = facts.submitFalse;
		requestEvidence.description_matches = facts.descriptionMatches;
		requestEvidence.customer_name_matches = facts.payloadCustomerNameMatches;
		requestEvidence.files_selected = selectedFileCount;
		writeEvidence( 'request_seen' );
		return true;
	}, { timeout: 30000 } );
	await saveButton.click();
	const saveResponse = await responsePromise;
	responseEvidence.status = saveResponse.status();
	responseEvidence.ok = saveResponse.ok();
	facts.responseOk = saveResponse.ok();
	writeEvidence( 'response_seen' );

	await page.getByText( 'Evidence saved!', { exact: true } ).first().waitFor( { state: 'visible', timeout: 15000 } );
	facts.saveFeedback = true;
	await maskedScreenshot( screenshotNames.saved );

	await page.reload( { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 1000 } );
	const reloaded = page.getByLabel( /^(?:PRODUCT OR SERVICE DESCRIPTION|Product description)$/i ).first();
	await reloaded.waitFor( { state: 'visible', timeout: 30000 } );
	facts.reloadedDescriptionMatches = await reloaded.inputValue() === config.expectedDescription;
	facts.descriptionEditable = await reloaded.isEditable();
	await maskedScreenshot( screenshotNames.reloaded );
	writeEvidence( 'reloaded' );

	const assertions = deriveAssertions( facts );
	for ( const [ name, passed ] of Object.entries( assertions.functional ) ) {
		if ( ! passed ) {
			errors.push( `functional_assertion_failed:${ name }` );
		}
	}
	if ( failed_responses.length ) {
		errors.push( 'browser_response_failure' );
	}
	if ( console_errors.length ) {
		errors.push( 'browser_console_failure' );
	}
	if ( page_errors.length ) {
		errors.push( 'browser_page_failure' );
	}
} catch ( error ) {
	blockers.push( `browser_execution_blocked:${ fingerprint( error?.message || error ) }` );
} finally {
	if ( page ) {
		page.off( 'response', recordFailedResponse );
		page.off( 'console', recordConsole );
		page.off( 'pageerror', recordPageError );
		try {
			await page.close();
		} catch ( error ) {
			blockers.push( `browser_page_cleanup_blocked:${ fingerprint( error?.message || error ) }` );
		}
	}
	state.page = null;
}

writeEvidence( phase );
console.log( JSON.stringify( { phase, store: config.store, evidencePath: config.evidencePath } ) );
if ( blockers.length || errors.length ) {
	throw new Error( `MD-02 browser evidence is incomplete at ${ phase }.` );
}
