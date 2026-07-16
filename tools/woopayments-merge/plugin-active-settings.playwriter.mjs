// Plugin-active WooPayments settings browser gate.
//
// Local runbook driver for the target store. It verifies that the standalone
// WooPayments plugin settings screen still renders while the plugin is active,
// and captures duplicate wc/payments/settings store registration errors.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const env = typeof process !== 'undefined' && process.env ? process.env : {};
const stateConfig =
	typeof state !== 'undefined' && state.pluginActiveSettingsConfig && 'object' === typeof state.pluginActiveSettingsConfig
		? state.pluginActiveSettingsConfig
		: {};

function requiredConfig( envName, stateName ) {
	const value = env[ envName ] || stateConfig[ stateName ];
	if ( ! value ) {
		throw new Error( `Missing required browser gate config ${ envName }` );
	}
	return value;
}

const targetUrl = requiredConfig( 'PLUGIN_SETTINGS_TARGET_URL', 'targetUrl' ).replace( /\/+$/, '' );
const settingsUrl = requiredConfig( 'PLUGIN_SETTINGS_SETTINGS_URL', 'settingsUrl' );
const evidencePath = requiredConfig( 'PLUGIN_SETTINGS_EVIDENCE_PATH', 'evidencePath' );
const dataDir = env.PLUGIN_SETTINGS_DATA_DIR || stateConfig.dataDir || path.dirname( evidencePath );
const startedAt = new Date().toISOString();

function assertLocalUrl( label, value ) {
	const parsed = new URL( value );
	const host = parsed.hostname;
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		throw new Error( `${ label } must be http(s), got ${ value }` );
	}
	if ( host !== 'localhost' && host !== '127.0.0.1' && ! host.endsWith( '.localhost' ) ) {
		throw new Error( `${ label } must stay local, got ${ value }` );
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

function logText( log ) {
	if ( typeof log === 'string' ) {
		return log;
	}
	return log?.text || log?.message || JSON.stringify( log );
}

function logType( log ) {
	if ( typeof log === 'string' ) {
		return '';
	}
	return log?.type || log?.level || '';
}

function isDuplicateStoreError( log ) {
	const text = logText( log );
	return /wc\/payments\/settings/i.test( text ) && /already\s+(?:registered|exists)|duplicate/i.test( text );
}

function isFatalConsoleError( log ) {
	const text = logText( log );
	const type = logType( log );
	if ( isDuplicateStoreError( log ) ) {
		return false;
	}
	if ( /uncaught|fatal|exception|typeerror|referenceerror/i.test( text ) ) {
		return true;
	}
	if ( /JQMIGRATE|Permissions policy violation: unload/i.test( text ) ) {
		return false;
	}
	return type === 'error' || type === 'pageerror';
}

function decodedUrlText( value ) {
	const chunks = [ value ];
	let current = value;
	for ( let index = 0; index < 3; index++ ) {
		try {
			const decoded = decodeURIComponent( current );
			if ( decoded === current ) {
				break;
			}
			chunks.push( decoded );
			current = decoded;
		} catch ( error ) {
			break;
		}
	}
	return chunks.join( ' ' );
}

function isOptionalLocalWiringResponseFailure( failure ) {
	if ( ! [ 401, 403, 500 ].includes( Number( failure.status ) ) ) {
		return false;
	}

	return /\/wc\/v3\/payments\/deposits\/overview-all\b/.test( decodedUrlText( failure.url || '' ) );
}

function writeEvidence( payload ) {
	ensureDir( path.dirname( evidencePath ) );
	fs.writeFileSync(
		evidencePath,
		`${ JSON.stringify(
			{
				schema: 'woopayments_plugin_active_settings_browser_evidence.v1',
				status: 'fail',
				target_url: targetUrl,
				settings_url: settingsUrl,
				plugin_active: true,
				authenticated_wp_admin: false,
				settings_screen_present: false,
				plugin_settings_assets_present: false,
				plugin_settings_global_present: false,
				plugin_settings_script_urls: [],
				plugin_settings_style_urls: [],
				native_settings_asset_urls: [],
				duplicate_store_errors: [],
				fatal_console_errors: [],
				failed_responses: [],
				blocked_responses: [],
				blockers: [],
				failures: [],
				started_at: startedAt,
				last_updated_at: new Date().toISOString(),
				...payload,
			},
			null,
			2
		) }\n`,
		'utf8'
	);
}

async function readAdminState( page ) {
	return page.evaluate( () => {
		const bodyText = ( document.body?.innerText || document.body?.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		const selectors = [
			'#woocommerce_woocommerce_payments_enabled',
			'[name="woocommerce_woocommerce_payments_enabled"]',
			'#woocommerce_woocommerce_payments_account_statement_descriptor',
			'[name="woocommerce_woocommerce_payments_account_statement_descriptor"]',
			'#wcpay-account-settings-container',
			'#wcpay-payment-settings-container',
			'[data-testid*="wcpay"]',
			'[data-testid*="payments-settings"]',
			'.woocommerce-payments',
			'.wcpay-settings',
			'.wcpay-settings__wrapper',
		];
		const selectorMatches = selectors
			.map( ( selector ) => ( {
				selector,
				count: document.querySelectorAll( selector ).length,
			} ) )
			.filter( ( match ) => match.count > 0 );
		let settingsStoreSelectable = false;
		try {
			settingsStoreSelectable = Boolean( window.wp?.data?.select?.( 'wc/payments/settings' ) );
		} catch ( error ) {
			settingsStoreSelectable = false;
		}

		const hasClassicSettingsText =
			/WooPayments/i.test( bodyText ) &&
			/(payment methods|account details|deposits|statement descriptor|payments accepted on checkout|test mode)/i.test( bodyText );
		const settingsScreenPresent =
			bodyText.length > 0 &&
			/WooPayments/i.test( bodyText ) &&
			( selectorMatches.length > 0 || settingsStoreSelectable || hasClassicSettingsText );
		const scriptUrls = Array.from( document.scripts )
			.map( ( script ) => script.src || '' )
			.filter( Boolean );
		const styleUrls = Array.from( document.querySelectorAll( 'link[rel="stylesheet"][href]' ) )
			.map( ( link ) => link.href || '' )
			.filter( Boolean );
		const pluginSettingsScriptUrls = scriptUrls.filter( ( url ) =>
			/\/wp-content\/plugins\/woocommerce-payments\/dist\/settings(?:\.min)?\.js(?:[?#]|$)/i.test( url )
		);
		const pluginSettingsStyleUrls = styleUrls.filter( ( url ) =>
			/\/wp-content\/plugins\/woocommerce-payments\/dist\/settings(?:\.min)?\.css(?:[?#]|$)/i.test( url )
		);
		const nativeSettingsAssetUrls = [ ...scriptUrls, ...styleUrls ].filter( ( url ) =>
			/\/wp-content\/plugins\/woocommerce\/assets\/client\/admin\/chunks\/settings-payments-woopayments/i.test( url )
		);
		const pluginSettingsGlobalPresent = Boolean(
			window.wcpaySettings && 'object' === typeof window.wcpaySettings
		);
		const pluginSettingsAssetsPresent =
			pluginSettingsScriptUrls.length > 0 && pluginSettingsGlobalPresent;

		return {
			finalUrl: window.location.href,
			title: document.title,
			bodyTextSample: bodyText.slice( 0, 1200 ),
			bodyTextLength: bodyText.length,
			hasAdminBody: document.body?.classList.contains( 'wp-admin' ) || false,
			hasAdminMenu: Boolean( document.querySelector( '#adminmenu' ) ),
			hasWpbodyContent: Boolean( document.querySelector( '#wpbody-content' ) ),
			hasLoginForm: Boolean( document.querySelector( '#loginform, form[name="loginform"]' ) ) || document.body?.classList.contains( 'login' ) || false,
			selectorMatches,
			settingsStoreSelectable,
			hasClassicSettingsText,
			settingsScreenPresent,
			pluginSettingsAssetsPresent,
			pluginSettingsGlobalPresent,
			pluginSettingsScriptUrls,
			pluginSettingsStyleUrls,
			nativeSettingsAssetUrls,
		};
	} );
}

async function captureEvidence( page, failedResponses ) {
	let logs = [];
	let snapshotText = '';
	const screenshotPath = path.join( dataDir, 'plugin-active-settings.png' );

	try {
		logs = await getLatestLogs( { page, sinceLastCall: true } );
	} catch ( error ) {
		logs = [ { type: 'playwriter-log-error', text: error?.message || String( error ) } ];
	}

	try {
		snapshotText = await snapshot( { page, search: /WooPayments|Payments|error|settings|login/i } );
	} catch ( error ) {
		snapshotText = `snapshot failed: ${ error?.message || String( error ) }`;
	}

	try {
		await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );
	} catch ( error ) {
		// Screenshot evidence is useful, but the structured browser state is primary.
	}

	const adminState = await readAdminState( page );
	const duplicateStoreErrors = logs.filter( isDuplicateStoreError );
	const fatalConsoleErrors = logs.filter( isFatalConsoleError );
	const blockedResponses = failedResponses.filter( isOptionalLocalWiringResponseFailure );
	const hardFailedResponses = failedResponses.filter( ( response ) => ! isOptionalLocalWiringResponseFailure( response ) );
	const authenticatedWpAdmin =
		! adminState.hasLoginForm &&
		adminState.hasAdminBody &&
		adminState.hasAdminMenu &&
		adminState.hasWpbodyContent &&
		adminState.finalUrl.includes( '/wp-admin/' );
	const failures = [];

	if ( ! authenticatedWpAdmin ) {
		failures.push( 'authenticated wp-admin settings page did not render' );
	}
	if ( ! adminState.settingsScreenPresent ) {
		failures.push( 'WooPayments settings screen is not present' );
	}
	if ( ! adminState.pluginSettingsAssetsPresent ) {
		failures.push( 'standalone WooPayments settings assets were not observed' );
	}
	if ( adminState.nativeSettingsAssetUrls.length > 0 ) {
		failures.push( 'native WooPayments settings assets were observed' );
	}
	if ( duplicateStoreErrors.length > 0 ) {
		failures.push( 'duplicate wc/payments/settings store registration error' );
	}
	if ( fatalConsoleErrors.length > 0 ) {
		failures.push( 'fatal browser console errors were captured' );
	}
	if ( hardFailedResponses.length > 0 ) {
		failures.push( 'failed browser responses were captured' );
	}
	const blockers =
		failures.length === 0 && blockedResponses.length > 0
			? [ 'optional WooPayments deposits overview request was unavailable in the local plugin-active fixture' ]
			: [];

	return {
		status: failures.length > 0 ? 'fail' : blockers.length > 0 ? 'blocked' : 'pass',
		authenticated_wp_admin: authenticatedWpAdmin,
		settings_screen_present: adminState.settingsScreenPresent,
		plugin_settings_assets_present: adminState.pluginSettingsAssetsPresent,
		plugin_settings_global_present: adminState.pluginSettingsGlobalPresent,
		plugin_settings_script_urls: adminState.pluginSettingsScriptUrls,
		plugin_settings_style_urls: adminState.pluginSettingsStyleUrls,
		native_settings_asset_urls: adminState.nativeSettingsAssetUrls,
		duplicate_store_errors: duplicateStoreErrors,
		fatal_console_errors: fatalConsoleErrors,
		failed_responses: hardFailedResponses,
		blocked_responses: blockedResponses,
		all_failed_responses: failedResponses,
		failures,
		blockers,
		page: adminState,
		snapshot: snapshotText,
		logs,
		screenshot_path: screenshotPath,
	};
}

function interestingFailure( response ) {
	const status = response.status();
	if ( status < 400 ) {
		return false;
	}
	return ! /favicon\.ico|load-scripts\.php.*ver=/.test( response.url() );
}

assertLocalUrl( 'target URL', targetUrl );
assertLocalUrl( 'settings URL', settingsUrl );
ensureDir( dataDir );
writeEvidence( { status: 'running' } );

let gatePage = null;
const failedResponses = [];
const responseHandler = ( response ) => {
	if ( interestingFailure( response ) ) {
		failedResponses.push( { status: response.status(), url: response.url() } );
	}
};

try {
	gatePage = await context.newPage();
	state.page = gatePage;
	await gatePage.setViewportSize( { width: 1440, height: 1100 } );
	gatePage.on( 'response', responseHandler );

	await gatePage.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await getLatestLogs( { page: gatePage, sinceLastCall: true } ).catch( () => [] );
	await gatePage.goto( settingsUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page: gatePage, timeout: 20000, minWait: 1000 } );

	const evidence = await captureEvidence( gatePage, failedResponses );
	writeEvidence( evidence );
	if ( evidence.status === 'fail' ) {
		throw new Error( `Plugin-active settings browser gate failed. See ${ evidencePath }` );
	}
	console.log( JSON.stringify( { evidencePath, pass: evidence.status === 'pass', blocked: evidence.status === 'blocked', settingsUrl }, null, 2 ) );
} catch ( error ) {
	const fallbackEvidence = fs.existsSync( evidencePath )
		? JSON.parse( fs.readFileSync( evidencePath, 'utf8' ) )
		: {};
	writeEvidence( {
		...fallbackEvidence,
		status: 'fail',
		failures: [ ...( fallbackEvidence.failures || [] ), error?.message || String( error ) ],
		error: safeError( error ),
	} );
	console.log( JSON.stringify( { evidencePath, pass: false, error: error?.message || String( error ) }, null, 2 ) );
	throw error;
} finally {
	if ( gatePage ) {
		gatePage.off( 'response', responseHandler );
		try {
			await gatePage.close();
		} catch ( error ) {
			// The page may already be closed if navigation crashes; keep the real failure.
		}
	}
	state.page = null;
}
