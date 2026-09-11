/**
 * Internal dependencies
 */
import { recordWooPaymentsUserEvent, getTracksIdentity } from '../tracks';

/*
 * WooPay email-input / OTP flow — port of the WooPayments plugin's
 * checkout/woopay/email-input-iframe.js for the Blocks checkout. Looks the
 * typed email up at WooPay and, for a registered shopper, opens WooPay's
 * one-time-code iframe anchored to the field; a verified code hands the
 * platform session back through postMessage and redirects to WooPay.
 */

const WAIT_TIME = 500;
const FULL_SCREEN_MODAL_BREAKPOINT = 768;

// A single init_woopay request in flight per page: WooPay's <Login>
// re-renders and fires the redirect message twice, and the second call
// must return undefined.
let isInitRequesting = false;
let navigate = ( url ) => {
	window.location.href = url;
};

/**
 * Wait for the target element: the Blocks checkout renders its fields
 * after the script runs.
 *
 * @param {string} selector The element selector.
 * @return {Promise<Element|null>} The element, or null when it never appears.
 */
export const getTargetElement = ( selector ) => {
	if ( ! selector ) {
		return Promise.resolve( null );
	}

	return new Promise( ( resolve ) => {
		if ( document.querySelector( selector ) ) {
			return resolve( document.querySelector( selector ) );
		}

		const checkoutBlock = document.querySelector(
			'[data-block-name="woocommerce/checkout"]'
		);

		if ( ! checkoutBlock ) {
			return resolve( null );
		}

		const observer = new MutationObserver( ( mutationList, obs ) => {
			if ( document.querySelector( selector ) ) {
				resolve( document.querySelector( selector ) );
				obs.disconnect();
			}
		} );

		observer.observe( checkoutBlock, {
			childList: true,
			subtree: true,
		} );
	} );
};

export const validateEmail = ( value ) => {
	/* Borrowed from WooCommerce checkout.js with a slight tweak to add `{2,}` to the end and make the TLD at least 2 characters. */

	const pattern = new RegExp(
		/^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[0-9a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]){2,}\.?$/i
	);

	return pattern.test( value );
};

/**
 * Whether the shopper opted out of WooPay for this session.
 *
 * @return {boolean} True when the skip_woopay cookie is set.
 */
export const shouldSkipWooPay = () => {
	const skipWooPayCookie = document.cookie
		.split( ';' )
		.find( ( cookie ) => cookie.includes( 'skip_woopay' ) );

	if ( ! skipWooPayCookie ) {
		return false;
	}

	const [ name, value ] = skipWooPayCookie.split( '=' );

	return name.trim() === 'skip_woopay' && value.trim() === '1';
};

/**
 * Delete the skip_woopay cookie when the shopper explicitly opts back in.
 */
export const deleteSkipWooPayCookie = () => {
	if ( ! shouldSkipWooPay() ) {
		return;
	}

	document.cookie =
		'skip_woopay=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
};

const buildWooPayAjaxUrl = ( paymentSettings, endpoint ) =>
	( paymentSettings.wcAjaxUrl || '/?wc-ajax=%%endpoint%%' ).replace(
		'%%endpoint%%',
		`wcpay_${ endpoint }`
	);

const postWooPayAjax = async ( paymentSettings, endpoint, data ) => {
	const body = new window.FormData();
	Object.entries( data ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null ) {
			return;
		}
		body.append(
			key,
			typeof value === 'object' ? JSON.stringify( value ) : value
		);
	} );

	const response = await window.fetch(
		buildWooPayAjaxUrl( paymentSettings, endpoint ),
		{
			method: 'POST',
			credentials: 'same-origin',
			body,
		}
	);

	return response.json();
};

// The plugin's resolveWoopayAppearance(): the Blocks checkout only ever
// carries the server-computed appearance.
const getWooPayAppearance = ( paymentSettings ) =>
	paymentSettings.isWooPayGlobalThemeSupportEnabled
		? paymentSettings.woopayAppearance || null
		: null;

/**
 * Fire the init_woopay request (the plugin's initWooPay()).
 *
 * @param {Object} paymentSettings Payment method settings.
 * @param {string} userEmail       The shopper's email address.
 * @param {string} userSession     The WooPay user session token.
 * @return {Promise<Object>|undefined} The request, or undefined when one is already in flight.
 */
export const initWooPay = ( paymentSettings, userEmail, userSession ) => {
	if ( isInitRequesting ) {
		return undefined;
	}

	isInitRequesting = true;

	const globalTheme = paymentSettings.isWooPayGlobalThemeSupportEnabled;

	return postWooPayAjax( paymentSettings, 'init_woopay', {
		_wpnonce: paymentSettings.initWooPayNonce || '',
		appearance: globalTheme ? paymentSettings.woopayAppearance : null,
		font_rules: globalTheme ? paymentSettings.woopayFontRules : null,
		email: userEmail,
		user_session: userSession,
		order_id: paymentSettings.orderId || '',
		key: paymentSettings.key || '',
		billing_email: paymentSettings.billing_email || '',
	} ).finally( () => {
		isInitRequesting = false;
	} );
};

/**
 * The plugin's isPreviewing(): the Customizer preview iframe (which carries
 * customize_messenger_channel) or a post preview.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {boolean} True while previewing.
 */
export const isPreviewing = ( paymentSettings ) =>
	new URLSearchParams( window.location.search ).get(
		'customize_messenger_channel'
	) !== null || !! paymentSettings?.isPreview;

/**
 * Whether the checkout should wire the WooPay email-input flow — the plugin's
 * blocks entry gate: WooPay on, email input on, not previewing, checkout block present.
 *
 * @param {Object} paymentSettings Payment method settings.
 * @return {boolean} True when the flow should be wired.
 */
export const shouldHandleWooPayEmailInput = ( paymentSettings ) =>
	!! paymentSettings?.isWooPayEnabled &&
	!! paymentSettings?.isWooPayEmailInputEnabled &&
	! isPreviewing( paymentSettings ) &&
	!! document.querySelector( '[data-block-name="woocommerce/checkout"]' );

/**
 * Wire the WooPay email-input flow to the checkout email field.
 *
 * @param {string} field           The email field selector.
 * @param {Object} paymentSettings Payment method settings.
 */
export const handleWooPayEmailInput = async ( field, paymentSettings ) => {
	// Pay-for-order pages carry their own WooPay entry point.
	if ( paymentSettings.isOrderPay ) {
		return;
	}

	let timer;
	const woopayEmailInput = await getTargetElement( field );
	const tracksUserId = await getTracksIdentity( paymentSettings );

	if ( ! woopayEmailInput ) {
		return;
	}

	const woopayHost = paymentSettings.woopayHost || '';
	const getWooPayHostOrigin = () => {
		try {
			return new URL( woopayHost ).origin;
		} catch ( error ) {
			return '';
		}
	};
	// Fail closed: no resolvable WooPay origin, no trusted sender.
	const isWooPayMessage = ( event ) => {
		const woopayOrigin = getWooPayHostOrigin();
		return !! woopayOrigin && event.origin === woopayOrigin;
	};
	const recordUserEvent = ( eventName, eventProperties ) =>
		recordWooPaymentsUserEvent(
			paymentSettings,
			eventName,
			eventProperties
		);

	const spinner = document.createElement( 'div' );
	const parentDiv = woopayEmailInput.parentNode;
	spinner.classList.add( 'wc-block-components-spinner' );

	// The OTP iframe wrapper (the dimmed backdrop on wide viewports).
	const iframeWrapper = document.createElement( 'div' );
	iframeWrapper.setAttribute( 'role', 'dialog' );
	iframeWrapper.setAttribute( 'aria-modal', 'true' );
	iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

	const iframe = document.createElement( 'iframe' );
	iframe.title = paymentSettings.woopayOtpIframeTitle || '';
	iframe.classList.add( 'woopay-otp-iframe' );
	// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
	iframe.classList.add( 'intrinsic-ignore' );

	const iframeArrow = document.createElement( 'span' );
	iframeArrow.setAttribute( 'aria-hidden', 'true' );
	iframeArrow.classList.add( 'arrow' );

	// A back-button return from WooPay (or ?skip_woopay=true) must not
	// bounce the shopper straight back; the cookie extends the skip to the
	// whole session.
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

	// Tracks the iframe header state; the default must match the
	// platform's default.
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

	// Positions the popover to the right of the input unless the window is
	// too narrow, in which case it sticks 50px from the right edge.
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

	iframeWrapper.insertBefore( iframeArrow, null );
	iframeWrapper.insertBefore( iframe, null );

	const errorMessage = document.createElement( 'div' );
	errorMessage.textContent = paymentSettings.woopayUnavailableMessage || '';
	errorMessage.classList.add( 'wc-block-checkout__guest-checkout-notice' );

	const closeIframe = ( focus = true ) => {
		window.removeEventListener( 'resize', getWindowSize );
		window.removeEventListener( 'resize', setPopoverPosition );

		iframeWrapper.remove();
		iframe.classList.remove( 'open' );

		if ( focus ) {
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

		const viewportWidth = window.document.documentElement.clientWidth;
		const viewportHeight = window.document.documentElement.clientHeight;

		const urlParams = new URLSearchParams();
		urlParams.append( 'email', email );
		urlParams.append( 'testMode', !! paymentSettings.testMode );
		urlParams.append(
			'needsHeader',
			FULL_SCREEN_MODAL_BREAKPOINT > window.innerWidth
		);
		urlParams.append( 'wcpayVersion', paymentSettings.wcpayVersionNumber );
		urlParams.append( 'is_blocks', 'true' );
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

	// The Blocks email field wrapper is the text-input component; the notice
	// goes below the whole field.
	const getNoticeNode = () => parentDiv.parentNode || parentDiv;

	const showErrorMessage = () => {
		getNoticeNode().insertBefore( errorMessage, null );
	};

	document.addEventListener( 'keyup', ( event ) => {
		if ( event.key === 'Escape' ) {
			closeIframe();
		}
	} );

	// Placing the order before the lookup returns cancels the WooPay
	// request and closes the iframe.
	const abortController = new AbortController();
	const { signal } = abortController;

	signal.addEventListener( 'abort', () => {
		spinner.remove();
		closeIframe( false );
	} );

	getTargetElement(
		'button.wc-block-components-checkout-place-order-button'
	).then( ( formSubmitButton ) => {
		formSubmitButton?.addEventListener( 'click', () => {
			abortController.abort();
		} );
	} );

	const dispatchUserExistEvent = ( userExist ) => {
		window.dispatchEvent(
			new CustomEvent( 'woopayUserCheck', {
				detail: { isRegisteredUser: userExist },
			} )
		);
	};

	const woopayLocateUser = ( email, shouldOpenIframe = true ) => {
		parentDiv.insertBefore( spinner, woopayEmailInput );

		const node = getNoticeNode();
		if ( node.contains( errorMessage ) ) {
			node.removeChild( errorMessage );
		}

		recordUserEvent( 'checkout_email_address_woopay_check' );

		postWooPayAjax( paymentSettings, 'get_woopay_signature', {
			_ajax_nonce: paymentSettings.woopaySignatureNonce || '',
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
				emailExistsQuery.append(
					'test_mode',
					!! paymentSettings.testMode
				);
				emailExistsQuery.append(
					'wcpay_version',
					paymentSettings.wcpayVersionNumber
				);
				emailExistsQuery.append(
					'blog_id',
					paymentSettings.woopayMerchantId
				);
				emailExistsQuery.append( 'request_signature', signature );

				return window.fetch(
					`${ woopayHost }/wp-json/platform-checkout/v1/user/exists?${ emailExistsQuery.toString() }`,
					{ signal }
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
					recordUserEvent( 'checkout_woopay_save_my_info_offered' );

					if ( window.woopayCheckout?.PRE_CHECK_SAVE_MY_INFO ) {
						recordUserEvent( 'checkout_save_my_info_click', {
							status: 'checked',
						} );
					}
				}
			} )
			.catch( ( err ) => {
				// Only surface connection errors reaching WooPay.
				if (
					! paymentSettings.woopayIsCountryAvailable ||
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
					navigate( e.data.redirectUrl );
				}
				break;
			case 'redirect_to_platform_checkout':
			case 'redirect_to_woopay': {
				const promise = initWooPay(
					paymentSettings,
					woopayEmailInput.value,
					e.data.platformCheckoutUserSession
				);

				// WooPay's <Login> re-renders and sends the message twice;
				// the second init is skipped.
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
							navigate( response.url );
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
		// The shopper returned via the back button: they exist. Wait for the
		// window to settle before announcing it.
		setTimeout( () => {
			dispatchUserExistEvent( true );
		}, 2000 );

		recordUserEvent( 'woopay_skipped', {} );

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

export const __test__ = {
	setNavigate: ( callback ) => {
		navigate = callback;
	},
};
