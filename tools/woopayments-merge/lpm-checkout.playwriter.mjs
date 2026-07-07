// WooPayments local payment-method checkout driver.
//
// This script runs inside an authenticated local Playwriter session. It drives
// one requested split WooPayments gateway through checkout and writes evidence
// that lpm-checkout-gate.sh validates for both the reference plugin store and
// native target store.

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

const methodLabels = {
	affirm: [ 'Affirm' ],
	afterpay_clearpay: [ 'Afterpay', 'Clearpay' ],
	alipay: [ 'Alipay' ],
	au_becs_debit: [ 'BECS', 'AU BECS', 'Direct Debit' ],
	bancontact: [ 'Bancontact' ],
	eps: [ 'EPS' ],
	grabpay: [ 'GrabPay' ],
	ideal: [ 'iDEAL', 'Ideal' ],
	klarna: [ 'Klarna' ],
	multibanco: [ 'Multibanco' ],
	p24: [ 'Przelewy24', 'P24' ],
	sepa_debit: [ 'SEPA', 'SEPA Direct Debit', 'Direct debit' ],
	wechat_pay: [ 'WeChat Pay', 'WeChat' ],
};

const billingByCountry = {
	AT: {
		address_1: 'Mariahilfer Strasse 1',
		city: 'Vienna',
		postcode: '1060',
		state: '',
	},
	AU: {
		address_1: '123 Collins St',
		city: 'Melbourne',
		postcode: '3000',
		state: 'VIC',
	},
	BE: {
		address_1: 'Rue de la Loi 16',
		city: 'Brussels',
		postcode: '1000',
		state: '',
	},
	NL: {
		address_1: 'Damrak 1',
		city: 'Amsterdam',
		postcode: '1012LG',
		state: '',
	},
	PL: {
		address_1: 'Marszalkowska 1',
		city: 'Warsaw',
		postcode: '00-001',
		state: '',
	},
	PT: {
		address_1: 'Avenida da Liberdade 1',
		city: 'Lisbon',
		postcode: '1250-096',
		state: '',
	},
	SG: {
		address_1: '1 Raffles Place',
		city: 'Singapore',
		postcode: '048616',
		state: '',
	},
	US: {
		address_1: '123 Main St',
		city: 'San Francisco',
		postcode: '94103',
		state: 'CA',
	},
};

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
				status: 'fail',
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
				selected_gateway_id: null,
				order_payment_method: null,
				order_received_url: null,
				payment_intent_id: null,
				used_base_card_gateway: null,
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

function intentIdsFromText( text ) {
	const matches = String( text || '' ).match( /\bpi_[A-Za-z0-9_]+\b/g ) || [];
	return [ ...new Set( matches ) ];
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

async function maybeClick( roots, selectors, timeout = 10000 ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	await candidate.locator.click( { timeout } );
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

async function selectFirstNonEmptyOption( locator ) {
	return locator.evaluate( ( element ) => {
		if ( ! ( element instanceof HTMLSelectElement ) ) {
			return '';
		}
		const option = Array.from( element.options ).find( ( item ) => item.value && ! item.disabled );
		return option ? option.value : '';
	} ).then( async ( value ) => {
		if ( value ) {
			await locator.selectOption( value, { timeout: 10000 } );
			return true;
		}
		return false;
	} ).catch( () => false );
}

async function capturePageEvidence( page, extra = {} ) {
	let logs = [];
	let snapshotText = '';
	const screenshotPath = evidencePath.replace( /\.json$/, '.png' );
	const gatewaySelectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		`[data-gateway-id="${ gatewayId }"]`,
		`[data-payment-method-id="${ gatewayId }"]`,
		`.wcpay-upe-form[data-payment-method-type="${ stripePaymentMethodType }"]`,
		`.wcpay-upe-element[data-payment-method-type="${ stripePaymentMethodType }"]`,
		'#place_order',
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
		fatal_console_errors: logs.filter( isFatalConsoleError ),
		screenshot_path: screenshotPath,
		...extra,
	};
}

async function installResponseCapture( page, intentIds, failedResponses ) {
	const handler = async ( response ) => {
		const status = response.status();
		const url = response.url();
		if ( status >= 400 && ! /favicon\.ico|load-scripts\.php.*ver=/.test( url ) ) {
			failedResponses.push( { status, url } );
		}

		if ( ! /wc-ajax|wp-json|stripe|payment|checkout|order/i.test( url ) ) {
			return;
		}

		try {
			const text = await response.text();
			for ( const intentId of intentIdsFromText( text ) ) {
				intentIds.add( intentId );
			}
		} catch ( error ) {
			// Non-text responses are not useful for PaymentIntent extraction.
		}
	};
	page.on( 'response', handler );
	return handler;
}

async function readHiddenIntentIds( page ) {
	return page.evaluate( () =>
		Array.from(
			document.querySelectorAll(
				'#wcpay-payment-intent, [name="wcpay-payment-intent"], [name="payment_intent"], input[value^="pi_"]'
			)
		)
			.map( ( element ) => element.value || element.getAttribute( 'value' ) || '' )
			.filter( ( value ) => /^pi_/.test( value ) )
	).catch( () => [] );
}

async function readCheckedPaymentMethod( page ) {
	return page.evaluate( () => {
		const checked = document.querySelector( 'input[name="payment_method"]:checked' );
		return checked ? checked.value || checked.getAttribute( 'value' ) || '' : '';
	} ).catch( () => '' );
}

async function navigateToCheckout( page ) {
	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await getLatestLogs( { page, sinceLastCall: true } ).catch( () => [] );
	await page.goto( checkoutUrl, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
}

async function fillBillingFields( page ) {
	const billing = billingByCountry[ country ] || billingByCountry.NL;
	const roots = [ page ];

	await maybeFill( roots, [ '#billing_first_name', '[name="billing_first_name"]' ], 'Lpm' );
	await maybeFill( roots, [ '#billing_last_name', '[name="billing_last_name"]' ], 'Checkout' );
	await maybeSelect( roots, [ '#billing_country', '[name="billing_country"]' ], [ country ] );
	await maybeFill( roots, [ '#billing_address_1', '[name="billing_address_1"]' ], billing.address_1 );
	await maybeFill( roots, [ '#billing_city', '[name="billing_city"]' ], billing.city );
	await maybeFill( roots, [ '#billing_postcode', '[name="billing_postcode"]' ], billing.postcode );
	if ( billing.state ) {
		await maybeSelect( roots, [ '#billing_state', '[name="billing_state"]' ], [ billing.state ] );
		await maybeFill( roots, [ '#billing_state', '[name="billing_state"]' ], billing.state );
	}
	await maybeFill( roots, [ '#billing_phone', '[name="billing_phone"]' ], '+15555550123' );
	await maybeFill( roots, [ '#billing_email', '[name="billing_email"]' ], `lpm-${ method }@example.test` );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
}

async function selectPaymentMethod( page ) {
	const labels = methodLabels[ method ] || [ method ];
	const selectors = [
		`input[name="payment_method"][value="${ gatewayId }"]`,
		`#payment_method_${ gatewayId }`,
		`label[for="payment_method_${ gatewayId }"]`,
		`[data-gateway-id="${ gatewayId }"]`,
		`[data-payment-method-id="${ gatewayId }"]`,
		`[data-testid="${ gatewayId }"]`,
		`.payment_method_${ gatewayId } label`,
		`.wc_payment_methods label[for="payment_method_${ gatewayId }"]`,
		...labels.flatMap( ( label ) => [
			`.wc_payment_methods label:has-text("${ label }")`,
			`label:has-text("${ label }")`,
			`text=${ label }`,
		] ),
	];

	if ( ! ( await maybeCheck( [ page ], selectors ) ) ) {
		throw new Error( `LPM gateway selector was not found for ${ gatewayId }.` );
	}

	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
	const selected = await readCheckedPaymentMethod( page );
	if ( selected !== gatewayId ) {
		throw new Error( `Selected gateway mismatch: expected ${ gatewayId }, browser selected ${ selected || 'none' }.` );
	}
	return selected;
}

async function fillDebitPaymentElement( page ) {
	const fillAttempts = [];
	for ( const frame of page.frames() ) {
		const visibleInputs = await frame.locator( 'input' ).all().catch( () => [] );
		for ( const input of visibleInputs ) {
			if ( ! ( await input.isVisible().catch( () => false ) ) ) {
				continue;
			}
			const fieldText = await input.evaluate( ( element ) =>
				[
					element.getAttribute( 'name' ),
					element.getAttribute( 'autocomplete' ),
					element.getAttribute( 'aria-label' ),
					element.getAttribute( 'placeholder' ),
					element.closest( '[data-elements-stable-field-name]' )?.getAttribute( 'data-elements-stable-field-name' ),
				]
					.filter( Boolean )
					.join( ' ' )
			).catch( () => '' );

			let value = '';
			if ( /iban|sepa/i.test( fieldText ) || method === 'sepa_debit' ) {
				value = env.LPM_GATE_TEST_IBAN || 'AT611904300234573201';
			} else if ( /bsb|routing/i.test( fieldText ) ) {
				value = '000000';
			} else if ( /account/i.test( fieldText ) ) {
				value = '000123456';
			} else if ( /name|holder/i.test( fieldText ) ) {
				value = 'Jenny Rosen';
			} else if ( /email/i.test( fieldText ) ) {
				value = `lpm-${ method }@example.test`;
			}

			if ( value ) {
				await input.fill( value, { timeout: 15000 } );
				fillAttempts.push( fieldText || 'input' );
			}
		}
	}

	if ( methodFamily === 'debit' && fillAttempts.length === 0 ) {
		throw new Error( `No debit input fields were filled for ${ method }.` );
	}
	return fillAttempts;
}

async function fillPaymentElement( page ) {
	const selectedOptions = [];
	for ( const frame of page.frames() ) {
		const selects = await frame.locator( 'select' ).all().catch( () => [] );
		for ( const select of selects ) {
			if ( await select.isVisible().catch( () => false ) ) {
				if ( await selectFirstNonEmptyOption( select ) ) {
					selectedOptions.push( await select.getAttribute( 'name' ).catch( () => 'select' ) );
				}
			}
		}
	}

	const debitFields = await fillDebitPaymentElement( page );
	return {
		selected_options: selectedOptions,
		debit_fields: debitFields,
	};
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
		page.waitForURL( /order-received|checkout\/order-pay|hooks\.stripe\.com|stripe\.com|checkout\.stripe\.com/i, { timeout: 90000 } ).catch( () => null ),
		page.waitForLoadState( 'networkidle', { timeout: 90000 } ).catch( () => null ),
		waitForPageLoad( { page, timeout: 90000, minWait: 2000 } ).catch( () => null ),
	] );
}

async function approveExternalAuthorization( page ) {
	const approvalSelectors = [
		'button:has-text("Authorize Test Payment")',
		'button:has-text("Authorize")',
		'button:has-text("Complete")',
		'button:has-text("Confirm")',
		'button:has-text("Approve")',
		'button:has-text("Pay")',
		'a:has-text("Authorize Test Payment")',
		'a:has-text("Authorize")',
		'a:has-text("Complete")',
		'a:has-text("Return to")',
		'text=/Authorize Test Payment/i',
		'text=/Complete payment/i',
	];
	const attempts = [];

	for ( let i = 0; i < 6; i++ ) {
		const currentUrl = page.url();
		if ( /\/checkout\/order-received\//.test( currentUrl ) ) {
			return attempts;
		}

		const roots = [ page, ...page.frames() ];
		if ( await maybeClick( roots, approvalSelectors, 15000 ) ) {
			attempts.push( { url: currentUrl, action: 'clicked_authorization_control' } );
			await Promise.race( [
				page.waitForURL( /order-received|checkout|localhost|127\.0\.0\.1|\.localhost/i, { timeout: 90000 } ).catch( () => null ),
				page.waitForLoadState( 'networkidle', { timeout: 90000 } ).catch( () => null ),
				waitForPageLoad( { page, timeout: 90000, minWait: 1500 } ).catch( () => null ),
			] );
			continue;
		}

		await page.waitForTimeout( 1500 );
	}

	return attempts;
}

async function extractOrderEvidence( page, intentIds ) {
	const orderEvidence = await page.evaluate( () => {
		const bodyText = ( document.body?.innerText || document.body?.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		const url = window.location.href;
		const orderMatch = url.match( /\/order-received\/(\d+)\// );
		const intentMatches = bodyText.match( /\bpi_[A-Za-z0-9_]+\b/g ) || [];
		return {
			url,
			order_id: orderMatch ? Number.parseInt( orderMatch[ 1 ], 10 ) : null,
			body_text_sample: bodyText.slice( 0, 1600 ),
			body_text_length: bodyText.length,
			intent_ids: Array.from( new Set( intentMatches ) ),
			has_order_received_heading: /order received|thank you|order details|payment instructions/i.test( bodyText ),
			has_payment_method_text: /payment method|paid with|multibanco|klarna|affirm|afterpay|bancontact|ideal|wechat|alipay|grabpay|sepa|becs/i.test( bodyText ),
		};
	} );

	for ( const intentId of orderEvidence.intent_ids ) {
		intentIds.add( intentId );
	}

	return {
		...orderEvidence,
		payment_intent_id: [ ...intentIds ].find( ( candidate ) => /^pi_/.test( candidate ) ) || null,
	};
}

async function runCheckoutFlow( page ) {
	const intentIds = new Set();
	const failedResponses = [];
	const responseHandler = await installResponseCapture( page, intentIds, failedResponses );

	try {
		await navigateToCheckout( page );
		await fillBillingFields( page );
		const selectedGatewayId = await selectPaymentMethod( page );
		const elementEvidence = await fillPaymentElement( page );
		await acceptCheckoutTerms( page );
		await submitCheckout( page );
		for ( const intentId of await readHiddenIntentIds( page ) ) {
			intentIds.add( intentId );
		}
		const authorizationAttempts = await approveExternalAuthorization( page );
		for ( const intentId of await readHiddenIntentIds( page ) ) {
			intentIds.add( intentId );
		}

		const orderEvidence = await extractOrderEvidence( page, intentIds );
		const failures = [];
		if ( ! orderEvidence.order_id || orderEvidence.order_id <= 0 ) {
			failures.push( 'checkout did not reach an order-received URL with an order id' );
		}
		if ( ! orderEvidence.payment_intent_id ) {
			failures.push( 'PaymentIntent id was not observed in checkout responses or page state' );
		}
		if ( failedResponses.length > 0 ) {
			failures.push( 'failed browser responses were captured during checkout' );
		}

		const pageEvidence = await capturePageEvidence( page, {
			order: orderEvidence,
			element: elementEvidence,
			authorization_attempts: authorizationAttempts,
			observed_payment_intent_ids: [ ...intentIds ],
			failed_responses: failedResponses,
		} );
		const payload = {
			status: failures.length === 0 ? 'pass' : 'fail',
			order_id: orderEvidence.order_id || 0,
			selected_gateway_id: selectedGatewayId,
			order_payment_method: selectedGatewayId,
			order_received_url: orderEvidence.order_id ? orderEvidence.url : null,
			payment_intent_id: orderEvidence.payment_intent_id,
			used_base_card_gateway: selectedGatewayId === 'woocommerce_payments',
			failures,
			page: pageEvidence,
		};
		writeEvidence( payload );
		if ( failures.length > 0 ) {
			throw new Error( `LPM checkout failed for ${ role }/${ method }: ${ failures.join( '; ' ) }` );
		}
		return payload;
	} finally {
		page.off( 'response', responseHandler );
	}
}

assertLocalBase( baseUrl );
writeEvidence( { status: 'running' } );

let gatePage = null;

try {
	gatePage = await context.newPage();
	if ( typeof state !== 'undefined' ) {
		state.lpmCheckoutPage = gatePage;
	}

	await gatePage.setViewportSize( { width: 1280, height: 900 } );
	await runCheckoutFlow( gatePage );

	console.log( JSON.stringify( { evidencePath, pass: true, role, method }, null, 2 ) );
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
		state.lpmCheckoutPage = null;
	}
}
