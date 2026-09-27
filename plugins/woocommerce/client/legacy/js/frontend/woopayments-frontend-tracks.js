( function () {
	'use strict';

	var params = window.wc_woopayments_frontend_tracks_params;
	var events;

	if (
		! params ||
		! params.events ||
		! params.events.length ||
		! window.fetch ||
		! window.FormData
	) {
		return;
	}

	events = params.events;
	params.events = [];

	events.forEach( function ( track ) {
		var body = new window.FormData();
		body.append( 'tracksNonce', params.nonce );
		body.append( 'action', 'platform_tracks' );
		body.append( 'tracksEventName', track.event );
		body.append(
			'tracksEventProp',
			JSON.stringify( track.properties || {} )
		);

		window
			.fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.catch( function () {} );
	} );
} )();
