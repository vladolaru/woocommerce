/**
 * Internal dependencies
 */
import { getIdentity } from '../../common/tracks';
import { getWooPayHostOrigin } from './helpers';

/*
 * WooPay Connect: a hidden iframe of WooPay's connect page that answers postMessage requests (client 11.2.0
 * checkout/woopay/connect/). The iframe and the wait for its page live on the iframe element, so every script on the
 * page posts through the same one.
 */

const PREFERRED_CARD_CACHE_KEY = 'woopay_preferred_card';
const CONNECT_TIMEOUT = 5000;

export const isValidPreferredCard = ( card ) =>
	Boolean(
		card &&
		typeof card.brand === 'string' &&
		card.brand.length > 0 &&
		typeof card.last4 === 'string' &&
		/^\d{4}$/.test( card.last4 )
	);

export const isSamePreferredCard = ( firstCard, secondCard ) =>
	( ! firstCard && ! secondCard ) ||
	( firstCard &&
		secondCard &&
		firstCard.brand === secondCard.brand &&
		firstCard.last4 === secondCard.last4 );

export const getCachedPreferredCard = () => {
	try {
		const cached = window.localStorage.getItem( PREFERRED_CARD_CACHE_KEY );
		if ( ! cached ) {
			return null;
		}

		const parsed = JSON.parse( cached );
		return isValidPreferredCard( parsed ) ? parsed : null;
	} catch ( error ) {
		return null;
	}
};

export const setCachedPreferredCard = ( card ) => {
	try {
		if ( isValidPreferredCard( card ) ) {
			window.localStorage.setItem(
				PREFERRED_CARD_CACHE_KEY,
				JSON.stringify( {
					brand: card.brand,
					last4: card.last4,
				} )
			);
			return;
		}

		window.localStorage.removeItem( PREFERRED_CARD_CACHE_KEY );
	} catch ( error ) {
		return undefined;
	}
};

/**
 * Create a script's WooPay Connect client: its own message listener, pending requests and preferred-card request, over
 * the page's one connect iframe.
 *
 * @param {Object}  args                      The calling script's values.
 * @param {string}  args.woopayHost           The WooPay host URL.
 * @param {string}  args.woopayMerchantId     The store's WooPay blog ID.
 * @param {boolean} args.testMode             Whether the account is in test mode; left out of the URL when undefined.
 * @param {string}  args.ajaxUrl              admin-ajax URL, for the Tracks identity.
 * @param {string}  args.platformTrackerNonce Nonce of the Tracks identity request.
 * @param {string}  args.iframeTitle          Title of the connect iframe, when this script creates it.
 * @return {Object} `sendMessage`, `sendPreemptiveSessionData` and `fetchPreferredCard`.
 */
export const createConnect = ( args ) => {
	const callbacks = {};
	let isListenerAttached = false;
	let preferredCardFetchPromise = null;

	// Resolves once the Tracks identity does: hosted WooPay's sessions route rejects the first-party session when the
	// connect page has no identity to post (client 11.2.0 woopay/connect/woopay-connect-iframe.js:31-34).
	const getConnectUrl = () => {
		if ( ! args.woopayHost || ! window.URLSearchParams ) {
			return Promise.resolve(
				args.woopayHost ? `${ args.woopayHost }/connect/` : ''
			);
		}

		const params = new window.URLSearchParams( {
			source_url: window.location.href,
		} );

		if ( args.woopayMerchantId ) {
			params.append( 'blogId', args.woopayMerchantId );
		}

		if ( args.testMode !== undefined ) {
			params.append( 'testMode', args.testMode ? 'true' : 'false' );
		}

		return getIdentity( {
			ajaxUrl: args.ajaxUrl,
			platformTrackerNonce: args.platformTrackerNonce,
		} ).then( ( tracksUserIdentity ) => {
			if ( tracksUserIdentity ) {
				params.append( 'tracksUserIdentity', tracksUserIdentity );
			}

			return `${ args.woopayHost }/connect/?${ params.toString() }`;
		} );
	};

	const resolveCallback = ( callbackName, value ) => {
		if ( ! callbacks[ callbackName ] ) {
			return;
		}

		callbacks[ callbackName ]( value );
		delete callbacks[ callbackName ];
	};

	const attachListener = () => {
		if ( isListenerAttached ) {
			return;
		}

		window.addEventListener( 'message', ( event ) => {
			const data = event.data || {};
			const origin = getWooPayHostOrigin( args );

			if ( ! origin || event.origin !== origin ) {
				return;
			}

			switch ( data.action ) {
				case 'set_preemptive_session_data_success':
					resolveCallback(
						'setPreemptiveSessionData',
						data.value || {}
					);
					break;
				case 'set_preemptive_session_data_error':
					resolveCallback( 'setPreemptiveSessionData', {
						is_error: true,
					} );
					break;
				case 'get_preferred_payment_method_success':
					resolveCallback(
						'getPreferredPaymentMethod',
						data.value || null
					);
					break;
				case 'get_is_user_logged_in_success':
					resolveCallback( 'getIsUserLoggedIn', !! data.value );
					break;
				case 'get_is_woopay_reachable_success':
					resolveCallback( 'getIsWooPayReachable', !! data.value );
					break;
				case 'get_encrypted_data_success':
					resolveCallback( 'getEncryptedData', data.value || null );
					break;
				case 'set_redirect_session_data_success':
					resolveCallback(
						'setRedirectSessionData',
						data.value || null
					);
					break;
				case 'set_redirect_session_data_error':
					resolveCallback( 'setRedirectSessionData', null );
					break;
			}
		} );

		isListenerAttached = true;
	};

	// The connect iframe carries the wait for its connect page, so every script on the page waits for the same load;
	// client 11.2.0 shares that state in window.WooPayConnect (connect/connect-utils.js).
	const getPostMessage = () => {
		const existingIframe = document.getElementById(
			'woopay-connect-iframe'
		);

		if ( existingIframe ) {
			return (
				existingIframe.wooPayConnectPostMessage ||
				Promise.resolve( ( message ) => {
					existingIframe.contentWindow.postMessage(
						message,
						args.woopayHost
					);
				} )
			);
		}

		preferredCardFetchPromise = null;

		if ( ! args.woopayHost ) {
			return Promise.reject();
		}

		let iframe;
		const connectPostMessage = new Promise( ( resolve ) => {
			iframe = document.createElement( 'iframe' );
			iframe.id = 'woopay-connect-iframe';
			iframe.height = 0;
			iframe.width = 0;
			iframe.title = args.iframeTitle || '';
			iframe.style.border = 'none';
			iframe.style.display = 'block';
			iframe.style.visibility = 'hidden';
			iframe.style.position = 'fixed';
			iframe.style.height = '0';
			iframe.style.width = '0';
			iframe.style.pointerEvents = 'none';
			iframe.addEventListener( 'load', () => {
				// Appending an iframe without a src loads about:blank first; only the connect page's load counts.
				if ( ! iframe.getAttribute( 'src' ) ) {
					return;
				}

				resolve( ( message ) => {
					iframe.contentWindow.postMessage(
						message,
						args.woopayHost
					);
				} );
			} );

			// The iframe is in the page at once, so later calls reuse it while the Tracks identity resolves.
			document.body.appendChild( iframe );
			getConnectUrl().then( ( url ) => {
				iframe.src = url;
			} );
		} );
		iframe.wooPayConnectPostMessage = connectPostMessage;

		return connectPostMessage;
	};

	/**
	 * Post a request to WooPay Connect and resolve its answer.
	 *
	 * Each request owns its timer and settled state, as client 11.2.0 sendMessageAndListenWith() owns its timer and
	 * isRejected flag (connect/woopay-connect.js:170-195): the newest request under a name takes the callback, a deadline
	 * settles only its own request, and a value reaching a settled request is ignored. Unlike the client, an expired
	 * request also never posts.
	 *
	 * @param {Object}  message       The message for the connect page.
	 * @param {string}  callbackName  The answer it waits for.
	 * @param {*}       fallback      What resolves when the iframe cannot load or the deadline passes.
	 * @param {boolean} shouldTimeOut Whether the request has a deadline.
	 * @return {Promise<*>} The answer, or the fallback.
	 */
	const sendMessage = ( message, callbackName, fallback, shouldTimeOut ) => {
		attachListener();

		return new Promise( ( resolve ) => {
			let timeoutId;
			let isSettled = false;
			const settle = ( value ) => {
				if ( isSettled ) {
					return;
				}

				isSettled = true;
				window.clearTimeout( timeoutId );
				if ( callbacks[ callbackName ] === settle ) {
					delete callbacks[ callbackName ];
				}
				resolve( value );
			};

			callbacks[ callbackName ] = settle;

			if ( shouldTimeOut ) {
				timeoutId = window.setTimeout(
					() => settle( fallback ),
					CONNECT_TIMEOUT
				);
			}

			getPostMessage()
				.then( ( postMessage ) => {
					if ( ! isSettled ) {
						postMessage( message );
					}
				} )
				.catch( () => {
					settle( fallback );
				} );
		} );
	};

	// Client 11.2.0 session-connect.js:152-161 resolves null when Connect does not answer.
	const sendPreemptiveSessionData = ( sessionData ) =>
		sendMessage(
			{
				action: 'setPreemptiveSessionData',
				value: sessionData,
			},
			'setPreemptiveSessionData',
			null,
			true
		);

	// One preferred-card request per connect iframe; a valid card or null.
	const fetchPreferredCard = () => {
		if (
			preferredCardFetchPromise &&
			! document.getElementById( 'woopay-connect-iframe' )
		) {
			preferredCardFetchPromise = null;
		}

		if ( preferredCardFetchPromise ) {
			return preferredCardFetchPromise;
		}

		preferredCardFetchPromise = sendMessage(
			{
				action: 'getPreferredPaymentMethod',
			},
			'getPreferredPaymentMethod',
			null,
			true
		).then( ( card ) => ( isValidPreferredCard( card ) ? card : null ) );

		return preferredCardFetchPromise;
	};

	return { sendMessage, sendPreemptiveSessionData, fetchPreferredCard };
};
