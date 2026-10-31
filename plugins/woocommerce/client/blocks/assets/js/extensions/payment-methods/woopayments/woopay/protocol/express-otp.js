/**
 * Internal dependencies
 */
import {
	claimWooPayRedirect,
	getWooPayHostOrigin,
	initWooPay,
	sendSessionToOtpIframe,
	validateEmail,
} from './helpers';

/*
 * The WooPay button's OTP flow on both checkouts (client 11.2.0 checkout/woopay/express-button/express-checkout-iframe.js).
 * Opens WooPay's one-time-code iframe as a centered modal; the verified code hands the platform session back through
 * postMessage and init_woopay starts WooPay.
 */

const FULL_SCREEN_MODAL_BREAKPOINT = 768;

/**
 * Open the WooPay OTP iframe for the WooPay button.
 *
 * @param {Object}   args                               The calling script's values; the WooPayRequestArgs plus:
 * @param {string}   args.woopayHost                    The WooPay host URL.
 * @param {string}   args.wcpayVersionNumber            Version WooPay is told.
 * @param {boolean}  args.testMode                      Whether the account is in test mode.
 * @param {boolean}  args.isWoopayFirstPartyAuthEnabled Whether the store posts its WooPay session to the iframe.
 * @param {string}   args.otpIframeTitle                Title of the OTP iframe.
 * @param {string}   args.otpCloseLabel                 Label of the dialog's Close button.
 * @param {string}   args.unavailableMessage            Error shown when WooPay cannot start.
 * @param {Function} args.showError                     Shows an error message to the shopper.
 * @param {Function} args.navigate                      Sends the page to a URL.
 * @param {Function} [args.getRedirectUrl]              Returns the URL to send the shopper to for a WooPay URL.
 * @param {Function} [args.getFocusTarget]              Returns the control that gets focus back on close, given the one
 *                                                      that had it when the dialog opened.
 * @param {string}   context                            The button context (checkout, cart, product, pay_for_order).
 * @param {string}   [email]                            The email to prefill.
 * @param {string}   [tracksUserId]                     The shopper's Tracks identity, for the OTP iframe.
 */
export const openExpressOtpIframe = ( args, context, email, tracksUserId ) => {
	const woopayHost = args.woopayHost || '';
	const getRedirectUrl = ( url ) =>
		args.getRedirectUrl ? args.getRedirectUrl( url ) : url;
	let userEmail = '';

	const iframeWrapper = document.createElement( 'div' );
	iframeWrapper.setAttribute( 'role', 'dialog' );
	iframeWrapper.setAttribute( 'aria-modal', 'true' );
	iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

	const iframe = document.createElement( 'iframe' );
	iframe.title = args.otpIframeTitle || '';
	iframe.classList.add( 'woopay-otp-iframe' );
	// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
	iframe.classList.add( 'intrinsic-ignore' );

	// The dialog is named after the iframe it holds; client 11.1.0 names only the iframe.
	if ( iframe.title ) {
		iframeWrapper.setAttribute( 'aria-label', iframe.title );
	}

	// Where focus was when the dialog opened; closing gives it back, as the email check gives it back to the email
	// field, unless getFocusTarget names another control.
	let previouslyFocused = null;

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

		sendSessionToOtpIframe( args, iframe );

		getWindowSize();
		window.addEventListener( 'resize', getWindowSize );

		setPopoverPosition();
		window.addEventListener( 'resize', setPopoverPosition );

		iframe.classList.add( 'open' );
	} );

	iframeWrapper.insertBefore( iframe, null );

	// The dialog's own way out. WooPay's iframe draws a close control and turns Escape into close_modal, but neither
	// exists when the iframe never loads, and a key pressed inside the iframe never reaches this page. After the iframe,
	// so Tab past the iframe's last control reaches it; the stylesheet shows it only while it has focus.
	const closeButton = document.createElement( 'button' );
	closeButton.type = 'button';
	closeButton.classList.add( 'woopay-otp-iframe-close' );
	closeButton.textContent = args.otpCloseLabel || '';
	iframeWrapper.insertBefore( closeButton, null );

	const closeIframe = () => {
		window.removeEventListener( 'resize', getWindowSize );
		window.removeEventListener( 'resize', setPopoverPosition );
		window.removeEventListener( 'pageshow', onPageShow );
		window.removeEventListener( 'message', onMessage );
		document.removeEventListener( 'keyup', onKeyUp );
		document.removeEventListener( 'focusin', onFocusIn );

		iframeWrapper.remove();
		iframe.classList.remove( 'open' );

		document.body.style.overflow = '';

		// Only the close that ends an open dialog gives focus back. The target is read now, not at open: the page can
		// render the WooPay button again while the dialog is open.
		if ( previouslyFocused ) {
			const target = args.getFocusTarget
				? args.getFocusTarget( previouslyFocused )
				: previouslyFocused;
			target?.focus?.();
		}
		previouslyFocused = null;
	};

	// A click on the backdrop or on the close button closes the dialog.
	iframeWrapper.addEventListener( 'click', closeIframe );

	const showUnavailable = () => {
		args.showError( args.unavailableMessage || '' );
		closeIframe();
	};

	const onMessage = ( e ) => {
		const woopayOrigin = getWooPayHostOrigin( args );
		if ( ! woopayOrigin || e.origin !== woopayOrigin ) {
			return;
		}

		switch ( e.data?.action ) {
			case 'otp_email_submitted':
				userEmail = e.data.userEmail;
				break;
			case 'redirect_to_woopay_skip_session_init':
				if ( e.data.redirectUrl ) {
					args.navigate( getRedirectUrl( e.data.redirectUrl ) );
				}
				break;
			case 'redirect_to_platform_checkout':
			case 'redirect_to_woopay': {
				if ( ! claimWooPayRedirect( e ) ) {
					break;
				}
				const promise = initWooPay(
					args,
					userEmail || e.data.userEmail,
					e.data.platformCheckoutUserSession
				);

				// WooPay's <Login> re-renders and sends the message twice; the second init is skipped.
				if ( ! promise ) {
					break;
				}

				promise
					.then( ( response ) => {
						// This request's dialog was closed meanwhile, whatever dialog is open now.
						if ( ! iframe.isConnected ) {
							return;
						}
						if ( response?.result === 'success' ) {
							args.navigate( getRedirectUrl( response.url ) );
						} else {
							showUnavailable();
						}
					} )
					.catch( () => {
						// Client 11.2.0 has no catch here, which leaves an open dialog stuck; a shopper still in the dialog gets
						// the notice and the way out.
						if ( ! iframe.isConnected ) {
							return;
						}
						showUnavailable();
					} );
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

	// A modal dialog keeps focus: Tab past the close button, or Shift+Tab before the iframe's first control, lands on
	// the page behind it and comes back to the iframe.
	const onFocusIn = ( event ) => {
		if ( ! iframeWrapper.contains( event.target ) ) {
			iframe.focus();
		}
	};

	// Only one OTP iframe at a time.
	if ( document.querySelector( '.woopay-otp-iframe' ) ) {
		return;
	}

	previouslyFocused = iframe.ownerDocument.activeElement;
	window.addEventListener( 'pageshow', onPageShow );
	window.addEventListener( 'message', onMessage );
	document.addEventListener( 'keyup', onKeyUp );
	document.addEventListener( 'focusin', onFocusIn );

	const viewportWidth = window.document.documentElement.clientWidth;
	const viewportHeight = window.document.documentElement.clientHeight;

	const urlParams = new URLSearchParams();
	urlParams.append( 'testMode', !! args.testMode );
	urlParams.append(
		'needsHeader',
		FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth
	);
	urlParams.append( 'wcpayVersion', args.wcpayVersionNumber );

	if ( email && validateEmail( email ) ) {
		userEmail = email;
		urlParams.append( 'email', email );
	}
	urlParams.append( 'is_blocks', !! window.wcSettings?.wcBlocksConfig );
	urlParams.append( 'is_express', 'true' );
	urlParams.append( 'express_context', context );
	urlParams.append( 'source_url', window.location.href );
	urlParams.append( 'viewport', `${ viewportWidth }x${ viewportHeight }` );

	if ( tracksUserId ) {
		urlParams.append( 'tracksUserIdentity', tracksUserId );
	}

	iframe.src = `${ woopayHost }/otp/?${ urlParams.toString() }`;

	document.body.insertBefore( iframeWrapper, null );

	setPopoverPosition();

	iframe.focus();
};
