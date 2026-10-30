/**
 * Internal dependencies
 */
import {
	claimWooPayRedirect,
	deleteSkipWooPayCookie,
	dispatchUserExistEvent,
	getWooPayHostOrigin,
	initWooPay,
	sendSessionToOtpIframe,
	shouldSkipWooPay,
	validateEmail,
} from './helpers';

/*
 * The WooPay email check on both checkouts (client 11.2.0 checkout/woopay/email-input-iframe.js). Looks the typed
 * email up at WooPay and, for a registered shopper, opens WooPay's one-time-code iframe anchored to the field; a
 * verified code hands the platform session back through postMessage and the shopper goes to WooPay.
 */

const WAIT_TIME = 500;
const FULL_SCREEN_MODAL_BREAKPOINT = 768;

/**
 * Wire the WooPay email check to the checkout email field.
 *
 * @param {Object}   args                                  The calling script's values; the WooPayRequestArgs plus:
 * @param {string}   args.woopayHost                       The WooPay host URL.
 * @param {string}   args.woopayMerchantId                 The store's WooPay blog ID.
 * @param {string}   args.woopaySignatureNonce             Nonce of the get_woopay_signature request.
 * @param {string}   args.wcpayVersionNumber               Version WooPay is told.
 * @param {boolean}  args.testMode                         Whether the account is in test mode.
 * @param {boolean}  args.isWoopayFirstPartyAuthEnabled    Whether the store posts its WooPay session to the iframe.
 * @param {boolean}  args.woopayIsCountryAvailable         Whether WooPay serves the shopper's country.
 * @param {boolean}  args.isBlocksCheckout                 Whether the page is the Blocks checkout.
 * @param {string}   args.otpIframeTitle                   Title of the OTP iframe.
 * @param {string}   args.otpCloseLabel                    Label of the dialog's Close button.
 * @param {string}   args.unavailableMessage               Notice shown when WooPay cannot start.
 * @param {Function} args.recordEvent                      Records a shopper Tracks event `( name, properties )`.
 * @param {Function} args.navigate                         Sends the page to a URL.
 * @param {Function} args.onPlaceOrder                     Calls back when the shopper places the order.
 * @param {Function} args.isSaveMyInfoPreChecked           Whether the save-my-info box starts checked.
 * @param {Function} [args.getNoticeNode]                  Returns the node the notice goes in, given the field's parent.
 * @param {boolean}  [args.refocusFieldWhenDialogHadFocus] Whether closing the dialog after a failure also gives focus
 *                                                         back to the field when focus was in the dialog.
 * @param {Element}  woopayEmailInput                      The checkout email field.
 * @param {string}   [tracksUserId]                        The shopper's Tracks identity, for the OTP iframe.
 */
export const handleEmailInput = ( args, woopayEmailInput, tracksUserId ) => {
	let timer;
	const woopayHost = args.woopayHost || '';
	// Fail closed: no resolvable WooPay origin, no trusted sender.
	const isWooPayMessage = ( event ) => {
		const woopayOrigin = getWooPayHostOrigin( args );
		return !! woopayOrigin && event.origin === woopayOrigin;
	};

	const spinner = document.createElement( 'div' );
	const parentDiv = woopayEmailInput.parentNode;
	spinner.classList.add( 'wc-block-components-spinner' );

	// The OTP iframe wrapper (the dimmed backdrop on wide viewports).
	const iframeWrapper = document.createElement( 'div' );
	iframeWrapper.setAttribute( 'role', 'dialog' );
	iframeWrapper.setAttribute( 'aria-modal', 'true' );
	iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

	const iframe = document.createElement( 'iframe' );
	iframe.title = args.otpIframeTitle || '';
	iframe.classList.add( 'woopay-otp-iframe' );
	// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
	iframe.classList.add( 'intrinsic-ignore' );

	// The dialog is named after the iframe it holds, as the express OTP dialog is; client 11.1.0 names neither.
	if ( iframe.title ) {
		iframeWrapper.setAttribute( 'aria-label', iframe.title );
	}

	const iframeArrow = document.createElement( 'span' );
	iframeArrow.setAttribute( 'aria-hidden', 'true' );
	iframeArrow.classList.add( 'arrow' );

	// A back-button return from WooPay (or ?skip_woopay=true) must not bounce the shopper straight back; the cookie
	// extends the skip to the whole session.
	const searchParams = new URLSearchParams( window.location.search );
	const isSkipWoopayCookieSet = shouldSkipWooPay();
	const customerClickedBackButton =
		( typeof performance !== 'undefined' &&
			performance.getEntriesByType?.( 'navigation' )?.[ 0 ]?.type ===
				'back_forward' ) ||
		searchParams.get( 'skip_woopay' ) === 'true' ||
		isSkipWoopayCookieSet;

	if ( customerClickedBackButton && ! isSkipWoopayCookieSet ) {
		const followingDay = new Date( Date.now() + 24 * 60 * 60 * 1000 );
		document.cookie = `skip_woopay=1; path=/; expires=${ followingDay.toUTCString() }`;
	}

	// Client 11.1.0's name (email-input-iframe.js:85): it switches the iframe header for the viewport, tells WooPay,
	// and keeps page scrolling locked while the iframe is open. The header default must match the platform's.
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

	// Positions the popover to the right of the input unless the window is too narrow, in which case it sticks 50px
	// from the right edge.
	const setPopoverPosition = () => {
		if ( FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth ) {
			iframe.style.left = '0';
			iframe.style.right = '';
			return;
		}

		// Scroll the iframe into view when it is off the top or bottom.
		if (
			iframe.getBoundingClientRect().top <= 0 ||
			window.innerHeight -
				( iframe.getBoundingClientRect().height +
					iframe.getBoundingClientRect().top ) <=
				0
		) {
			const topOffset = 50;
			const scrollTop =
				document.documentElement.scrollTop +
				woopayEmailInput.getBoundingClientRect().top -
				iframe.getBoundingClientRect().height / 2 -
				topOffset;
			window.scrollTo( { top: scrollTop } );
		}

		const anchorRect = woopayEmailInput.getBoundingClientRect();
		const iframeRect = iframe.getBoundingClientRect();

		iframe.style.top =
			Math.floor( anchorRect.top - iframeRect.height / 2 ) + 'px';
		iframeArrow.style.top =
			Math.floor(
				anchorRect.top +
					anchorRect.height / 2 -
					parseFloat(
						window.getComputedStyle( iframeArrow )[
							'border-right-width'
						]
					)
			) + 'px';

		if (
			window.innerWidth - ( anchorRect.right + iframeRect.width ) <=
			50
		) {
			iframe.style.left = 'auto';
			iframeArrow.style.left = 'auto';
			iframe.style.right = '50px';
			iframeArrow.style.right = `${ iframeRect.width + 50 }px`;
		} else {
			iframe.style.left = `${ anchorRect.right + 5 }px`;
			iframe.style.right = '';
			iframeArrow.style.left = `${ anchorRect.right - 10 }px`;
			iframeArrow.style.right = '';
		}
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

	iframeWrapper.insertBefore( iframeArrow, null );
	iframeWrapper.insertBefore( iframe, null );

	// The dialog's own way out, as on the express OTP dialog: WooPay's iframe draws a close control and turns Escape
	// into close_modal, but neither exists when the iframe never loads. After the iframe, so Tab past the iframe's last
	// control reaches it; the stylesheet shows it only while it has focus. Its click reaches the wrapper's click
	// handler, which closes the dialog.
	const closeButton = document.createElement( 'button' );
	closeButton.type = 'button';
	closeButton.classList.add( 'woopay-otp-iframe-close' );
	closeButton.textContent = args.otpCloseLabel || '';
	iframeWrapper.insertBefore( closeButton, null );

	// A modal dialog keeps focus: Tab past the Close button, or Shift+Tab before the iframe's first control, lands on the
	// page behind it and comes back to the iframe.
	const onFocusIn = ( event ) => {
		if ( ! iframeWrapper.contains( event.target ) ) {
			iframe.focus();
		}
	};

	const errorMessage = document.createElement( 'div' );
	errorMessage.textContent = args.unavailableMessage || '';
	errorMessage.classList.add( 'wc-block-checkout__guest-checkout-notice' );
	// Announced when it appears; client 11.1.0 inserts a plain div here.
	errorMessage.setAttribute( 'role', 'alert' );

	// The closes that ask for it give focus back to the email field. With refocusFieldWhenDialogHadFocus, the failure
	// closes also give it back when focus was in the dialog, so the page does not lose it.
	const closeIframe = ( focus = true ) => {
		const dialogHadFocus = iframeWrapper.contains(
			iframeWrapper.ownerDocument.activeElement
		);

		window.removeEventListener( 'resize', getWindowSize );
		window.removeEventListener( 'resize', setPopoverPosition );
		document.removeEventListener( 'focusin', onFocusIn );

		iframeWrapper.remove();
		iframe.classList.remove( 'open' );

		if (
			focus ||
			( args.refocusFieldWhenDialogHadFocus && dialogHadFocus )
		) {
			woopayEmailInput.focus();
		}

		document.body.style.overflow = '';
	};

	iframeWrapper.addEventListener( 'click', () => closeIframe() );

	const openIframe = ( email ) => {
		// Only one OTP iframe at a time.
		if ( document.querySelector( '.woopay-otp-iframe' ) ) {
			return;
		}

		document.addEventListener( 'focusin', onFocusIn );

		const viewportWidth = window.document.documentElement.clientWidth;
		const viewportHeight = window.document.documentElement.clientHeight;

		const urlParams = new URLSearchParams();
		urlParams.append( 'email', email );
		urlParams.append( 'testMode', !! args.testMode );
		urlParams.append(
			'needsHeader',
			FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth
		);
		urlParams.append( 'wcpayVersion', args.wcpayVersionNumber );
		urlParams.append(
			'is_blocks',
			args.isBlocksCheckout ? 'true' : 'false'
		);
		urlParams.append(
			'source_url',
			window.wcSettings?.storePages?.checkout?.permalink ||
				window.location.href
		);
		urlParams.append(
			'viewport',
			`${ viewportWidth }x${ viewportHeight }`
		);

		if ( tracksUserId ) {
			urlParams.append( 'tracksUserIdentity', tracksUserId );
		}

		iframe.src = `${ woopayHost }/otp/?${ urlParams.toString() }`;

		parentDiv.insertBefore( iframeWrapper, null );

		setPopoverPosition();

		iframe.focus();
	};

	const getNoticeNode = () =>
		args.getNoticeNode ? args.getNoticeNode( parentDiv ) : parentDiv;

	const showErrorMessage = () => {
		getNoticeNode().insertBefore( errorMessage, null );
	};

	// Only while the OTP iframe is open: an Escape elsewhere on the page (closing a select or a suggestion list) must
	// not pull focus to the email field. Client 11.1.0 closes on every Escape (email-input-iframe.js:300-304).
	document.addEventListener( 'keyup', ( event ) => {
		if ( event.key === 'Escape' && iframeWrapper.isConnected ) {
			closeIframe();
		}
	} );

	// Placing the order before the lookup returns cancels the WooPay request and closes the iframe.
	const abortController = window.AbortController
		? new window.AbortController()
		: null;

	if ( abortController ) {
		abortController.signal.addEventListener( 'abort', () => {
			spinner.remove();
			closeIframe( false );
		} );

		args.onPlaceOrder( () => {
			abortController.abort();
		} );
	}

	const woopayLocateUser = ( email, shouldOpenIframe = true ) => {
		parentDiv.insertBefore( spinner, woopayEmailInput );

		const node = getNoticeNode();
		if ( node.contains( errorMessage ) ) {
			node.removeChild( errorMessage );
		}

		args.recordEvent( 'checkout_email_address_woopay_check' );

		args.post( 'get_woopay_signature', {
			_ajax_nonce: args.woopaySignatureNonce || '',
		} )
			.then( ( response ) => {
				if ( response?.success ) {
					return response.data;
				}

				throw new Error( 'Request for signature failed.' );
			} )
			.then( ( data ) => {
				if ( data?.signature ) {
					return data.signature;
				}

				throw new Error( 'Signature not found.' );
			} )
			.then( ( signature ) => {
				const emailExistsQuery = new URLSearchParams();
				emailExistsQuery.append( 'email', email );
				emailExistsQuery.append( 'test_mode', !! args.testMode );
				emailExistsQuery.append(
					'wcpay_version',
					args.wcpayVersionNumber
				);
				emailExistsQuery.append( 'blog_id', args.woopayMerchantId );
				emailExistsQuery.append( 'request_signature', signature );

				return window.fetch(
					`${ woopayHost }/wp-json/platform-checkout/v1/user/exists?${ emailExistsQuery.toString() }`,
					abortController ? { signal: abortController.signal } : {}
				);
			} )
			.then( ( response ) => {
				if ( response.status !== 200 ) {
					showErrorMessage();
				}

				return response.json();
			} )
			.then( ( data ) => {
				dispatchUserExistEvent( data[ 'user-exists' ] );

				if ( data[ 'user-exists' ] ) {
					if ( shouldOpenIframe ) {
						openIframe( email );
					}
				} else if ( data.code !== 'rest_invalid_param' ) {
					args.recordEvent( 'checkout_woopay_save_my_info_offered' );

					if ( args.isSaveMyInfoPreChecked() ) {
						args.recordEvent( 'checkout_save_my_info_click', {
							status: 'checked',
						} );
					}
				}
			} )
			.catch( ( err ) => {
				// Only surface connection errors reaching WooPay.
				if (
					! args.woopayIsCountryAvailable ||
					err.name !== 'TypeError'
				) {
					return;
				}

				showErrorMessage();
			} )
			.finally( () => {
				spinner.remove();
			} );
	};

	woopayEmailInput.addEventListener( 'input', ( e ) => {
		const email = e.currentTarget.value;

		clearTimeout( timer );
		spinner.remove();

		timer = setTimeout( () => {
			// Always show the checkbox until the email belongs to a WooPay user.
			dispatchUserExistEvent( false );

			if ( validateEmail( email ) ) {
				woopayLocateUser( email );
			}
		}, WAIT_TIME );
	} );

	window.addEventListener( 'message', ( e ) => {
		if ( ! isWooPayMessage( e ) ) {
			return;
		}
		switch ( e.data.action ) {
			case 'redirect_to_woopay_skip_session_init':
				if ( e.data.redirectUrl ) {
					deleteSkipWooPayCookie();
					args.navigate( e.data.redirectUrl );
				}
				break;
			case 'redirect_to_platform_checkout':
			case 'redirect_to_woopay': {
				if ( ! claimWooPayRedirect( e ) ) {
					break;
				}
				const promise = initWooPay(
					args,
					woopayEmailInput.value,
					e.data.platformCheckoutUserSession
				);

				// WooPay's <Login> re-renders and sends the message twice; the second init is skipped.
				if ( ! promise ) {
					break;
				}

				promise
					.then( ( response ) => {
						// The iframe was closed meanwhile.
						if (
							! document.querySelector( '.woopay-otp-iframe' )
						) {
							return;
						}
						if ( response?.result === 'success' ) {
							deleteSkipWooPayCookie();
							args.navigate( response.url );
						} else {
							showErrorMessage();
							closeIframe( false );
						}
					} )
					.catch( () => {
						showErrorMessage();
						closeIframe( false );
					} );
				break;
			}
			case 'otp_validation_failed':
				break;
			case 'close_modal':
				closeIframe();
				break;
			case 'iframe_height':
				if ( e.data.height > 300 ) {
					if ( FULL_SCREEN_MODAL_BREAKPOINT <= window.innerWidth ) {
						// Attach the iframe to the right of the input.
						iframe.style.height = e.data.height + 'px';

						const inputRect =
							woopayEmailInput.getBoundingClientRect();

						iframe.style.top =
							Math.floor( inputRect.top - e.data.height / 2 ) +
							'px';
						iframeArrow.style.top =
							Math.floor(
								inputRect.top +
									inputRect.height / 2 -
									parseFloat(
										window.getComputedStyle( iframeArrow )[
											'border-right-width'
										]
									)
							) + 'px';
					} else {
						iframe.style.height = '';
						iframe.style.top = '';
					}
				}
				break;
			default:
			// Only respond to expected actions.
		}
	} );

	window.addEventListener( 'pageshow', ( event ) => {
		if ( event.persisted ) {
			// Safari needs the iframe closed on bfcache restore.
			closeIframe( false );
		}
	} );

	if ( woopayEmailInput.value && validateEmail( woopayEmailInput.value ) ) {
		woopayLocateUser( woopayEmailInput.value, false );
	}

	if ( customerClickedBackButton ) {
		// The shopper returned via the back button: they exist. Wait for the window to settle before announcing it.
		setTimeout( () => {
			dispatchUserExistEvent( true );
		}, 2000 );

		args.recordEvent( 'woopay_skipped', {} );

		searchParams.delete( 'skip_woopay' );

		let { pathname } = window.location;

		if ( searchParams.toString() !== '' ) {
			pathname += '?' + searchParams.toString();
		}

		window.history.replaceState( null, null, pathname );

		// Safari needs the iframe closed here too.
		closeIframe( false );
	}
};
