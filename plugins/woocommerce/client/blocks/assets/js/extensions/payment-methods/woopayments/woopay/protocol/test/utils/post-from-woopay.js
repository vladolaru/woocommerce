const WOOPAY_ORIGIN = 'https://pay.woo.test';

/**
 * Remove each test's message listeners after it. A page has one Connect client, but each test that loads a fresh copy
 * of the module leaves one listening, and its iframe is gone, so it would warn about the next test's messages.
 */
export const removeMessageListenersAfterEach = () => {
	let listeners;
	let addEventListener;

	beforeEach( () => {
		listeners = [];
		const add = window.addEventListener.bind( window );
		addEventListener = jest
			.spyOn( window, 'addEventListener' )
			.mockImplementation( ( type, listener, options ) => {
				if ( type === 'message' ) {
					listeners.push( listener );
				}
				add( type, listener, options );
			} );
	} );

	afterEach( () => {
		listeners.forEach( ( listener ) =>
			window.removeEventListener( 'message', listener )
		);
		addEventListener.mockRestore();
	} );
};

/**
 * The window of an iframe, held still: the listeners compare event.source with iframe.contentWindow, and a stubbed
 * getter would answer a new object each time.
 *
 * @param {HTMLIFrameElement|null} iframe The iframe.
 * @return {Object|null} Its window.
 */
export const getIframeWindow = ( iframe ) => {
	if ( ! iframe ) {
		return null;
	}

	const iframeWindow = iframe.contentWindow;
	if ( ! Object.getOwnPropertyDescriptor( iframe, 'contentWindow' ) ) {
		Object.defineProperty( iframe, 'contentWindow', {
			configurable: true,
			value: iframeWindow,
		} );
	}

	return iframeWindow;
};

/**
 * Deliver a message to the store window from a given window.
 *
 * @param {Object|null} source The sending window.
 * @param {Object}      data   The message.
 * @param {string}      origin The sender's origin.
 */
export const dispatchFromWindow = ( source, data, origin = WOOPAY_ORIGIN ) => {
	const event = new window.MessageEvent( 'message', { data, origin } );
	Object.defineProperty( event, 'source', { value: source } );
	window.dispatchEvent( event );
};

/**
 * Deliver a WooPay message from the iframe that sends it: Connect answers from the connect iframe, the rest from the
 * open code dialog.
 *
 * @param {Object} data   The message.
 * @param {string} origin The sender's origin.
 */
export const postFromWooPay = ( data, origin ) => {
	const isConnectAnswer = /^(get|set)_/.test( data?.action );
	const iframe = document.querySelector(
		isConnectAnswer ? '#woopay-connect-iframe' : '.woopay-otp-iframe'
	);

	dispatchFromWindow( getIframeWindow( iframe ), data, origin );
};
