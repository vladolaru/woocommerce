// SEPA token-continuity browser evidence driver.
//
// This driver normally runs in the isolated local Playwright runner. It saves a
// SEPA token while the WooPayments plugin is active, then verifies that the same
// token renders in My Account after the shell gate cuts the store over to native
// WooPayments. The explicit Playwriter compatibility runner supplies the same
// scenario interface for persistent-session reproduction. Evidence stays
// fail-closed unless browser-observed semantics satisfy token-continuity-gate.sh.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const env = typeof process !== 'undefined' && process.env ? process.env : {};
const stateConfig =
	typeof state !== 'undefined' && state.tokenContinuityConfig && 'object' === typeof state.tokenContinuityConfig
		? state.tokenContinuityConfig
		: {};

function requiredConfig( envName, stateName ) {
	const value = env[ envName ] || stateConfig[ stateName ];
	if ( ! value ) {
		throw new Error( `Missing required token-continuity browser config ${ envName }` );
	}
	return value;
}

const phase = requiredConfig( 'TOKEN_CONTINUITY_GATE_PHASE', 'phase' );
const baseUrl = requiredConfig( 'TOKEN_CONTINUITY_GATE_BASE_URL', 'baseUrl' ).replace( /\/+$/, '' );
const checkoutBaseUrl = requiredConfig( 'TOKEN_CONTINUITY_GATE_CHECKOUT_URL', 'checkoutUrl' );
const cartBaseUrl = requiredConfig( 'TOKEN_CONTINUITY_GATE_CART_URL', 'cartUrl' );
const addPaymentMethodBaseUrl = requiredConfig(
	'TOKEN_CONTINUITY_GATE_ADD_PAYMENT_METHOD_URL',
	'addPaymentMethodUrl'
);
const paymentMethodsBaseUrl = requiredConfig( 'TOKEN_CONTINUITY_GATE_PAYMENT_METHODS_URL', 'paymentMethodsUrl' );
const sourceFlow = ( env.TOKEN_CONTINUITY_GATE_SOURCE_FLOW || stateConfig.sourceFlow || 'checkout' ).replace(
	/-/g,
	'_'
);
const method = requiredConfig( 'TOKEN_CONTINUITY_GATE_METHOD', 'method' );
const gatewayId = requiredConfig( 'TOKEN_CONTINUITY_GATE_GATEWAY_ID', 'gatewayId' );
const stripePaymentMethodType = requiredConfig( 'TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE', 'stripePaymentMethodType' );
const tokenType = requiredConfig( 'TOKEN_CONTINUITY_GATE_TOKEN_TYPE', 'tokenType' );
const customerId = Number.parseInt( requiredConfig( 'TOKEN_CONTINUITY_GATE_CUSTOMER_ID', 'customerId' ), 10 );
const configuredTokenId = env.TOKEN_CONTINUITY_GATE_TOKEN_ID || stateConfig.tokenId;
const tokenId = configuredTokenId ? Number.parseInt( configuredTokenId, 10 ) : null;
const configuredSubscriptionProductId =
	env.TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID || stateConfig.subscriptionProductId;
const subscriptionProductId = configuredSubscriptionProductId
	? Number.parseInt( configuredSubscriptionProductId, 10 )
	: null;
const configuredCheckoutProductId = env.TOKEN_CONTINUITY_GATE_CHECKOUT_PRODUCT_ID || stateConfig.checkoutProductId;
const checkoutProductId = configuredCheckoutProductId ? Number.parseInt( configuredCheckoutProductId, 10 ) : null;
const evidencePath = requiredConfig( 'TOKEN_CONTINUITY_GATE_EVIDENCE_PATH', 'evidencePath' );
const startedAt = new Date().toISOString();
const sepaTestIban = env.TOKEN_CONTINUITY_GATE_TEST_IBAN || stateConfig.testIban || 'AT611904300234573201';
const failedResponseBodySampleLimit = 1200;

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

function phaseUrl() {
	if ( phase === 'save_sepa_token' ) {
		if ( sourceFlow === 'add_payment_method' ) {
			return withQueryParams( addPaymentMethodBaseUrl, {
				'token-continuity-gate': 'save-sepa-add-payment-method',
			} );
		}
		return withQueryParams( checkoutBaseUrl, {
			'token-continuity-gate': 'save-sepa',
		} );
	}
	if ( phase === 'render_payment_methods' ) {
		if ( ! tokenId || tokenId <= 0 ) {
			throw new Error( 'render_payment_methods requires TOKEN_CONTINUITY_GATE_TOKEN_ID.' );
		}
		return withQueryParams( paymentMethodsBaseUrl, {
			'token-continuity-gate': 'render-sepa',
			token_id: tokenId,
		} );
	}
	throw new Error( `Unsupported token-continuity phase: ${ phase }` );
}

function withQueryParams( value, params ) {
	const url = new URL( value );
	for ( const [ key, paramValue ] of Object.entries( params ) ) {
		url.searchParams.set( key, paramValue );
	}
	return url.toString();
}

function subscriptionAddToCartUrl() {
	if ( ! subscriptionProductId || subscriptionProductId <= 0 ) {
		return null;
	}
	return `${ baseUrl }/?add-to-cart=${ encodeURIComponent(
		subscriptionProductId
	) }&quantity=1&token-continuity-gate=save-sepa-subscription`;
}

function checkoutProductAddToCartUrl() {
	if ( ! checkoutProductId || checkoutProductId <= 0 ) {
		return null;
	}
	return `${ baseUrl }/?add-to-cart=${ encodeURIComponent(
		checkoutProductId
	) }&quantity=1&token-continuity-gate=save-sepa-token`;
}

function clearCartUrl() {
	return withQueryParams( cartBaseUrl, {
		'token-continuity-gate': 'clear-source-cart',
	} );
}

function orderIdFromUrl( value ) {
	const match = String( value || '' ).match( /order-received(?:\/|=)(\d+)|order_id=(\d+)/i );
	if ( ! match ) {
		return null;
	}
	const candidate = Number.parseInt( match[ 1 ] || match[ 2 ], 10 );
	return Number.isInteger( candidate ) && candidate > 0 ? candidate : null;
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
				source_flow: sourceFlow,
				token_id: tokenId,
				order_id: null,
				checkout_product_id: checkoutProductId,
				subscription_product_id: subscriptionProductId,
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
	const matches = String( text || '' ).match( /\bpm_[A-Za-z0-9]{8,}\b/g ) || [];
	return [ ...new Set( matches ) ];
}

function isStripePaymentMethodId( value ) {
	return /^pm_[A-Za-z0-9]{8,}$/.test( String( value || '' ) );
}

function isStripeMandateId( value ) {
	return /^mandate_[A-Za-z0-9]{8,}$/.test( String( value || '' ) );
}

function collectMandateIds( value, ids = new Set() ) {
	if ( Array.isArray( value ) ) {
		for ( const item of value ) {
			collectMandateIds( item, ids );
		}
		return ids;
	}
	if ( ! value || typeof value !== 'object' ) {
		return ids;
	}
	for ( const [ key, child ] of Object.entries( value ) ) {
		if ( key === 'mandate' && isStripeMandateId( child ) ) {
			ids.add( child );
		}
		collectMandateIds( child, ids );
	}
	return ids;
}

function mandateIdsFromPayloadText( text ) {
	if ( ! text ) {
		return [];
	}
	try {
		return [ ...collectMandateIds( JSON.parse( text ) ) ];
	} catch ( error ) {
		return [];
	}
}

function isStripeSetupIntentId( value ) {
	return /^seti_[A-Za-z0-9]{8,}$/.test( String( value || '' ) );
}

function collectSetupIntentIds( value, ids = new Set() ) {
	if ( Array.isArray( value ) ) {
		for ( const item of value ) {
			collectSetupIntentIds( item, ids );
		}
		return ids;
	}
	if ( ! value || typeof value !== 'object' ) {
		return ids;
	}
	for ( const [ key, child ] of Object.entries( value ) ) {
		if ( key === 'id' && isStripeSetupIntentId( child ) ) {
			ids.add( child );
		}
		collectSetupIntentIds( child, ids );
	}
	return ids;
}

function setupIntentIdsFromPayloadText( text ) {
	if ( ! text ) {
		return [];
	}
	try {
		return [ ...collectSetupIntentIds( JSON.parse( text ) ) ];
	} catch ( error ) {
		return [];
	}
}

function responseBodySample( text ) {
	return String( text || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, failedResponseBodySampleLimit );
}

async function readHiddenPaymentMethodIds( page ) {
	return page.evaluate( () =>
		Array.from( document.querySelectorAll( '#wcpay-payment-method, [name="wcpay-payment-method"]' ) )
			.map( ( element ) => element.value || element.getAttribute( 'value' ) || '' )
			.filter( ( value ) => /^pm_[A-Za-z0-9]{8,}$/.test( value ) )
	).catch( () => [] );
}

async function readHiddenSetupIntentIds( page ) {
	return page.evaluate( () =>
		Array.from( document.querySelectorAll( '#wcpay-setup-intent, [name="wcpay-setup-intent"]' ) )
			.map( ( element ) => element.value || element.getAttribute( 'value' ) || '' )
			.filter( ( value ) => /^seti_[A-Za-z0-9]{8,}$/.test( value ) )
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

async function maybeSelectOrFill( roots, selectors, values ) {
	if ( await maybeSelect( roots, selectors, values ) ) {
		return true;
	}
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	await candidate.locator.fill( values[ values.length - 1 ], { timeout: 10000 } );
	return true;
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
		logs = [ { type: 'browser-log-error', text: error?.message || String( error ) } ];
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

async function installResponseCapture( page, paymentMethodIds, mandateIds, setupIntentIds, failedResponses ) {
	const handler = async ( response ) => {
		const status = response.status();
		const url = response.url();
		const isFailed = status >= 400 && ! /favicon\.ico|load-scripts\.php.*ver=/.test( url );
		const shouldInspect = /wc-ajax|wp-json|stripe|payment|checkout|setup/i.test( url );
		if ( ! isFailed && ! shouldInspect ) {
			return;
		}

		let text = '';
		let bodyError = '';
		try {
			text = await response.text();
		} catch ( error ) {
			bodyError = error?.message || String( error );
		}

		if ( isFailed ) {
			const failedResponse = { status, url };
			if ( text ) {
				failedResponse.body_sample = responseBodySample( text );
			}
			if ( bodyError ) {
				failedResponse.body_error = bodyError.slice( 0, 300 );
			}
			failedResponses.push( failedResponse );
		}

		if ( shouldInspect && text ) {
			for ( const paymentMethodId of paymentMethodIdsFromText( text ) ) {
				paymentMethodIds.add( paymentMethodId );
			}
			for ( const mandateId of mandateIdsFromPayloadText( text ) ) {
				mandateIds.add( mandateId );
			}
			for ( const setupIntentId of setupIntentIdsFromPayloadText( text ) ) {
				setupIntentIds.add( setupIntentId );
			}
		}
	};
	page.on( 'response', handler );
	return handler;
}

async function navigateToPhaseUrl( page ) {
	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await getLatestLogs( { page, sinceLastCall: true } ).catch( () => [] );
	if ( phase === 'save_sepa_token' && sourceFlow === 'checkout' && subscriptionProductId ) {
		await clearBrowserCart( page );
		await page.goto( subscriptionAddToCartUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
		await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	} else if ( phase === 'save_sepa_token' && sourceFlow === 'checkout' && checkoutProductId ) {
		await clearBrowserCart( page );
		await page.goto( checkoutProductAddToCartUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
		await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	}
	await page.goto( phaseUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
}

async function clearBrowserCart( page ) {
	await page.goto( clearCartUrl(), { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } );

	const removeSelector = [
		'a.remove',
		'.product-remove a',
		'a[href*="remove_item"]',
		'a[aria-label^="Remove"]',
	].join( ', ' );

	for ( let attempts = 0; attempts < 20; attempts++ ) {
		const removeLink = page.locator( removeSelector ).first();
		if ( ( await removeLink.count().catch( () => 0 ) ) === 0 ) {
			return;
		}
		if ( ! ( await removeLink.isVisible().catch( () => true ) ) ) {
			return;
		}

		await removeLink.click( { timeout: 10000 } );
		await Promise.race( [
			page.waitForLoadState( 'domcontentloaded', { timeout: 10000 } ).catch( () => null ),
			waitForPageLoad( { page, timeout: 10000, minWait: 500 } ).catch( () => null ),
			page.waitForTimeout( 1000 ),
		] );
	}

	throw new Error( 'Could not clear browser cart before source token-save checkout.' );
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

	for ( let attempt = 0; attempt < 8; attempt++ ) {
		await page
			.locator( 'text=/Loading payment options/i' )
			.first()
			.waitFor( { state: 'detached', timeout: 10000 } )
			.catch( () => {} );
		if ( await maybeCheck( roots, selectors ) ) {
			await maybeCheck( roots, [
				`#wc-${ gatewayId }-payment-token-new`,
				`[name="wc-${ gatewayId }-payment-token"][value="new"]`,
			] );
			await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
			return gatewayId;
		}
		await waitForPageLoad( { page, timeout: 15000, minWait: 500 } ).catch( () => {} );
		await page.waitForTimeout( 1000 );
	}

	throw new Error( `SEPA gateway selector was not found for ${ gatewayId }.` );
}

async function fillBillingFields( page ) {
	const roots = [ page ];
	await maybeClick( roots, [
		'button:has-text("Edit billing address")',
		'button[aria-label*="Edit billing"]',
		'button[aria-label*="billing address"]',
		'text=/Edit billing address/i',
	] );
	await waitForPageLoad( { page, timeout: 10000, minWait: 500 } ).catch( () => {} );

	await maybeFill(
		roots,
		[
			'#billing_first_name',
			'[name="billing_first_name"]',
			'#billing-first_name',
			'[name="billing-first_name"]',
			'[autocomplete="given-name"]',
		],
		'Token'
	);
	await maybeFill(
		roots,
		[
			'#billing_last_name',
			'[name="billing_last_name"]',
			'#billing-last_name',
			'[name="billing-last_name"]',
			'[autocomplete="family-name"]',
		],
		'Continuity'
	);
	await maybeSelectOrFill(
		roots,
		[
			'#billing_country',
			'[name="billing_country"]',
			'#billing-country',
			'[name="billing-country"]',
			'[autocomplete="country"]',
		],
		[ 'DE', 'Germany' ]
	);
	await maybeFill(
		roots,
		[
			'#billing_address_1',
			'[name="billing_address_1"]',
			'#billing-address_1',
			'[name="billing-address_1"]',
			'[autocomplete="address-line1"]',
		],
		'Invalidenstrasse 117'
	);
	await maybeFill(
		roots,
		[
			'#billing_city',
			'[name="billing_city"]',
			'#billing-city',
			'[name="billing-city"]',
			'[autocomplete="address-level2"]',
		],
		'Berlin'
	);
	await maybeFill(
		roots,
		[
			'#billing_postcode',
			'[name="billing_postcode"]',
			'#billing-postcode',
			'[name="billing-postcode"]',
			'[autocomplete="postal-code"]',
		],
		'10115'
	);
	await maybeFill(
		roots,
		[
			'#billing_phone',
			'[name="billing_phone"]',
			'#billing-phone',
			'[name="billing-phone"]',
			'[autocomplete="tel"]',
		],
		'+493012345678'
	);
	await maybeFill(
		roots,
		[
			'#billing_email',
			'[name="billing_email"]',
			'#email',
			'[name="email"]',
			'#billing-email',
			'[name="billing-email"]',
			'[autocomplete="email"]',
		],
		'token-continuity@example.test'
	);
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } ).catch( () => {} );
	await page.waitForTimeout( 1000 );
}

async function fillSepaElement( page ) {
	const selectors = [
		'input[name="iban"]',
		'#payment-ibanInput',
		'input[autocomplete="iban"]',
		'input[aria-label*="IBAN"]',
		'input[placeholder*="IBAN"]',
		'input[placeholder*="0000"]',
		'input[id*="iban" i]',
		'.wcpay-upe-form[data-payment-method-type="sepa_debit"] input',
		'.wcpay-upe-element[data-payment-method-type="sepa_debit"] input',
		'[data-elements-stable-field-name="iban"] input',
		'input',
	];

	for ( let attempt = 0; attempt < 30; attempt++ ) {
		for ( const frame of page.frames() ) {
			const candidate = await firstVisibleLocator( [ frame ], selectors );
			if ( ! candidate ) {
				continue;
			}

			const label = await candidate.locator.evaluate( ( element ) => {
				const input = element;
				return [
					input.getAttribute( 'id' ),
					input.getAttribute( 'name' ),
					input.getAttribute( 'autocomplete' ),
					input.getAttribute( 'aria-label' ),
					input.getAttribute( 'placeholder' ),
					input.closest( '[data-elements-stable-field-name]' )?.getAttribute( 'data-elements-stable-field-name' ),
				]
					.filter( Boolean )
					.join( ' ' );
			} ).catch( () => '' );

			if ( ! /iban|sepa|0000/i.test( label ) && selectors.indexOf( candidate.selector ) === selectors.length - 1 ) {
				continue;
			}

			await candidate.locator.fill( sepaTestIban, { timeout: 15000 } );
			return true;
		}

		await waitForPageLoad( { page, timeout: 5000, minWait: 250 } ).catch( () => {} );
		await page.waitForTimeout( 1000 );
	}

	const frameSummary = page
		.frames()
		.map( ( frame ) => frame.url() )
		.filter( Boolean )
		.join( ', ' )
		.slice( 0, 1000 );
	throw new Error( `SEPA IBAN field was not found in checkout Stripe Elements frames after waiting. Frames: ${ frameSummary }` );
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

async function submitAddPaymentMethod( page ) {
	const submitted = await maybeClick( [ page ], [
		'button[name="woocommerce_add_payment_method"]',
		'button[type="submit"][value*="Add payment"]',
		'button:has-text("Add payment method")',
		'button:has-text("Add payment")',
		'#place_order',
	] );
	if ( ! submitted ) {
		throw new Error( 'add-payment-method submit button was not found.' );
	}

	await Promise.race( [
		page.waitForURL( /payment-methods|add-payment-method/i, { timeout: 90000 } ).catch( () => null ),
		page.waitForLoadState( 'networkidle', { timeout: 90000 } ).catch( () => null ),
		waitForPageLoad( { page, timeout: 90000, minWait: 2000 } ).catch( () => null ),
	] );
}

async function readCheckoutOrderId( page ) {
	const fromUrl = orderIdFromUrl( page.url() );
	if ( fromUrl ) {
		return fromUrl;
	}

	return page.evaluate( () => {
		const source = [
			window.location.href,
			document.body?.innerText || '',
			...Array.from( document.querySelectorAll( 'a[href]' ) ).map( ( link ) => link.getAttribute( 'href' ) || '' ),
			...Array.from( document.querySelectorAll( '[data-order-id], [data-order_id]' ) ).map(
				( element ) => element.getAttribute( 'data-order-id' ) || element.getAttribute( 'data-order_id' ) || ''
			),
		].join( ' ' );
		const match = source.match( /order-received(?:\/|=)(\d+)|order_id=(\d+)|Order number:\s*(\d+)/i );
		if ( ! match ) {
			return null;
		}
		const candidate = Number.parseInt( match[ 1 ] || match[ 2 ] || match[ 3 ], 10 );
		return Number.isInteger( candidate ) && candidate > 0 ? candidate : null;
	} ).catch( () => null );
}

async function paymentMethodsUrl( requestedTokenId = null ) {
	if ( requestedTokenId ) {
		return withQueryParams( paymentMethodsBaseUrl, {
			'token-continuity-gate': 'render-sepa',
			token_id: requestedTokenId,
		} );
	}
	return withQueryParams( paymentMethodsBaseUrl, {
		'token-continuity-gate': 'save-sepa-after-checkout',
	} );
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
	const mandateIds = new Set();
	const setupIntentIds = new Set();
	const failedResponses = [];
	const responseHandler = await installResponseCapture(
		page,
		paymentMethodIds,
		mandateIds,
		setupIntentIds,
		failedResponses
	);

	try {
		if ( sourceFlow === 'add_payment_method' ) {
			return await runAddPaymentMethodSourceFlow( page, paymentMethodIds, mandateIds, setupIntentIds, failedResponses );
		}
		return await runCheckoutSourceFlow( page, paymentMethodIds, mandateIds, setupIntentIds, failedResponses );
	} finally {
		page.off( 'response', responseHandler );
	}
}

async function runCheckoutSourceFlow( page, paymentMethodIds, mandateIds, setupIntentIds, failedResponses ) {
	await navigateToPhaseUrl( page );
	await fillBillingFields( page );
	const selectedGatewayId = await selectSepaGateway( page );
	await fillSepaElement( page );
	await enableReusablePaymentMethod( page );
	await acceptCheckoutTerms( page );
	await submitCheckout( page );
	const checkoutFinalUrl = page.url();
	const observedOrderId = await readCheckoutOrderId( page );

	for ( const paymentMethodId of await readHiddenPaymentMethodIds( page ) ) {
		paymentMethodIds.add( paymentMethodId );
	}
	for ( const setupIntentId of await readHiddenSetupIntentIds( page ) ) {
		setupIntentIds.add( setupIntentId );
	}
	await page.waitForTimeout( 1500 );
	const methods = await openPaymentMethodsPage( page );
	const observedTokenId = methods.token_id;
	const paymentMethodId = [ ...paymentMethodIds ].find( isStripePaymentMethodId ) || null;
	const mandateId = [ ...mandateIds ].find( isStripeMandateId ) || null;
	const sourceTokenRequiresStatePersistence = ! observedTokenId && Boolean( paymentMethodId );
	const failures = [];

	if ( ( ! observedTokenId || observedTokenId <= 0 ) && ! sourceTokenRequiresStatePersistence ) {
		failures.push( 'saved SEPA token was not visible in My Account payment methods after checkout' );
	}
	if ( ! paymentMethodId ) {
		failures.push( 'Stripe PaymentMethod id was not observed in checkout network responses' );
	}
	if ( failedResponses.length > 0 ) {
		failures.push( 'failed browser responses were captured during checkout' );
	}
	if ( subscriptionProductId && ( ! observedOrderId || observedOrderId <= 0 ) ) {
		failures.push( 'browser-created subscription checkout did not expose a positive order_id' );
	}

	const pageEvidence = await capturePageEvidence( page, {
		checkout_final_url: checkoutFinalUrl,
		payment_methods: methods,
		observed_payment_method_ids: [ ...paymentMethodIds ],
		observed_mandate_ids: [ ...mandateIds ],
		observed_setup_intent_ids: [ ...setupIntentIds ],
		failed_responses: failedResponses,
	} );
	const payload = {
		status: failures.length === 0 ? 'pass' : 'fail',
		token_id: observedTokenId || 0,
		order_id: observedOrderId || 0,
		checkout_product_id: checkoutProductId,
		subscription_product_id: subscriptionProductId,
		selected_gateway_id: selectedGatewayId,
		payment_method_id: paymentMethodId,
		mandate_id: mandateId,
		source_token_requires_state_persistence: sourceTokenRequiresStatePersistence,
		token_visible: Boolean( methods.token_visible ),
		failures,
		page: pageEvidence,
	};
	writeEvidence( payload );
	if ( failures.length > 0 ) {
		throw new Error( `SEPA token save phase failed: ${ failures.join( '; ' ) }` );
	}
	return payload;
}

async function runAddPaymentMethodSourceFlow( page, paymentMethodIds, mandateIds, setupIntentIds, failedResponses ) {
	await navigateToPhaseUrl( page );
	await fillBillingFields( page );
	const selectedGatewayId = await selectSepaGateway( page );
	await fillSepaElement( page );
	await submitAddPaymentMethod( page );

	for ( const paymentMethodId of await readHiddenPaymentMethodIds( page ) ) {
		paymentMethodIds.add( paymentMethodId );
	}
	for ( const setupIntentId of await readHiddenSetupIntentIds( page ) ) {
		setupIntentIds.add( setupIntentId );
	}
	await page.waitForTimeout( 1500 );
	const methods = await openPaymentMethodsPage( page );
	const observedTokenId = methods.token_id;
	const paymentMethodId = [ ...paymentMethodIds ].find( isStripePaymentMethodId ) || null;
	const mandateId = [ ...mandateIds ].find( isStripeMandateId ) || null;
	const setupIntentId = [ ...setupIntentIds ].find( isStripeSetupIntentId ) || null;
	const sourceTokenRequiresStatePersistence = false;
	const failures = [];

	if ( ! observedTokenId || observedTokenId <= 0 ) {
		failures.push( 'setup-intent add-payment-method flow did not create a visible My Account SEPA token' );
	}
	if ( ! paymentMethodId ) {
		failures.push( 'Stripe PaymentMethod id was not observed in add-payment-method network responses' );
	}
	if ( ! setupIntentId ) {
		failures.push( 'Stripe SetupIntent id was not observed in add-payment-method network responses' );
	}
	if ( failedResponses.length > 0 ) {
		failures.push( 'failed browser responses were captured during add-payment-method setup' );
	}

	const pageEvidence = await capturePageEvidence( page, {
		payment_methods: methods,
		observed_payment_method_ids: [ ...paymentMethodIds ],
		observed_mandate_ids: [ ...mandateIds ],
		observed_setup_intent_ids: [ ...setupIntentIds ],
		failed_responses: failedResponses,
	} );
	const payload = {
		status: failures.length === 0 ? 'pass' : 'fail',
		token_id: observedTokenId || 0,
		order_id: 0,
		checkout_product_id: checkoutProductId,
		subscription_product_id: subscriptionProductId,
		selected_gateway_id: selectedGatewayId,
		payment_method_id: paymentMethodId,
		mandate_id: mandateId,
		setup_intent_id: setupIntentId,
		source_token_requires_state_persistence: sourceTokenRequiresStatePersistence,
		token_visible: Boolean( methods.token_visible ),
		failures,
		page: pageEvidence,
	};
	writeEvidence( payload );
	if ( failures.length > 0 ) {
		throw new Error( `SEPA add-payment-method source phase failed: ${ failures.join( '; ' ) }` );
	}
	return payload;
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
assertLocalUrl( 'checkout URL', checkoutBaseUrl );
assertLocalUrl( 'cart URL', cartBaseUrl );
assertLocalUrl( 'payment methods URL', paymentMethodsBaseUrl );
if ( subscriptionProductId !== null && ( ! Number.isInteger( subscriptionProductId ) || subscriptionProductId <= 0 ) ) {
	throw new Error( 'TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID must be a positive integer when provided.' );
}
if ( checkoutProductId !== null && ( ! Number.isInteger( checkoutProductId ) || checkoutProductId <= 0 ) ) {
	throw new Error( 'TOKEN_CONTINUITY_GATE_CHECKOUT_PRODUCT_ID must be a positive integer when provided.' );
}
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
