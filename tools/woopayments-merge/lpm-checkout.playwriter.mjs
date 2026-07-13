// WooPayments local payment-method checkout driver.
//
// This script runs inside an authenticated local Playwriter session. It drives
// one requested split WooPayments gateway through checkout and writes evidence
// that lpm-checkout-gate.sh validates for both the reference plugin store and
// native target store.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const env = typeof process !== 'undefined' && process.env ? process.env : {};
const stateConfig =
	typeof state !== 'undefined' && state.lpmCheckoutConfig && 'object' === typeof state.lpmCheckoutConfig
		? state.lpmCheckoutConfig
		: {};

function requiredConfig( envName, stateName ) {
	const value = env[ envName ] || stateConfig[ stateName ];
	if ( ! value ) {
		throw new Error( `Missing required browser gate config ${ envName }` );
	}
	return value;
}

const role = requiredConfig( 'LPM_GATE_ROLE', 'role' );
const method = requiredConfig( 'LPM_GATE_METHOD', 'method' );
const surface = requiredConfig( 'LPM_GATE_SURFACE', 'surface' );
const baseUrl = requiredConfig( 'LPM_GATE_BASE_URL', 'baseUrl' ).replace( /\/+$/, '' );
const currency = requiredConfig( 'LPM_GATE_CURRENCY', 'currency' );
const country = requiredConfig( 'LPM_GATE_COUNTRY', 'country' );
const gatewayId = requiredConfig( 'LPM_GATE_GATEWAY_ID', 'gatewayId' );
const stripePaymentMethodType = requiredConfig( 'LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE', 'stripePaymentMethodType' );
const methodFamily = requiredConfig( 'LPM_GATE_METHOD_FAMILY', 'methodFamily' );
const automationDisposition = requiredConfig( 'LPM_GATE_AUTOMATION_DISPOSITION', 'automationDisposition' );
const productId = requiredConfig( 'LPM_GATE_PRODUCT_ID', 'productId' );
const checkoutPageId = requiredConfig( 'LPM_GATE_CHECKOUT_PAGE_ID', 'checkoutPageId' );
const evidencePath = requiredConfig( 'LPM_GATE_EVIDENCE_PATH', 'evidencePath' );
const startedAt = new Date().toISOString();
const addToCartUrl = `${ baseUrl }/?add-to-cart=${ encodeURIComponent( productId ) }&quantity=1&lpm-gate-method=${ encodeURIComponent( method ) }&lpm-gate-surface=${ encodeURIComponent( surface ) }`;
const checkoutUrl = `${ baseUrl }/?page_id=${ encodeURIComponent( checkoutPageId ) }&lpm-gate-method=${ encodeURIComponent( method ) }&lpm-gate-surface=${ encodeURIComponent( surface ) }`;
let latestCheckoutRequests = [];
let latestCheckoutResponses = [];

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

const klarnaTestPhoneByCountry = {
	US: '+13106683312',
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
				redactSensitiveValue( {
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
					automation_disposition: automationDisposition,
				product_id: productId,
				checkout_page_id: checkoutPageId,
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
				} ),
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
	return [
		...new Set(
			matches
				.map( ( candidate ) => candidate.split( '_secret_' )[ 0 ] )
				.filter(
					( candidate ) =>
						/^pi_[A-Za-z0-9_]{8,}$/.test( candidate ) &&
						! /^pi_client_secret\b/.test( candidate )
				)
		),
	];
}

function redactSensitiveText( value ) {
	return String( value || '' )
		.replace( /pi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/g, 'pi_[redacted]_secret_[redacted]' )
		.replace( /seti_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/g, 'seti_[redacted]_secret_[redacted]' )
		.replace( /([?&](?:payment_intent_client_secret|setup_intent_client_secret|client_secret|token)=)[^&\s"']+/gi, '$1[redacted]' );
}

function redactSensitiveValue( value ) {
	if ( typeof value === 'string' ) {
		return redactSensitiveText( value );
	}
	if ( Array.isArray( value ) ) {
		return value.map( redactSensitiveValue );
	}
	if ( value && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value ).map( ( [ key, nestedValue ] ) => [
				key,
				redactSensitiveValue( nestedValue ),
			] )
		);
	}
	return value;
}

function plainTextSample( value, maxLength = 1600 ) {
	return redactSensitiveText( value )
		.replace( /<script[\s\S]*?<\/script>/gi, ' ' )
		.replace( /<style[\s\S]*?<\/style>/gi, ' ' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
		.slice( 0, maxLength );
}

function isCheckoutAjaxResponse( url ) {
	return /[?&]wc-ajax=checkout(?:&|$)|\/wc-ajax\/checkout(?:[/?#]|$)/i.test( url );
}

function isCartStateProbeResponse( url ) {
	const decodedUrl = decodeURIComponent( String( url || '' ) );
	return (
		/\/wp-json\/wc\/store\/v1\/cart(?:[?#]|$)/i.test( decodedUrl ) ||
		/[?&]rest_route=\/wc\/store\/v1\/cart(?:&|$)/i.test( decodedUrl )
	);
}

function summarizeCheckoutResponse( url, status, text ) {
	const summary = {
		status,
		url: redactSensitiveText( url ),
		result: null,
		order_id: null,
		messages: '',
		redirect: '',
		body_sample: '',
	};

	try {
		const payload = JSON.parse( text );
		if ( payload && typeof payload === 'object' ) {
			if ( typeof payload.result === 'string' ) {
				summary.result = payload.result;
			}
			if ( typeof payload.order_id === 'number' || ( typeof payload.order_id === 'string' && /^\d+$/.test( payload.order_id ) ) ) {
				summary.order_id = Number.parseInt( String( payload.order_id ), 10 );
			}
			if ( typeof payload.messages === 'string' ) {
				summary.messages = plainTextSample( payload.messages );
			}
			if ( typeof payload.redirect === 'string' ) {
				summary.redirect = redactSensitiveText( payload.redirect ).slice( 0, 1600 );
			}
			if ( typeof payload.error === 'string' ) {
				summary.body_sample = plainTextSample( payload.error );
			} else if ( payload.data && typeof payload.data.message === 'string' ) {
				summary.body_sample = plainTextSample( payload.data.message );
			}
			if ( ! summary.messages && ! summary.body_sample ) {
				summary.body_sample = plainTextSample( text );
			}
			return summary;
		}
	} catch ( error ) {
		// Non-JSON checkout responses still carry useful WooCommerce error text.
	}

	summary.body_sample = plainTextSample( text );
	return summary;
}

function summarizeCheckoutCredential( value ) {
	const rawValue = String( value || '' );
	let credentialPrefix = rawValue ? 'other' : '';
	if ( rawValue.startsWith( 'pm_' ) ) {
		credentialPrefix = 'pm_';
	} else if ( rawValue.startsWith( 'ctoken_' ) ) {
		credentialPrefix = 'ctoken_';
	} else if ( rawValue.startsWith( 'seti_' ) ) {
		credentialPrefix = 'seti_';
	} else if ( rawValue.startsWith( 'src_' ) ) {
		credentialPrefix = 'src_';
	} else if ( rawValue.startsWith( 'card_' ) ) {
		credentialPrefix = 'card_';
	}
	return {
		present: rawValue.length > 0,
		credential_prefix: credentialPrefix,
		length: rawValue.length,
	};
}

function summarizeCheckoutRequest( url, postData ) {
	const params = new URLSearchParams( postData || '' );
	return {
		url: redactSensitiveText( url ),
		field_count: Array.from( params.keys() ).length,
		payment_method: plainTextSample( params.get( 'payment_method' ) || '', 200 ),
		credentials: {
			'wcpay-payment-method': summarizeCheckoutCredential( params.get( 'wcpay-payment-method' ) || '' ),
			'wcpay-confirmation-token': summarizeCheckoutCredential( params.get( 'wcpay-confirmation-token' ) || '' ),
			'wcpay-payment-method-sepa': summarizeCheckoutCredential( params.get( 'wcpay-payment-method-sepa' ) || '' ),
		},
		payment_method_error_code: plainTextSample( params.get( 'wcpay-payment-method-error-code' ) || '', 300 ),
		payment_method_error_message: plainTextSample( params.get( 'wcpay-payment-method-error-message' ) || '', 500 ),
		has_fingerprint: Boolean( params.get( 'wcpay-fingerprint' ) ),
		has_fraud_prevention_token: Boolean( params.get( 'wcpay-fraud-prevention-token' ) ),
	};
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

async function firstActionableLocator( roots, selectors ) {
	for ( const root of roots ) {
		for ( const selector of selectors ) {
			const matches = await root.locator( selector ).all().catch( () => [] );
			for ( const locator of matches.slice( 0, 20 ) ) {
				try {
					if ( ( await locator.count() ) === 0 || ! ( await locator.isVisible().catch( () => true ) ) ) {
						continue;
					}
					await locator.scrollIntoViewIfNeeded( { timeout: 10000 } ).catch( () => {} );
					const readiness = await locator.evaluate( ( element ) => {
						const actionElement =
							element.closest( 'button,a,[role="button"],input[type="button"],input[type="submit"]' ) || element;
						const tagName = actionElement.tagName.toLowerCase();
						const role = actionElement.getAttribute( 'role' ) || '';
						const type = actionElement.getAttribute( 'type' ) || '';
						const text = ( actionElement.innerText || actionElement.textContent || actionElement.getAttribute( 'aria-label' ) || '' )
							.replace( /\s+/g, ' ' )
							.trim();
						const rect = actionElement.getBoundingClientRect();
						const centerX = Math.min( Math.max( rect.left + rect.width / 2, 0 ), window.innerWidth - 1 );
						const centerY = Math.min( Math.max( rect.top + rect.height / 2, 0 ), window.innerHeight - 1 );
						const hitTarget = rect.width > 0 && rect.height > 0 ? document.elementFromPoint( centerX, centerY ) : null;
						return {
							text,
							is_control:
								tagName === 'button' ||
								tagName === 'a' ||
								role === 'button' ||
								( tagName === 'input' && /button|submit/i.test( type ) ),
							disabled:
								Boolean( actionElement.disabled ) ||
								actionElement.getAttribute( 'aria-disabled' ) === 'true' ||
								actionElement.getAttribute( 'aria-busy' ) === 'true' ||
								actionElement.hasAttribute( 'disabled' ),
							is_topmost: Boolean( hitTarget && ( actionElement === hitTarget || actionElement.contains( hitTarget ) ) ),
						};
					} ).catch( () => ( { text: '', disabled: false } ) );
					const { text, disabled, is_control, is_topmost } = readiness;
					if ( disabled || ! is_control || ! is_topmost || /loading/i.test( text ) ) {
						continue;
					}
					return {
						locator,
						selector,
						text,
					};
				} catch ( error ) {
					// Some selectors are intentionally broad; continue with the next candidate.
				}
			}
		}
	}
	return null;
}

async function maybeClick( roots, selectors, timeout = 10000 ) {
	const candidate = await firstActionableLocator( roots, selectors );
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

async function fillTextInputValue( locator, value, options = {} ) {
	const { typeSequentially = false } = options;
	await locator.click( { timeout: 10000 } ).catch( () => {} );
	await locator.fill( '', { timeout: 10000 } ).catch( () => {} );

	if ( typeSequentially && typeof locator.pressSequentially === 'function' ) {
		await locator.pressSequentially( value, { delay: 50, timeout: 10000 } );
	} else {
		await locator.fill( value, { timeout: 10000 } );
	}

	let currentValue = await locator.inputValue().catch( () => null );
	if ( currentValue === value ) {
		return true;
	}

	await locator.fill( value, { timeout: 10000 } ).catch( () => {} );
	currentValue = await locator.inputValue().catch( () => null );
	if ( currentValue === value ) {
		return true;
	}

	return locator.evaluate( ( element, nextValue ) => {
		if ( element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement ) {
			const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
			const valueSetter = Object.getOwnPropertyDescriptor( prototype, 'value' )?.set;
			if ( valueSetter ) {
				valueSetter.call( element, nextValue );
			} else {
				element.value = nextValue;
			}
		} else if ( element.isContentEditable ) {
			element.textContent = nextValue;
		}
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		return element.value === nextValue || element.textContent === nextValue;
	}, value ).catch( () => false );
}

async function maybeFill( roots, selectors, value ) {
	const candidate = await firstVisibleLocator( roots, selectors );
	if ( ! candidate ) {
		return false;
	}
	const isFillable = await candidate.locator.evaluate( ( element ) => {
		const tagName = element.tagName.toLowerCase();
		return tagName === 'input' || tagName === 'textarea' || element.isContentEditable;
	} ).catch( () => false );
	if ( ! isFillable ) {
		return false;
	}
	return fillTextInputValue( candidate.locator, value );
}

async function firstExistingLocator( roots, selectors ) {
	for ( const root of roots ) {
		for ( const selector of selectors ) {
			try {
				const locator = root.locator( selector ).first();
				if ( ( await locator.count() ) > 0 ) {
					return { locator, selector };
				}
			} catch ( error ) {
				// Some selectors are intentionally broad; continue with the next candidate.
			}
		}
	}
	return null;
}

async function maybeSelect( roots, selectors, values ) {
	const candidate = ( await firstVisibleLocator( roots, selectors ) ) || ( await firstExistingLocator( roots, selectors ) );
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
	let gatewayHtmlSample = '';
	let frameDiagnostics = [];
	let inputDiagnostics = [];
	let stripeRuntime = {};
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
	const gatewayContainerSelectors = [
		`.payment_method_${ gatewayId }`,
		`.wcpay-upe-form[data-payment-method-type="${ stripePaymentMethodType }"]`,
		`.wcpay-upe-element[data-payment-method-type="${ stripePaymentMethodType }"]`,
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

	for ( const selector of gatewayContainerSelectors ) {
		try {
			const locator = page.locator( selector ).first();
			if ( ( await locator.count() ) > 0 ) {
				gatewayHtmlSample = ( await locator.evaluate( ( element ) => element.outerHTML ) ).slice( 0, 5000 );
				break;
			}
		} catch ( error ) {
			// Keep collecting other evidence.
		}
	}

	frameDiagnostics = await Promise.all(
		page.frames().map( async ( frame ) => ( {
			url: typeof frame.url === 'function' ? frame.url() : '',
			input_count: await frame.locator( 'input' ).count().catch( () => 0 ),
			visible_input_count: await frame.locator( 'input:visible' ).count().catch( () => 0 ),
			iframe_count: await frame.locator( 'iframe' ).count().catch( () => 0 ),
		} ) )
	).catch( () => [] );

	inputDiagnostics = ( await Promise.all(
		page.frames().map( async ( frame ) => {
			const frameUrl = typeof frame.url === 'function' ? frame.url() : '';
			const isStripeFrame = /stripe|js\.stripe\.com|hooks\.stripe\.com/i.test( frameUrl );
			const inputs = await frame.locator( 'input' ).all().catch( () => [] );
			const rows = [];
			for ( const input of inputs.slice( 0, 25 ) ) {
				rows.push(
					await input.evaluate(
						( element, currentGatewayId ) => ( {
							name: element.getAttribute( 'name' ) || '',
							type: element.getAttribute( 'type' ) || '',
							autocomplete: element.getAttribute( 'autocomplete' ) || '',
							aria_label: element.getAttribute( 'aria-label' ) || '',
							placeholder: element.getAttribute( 'placeholder' ) || '',
							stable_field_name:
								element.closest( '[data-elements-stable-field-name]' )?.getAttribute( 'data-elements-stable-field-name' ) || '',
							is_selected_gateway_input: Boolean(
								element.closest( `.payment_method_${ currentGatewayId }` ) ||
									element.closest( `[data-gateway-id="${ currentGatewayId }"]` ) ||
									element.closest( `[data-payment-method-id="${ currentGatewayId }"]` )
							),
						} ),
						gatewayId
					).then( async ( attrs ) => ( {
						frame_url: frameUrl,
						is_stripe_frame: isStripeFrame,
						visible: await input.isVisible().catch( () => false ),
						...attrs,
					} ) ).catch( ( error ) => ( {
						frame_url: frameUrl,
						is_stripe_frame: isStripeFrame,
						error: error?.message || String( error ),
					} ) )
				);
			}
			return rows;
		} )
	) ).flat().slice( 0, 80 );

		stripeRuntime = await page.evaluate( () => {
			const selected = document.querySelector( 'input[name="payment_method"]:checked' );
			const selectedContainer = selected && selected.closest ? selected.closest( 'li' ) : null;
			const scriptSrcs = Array.from( document.scripts || [] ).map( ( script ) => script.src || '' );
		return {
			has_stripe: typeof window.Stripe === 'function',
			stripe_script_count: scriptSrcs.filter( ( src ) => /js\.stripe\.com/i.test( src ) ).length,
			woopayments_checkout_script_srcs: scriptSrcs
				.filter( ( src ) => /woopayments-checkout/i.test( src ) )
				.slice( 0, 5 ),
			core_checkout_form_count: document.querySelectorAll( '.wcpay-core-checkout-form' ).length,
			core_payment_element_count: document.querySelectorAll( '.wcpay-core-payment-element, #wcpay-core-payment-element' ).length,
			selected_gateway_id: selected ? selected.value || selected.getAttribute( 'value' ) || '' : '',
			selected_gateway_payment_element_count: selectedContainer
				? selectedContainer.querySelectorAll( '.wcpay-core-payment-element, #wcpay-core-payment-element' ).length
				: 0,
			selected_gateway_iframe_count: selectedContainer ? selectedContainer.querySelectorAll( 'iframe' ).length : 0,
		};
		} ).catch( ( error ) => ( {
			error: error?.message || String( error ),
		} ) );

		const checkoutConfig = await page.evaluate( ( currentGatewayId ) => {
			const selected = document.querySelector( 'input[name="payment_method"]:checked' );
			const selectedContainer = selected && selected.closest ? selected.closest( 'li' ) : null;
			const selectedMethodType =
				selectedContainer?.querySelector( '[data-payment-method-type]' )?.getAttribute( 'data-payment-method-type' ) || '';
			const upeConfig = window.wcpay_upe_config || {};
			const paymentMethodsConfig = upeConfig.paymentMethodsConfig || {};
			const selectedMethodConfig = selectedMethodType && paymentMethodsConfig[ selectedMethodType ]
				? paymentMethodsConfig[ selectedMethodType ]
				: null;
			return {
				has_wcpay_upe_config: Boolean( window.wcpay_upe_config ),
				selected_gateway_id: selected ? selected.value || selected.getAttribute( 'value' ) || currentGatewayId : '',
				selected_payment_method_type: selectedMethodType,
				payment_methods_config_keys: Object.keys( paymentMethodsConfig ).sort(),
				selected_method_config_present: Boolean( selectedMethodConfig ),
				selected_method_config_keys: selectedMethodConfig ? Object.keys( selectedMethodConfig ).sort() : [],
				selected_method_countries_count: Array.isArray( selectedMethodConfig?.countries )
					? selectedMethodConfig.countries.length
					: null,
				selected_method_currencies_count: Array.isArray( selectedMethodConfig?.currencies )
					? selectedMethodConfig.currencies.length
					: null,
				selected_method_force_network_saved_cards_type: typeof selectedMethodConfig?.forceNetworkSavedCards,
				test_mode: Boolean( upeConfig.testMode ),
			};
		}, gatewayId ).catch( ( error ) => ( {
			error: error?.message || String( error ),
		} ) );

		return {
			final_url: redactSensitiveText( page.url() ),
			title: await page.title().catch( () => '' ),
		gateway_selectors: await Promise.all(
			gatewaySelectors.map( async ( selector ) => ( {
				selector,
				count: await countLocator( page, selector ),
			} ) )
		),
		snapshot: snapshotText,
		gateway_html_sample: gatewayHtmlSample,
			frame_diagnostics: frameDiagnostics,
			input_diagnostics: inputDiagnostics,
			stripe_runtime: stripeRuntime,
			checkout_config: checkoutConfig,
			logs,
		fatal_console_errors: logs.filter( isFatalConsoleError ),
		screenshot_path: screenshotPath,
		...extra,
	};
}

function installResponseCapture(
	page,
	intentIds,
	failedResponses,
	checkoutResponses,
	pendingResponseCaptures,
	responseBodyTimeoutMs = 5000
) {
	const readResponseText = async ( response ) => {
		let timeoutId;
		try {
			return await Promise.race( [
				response.text(),
				new Promise( ( resolve, reject ) => {
					timeoutId = setTimeout(
						() => reject( new Error( 'Response body capture timed out.' ) ),
						responseBodyTimeoutMs
					);
				} ),
			] );
		} finally {
			clearTimeout( timeoutId );
		}
	};
	const captureResponse = async ( response ) => {
		const status = response.status();
		const url = response.url();
		if ( isCartStateProbeResponse( url ) ) {
			return;
		}
		const shouldCaptureFailure = status >= 400 && ! /favicon\.ico|load-scripts\.php.*ver=/.test( url );
		const shouldInspectBody = shouldCaptureFailure || /wc-ajax|wp-json|stripe|payment|checkout|order/i.test( url );

		if ( ! shouldInspectBody ) {
			return;
		}

		try {
			const text = await readResponseText( response );
			if ( shouldCaptureFailure && failedResponses.length < 50 ) {
				failedResponses.push( {
					status,
					url: redactSensitiveText( url ),
					body_sample: plainTextSample( text ),
				} );
			}
			if ( isCheckoutAjaxResponse( url ) && checkoutResponses.length < 20 ) {
				checkoutResponses.push( summarizeCheckoutResponse( url, status, text ) );
			}
			for ( const intentId of intentIdsFromText( text ) ) {
				intentIds.add( intentId );
			}
		} catch ( error ) {
			if ( shouldCaptureFailure && failedResponses.length < 50 ) {
				failedResponses.push( {
					status,
					url: redactSensitiveText( url ),
					body_sample: '',
				} );
			}
		}
	};
	const handler = ( response ) => {
		const capture = captureResponse( response );
		pendingResponseCaptures.add( capture );
		void capture.then(
			() => pendingResponseCaptures.delete( capture ),
			() => pendingResponseCaptures.delete( capture )
		);
	};
	page.on( 'response', handler );
	return handler;
}

async function settlePendingResponseCaptures( pendingResponseCaptures ) {
	while ( pendingResponseCaptures.size > 0 ) {
		await Promise.allSettled( [ ...pendingResponseCaptures ] );
	}
}

function installCheckoutRequestCapture( page, checkoutRequests ) {
	const handler = ( request ) => {
		const url = request.url();
		if ( ! isCheckoutAjaxResponse( url ) || checkoutRequests.length >= 20 ) {
			return;
		}

		try {
			checkoutRequests.push( {
				method: typeof request.method === 'function' ? request.method() : '',
				...summarizeCheckoutRequest(
					url,
					typeof request.postData === 'function' ? request.postData() || '' : ''
				),
			} );
		} catch ( error ) {
			checkoutRequests.push( {
				method: typeof request.method === 'function' ? request.method() : '',
				url: redactSensitiveText( url ),
				capture_error: error?.message || String( error ),
			} );
		}
	};

	page.on( 'request', handler );
	return handler;
}

function installRequestFailureCapture( page, failedRequests ) {
	const handler = ( request ) => {
		const url = request.url();
		if ( /favicon\.ico/i.test( url ) ) {
			return;
		}

		const failure = typeof request.failure === 'function' ? request.failure() : null;
		failedRequests.push( {
			method: typeof request.method === 'function' ? request.method() : '',
			url,
			failure: failure?.errorText || '',
		} );
	};

	page.on( 'requestfailed', handler );
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

function sameOrigin( currentUrl, targetUrl ) {
	try {
		return new URL( currentUrl ).origin === new URL( targetUrl ).origin;
	} catch ( error ) {
		return false;
	}
}

function isWooCommerceCartCookie( name ) {
	return (
		name === 'woocommerce_cart_hash' ||
		name === 'woocommerce_items_in_cart' ||
		name.startsWith( 'wp_woocommerce_session_' )
	);
}

function isLocalWordPressAuthCookie( name ) {
	return (
		name === 'wordpress_test_cookie' ||
		name.startsWith( 'wordpress_logged_in_' ) ||
		name.startsWith( 'wordpress_sec_' ) ||
		/^wordpress_[a-f0-9]{32}$/i.test( name )
	);
}

function isLocalCheckoutCookie( name ) {
	return isWooCommerceCartCookie( name ) || isLocalWordPressAuthCookie( name );
}

const wooCommerceStoreApiCartStorageKeys = [
	'storeApiCartData',
	'storeApiCartHash',
	'storeApiNonce',
	'wc_cart_created',
];

function isWooCommerceStoreApiCartStorageKey( name ) {
	return (
		wooCommerceStoreApiCartStorageKeys.includes( name ) ||
		name.startsWith( 'wc_cart_hash_' ) ||
		name.startsWith( 'wc_fragments_' )
	);
}

async function deleteLocalCheckoutCookie( cdp, cookie ) {
	const errors = [];
	try {
		await cdp.send( 'Network.deleteCookies', {
			name: cookie.name,
			domain: cookie.domain,
			path: cookie.path,
		} );
	} catch ( exactError ) {
		errors.push( exactError?.message || String( exactError ) );
	}

	try {
		await cdp.send( 'Network.deleteCookies', {
			name: cookie.name,
			url: baseUrl,
		} );
	} catch ( urlError ) {
		errors.push( urlError?.message || String( urlError ) );
	}

	try {
		await cdp.send( 'Network.deleteCookies', {
			name: cookie.name,
			url: `${ baseUrl }/`,
		} );
	} catch ( slashUrlError ) {
		errors.push( slashUrlError?.message || String( slashUrlError ) );
	}

	if ( errors.length === 3 ) {
		throw new Error( `Unable to clear local checkout cookie ${ cookie.name }: ${ errors.join( '; ' ) }` );
	}
}

async function clearLocalCheckoutBrowserStorage( page, options = {} ) {
	if ( options.navigate !== false ) {
		await gotoLocalPage( page, `${ baseUrl }/?lpm-gate-clear-storage=1`, { timeout: 30000 } );
		await waitForPageLoad( { page, timeout: 15000, minWait: 500 } ).catch( () => {} );
	}

	const exactStorageKeys = wooCommerceStoreApiCartStorageKeys.filter( isWooCommerceStoreApiCartStorageKey );
	return page.evaluate( ( exactKeys ) => {
		const removed = {
			localStorage: [],
			sessionStorage: [],
		};
		const shouldRemove = ( key ) =>
			exactKeys.includes( key ) ||
			key.startsWith( 'wc_cart_hash_' ) ||
			key.startsWith( 'wc_fragments_' );

		for ( const key of Object.keys( window.localStorage || {} ) ) {
			if ( shouldRemove( key ) ) {
				window.localStorage.removeItem( key );
				removed.localStorage.push( key );
			}
		}

		for ( const key of Object.keys( window.sessionStorage || {} ) ) {
			if ( shouldRemove( key ) ) {
				window.sessionStorage.removeItem( key );
				removed.sessionStorage.push( key );
			}
		}

		return removed;
	}, exactStorageKeys ).catch( ( error ) => ( {
		error: error?.message || String( error ),
	} ) );
}

async function clearLocalCheckoutSession( page ) {
	await clearLocalCheckoutBrowserStorage( page );

	const cdp = await getCDPSession( { page } );
	const { cookies = [] } = await cdp.send( 'Network.getCookies', {
		urls: [ baseUrl ],
	} );
	const localCheckoutCookies = cookies.filter( ( cookie ) => isLocalCheckoutCookie( cookie.name ) );

	for ( const cookie of localCheckoutCookies ) {
		await deleteLocalCheckoutCookie( cdp, cookie );
	}

	const { cookies: remainingCookies = [] } = await cdp.send( 'Network.getCookies', {
		urls: [ baseUrl ],
	} );
	const remainingLocalCheckoutCookies = remainingCookies.filter( ( cookie ) =>
		isLocalCheckoutCookie( cookie.name )
	);
	if ( remainingLocalCheckoutCookies.length > 0 ) {
		throw new Error(
			`Unable to clear local checkout cookie(s): ${ remainingLocalCheckoutCookies
				.map( ( cookie ) => cookie.name )
			.join( ', ' ) }`
		);
	}

	await clearLocalCheckoutBrowserStorage( page, { navigate: false } );
}

async function resetCartToSingleGateProduct( page ) {
	const result = await page.evaluate( async ( expectedProductId ) => {
		const cartEndpoint = '/?rest_route=/wc/store/v1/cart';
		const addEndpoint = '/?rest_route=/wc/store/v1/cart/add-item';
		const itemEndpoint = ( key ) => `/?rest_route=/wc/store/v1/cart/items/${ encodeURIComponent( key ) }`;
		const readCart = async () => {
			const response = await fetch( cartEndpoint, {
				credentials: 'include',
				headers: { Accept: 'application/json' },
			} );
			const text = await response.text();
			let payload = null;
			try {
				payload = text ? JSON.parse( text ) : null;
			} catch ( error ) {
				return {
					ok: false,
					status: response.status,
					error: `cart JSON parse failed: ${ error?.message || String( error ) }`,
					body: text.slice( 0, 500 ),
					nonce: response.headers.get( 'Nonce' ) || response.headers.get( 'X-WC-Store-API-Nonce' ) || '',
				};
			}
			return {
				ok: response.ok,
				status: response.status,
				payload,
				body: text.slice( 0, 500 ),
				nonce: response.headers.get( 'Nonce' ) || response.headers.get( 'X-WC-Store-API-Nonce' ) || '',
			};
		};

		const nonceFromSettings =
			window.wcSettings?.storeApiNonce ||
			window.wc?.wcSettings?.getSetting?.( 'storeApiNonce' ) ||
			window.wp?.data?.select?.( 'wc/store/cart' )?.getCartData?.()?.nonce ||
			'';
		const before = await readCart();
		const nonce = before.nonce || nonceFromSettings;
		const mutationHeaders = {
			Accept: 'application/json',
			'Content-Type': 'application/json',
		};
		if ( nonce ) {
			mutationHeaders.Nonce = nonce;
			mutationHeaders[ 'X-WC-Store-API-Nonce' ] = nonce;
		}
		if ( ! before.ok ) {
			return {
				success: false,
				stage: 'read-before',
				before,
			};
		}

		const items = Array.isArray( before.payload?.items ) ? before.payload.items : [];
		const deleteResults = [];
		for ( const item of items ) {
			const key = item.key || item.item_key;
			if ( ! key ) {
				deleteResults.push( { ok: false, status: 0, error: 'cart item had no key', item } );
				continue;
			}
			const response = await fetch( itemEndpoint( key ), {
				method: 'DELETE',
				credentials: 'include',
				headers: mutationHeaders,
			} );
			deleteResults.push( {
				key,
				ok: response.ok,
				status: response.status,
				body: await response.text().then( ( text ) => text.slice( 0, 500 ) ).catch( () => '' ),
			} );
		}
		const failedDeletes = deleteResults.filter( ( item ) => ! item.ok );
		if ( failedDeletes.length > 0 ) {
			return {
				success: false,
				stage: 'delete-existing-items',
				before,
				deleteResults,
			};
		}

		const addResponse = await fetch( addEndpoint, {
			method: 'POST',
			credentials: 'include',
			headers: mutationHeaders,
			body: JSON.stringify( {
				id: expectedProductId,
				quantity: 1,
			} ),
		} );
		const addBody = await addResponse.text();
		const after = await readCart();
		const afterItems = Array.isArray( after.payload?.items ) ? after.payload.items : [];
		const matchingQuantity = afterItems
			.filter( ( item ) => Number.parseInt( String( item.id || 0 ), 10 ) === expectedProductId )
			.reduce( ( total, item ) => total + Number( item.quantity || 0 ), 0 );
		const totalQuantity = afterItems.reduce( ( total, item ) => total + Number( item.quantity || 0 ), 0 );

		return {
			success: addResponse.ok && after.ok && totalQuantity === 1 && matchingQuantity === 1,
			stage: 'add-item',
			add: {
				ok: addResponse.ok,
				status: addResponse.status,
				body: addBody.slice( 0, 500 ),
			},
			before: {
				status: before.status,
				items_count: items.reduce( ( total, item ) => total + Number( item.quantity || 0 ), 0 ),
			},
			deleteResults,
			after: {
				status: after.status,
				items_count: totalQuantity,
				matching_product_quantity: matchingQuantity,
				items: afterItems.map( ( item ) => ( {
					id: item.id,
					key: item.key || item.item_key || '',
					quantity: item.quantity,
					name: item.name,
				} ) ),
			},
		};
	}, Number.parseInt( productId, 10 ) );

	if ( ! result?.success ) {
		throw new Error( `Unable to reset checkout cart to a single LPM gate product: ${ JSON.stringify( result ) }` );
	}

	return result;
}

async function gotoLocalPage( page, url, options = {} ) {
	const timeout = options.timeout || 45000;
	try {
		await page.goto( url, { waitUntil: 'domcontentloaded', timeout } );
		return { timed_out: false, final_url: page.url() };
	} catch ( error ) {
		const finalUrl = page.url();
		if ( ! finalUrl || finalUrl === 'about:blank' || ! sameOrigin( finalUrl, url ) ) {
			throw error;
		}
		const readyState = await page.evaluate( () => document.readyState ).catch( () => '' );
		return {
			timed_out: true,
			final_url: finalUrl,
			ready_state: readyState,
			message: error?.message || String( error ),
		};
	}
}

async function navigateToCheckout( page ) {
	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await getLatestLogs( { page, sinceLastCall: true } ).catch( () => [] );
	await clearLocalCheckoutSession( page );
	await resetCartToSingleGateProduct( page );
	await gotoLocalPage( page, checkoutUrl );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
}

async function readGateCartState( page ) {
	return page.evaluate( async ( expectedProductId ) => {
		const normalize = ( value ) => String( value || '' ).replace( /\s+/g, ' ' ).trim();
		const summarizeStoreApiCart = ( parsed ) => {
			const items = Array.isArray( parsed?.items ) ? parsed.items : [];
			const matchingItems = items.filter( ( item ) => Number.parseInt( String( item.id || 0 ), 10 ) === expectedProductId );
			return {
				present: true,
				items_count: Number.isFinite( Number( parsed?.itemsCount ) )
					? Number( parsed.itemsCount )
					: items.reduce( ( total, item ) => total + Number( item.quantity || 0 ), 0 ),
				matching_product_quantity: matchingItems.reduce(
					( total, item ) => total + Number( item.quantity || 0 ),
					0
				),
				items: items.map( ( item ) => ( {
					id: Number.parseInt( String( item.id || 0 ), 10 ),
					quantity: Number( item.quantity || 0 ),
					name: normalize( item.name ).slice( 0, 200 ),
				} ) ),
			};
		};
		const parseQuantity = ( text ) => {
			const quantityMatch = normalize( text ).match( /[×x]\s*(\d+)/i );
			return quantityMatch ? Number.parseInt( quantityMatch[ 1 ], 10 ) : 1;
		};
		const domRows = Array.from(
			document.querySelectorAll(
				'.woocommerce-checkout-review-order-table tr.cart_item, .shop_table tr.cart_item, .wc-block-components-order-summary-item'
			)
		).map( ( row ) => {
			const text = normalize( row.textContent );
			return {
				text: text.slice( 0, 400 ),
				quantity: parseQuantity( text ),
				is_gate_product: /WooPayments LPM Checkout Gate Product/i.test( text ),
			};
		} );
		const gateRows = domRows.filter( ( row ) => row.is_gate_product );
		const bodyText = normalize( document.body?.innerText || document.body?.textContent || '' );
		const bodyGateProductMatch = bodyText.match( /WooPayments LPM Checkout Gate Product[^×x]*[×x]\s*(\d+)/i );
		const bodyGateProductQuantity = bodyGateProductMatch
			? Number.parseInt( bodyGateProductMatch[ 1 ], 10 )
			: 0;
		let storeApiCart = {
			present: false,
			items_count: null,
			matching_product_quantity: null,
			items: [],
		};
		let storeApiFetchCart = {
			present: false,
			items_count: null,
			matching_product_quantity: null,
			items: [],
		};

		try {
			const rawCartData = window.localStorage?.getItem( 'storeApiCartData' ) || '';
			if ( rawCartData ) {
				storeApiCart = summarizeStoreApiCart( JSON.parse( rawCartData ) );
			}
		} catch ( error ) {
			storeApiCart = {
				...storeApiCart,
				error: error?.message || String( error ),
			};
		}

		for ( const cartEndpoint of [ '/wp-json/wc/store/v1/cart', '/?rest_route=/wc/store/v1/cart' ] ) {
			try {
				const cartResponse = await fetch( cartEndpoint, {
					credentials: 'include',
					headers: {
						Accept: 'application/json',
					},
				} );
				if ( cartResponse.ok ) {
					storeApiFetchCart = summarizeStoreApiCart( await cartResponse.json() );
					storeApiFetchCart.status = cartResponse.status;
					storeApiFetchCart.endpoint = cartEndpoint;
					break;
				}
				storeApiFetchCart = {
					...storeApiFetchCart,
					endpoint: cartEndpoint,
					status: cartResponse.status,
					error: await cartResponse.text().then( ( text ) => text.slice( 0, 500 ) ).catch( () => '' ),
				};
			} catch ( error ) {
				storeApiFetchCart = {
					...storeApiFetchCart,
					endpoint: cartEndpoint,
					error: error?.message || String( error ),
				};
			}
		}

		return {
			expected_product_id: Number.parseInt( String( expectedProductId || 0 ), 10 ),
			dom_cart_item_rows: domRows.length,
			dom_gate_product_rows: gateRows.length,
			dom_gate_product_quantity: gateRows.reduce( ( total, row ) => total + row.quantity, 0 ),
			dom_rows: domRows,
			body_has_gate_product: /WooPayments LPM Checkout Gate Product/i.test( bodyText ),
			body_gate_product_quantity: bodyGateProductQuantity,
			store_api_cart: storeApiCart,
			store_api_fetch_cart: storeApiFetchCart,
		};
	}, Number.parseInt( productId, 10 ) );
}

function isSingleGateProductCartState( cartState ) {
	if ( cartState.dom_cart_item_rows > 0 ) {
		return cartState.dom_gate_product_rows === 1 && cartState.dom_gate_product_quantity === 1;
	}

	if ( cartState.store_api_cart?.present ) {
		return (
			cartState.store_api_cart.items_count === 1 &&
			cartState.store_api_cart.matching_product_quantity === 1
		);
	}

	if ( cartState.store_api_fetch_cart?.present ) {
		return (
			cartState.store_api_fetch_cart.items_count === 1 &&
			cartState.store_api_fetch_cart.matching_product_quantity === 1
		);
	}

	return cartState.body_has_gate_product && cartState.body_gate_product_quantity === 1;
}

async function assertSingleGateProductInCart( page ) {
	let lastCartState = null;
	const startedAtMs = Date.now();

	while ( Date.now() - startedAtMs < 15000 ) {
		lastCartState = await readGateCartState( page );
		if ( isSingleGateProductCartState( lastCartState ) ) {
			return lastCartState;
		}
		await page.waitForTimeout( 500 );
	}

	throw new Error(
		`Checkout cart must contain exactly one LPM gate product before submit: ${ JSON.stringify(
			lastCartState
		) }`
	);
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

	let selectionError = null;
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		await page.locator( '.blockUI.blockOverlay' ).last().waitFor( { state: 'detached', timeout: 10000 } ).catch( () => {} );
		try {
			if ( await maybeCheck( [ page ], selectors ) ) {
				await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
				const selected = await readCheckedPaymentMethod( page );
				if ( selected === gatewayId ) {
					return selected;
				}
			}
		} catch ( error ) {
			selectionError = error;
		}
		await page.waitForTimeout( 500 );
	}

	const selectedByScript = await page.evaluate( ( currentGatewayId ) => {
		const input = document.querySelector( `input[name="payment_method"][value="${ currentGatewayId }"]` );
		if ( ! ( input instanceof HTMLInputElement ) ) {
			return '';
		}
		input.checked = true;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		return input.checked ? input.value : '';
	}, gatewayId ).catch( () => '' );
	await waitForPageLoad( { page, timeout: 20000, minWait: 500 } ).catch( () => {} );
	const selected = selectedByScript || await readCheckedPaymentMethod( page );
	if ( selected !== gatewayId ) {
		if ( selectionError ) {
			throw selectionError;
		}
		throw new Error( `Selected gateway mismatch: expected ${ gatewayId }, browser selected ${ selected || 'none' }.` );
	}
	return selected;
}

async function fillDebitPaymentElement( page ) {
	const fillAttempts = [];
	for ( const frame of page.frames() ) {
		const frameUrl = typeof frame.url === 'function' ? frame.url() : '';
		const isStripeFrame = /stripe|js\.stripe\.com|hooks\.stripe\.com/i.test( frameUrl );
		const visibleInputs = await frame.locator( 'input' ).all().catch( () => [] );
		for ( const input of visibleInputs ) {
			if ( ! ( await input.isVisible().catch( () => false ) ) ) {
				continue;
			}
			const inputType = await input.getAttribute( 'type' ).catch( () => '' );
			if ( /^(checkbox|radio|hidden|submit|button|reset)$/i.test( inputType || '' ) ) {
				continue;
			}
			const isSelectedGatewayInput = await input.evaluate(
				( element, currentGatewayId ) =>
					Boolean(
						element.closest( `.payment_method_${ currentGatewayId }` ) ||
							element.closest( `[data-gateway-id="${ currentGatewayId }"]` ) ||
							element.closest( `[data-payment-method-id="${ currentGatewayId }"]` )
					),
				gatewayId
			).catch( () => false );
			if ( ! isStripeFrame && ! isSelectedGatewayInput ) {
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
			if ( /iban|sepa/i.test( fieldText ) || ( method === 'sepa_debit' && isStripeFrame ) ) {
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

async function waitForPaymentElementReady( page ) {
	const selectors = [
		`.payment_method_${ gatewayId } iframe`,
		`.wcpay-upe-form[data-payment-method-type="${ stripePaymentMethodType }"] iframe`,
		`.wcpay-upe-element[data-payment-method-type="${ stripePaymentMethodType }"] iframe`,
	];
	const startedAtMs = Date.now();

	while ( Date.now() - startedAtMs < 20000 ) {
		for ( const selector of selectors ) {
			const count = await countLocator( page, selector );
			if ( count > 0 ) {
				await page.waitForTimeout( 1000 );
				return {
					ready: true,
					selector,
					count,
				};
			}
		}
		await page.waitForTimeout( 500 );
	}

	return {
		ready: false,
		selector: null,
		count: 0,
	};
}

async function selectPaymentElementOptions( page ) {
	const selectedOptions = [];
	for ( const frame of page.frames() ) {
		const frameUrl = typeof frame.url === 'function' ? frame.url() : '';
		const isStripeFrame = /stripe|js\.stripe\.com|hooks\.stripe\.com/i.test( frameUrl );
		const selects = await frame.locator( 'select' ).all().catch( () => [] );
		for ( const select of selects ) {
			if ( await select.isVisible().catch( () => false ) ) {
				const isSelectedGatewaySelect = await select.evaluate(
					( element, currentGatewayId ) =>
						Boolean(
							element.closest( `.payment_method_${ currentGatewayId }` ) ||
								element.closest( `[data-gateway-id="${ currentGatewayId }"]` ) ||
								element.closest( `[data-payment-method-id="${ currentGatewayId }"]` )
						),
					gatewayId
				).catch( () => false );
				if ( ! isStripeFrame && ! isSelectedGatewaySelect ) {
					continue;
				}
				if ( await selectFirstNonEmptyOption( select ) ) {
					selectedOptions.push( await select.getAttribute( 'name' ).catch( () => 'select' ) );
				}
			}
		}
	}
	return selectedOptions;
}

async function fillPaymentElement( page ) {
	const paymentElement = await waitForPaymentElementReady( page );
	const selectedOptions = await selectPaymentElementOptions( page );
	const debitFields = await fillDebitPaymentElement( page );
	return {
		selected_options: selectedOptions,
		payment_element: paymentElement,
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

function hasCheckoutAdvanced( page ) {
	return /order-received|checkout\/order-pay|hooks\.stripe\.com|stripe\.com|checkout\.stripe\.com/i.test( page.url() );
}

async function waitForCheckoutProgress( page, checkoutRequests, checkoutResponses, timeout = 90000 ) {
	const startedAtMs = Date.now();
	let sawCheckoutRequestAtMs = 0;

	while ( Date.now() - startedAtMs < timeout ) {
		if ( hasCheckoutAdvanced( page ) ) {
			return;
		}

		if ( checkoutResponses.length > 0 ) {
			await page.waitForTimeout( 1500 );
			return;
		}

		if ( checkoutRequests.length > 0 ) {
			sawCheckoutRequestAtMs = sawCheckoutRequestAtMs || Date.now();
			if ( Date.now() - sawCheckoutRequestAtMs > 30000 ) {
				return;
			}
		}

		await page.waitForTimeout( 500 );
	}
}

async function submitCheckout( page, checkoutRequests, checkoutResponses ) {
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

	await waitForCheckoutProgress( page, checkoutRequests, checkoutResponses );
}

function orderIdFromOrderReceivedUrl( value ) {
	try {
		const parsed = new URL( value );
		const pathMatch = parsed.pathname.match( /\/order-received\/(\d+)\/?/ );
		if ( pathMatch ) {
			return Number.parseInt( pathMatch[ 1 ], 10 );
		}

		const queryOrderId = parsed.searchParams.get( 'order-received' );
		if ( queryOrderId && /^\d+$/.test( queryOrderId ) ) {
			return Number.parseInt( queryOrderId, 10 );
		}
	} catch ( error ) {
		return 0;
	}

	return 0;
}

async function approveExternalAuthorization( page ) {
	const approvalSelectors = [
		'[data-testid="kaf-button"]',
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

	async function firstReadyKlarnaAuthorizationControl( roots ) {
		const klarnaAuthorizationSelectors = [
			'[data-testid="kaf-button"]',
			'[data-testid="offers-selector-continue-button"]',
			'[data-testid="confirm-and-pay"]',
			'button:has-text("Continue")',
			'button:has-text("Confirm")',
			'button:has-text("Authorize")',
			'button:has-text("Pay")',
			'[role="button"]:has-text("Continue")',
			'[role="button"]:has-text("Confirm")',
			'[role="button"]:has-text("Authorize")',
			'[role="button"]:has-text("Pay")',
			'text=/Continue|Confirm|Authorize|Pay/i',
		];

		return firstActionableLocator( roots, klarnaAuthorizationSelectors );
	}

	async function waitForKlarnaAuthorizationReturn( page, timeout = 90000, previousUrl = '', previousText = '' ) {
		const startedAtMs = Date.now();
		while ( Date.now() - startedAtMs < timeout ) {
			const currentUrl = page.url();
			if ( orderIdFromOrderReceivedUrl( currentUrl ) > 0 || sameOrigin( currentUrl, baseUrl ) ) {
				return {
					returned: true,
					advanced: false,
					url: currentUrl,
				};
			}
			if ( previousUrl && currentUrl !== previousUrl ) {
				return {
					returned: false,
					advanced: true,
					url: currentUrl,
				};
			}
			const currentText = await page.evaluate( () => document.body?.innerText || '' ).catch( () => '' );
			if ( previousText && currentText && currentText !== previousText ) {
				return {
					returned: false,
					advanced: true,
					url: currentUrl,
				};
			}
			await page.waitForTimeout( 500 );
		}

		return {
			returned: false,
			advanced: false,
			url: page.url(),
		};
	}

	for ( let i = 0; i < 6; i++ ) {
		const currentUrl = page.url();
		if ( orderIdFromOrderReceivedUrl( currentUrl ) > 0 ) {
			return attempts;
		}

		const roots = [ page, ...page.frames() ];
		if ( /klarna\.com/i.test( currentUrl ) ) {
			const rootTexts = await Promise.all(
				roots.map( ( root ) =>
					root.evaluate( () => document.body?.innerText || '' ).catch( () => '' )
				)
			);
			const hasSubmittedKlarnaPhone = attempts.some( ( attempt ) => attempt.action === 'filled_klarna_test_phone' ) &&
				attempts.some( ( attempt ) =>
					attempt.action === 'clicked_authorization_control' ||
					attempt.action === 'clicked_klarna_authorization_control'
				);
			const isCodeStep = hasSubmittedKlarnaPhone ||
				rootTexts.some( ( text ) => /Enter the 6-digit code|Enter code/i.test( text ) );
			if ( isCodeStep ) {
				const codeField = await firstVisibleLocator( roots, [
					'[data-testid="kaf-field"]',
					'input[inputmode="numeric"]',
					'input[name*="code" i]',
				] );
				const filledCode = Boolean( codeField );
				if ( filledCode ) {
					const codeValueSet = await fillTextInputValue( codeField.locator, '123456', { typeSequentially: true } );
					attempts.push( { url: currentUrl, action: 'filled_klarna_test_code', value_set: codeValueSet } );
					await codeField.locator.press( 'Enter', { timeout: 10000 } ).catch( () => {} );
					attempts.push( { url: currentUrl, action: 'submitted_klarna_test_code' } );
				}
			} else {
				const filledPhone = await maybeFill( roots, [
					'[data-testid="kaf-field"]',
					'input[type="tel"]',
					'input[name*="phone" i]',
					'input[autocomplete="tel"]',
				], klarnaTestPhoneByCountry[ country ] || klarnaTestPhoneByCountry.US );
				if ( filledPhone ) {
					attempts.push( { url: currentUrl, action: 'filled_klarna_test_phone' } );
				}
			}

			const klarnaAuthorizationControl = await firstReadyKlarnaAuthorizationControl( roots );
			if ( klarnaAuthorizationControl ) {
				await klarnaAuthorizationControl.locator.click( { timeout: 15000 } );
				attempts.push( {
					url: currentUrl,
					action: 'clicked_klarna_authorization_control',
					selector: klarnaAuthorizationControl.selector,
					text: klarnaAuthorizationControl.text,
				} );
				const returnAttempt = await waitForKlarnaAuthorizationReturn(
					page,
					isCodeStep ? 90000 : 30000,
					currentUrl,
					rootTexts.join( '\n' )
				);
				if ( returnAttempt.returned ) {
					return attempts;
				}
				continue;
			}

			if ( isCodeStep ) {
				const returnAttempt = await waitForKlarnaAuthorizationReturn(
					page,
					30000,
					currentUrl,
					rootTexts.join( '\n' )
				);
				if ( returnAttempt.returned ) {
					return attempts;
				}
			}

			await page.waitForTimeout( 1500 );
			continue;
		}

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
		const orderIdFromUrl = ( currentUrl ) => {
			try {
				const parsed = new URL( currentUrl );
				const pathMatch = parsed.pathname.match( /\/order-received\/(\d+)\/?/ );
				if ( pathMatch ) {
					return Number.parseInt( pathMatch[ 1 ], 10 );
				}

				const queryOrderId = parsed.searchParams.get( 'order-received' );
				if ( queryOrderId && /^\d+$/.test( queryOrderId ) ) {
					return Number.parseInt( queryOrderId, 10 );
				}
			} catch ( error ) {
				return null;
			}

			return null;
		};
		const intentMatches = ( bodyText.match( /\bpi_[A-Za-z0-9_]+\b/g ) || [] )
			.map( ( candidate ) => candidate.split( '_secret_' )[ 0 ] )
			.filter(
				( candidate ) =>
					/^pi_[A-Za-z0-9_]{8,}$/.test( candidate ) &&
					! /^pi_client_secret\b/.test( candidate )
			);
		const multibancoContainer = document.querySelector( '#wc-payment-gateway-multibanco-instructions-container' );
		const multibancoValues = multibancoContainer
			? Array.from( multibancoContainer.querySelectorAll( '.payment-box-row .payment-box-value[data-copy-value]' ) )
				.map( ( element ) => element.getAttribute( 'data-copy-value' ) || '' )
			: [];
		return {
			url,
			order_id: orderIdFromUrl( url ),
			body_text_sample: bodyText.slice( 0, 1600 ),
			body_text_length: bodyText.length,
			intent_ids: Array.from( new Set( intentMatches ) ),
			has_order_received_heading: /order received|thank you|order details|payment instructions/i.test( bodyText ),
			has_payment_method_text: /payment method|paid with|multibanco|klarna|affirm|afterpay|bancontact|ideal|wechat|alipay|grabpay|sepa|becs/i.test( bodyText ),
			multibanco_voucher: {
				rendered: Boolean( multibancoContainer ),
				visible: Boolean( multibancoContainer && multibancoContainer.getClientRects().length > 0 ),
				entity: multibancoValues[ 0 ] || '',
				reference: multibancoValues[ 1 ] || '',
				amount: multibancoValues[ 2 ] || '',
				share_link_present: Boolean( multibancoContainer?.querySelector( '.copy-link-btn[data-copy-value]' ) ),
			},
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

async function runCheckoutFlow( page, failedRequests ) {
	const intentIds = new Set();
	const failedResponses = [];
	const checkoutRequests = [];
	const checkoutResponses = [];
	const pendingResponseCaptures = new Set();
	latestCheckoutRequests = checkoutRequests;
	latestCheckoutResponses = checkoutResponses;
	const checkoutRequestHandler = installCheckoutRequestCapture( page, checkoutRequests );
	const responseHandler = installResponseCapture(
		page,
		intentIds,
		failedResponses,
		checkoutResponses,
		pendingResponseCaptures
	);

	try {
		await navigateToCheckout( page );
		const cartState = await assertSingleGateProductInCart( page );
		await fillBillingFields( page );
		const selectedGatewayId = await selectPaymentMethod( page );
		const elementEvidence = await fillPaymentElement( page );
		await acceptCheckoutTerms( page );
		await submitCheckout( page, checkoutRequests, checkoutResponses );
		for ( const intentId of await readHiddenIntentIds( page ) ) {
			intentIds.add( intentId );
		}
		const authorizationAttempts = await approveExternalAuthorization( page );
		await settlePendingResponseCaptures( pendingResponseCaptures );
		for ( const intentId of await readHiddenIntentIds( page ) ) {
			intentIds.add( intentId );
		}

		const orderEvidence = await extractOrderEvidence( page, intentIds );
		const reachedOrderReceived = Boolean( orderEvidence.order_id && orderEvidence.order_id > 0 );
		const failures = [];
		if ( ! reachedOrderReceived ) {
			failures.push( 'checkout did not reach an order-received URL with an order id' );
		}
		if ( failedResponses.length > 0 ) {
			failures.push( 'failed browser responses were captured during checkout' );
		}
		if (
			method === 'multibanco' &&
			(
				! orderEvidence.multibanco_voucher.rendered ||
				! orderEvidence.multibanco_voucher.visible ||
				! orderEvidence.multibanco_voucher.entity ||
				! orderEvidence.multibanco_voucher.reference ||
				! orderEvidence.multibanco_voucher.amount ||
				! orderEvidence.multibanco_voucher.share_link_present
			)
		) {
			failures.push( 'Multibanco voucher rendering is incomplete' );
		}

		const pageEvidence = await capturePageEvidence( page, {
			order: orderEvidence,
			element: elementEvidence,
			cart_state: cartState,
			authorization_attempts: authorizationAttempts,
			observed_payment_intent_ids: [ ...intentIds ],
			checkout_requests: checkoutRequests,
			checkout_responses: checkoutResponses,
			failed_responses: failedResponses,
			failed_requests: failedRequests,
		} );
		const payload = {
			status: failures.length === 0 ? 'pass' : 'fail',
			order_id: orderEvidence.order_id || 0,
			selected_gateway_id: selectedGatewayId,
			order_payment_method: selectedGatewayId,
			order_received_url: reachedOrderReceived ? orderEvidence.url : null,
			payment_intent_id: orderEvidence.payment_intent_id,
			multibanco_voucher: method === 'multibanco' ? orderEvidence.multibanco_voucher : null,
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
		await settlePendingResponseCaptures( pendingResponseCaptures );
		page.off( 'response', responseHandler );
		page.off( 'request', checkoutRequestHandler );
	}
}

assertLocalBase( baseUrl );
writeEvidence( { status: 'running' } );

let gatePage = null;
let requestFailureHandler = null;
const failedRequests = [];

try {
	gatePage = await context.newPage();
	requestFailureHandler = installRequestFailureCapture( gatePage, failedRequests );
	if ( typeof state !== 'undefined' ) {
		state.lpmCheckoutPage = gatePage;
	}

	await gatePage.setViewportSize( { width: 1280, height: 900 } );
	await runCheckoutFlow( gatePage, failedRequests );

	console.log( JSON.stringify( { evidencePath, pass: true, role, method }, null, 2 ) );
} catch ( error ) {
	const fallbackEvidence = fs.existsSync( evidencePath )
		? JSON.parse( fs.readFileSync( evidencePath, 'utf8' ) )
		: {};
	const pageEvidence = gatePage
		? await capturePageEvidence( gatePage, {
				checkout_requests: latestCheckoutRequests,
				checkout_responses: latestCheckoutResponses,
				failed_requests: failedRequests,
		} ).catch( ( captureError ) => ( {
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
		if ( requestFailureHandler ) {
			gatePage.off( 'requestfailed', requestFailureHandler );
		}
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
