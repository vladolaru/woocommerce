// A4/N12 native WooPayments admin browser gate.
//
// This is a browser/runbook gate, not the source/chunk gate. It drives only the
// local target store and local reference store, records JSON/screenshot evidence,
// and fails closed. Red results must become product fixes or tracked A4 slices;
// do not weaken this script to hide product bugs. WPCOM sandbox/repo access is
// forbidden.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const targetBase = 'http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=';
const referenceWcAdminBase = 'http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=';
const gateSlug =
	( typeof process !== 'undefined' && process.env?.WOOPAYMENTS_GATE_SLUG ) || state.gateSlug || 'a4ab';
const dataDir = `/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/${ gateSlug }`;
const evidencePath = `/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/${ gateSlug }-admin-browser-gate.json`;
const unavailableRouteToken = 'This WooPayments admin area is unavailable.';

function targetRoute( routePath, query = '' ) {
	return targetBase + encodeURIComponent( routePath ) + query;
}

function referenceRoute( routePath, query = '' ) {
	return referenceWcAdminBase + encodeURIComponent( routePath ) + query;
}

const surfaces = [
	{
		id: 'settings',
		target: targetRoute( '/woopayments/settings' ),
		reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments',
		targetTokens: [ 'WooPayments', 'General', 'Payment methods', 'Transactions', 'Payouts', 'Account notifications', 'Fraud protection', 'Advanced settings' ],
		referenceTokens: [ 'WooPayments', 'General', 'Payment methods', 'Transactions', 'Payouts', 'Account notifications', 'Fraud protection', 'Advanced settings' ],
		targetAssets: [],
		targetAssetNote: 'Top-level gateway settings are rendered through the WC settings page in this local route; no native lazy chunk was observed for this surface.',
	},
	{
		id: 'settings-express-woopay',
		target: targetRoute( '/woopayments/settings/express-checkout/woopay' ),
		reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=woopay',
		targetTokens: [ 'WooPay', 'PREVIEW OF CHECKOUT', 'Configure the display of WooPay buttons', 'CALL TO ACTION', 'BUTTON SIZE', 'PREVIEW', 'Save changes' ],
		referenceTokens: [ 'WooPay', 'PREVIEW OF CHECKOUT', 'Configure the display of WooPay buttons', 'CALL TO ACTION', 'BUTTON SIZE', 'PREVIEW', 'Save changes' ],
		targetAssets: [ 'settings-payments-woopayments-express-checkout-settings.js', 'settings-payments-woopayments-express-checkout-woopay.js' ],
	},
	{
		id: 'settings-express-payment-request',
		target: targetRoute( '/woopayments/settings/express-checkout/payment_request' ),
		reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=payment_request',
		targetTokens: [ 'Apple Pay / Google Pay', 'Configure the display of Apple Pay and Google Pay buttons', 'CALL TO ACTION', 'BUTTON SIZE', 'THEME', 'PREVIEW', 'Save changes' ],
		referenceTokens: [ 'Apple Pay / Google Pay', 'Configure the display of Apple Pay and Google Pay buttons', 'CALL TO ACTION', 'BUTTON SIZE', 'THEME', 'PREVIEW', 'Save changes' ],
		targetAssets: [ 'settings-payments-woopayments-express-checkout-settings.js', 'settings-payments-woopayments-express-checkout-payment-request.js' ],
	},
	{
		id: 'settings-express-amazon-pay',
		target: targetRoute( '/woopayments/settings/express-checkout/amazon_pay' ),
		reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=amazon_pay',
		targetTokens: [ 'Amazon Pay', 'Configure the display of Amazon Pay buttons', 'BUTTON SIZE', 'Save changes' ],
		referenceTokens: [ 'Amazon Pay', 'Configure the display of Amazon Pay buttons', 'BUTTON SIZE', 'Save changes' ],
		targetAssets: [ 'settings-payments-woopayments-express-checkout-settings.js', 'settings-payments-woopayments-express-checkout-amazon-pay.js' ],
	},
	{
		id: 'fraud-protection',
		target: targetRoute( '/woopayments/settings/fraud-protection' ),
		reference: referenceRoute( '/payments/fraud-protection' ),
		targetTokens: [ 'Advanced fraud protection', 'Filter configuration', 'Address Mismatch', 'CVC Verification', 'Save changes' ],
		referenceTokens: [ 'Advanced fraud protection', 'Filter configuration', 'Address Mismatch', 'CVC Verification', 'Save changes' ],
		targetAssets: [ 'settings-payments-woopayments-fraud-protection-settings.js' ],
	},
	{
			id: 'overview',
			target: targetRoute( '/woopayments/overview' ),
			protectedPath: '/woopayments/overview',
			reference: referenceRoute( '/payments/overview' ),
		targetTokens: [ 'Overview', 'Balance', 'Payouts', 'Account details', 'WooPayments settings' ],
		referenceTokens: [ 'Overview', 'Balance', 'Payouts', 'Account details' ],
		targetAssets: [ 'settings-payments-woopayments-overview.js' ],
	},
	{
			id: 'payouts',
			target: targetRoute( '/woopayments/payouts' ),
			protectedPath: '/woopayments/payouts',
			reference: referenceRoute( '/payments/payouts' ),
		targetTokens: [ 'Payout history', 'Search payouts', 'Download payouts' ],
		referenceTokens: [ 'Payouts', 'Payout history', 'Export' ],
		referenceMobileTokens: [ 'Payouts', 'Payout history' ],
		targetAssets: [ 'settings-payments-woopayments-payouts.js' ],
	},
	{
			id: 'transactions',
			target: targetRoute( '/woopayments/transactions' ),
			protectedPath: '/woopayments/transactions',
			reference: referenceRoute( '/payments/transactions' ),
		targetTokens: [ 'Transactions', 'Search transactions', 'Download transactions' ],
		referenceTokens: [ 'Transactions', 'Export' ],
		referenceMobileTokens: [ 'Transactions', 'Uncaptured' ],
		targetAssets: [ 'settings-payments-woopayments-money-movement.js' ],
	},
	{
			id: 'uncaptured',
			target: targetRoute( '/woopayments/transactions', '&view=uncaptured' ),
			protectedPath: '/woopayments/transactions',
			reference: referenceRoute( '/payments/transactions', '&tab=uncaptured' ),
		targetTokens: [ 'Uncaptured transactions', 'Search uncaptured transactions' ],
		referenceTokens: [ 'Transactions', 'Uncaptured' ],
		targetAssets: [ 'settings-payments-woopayments-money-movement.js' ],
	},
	{
			id: 'disputes',
			target: targetRoute( '/woopayments/disputes' ),
			protectedPath: '/woopayments/disputes',
			reference: referenceRoute( '/payments/disputes' ),
		targetTokens: [ 'Disputes', 'Search disputes', 'Download disputes' ],
		referenceTokens: [ 'Disputes', 'Export' ],
		referenceMobileTokens: [ 'Disputes' ],
		targetAssets: [ 'settings-payments-woopayments-money-movement.js' ],
	},
	{
			id: 'documents',
			target: targetRoute( '/woopayments/documents' ),
			protectedPath: '/woopayments/documents',
			reference: referenceRoute( '/payments/documents' ),
		targetTokens: [ 'Documents' ],
		referenceTokens: [ 'Documents' ],
		referenceOptional: 'Reference documents route is account/feature gated in this local account.',
		targetAssets: [ 'settings-payments-woopayments-documents.js' ],
	},
	{
			id: 'reports-fees',
			target: targetRoute( '/woopayments/reports', '&report=fees' ),
			protectedPath: '/woopayments/reports',
			reference: referenceRoute( '/payments/reports', '&report=fees' ),
		targetTokens: [ 'Reports', 'Fees', 'Search' ],
		referenceTokens: [ 'Reports', 'Fees', 'Export' ],
		targetAssets: [ 'settings-payments-woopayments-reports.js' ],
	},
	{
			id: 'card-readers',
			target: targetRoute( '/woopayments/card-readers' ),
			protectedPath: '/woopayments/card-readers',
			reference: referenceRoute( '/payments/card-readers' ),
		targetTokens: [ 'Connected card readers' ],
		referenceTokens: [ 'Connected card readers' ],
		referenceOptional: 'Reference card readers route is account/feature gated in this local account.',
		targetAssets: [ 'settings-payments-woopayments-card-readers.js' ],
	},
	{
			id: 'capital',
			target: targetRoute( '/woopayments/loans' ),
			protectedPath: '/woopayments/loans',
			reference: referenceRoute( '/payments/loans' ),
		targetTokens: [ 'Capital Loans' ],
		referenceTokens: [ 'Capital Loans' ],
		referenceOptional: 'Reference capital route is account/feature gated in this local account.',
		targetAssets: [ 'settings-payments-woopayments-capital.js' ],
	},
];

const viewports = [
	{ id: 'desktop', width: 1440, height: 1100 },
	{ id: 'mobile', width: 390, height: 844 },
];

function toOptionalSelectionSet( values ) {
	return Array.isArray( values ) && values.length > 0 ? new Set( values ) : null;
}

function assertKnownSelection( label, requestedSet, knownValues ) {
	if ( ! requestedSet ) {
		return;
	}

	const knownSet = new Set( knownValues );
	const unknown = [ ...requestedSet ].filter( ( id ) => ! knownSet.has( id ) );
	if ( unknown.length > 0 ) {
		throw new Error( `${ label } filter contains unknown IDs: ${ unknown.join( ', ' ) }. Known IDs: ${ knownValues.join( ', ' ) }.` );
	}
}

const selectedSurfaceIds = toOptionalSelectionSet( state.surfaceIds );
const selectedViewportIds = toOptionalSelectionSet( state.viewportIds );
const selectedStoreIds = toOptionalSelectionSet( state.storeIds );
const strictReferenceOptional = state.strictReferenceOptional === true;
assertKnownSelection( 'Surface', selectedSurfaceIds, surfaces.map( ( surface ) => surface.id ) );
assertKnownSelection( 'Viewport', selectedViewportIds, viewports.map( ( viewport ) => viewport.id ) );
assertKnownSelection( 'Store', selectedStoreIds, [ 'target', 'reference' ] );
const selectedSurfaces = selectedSurfaceIds ? surfaces.filter( ( surface ) => selectedSurfaceIds.has( surface.id ) ) : surfaces;
const selectedViewports = selectedViewportIds ? viewports.filter( ( viewport ) => selectedViewportIds.has( viewport.id ) ) : viewports;
const selectedStores = selectedStoreIds ? [ 'target', 'reference' ].filter( ( store ) => selectedStoreIds.has( store ) ) : [ 'target', 'reference' ];

if ( selectedSurfaces.length === 0 || selectedViewports.length === 0 || selectedStores.length === 0 ) {
	throw new Error( 'A4 admin browser gate produced no selected work. Check surfaceIds, viewportIds, and storeIds.' );
}

const requiredTargetNavigationRoutes = [
	'/woopayments/overview',
	'/woopayments/payouts',
	'/woopayments/transactions',
	'/woopayments/disputes',
	'/woopayments/settings',
];

const reducedTargetNavigationRoutes = [
	'/woopayments/overview',
	'/woopayments/transactions',
	'/woopayments/disputes',
];

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

function isIgnoredFailedResponse( store, surface, failure ) {
	return (
		store === 'reference' &&
		/^settings-express-/.test( surface.id ) &&
		/wp-admin\/null\/connect\//.test( failure.url )
	);
}

function isConsoleIssue( issue ) {
	return [ 'error', 'warning' ].includes( issue.type ) || /fatal|exception/i.test( issue.text );
}

function isIgnoredConsoleIssue( store, text ) {
	if ( /JQMIGRATE|Permissions policy violation: unload/i.test( text ) ) {
		return true;
	}

	if ( store === 'reference' && /useSelect/i.test( text ) ) {
		return true;
	}

	if (
		store === 'reference' &&
		(
			/^\[Stripe\.js\] It looks like Stripe\.js was loaded more than one time\./.test( text ) ||
			/^You may test your (Stripe\.js )?integration over HTTP\. However, live (Stripe\.js )?integrations must use HTTPS\./.test( text ) ||
			/^Failed to load resource: the server responded with a status of 404 \(Not Found\)/.test( text ) ||
			/^Failed to load resource: net::ERR_PROXY_CONNECTION_FAILED$/.test( text ) ||
			/^woocommerce_admin_onboarding_task_list is deprecated and will be removed from @woocommerce\/data/.test( text )
		)
	) {
		return true;
	}

	return (
		store === 'reference' &&
		/Each child in a list should have a unique "key" prop|findDOMNode is deprecated/.test( text )
	);
}

function isIgnoredPageError( store, surface, error ) {
	return (
		store === 'reference' &&
		/^settings-express-/.test( surface.id ) &&
		error.message === "Failed to construct 'URL': Invalid URL"
	);
}

async function readBodyTextAfterRequiredTokensSettle( page, expectedTokens, strict ) {
	const deadline = Date.now() + ( strict ? 10000 : 2000 );
	let mainText = '';

	do {
		mainText = await page.locator( 'body' ).innerText( { timeout: 20000 } );
		if ( ! strict || expectedTokens.every( ( token ) => mainText.includes( token ) ) ) {
			return mainText;
		}
		await page.waitForTimeout( 500 );
	} while ( Date.now() < deadline );

	return mainText;
}

async function readTargetRouteAvailability( page ) {
	return page.evaluate( () => {
		const availability =
			globalThis.wcSettings?.admin?.woopaymentsSettings
				?.adminRouteAvailability || null;

		if ( ! availability || typeof availability !== 'object' ) {
			return null;
		}

		return availability;
	} );
}

function isTargetSurfaceAvailable( surface, routeAvailability ) {
	if ( ! surface.protectedPath ) {
		return true;
	}

	const allowedRoutes = routeAvailability?.allowedRoutes;
	if ( ! allowedRoutes || typeof allowedRoutes !== 'object' ) {
		return true;
	}

	return allowedRoutes[ surface.protectedPath ] !== false;
}

async function getOrCreatePage() {
	if ( state.page && ! state.page.isClosed() ) {
		return state.page;
	}
	state.page = context.pages().find( ( candidate ) => candidate.url() === 'about:blank' ) || ( await context.newPage() );
	return state.page;
}

async function checkRoute( page, store, surface, viewport ) {
	const url = store === 'target' ? surface.target : surface.reference;
	const tokenKey = `${ store }${ viewport.id === 'mobile' ? 'Mobile' : '' }Tokens`;
	const fallbackTokenKey = `${ store }Tokens`;
	const expectedTokens = surface[ tokenKey ] || surface[ fallbackTokenKey ];
	const strictTokens = ! (
		store === 'reference' &&
		surface.referenceOptional &&
		! strictReferenceOptional
	);
	const failedResponses = [];
	const ignoredFailedResponses = [];
	const consoleIssues = [];
	const ignoredConsoleIssues = [];
	const pageErrors = [];

	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await page.waitForTimeout( 100 );

	const responseHandler = ( response ) => {
		if ( interestingFailure( response ) ) {
			const failure = { status: response.status(), url: response.url() };
			if ( isIgnoredFailedResponse( store, surface, failure ) ) {
				ignoredFailedResponses.push( failure );
				return;
			}
			failedResponses.push( failure );
		}
	};
	const consoleHandler = ( message ) => {
		const text = message.text();
		const issue = { type: message.type(), text };
		if ( isConsoleIssue( issue ) ) {
			if ( isIgnoredConsoleIssue( store, text ) ) {
				ignoredConsoleIssues.push( issue );
				return;
			}
			consoleIssues.push( issue );
		}
	};
	const pageErrorHandler = ( error ) => {
		const issue = {
			message: error.message,
			stack: error.stack || '',
		};
		if ( isIgnoredPageError( store, surface, issue ) ) {
			return;
		}
		pageErrors.push( issue );
	};

	page.on( 'response', responseHandler );
	page.on( 'console', consoleHandler );
	page.on( 'pageerror', pageErrorHandler );
	await page.setViewportSize( { width: viewport.width, height: viewport.height } );
	await page.evaluate( () => performance.clearResourceTimings() );
	await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	const routeAvailability = store === 'target' ? await readTargetRouteAvailability( page ) : null;
	const targetSurfaceAvailable = store !== 'target' || isTargetSurfaceAvailable( surface, routeAvailability );
	const targetExpectedTokens = targetSurfaceAvailable ? expectedTokens : [ unavailableRouteToken ];
	const mainText = await readBodyTextAfterRequiredTokensSettle( page, targetExpectedTokens, strictTokens );
	const missingTokens = expectedTokens.filter( ( token ) => ! mainText.includes( token ) );
	const unavailableTokenMissing = ! targetSurfaceAvailable && ! mainText.includes( unavailableRouteToken );
	const unmetRequiredTokens = targetSurfaceAvailable && strictTokens ? missingTokens : [];
	const scripts = await page.locator( 'script[src]' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.getAttribute( 'src' ) ).filter( Boolean ) );
	const resources = await page.evaluate( () => performance.getEntriesByType( 'resource' ).map( ( entry ) => entry.name ) );
	const nativeAssetSources = scripts.concat( resources ).filter( ( src ) => /settings-payments-woopayments|woocommerce-payments\/dist|wcpay-/i.test( src ) );
	const expectedTargetAssets = store === 'target' ? surface.targetAssets || [] : [];
	const missingTargetAssets = targetSurfaceAvailable ? expectedTargetAssets.filter( ( asset ) => ! nativeAssetSources.some( ( source ) => source.includes( asset ) ) ) : [];
	const forbiddenTargetAssets = ! targetSurfaceAvailable ? expectedTargetAssets.filter( ( asset ) => nativeAssetSources.some( ( source ) => source.includes( asset ) ) ) : [];
	const screenshotPath = path.join( dataDir, `${ store }-${ surface.id }-${ viewport.id }.png` );
	await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );
	page.off( 'response', responseHandler );
	page.off( 'console', consoleHandler );
	page.off( 'pageerror', pageErrorHandler );

	return {
		store,
		surface: surface.id,
		viewport: viewport.id,
		requestedUrl: url,
		finalUrl: page.url(),
			expectedTokens,
			missingTokens,
			unmetRequiredTokens,
			unavailableTokenMissing,
			strictTokens,
			strictReferenceOptional,
			referenceOptional: store === 'reference' ? surface.referenceOptional || null : null,
			routeAvailability,
			targetSurfaceAvailable,
			coverageStatus:
				store === 'target' && ! targetSurfaceAvailable
					? 'unavailable-guard-pass'
					: 'available-route-pass',
		failedResponses,
		ignoredFailedResponses,
		consoleIssues,
		ignoredConsoleIssues,
		pageErrors,
		scripts: scripts.filter( ( src ) => /settings-payments-woopayments|woocommerce-payments\/dist|wcpay-/i.test( src ) ),
		resources: resources.filter( ( src ) => /settings-payments-woopayments|woocommerce-payments\/dist|wcpay-/i.test( src ) ),
			expectedTargetAssets,
			missingTargetAssets,
			forbiddenTargetAssets,
			targetAssetNote: store === 'target' ? surface.targetAssetNote || null : null,
			screenshotPath,
			passed: unmetRequiredTokens.length === 0 && ! unavailableTokenMissing && missingTargetAssets.length === 0 && forbiddenTargetAssets.length === 0 && failedResponses.length === 0 && consoleIssues.length === 0 && pageErrors.length === 0,
		};
}

async function checkTargetNavigation( page ) {
	await page.setViewportSize( { width: 1440, height: 1100 } );
	const requestedUrl = targetBase + encodeURIComponent( '/woopayments/overview' );
	await page.goto( requestedUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	const hrefs = await page.locator( '#adminmenu a[href]' ).evaluateAll( ( links ) => links.map( ( link ) => link.getAttribute( 'href' ) || '' ) );
	const routeAvailability = await readTargetRouteAvailability( page );
	const accountState = routeAvailability?.accountState || 'unknown';
	const expectedRoutes = accountState === 'restricted' ? reducedTargetNavigationRoutes : requiredTargetNavigationRoutes;
	const unavailableRoutesPresent = Object.entries( routeAvailability?.allowedRoutes || {} )
		.filter( ( [ , available ] ) => available === false )
		.map( ( [ route ] ) => route )
		.filter( ( route ) => hrefs.some( ( href ) => href.includes( encodeURIComponent( route ) ) || href.includes( route ) ) );
	const missingRoutes = expectedRoutes.filter( ( route ) => ! hrefs.some( ( href ) => href.includes( encodeURIComponent( route ) ) || href.includes( route ) ) );

	return {
		store: 'target',
		surface: 'admin-navigation',
		viewport: 'desktop',
		requestedUrl,
		finalUrl: page.url(),
		requiredRoutes: expectedRoutes,
		routeAvailability,
		unavailableRoutesPresent,
		missingRoutes,
		passed: missingRoutes.length === 0 && unavailableRoutesPresent.length === 0,
	};
}

ensureDir( dataDir );
const page = await getOrCreatePage();
const startedAt = new Date().toISOString();
const results = [];

function writeEvidence( status = 'running' ) {
	const failures = results.filter( ( result ) => ! result.passed );
	const evidence = {
		gate: `${ gateSlug }-admin-browser-gate`,
		gateSlug,
		status,
		startedAt,
		lastUpdatedAt: new Date().toISOString(),
		surfaces: selectedSurfaces.map( ( surface ) => surface.id ),
		viewports: selectedViewports.map( ( viewport ) => viewport.id ),
		stores: selectedStores,
		results,
		failures,
	};
	fs.writeFileSync( evidencePath, `${ JSON.stringify( evidence, null, 2 ) }\n` );
	return evidence;
}

function recordResult( result ) {
	results.push( result );
	const evidence = writeEvidence();
	console.log(
		JSON.stringify(
			{
				status: result.passed ? 'PASS' : 'FAIL',
				checked: results.length,
				failures: evidence.failures.length,
				store: result.store,
				surface: result.surface,
				viewport: result.viewport,
				evidencePath,
			},
			null,
			2
		)
	);
}

if ( state.skipNavigation === true && state.allowSkipNavigation !== true ) {
	throw new Error( 'A4 admin browser gate refuses stale state.skipNavigation without explicit state.allowSkipNavigation.' );
}

if ( ! ( state.allowSkipNavigation === true && state.skipNavigation === true ) && selectedStores.includes( 'target' ) ) {
	recordResult( await checkTargetNavigation( page ) );
}

for ( const viewport of selectedViewports ) {
	for ( const surface of selectedSurfaces ) {
		for ( const store of selectedStores ) {
			recordResult( await checkRoute( page, store, surface, viewport ) );
		}
	}
}

const finalEvidence = writeEvidence( 'complete' );
console.log( JSON.stringify( { evidencePath, checked: results.length, failures: finalEvidence.failures.length }, null, 2 ) );
if ( results.length === 0 ) {
	throw new Error( `A4 admin browser gate produced no route checks. See ${ evidencePath }` );
}
if ( finalEvidence.failures.length > 0 ) {
	throw new Error( `A4 admin browser gate failed with ${ finalEvidence.failures.length } route checks failing. See ${ evidencePath }` );
}
