// A4/N12 native WooPayments checkout browser gate.
//
// This is a browser/runbook gate, not a visual-diff gate. It drives only the
// local target store and local reference store, records JSON/screenshot
// evidence, and fails closed. Red results must become product fixes, fixture
// fixes, or tracked A4 slices; do not weaken this script to hide product bugs.
// WPCOM sandbox/repo access and remote writes are forbidden.

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const runState = typeof state !== 'undefined' ? state : {};
const defaultTargetBase = 'http://store8889.localhost:8889';
const defaultReferenceBase = 'http://localhost:8082';
const targetBase = runState.allowBaseOverrides === true ? runState.targetBase || defaultTargetBase : defaultTargetBase;
const referenceBase = runState.allowBaseOverrides === true ? runState.referenceBase || defaultReferenceBase : defaultReferenceBase;
const routeOverrides = runState.allowRouteOverrides === true && runState.routes ? runState.routes : null;
const gateSlug =
	( typeof process !== 'undefined' && process.env?.WOOPAYMENTS_GATE_SLUG ) ||
	runState.gateSlug ||
	'a4aq';
const tempRoot =
	( typeof process !== 'undefined' && process.env?.TMPDIR ) ||
	( typeof __dirname !== 'undefined' ? path.join( __dirname, '.tmp' ) : '.tmp' );
const dataDir =
	( typeof process !== 'undefined' && process.env?.WOOPAYMENTS_BROWSER_DATA_DIR ) ||
	runState.dataDir ||
	path.join( tempRoot, 'woopayments-merge', gateSlug );
const evidencePath =
	( typeof process !== 'undefined' && process.env?.WOOPAYMENTS_BROWSER_EVIDENCE_PATH ) ||
	runState.evidencePath ||
	path.join( dataDir, `${ gateSlug }-checkout-browser-gate.json` );

const resourcePattern =
	/wc-payment-method-woopayments|woocommerce-payments\/dist|woopayments-checkout|woopayments-express-checkout|woopayments-woopay|payment-methods-cards|payment-method-icons|woopayments-card-brands|js\.stripe\.com\/v3/i;

function assertLocalBase( label, value, expectedHost ) {
	const url = new URL( value );
	const actual = `${ url.hostname }:${ url.port || ( url.protocol === 'https:' ? '443' : '80' ) }`;
	if ( actual !== expectedHost ) {
		throw new Error( `${ label } must stay on ${ expectedHost }, got ${ value }` );
	}
}

assertLocalBase( 'targetBase', targetBase, 'store8889.localhost:8889' );
assertLocalBase( 'referenceBase', referenceBase, 'localhost:8082' );

function localUrl( base, route ) {
	if ( /^https?:\/\//.test( route ) ) {
		return route;
	}
	return `${ base }${ route.startsWith( '/' ) ? '' : '/' }${ route }`;
}

function configuredRoute( key, fallback ) {
	return routeOverrides && typeof routeOverrides[ key ] === 'string' ? routeOverrides[ key ] : fallback;
}

const surfaces = [
	{
		id: 'blocks-checkout-card',
		target: localUrl( targetBase, configuredRoute( 'blocksCheckoutCard', '/checkout/?a4aq=blocks-card' ) ),
		reference: localUrl( referenceBase, configuredRoute( 'referenceBlocksCheckoutCard', '/checkout/?a4aq=blocks-card' ) ),
		targetRequiredSelectors: [
			'#wcpay-core-blocks-payment-element',
			'.wcpay-core-test-mode-instructions',
			'[data-testid="payment-methods-logos"]',
		],
		referenceRequiredSelectors: [
			'.wcpay-payment-element',
			'[data-testid="payment-methods-logos"]',
		],
		requiredAnySelectorGroups: [
			{
				id: 'stripe-iframe',
				selectors: [ 'iframe[src*="js.stripe.com"]', 'iframe[name^="__privateStripeFrame"]' ],
			},
		],
		forbiddenSelectors: [
			'#wc-woocommerce_payments-new-payment-method',
			'input[name="wc-woocommerce_payments-new-payment-method"]',
		],
		desktopRequiredTokens: [ 'Card', '+ 2' ],
		mobileRequiredTokens: [ 'Card' ],
		targetRequiredResources: [
			/wc-payment-method-woopayments/i,
			/payment-methods-cards\/visa\.svg/i,
			/payment-methods-cards\/mastercard\.svg/i,
			/js\.stripe\.com\/v3/i,
		],
		referenceRequiredResources: [
			/woocommerce-payments\/dist\/blocks-checkout\.js/i,
			/payment-method-icons\/visa\.svg/i,
			/payment-method-icons\/mastercard\.svg/i,
			/js\.stripe\.com\/v3/i,
		],
		requiredSettings: [ 'paymentMethodData' ],
		caveats: [
			'Does not fail on BNPL/local payment-method fields.',
			'Does not compare order-attribution hidden inputs.',
		],
	},
	{
		id: 'blocks-checkout-express',
		target: localUrl( targetBase, configuredRoute( 'blocksCheckoutExpress', '/checkout/?a4aq=blocks-express' ) ),
		reference: localUrl( referenceBase, configuredRoute( 'referenceBlocksCheckoutExpress', '/checkout/?a4aq=blocks-express' ) ),
		targetRequiredAnySelectorGroups: [
			{
				id: 'blocks-express-element',
				selectors: [ '.wcpay-core-express-checkout__element' ],
			},
			{
				id: 'blocks-woopay-button',
				selectors: [ '.woopay-express-button' ],
			},
		],
		referenceRequiredAnySelectorGroups: [
			{
				id: 'blocks-express-ui',
				selectors: [ '#wcpay-woopay-button', '.woopay-express-button', '#wcpay-express-checkout-element' ],
			},
		],
		targetRequiredResources: [
			/wc-payment-method-woopayments-express-checkout/i,
			/wc-payment-method-woopayments-woopay/i,
			/js\.stripe\.com\/v3/i,
		],
		referenceRequiredResources: [
			/woocommerce-payments\/dist\/express-checkout\.js/i,
			/woocommerce-payments\/dist\/woopay(?:-express-button)?\.js/i,
			/js\.stripe\.com\/v3/i,
		],
		requiredSettings: [ 'expressCheckoutMethods' ],
		caveats: [
			'Express method availability is accepted from Store API cart extensions or wcSettings.',
			'Does not fail on methods_enabled_at_location shape drift.',
		],
	},
	{
		id: 'blocks-cart-express',
		target: localUrl( targetBase, configuredRoute( 'blocksCartExpress', '/cart/?a4aq=blocks-cart-express' ) ),
		reference: localUrl( referenceBase, configuredRoute( 'referenceBlocksCartExpress', '/cart/?a4aq=blocks-cart-express' ) ),
		targetRequiredAnySelectorGroups: [
			{
				id: 'blocks-cart-express-element',
				selectors: [ '.wcpay-core-express-checkout__element' ],
			},
			{
				id: 'blocks-cart-woopay-button',
				selectors: [ '.woopay-express-button' ],
			},
		],
		referenceRequiredAnySelectorGroups: [
			{
				id: 'blocks-cart-express-ui',
				selectors: [ '#wcpay-woopay-button', '.woopay-express-button', '#wcpay-express-checkout-element' ],
			},
		],
		targetRequiredResources: [
			/wc-payment-method-woopayments-express-checkout/i,
			/wc-payment-method-woopayments-woopay/i,
			/js\.stripe\.com\/v3/i,
		],
		referenceRequiredResources: [
			/woocommerce-payments\/dist\/cart(?:-block)?\.js/i,
			/woocommerce-payments\/dist\/express-checkout\.js/i,
			/woocommerce-payments\/dist\/woopay(?:-express-button)?\.js/i,
			/js\.stripe\.com\/v3/i,
		],
		requiredSettings: [ 'expressCheckoutMethods' ],
		caveats: [ 'Requires the local cart fixture to already contain items.' ],
	},
	{
		id: 'classic-checkout-card',
		target: localUrl( targetBase, configuredRoute( 'classicCheckoutCard', '/codex-classic-checkout/?a4aq=classic-card' ) ),
		reference: localUrl(
			referenceBase,
			configuredRoute( 'referenceClassicCheckoutCard', '/codex-reference-classic-checkout/?a4aq=classic-card' )
		),
		targetRequiredSelectors: [
			'#wcpay-core-checkout-form[data-wcpay-config]',
			'#wcpay-core-payment-element',
			'.wcpay-core-test-mode-instructions',
			'[data-testid="payment-methods-logos"]',
		],
		referenceRequiredSelectors: [
			'.testmode-info',
			'[data-testid="payment-methods-logos"]',
		],
		requiredAnySelectorGroups: [
			{
				id: 'stripe-iframe',
				selectors: [ 'iframe[src*="js.stripe.com"]', 'iframe[name^="__privateStripeFrame"]' ],
			},
		],
		targetRequiredGlobals: [ 'wcpay_core_checkout_config' ],
		referenceRequiredGlobals: [],
		targetRequiredResources: [ /woopayments-checkout/i, /payment-methods-cards\/visa\.svg/i, /js\.stripe\.com\/v3/i ],
		referenceRequiredResources: [
			/woocommerce-payments\/dist\/checkout\.js/i,
			/payment-method-icons\/visa\.svg/i,
			/js\.stripe\.com\/v3/i,
		],
		caveats: [
			'Card-brand overflow dialog is asserted through the rendered logo container and card icon resource evidence.',
			'Does not fail on reference order-attribution hidden inputs.',
		],
	},
	{
		id: 'classic-add-payment-method',
		target: localUrl(
			targetBase,
			configuredRoute( 'classicAddPaymentMethod', '/my-account/add-payment-method/?a4aq=classic-add-payment-method' )
		),
		reference: localUrl(
			referenceBase,
			configuredRoute( 'referenceClassicAddPaymentMethod', '/my-account/add-payment-method/?a4aq=classic-add-payment-method' )
		),
		targetRequiredSelectors: [ '#add_payment_method', '#wcpay-core-checkout-form[data-wcpay-config]', '#wcpay-core-payment-element' ],
		referenceRequiredSelectors: [ '#add_payment_method', '.testmode-info' ],
		targetRequiredGlobals: [ 'wcpay_core_checkout_config' ],
		referenceRequiredGlobals: [],
		setupIntentSelectors: [ 'input[name="wcpay-setup-intent"]' ],
		targetRequiredResources: [ /woopayments-checkout/i, /js\.stripe\.com\/v3/i ],
		referenceRequiredResources: [ /woocommerce-payments\/dist\/checkout\.js/i, /js\.stripe\.com\/v3/i ],
		forbiddenSelectors: [
			'.wcpay-core-express-checkout',
			'.wcpay-core-express-checkout__element',
			'.wcpay-express-checkout-wrapper',
			'#wcpay-express-checkout-element',
			'#wcpay-woopay-button',
			'.woopay-express-button',
		],
		caveats: [
			'Setup-intent hidden input is recorded as pre-submit evidence; a full setup submission is outside this local gate.',
		],
	},
	{
		id: 'product-express',
		target: localUrl( targetBase, configuredRoute( 'productExpress', '/product/h20-ece-probe-product/?a4aq=product-express' ) ),
		reference: localUrl(
			referenceBase,
			configuredRoute( 'referenceProductExpress', '/product/h20-ece-probe-product/?a4aq=product-express' )
		),
		targetRequiredAnySelectorGroups: [
			{
				id: 'product-ece-button',
				selectors: [ '.wcpay-express-checkout-wrapper', '#wcpay-express-checkout-element' ],
			},
			{
				id: 'product-woopay-button',
				selectors: [ '#wcpay-woopay-button[data-product_page="1"]', '.woopay-express-button' ],
			},
		],
		referenceRequiredAnySelectorGroups: [
			{
				id: 'classic-ece-or-woopay-product-button',
				selectors: [
					'.wcpay-express-checkout-wrapper',
					'#wcpay-express-checkout-element',
					'#wcpay-woopay-button[data-product_page="1"]',
					'.woopay-express-button',
				],
			},
		],
		targetRequiredResources: [ /woopayments-express-checkout|woopayments-woopay/i, /js\.stripe\.com\/v3/i ],
		referenceRequiredResources: [
			/woocommerce-payments\/dist\/(?:express-checkout|woopay(?:-express-button)?)\.js/i,
			/js\.stripe\.com\/v3/i,
		],
		caveats: [
			'Product express depends on a deterministic simple-product fixture; source/Jest coverage remains the fallback when the fixture cannot render both ECE and WooPay locally.',
			'Does not assert WooPay order-pay parity.',
		],
	},
];

const viewports = [
	{ id: 'desktop', width: 1440, height: 1100 },
	{ id: 'mobile', width: 390, height: 844 },
];

const sharedExpressSurfaceIds = new Set( [
	'blocks-checkout-express',
	'blocks-cart-express',
	'product-express',
] );

for ( const surface of surfaces ) {
	assertLocalBase( `${ surface.id } target`, surface.target, 'store8889.localhost:8889' );
	assertLocalBase( `${ surface.id } reference`, surface.reference, 'localhost:8082' );
}

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

const selectedSurfaceIds = toOptionalSelectionSet( runState.surfaceIds );
const selectedViewportIds = toOptionalSelectionSet( runState.viewportIds );
const selectedStoreIds = toOptionalSelectionSet( runState.storeIds );
assertKnownSelection( 'Surface', selectedSurfaceIds, surfaces.map( ( surface ) => surface.id ) );
assertKnownSelection( 'Viewport', selectedViewportIds, viewports.map( ( viewport ) => viewport.id ) );
assertKnownSelection( 'Store', selectedStoreIds, [ 'target', 'reference' ] );
const selectedSurfaces = selectedSurfaceIds ? surfaces.filter( ( surface ) => selectedSurfaceIds.has( surface.id ) ) : surfaces;
const selectedViewports = selectedViewportIds ? viewports.filter( ( viewport ) => selectedViewportIds.has( viewport.id ) ) : viewports;
const selectedStores = selectedStoreIds ? [ 'target', 'reference' ].filter( ( store ) => selectedStoreIds.has( store ) ) : [ 'target', 'reference' ];

if ( selectedSurfaces.length === 0 || selectedViewports.length === 0 || selectedStores.length === 0 ) {
	throw new Error( 'A4 checkout browser gate produced no selected work. Check surfaceIds, viewportIds, and storeIds.' );
}

function ensureDir( dir ) {
	fs.mkdirSync( dir, { recursive: true } );
}

function normalizeText( value ) {
	return String( value || '' ).replace( /\s+/g, ' ' ).trim();
}

function interestingFailure( response ) {
	const status = response.status();
	if ( status < 400 ) {
		return false;
	}
	const url = response.url();
	return ! /favicon\.ico|load-scripts\.php.*ver=/.test( url );
}

function isStoreApiPrettyCartFallbackFailure( failure ) {
	return (
		failure.status === 404 &&
		/\/wp-json\/wc\/store\/v1\/cart(?:[?#]|$)/i.test( failure.url )
	);
}

function isLocalWooCommercePlaceholderMediaFailure( failure ) {
	return (
		failure.status === 404 &&
		/^http:\/\/(?:store8889\.localhost:8889|localhost:8082)\/wp-content\/uploads\/woocommerce-placeholder(?:-\d+x\d+)?\.webp(?:[?#].*)?$/i.test( failure.url )
	);
}

function isIgnoredFailedResponse( store, surface, failure ) {
	return (
		( store === 'target' && isStoreApiPrettyCartFallbackFailure( failure ) ) ||
		isLocalWooCommercePlaceholderMediaFailure( failure ) ||
		( failure.status === 400 &&
			/^https:\/\/[rq]\.stripe\.com\//.test( failure.url ) ) ||
		( failure.status === 429 &&
			/^http:\/\/woopay\.localhost:30001\/wp-json\/platform-checkout\/v1\/user\/exists/.test(
				failure.url
			) ) ||
		( failure.status === 500 &&
			[ 'blocks-checkout-card', 'blocks-checkout-express', 'blocks-cart-express', 'product-express' ].includes( surface.id ) &&
			/^https:\/\/apay-us\.amazon\.com\/amazonpayMerchantId\?/.test(
				failure.url
			) )
		||
		( failure.status === 502 &&
			/^https:\/\/apay-us\.amazon\.com\/cs\/uedata/.test(
				failure.url
			) )
	);
}

function isConsoleIssue( issue ) {
	return [ 'error', 'warning' ].includes( issue.type ) || /fatal|exception/i.test( issue.text );
}

const expectedConsoleIssueRules = [
	{
		id: 'local-unload-permissions-policy',
		stores: [ 'target', 'reference' ],
		text: /Permissions policy violation: unload/i,
		sourceUrl: /.*/,
		maxCount: 2,
	},
	{
		id: 'local-jquery-migrate',
		stores: [ 'target', 'reference' ],
		text: /JQMIGRATE/i,
		sourceUrl: /jquery-migrate|load-scripts\.php/i,
		maxCount: 4,
	},
	{
		id: 'chromium-webgl-readpixels-warning',
		stores: [ 'target', 'reference' ],
		text: /^\[\.WebGL-[^\]]+\]GL Driver Message .*ReadPixels/i,
		sourceUrl: /.*/,
		maxCount: 12,
	},
	{
		id: 'react-devtools-useselect-validation',
		stores: [ 'target', 'reference' ],
		surfaces: [ 'blocks-checkout-card', 'blocks-checkout-express' ],
		text: /The `useSelect` hook returns different values when called with the same state and parameters\./,
		textOwner: /getValidationError[\s\S]+wp-content\/plugins\/woocommerce\/assets\/client\/blocks\/checkout-frontend\.js/,
		sourceUrl: /^chrome-extension:\/\/[^/]+\/build\/installHook\.js/,
		maxCount: 30,
	},
	{
		id: 'wc-blocks-useselect-validation',
		stores: [ 'target', 'reference' ],
		surfaces: [ 'blocks-checkout-card', 'blocks-checkout-express', 'blocks-cart-express' ],
		text: /The `useSelect` hook returns different values when called with the same state and parameters\.[\s\S]+getValidationError/,
		sourceUrl: /\/wp-includes\/js\/dist\/data\.js/,
		maxCount: 40,
	},
	{
		id: 'react-devtools-wcblocksdata-dependency-warning',
		stores: [ 'target', 'reference' ],
		surfaces: [ 'blocks-checkout-card', 'blocks-checkout-express', 'blocks-cart-express' ],
		text: /^\[WooCommerce\] An inline or unknown script accessed wc\.wcBlocksData without proper dependency declaration\./,
		textOwner: /wp-content\/plugins\/woocommerce\/assets\/client\/blocks\/wc-cart-checkout-base-frontend\.js/,
		sourceUrl: /^chrome-extension:\/\/[^/]+\/build\/installHook\.js/,
		maxCount: 5,
	},
	{
		id: 'reference-wcsettings-dependency-warning',
		stores: [ 'reference' ],
		text: /^\[WooCommerce\] (An inline or unknown script|Script "WCPAY_WOOPAY_EXPRESS_BUTTON") accessed wc\.wcSettings without/,
		sourceUrl: /.*/,
		maxCount: 8,
	},
	{
		id: 'reference-wcblocksdata-dependency-warning',
		stores: [ 'reference' ],
		surfaces: [ 'blocks-checkout-card', 'blocks-checkout-express', 'blocks-cart-express' ],
		text: /^\[WooCommerce\] An inline or unknown script accessed wc\.wcBlocksData without proper dependency declaration\./,
		sourceUrl: /.*/,
		maxCount: 2,
	},
	{
		id: 'target-store-api-pretty-cart-fallback-resource',
		stores: [ 'target' ],
		text: /^Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
		sourceUrl: /\/wp-json\/wc\/store\/v1\/cart/,
		maxCount: 12,
	},
	{
		id: 'local-woocommerce-placeholder-media-resource',
		stores: [ 'target', 'reference' ],
		text: /^Failed to load resource: the server responded with a status of 404 \(Not Found\)/,
		sourceUrl: /^http:\/\/(?:store8889\.localhost:8889|localhost:8082)\/wp-content\/uploads\/woocommerce-placeholder(?:-\d+x\d+)?\.webp(?:[?#].*)?$/i,
		maxCount: 4,
	},
	{
		id: 'core-mini-cart-interactivity-deprecation',
		stores: [ 'target', 'reference' ],
		surfaces: [ 'classic-add-payment-method', 'product-express' ],
		text: /^The usage of data-wp-init--mark-as-hydrated \(two hyphens for unique ID\) is deprecated/,
		sourceUrl: /^chrome-extension:\/\/[^/]+\/build\/installHook\.js/,
		maxCount: 2,
	},
	{
		id: 'stripe-amazon-pay-merchant-fetch',
		stores: [ 'target', 'reference' ],
		surfaces: [ 'blocks-checkout-card', 'blocks-checkout-express', 'blocks-cart-express', 'product-express' ],
		text: /^Failed to fetch Amazon Pay merchantId for Stripe flow: TypeError: Failed to fetch/,
		sourceUrl: /^https:\/\/b\.stripecdn\.com\/stripethirdparty-srv\//,
		maxCount: 2,
	},
	{
		id: 'woopay-local-rate-limit-resource',
		stores: [ 'target', 'reference' ],
		text: /^Failed to load resource: the server responded with a status of 429 \(Too Many Requests\)/,
		sourceUrl: /^http:\/\/woopay\.localhost:30001\//,
		maxCount: 4,
	},
	{
		id: 'amazon-pay-merchant-id-resource',
		stores: [ 'target', 'reference' ],
		text: /^Failed to load resource: the server responded with a status of 500/,
		sourceUrl: /^https:\/\/apay-us\.amazon\.com\/amazonpayMerchantId\?/,
		maxCount: 2,
	},
	{
		id: 'amazon-pay-uedata-resource',
		stores: [ 'target', 'reference' ],
		text: /^Failed to load resource: the server responded with a status of 502/,
		sourceUrl: /^https:\/\/apay-us\.amazon\.com\/cs\/uedata/,
		maxCount: 2,
	},
	{
		id: 'reference-stripe-radius-px-warning',
		stores: [ 'reference' ],
		text: /^\[Stripe\.js\] stripe\.elements\(\): invalid variable value "px" provided to "borderRadius"/,
		sourceUrl: /^https:\/\/js\.stripe\.com\/v3\//,
		maxCount: 12,
	},
	{
		id: 'reference-stripe-srgb-border-warning',
		stores: [ 'reference' ],
		text: /^\[Stripe\.js\] stripe\.elements\(\): "(?:1px solid )?color\(srgb [^"]+ \/ [^"]+\)" is not a supported value for the "border(?:Top|Right|Bottom|Left)?(?:Color)?" property; the "\/" character is not supported/,
		sourceUrl: /^https:\/\/js\.stripe\.com\/v3\//,
		maxCount: 24,
	},
	{
		id: 'stripe-local-http-warning',
		stores: [ 'target', 'reference' ],
		text: /Stripe\.js.*HTTPS|live Stripe\.js integrations must use HTTPS/i,
		sourceUrl: /^https:\/\/js\.stripe\.com\/v3\//,
		maxCount: 6,
	},
	{
		id: 'reference-stripe-http-react-devtools-warning',
		stores: [ 'reference' ],
		text: /You may test your Stripe\.js integration over HTTP\. However, live Stripe\.js integrations must use HTTPS\./,
		sourceUrl: /^chrome-extension:\/\/[^/]+\/build\/installHook\.js/,
		maxCount: 4,
	},
	{
		id: 'stripe-local-domain-registration-warning',
		stores: [ 'target', 'reference' ],
		text: /^\[Stripe\.js\] You have not registered or verified the domain, so the following payment methods are not enabled in the Express Checkout Element:/i,
		sourceUrl: /^https:\/\/js\.stripe\.com\/v3\//,
		maxCount: 2,
	},
];

function ruleListIncludes( values, value ) {
	return ! values || values.includes( value );
}

function getExpectedConsoleIssueRule( store, surface, viewport, issue ) {
	const text = issue.text;
	const sourceUrl = issue.location?.url || '';
	for ( const rule of expectedConsoleIssueRules ) {
		if (
			ruleListIncludes( rule.stores, store ) &&
			ruleListIncludes( rule.surfaces, surface.id ) &&
			ruleListIncludes( rule.viewports, viewport.id ) &&
			rule.text.test( text ) &&
			( ! rule.textOwner || rule.textOwner.test( text ) ) &&
			rule.sourceUrl.test( sourceUrl )
		) {
			return rule;
		}
	}
	return null;
}

function collectExpectedConsoleIssueLimitFailures( ignoredConsoleIssues ) {
	const counts = new Map();
	for ( const issue of ignoredConsoleIssues ) {
		if ( ! issue.expectedRuleId ) {
			continue;
		}
		counts.set( issue.expectedRuleId, ( counts.get( issue.expectedRuleId ) || 0 ) + 1 );
	}
	return expectedConsoleIssueRules
		.filter( ( rule ) => counts.has( rule.id ) && counts.get( rule.id ) > rule.maxCount )
		.map( ( rule ) => `expected console issue ${ rule.id } exceeded max ${ rule.maxCount }: ${ counts.get( rule.id ) }` );
}

function patternLabel( pattern ) {
	return pattern instanceof RegExp ? pattern.toString() : String( pattern );
}

function matchesPattern( value, pattern ) {
	return pattern instanceof RegExp ? pattern.test( value ) : String( value ).includes( String( pattern ) );
}

function hasAnyResource( resources, pattern ) {
	return resources.some( ( resource ) => matchesPattern( resource, pattern ) );
}

function storeSpecificValue( surface, store, key, fallback = [] ) {
	const storeKey = `${ store }${ key.charAt( 0 ).toUpperCase() }${ key.slice( 1 ) }`;
	return surface[ storeKey ] || surface[ key ] || fallback;
}

function scopedValue( surface, store, viewport, key, fallback = [] ) {
	const capitalizedKey = `${ key.charAt( 0 ).toUpperCase() }${ key.slice( 1 ) }`;
	const viewportKey = `${ viewport.id }${ capitalizedKey }`;
	const storeViewportKey = `${ store }${ viewportKey.charAt( 0 ).toUpperCase() }${ viewportKey.slice( 1 ) }`;
	return surface[ storeViewportKey ] || surface[ viewportKey ] || storeSpecificValue( surface, store, key, fallback );
}

async function getOrCreatePage() {
	if ( runState.page && ! runState.page.isClosed() ) {
		return runState.page;
	}
	runState.page = context.pages().find( ( candidate ) => candidate.url() === 'about:blank' ) || ( await context.newPage() );
	return runState.page;
}

async function countVisible( page, selector ) {
	const locator = page.locator( selector );
	const count = await locator.count();
	let visible = 0;
	for ( let index = 0; index < Math.min( count, 10 ); index++ ) {
		if ( await locator.nth( index ).isVisible().catch( () => false ) ) {
			visible++;
		}
	}
	return { selector, count, visible, passed: visible > 0 };
}

async function collectSelectorEvidence( page, selectors ) {
	const evidence = [];
	for ( const selector of selectors || [] ) {
		evidence.push( await countVisible( page, selector ) );
	}
	return evidence;
}

async function collectAnySelectorEvidence( page, groups ) {
	const evidence = [];
	for ( const group of groups || [] ) {
		const selectors = await collectSelectorEvidence( page, group.selectors );
		evidence.push( {
			id: group.id,
			selectors,
			passed: selectors.some( ( selector ) => selector.passed ),
		} );
	}
	return evidence;
}

async function collectBrowserEvidence( page ) {
	return page.evaluate( () => {
		const extractCssUrls = ( value ) => Array.from( String( value || '' ).matchAll( /url\(["']?([^"')]+)["']?\)/g ) ).map( ( match ) => match[ 1 ] );
		const imageSources = new Set();
		const addSource = ( value ) => {
			if ( value ) {
				imageSources.add( value );
			}
		};
		for ( const node of Array.from( document.querySelectorAll( 'img, source' ) ) ) {
			addSource( node.getAttribute( 'src' ) );
			addSource( node.src );
			addSource( node.currentSrc );
			const srcset = node.getAttribute( 'srcset' ) || '';
			for ( const part of srcset.split( ',' ) ) {
				addSource( part.trim().split( /\s+/ )[ 0 ] );
			}
		}
		for ( const node of Array.from( document.querySelectorAll( 'svg use' ) ) ) {
			addSource( node.getAttribute( 'href' ) );
			addSource( node.getAttribute( 'xlink:href' ) );
		}
		for ( const node of Array.from( document.querySelectorAll( '[data-testid="payment-methods-logos"], [data-testid="payment-methods-logos"] *' ) ) ) {
			const styles = window.getComputedStyle( node );
			for ( const property of [ 'backgroundImage', 'maskImage', 'webkitMaskImage' ] ) {
				for ( const source of extractCssUrls( styles[ property ] ) ) {
					addSource( source );
				}
			}
		}
		const paymentMethodData = window.wcSettings?.paymentMethodData?.woocommerce_payments || null;
		const settings = paymentMethodData || window.wcSettings?.woocommerce_payments_data || {};
		const bodyText = document.body?.innerText || '';
		const scripts = Array.from( document.querySelectorAll( 'script[src]' ) )
			.map( ( node ) => node.getAttribute( 'src' ) || '' )
			.filter( Boolean );
		const styles = Array.from( document.querySelectorAll( 'link[href]' ) )
			.map( ( node ) => node.getAttribute( 'href' ) || '' )
			.filter( Boolean );
		const images = Array.from( imageSources ).filter( Boolean );
		const globals = {
			wcpay_core_checkout_config: Boolean( window.wcpay_core_checkout_config ),
			wcpayExpressCheckoutParams: Boolean( window.wcpayExpressCheckoutParams ),
			wcpay_core_woopay_config: Boolean( window.wcpay_core_woopay_config ),
		};
		const expressCheckoutMethods =
			settings?.express_checkout_methods ||
			settings?.expressCheckoutMethods ||
			paymentMethodData?.express_checkout_methods ||
			paymentMethodData?.expressCheckoutMethods ||
			paymentMethodData?.params?.enabled_methods ||
			[];

		return {
			settings,
			paymentMethodData,
			labels: bodyText.replace( /\s+/g, ' ' ).trim(),
			scripts,
			styles,
			images,
			globals,
			expressCheckoutMethods,
		};
	} );
}

async function collectResourceSnapshot( page ) {
	const browserEvidence = await collectBrowserEvidence( page );
	const resources = await page.evaluate( () => performance.getEntriesByType( 'resource' ).map( ( entry ) => entry.name ) );
	const allResources = [
		...new Set( browserEvidence.scripts.concat( browserEvidence.styles, browserEvidence.images, resources ) ),
	];
	const interestingResources = allResources.filter( ( source ) => resourcePattern.test( source ) );

	return {
		browserEvidence,
		resources,
		allResources,
		interestingResources,
	};
}

async function waitForRequiredResourceEvidence( page, requiredResources, timeout = 7000 ) {
	const deadline = Date.now() + timeout;
	let snapshot = await collectResourceSnapshot( page );
	while ( requiredResources.length > 0 && ! requiredResources.every( ( pattern ) => hasAnyResource( snapshot.allResources, pattern ) ) && Date.now() < deadline ) {
		await page.waitForTimeout( 250 );
		snapshot = await collectResourceSnapshot( page );
	}
	return snapshot;
}

async function collectStoreApiCartEvidence( page ) {
	return page.evaluate( async () => {
		let lastFailure = null;
		for ( const cartEndpoint of [ '/wp-json/wc/store/v1/cart', '/?rest_route=/wc/store/v1/cart' ] ) {
			try {
				const response = await window.fetch( cartEndpoint, {
					credentials: 'include',
				} );
				const text = await response.text();
				let body = null;
				try {
					body = text ? JSON.parse( text ) : null;
				} catch ( error ) {
					body = null;
				}

				if ( response.ok || 404 !== response.status ) {
					return {
						status: response.status,
						url: response.url,
						endpoint: cartEndpoint,
						body,
						error: response.ok ? '' : text.slice( 0, 500 ),
					};
				}

				lastFailure = {
					status: response.status,
					url: response.url,
					endpoint: cartEndpoint,
					body,
					error: text.slice( 0, 500 ),
				};
			} catch ( error ) {
				lastFailure = {
					status: 0,
					url: cartEndpoint,
					endpoint: cartEndpoint,
					body: null,
					error: error?.message || String( error ),
				};
			}
		}

		return lastFailure || {
			status: 0,
			url: '/wp-json/wc/store/v1/cart',
			endpoint: '/wp-json/wc/store/v1/cart',
			body: null,
			error: 'Store API cart probe did not run.',
		};
	} );
}

async function readBodyTextAfterSettle( page, expectedTokens ) {
	const deadline = Date.now() + 10000;
	let bodyText = '';
	do {
		bodyText = normalizeText( await page.locator( 'body' ).innerText( { timeout: 20000 } ) );
		if ( ( expectedTokens || [] ).every( ( token ) => bodyText.includes( token ) ) ) {
			return bodyText;
		}
		await page.waitForTimeout( 500 );
	} while ( Date.now() < deadline );
	return bodyText;
}

function collectStoreApiExpressMethods( storeApiResponses ) {
	const methods = [];
	for ( const item of storeApiResponses ) {
		const extensionMethods = item.body?.extensions?.wcpay?.express_checkout_methods;
		if ( Array.isArray( extensionMethods ) ) {
			methods.push( ...extensionMethods );
		}
	}
	return [ ...new Set( methods ) ];
}

function evaluateSettingsRequirements( surface, browserEvidence, storeApiResponses ) {
	const required = surface.requiredSettings || [];
	const storeApiExpressMethods = collectStoreApiExpressMethods( storeApiResponses );
	const checks = required.map( ( requirement ) => {
		if ( requirement === 'paymentMethodData' ) {
			return {
				requirement,
				passed: Boolean( browserEvidence.paymentMethodData && Object.keys( browserEvidence.paymentMethodData ).length > 0 ),
			};
		}
		if ( requirement === 'expressCheckoutMethods' ) {
			const methods = [
				...( Array.isArray( browserEvidence.expressCheckoutMethods ) ? browserEvidence.expressCheckoutMethods : [] ),
				...storeApiExpressMethods,
			];
			return {
				requirement,
				methods: [ ...new Set( methods ) ],
				storeApiExpressMethods,
				passed: methods.length > 0,
			};
		}
		return { requirement, passed: false, reason: 'Unknown settings requirement.' };
	} );
	return checks;
}

function evaluateGlobalRequirements( surface, browserEvidence ) {
	return ( surface.requiredGlobals || [] ).map( ( globalName ) => ( {
		global: globalName,
		passed: Boolean( browserEvidence.globals?.[ globalName ] ),
	} ) );
}

async function checkRoute( page, store, surface, viewport ) {
	const url = store === 'target' ? surface.target : surface.reference;
	const failedResponses = [];
	const ignoredFailedResponses = [];
	const consoleIssues = [];
	const ignoredConsoleIssues = [];
	const pageErrors = [];
	const storeApiResponses = [];
	const storeApiResponsePromises = [];
	const requiredTokens = scopedValue( surface, store, viewport, 'requiredTokens' );
	const forbiddenTokens = scopedValue( surface, store, viewport, 'forbiddenTokens' );
	const requiredSelectors = scopedValue( surface, store, viewport, 'requiredSelectors' );
	const forbiddenSelectors = scopedValue( surface, store, viewport, 'forbiddenSelectors' );
	const requiredAnySelectorGroups = scopedValue( surface, store, viewport, 'requiredAnySelectorGroups' );
	const setupIntentSelectors = scopedValue( surface, store, viewport, 'setupIntentSelectors' );
	const requiredResources = scopedValue( surface, store, viewport, 'requiredResources' );
	const requiredSettings = scopedValue( surface, store, viewport, 'requiredSettings' );
	const requiredGlobals = scopedValue( surface, store, viewport, 'requiredGlobals' );

	await page.goto( 'about:blank', { waitUntil: 'domcontentloaded', timeout: 10000 } );
	await page.waitForTimeout( 100 );

	const responseHandler = ( response ) => {
		const urlString = response.url();
		if ( /\/wc\/store\/v1\/cart(?:\?|$|\/)/.test( urlString ) ) {
			storeApiResponsePromises.push(
				response
					.json()
					.then( ( body ) => {
						storeApiResponses.push( { status: response.status(), url: urlString, body } );
					} )
					.catch( () => {
						storeApiResponses.push( { status: response.status(), url: urlString, body: null } );
					} )
			);
		}

		if ( interestingFailure( response ) ) {
			const failure = { status: response.status(), url: urlString };
			if ( isIgnoredFailedResponse( store, surface, failure ) ) {
				ignoredFailedResponses.push( failure );
				return;
			}
			failedResponses.push( failure );
		}
	};
	const consoleHandler = ( message ) => {
		const text = message.text();
		const issue = { type: message.type(), text, location: message.location() };
		if ( isConsoleIssue( issue ) ) {
			const expectedRule = getExpectedConsoleIssueRule( store, surface, viewport, issue );
			if ( expectedRule ) {
				ignoredConsoleIssues.push( { ...issue, expectedRuleId: expectedRule.id } );
				return;
			}
			consoleIssues.push( issue );
		}
	};
	const pageErrorHandler = ( error ) => {
		pageErrors.push( {
			message: error.message,
			stack: error.stack || '',
		} );
	};

	page.on( 'response', responseHandler );
	page.on( 'console', consoleHandler );
	page.on( 'pageerror', pageErrorHandler );

	await page.setViewportSize( { width: viewport.width, height: viewport.height } );
	await page.evaluate( () => performance.clearResourceTimings() );
	await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
	await page.waitForTimeout( 1000 );
	await Promise.allSettled( storeApiResponsePromises );
	storeApiResponses.push( await collectStoreApiCartEvidence( page ) );

	const bodyText = await readBodyTextAfterSettle( page, requiredTokens );
	const resourceSnapshot = await waitForRequiredResourceEvidence( page, requiredResources );
	const { browserEvidence, allResources, interestingResources } = resourceSnapshot;
	const selectorEvidence = await collectSelectorEvidence( page, requiredSelectors );
	const forbiddenSelectorEvidence = await collectSelectorEvidence( page, forbiddenSelectors );
	const anySelectorEvidence = await collectAnySelectorEvidence( page, requiredAnySelectorGroups );
	const setupIntentEvidence = await collectSelectorEvidence( page, setupIntentSelectors );
	const missingTokens = requiredTokens.filter( ( token ) => ! bodyText.includes( token ) );
	const forbiddenTokensPresent = forbiddenTokens.filter( ( token ) => bodyText.includes( token ) );
	const resourceEvidence = requiredResources.map( ( pattern ) => ( {
		pattern: patternLabel( pattern ),
		passed: hasAnyResource( allResources, pattern ),
		matches: allResources.filter( ( resource ) => matchesPattern( resource, pattern ) ),
	} ) );
	const settingsEvidence = evaluateSettingsRequirements( { ...surface, requiredSettings }, browserEvidence, storeApiResponses );
	const globalEvidence = evaluateGlobalRequirements( { ...surface, requiredGlobals }, browserEvidence );
	const expectedConsoleIssueLimitFailures = collectExpectedConsoleIssueLimitFailures( ignoredConsoleIssues );
	const screenshotPath = path.join( dataDir, `${ store }-${ surface.id }-${ viewport.id }.png` );
	await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );

	page.off( 'response', responseHandler );
	page.off( 'console', consoleHandler );
	page.off( 'pageerror', pageErrorHandler );

	const failedAssertions = [
		...selectorEvidence.filter( ( selector ) => ! selector.passed ).map( ( selector ) => `missing selector ${ selector.selector }` ),
		...forbiddenSelectorEvidence
			.filter( ( selector ) => selector.passed )
			.map( ( selector ) => `forbidden selector present ${ selector.selector }` ),
		...anySelectorEvidence.filter( ( group ) => ! group.passed ).map( ( group ) => `missing selector group ${ group.id }` ),
		...resourceEvidence.filter( ( resource ) => ! resource.passed ).map( ( resource ) => `missing resource ${ resource.pattern }` ),
		...settingsEvidence.filter( ( setting ) => ! setting.passed ).map( ( setting ) => `missing settings evidence ${ setting.requirement }` ),
		...globalEvidence.filter( ( globalCheck ) => ! globalCheck.passed ).map( ( globalCheck ) => `missing global ${ globalCheck.global }` ),
		...expectedConsoleIssueLimitFailures,
		...missingTokens.map( ( token ) => `missing token ${ token }` ),
		...forbiddenTokensPresent.map( ( token ) => `forbidden token present ${ token }` ),
	];

	return {
		store,
		surface: surface.id,
		viewport: viewport.id,
		requestedUrl: url,
		finalUrl: page.url(),
		requiredTokens,
		missingTokens,
		forbiddenTokensPresent,
		requiredSelectors: selectorEvidence,
		requiredAnySelectorGroups: anySelectorEvidence,
		forbiddenSelectors: forbiddenSelectorEvidence,
		setupIntentSelectors: setupIntentEvidence,
		requiredResources: resourceEvidence,
		settingsEvidence,
		globalEvidence,
		labels: browserEvidence.labels,
		settings: browserEvidence.settings,
		paymentMethodData: browserEvidence.paymentMethodData,
		storeApiResponses: storeApiResponses.map( ( item ) => ( {
			status: item.status,
			url: item.url,
			expressCheckoutMethods: item.body?.extensions?.wcpay?.express_checkout_methods || null,
		} ) ),
		scripts: browserEvidence.scripts.filter( ( source ) => resourcePattern.test( source ) ),
		styles: browserEvidence.styles.filter( ( source ) => resourcePattern.test( source ) ),
		resources: interestingResources,
		failedResponses,
		ignoredFailedResponses,
		consoleIssues,
		ignoredConsoleIssues,
		expectedConsoleIssueLimitFailures,
		pageErrors,
		caveats: surface.caveats || [],
		screenshotPath,
		failedAssertions,
		passed:
			failedAssertions.length === 0 &&
			failedResponses.length === 0 &&
			consoleIssues.length === 0 &&
			pageErrors.length === 0,
	};
}

async function checkRouteSafely( page, store, surface, viewport ) {
	try {
		return await checkRoute( page, store, surface, viewport );
	} catch ( error ) {
		page.removeAllListeners( 'response' );
		page.removeAllListeners( 'console' );
		page.removeAllListeners( 'pageerror' );

		const screenshotPath = path.join( dataDir, `${ store }-${ surface.id }-${ viewport.id }-exception.png` );
		try {
			await page.screenshot( { path: screenshotPath, fullPage: false, scale: 'css' } );
		} catch ( screenshotError ) {
			// Keep the original route error as the gate failure; screenshot capture is best-effort.
		}

		return {
			store,
			surface: surface.id,
			viewport: viewport.id,
			requestedUrl: store === 'target' ? surface.target : surface.reference,
			finalUrl: page.url(),
			exception: {
				message: error?.message || String( error ),
				stack: error?.stack || '',
			},
			requiredTokens: surface.requiredTokens || [],
			missingTokens: [],
			forbiddenTokensPresent: [],
			requiredSelectors: [],
			requiredAnySelectorGroups: [],
			forbiddenSelectors: [],
			setupIntentSelectors: [],
			requiredResources: [],
			settingsEvidence: [],
			globalEvidence: [],
			labels: '',
			settings: null,
			paymentMethodData: null,
			storeApiResponses: [],
			scripts: [],
			styles: [],
			resources: [],
			failedResponses: [],
			ignoredFailedResponses: [],
			consoleIssues: [],
			ignoredConsoleIssues: [],
			expectedConsoleIssueLimitFailures: [],
			pageErrors: [],
			caveats: surface.caveats || [],
			screenshotPath,
			failedAssertions: [ `route exception: ${ error?.message || String( error ) }` ],
			passed: false,
		};
	}
}

ensureDir( dataDir );
const page = await getOrCreatePage();
const startedAt = new Date().toISOString();
const results = [];

function resultKey( result ) {
	return `${ result.surface }::${ result.viewport }`;
}

function hasTargetOwnDefects( result ) {
	return (
		( result.pageErrors || [] ).length > 0 ||
		( result.consoleIssues || [] ).length > 0 ||
		( result.failedResponses || [] ).length > 0
	);
}

function isSharedExpressSurfaceBlocker( result, referenceFailureKeys ) {
	if ( ! sharedExpressSurfaceIds.has( result.surface ) ) {
		return false;
	}
	if ( result.store === 'reference' ) {
		return true;
	}
	// Only demote a target failure to a shared-prerequisite blocker when the
	// reference failed the same surface::viewport AND the target result shows
	// no defects of its own: a target-only JS error, console issue, or failed
	// response is a real regression and must stay a FAIL.
	return referenceFailureKeys.has( resultKey( result ) ) && ! hasTargetOwnDefects( result );
}

function uniqueStrings( values ) {
	return [ ...new Set( ( values || [] ).map( ( value ) => String( value || '' ).trim() ).filter( Boolean ) ) ];
}

function collectFailedValues( values, mapper ) {
	return uniqueStrings(
		( Array.isArray( values ) ? values : [] )
			.filter( ( value ) => value && value.passed === false )
			.map( mapper )
	);
}

function collectExpressCheckoutMethods( result ) {
	const methods = [];
	for ( const setting of result.settingsEvidence || [] ) {
		if ( Array.isArray( setting.methods ) ) {
			methods.push( ...setting.methods );
		}
		if ( Array.isArray( setting.storeApiExpressMethods ) ) {
			methods.push( ...setting.storeApiExpressMethods );
		}
	}
	for ( const response of result.storeApiResponses || [] ) {
		if ( Array.isArray( response.expressCheckoutMethods ) ) {
			methods.push( ...response.expressCheckoutMethods );
		}
	}
	return uniqueStrings( methods );
}

function collectExpressBlockerDiagnostics( result, referenceFailureKeys ) {
	return {
		blockerCategory: 'local-express-checkout-prerequisite',
		referenceBlockerPresent: result.store === 'reference' || referenceFailureKeys.has( resultKey( result ) ),
		missingSelectorGroups: collectFailedValues( result.requiredAnySelectorGroups, ( group ) => group.id ),
		missingSelectors: collectFailedValues( result.requiredSelectors, ( selector ) => selector.selector ),
		missingResources: collectFailedValues( result.requiredResources, ( resource ) => resource.pattern ),
		missingSettings: collectFailedValues( result.settingsEvidence, ( setting ) => setting.requirement ),
		missingGlobals: collectFailedValues( result.globalEvidence, ( globalCheck ) => globalCheck.global ),
		expressCheckoutMethods: collectExpressCheckoutMethods( result ),
		storeApiStatuses: ( result.storeApiResponses || [] ).map( ( response ) => ( {
			status: response.status,
			expressCheckoutMethods: Array.isArray( response.expressCheckoutMethods ) ? response.expressCheckoutMethods : [],
		} ) ),
	};
}

function describeExpressBlockerDiagnostics( diagnostics ) {
	const parts = [];
	if ( diagnostics.missingSelectorGroups.length > 0 ) {
		parts.push( `missing selector groups: ${ diagnostics.missingSelectorGroups.join( ', ' ) }` );
	}
	if ( diagnostics.missingSelectors.length > 0 ) {
		parts.push( `missing selectors: ${ diagnostics.missingSelectors.join( ', ' ) }` );
	}
	if ( diagnostics.missingResources.length > 0 ) {
		parts.push( `missing resources: ${ diagnostics.missingResources.join( ', ' ) }` );
	}
	if ( diagnostics.missingSettings.length > 0 ) {
		parts.push( `missing settings: ${ diagnostics.missingSettings.join( ', ' ) }` );
	}
	if ( diagnostics.missingGlobals.length > 0 ) {
		parts.push( `missing globals: ${ diagnostics.missingGlobals.join( ', ' ) }` );
	}
	parts.push(
		`express methods: ${
			diagnostics.expressCheckoutMethods.length > 0 ? diagnostics.expressCheckoutMethods.join( ', ' ) : 'none'
		}`
	);
	if ( diagnostics.referenceBlockerPresent ) {
		parts.push( 'reference shared blocker present' );
	}
	return parts.join( '; ' );
}

function summarizeBlockedFailure( result, referenceFailureKeys ) {
	const blockerDiagnostics = collectExpressBlockerDiagnostics( result, referenceFailureKeys );
	return {
		store: result.store,
		surface: result.surface,
		viewport: result.viewport,
		requestedUrl: result.requestedUrl,
		finalUrl: result.finalUrl,
		blockerReason: 'shared local express checkout prerequisite',
		blockerSummary: describeExpressBlockerDiagnostics( blockerDiagnostics ),
		blockerDiagnostics,
		failedAssertions: result.failedAssertions,
		failedResponses: result.failedResponses,
		consoleIssues: result.consoleIssues,
		pageErrors: result.pageErrors,
		screenshotPath: result.screenshotPath,
	};
}

function classifyFailures( currentResults ) {
	const failedResults = currentResults.filter( ( result ) => ! result.passed );
	const referenceFailureKeys = new Set(
		failedResults
			.filter( ( result ) => result.store === 'reference' && sharedExpressSurfaceIds.has( result.surface ) )
			.map( resultKey )
	);
	const failures = [];
	const blockers = [];

	for ( const result of failedResults ) {
		if ( isSharedExpressSurfaceBlocker( result, referenceFailureKeys ) ) {
			blockers.push( summarizeBlockedFailure( result, referenceFailureKeys ) );
			continue;
		}
		failures.push( result );
	}

	return { failures, blockers };
}

function writeEvidence( status = 'running' ) {
	const { failures, blockers } = classifyFailures( results );
	const evidence = {
		gate: `${ gateSlug }-checkout-browser-gate`,
		gateSlug,
		status,
		startedAt,
		lastUpdatedAt: new Date().toISOString(),
		surfaces: selectedSurfaces.map( ( surface ) => surface.id ),
		viewports: selectedViewports.map( ( viewport ) => viewport.id ),
		stores: selectedStores,
		results,
		failures,
		blockers,
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

try {
	for ( const viewport of selectedViewports ) {
	for ( const surface of selectedSurfaces ) {
		for ( const store of selectedStores ) {
			recordResult( await checkRouteSafely( page, store, surface, viewport ) );
		}
	}
}
} finally {
	page.removeAllListeners( 'response' );
	page.removeAllListeners( 'console' );
	page.removeAllListeners( 'pageerror' );
}

let status = 'complete';
const finalClassifiedFailures = classifyFailures( results );
if ( finalClassifiedFailures.failures.length === 0 && finalClassifiedFailures.blockers.length > 0 ) {
	status = 'incomplete';
}
const finalEvidence = writeEvidence( status );
console.log(
	JSON.stringify(
		{
			evidencePath,
			status: finalEvidence.status,
			checked: results.length,
			failures: finalEvidence.failures.length,
			blockers: finalEvidence.blockers.length,
		},
		null,
		2
	)
);
if ( results.length === 0 ) {
	throw new Error( `A4 checkout browser gate produced no route checks. See ${ evidencePath }` );
}
if ( finalEvidence.failures.length > 0 ) {
	throw new Error( `A4 checkout browser gate failed with ${ finalEvidence.failures.length } route checks failing. See ${ evidencePath }` );
}
if ( finalEvidence.blockers.length > 0 ) {
	console.log(
		JSON.stringify(
			{
				status: 'INCOMPLETE',
				reason: 'shared local express checkout prerequisite',
				blockers: finalEvidence.blockers.length,
				evidencePath,
			},
			null,
			2
		)
	);
	// A blockers-only run must not exit 0: exit-code consumers would read it as
	// a PASS. The evidence file above is already written; exit 3 (incomplete /
	// blocked-preconditions) so a4aq-accumulated-gate.py records the check as
	// incomplete and verify.sh's gate() classifies it BLOCKED, never FAIL.
	// playwright-script-runner.mjs runs this script with the real `process` and
	// exits with process.exitCode when the script completes without throwing.
	if ( typeof process !== 'undefined' && process ) {
		process.exitCode = 3;
	}
}
