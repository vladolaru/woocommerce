// MD-01 provider-created dispute browser evidence under isolated Playwright.

const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const {
	badgeHasExpectedCount,
	rowHasExpectedAmount,
	rowHasExpectedOrderId,
	textHasExpectedIdentifier,
} = require( './md01-browser-assertions.cjs' );

const config = state?.md01CreatedConfig;
if ( ! config || 'object' !== typeof config ) {
	throw new Error( 'MD-01 browser config is unavailable.' );
}

const required = [
	'store',
	'runStamp',
	'runtimeOwner',
	'manifestSha256',
	'baseUrl',
	'orderPath',
	'disputesPath',
	'orderId',
	'chargeId',
	'intentId',
	'disputeId',
	'amountMinor',
	'currency',
	'reasonLabel',
	'awaitingResponseCount',
	'authCookies',
	'evidencePath',
	'screenshotDir',
];
for ( const name of required ) {
	if ( config[ name ] === undefined || config[ name ] === null || config[ name ] === '' ) {
		throw new Error( `MD-01 browser config field is unavailable: ${ name }` );
	}
}
if ( ! Array.isArray( config.authCookies ) || config.authCookies.length !== 3 ) {
	throw new Error( 'MD-01 browser auth cookie set is unavailable.' );
}
for ( const cookie of config.authCookies ) {
	if ( ! cookie || 'string' !== typeof cookie.name || ! cookie.name || 'string' !== typeof cookie.value || ! cookie.value ) {
		throw new Error( 'MD-01 browser auth cookie is invalid.' );
	}
}

function assertLocalUrl( value ) {
	const parsed = new URL( value );
	if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
		throw new Error( 'MD-01 browser origin must use HTTP(S).' );
	}
	if ( ! [ 'localhost', '127.0.0.1' ].includes( parsed.hostname ) && ! parsed.hostname.endsWith( '.localhost' ) ) {
		throw new Error( 'MD-01 browser origin must stay local.' );
	}
}

function fingerprint( value ) {
	return `sha256:${ crypto.createHash( 'sha256' ).update( String( value ) ).digest( 'hex' ) }`;
}

function sameOriginUrl( relativePath ) {
	const resolved = new URL( relativePath, `${ config.baseUrl.replace( /\/+$/, '' ) }/` );
	if ( resolved.origin !== new URL( config.baseUrl ).origin || ! resolved.pathname.startsWith( '/wp-admin/' ) ) {
		throw new Error( 'MD-01 browser path escaped its local wp-admin origin.' );
	}
	return resolved.toString();
}

function writeEvidence( payload ) {
	fs.mkdirSync( path.dirname( config.evidencePath ), { recursive: true } );
	fs.writeFileSync( config.evidencePath, `${ JSON.stringify( payload, null, 2 ) }\n`, 'utf8' );
}

function normalizedText( value ) {
	return String( value || '' ).replace( /\s+/g, ' ' ).trim();
}

assertLocalUrl( config.baseUrl );
fs.mkdirSync( config.screenshotDir, { recursive: true } );

const orderScreenshot = `${ config.store }-order.png`;
const disputesScreenshot = `${ config.store }-disputes.png`;
const failed_responses = [];
const console_errors = [];
const page_errors = [];
let page = null;
let ignoredSamplePermalinkConsoleAllowances = 0;
const samplePermalinkConsoleText = 'Failed to load resource: the server responded with a status of 403 (Forbidden)';

const isExactSamplePermalinkFailure = ( response ) => {
	if ( response.status() !== 403 ) {
		return false;
	}
	const request = response.request();
	const parsed = new URL( response.url() );
	if ( request.method() !== 'POST' || parsed.pathname !== '/wp-admin/admin-ajax.php' ) {
		return false;
	}
	const fields = new URLSearchParams( request.postData() || '' );
	return ! (
		fields.size !== 2 ||
		fields.get( 'action' ) !== 'sample-permalink' ||
		fields.get( 'post_id' ) !== String( config.orderId )
	);
};

const recordFailedResponse = ( response ) => {
	if ( response.status() >= 400 && ! /favicon\.ico/.test( response.url() ) ) {
		if ( isExactSamplePermalinkFailure( response ) ) {
			const consoleIndex = console_errors.findLastIndex( ( item ) => item.text_sha256 === fingerprint( samplePermalinkConsoleText ) );
			if ( consoleIndex >= 0 ) {
				console_errors.splice( consoleIndex, 1 );
			} else {
				ignoredSamplePermalinkConsoleAllowances += 1;
			}
			return;
		}
		const parsed = new URL( response.url() );
		failed_responses.push( { status: response.status(), path: parsed.pathname } );
	}
};
const recordConsole = ( message ) => {
	if ( message.type() === 'error' ) {
		if ( message.text() === samplePermalinkConsoleText && ignoredSamplePermalinkConsoleAllowances > 0 ) {
			ignoredSamplePermalinkConsoleAllowances -= 1;
			return;
		}
		console_errors.push( { type: 'error', text_sha256: fingerprint( message.text() ) } );
	}
};
const recordPageError = ( error ) => {
	page_errors.push( { message_sha256: fingerprint( error?.message || error ) } );
};

const assertions = {
	authenticated_admin: false,
	order_on_hold: false,
	created_note: false,
	note_reason_context: false,
	note_response_due_context: false,
	exact_dispute_row: false,
	needs_response: false,
	amount: false,
	reason: false,
	respond_action: false,
	badge_count: false,
};
const errors = [];
const blockers = [];

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

	await page.goto( sameOriginUrl( config.orderPath ), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 1000 } );
	const orderState = await page.evaluate( () => ( {
		url: window.location.href,
		text: ( document.body?.innerText || document.body?.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
		admin: document.body?.classList.contains( 'wp-admin' ) && Boolean( document.querySelector( '#adminmenu' ) ),
		login: Boolean( document.querySelector( '#loginform, form[name="loginform"]' ) ) || document.body?.classList.contains( 'login' ),
		statusControls: Array.from( document.querySelectorAll( '#order_status, select[name="order_status"], input[name="order_status"], [data-testid="order-status"]' ) ).map( ( control ) => ( {
			value: 'value' in control ? String( control.value || '' ) : String( control.getAttribute( 'data-value' ) || '' ),
			text: control instanceof HTMLSelectElement
				? Array.from( control.selectedOptions ).map( ( option ) => option.textContent || '' ).join( ' ' )
				: String( control.textContent || control.getAttribute( 'aria-label' ) || '' ),
		} ) ),
	} ) );
	const orderText = normalizedText( orderState.text );
	assertions.authenticated_admin = Boolean( orderState.admin && ! orderState.login && orderState.url.includes( '/wp-admin/' ) );
	assertions.order_on_hold = orderState.statusControls.some( ( control ) => {
		const value = normalizedText( control.value ).toLowerCase().replace( /^wc-/, '' );
		const text = normalizedText( control.text ).toLowerCase();
		return value === 'on-hold' || text === 'on hold';
	} );
	assertions.created_note = /payment has been disputed for/i.test( orderText );
	assertions.note_reason_context = /payment has been disputed for.{0,500}with reason/i.test( orderText );
	assertions.note_response_due_context = /payment has been disputed for.{0,700}response due by/i.test( orderText );
	await page.screenshot( { path: path.join( config.screenshotDir, orderScreenshot ), fullPage: false, scale: 'css' } );

	await page.goto( sameOriginUrl( config.disputesPath ), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 2000 } );
	await page.getByRole( 'heading', { name: /^Disputes$/i } ).first().waitFor( { state: 'visible', timeout: 30000 } );
	const disputeState = await page.evaluate( () => {
		const clean = ( value ) => String( value || '' ).replace( /\s+/g, ' ' ).trim();
		const candidates = Array.from( document.querySelectorAll( 'tr, [role="row"]' ) );
		const badgeValues = Array.from( document.querySelectorAll( '.wcpay-menu-badge .plugin-count, .wcpay-menu-badge' ) )
			.map( ( element ) => clean( element.textContent ) )
			.filter( Boolean );
		return {
			url: window.location.href,
			text: clean( document.body?.innerText || document.body?.textContent ),
			rows: candidates.map( ( candidate ) => ( {
				text: clean( candidate.innerText || candidate.textContent ),
				ariaLabels: Array.from( candidate.querySelectorAll( 'a[aria-label]' ) )
					.map( ( link ) => clean( link.getAttribute( 'aria-label' ) ) )
					.filter( Boolean ),
				links: Array.from( candidate.querySelectorAll( 'a[href]' ) ).map( ( link ) => ( {
					text: clean( link.innerText || link.textContent ),
					href: link.href,
				} ) ),
			} ) ),
			badgeValues,
			admin: document.body?.classList.contains( 'wp-admin' ) && Boolean( document.querySelector( '#adminmenu' ) ),
			login: Boolean( document.querySelector( '#loginform, form[name="loginform"]' ) ) || document.body?.classList.contains( 'login' ),
		};
	} );
	const exactByIdRow = disputeState.rows.find( ( row ) =>
		row.ariaLabels.some( ( label ) =>
			textHasExpectedIdentifier( label, config.disputeId )
		)
	);
	const exactByOrderRow = disputeState.rows.find( ( row ) =>
		rowHasExpectedOrderId( row, config.orderId ) && /respond/i.test( row.text )
	);
	const exactRow = exactByIdRow || exactByOrderRow;
	const rowText = normalizedText( exactRow?.text );
	assertions.authenticated_admin = assertions.authenticated_admin && Boolean( disputeState.admin && ! disputeState.login );
	assertions.exact_dispute_row = Boolean( exactRow );
	assertions.needs_response = /response needed|needs response/i.test( rowText );
	assertions.amount = rowHasExpectedAmount( rowText, config.amountMinor );
	assertions.reason = rowText.toLowerCase().includes( String( config.reasonLabel ).toLowerCase() );
	assertions.respond_action = /\brespond(?: now)?\b/i.test( rowText );
	assertions.badge_count = disputeState.badgeValues.some( ( value ) =>
		badgeHasExpectedCount( value, config.awaitingResponseCount )
	);
	await page.screenshot( { path: path.join( config.screenshotDir, disputesScreenshot ), fullPage: false, scale: 'css' } );

	for ( const [ name, passed ] of Object.entries( assertions ) ) {
		if ( ! passed ) {
			errors.push( `ui_assertion_failed:${ name }` );
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

const status = blockers.length ? 'blocked' : errors.length ? 'fail' : 'pass';
const evidence = {
	schema: 'woopayments_md01_browser_raw.v1',
	status,
	store: config.store,
	run_stamp: config.runStamp,
	runtime_owner: config.runtimeOwner,
	deterministic_manifest_sha256: config.manifestSha256,
	identity: {
		order_id: config.orderId,
		charge_id: config.chargeId,
		intent_id: config.intentId,
		dispute_id: config.disputeId,
	},
	assertions,
	failed_responses,
	console_errors,
	page_errors,
	screenshots: [ orderScreenshot, disputesScreenshot ],
	errors,
	blockers,
};
writeEvidence( evidence );
console.log( JSON.stringify( { status, store: config.store, evidencePath: config.evidencePath } ) );
if ( status !== 'pass' ) {
	throw new Error( `MD-01 browser evidence ${ status }. See ${ config.evidencePath }` );
}
