// SEPA token-continuity browser evidence driver.
//
// This driver runs inside an authenticated local Playwriter session. It saves a
// SEPA token while the WooPayments plugin is active, then verifies that the same
// token renders in My Account after the shell gate cuts the store over to native
// WooPayments. Evidence stays fail-closed unless browser-observed semantics
// satisfy token-continuity-gate.sh.

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
const sepaTestIban = env.TOKEN_CONTINUITY_GATE_TEST_IBAN || 'AT611904300234573201';

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
		if ( ! tokenId || tokenId <= 0 ) {
			throw new Error( 'render_payment_methods requires TOKEN_CONTINUITY_GATE_TOKEN_ID.' );
		}
		return `${ baseUrl }/my-account/payment-methods/?token-continuity-gate=render-sepa&token_id=${ encodeURIComponent(
			tokenId
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
				status: 'fail',
				phase,
				base_url: baseUrl,
				url: phaseUrl(),
				method,
				gateway_id: gatewayId,
				stripe_payment_method_type: stripePaymentMethodType,
				token_type: tokenType,
				customer_id: customerId,
				token_id: tokenId,
				selected_gateway_id: null,
				payment_method_id: null,
				token_visible: false,
				started_at: startedAt,
				last_updated_at: new Date().toISOString(),
				failures: [],
				...payload,
			},
			null,
			2
		) }\n`,
		'utf8'
	);
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

function isFatalConsoleError( log ) {
	const text = logText( log );
	const type = logType( log );
	if ( /JQMIGRATE|Permissions policy violation: unload/i.test( text ) ) {
		return false;
	}
	return type === 'error' || type === 'pageerror' || /uncaught|fatal|exception|typeerror|referenceerror/i.test( text );
}

function paymentMethodIdsFromText( text ) {
	const matches = String( text || '' ).match( /\bpm_[A-Za-z0-9_]+\b/g ) || [];
	return [ ...new Set( matches ) ];
}

async function readHiddenPaymentMethodIds( page ) {
	return page.evaluate( () =>
		Array.from( document.querySelectorAll( '#wcpay-payment-method, [name="wcpay-payment-method"]' ) )
			.map( ( element ) => element.value || element.getAttribute( 'value' ) || '' )
			.filter( ( value ) => /^pm_/.test( value ) )
	).catch( () => [] );
}

async function countLocator( page, selector ) {
	try {
		return await page.locator( selector ).count();
	} catch ( error ) {
		return 0;
	}
}

async function firstVisibleLocator( roots, selectors ) {
	for ( const root of roots ) {
		for ( const selector of selectors ) {
			const locator = root.locator( selector ).first();
			try {
				if ( ( await locator.count() ) > 0 && ( await locator.isVisible().catch( () => true ) ) ) {
					return { locator, selector };
				}
			} catch ( error ) {
				// Some selectors are intentionally broad; continue with the next candidate.
			}
		}
	}
	return null;
}

async function maybeClick( roots, selectors ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	await candidate.locator.click( { timeout: 10000 } );
	return true;
}

async function maybeCheck( roots, selectors ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	const tagName = await candidate.locator.evaluate( ( element ) => element.tagName.toLowerCase() ).catch( () => '' );
	const type = await candidate.locator.getAttribute( 'type' ).catch( () => '' );
	if ( tagName === 'input' && /checkbox|radio/i.test( type || '' ) ) {
		await candidate.locator.check( { timeout: 10000 } );
		return true;
	}
	await candidate.locator.click( { timeout: 10000 } );
	return true;
}

async function maybeFill( roots, selectors, value ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	await candidate.locator.fill( value, { timeout: 10000 } );
	return true;
}

async function maybeSelect( roots, selectors, values ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	for ( const value of values ) {
		try {
			await candidate.locator.selectOption( value, { timeout: 10000 } );
			return true;
		} catch ( error ) {
			// Try the next compatible value.
		}
	}
	return false;
}

async function capturePageEvidence( page, extra = {} ) {
	let logs = [];
	let snapshotText = '';
	const screenshotPath = evidencePath.replace( /\.json$/, '.png' );
	const selectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		`[data-gateway-id="${ gatewayId }"]`,
		'.woocommerce-MyAccount-paymentMethods',
		'.woocommerce-PaymentMethod',
		'.payment-method',
		'a[href*="delete-payment-method"]',
		'[data-token-id]',
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
		fatal_console_errors: logs.filter( isFatalConsoleError ),
		screenshot_path: screenshotPath,
		...extra,
	};
}

async function installResponseCapture( page, paymentMethodIds, failedResponses ) {
	const handler = async ( response ) => {
		const status = response.status();
		const url = response.url();
		if ( status >= 400 && ! /favicon\.ico|load-scripts\.php.*ver=/.test( url ) ) {
			failedResponses.push( { status, url } );
		}

		if ( ! /wc-ajax|wp-json|stripe|payment|checkout|setup/i.test( url ) ) {
			return;
		}

		try {
			const text = await response.text();
			for ( const paymentMethodId of paymentMethodIdsFromText( text ) ) {
				paymentMethodIds.add( paymentMethodId );
			}
		} catch ( error ) {
			// Non-text responses are not useful for PaymentMethod extraction.
		}
	};
	page.on( 'response', handler );
	return handler;
}

async function navigateToPhaseUrl( page ) {
	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await getLatestLogs( { page, sinceLastCall: true } ).catch( () => [] );
	await page.goto( phaseUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
}

async function selectSepaGateway( page ) {
	const roots = [ page ];
	const selectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		`[data-gateway-id="${ gatewayId }"]`,
		`[data-payment-method-id="${ gatewayId }"]`,
		`[data-testid="${ gatewayId }"]`,
		'.wc_payment_methods label:has-text("SEPA Direct Debit")',
		`text=${ gatewayId }`,
		'text=/SEPA/i',
		'text=/Direct debit/i',
	];
	if ( ! ( await maybeCheck( roots, selectors ) ) ) {
		throw new Error( `SEPA gateway selector was not found for ${ gatewayId }.` );
	}
	await maybeCheck( roots, [
		`#wc-${ gatewayId }-payment-token-new`,
		`[name="wc-${ gatewayId }-payment-token"][value="new"]`,
	] );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
	return gatewayId;
}

async function fillBillingFields( page ) {
	const roots = [ page ];
	await maybeFill( roots, [ '#billing_first_name', '[name="billing_first_name"]' ], 'Token' );
	await maybeFill( roots, [ '#billing_last_name', '[name="billing_last_name"]' ], 'Continuity' );
	await maybeSelect( roots, [ '#billing_country', '[name="billing_country"]' ], [ 'DE', 'Germany' ] );
	await maybeFill( roots, [ '#billing_address_1', '[name="billing_address_1"]' ], 'Invalidenstrasse 117' );
	await maybeFill( roots, [ '#billing_city', '[name="billing_city"]' ], 'Berlin' );
	await maybeFill( roots, [ '#billing_postcode', '[name="billing_postcode"]' ], '10115' );
	await maybeFill( roots, [ '#billing_phone', '[name="billing_phone"]' ], '+493012345678' );
	await maybeFill( roots, [ '#billing_email', '[name="billing_email"]' ], 'token-continuity@example.test' );
}

async function fillSepaElement( page ) {
	const selectors = [
		'input[name="iban"]',
		'input[autocomplete="iban"]',
		'input[aria-label*="IBAN"]',
		'input[placeholder*="IBAN"]',
		'.wcpay-upe-form[data-payment-method-type="sepa_debit"] input',
		'.wcpay-upe-element[data-payment-method-type="sepa_debit"] input',
		'[data-elements-stable-field-name="iban"] input',
		'input',
	];

	for ( const frame of page.frames() ) {
		const candidate = await firstVisibleLocator( [ frame ], selectors );
		if ( ! candidate ) {
			continue;
		}

		const label = await candidate.locator.evaluate( ( element ) => {
			const input = element;
			return [
				input.getAttribute( 'name' ),
				input.getAttribute( 'autocomplete' ),
				input.getAttribute( 'aria-label' ),
				input.getAttribute( 'placeholder' ),
				input.closest( '[data-elements-stable-field-name]' )?.getAttribute( 'data-elements-stable-field-name' ),
			]
				.filter( Boolean )
				.join( ' ' );
		} ).catch( () => '' );

		if ( ! /iban|sepa/i.test( label ) && selectors.indexOf( candidate.selector ) === selectors.length - 1 ) {
			continue;
		}

		await candidate.locator.fill( sepaTestIban, { timeout: 15000 } );
		return true;
	}

	throw new Error( 'SEPA IBAN field was not found in checkout Stripe Elements frames.' );
}

async function enableReusablePaymentMethod( page ) {
	await maybeCheck( [ page ], [
		'#wc-woocommerce_payments_sepa_debit-new-payment-method',
		`#wc-${ gatewayId }-new-payment-method`,
		'[name="wc-woocommerce_payments_sepa_debit-new-payment-method"]',
		`[name="wc-${ gatewayId }-new-payment-method"]`,
		'.woocommerce-SavedPaymentMethods-saveNew input[type="checkbox"]',
		'input[name*="new-payment-method"]',
		'label:has-text("Save payment information to my account for future purchases.")',
		'text=/save.*payment method/i',
	] );
}

async function acceptCheckoutTerms( page ) {
	await maybeCheck( [ page ], [
		'#terms',
		'[name="terms"]',
		'.woocommerce-terms-and-conditions-checkbox-text',
		'text=/terms and conditions/i',
	] );
}

async function submitCheckout( page ) {
	const submitted = await maybeClick( [ page ], [
		'#place_order',
		'button[name="woocommerce_checkout_place_order"]',
		'.wc-block-components-checkout-place-order-button',
		'button:has-text("Place order")',
		'button:has-text("Pay")',
	] );
	if ( ! submitted ) {
		throw new Error( 'checkout submit button was not found.' );
	}

	await Promise.race( [
		page.waitForURL( /order-received|order-pay|checkout\/order-received/i, { timeout: 90000 } ).catch( () => null ),
		page.waitForLoadState( 'networkidle', { timeout: 90000 } ).catch( () => null ),
		waitForPageLoad( { page, timeout: 90000, minWait: 2000 } ).catch( () => null ),
	] );
}

async function paymentMethodsUrl( requestedTokenId = null ) {
	if ( requestedTokenId ) {
		return `${ baseUrl }/my-account/payment-methods/?token-continuity-gate=render-sepa&token_id=${ encodeURIComponent(
			requestedTokenId
		) }`;
	}
	return `${ baseUrl }/my-account/payment-methods/?token-continuity-gate=save-sepa-after-checkout`;
}

async function readPaymentMethods( page, requestedTokenId = null ) {
	return page.evaluate( ( expectedTokenId ) => {
		const tokenIdPattern = /(?:delete-payment-method|set-default-payment-method|payment-method)[/=](\d+)|data-token-id=["']?(\d+)/i;
		const candidates = Array.from(
			document.querySelectorAll(
				'.woocommerce-PaymentMethod, .woocommerce-MyAccount-paymentMethods tr, tr.payment-method, .payment-method, [data-token-id], a[href*="delete-payment-method"], a[href*="set-default-payment-method"]'
			)
		);
		const seen = new Set();
		const methods = candidates
			.map( ( element ) => {
				const host = element.closest( '.woocommerce-PaymentMethod, tr, .payment-method, [data-token-id]' ) || element;
				const key = host.outerHTML.slice( 0, 500 );
				if ( seen.has( key ) ) {
					return null;
				}
				seen.add( key );
				const hrefs = Array.from( host.querySelectorAll( 'a[href]' ) ).map( ( link ) => link.getAttribute( 'href' ) || '' );
				const dataTokenId = host.getAttribute( 'data-token-id' ) || '';
				const text = ( host.innerText || host.textContent || '' ).replace( /\s+/g, ' ' ).trim();
				const source = [ dataTokenId, ...hrefs, host.outerHTML ].join( ' ' );
				const match = source.match( tokenIdPattern );
				const tokenId = match ? Number.parseInt( match[ 1 ] || match[ 2 ], 10 ) : null;
				return {
					token_id: Number.isFinite( tokenId ) ? tokenId : null,
					text,
					html_sample: host.outerHTML.slice( 0, 800 ),
					looks_like_sepa: /sepa|iban|direct debit|\u2022\u2022|3201/i.test( text ),
				};
			} )
			.filter( Boolean );
		const positiveIds = methods.map( ( row ) => row.token_id ).filter( ( id ) => Number.isInteger( id ) && id > 0 );
		const requested = expectedTokenId ? Number.parseInt( expectedTokenId, 10 ) : null;
		const requestedRow = methods.find( ( row ) => row.token_id === requested );
		const sepaRow = methods.find( ( row ) => row.looks_like_sepa && Number.isInteger( row.token_id ) && row.token_id > 0 );
		const selectedTokenId =
			( requestedRow && requestedRow.token_id ) ||
			( sepaRow && sepaRow.token_id ) ||
			( positiveIds.length ? Math.max( ...positiveIds ) : null );
		const bodyText = ( document.body?.innerText || document.body?.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		return {
			token_id: selectedTokenId,
			token_visible: requested ? Boolean( requestedRow ) : Boolean( selectedTokenId ),
			requested_token_id: requested,
			methods,
			body_text_sample: bodyText.slice( 0, 1600 ),
			body_text_length: bodyText.length,
			has_login_form: Boolean( document.querySelector( '#loginform, form[name="loginform"]' ) ) || document.body?.classList.contains( 'login' ) || false,
		};
	}, requestedTokenId );
}

async function openPaymentMethodsPage( page, requestedTokenId = null ) {
	await page.goto( await paymentMethodsUrl( requestedTokenId ), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	return readPaymentMethods( page, requestedTokenId );
}

async function runSaveSepaTokenPhase( page ) {
	const paymentMethodIds = new Set();
	const failedResponses = [];
	const responseHandler = await installResponseCapture( page, paymentMethodIds, failedResponses );

	try {
		await navigateToPhaseUrl( page );
		const selectedGatewayId = await selectSepaGateway( page );
		await fillBillingFields( page );
		await fillSepaElement( page );
		await enableReusablePaymentMethod( page );
		await acceptCheckoutTerms( page );
		await submitCheckout( page );

		for ( const paymentMethodId of await readHiddenPaymentMethodIds( page ) ) {
			paymentMethodIds.add( paymentMethodId );
		}
		await page.waitForTimeout( 1500 );
		const methods = await openPaymentMethodsPage( page );
		const observedTokenId = methods.token_id;
		const paymentMethodId = [ ...paymentMethodIds ].find( ( candidate ) => /^pm_/.test( candidate ) ) || null;
		const failures = [];

		if ( ! observedTokenId || observedTokenId <= 0 ) {
			failures.push( 'saved SEPA token was not visible in My Account payment methods after checkout' );
		}
		if ( ! paymentMethodId ) {
			failures.push( 'Stripe PaymentMethod id was not observed in checkout network responses' );
		}
		if ( failedResponses.length > 0 ) {
			failures.push( 'failed browser responses were captured during checkout' );
		}

		const pageEvidence = await capturePageEvidence( page, {
			payment_methods: methods,
			observed_payment_method_ids: [ ...paymentMethodIds ],
			failed_responses: failedResponses,
		} );
		const payload = {
			status: failures.length === 0 ? 'pass' : 'fail',
			token_id: observedTokenId || 0,
			selected_gateway_id: selectedGatewayId,
			payment_method_id: paymentMethodId,
			token_visible: Boolean( methods.token_visible ),
			failures,
			page: pageEvidence,
		};
		writeEvidence( payload );
		if ( failures.length > 0 ) {
			throw new Error( `SEPA token save phase failed: ${ failures.join( '; ' ) }` );
		}
		return payload;
	} finally {
		page.off( 'response', responseHandler );
	}
}

async function runRenderPaymentMethodsPhase( page ) {
	await navigateToPhaseUrl( page );
	const methods = await readPaymentMethods( page, tokenId );
	const failures = [];
	if ( ! methods.token_visible ) {
		failures.push( `saved SEPA token ${ tokenId } was not visible in My Account payment methods` );
	}

	const pageEvidence = await capturePageEvidence( page, {
		payment_methods: methods,
	} );
	const payload = {
		status: failures.length === 0 ? 'pass' : 'fail',
		token_id: tokenId,
		token_visible: methods.token_visible,
		failures,
		page: pageEvidence,
	};
	writeEvidence( payload );
	if ( failures.length > 0 ) {
		throw new Error( `SEPA token render phase failed: ${ failures.join( '; ' ) }` );
	}
	return payload;
}

assertLocalBase( baseUrl );
writeEvidence( { status: 'running' } );

let gatePage = null;

try {
	gatePage = await context.newPage();
	if ( typeof state !== 'undefined' ) {
		state.tokenContinuityPage = gatePage;
	}

	await gatePage.setViewportSize( { width: 1280, height: 900 } );

	if ( phase === 'save_sepa_token' ) {
		await runSaveSepaTokenPhase( gatePage );
	} else if ( phase === 'render_payment_methods' ) {
		await runRenderPaymentMethodsPhase( gatePage );
	} else {
		throw new Error( `Unsupported token-continuity phase: ${ phase }` );
	}

	console.log( JSON.stringify( { evidencePath, pass: true, phase }, null, 2 ) );
} catch ( error ) {
	const fallbackEvidence = fs.existsSync( evidencePath )
		? JSON.parse( fs.readFileSync( evidencePath, 'utf8' ) )
		: {};
	const pageEvidence = gatePage
		? await capturePageEvidence( gatePage ).catch( ( captureError ) => ( {
				capture_error: safeError( captureError ),
		} ) )
		: null;
	writeEvidence( {
		...fallbackEvidence,
		status: 'fail',
		failures: [ ...( fallbackEvidence.failures || [] ), error?.message || String( error ) ],
		error: safeError( error ),
		page: fallbackEvidence.page || pageEvidence,
	} );
	console.log( JSON.stringify( { evidencePath, pass: false, error: error?.message || String( error ) }, null, 2 ) );
	throw error;
} finally {
	if ( gatePage ) {
		try {
			await gatePage.close();
		} catch ( error ) {
			// The page may already be closed if navigation crashes; keep the real failure.
		}
	}
	if ( typeof state !== 'undefined' ) {
		state.tokenContinuityPage = null;
	}
}
