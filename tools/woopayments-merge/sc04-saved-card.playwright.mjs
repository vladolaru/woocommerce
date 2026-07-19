const fs = require( 'node:fs' );
const path = require( 'node:path' );

const config = state.sc04SavedCardConfig || {};
const evidencePath =
	typeof config.evidencePath === 'string' ? config.evidencePath : '';

if ( ! evidencePath ) {
	throw new Error( 'state.sc04SavedCardConfig.evidencePath is required.' );
}

fs.mkdirSync( path.dirname( evidencePath ), { recursive: true } );

const store = typeof config.store === 'string' ? config.store : '';
const baseUrl =
	typeof config.baseUrl === 'string'
		? config.baseUrl.replace( /\/+$/, '' )
		: '';
const classicCheckoutUrl =
	typeof config.classicCheckoutUrl === 'string'
		? config.classicCheckoutUrl
		: '';
const blocksCheckoutUrl =
	typeof config.blocksCheckoutUrl === 'string'
		? config.blocksCheckoutUrl
		: '';
const productId = Number.parseInt( String( config.productId || '' ), 10 );
const customerId = Number.parseInt( String( config.customerId || '' ), 10 );
const normalTokenId = Number.parseInt(
	String( config.normalTokenId || '' ),
	10
);
const existingScaTokenId = Number.parseInt(
	String( config.existingScaTokenId || '' ),
	10
);
const authCookie =
	config.authCookie && typeof config.authCookie === 'object'
		? config.authCookie
		: {};
const contextBinding = {
	aggregate_run_id: config.contextBinding?.aggregate_run_id,
	context_sha256: config.contextBinding?.context_sha256,
};
const scaCardNumber = '4000002500003155';
const scaCardLast4 = '3155';
let baseOrigin = '';
try {
	baseOrigin = baseUrl ? new URL( baseUrl ).origin : '';
} catch ( error ) {
	// Validation below records a schema-valid failure for malformed URLs.
}

function emptySurface() {
	return {
		success: false,
		order_id: null,
		selected_token_id: null,
		final_url: '',
		saved_card_visible: false,
		saved_card_selected: false,
		new_card_fields_forced: false,
		sca_challenge_present: false,
		sca_challenge_completed: false,
		expected_amount: null,
		expected_currency: null,
		screenshot_paths: [],
		checkout_response: null,
		errors: [],
	};
}

const evidence = {
	schema: 'woopayments_sc04_browser_evidence.v1',
	context_binding: contextBinding,
	store,
	home_url: baseUrl,
	customer_id: Number.isInteger( customerId ) ? customerId : null,
	token_id: Number.isInteger( normalTokenId ) ? normalTokenId : null,
	sca_token_id: null,
	sca_token_origin: null,
	surfaces: {
		classic: emptySurface(),
		blocks: emptySurface(),
		sca_classic: emptySurface(),
		sca_blocks: emptySurface(),
	},
	fatal_console_errors: [],
	fatal_response_errors: [],
	errors: [],
};

function replaceAllLiteral( value, needle, replacement ) {
	if ( ! needle ) {
		return value;
	}
	return value.split( needle ).join( replacement );
}

function redactSensitiveText( value ) {
	let text = String( value ?? '' );
	text = text
		.replace(
			/\b(?:pi|seti|src)_[A-Za-z0-9._%-]+_secret_[A-Za-z0-9._%-]+\b/gi,
			'[REDACTED_CLIENT_SECRET]'
		)
		.replace(
			/(\b(?:payment_intent_|setup_intent_)?client_secret\b(?:=|%3D|["':\s]+))([^&\s"',}]+)/gi,
			'$1[REDACTED]'
		);
	for ( const cookiePart of [ authCookie.name, authCookie.value ] ) {
		text = replaceAllLiteral( text, cookiePart, '[REDACTED_AUTH_COOKIE]' );
		if ( cookiePart ) {
			text = replaceAllLiteral(
				text,
				encodeURIComponent( cookiePart ),
				'[REDACTED_AUTH_COOKIE]'
			);
		}
	}
	return text;
}

function sanitizeForEvidence( value ) {
	if ( typeof value === 'string' ) {
		return redactSensitiveText( value );
	}
	if ( Array.isArray( value ) ) {
		return value.map( sanitizeForEvidence );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value ).map( ( [ key, item ] ) => [
				key,
				sanitizeForEvidence( item ),
			] )
		);
	}
	return value;
}

function writeEvidence() {
	const payload = sanitizeForEvidence( evidence );
	// Context binding is a signed harness value and must be copied byte-for-byte.
	payload.context_binding = {
		aggregate_run_id: contextBinding.aggregate_run_id,
		context_sha256: contextBinding.context_sha256,
	};
	fs.writeFileSync(
		evidencePath,
		`${ JSON.stringify( payload, null, 2 ) }\n`,
		'utf8'
	);
}

function safeErrorMessage( error ) {
	return redactSensitiveText( error?.message || String( error ) );
}

function safeUrl( rawUrl ) {
	const value = String( rawUrl || '' );
	try {
		const parsed = new URL( value );
		for ( const key of [ ...parsed.searchParams.keys() ] ) {
			if (
				/(?:^|_)(?:key|nonce|token|signature|secret|password|cookie)(?:$|_)/i.test(
					key
				)
			) {
				parsed.searchParams.set( key, '[REDACTED]' );
			}
		}
		parsed.hash = redactSensitiveText( parsed.hash );
		return redactSensitiveText( parsed.toString() );
	} catch ( error ) {
		return redactSensitiveText( value );
	}
}

function addUnique( target, item ) {
	const serialized = JSON.stringify( item );
	if (
		! target.some(
			( candidate ) => JSON.stringify( candidate ) === serialized
		)
	) {
		target.push( item );
	}
}

function validateConfig() {
	if ( ! [ 'ref', 'target' ].includes( store ) ) {
		throw new Error(
			'state.sc04SavedCardConfig.store must be "ref" or "target".'
		);
	}
	for ( const [ name, value ] of [
		[ 'baseUrl', baseUrl ],
		[ 'classicCheckoutUrl', classicCheckoutUrl ],
		[ 'blocksCheckoutUrl', blocksCheckoutUrl ],
	] ) {
		if ( ! value ) {
			throw new Error(
				`state.sc04SavedCardConfig.${ name } is required.`
			);
		}
		let parsed;
		try {
			parsed = new URL( value );
		} catch ( error ) {
			throw new Error(
				`state.sc04SavedCardConfig.${ name } must be an absolute URL.`
			);
		}
		if ( name !== 'baseUrl' && parsed.origin !== baseOrigin ) {
			throw new Error(
				`state.sc04SavedCardConfig.${ name } must use the baseUrl origin.`
			);
		}
	}
	for ( const [ name, value ] of [
		[ 'productId', productId ],
		[ 'customerId', customerId ],
		[ 'normalTokenId', normalTokenId ],
	] ) {
		if ( ! Number.isInteger( value ) || value <= 0 ) {
			throw new Error(
				`state.sc04SavedCardConfig.${ name } must be a positive integer.`
			);
		}
	}
	if (
		typeof authCookie.name !== 'string' ||
		! authCookie.name ||
		typeof authCookie.value !== 'string' ||
		! authCookie.value
	) {
		throw new Error(
			'state.sc04SavedCardConfig.authCookie must contain non-empty name and value strings.'
		);
	}
	if (
		typeof contextBinding.aggregate_run_id !== 'string' ||
		! contextBinding.aggregate_run_id ||
		typeof contextBinding.context_sha256 !== 'string' ||
		! contextBinding.context_sha256
	) {
		throw new Error(
			'state.sc04SavedCardConfig.contextBinding is incomplete.'
		);
	}
}

async function resetShopperSession() {
	await context.clearCookies();
	await context.addCookies( [
		{
			name: authCookie.name,
			value: authCookie.value,
			url: `${ baseUrl }/`,
			httpOnly: true,
			secure: new URL( baseUrl ).protocol === 'https:',
			sameSite: 'Lax',
		},
	] );
}

function logText( log ) {
	return typeof log === 'string'
		? log
		: log?.text || log?.message || JSON.stringify( log );
}

function logType( log ) {
	return typeof log === 'string' ? '' : log?.type || log?.level || '';
}

function isIgnoredConsoleMessage( text ) {
	return /JQMIGRATE|Permissions policy violation: unload|Download the React DevTools|prebid|raven|cadmus|IntersectionObserver.*rootMargin|favicon\.ico|hcaptcha\.com\/logo\.png/i.test(
		text
	);
}

function isBenignStripeTestAcsCspError( log ) {
	const text = logText( log );
	const url = log?.location?.url || '';
	let parsed;
	try {
		parsed = new URL( url );
	} catch ( error ) {
		return false;
	}

	return (
		parsed.protocol === 'https:' &&
		parsed.hostname === 'testmode-acs.stripe.com' &&
		/^\/3d_secure_2_test\/[^/]+\/[^/]+\/challenge(?:\/complete)?$/.test(
			parsed.pathname
		) &&
		text ===
			`Loading the image 'data:image/png;base64,iVBORw0KGgo=' violates the following Content Security Policy directive: "img-src 'self'". The action has been blocked.`
	);
}

function isNonCriticalResourceConsoleError( log ) {
	const text = logText( log );
	const url = log?.location?.url || '';

	return (
		/^Failed to load resource: the server responded with a status of [45]\d\d \([^)]+\)$/.test(
			text
		) &&
		url !== '' &&
		! isCriticalResourceUrl( url )
	);
}

function isFatalConsoleError( log ) {
	const text = logText( log );
	const type = logType( log );
	if (
		isIgnoredConsoleMessage( text ) ||
		isBenignStripeTestAcsCspError( log ) ||
		isNonCriticalResourceConsoleError( log ) ||
		type === 'requestfailed'
	) {
		return false;
	}
	return (
		type === 'error' ||
		type === 'pageerror' ||
		/uncaught|fatal|exception|typeerror|referenceerror/i.test( text )
	);
}

function normalizedLog( log ) {
	const location =
		log && typeof log === 'object' && log.location ? log.location : null;
	return {
		type: logType( log ) || 'error',
		text: redactSensitiveText( logText( log ) ),
		...( location
			? {
					location: {
						url: safeUrl( location.url || '' ),
						lineNumber: location.lineNumber ?? null,
						columnNumber: location.columnNumber ?? null,
					},
			  }
			: {} ),
	};
}

function isIgnoredFailedResource( url, failure = '' ) {
	return (
		/favicon\.ico|elements-inner-accessory-target|hcaptcha\.com\/logo\.png|\.map(?:\?|$)/i.test(
			url
		) ||
		( /net::ERR_ABORTED/i.test( failure ) &&
			/stripe\.com|order-received/i.test( url ) )
	);
}

function isCriticalResourceUrl( url ) {
	return /wc-ajax=(?:checkout|update_order_review)|\/wc\/store\/v1\/(?:cart|checkout)|woocommerce[-_]payments|woopayments|js\.stripe\.com|api\.stripe\.com/i.test(
		url
	);
}

function normalizedFailedRequestLog( log ) {
	const text = logText( log );
	const match = text.match( /^(.*?):\s+(https?:\/\/\S+)$/ );
	return {
		type: 'requestfailed',
		failure: redactSensitiveText( match ? match[ 1 ] : text ),
		url: safeUrl( log?.url || ( match ? match[ 2 ] : '' ) ),
	};
}

async function drainBrowserLogs( page ) {
	let logs;
	try {
		logs = await getLatestLogs( { page, sinceLastCall: true } );
	} catch ( error ) {
		addUnique(
			evidence.errors,
			`Browser log capture failed: ${ safeErrorMessage( error ) }`
		);
		return;
	}

	for ( const log of logs ) {
		if ( logType( log ) === 'requestfailed' ) {
			const failed = normalizedFailedRequestLog( log );
			if (
				! isIgnoredFailedResource( failed.url, failed.failure ) &&
				isCriticalResourceUrl( failed.url )
			) {
				addUnique( evidence.fatal_response_errors, failed );
			}
			continue;
		}
		if ( isFatalConsoleError( log ) ) {
			addUnique( evidence.fatal_console_errors, normalizedLog( log ) );
		}
	}
}

function installResponseClassifier( page ) {
	page.on( 'response', ( response ) => {
		const status = response.status();
		if ( status < 400 ) {
			return;
		}
		const url = response.url();
		if ( isIgnoredFailedResource( url ) ) {
			return;
		}
		let sameOrigin = false;
		try {
			sameOrigin = new URL( url ).origin === baseOrigin;
		} catch ( error ) {
			// An invalid response URL is classified only if it matches a critical endpoint.
		}
		if ( isCriticalResourceUrl( url ) || ( sameOrigin && status >= 500 ) ) {
			addUnique( evidence.fatal_response_errors, {
				type: 'http',
				method: response.request().method(),
				status,
				url: safeUrl( url ),
			} );
		}
	} );
}

function screenshotPath( surface, kind ) {
	const extension = path.extname( evidencePath );
	const stem = extension
		? evidencePath.slice( 0, -extension.length )
		: evidencePath;
	return `${ stem }-${ store }-${ surface }-${ kind }.png`;
}

async function captureScreenshot( page, surface, kind, targetSurface = null ) {
	const destination = screenshotPath( surface, kind );
	await page.screenshot( {
		path: destination,
		fullPage: false,
		scale: 'css',
		animations: 'disabled',
	} );
	if (
		targetSurface &&
		! targetSurface.screenshot_paths.includes( destination )
	) {
		targetSurface.screenshot_paths.push( destination );
	}
	return destination;
}

async function safeSnapshot( page, pattern ) {
	try {
		return await snapshot( { page, search: pattern } );
	} catch ( error ) {
		return '';
	}
}

function formatMinorUnits( rawValue, rawMinorUnit ) {
	const value = String( rawValue ?? '' );
	const minorUnit = Number.parseInt( String( rawMinorUnit ?? '2' ), 10 );
	if (
		! /^-?\d+$/.test( value ) ||
		! Number.isInteger( minorUnit ) ||
		minorUnit < 0 ||
		minorUnit > 6
	) {
		throw new Error( 'Store API returned an invalid cart total.' );
	}
	const negative = value.startsWith( '-' );
	const digits = negative ? value.slice( 1 ) : value;
	if ( minorUnit === 0 ) {
		return `${ negative ? '-' : '' }${ digits }`;
	}
	const padded = digits.padStart( minorUnit + 1, '0' );
	return `${ negative ? '-' : '' }${ padded.slice(
		0,
		-minorUnit
	) }.${ padded.slice( -minorUnit ) }`;
}

async function fetchStoreCart( page ) {
	const result = await page.evaluate( async () => {
		const endpoints = [
			'/wp-json/wc/store/v1/cart',
			'/?rest_route=/wc/store/v1/cart',
		];
		const attempts = [];
		for ( const endpoint of endpoints ) {
			try {
				const response = await fetch( endpoint, {
					credentials: 'same-origin',
				} );
				if ( response.ok ) {
					return {
						ok: true,
						endpoint,
						nonce: response.headers.get( 'Nonce' ) || '',
						cartToken: response.headers.get( 'Cart-Token' ) || '',
						cart: await response.json(),
					};
				}
				attempts.push( { endpoint, status: response.status } );
			} catch ( error ) {
				attempts.push( {
					endpoint,
					error: error?.message || String( error ),
				} );
			}
		}
		return { ok: false, attempts };
	} );

	if ( ! result.ok ) {
		throw new Error(
			`Unable to read the Store API cart: ${ JSON.stringify(
				result.attempts
			) }`
		);
	}
	return result;
}

async function emptyStoreCart( page ) {
	const initial = await fetchStoreCart( page );
	if (
		! Array.isArray( initial.cart?.items ) ||
		initial.cart.items.length === 0
	) {
		return;
	}

	const result = await page.evaluate(
		async ( { endpoint, nonce, cartToken, keys } ) => {
			const errors = [];
			for ( const key of keys ) {
				const itemEndpoint = endpoint.includes( 'rest_route=' )
					? `/?rest_route=/wc/store/v1/cart/items/${ encodeURIComponent(
							key
					  ) }`
					: `${ endpoint }/items/${ encodeURIComponent( key ) }`;
				const headers = {};
				if ( nonce ) {
					headers.Nonce = nonce;
				}
				if ( cartToken ) {
					headers[ 'Cart-Token' ] = cartToken;
				}
				try {
					const response = await fetch( itemEndpoint, {
						method: 'DELETE',
						credentials: 'same-origin',
						headers,
					} );
					if ( response.status !== 204 && ! response.ok ) {
						errors.push( { key, status: response.status } );
					}
				} catch ( error ) {
					errors.push( {
						key,
						error: error?.message || String( error ),
					} );
				}
			}
			return errors;
		},
		{
			endpoint: initial.endpoint,
			nonce: initial.nonce,
			cartToken: initial.cartToken,
			keys: initial.cart.items.map( ( item ) => item.key ),
		}
	);

	if ( result.length > 0 ) {
		throw new Error(
			`Unable to empty the Store API cart: ${ JSON.stringify( result ) }`
		);
	}
}

async function prepareSingleProductCart( page ) {
	await page.goto( `${ baseUrl }/`, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await emptyStoreCart( page );
	const addToCartUrl = `${ baseUrl }/?add-to-cart=${ encodeURIComponent(
		productId
	) }&quantity=1`;
	const addResponse = await page.goto( addToCartUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	if ( addResponse && addResponse.status() >= 400 ) {
		throw new Error(
			`Add-to-cart navigation failed with HTTP ${ addResponse.status() }.`
		);
	}

	const cartResult = await fetchStoreCart( page );
	const cart = cartResult.cart || {};
	const matchingItem = ( cart.items || [] ).find(
		( item ) =>
			Number( item.id ) === productId ||
			Number( item.variation_id ) === productId
	);
	if ( ! matchingItem ) {
		throw new Error(
			`Product ${ productId } was not present in the Store API cart after add-to-cart.`
		);
	}
	const totals = cart.totals || {};
	const currency = String( totals.currency_code || '' ).toUpperCase();
	if ( ! currency ) {
		throw new Error(
			'Store API cart totals did not include a currency code.'
		);
	}
	return {
		amount: formatMinorUnits(
			totals.total_price,
			totals.currency_minor_unit
		),
		currency,
	};
}

async function selectWooPaymentsGateway( page ) {
	const selectors = [
		'input[name="payment_method"][value="woocommerce_payments"]',
		'#payment_method_woocommerce_payments',
		'#radio-control-wc-payment-method-options-woocommerce_payments',
	];
	for ( const selector of selectors ) {
		const input = page.locator( selector ).first();
		if ( ( await input.count().catch( () => 0 ) ) === 0 ) {
			continue;
		}
		if ( await input.isChecked().catch( () => false ) ) {
			return;
		}
		await input.check( { timeout: 10000 } ).catch( async () => {
			const id = await input.getAttribute( 'id' );
			const label = id
				? page.locator( `label[for=${ JSON.stringify( id ) }]` ).first()
				: null;
			if ( label && ( await label.isVisible().catch( () => false ) ) ) {
				await label.click( { timeout: 10000 } );
			} else {
				await input.click( { timeout: 10000 } );
			}
		} );
		return;
	}
}

async function tokenInputFacts( input ) {
	return input.evaluate( ( element ) => {
		const isVisible = ( candidate ) => {
			if ( ! candidate ) {
				return false;
			}
			const style = window.getComputedStyle( candidate );
			const rect = candidate.getBoundingClientRect();
			return (
				style.display !== 'none' &&
				style.visibility !== 'hidden' &&
				Number( style.opacity ) !== 0 &&
				rect.width > 0 &&
				rect.height > 0
			);
		};
		const labels = Array.from( element.labels || [] );
		const container = element.closest(
			'label, li, .wc-block-components-radio-control__option, .woocommerce-SavedPaymentMethods-saveNew, .woocommerce-SavedPaymentMethods-token'
		);
		const representation =
			labels.find( isVisible ) ||
			( isVisible( container ) ? container : null ) ||
			( isVisible( element ) ? element : null );
		return {
			visible: Boolean( representation ),
			checked: Boolean( element.checked ),
			id: element.id || '',
			name: element.name || '',
			value: element.value || '',
			text: ( representation?.textContent || '' )
				.replace( /\s+/g, ' ' )
				.trim(),
		};
	} );
}

function isSavedTokenInputName( name ) {
	return (
		name === 'wc-woocommerce_payments-payment-token' ||
		name === 'radio-control-wc-payment-method-saved-tokens' ||
		/payment-token/i.test( name )
	);
}

async function findExactSavedTokenInput( page, tokenId ) {
	const tokenValue = String( tokenId );
	await page.waitForFunction(
		( expectedValue ) =>
			Array.from( document.querySelectorAll( 'input' ) ).some(
				( input ) =>
					input.value === expectedValue &&
					( input.name === 'wc-woocommerce_payments-payment-token' ||
						input.name ===
							'radio-control-wc-payment-method-saved-tokens' ||
						/payment-token/i.test( input.name ) )
			),
		tokenValue,
		{ timeout: 30000 }
	);

	const inputs = page.locator( 'input' );
	const matches = [];
	for ( let index = 0; index < ( await inputs.count() ); index++ ) {
		const input = inputs.nth( index );
		const value = await input.getAttribute( 'value' );
		const name = ( await input.getAttribute( 'name' ) ) || '';
		if ( value === tokenValue && isSavedTokenInputName( name ) ) {
			matches.push( { input, facts: await tokenInputFacts( input ) } );
		}
	}
	const selected =
		matches.find( ( match ) => match.facts.visible ) || matches[ 0 ];
	if ( ! selected ) {
		throw new Error( `Saved-card token ${ tokenId } was not rendered.` );
	}
	return selected;
}

async function selectExactSavedToken( page, tokenId ) {
	await selectWooPaymentsGateway( page );
	const selected = await findExactSavedTokenInput( page, tokenId );
	const { input } = selected;
	if ( ! ( await input.isChecked().catch( () => false ) ) ) {
		const id = await input.getAttribute( 'id' );
		const label = id
			? page.locator( `label[for=${ JSON.stringify( id ) }]` ).first()
			: null;
		if ( await input.isVisible().catch( () => false ) ) {
			await input.check( { timeout: 10000 } );
		} else if (
			label &&
			( await label.isVisible().catch( () => false ) )
		) {
			await label.click( { timeout: 10000 } );
		} else {
			await input.check( { timeout: 10000, force: true } );
		}
	}
	const facts = await tokenInputFacts( input );
	return {
		visible: facts.visible,
		selected:
			facts.checked || ( await input.isChecked().catch( () => false ) ),
	};
}

async function visibleNewCardFieldsForced( page ) {
	const frameSelectors = [
		'#payment .wcpay-upe-element iframe',
		'#payment #wcpay-card-element iframe',
		'#payment-method .wcpay-payment-element iframe',
		'.wc-block-components-payment-method-content .wcpay-payment-element iframe',
		'.wc-block-components-payment-method-content .wcpay-upe-element iframe',
	];
	for ( const selector of frameSelectors ) {
		const frames = page.locator( selector );
		for ( let index = 0; index < ( await frames.count() ); index++ ) {
			if (
				await frames
					.nth( index )
					.isVisible()
					.catch( () => false )
			) {
				return true;
			}
		}
	}
	return false;
}

function checkoutResponseMatches( response, surfaceType ) {
	const url = response.url();
	if ( response.request().method() !== 'POST' ) {
		return false;
	}
	return surfaceType === 'classic'
		? /[?&]wc-ajax=checkout(?:&|$)|\/wc-ajax\/checkout(?:\/|\?|$)/i.test(
				url
		  )
		: /\/wc\/store\/v1\/checkout(?:\?|$)|[?&]rest_route=(?:%2F|\/)wc(?:%2F|\/)store(?:%2F|\/)v1(?:%2F|\/)checkout/i.test(
				url
		  );
}

function numericOrderId( value ) {
	const parsed = Number.parseInt( String( value || '' ), 10 );
	return Number.isInteger( parsed ) && parsed > 0 ? parsed : null;
}

function orderIdFromUrl( url ) {
	const match = String( url || '' ).match( /order-received(?:\/|=)(\d+)/i );
	return match ? numericOrderId( match[ 1 ] ) : null;
}

async function summarizeCheckoutResponse( response ) {
	let body = null;
	try {
		body = await response.json();
	} catch ( error ) {
		// Some classic checkout failures return HTML. Status and URL remain evidence.
	}
	const redirect =
		body?.redirect ||
		body?.redirect_url ||
		body?.payment_result?.redirect_url ||
		body?.payment_result?.redirect ||
		'';
	const orderId =
		numericOrderId( body?.order_id ) ||
		numericOrderId( body?.orderId ) ||
		orderIdFromUrl( redirect );
	return {
		status: response.status(),
		ok: response.ok(),
		url: safeUrl( response.url() ),
		checkout_status:
			typeof body?.status === 'string'
				? redactSensitiveText( body.status )
				: null,
		result:
			typeof body?.result === 'string'
				? redactSensitiveText( body.result )
				: null,
		payment_status:
			typeof body?.payment_status === 'string'
				? redactSensitiveText( body.payment_status )
				: typeof body?.payment_result?.payment_status === 'string'
				? redactSensitiveText( body.payment_result.payment_status )
				: null,
		order_id: orderId,
		redirect_url: redirect ? safeUrl( redirect ) : '',
	};
}

async function clickPlaceOrder( page, surfaceType ) {
	const candidates =
		surfaceType === 'classic'
			? [
					page
						.getByRole( 'button', { name: /^Place order$/i } )
						.first(),
					page.locator( '#place_order' ).first(),
					page
						.locator(
							'button[name="woocommerce_checkout_place_order"]'
						)
						.first(),
			  ]
			: [
					page
						.getByRole( 'button', { name: /^Place Order$/i } )
						.first(),
					page
						.locator(
							'.wc-block-components-checkout-place-order-button'
						)
						.first(),
			  ];
	for ( const button of candidates ) {
		if (
			( await button.count().catch( () => 0 ) ) > 0 &&
			( await button.isVisible().catch( () => false ) )
		) {
			await button.focus();
			await button.click( { timeout: 20000 } );
			return;
		}
	}
	throw new Error(
		`${ surfaceType } checkout Place order button was not found.`
	);
}

async function acceptTerms( page ) {
	const terms = page.getByLabel( /terms and conditions/i ).first();
	if (
		( await terms.count().catch( () => 0 ) ) > 0 &&
		( await terms.isVisible().catch( () => false ) )
	) {
		await terms.check( { timeout: 10000 } );
		return;
	}
	await page
		.locator( 'input[name="terms"], #terms' )
		.first()
		.check( { timeout: 10000 } )
		.catch( () => {} );
}

function stripeChallengeLocators( page ) {
	const outerSelector =
		'body > div > iframe[name^="__privateStripeFrame"], iframe[title*="Secure payment confirmation"]';
	const outer = page.locator( outerSelector ).last();
	const stripeFrame = page.frameLocator( outerSelector ).last();
	const challengeFrame = stripeFrame.frameLocator(
		'iframe[name="stripe-challenge-frame"]'
	);
	return {
		outer,
		stripeFrame,
		button: challengeFrame.getByRole( 'button', {
			name: 'Complete',
			exact: true,
		} ),
	};
}

async function isStripeChallengeVisible( page ) {
	const { button } = stripeChallengeLocators( page );
	return button.isVisible().catch( () => false );
}

async function completeStripeChallenge( page, surface, targetSurface = null ) {
	const { outer, stripeFrame, button } = stripeChallengeLocators( page );
	await button.waitFor( { state: 'visible', timeout: 45000 } );
	const challengeScreenshot = await captureScreenshot(
		page,
		surface,
		'sca-challenge',
		targetSurface
	);
	await stripeFrame
		.locator( '.LightboxModalLoadingIndicator' )
		.waitFor( { state: 'hidden', timeout: 20000 } )
		.catch( () => {} );
	await button.click( { timeout: 20000 } );
	try {
		await Promise.any( [
			outer.waitFor( { state: 'hidden', timeout: 45000 } ),
			page.waitForURL(
				/order-received|\/my-account\/payment-methods\/?/i,
				{ timeout: 45000 }
			),
		] );
	} catch ( error ) {
		throw new Error(
			'Stripe challenge did not close after Complete was selected.'
		);
	}
	return {
		present: true,
		completed: true,
		screenshot: challengeScreenshot,
	};
}

async function waitForOrderReceived( page ) {
	await page.waitForURL( /order-received(?:\/|=)/i, { timeout: 90000 } );
	await page
		.waitForLoadState( 'domcontentloaded', { timeout: 30000 } )
		.catch( () => {} );
	const finalUrl = page.url();
	const snapshotText = await safeSnapshot(
		page,
		/Order received|Thank you|Order\s+#?\d+|payment failed|error/i
	);
	const textOrderMatch = String( snapshotText ).match( /Order\s+#?(\d+)/i );
	const orderId =
		orderIdFromUrl( finalUrl ) ||
		( textOrderMatch ? numericOrderId( textOrderMatch[ 1 ] ) : null );
	if ( ! orderId ) {
		throw new Error(
			`Order-received URL did not contain a numeric order ID: ${ safeUrl(
				finalUrl
			) }`
		);
	}
	return { orderId, finalUrl };
}

async function runCheckoutSurface(
	page,
	surfaceName,
	surfaceType,
	tokenId,
	requiresSca
) {
	const result = evidence.surfaces[ surfaceName ];
	await resetShopperSession();
	const expected = await prepareSingleProductCart( page );
	result.expected_amount = expected.amount;
	result.expected_currency = expected.currency;

	const checkoutUrl =
		surfaceType === 'classic' ? classicCheckoutUrl : blocksCheckoutUrl;
	await page.goto( checkoutUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await waitForPageLoad( { page, timeout: 20000 } );
	const tokenState = await selectExactSavedToken( page, tokenId );
	result.selected_token_id = tokenId;
	result.saved_card_visible = tokenState.visible;
	result.saved_card_selected = tokenState.selected;
	result.new_card_fields_forced = await visibleNewCardFieldsForced( page );
	if ( ! result.saved_card_visible ) {
		result.errors.push(
			`Saved-card token ${ tokenId } did not have a visible checkout representation.`
		);
	}
	if ( ! result.saved_card_selected ) {
		result.errors.push( `Saved-card token ${ tokenId } was not selected.` );
	}
	if ( result.new_card_fields_forced ) {
		result.errors.push(
			`Selecting saved-card token ${ tokenId } forced visible new-card fields.`
		);
	}
	await acceptTerms( page );
	await captureScreenshot( page, surfaceName, 'selected', result );

	const checkoutResponsePromise = page
		.waitForResponse(
			( response ) => checkoutResponseMatches( response, surfaceType ),
			{ timeout: 120000 }
		)
		.then( summarizeCheckoutResponse )
		.catch( ( error ) => ( { capture_error: safeErrorMessage( error ) } ) );
	await clickPlaceOrder( page, surfaceType );

	if ( requiresSca ) {
		const challenge = await completeStripeChallenge(
			page,
			surfaceName,
			result
		);
		result.sca_challenge_present = challenge.present;
		result.sca_challenge_completed = challenge.completed;
	} else {
		result.sca_challenge_present = await isStripeChallengeVisible( page );
		result.sca_challenge_completed = false;
	}

	const order = await waitForOrderReceived( page );
	result.order_id = order.orderId;
	result.final_url = safeUrl( order.finalUrl );
	result.checkout_response = await checkoutResponsePromise;
	await captureScreenshot( page, surfaceName, 'order-received', result );

	if ( result.checkout_response.capture_error ) {
		result.errors.push(
			`Checkout response was not captured: ${ result.checkout_response.capture_error }`
		);
	} else {
		if ( ! result.checkout_response.ok ) {
			result.errors.push(
				`Checkout response returned HTTP ${ result.checkout_response.status }.`
			);
		}
		if (
			result.checkout_response.order_id &&
			result.checkout_response.order_id !== result.order_id
		) {
			result.errors.push(
				`Checkout response order ${ result.checkout_response.order_id } did not match final order ${ result.order_id }.`
			);
		}
	}
	if (
		requiresSca &&
		( ! result.sca_challenge_present || ! result.sca_challenge_completed )
	) {
		result.errors.push(
			'The saved 3DS card checkout did not complete the Stripe challenge.'
		);
	}
	if ( ! requiresSca && result.sca_challenge_present ) {
		result.errors.push(
			'The normal saved card unexpectedly presented a Stripe challenge.'
		);
	}
	result.success = result.errors.length === 0;
	writeEvidence();
}

const paymentFrameSelectors = [
	'iframe[title="Secure payment input frame"]',
	'.wcpay-upe-element iframe[name^="__privateStripeFrame"]',
	'#wcpay-card-element iframe[name^="__privateStripeFrame"]',
	'.wcpay-payment-element iframe[name^="__privateStripeFrame"]',
];

async function findStripeField( page, locatorFactories, timeout = 30000 ) {
	const deadline = Date.now() + timeout;
	while ( Date.now() < deadline ) {
		for ( const frameSelector of paymentFrameSelectors ) {
			const frameCount = await page
				.locator( frameSelector )
				.count()
				.catch( () => 0 );
			for ( let frameIndex = 0; frameIndex < frameCount; frameIndex++ ) {
				const frame = page
					.frameLocator( frameSelector )
					.nth( frameIndex );
				for ( const createLocator of locatorFactories ) {
					const field = createLocator( frame ).first();
					if (
						( await field.count().catch( () => 0 ) ) > 0 &&
						( await field.isVisible().catch( () => false ) )
					) {
						return field;
					}
				}
			}
		}
		// Stripe mounts its secure iframe asynchronously; this is a bounded stabilization poll.
		await page.waitForTimeout( 250 );
	}
	return null;
}

async function fillScaCard( page ) {
	const number = await findStripeField( page, [
		( frame ) => frame.getByPlaceholder( /1234\s+1234\s+1234(?:\s+1234)?/ ),
		( frame ) => frame.getByLabel( /card number/i ),
		( frame ) =>
			frame.locator(
				'input[name="number"], input[name="cardnumber"], input[autocomplete="cc-number"]'
			),
	] );
	if ( ! number ) {
		throw new Error(
			'Stripe card-number field was not found on Add payment method.'
		);
	}
	await number.fill( scaCardNumber );

	const expiry = await findStripeField( page, [
		( frame ) => frame.getByPlaceholder( /MM\s*\/\s*YY/i ),
		( frame ) => frame.getByLabel( /expir/i ),
		( frame ) =>
			frame.locator(
				'input[name="expiry"], input[name="exp-date"], input[autocomplete="cc-exp"]'
			),
	] );
	const cvc = await findStripeField( page, [
		( frame ) => frame.getByPlaceholder( /CVC|CVV/i ),
		( frame ) => frame.getByLabel( /security code|cvc|cvv/i ),
		( frame ) =>
			frame.locator( 'input[name="cvc"], input[autocomplete="cc-csc"]' ),
	] );
	if ( ! expiry || ! cvc ) {
		throw new Error(
			'Stripe expiry or CVC field was not found on Add payment method.'
		);
	}
	await expiry.fill( '1230' );
	await cvc.fill( '123' );

	const country = await findStripeField(
		page,
		[
			( frame ) => frame.getByRole( 'combobox', { name: /country/i } ),
			( frame ) => frame.locator( 'select[name="country"]' ),
		],
		1500
	);
	if ( country ) {
		await country.selectOption( 'US' );
	}
	const postalCode = await findStripeField(
		page,
		[
			( frame ) => frame.getByLabel( /ZIP|postal/i ),
			( frame ) =>
				frame.locator(
					'input[name="postalCode"], input[autocomplete="postal-code"]'
				),
		],
		1500
	);
	if ( postalCode ) {
		await postalCode.fill( '90210' );
	}
}

async function clickAddPaymentMethod( page ) {
	const candidates = [
		page.getByRole( 'button', { name: /^Add payment method$/i } ).first(),
		page.locator( 'button[name="save_card"]' ).first(),
		page
			.locator( 'button[type="submit"]' )
			.filter( { hasText: /Add payment method/i } )
			.first(),
	];
	for ( const button of candidates ) {
		if (
			( await button.count().catch( () => 0 ) ) > 0 &&
			( await button.isVisible().catch( () => false ) )
		) {
			await button.focus();
			await button.click( { timeout: 20000 } );
			return;
		}
	}
	throw new Error( 'My Account Add payment method button was not found.' );
}

async function addScaPaymentMethod( page ) {
	const paymentMethodsUrl = `${ baseUrl }/my-account/payment-methods/`;
	const addPaymentMethodUrl = `${ baseUrl }/my-account/add-payment-method/`;
	await page.goto( paymentMethodsUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await waitForPageLoad( { page, timeout: 20000 } );
	if (
		( await page
			.locator( 'form.woocommerce-form-login, form.login' )
			.count()
			.catch( () => 0 ) ) > 0
	) {
		throw new Error(
			'The authentication cookie did not establish a My Account session.'
		);
	}
	const addPaymentMethodLink = page
		.getByRole( 'link', { name: /^Add payment method$/i } )
		.first();
	if ( await addPaymentMethodLink.isVisible().catch( () => false ) ) {
		await addPaymentMethodLink.click( { timeout: 10000 } );
		await page
			.waitForURL( /\/my-account\/add-payment-method\/?/i, {
				timeout: 30000,
			} )
			.catch( () => {} );
	} else {
		await page.goto( addPaymentMethodUrl, {
			waitUntil: 'domcontentloaded',
			timeout: 30000,
		} );
	}
	await page
		.locator( 'input[name="payment_method"]' )
		.first()
		.waitFor( { state: 'attached', timeout: 30000 } );
	await selectWooPaymentsGateway( page );
	const cardChoice = page.getByText( 'Card', { exact: true } ).first();
	if (
		( await cardChoice.count().catch( () => 0 ) ) > 0 &&
		( await cardChoice.isVisible().catch( () => false ) )
	) {
		await cardChoice.click( { timeout: 10000 } ).catch( () => {} );
	}
	await fillScaCard( page );
	await clickAddPaymentMethod( page );
	const challenge = await completeStripeChallenge( page, 'sca-token-setup' );

	await page
		.waitForFunction(
			() =>
				/my-account\/payment-methods\/?/i.test(
					window.location.pathname
				) ||
				/Payment method successfully added\./i.test(
					document.body?.innerText || ''
				),
			null,
			{ timeout: 60000 }
		)
		.catch( async () => {
			const diagnostic = await safeSnapshot(
				page,
				/successfully added|cannot add|not able to add|error|payment method/i
			);
			throw new Error(
				`My Account did not confirm the saved 3DS card.${
					diagnostic ? ' A payment-method error was visible.' : ''
				}`
			);
		} );
	return challenge.screenshot;
}

function numericTokenIdFromText( value ) {
	let text = String( value || '' );
	for ( let index = 0; index < 3; index++ ) {
		try {
			const decoded = decodeURIComponent( text );
			if ( decoded === text ) {
				break;
			}
			text = decoded;
		} catch ( error ) {
			break;
		}
	}
	const match = text.match(
		/(?:delete-payment-method|set-default-payment-method)(?:=|\/)(\d+)|(?:payment-method|token(?:_|-)?id)=(\d+)/i
	);
	return match ? numericOrderId( match[ 1 ] || match[ 2 ] ) : null;
}

async function discoverScaTokenFromMyAccount( page ) {
	await page.goto( `${ baseUrl }/my-account/payment-methods/`, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await waitForPageLoad( { page, timeout: 20000 } );
	const containers = page
		.locator( 'tr, li, .woocommerce-PaymentMethod, .payment-method' )
		.filter( { hasText: new RegExp( scaCardLast4 ) } );
	for ( let index = 0; index < ( await containers.count() ); index++ ) {
		const attributes = await containers
			.nth( index )
			.evaluate( ( container ) =>
				[ container, ...container.querySelectorAll( '*' ) ].flatMap(
					( element ) =>
						Array.from( element.attributes || [] ).map(
							( attribute ) =>
								`${ attribute.name }=${ attribute.value }`
						)
				)
			);
		for ( const attribute of attributes ) {
			const tokenId = numericTokenIdFromText( attribute );
			if ( tokenId ) {
				return tokenId;
			}
		}
	}
	return null;
}

async function discoverScaTokenFromCheckout( page ) {
	await prepareSingleProductCart( page );
	await page.goto( classicCheckoutUrl, {
		waitUntil: 'domcontentloaded',
		timeout: 30000,
	} );
	await waitForPageLoad( { page, timeout: 20000 } );
	await selectWooPaymentsGateway( page );
	await page.waitForFunction(
		( last4 ) =>
			/3155/.test( document.body?.innerText || '' ) ||
			( document.body?.innerText || '' ).includes( last4 ),
		scaCardLast4,
		{ timeout: 30000 }
	);
	const inputs = page.locator( 'input' );
	for ( let index = 0; index < ( await inputs.count() ); index++ ) {
		const input = inputs.nth( index );
		const name = ( await input.getAttribute( 'name' ) ) || '';
		const value = ( await input.getAttribute( 'value' ) ) || '';
		if ( ! isSavedTokenInputName( name ) || ! /^\d+$/.test( value ) ) {
			continue;
		}
		const facts = await tokenInputFacts( input );
		if ( facts.text.includes( scaCardLast4 ) ) {
			return Number.parseInt( value, 10 );
		}
	}
	return null;
}

async function discoverScaTokenId( page ) {
	const fromAccount = await discoverScaTokenFromMyAccount( page );
	const tokenId =
		fromAccount || ( await discoverScaTokenFromCheckout( page ) );
	if ( ! Number.isInteger( tokenId ) || tokenId <= 0 ) {
		throw new Error(
			`Could not discover a numeric WooCommerce token ID for the saved card ending in ${ scaCardLast4 }.`
		);
	}
	if ( tokenId === normalTokenId ) {
		throw new Error(
			'The saved 3DS card resolved to the normal-card token ID.'
		);
	}
	return tokenId;
}

async function runSurfaceSafely(
	page,
	surfaceName,
	surfaceType,
	tokenId,
	requiresSca
) {
	try {
		await runCheckoutSurface(
			page,
			surfaceName,
			surfaceType,
			tokenId,
			requiresSca
		);
	} catch ( error ) {
		const result = evidence.surfaces[ surfaceName ];
		result.final_url = safeUrl( page.url() );
		result.sca_challenge_present =
			result.sca_challenge_present ||
			( await isStripeChallengeVisible( page ) );
		result.errors.push( safeErrorMessage( error ) );
		await captureScreenshot( page, surfaceName, 'failure', result ).catch(
			( screenshotError ) => {
				result.errors.push(
					`Failure screenshot was not captured: ${ safeErrorMessage(
						screenshotError
					) }`
				);
			}
		);
		addUnique(
			evidence.errors,
			`${ surfaceName }: ${ safeErrorMessage( error ) }`
		);
		writeEvidence();
	}
	await drainBrowserLogs( page );
	writeEvidence();
}

async function run() {
	writeEvidence();
	validateConfig();

	const page =
		context.pages().find( ( candidate ) => ! candidate.isClosed() ) ||
		( await context.newPage() );
	state.sc04SavedCardPage = page;
	await page.setViewportSize( { width: 1440, height: 1000 } );
	installResponseClassifier( page );
	await getLatestLogs( { page, sinceLastCall: true } ).catch( () => [] );

	await runSurfaceSafely( page, 'classic', 'classic', normalTokenId, false );
	await runSurfaceSafely( page, 'blocks', 'blocks', normalTokenId, false );

	let scaTokenId =
		Number.isInteger( existingScaTokenId ) && existingScaTokenId > 0
			? existingScaTokenId
			: null;
	if ( scaTokenId ) {
		evidence.sca_token_id = scaTokenId;
		evidence.sca_token_origin = 'existing';
		writeEvidence();
	} else {
		try {
			await resetShopperSession();
			const setupScreenshot = await addScaPaymentMethod( page );
			scaTokenId = await discoverScaTokenId( page );
			evidence.sca_token_id = scaTokenId;
			evidence.sca_token_origin = 'created';
			for ( const surfaceName of [ 'sca_classic', 'sca_blocks' ] ) {
				evidence.surfaces[ surfaceName ].screenshot_paths.push(
					setupScreenshot
				);
			}
			writeEvidence();
		} catch ( error ) {
			const message = safeErrorMessage( error );
			addUnique( evidence.errors, `sca_token_setup: ${ message }` );
			for ( const surfaceName of [ 'sca_classic', 'sca_blocks' ] ) {
				evidence.surfaces[ surfaceName ].errors.push(
					`Saved 3DS card setup failed: ${ message }`
				);
			}
			await captureScreenshot( page, 'sca-token-setup', 'failure' ).catch(
				() => {}
			);
			writeEvidence();
		}
	}
	await drainBrowserLogs( page );

	if ( scaTokenId ) {
		await runSurfaceSafely(
			page,
			'sca_classic',
			'classic',
			scaTokenId,
			true
		);
		await runSurfaceSafely(
			page,
			'sca_blocks',
			'blocks',
			scaTokenId,
			true
		);
	}

	await drainBrowserLogs( page );
	if ( evidence.fatal_console_errors.length > 0 ) {
		addUnique(
			evidence.errors,
			'Fatal browser console errors were captured.'
		);
	}
	if ( evidence.fatal_response_errors.length > 0 ) {
		addUnique(
			evidence.errors,
			'Fatal browser response errors were captured.'
		);
	}
	const failedSurfaces = Object.entries( evidence.surfaces )
		.filter( ( [ , result ] ) => ! result.success )
		.map( ( [ name ] ) => name );
	writeEvidence();

	console.log(
		JSON.stringify( {
			success:
				failedSurfaces.length === 0 && evidence.errors.length === 0,
			store,
			evidencePath,
			orders: Object.fromEntries(
				Object.entries( evidence.surfaces ).map(
					( [ name, result ] ) => [ name, result.order_id ]
				)
			),
		} )
	);
	if ( failedSurfaces.length > 0 || evidence.errors.length > 0 ) {
		throw new Error(
			`SC-04 saved-card browser evidence failed for ${
				failedSurfaces.length > 0
					? failedSurfaces.join( ', ' )
					: 'browser diagnostics'
			}.`
		);
	}
}

await run().catch( async ( error ) => {
	addUnique( evidence.errors, safeErrorMessage( error ) );
	const page = state.sc04SavedCardPage;
	if ( page && ! page.isClosed() ) {
		await drainBrowserLogs( page );
	}
	writeEvidence();
	throw new Error( safeErrorMessage( error ) );
} );
