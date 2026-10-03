const SIFT_SRC = 'https://cdn.sift.com/s.js';
const STRIPE_SRC = 'https://js.stripe.com/v3';

const loadSift = ( {
	beacon_key: beaconKey,
	session_id: sessionId,
	user_id: userId,
} ) => {
	const _sift = ( window._sift = window._sift || [] );
	_sift.push( [ '_setAccount', beaconKey ] );
	_sift.push( [ '_setUserId', userId ] );
	_sift.push( [ '_setSessionId', sessionId ] );
	_sift.push( [ '_trackPageview' ] );

	if ( ! document.querySelector( `[src="${ SIFT_SRC }"]` ) ) {
		const script = document.createElement( 'script' );
		script.src = SIFT_SRC;
		script.async = true;
		document.body.appendChild( script );
	}
};

const loadStripe = () => {
	if ( ! document.querySelector( `[src^="${ STRIPE_SRC }"]` ) ) {
		const script = document.createElement( 'script' );
		script.src = STRIPE_SRC;
		script.async = true;
		document.body.appendChild( script );
	}
};

const services = {
	sift: loadSift,
	stripe: loadStripe,
};

/**
 * Enqueue the anti-fraud scripts described by the fraud-services config.
 *
 * Idempotent across bundles: the regular and express payment-method entries
 * can both load on one page, and the Sift snippet must push its account,
 * identity and pageview commands exactly once.
 *
 * @param {Object} config Fraud-services config keyed by service ID.
 */
const enqueueFraudScripts = ( config ) => {
	if ( ! config || window.__wooPaymentsFraudScriptsEnqueued ) {
		return;
	}
	window.__wooPaymentsFraudScriptsEnqueued = true;

	for ( const serviceName in config ) {
		const service = services[ serviceName ];
		if ( ! service || ! config[ serviceName ] ) {
			continue;
		}

		service( config[ serviceName ] );
	}
};

export default enqueueFraudScripts;
