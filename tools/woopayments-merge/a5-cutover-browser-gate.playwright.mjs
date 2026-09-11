// A5e native WooPayments soft cutover browser gate.
//
// This is a local browser/runbook gate for the target store only. It verifies
// the real product-generated merchant handoff from standalone WooPayments to
// native/core WooPayments, records JSON/screenshot evidence, and fails closed.
// Red results must become product fixes or tracked A5 slices; do not weaken this
// script to hide product bugs. WPCOM sandbox/repo access is forbidden.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const env = typeof process !== 'undefined' && process.env ? process.env : {};
const targetUrl = ( env.A5_GATE_TARGET_URL || 'http://store8889.localhost:8889' ).replace( /\/+$/, '' );
const pluginsUrl = env.A5_GATE_PLUGINS_URL || `${ targetUrl }/wp-admin/plugins.php`;
const dataDir = env.A5_GATE_DATA_DIR || path.join( process.cwd(), '.agents/scratchpad/sessions/2026-06-15-core-native-payments/data' );
const evidencePath = env.A5_GATE_EVIDENCE_PATH || path.join( dataDir, 'a5e-soft-cutover-browser-gate.json' );

const failedResponses = [];
const ignoredFailedResponses = [];
const consoleIssues = [];
const ignoredConsoleIssues = [];
const screenshots = [];
const screenshotFailures = [];
const checks = [];
const urls = {
	plugins: pluginsUrl,
	initial: null,
	afterDisableClick: null,
	pluginsCheck: null,
};

function ensureDir( dir ) {
	fs.mkdirSync( dir, { recursive: true } );
}

function interestingFailure( response ) {
	const status = response.status();
	if ( status < 400 ) {
		return false;
	}
	const url = response.url();
	return ! /favicon\.ico|load-scripts\.php.*ver=/.test( url );
}

function isConsoleIssue( message ) {
	const type = message.type();
	const text = message.text();
	return type === 'error' || type === 'warning' || /error|warning|fatal|exception/i.test( text );
}

function isIgnoredConsoleIssue( text ) {
	return /JQMIGRATE|Permissions policy violation: unload/i.test( text );
}

async function getOrCreatePage() {
	if ( state.page && ! state.page.isClosed() ) {
		await state.page.close();
	}
	state.page = await context.newPage();
	return state.page;
}

async function settlePage( page, timeout = 20000 ) {
	await waitForPageLoad( { page, timeout, minWait: 1000 } );
}

function recordCheck( id, passed, details = {} ) {
	const check = { id, passed, ...details };
	checks.push( check );
	writeEvidence( 'running' );
	return check;
}

async function captureScreenshot( page, id ) {
	const screenshotPath = path.join( dataDir, `a5e-soft-cutover-${ id }.png` );
	try {
		await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css', timeout: 10000 } );
		screenshots.push( screenshotPath );
	} catch ( error ) {
		screenshotFailures.push( {
			id,
			path: screenshotPath,
			message: error.message,
		} );
	}
	writeEvidence( 'running' );
	return screenshotPath;
}

function writeEvidence( status = 'running' ) {
	const evidence = {
		gate: 'a5e-soft-cutover-browser-gate',
		status,
		lastUpdatedAt: new Date().toISOString(),
		urls,
		checks,
		failedResponses,
		ignoredFailedResponses,
		consoleIssues,
		ignoredConsoleIssues,
		screenshots,
		screenshotFailures,
		pass: status === 'complete' && checks.every( ( check ) => check.passed ) && failedResponses.length === 0 && consoleIssues.length === 0,
	};
	fs.writeFileSync( evidencePath, `${ JSON.stringify( evidence, null, 2 ) }\n` );
	return evidence;
}

async function assertAuthenticatedWpAdmin( page ) {
	const authState = await page.evaluate( () => ( {
		bodyClasses: document.body.className,
		hasAdminBody: document.body.classList.contains( 'wp-admin' ),
		hasAdminMenu: Boolean( document.querySelector( '#adminmenu' ) ),
		hasWpbodyContent: Boolean( document.querySelector( '#wpbody-content' ) ),
		hasLoginForm: Boolean( document.querySelector( '#loginform, form[name="loginform"]' ) ) || document.body.classList.contains( 'login' ),
	} ) );
	const passed = ! authState.hasLoginForm && authState.hasAdminBody && authState.hasAdminMenu && authState.hasWpbodyContent;
	recordCheck( 'authenticated-wp-admin', passed, authState );
	if ( ! passed ) {
		throw new Error( 'A5e browser gate is not authenticated as WP Admin on the target store.' );
	}
}

async function readBodyText( page ) {
	return page.locator( 'body' ).innerText( { timeout: 20000 } );
}

async function assertSoftCutoverNotice( page ) {
	const bodyText = await readBodyText( page );
	const requiredTokens = [ 'WooPayments', 'now part of WooCommerce core', 'Disable WooPayments' ];
	const missingTokens = requiredTokens.filter( ( token ) => ! bodyText.includes( token ) );
	const fallbackTokens = [ 'WooPayments', 'WooCommerce core', 'Disable WooPayments' ];
	const missingFallbackTokens = fallbackTokens.filter( ( token ) => ! bodyText.includes( token ) );
	const passed = missingTokens.length === 0 || missingFallbackTokens.length === 0;
	recordCheck( 'soft-cutover-notice', passed, {
		requiredTokens,
		missingTokens,
		fallbackTokens,
		missingFallbackTokens,
	} );
	if ( ! passed ) {
		throw new Error( 'Missing WooPayments soft cutover notice on the plugins page.' );
	}
}

async function locateDisableWooPaymentsControl( page ) {
	const controls = page.locator( 'a, button, input[type="submit"], input[type="button"]' );
	const index = await controls.evaluateAll( ( elements ) => {
		return elements.findIndex( ( element ) => {
			if ( element.offsetParent === null ) {
				return false;
			}

			const label = [
				element.innerText,
				element.textContent,
				element.value,
				element.getAttribute( 'aria-label' ),
				element.getAttribute( 'title' ),
			].filter( Boolean ).join( ' ' );
			return /Disable\s+WooPayments/i.test( label );
		} );
	} );

	if ( index < 0 ) {
		recordCheck( 'disable-woopayments-control', false, { found: false } );
		throw new Error( 'Could not find the product-generated Disable WooPayments control.' );
	}

	const control = controls.nth( index );
	const metadata = await control.evaluate( ( element ) => {
		const notice = element.closest( '.notice, .updated, .error, .components-notice, tr, .plugin-card' );
		return {
			found: true,
			tagName: element.tagName.toLowerCase(),
			text: ( element.innerText || element.textContent || element.value || '' ).trim(),
			href: element.getAttribute( 'href' ),
			name: element.getAttribute( 'name' ),
			type: element.getAttribute( 'type' ),
			classes: element.className,
			noticeText: notice ? ( notice.innerText || notice.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 500 ) : null,
		};
	} );

	recordCheck( 'disable-woopayments-control', true, metadata );
	return control;
}

async function clickDisableWooPayments( page ) {
	const control = await locateDisableWooPaymentsControl( page );
	await Promise.all( [
		page.waitForLoadState( 'domcontentloaded', { timeout: 45000 } ).catch( () => null ),
		control.click( { timeout: 10000 } ),
	] );
	await settlePage( page );
	urls.afterDisableClick = page.url();
	recordCheck( 'disable-woopayments-click', true, { finalUrl: urls.afterDisableClick } );
}

async function assertSuccessNotice( page ) {
	const deadline = Date.now() + 10000;
	let bodyText = '';

	do {
		bodyText = await readBodyText( page );
		if ( /WooPayments/i.test( bodyText ) && /(native|core)/i.test( bodyText ) && /(success|successfully|disabled|deactivated)/i.test( bodyText ) ) {
			recordCheck( 'soft-cutover-success-notice', true, {
				finalUrl: page.url(),
				requiredLanguage: [ 'WooPayments', 'native/core', 'successfully disabled/deactivated' ],
			} );
			return;
		}
		await page.waitForTimeout( 500 );
	} while ( Date.now() < deadline );

	recordCheck( 'soft-cutover-success-notice', false, {
		finalUrl: page.url(),
		requiredLanguage: [ 'WooPayments', 'native/core', 'successfully disabled/deactivated' ],
		bodyTextSample: bodyText.replace( /\s+/g, ' ' ).trim().slice( 0, 1000 ),
	} );
	throw new Error( 'Missing WooPayments native/core success notice after soft cutover.' );
}

async function assertWooPaymentsInactiveOnPluginsPage( page ) {
	if ( ! page.url().startsWith( pluginsUrl ) ) {
		await page.goto( pluginsUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
		await settlePage( page );
	}
	urls.pluginsCheck = page.url();

	const pluginRows = await page.evaluate( () => {
		return Array.from( document.querySelectorAll( 'tr' ) )
			.map( ( row ) => {
				const text = ( row.innerText || row.textContent || '' ).replace( /\s+/g, ' ' ).trim();
				const titleText = ( row.querySelector( '.plugin-title, strong' )?.textContent || '' ).replace( /\s+/g, ' ' ).trim();
				const actionElements = Array.from( row.querySelectorAll( 'a, button, input[type="submit"], input[type="button"]' ) );
				const actions = actionElements.map( ( action ) => ( {
					text: ( action.innerText || action.textContent || action.value || '' ).replace( /\s+/g, ' ' ).trim(),
					href: action.getAttribute( 'href' ),
					name: action.getAttribute( 'name' ),
					classes: action.className,
				} ) );
				const actionHrefs = actions.map( ( action ) => action.href || '' ).join( ' ' );
				const looksLikeWooPaymentsRow =
					/woocommerce-payments\/woocommerce-payments\.php/i.test( row.id ) ||
					/plugin=(?:woocommerce-payments%2Fwoocommerce-payments\.php|woocommerce-payments\/woocommerce-payments\.php)\b/i.test( actionHrefs ) ||
					/checked%5B0%5D=(?:woocommerce-payments%2Fwoocommerce-payments\.php|woocommerce-payments\/woocommerce-payments\.php)\b/i.test( actionHrefs );
				const hasDeactivateAction = actions.some( ( action ) => {
					const label = action.text || action.name || '';
					return /^Deactivate$/i.test( label ) || /[?&]action=deactivate\b/i.test( action.href || '' );
				} );

				return {
					id: row.id,
					classes: row.className,
					titleText,
					textSample: text.slice( 0, 500 ),
					actions,
					looksLikeWooPaymentsRow,
					hasDeactivateAction,
					hasActiveClass: row.classList.contains( 'active' ),
				};
			} )
			.filter( ( row ) => row.looksLikeWooPaymentsRow );
	} );

	const rowsWithActiveDeactivate = pluginRows.filter( ( row ) => row.hasDeactivateAction && ( row.hasActiveClass || /active/i.test( row.classes ) ) );
	const rowsWithDeactivate = pluginRows.filter( ( row ) => row.hasDeactivateAction );
	const passed = rowsWithActiveDeactivate.length === 0 && rowsWithDeactivate.length === 0;

	recordCheck( 'woopayments-plugin-inactive', passed, {
		finalUrl: urls.pluginsCheck,
		pluginRows,
		rowsWithActiveDeactivate,
		rowsWithDeactivate,
	} );

	if ( ! passed ) {
		throw new Error( 'WooPayments still presents an active-state Deactivate action on the plugins page.' );
	}
}

ensureDir( dataDir );
writeEvidence( 'running' );

const page = await getOrCreatePage();
await page.setViewportSize( { width: 1440, height: 1100 } );

const responseHandler = ( response ) => {
	if ( interestingFailure( response ) ) {
		failedResponses.push( { status: response.status(), url: response.url() } );
	}
};
const consoleHandler = ( message ) => {
	if ( isConsoleIssue( message ) ) {
		const issue = { type: message.type(), text: message.text() };
		if ( isIgnoredConsoleIssue( issue.text ) ) {
			ignoredConsoleIssues.push( issue );
			return;
		}
		consoleIssues.push( issue );
	}
};

page.on( 'response', responseHandler );
page.on( 'console', consoleHandler );

try {
	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await page.waitForTimeout( 100 );
	await page.goto( pluginsUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await settlePage( page );
	urls.initial = page.url();

	await assertAuthenticatedWpAdmin( page );
	await assertSoftCutoverNotice( page );
	await captureScreenshot( page, 'before-disable' );
	await clickDisableWooPayments( page );
	await assertSuccessNotice( page );
	await captureScreenshot( page, 'after-disable' );
	await assertWooPaymentsInactiveOnPluginsPage( page );
	await captureScreenshot( page, 'plugins-inactive' );
} catch ( error ) {
	recordCheck( 'uncaught-error', false, { message: error.message, stack: error.stack } );
	const evidence = writeEvidence( 'failed' );
	console.log( JSON.stringify( { evidencePath, pass: evidence.pass, failedChecks: checks.filter( ( check ) => ! check.passed ).length }, null, 2 ) );
	throw error;
} finally {
	page.off( 'response', responseHandler );
	page.off( 'console', consoleHandler );
	if ( ! page.isClosed() ) {
		await page.close();
	}
	state.page = null;
}

const evidence = writeEvidence( 'complete' );
console.log( JSON.stringify( { evidencePath, pass: evidence.pass, checks: checks.length, failedResponses: failedResponses.length, consoleIssues: consoleIssues.length }, null, 2 ) );
if ( ! evidence.pass ) {
	throw new Error( `A5e soft cutover browser gate failed. See ${ evidencePath }` );
}
