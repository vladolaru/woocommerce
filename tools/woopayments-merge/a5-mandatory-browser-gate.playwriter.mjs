// A5e mandatory native WooPayments cutover browser gate.
//
// Local runbook gate for the target store. It verifies that mandatory cutover
// deactivates the standalone WooPayments plugin and renders the product success
// notice in the real wp-admin browser flow. Do not weaken this to mask bugs.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const pluginsUrl = 'http://store8889.localhost:8889/wp-admin/plugins.php';
const dataDir = '/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data';
const evidencePath = path.join( dataDir, 'a5e-mandatory-auto-deactivation-browser-check.json' );

const failedResponses = [];
const ignoredFailedResponses = [];
const consoleIssues = [];
const ignoredConsoleIssues = [];
const screenshots = [];
const checks = [];
const urls = {
	plugins: pluginsUrl,
	initial: null,
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
	const screenshotPath = path.join( dataDir, `a5e-mandatory-${ id }.png` );
	await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );
	screenshots.push( screenshotPath );
	writeEvidence( 'running' );
	return screenshotPath;
}

function writeEvidence( status = 'running' ) {
	const evidence = {
		gate: 'a5e-mandatory-auto-deactivation-browser-check',
		status,
		lastUpdatedAt: new Date().toISOString(),
		urls,
		checks,
		failedResponses,
		ignoredFailedResponses,
		consoleIssues,
		ignoredConsoleIssues,
		screenshots,
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
		throw new Error( 'A5e mandatory browser gate is not authenticated as WP Admin on the target store.' );
	}
}

async function readBodyText( page ) {
	return page.locator( 'body' ).innerText( { timeout: 20000 } );
}

async function assertMandatorySuccessNotice( page ) {
	const deadline = Date.now() + 10000;
	let bodyText = '';

	do {
		bodyText = await readBodyText( page );
		const passed = bodyText.includes( 'WooPayments is now fully native in WooCommerce' ) && bodyText.includes( 'Everything works as before' );
		if ( passed ) {
			recordCheck( 'mandatory-success-notice', true, {
				finalUrl: page.url(),
				requiredTokens: [ 'WooPayments is now fully native in WooCommerce', 'Everything works as before' ],
			} );
			return;
		}
		await page.waitForTimeout( 500 );
	} while ( Date.now() < deadline );

	recordCheck( 'mandatory-success-notice', false, {
		finalUrl: page.url(),
		requiredTokens: [ 'WooPayments is now fully native in WooCommerce', 'Everything works as before' ],
		bodyTextSample: bodyText.replace( /\s+/g, ' ' ).trim().slice( 0, 1000 ),
	} );
	throw new Error( 'Missing mandatory native WooPayments success notice.' );
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
	await assertMandatorySuccessNotice( page );
	await captureScreenshot( page, 'success-notice' );
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
	throw new Error( `A5e mandatory auto-deactivation browser gate failed. See ${ evidencePath }` );
}
