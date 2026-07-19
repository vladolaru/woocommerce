#!/usr/bin/env node
/**
 * Run the merge harness browser evidence scripts under Playwright.
 *
 * Browser scenarios use a small injected interface containing globals such as
 * `context`, `state`, `waitForPageLoad`, `getLatestLogs`, and `snapshot`. This
 * runner supplies that interface inside an isolated Playwright process.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath( import.meta.url );
const __dirname = path.dirname( __filename );
const require = createRequire( import.meta.url );

function usage() {
	console.error( 'usage: playwright-script-runner.mjs <script> [--timeout <ms>]' );
	process.exit( 2 );
}

function parseArgs( argv ) {
	const args = [ ...argv ];
	let script = '';
	let timeout = 300000;

	while ( args.length > 0 ) {
		const arg = args.shift();
		if ( arg === '--timeout' ) {
			timeout = Number( args.shift() || '' );
		} else if ( arg?.startsWith( '--timeout=' ) ) {
			timeout = Number( arg.slice( '--timeout='.length ) );
		} else if ( ! script ) {
			script = arg || '';
		} else {
			usage();
		}
	}

	if ( ! script || ! Number.isFinite( timeout ) || timeout <= 0 ) {
		usage();
	}

	return { script: path.resolve( script ), timeout };
}

function repoRoot() {
	return path.resolve( __dirname, '../..' );
}

function loadPlaywright() {
	const roots = [
		path.join( repoRoot(), 'plugins/woocommerce' ),
		repoRoot(),
		process.cwd(),
	];
	const resolved = require.resolve( '@playwright/test', { paths: roots } );
	return require( resolved );
}

function loadInitialState() {
	const raw = process.env.PLAYWRIGHT_RUNNER_STATE_JSON || '{}';
	try {
		const parsed = JSON.parse( raw );
		if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
			throw new Error( 'state must be a JSON object' );
		}
		return parsed;
	} catch ( error ) {
		throw new Error( `Invalid PLAYWRIGHT_RUNNER_STATE_JSON: ${ error.message }` );
	}
}

function loadAdminCredentials() {
	const byHost = {};
	const raw = process.env.WP_ADMIN_CREDENTIALS_JSON || '';
	if ( raw ) {
		try {
			const parsed = JSON.parse( raw );
			if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
				throw new Error( 'credentials must be a JSON object' );
			}
			for ( const [ key, value ] of Object.entries( parsed ) ) {
				if ( value && typeof value === 'object' && typeof value.user === 'string' && typeof value.password === 'string' ) {
					byHost[ key ] = value;
				}
			}
		} catch ( error ) {
			throw new Error( `Invalid WP_ADMIN_CREDENTIALS_JSON: ${ error.message }` );
		}
	}

	const fallback = {
		user: process.env.WP_ADMIN_USER || '',
		password: process.env.WP_ADMIN_PASSWORD || '',
	};

	return { byHost, fallback };
}

function adminCredentialsForUrl( url, credentials ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch {
		return credentials.fallback;
	}

	return (
		credentials.byHost[ parsed.origin ] ||
		credentials.byHost[ parsed.host ] ||
		credentials.byHost[ parsed.hostname ] ||
		credentials.fallback
	);
}

function isIgnoredBrowserConsoleMessage( text ) {
	return /^\[\.WebGL-[^\]]+\]GL Driver Message .*ReadPixels/i.test( text );
}

function attachLogCapture( page, pageLogs, lastReadIndex ) {
	if ( pageLogs.has( page ) ) {
		return page;
	}

	const logs = [];
	pageLogs.set( page, logs );
	lastReadIndex.set( page, 0 );

	page.on( 'console', ( message ) => {
		const text = message.text();
		if ( isIgnoredBrowserConsoleMessage( text ) ) {
			return;
		}
		const type = message.type() === 'error' && /^Warning:/i.test( text ) ? 'warning' : message.type();
		logs.push( {
			type,
			text,
			location: message.location(),
		} );
	} );
	page.on( 'pageerror', ( error ) => {
		logs.push( {
			type: 'pageerror',
			text: error?.message || String( error ),
			stack: error?.stack || '',
		} );
	} );
	page.on( 'requestfailed', ( request ) => {
		logs.push( {
			type: 'requestfailed',
			text: `${ request.failure()?.errorText || 'request failed' }: ${ request.url() }`,
			url: request.url(),
		} );
	} );

	return page;
}

function installContextInstrumentation( context, credentials ) {
	const pageLogs = new WeakMap();
	const lastReadIndex = new WeakMap();
	const originalNewPage = context.newPage.bind( context );

	context.newPage = async () => installPageHelpers( attachLogCapture( await originalNewPage(), pageLogs, lastReadIndex ), credentials );
	for ( const page of context.pages() ) {
		installPageHelpers( attachLogCapture( page, pageLogs, lastReadIndex ), credentials );
	}

	return {
		async getLatestLogs( { page, sinceLastCall = false } = {} ) {
			if ( ! page ) {
				return [];
			}
			attachLogCapture( page, pageLogs, lastReadIndex );
			const logs = pageLogs.get( page ) || [];
			const start = sinceLastCall ? lastReadIndex.get( page ) || 0 : 0;
			lastReadIndex.set( page, logs.length );
			return logs.slice( start );
		},
	};
}

async function maybeLoginToWpAdmin( page, credentials ) {
	const { user, password } = adminCredentialsForUrl( page.url(), credentials );
	if ( ! user || ! password ) {
		return;
	}

	const loginForm = page.locator( '#loginform, form[name="loginform"]' ).first();
	if ( ( await loginForm.count().catch( () => 0 ) ) === 0 ) {
		return;
	}
	const currentUrl = page.url();
	const redirectsToAdmin = /[?&]redirect_to=[^&]*wp-admin/i.test( currentUrl );
	const isWpLogin = /\/wp-login\.php\b/i.test( currentUrl );
	if ( ! redirectsToAdmin && ! isWpLogin ) {
		return;
	}

	await page.locator( '#user_login, input[name="log"]' ).first().fill( user );
	await page.locator( '#user_pass, input[name="pwd"]' ).first().fill( password );
	await Promise.all( [
		page.waitForLoadState( 'domcontentloaded', { timeout: 20000 } ).catch( () => {} ),
		page.locator( '#wp-submit, input[type="submit"]' ).first().click(),
	] );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } );
}

async function maybeLoginToWooAccount( page, credentials ) {
	const currentUrl = page.url();
	if ( ! /(?:[?&]add-payment-method=1|\/my-account\/?)/i.test( currentUrl ) ) {
		return;
	}

	const { user, password } = adminCredentialsForUrl( currentUrl, credentials );
	if ( ! user || ! password ) {
		return;
	}

	const username = page.locator( 'form.woocommerce-form-login input[name="username"], form.login input[name="username"]' ).first();
	if ( ( await username.count().catch( () => 0 ) ) === 0 ) {
		return;
	}

	await username.fill( user );
	await page.locator( 'form.woocommerce-form-login input[name="password"], form.login input[name="password"]' ).first().fill( password );
	await Promise.all( [
		page.waitForLoadState( 'domcontentloaded', { timeout: 20000 } ).catch( () => {} ),
		page.locator( 'form.woocommerce-form-login button[name="login"], form.login button[name="login"], form.woocommerce-form-login button[type="submit"], form.login button[type="submit"], input[name="login"]' ).first().click(),
	] );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } );
}

function installPageHelpers( page, credentials ) {
	if ( page.__woopaymentsMergeRunnerPatched ) {
		return page;
	}

	const originalGoto = page.goto.bind( page );
	page.goto = async ( ...args ) => {
		const response = await originalGoto( ...args );
		await maybeLoginToWpAdmin( page, credentials );
		await maybeLoginToWooAccount( page, credentials );
		return response;
	};
	page.__woopaymentsMergeRunnerPatched = true;
	return page;
}

async function waitForPageLoad( { page, timeout = 20000, minWait = 0 } = {} ) {
	if ( ! page ) {
		return;
	}
	await page.waitForLoadState( 'domcontentloaded', { timeout } ).catch( () => {} );
	await page.waitForLoadState( 'load', { timeout: Math.min( timeout, 10000 ) } ).catch( () => {} );
	await page.waitForLoadState( 'networkidle', { timeout: Math.min( timeout, 5000 ) } ).catch( () => {} );
	if ( minWait > 0 ) {
		await page.waitForTimeout( minWait );
	}
}

async function snapshot( { page, search } = {} ) {
	if ( ! page ) {
		return '';
	}
	const text = await page.locator( 'body' ).innerText( { timeout: 5000 } ).catch( async () => {
		return page.content().catch( () => '' );
	} );
	if ( ! search ) {
		return text;
	}
	return text
		.split( /\r?\n/ )
		.filter( ( line ) => search.test( line ) )
		.join( '\n' );
}

async function getCDPSession( { page } = {} ) {
	if ( ! page ) {
		throw new Error( 'getCDPSession requires a page' );
	}
	return page.context().newCDPSession( page );
}

async function runScript( scriptPath, globals ) {
	const source = fs.readFileSync( scriptPath, 'utf8' );
	const scriptDir = path.dirname( scriptPath );
	const scriptRequire = createRequire( scriptPath );
	const wrapped = `( async ( require, process, console, __filename, __dirname, context, state, waitForPageLoad, getLatestLogs, snapshot, getCDPSession ) => {\n${ source }\n} )`;
	const fn = vm.runInThisContext( wrapped, { filename: scriptPath } );
	return fn(
		scriptRequire,
		process,
		console,
		scriptPath,
		scriptDir,
		globals.context,
		globals.state,
		globals.waitForPageLoad,
		globals.getLatestLogs,
		globals.snapshot,
		globals.getCDPSession
	);
}

async function main() {
	const { script, timeout } = parseArgs( process.argv.slice( 2 ) );
	const { chromium } = loadPlaywright();
	const browser = await chromium.launch( {
		headless: process.env.PLAYWRIGHT_HEADLESS !== '0',
	} );
	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const credentials = loadAdminCredentials();
	const { getLatestLogs } = installContextInstrumentation( context, credentials );
	const state = loadInitialState();

	globalThis.context = context;
	globalThis.state = state;
	globalThis.waitForPageLoad = waitForPageLoad;
	globalThis.getLatestLogs = getLatestLogs;
	globalThis.snapshot = snapshot;
	globalThis.getCDPSession = getCDPSession;

	let timer;
	try {
		await Promise.race( [
			runScript( script, {
				context,
				state,
				waitForPageLoad,
				getLatestLogs,
				snapshot,
				getCDPSession,
			} ),
			new Promise( ( _, reject ) => {
				timer = setTimeout( () => reject( new Error( `Playwright script timed out after ${ timeout }ms` ) ), timeout );
			} ),
		] );
	} finally {
		clearTimeout( timer );
		await context.close().catch( () => {} );
		await browser.close().catch( () => {} );
	}
}

main().catch( ( error ) => {
	console.error( error?.stack || error?.message || String( error ) );
	process.exit( 1 );
} );
