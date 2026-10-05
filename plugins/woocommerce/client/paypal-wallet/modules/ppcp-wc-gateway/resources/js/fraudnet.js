function loadBeaconJS( options ) {
	const script = document.createElement( 'script' );
	script.src = options.fnUrl;
	document.body.appendChild( script );
}

function injectConfig() {
	let script = document.querySelector(
		"[fncls='fnparams-dede7cc5-15fd-4c75-a9f4-36c430ee3a99']"
	);
	if ( script ) {
		if ( script.parentNode ) {
			script.parentNode.removeChild( script );
		}
	}

	script = document.createElement( 'script' );
	script.id = 'fconfig';
	script.type = 'application/json';
	script.setAttribute(
		'fncls',
		'fnparams-dede7cc5-15fd-4c75-a9f4-36c430ee3a99'
	);

	const configuration = {
		f: FraudNetConfig.f,
		s: FraudNetConfig.s,
	};
	if ( FraudNetConfig.sandbox === '1' ) {
		configuration.sandbox = true;
	}

	script.text = JSON.stringify( configuration );
	document.body.appendChild( script );

	loadBeaconJS( { fnUrl: 'https://c.paypal.com/da/r/fb.js' } );
}

window.addEventListener( 'load', function () {
	injectConfig();
} );
