/**
 * External dependencies
 */
import { dispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { getTracksIdentity } from '../tracks';
import {
	getTargetElement,
	getWooPayAppearance,
	initWooPay,
	postWooPayAjax,
	validateEmail,
} from './email-input-iframe';

/*
 * WooPay express-button OTP flow: port of the WooPayments plugin's
 * checkout/woopay/express-button/express-checkout-iframe.js. Opens WooPay's
 * one-time-code iframe as a centered modal; the verified code hands the
 * platform session back through postMessage and init_woopay starts WooPay.
 */

const FULL_SCREEN_MODAL_BREAKPOINT = 768;

/**
 * Show an express-button error the way the plugin does: a Blocks notice on
 * the cart and checkout blocks, a server-rendered WooCommerce notice elsewhere.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} context         The button context.
 * @param {string} message         The error message.
 */
const showErrorMessage = ( paymentSettings, context, message ) => {
	if ( window.wcSettings?.wcBlocksConfig && context !== 'product' ) {
		dispatch( 'core/notices' )?.createNotice( 'error', message, {
			context: `wc/${ context }`,
		} );
		return;
	}

	window
		.fetch( paymentSettings.ajaxUrl, {
			method: 'POST',
			body: new URLSearchParams( {
				action: 'woopay_express_checkout_button_show_error_notice',
				_ajax_nonce: paymentSettings.woopayButtonNonce || '',
				context,
				message,
			} ),
		} )
		.then( ( response ) => response.json() )
		.then( ( response ) => {
			const noticesWrapper = document.querySelector(
				'.woocommerce-notices-wrapper'
			);
			if ( ! response?.success || ! noticesWrapper ) {
				return;
			}

			const wrapper = document.createElement( 'div' );
			wrapper.innerHTML = response.data.notice;
			noticesWrapper.insertBefore( wrapper, null );
			noticesWrapper.scrollIntoView( {
				behavior: 'smooth',
				block: 'center',
			} );
		} )
		.catch( () => {} );
};

/**
 * Open the WooPay OTP iframe for the express button.
 *
 * @param {Object}   paymentSettings Payment method settings.
 * @param {string}   context         The button context (checkout, cart, product).
 * @param {string}   emailSelector   Selector of the email field to prefill from.
 * @param {Function} navigate        Navigates the page to a URL.
 */
export const expressCheckoutIframe = async (
	paymentSettings,
	context,
	emailSelector,
	navigate
) => {
	const tracksUserId = await getTracksIdentity( paymentSettings );
	const woopayHost = paymentSettings.woopayHost || '';
	let userEmail = '';

	const iframeWrapper = document.createElement( 'div' );
	iframeWrapper.setAttribute( 'role', 'dialog' );
	iframeWrapper.setAttribute( 'aria-modal', 'true' );
	iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

	const iframe = document.createElement( 'iframe' );
	iframe.title = paymentSettings.woopayOtpIframeTitle || '';
	iframe.classList.add( 'woopay-otp-iframe' );
	// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
	iframe.classList.add( 'intrinsic-ignore' );

	// Tracks the iframe header state; the default must match the platform's.
	let iframeHeaderValue = true;
	const getWindowSize = () => {
		if (
			( FULL_SCREEN_MODAL_BREAKPOINT <= window.innerWidth &&
				iframeHeaderValue ) ||
			( FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth &&
				! iframeHeaderValue )
		) {
			iframeHeaderValue = ! iframeHeaderValue;
			iframe.contentWindow.postMessage(
				{ action: 'setHeader', value: iframeHeaderValue },
				woopayHost
			);
		}

		// Prevent scrolling while the iframe is open.
		document.body.style.overflow = 'hidden';
	};

	// Center the iframe, or fill the window below the breakpoint.
	const setPopoverPosition = () => {
		if ( FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth ) {
			iframe.style.left = '0';
			iframe.style.right = '';
			iframe.style.top = '0';
			return;
		}

		const iframeRect = iframe.getBoundingClientRect();
		iframe.style.top =
			Math.floor( window.innerHeight / 2 - iframeRect.height / 2 ) + 'px';
		iframe.style.left =
			Math.floor( window.innerWidth / 2 - iframeRect.width / 2 ) + 'px';
	};

	iframe.addEventListener( 'load', () => {
		iframeHeaderValue = true;

		if ( paymentSettings.isWoopayFirstPartyAuthEnabled ) {
			postWooPayAjax( paymentSettings, 'get_woopay_session', {
				_ajax_nonce: paymentSettings.woopaySessionNonce || '',
				order_id: paymentSettings.orderId || '',
				key: paymentSettings.key || '',
				billing_email: paymentSettings.billing_email || '',
				appearance: getWooPayAppearance( paymentSettings ),
			} )
				.then( ( response ) => {
					if ( response?.data?.session ) {
						iframe.contentWindow.postMessage(
							{ action: 'setSessionData', value: response },
							woopayHost
						);
					}
				} )
				.catch( () => {} );
		}

		getWindowSize();
		window.addEventListener( 'resize', getWindowSize );

		setPopoverPosition();
		window.addEventListener( 'resize', setPopoverPosition );

		iframe.classList.add( 'open' );
	} );

	iframeWrapper.insertBefore( iframe, null );

	const closeIframe = () => {
		window.removeEventListener( 'resize', getWindowSize );
		window.removeEventListener( 'resize', setPopoverPosition );
		window.removeEventListener( 'pageshow', onPageShow );
		window.removeEventListener( 'message', onMessage );
		document.removeEventListener( 'keyup', onKeyUp );

		iframeWrapper.remove();
		iframe.classList.remove( 'open' );

		document.body.style.overflow = '';
	};

	iframeWrapper.addEventListener( 'click', closeIframe );

	const getWooPayHostOrigin = () => {
		try {
			return new URL( woopayHost ).origin;
		} catch ( error ) {
			return '';
		}
	};

	const onMessage = ( e ) => {
		const woopayOrigin = getWooPayHostOrigin();
		if ( ! woopayOrigin || e.origin !== woopayOrigin ) {
			return;
		}

		switch ( e.data?.action ) {
			case 'otp_email_submitted':
				userEmail = e.data.userEmail;
				break;
			case 'redirect_to_woopay_skip_session_init':
				if ( e.data.redirectUrl ) {
					navigate( e.data.redirectUrl );
				}
				break;
			case 'redirect_to_platform_checkout':
			case 'redirect_to_woopay': {
				const promise = initWooPay(
					paymentSettings,
					userEmail || e.data.userEmail,
					e.data.platformCheckoutUserSession
				);

				// WooPay's <Login> re-renders and sends the message twice;
				// the second init is skipped.
				if ( ! promise ) {
					break;
				}

				const showUnavailable = () => {
					showErrorMessage(
						paymentSettings,
						context,
						paymentSettings.woopayExpressUnavailableMessage || ''
					);
					closeIframe();
				};

				promise
					.then( ( response ) => {
						// The iframe was closed meanwhile.
						if (
							! document.querySelector( '.woopay-otp-iframe' )
						) {
							return;
						}
						if ( response?.result === 'success' ) {
							navigate( response.url );
						} else {
							showUnavailable();
						}
					} )
					.catch( showUnavailable );
				break;
			}
			case 'close_modal':
				closeIframe();
				break;
			case 'iframe_height':
				if ( e.data.height > 300 ) {
					if ( FULL_SCREEN_MODAL_BREAKPOINT <= window.innerWidth ) {
						iframe.style.height = e.data.height + 'px';
						iframe.style.top =
							Math.floor(
								window.innerHeight / 2 - e.data.height / 2
							) + 'px';
					} else {
						iframe.style.height = '';
						iframe.style.top = '';
					}
				}
				break;
			default:
			// Only respond to expected actions (otp_validation_failed included).
		}
	};

	const onPageShow = ( event ) => {
		if ( event.persisted ) {
			// Safari needs the iframe closed on bfcache restore.
			closeIframe();
		}
	};

	const onKeyUp = ( event ) => {
		if ( event.key === 'Escape' ) {
			closeIframe();
		}
	};

	const openIframe = ( email ) => {
		// Only one OTP iframe at a time.
		if ( document.querySelector( '.woopay-otp-iframe' ) ) {
			return;
		}

		window.addEventListener( 'pageshow', onPageShow );
		window.addEventListener( 'message', onMessage );
		document.addEventListener( 'keyup', onKeyUp );

		const viewportWidth = window.document.documentElement.clientWidth;
		const viewportHeight = window.document.documentElement.clientHeight;

		const urlParams = new URLSearchParams();
		urlParams.append( 'testMode', paymentSettings.testMode );
		urlParams.append(
			'needsHeader',
			FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth
		);
		urlParams.append( 'wcpayVersion', paymentSettings.wcpayVersionNumber );

		if ( email && validateEmail( email ) ) {
			userEmail = email;
			urlParams.append( 'email', email );
		}
		urlParams.append( 'is_blocks', !! window.wcSettings?.wcBlocksConfig );
		urlParams.append( 'is_express', 'true' );
		urlParams.append( 'express_context', context );
		urlParams.append( 'source_url', window.location.href );
		urlParams.append(
			'viewport',
			`${ viewportWidth }x${ viewportHeight }`
		);

		if ( tracksUserId ) {
			urlParams.append( 'tracksUserIdentity', tracksUserId );
		}

		iframe.src = `${ woopayHost }/otp/?${ urlParams.toString() }`;

		document.body.insertBefore( iframeWrapper, null );

		setPopoverPosition();

		iframe.focus();
	};

	const emailInput = await getTargetElement( emailSelector );

	openIframe( emailInput?.value || paymentSettings.woopaySessionEmail );
};
