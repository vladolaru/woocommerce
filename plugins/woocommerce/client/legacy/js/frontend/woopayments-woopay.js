/* eslint-disable max-len */
/* global jQuery, wcpay_core_woopay_config */
( function ( $, window, document ) {
	'use strict';

	var config = window.wcpay_core_woopay_config || {};
	// Shopper-facing strings, translated through the wp-i18n dependency like client 11.1.0's @wordpress/i18n imports.
	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;
	var navigate = function ( url ) {
		window.location.href = url;
	};
	var isWooPayRequesting = false;
	// Client 11.2.0 woopay-express-checkout-button.js:282-295, :324-337 (onClickCallbackRef.current = onClickOtpFlow).
	var isWooPayOtpFlowNext = false;
	var currentPreferredCard = null;
	var buttonWidthThreshold = 140;
	var cardDisplayWidthThreshold = 220;
	var directCheckoutLoggedIn = false;
	var hasPhoneForMobileEnter = false;
	var isWooPayUser = false;
	var proceedToCheckoutSelector =
		'.wc-proceed-to-checkout .checkout-button,' +
		'.wp-block-woocommerce-proceed-to-checkout-block,' +
		'a.wp-block-woocommerce-mini-cart-checkout-button-block,' +
		'a.wc-block-mini-cart__footer-checkout,' +
		'.widget_shopping_cart a.button.checkout';
	var brandAliases = {
		american_express: 'amex',
		diners_club: 'diners',
		union_pay: 'unionpay',
	};
	var brandDisplayNames = {
		// Client 11.1.0 woopay-express-checkout-button.js:34-45.
		amex: __( 'American Express', 'woocommerce' ),
		cartes_bancaires: __( 'Cartes Bancaires', 'woocommerce' ),
		diners: __( 'Diners Club', 'woocommerce' ),
		discover: __( 'Discover', 'woocommerce' ),
		jcb: __( 'JCB', 'woocommerce' ),
		mastercard: __( 'Mastercard', 'woocommerce' ),
		unionpay: __( 'UnionPay', 'woocommerce' ),
		visa: __( 'Visa', 'woocommerce' ),
	};
	var wooPayIconDark =
		'<svg aria-hidden="true" focusable="false" viewBox="0 0 109 28" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M69.496 5.785v16.42h2.766v-6.179h4.37c1.104 0 2.059-.23 2.865-.689.807-.46 1.424-1.075 1.852-1.846.428-.788.642-1.65.642-2.585 0-.936-.214-1.79-.642-2.56a4.714 4.714 0 0 0-1.852-1.872c-.79-.46-1.745-.69-2.864-.69h-7.137Zm2.766 7.804h4c.56 0 1.054-.107 1.482-.32.445-.23.79-.55 1.037-.96.247-.41.37-.878.37-1.403 0-.526-.123-.993-.37-1.404a2.386 2.386 0 0 0-1.037-.935c-.428-.23-.922-.345-1.482-.345h-4v5.367Zm15.693 8.912c-1.02 0-1.934-.246-2.74-.739-.808-.508-1.441-1.23-1.902-2.166-.445-.936-.667-2.043-.667-3.323 0-1.264.222-2.364.667-3.3.46-.951 1.094-1.682 1.901-2.19.807-.51 1.72-.764 2.741-.764.823 0 1.597.206 2.321.616a4.761 4.761 0 0 1 1.556 1.352v-1.672h2.47v11.89h-2.47v-1.667a5.231 5.231 0 0 1-1.556 1.372 4.695 4.695 0 0 1-2.32.591Zm2.692-9.675c.53.311.924.673 1.185 1.084v4.757c-.26.423-.656.79-1.185 1.101-.642.361-1.3.542-1.976.542-1.053 0-1.893-.37-2.519-1.108-.609-.755-.913-1.731-.913-2.93 0-.787.132-1.485.395-2.092a3.327 3.327 0 0 1 1.21-1.428c.527-.345 1.136-.517 1.827-.517.675 0 1.334.197 1.976.59Zm6.422 11.817c.115.066.271.115.469.148.197.032.395.049.592.049.395 0 .725-.082.988-.246.264-.164.478-.435.642-.813l.625-1.442-4.897-12.024h2.642l3.556 9.035 3.556-9.035h2.667l-5.803 14.106c0 .017-.008.025-.024.025v.05c-.264.607-.585 1.099-.964 1.476a3.337 3.337 0 0 1-1.284.813c-.477.164-1.02.246-1.63.246a6.98 6.98 0 0 1-1.53-.172l.395-2.216ZM39.45 5.512c-4.856 0-8.575 3.614-8.575 8.502 0 4.888 3.743 8.478 8.575 8.478 4.832 0 8.527-3.614 8.551-8.478 0-4.888-3.719-8.502-8.551-8.502Zm0 11.76c-1.824 0-3.08-1.369-3.08-3.258 0-1.89 1.256-3.283 3.08-3.283s3.08 1.394 3.08 3.283-1.233 3.259-3.08 3.259Zm-30.463 5.22c1.919 0 3.458-.945 4.619-3.117l2.582-4.818v4.085c0 2.41 1.563 3.85 3.98 3.85 1.894 0 3.292-.827 4.642-3.117l5.946-10.013c1.302-2.196.378-3.85-2.488-3.85-1.54 0-2.534.496-3.434 2.173l-4.098 7.675V8.535c0-2.03-.971-3.022-2.772-3.022-1.42 0-2.558.614-3.434 2.314l-3.861 7.533V8.606c0-2.172-.9-3.093-3.08-3.093H3.136c-1.682 0-2.534.779-2.534 2.22 0 1.44.9 2.266 2.534 2.266H4.96v8.62c0 2.432 1.634 3.873 4.027 3.873Zm40.221-8.478c0-4.888 3.719-8.502 8.551-8.502 4.832 0 8.551 3.637 8.551 8.502 0 4.864-3.719 8.478-8.55 8.478-4.833 0-8.552-3.59-8.552-8.478Zm5.495 0c0 1.889 1.208 3.259 3.056 3.259 1.824 0 3.08-1.37 3.08-3.26 0-1.888-1.256-3.282-3.08-3.282s-3.056 1.394-3.056 3.283Z" fill="#fff"/></svg>';
	var wooPayIconLight =
		'<svg aria-hidden="true" focusable="false" viewBox="0 0 100 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M63.963 4.435v15.121h2.547v-5.69h4.025c1.016 0 1.895-.212 2.638-.635a4.384 4.384 0 0 0 1.705-1.7 4.906 4.906 0 0 0 .591-2.38c0-.862-.197-1.648-.59-2.358a4.341 4.341 0 0 0-1.706-1.723c-.728-.423-1.607-.635-2.638-.635h-6.572Zm2.547 7.187h3.684c.515 0 .97-.099 1.364-.295.41-.212.728-.506.955-.884.228-.378.341-.809.341-1.292 0-.484-.113-.915-.34-1.293a2.197 2.197 0 0 0-.956-.861c-.394-.212-.849-.318-1.364-.318H66.51v4.943Zm14.451 8.206c-.94 0-1.781-.226-2.524-.68-.743-.468-1.326-1.133-1.75-1.995-.41-.861-.615-1.881-.615-3.06 0-1.164.205-2.177.614-3.038.425-.877 1.008-1.55 1.751-2.018.743-.468 1.584-.703 2.524-.703.758 0 1.47.19 2.138.567a4.384 4.384 0 0 1 1.432 1.245v-1.54h2.274v10.95h-2.274V18.02c-.393.517-.87.938-1.432 1.264a4.323 4.323 0 0 1-2.138.544Zm2.479-8.91c.487.287.851.62 1.091 1v4.38c-.24.39-.604.727-1.091 1.014-.591.332-1.198.499-1.82.499-.97 0-1.743-.34-2.319-1.02-.56-.696-.841-1.595-.841-2.698 0-.726.121-1.368.364-1.927.257-.56.629-.998 1.114-1.315.485-.318 1.046-.476 1.683-.476.621 0 1.228.181 1.819.544Zm5.913 10.883c.107.06.25.106.433.136.181.03.363.045.545.045.364 0 .667-.075.91-.227.243-.15.44-.4.591-.748l.576-1.328-4.51-11.073h2.433l3.275 8.32 3.274-8.32h2.456l-5.344 12.99c0 .016-.007.023-.022.023v.046c-.243.559-.538 1.012-.887 1.36a3.079 3.079 0 0 1-1.183.748c-.44.151-.94.227-1.5.227a6.449 6.449 0 0 1-1.41-.159l.364-2.04Z" fill="#000"/><path fill-rule="evenodd" clip-rule="evenodd" d="M8.242 19.82c1.767 0 3.185-.87 4.254-2.871l2.377-4.436v3.762c0 2.218 1.44 3.545 3.665 3.545 1.745 0 3.032-.762 4.275-2.871l5.475-9.22c1.2-2.023.35-3.545-2.29-3.545-1.418 0-2.334.457-3.163 2l-3.774 7.068V6.968c0-1.87-.894-2.784-2.552-2.784-1.308 0-2.355.565-3.162 2.131L9.79 13.252v-6.22c0-2-.829-2.848-2.836-2.848h-4.1c-1.55 0-2.334.718-2.334 2.044 0 1.327.828 2.088 2.334 2.088h1.68v7.937c0 2.24 1.504 3.567 3.707 3.567ZM36.295 4.184c-4.472 0-7.897 3.327-7.897 7.829 0 4.501 3.447 7.807 7.897 7.807s7.852-3.328 7.874-7.807c0-4.502-3.425-7.829-7.874-7.829Zm0 10.83c-1.68 0-2.836-1.262-2.836-3.001 0-1.74 1.156-3.023 2.836-3.023s2.835 1.283 2.835 3.023c0 1.74-1.134 3-2.835 3Zm8.985-3.001c0-4.502 3.425-7.829 7.875-7.829s7.874 3.349 7.874 7.829c0 4.48-3.424 7.807-7.874 7.807-4.45 0-7.875-3.306-7.875-7.807Zm5.061 0c0 1.74 1.112 3 2.814 3 1.68 0 2.835-1.26 2.835-3S54.834 8.99 53.155 8.99c-1.68 0-2.814 1.283-2.814 3.023Z" fill="#873EFF"/></svg>';

	// Each WooPayments gateway renders its own box, so the error goes to the
	// selected gateway's box and the others are cleared.
	function setError( message ) {
		var boxSelector = '.wcpay-core-payment-errors';
		var boxes = Array.prototype.slice.call(
			document.querySelectorAll( boxSelector )
		);
		var selected = document.querySelector(
			'input[name="payment_method"]:checked'
		);
		var listItem =
			selected && selected.closest ? selected.closest( 'li' ) : null;
		var errorElement =
			( listItem && listItem.querySelector( boxSelector ) ) || boxes[ 0 ];

		if ( ! errorElement ) {
			return;
		}

		boxes.forEach( function ( box ) {
			if ( box !== errorElement ) {
				box.textContent = '';
				box.hidden = true;
			}
		} );
		errorElement.textContent = message || '';
		errorElement.hidden = ! message;
	}

	// The WooPay protocol both checkouts share (wc-woopayments-woopay-common).
	function getWooPayProtocol() {
		return window.wc.woopayments.woopay;
	}

	function buildWooPayAjaxUrl( endpoint ) {
		return getWooPayProtocol().buildAjaxUrl(
			{ wcAjaxUrl: config.wcAjaxUrl },
			endpoint
		);
	}

	function getWooPayButtonSettings() {
		return config.woopayButton || {};
	}

	function getBillingEmail() {
		var field = document.getElementById( 'billing_email' );

		return field && field.value ? field.value : config.woopaySessionEmail || '';
	}

	// Port of the plugin's appendRedirectionParams() (client 11.1.0
	// woopay/utils.js:48-63): on an order's pay page WooPay needs the order,
	// its key and the billing email to pay that order instead of a cart.
	function appendRedirectionParams( woopayUrl ) {
		var url;

		if ( ! config.pay_for_order || ! config.order_id || ! config.key ) {
			return woopayUrl;
		}

		url = new window.URL( woopayUrl );
		url.searchParams.append( 'pay_for_order', config.pay_for_order );
		url.searchParams.append( 'order_id', config.order_id );
		url.searchParams.append( 'key', config.key );
		url.searchParams.append( 'billing_email', config.billing_email );

		return url.href;
	}

	function escapeHtml( value ) {
		var element = document.createElement( 'div' );

		element.textContent = value || '';

		return element.innerHTML;
	}

	function normalizeCardBrand( brand ) {
		return brandAliases[ brand ] || brand;
	}

	function getPreferredCardDisplayName( preferredCard ) {
		var normalizedBrand = normalizeCardBrand( preferredCard.brand );

		return brandDisplayNames[ normalizedBrand ] || normalizedBrand;
	}

	function getWooPayConnectOrigin() {
		return getWooPayProtocol().getWooPayHostOrigin( {
			woopayHost: config.woopayHost,
		} );
	}

	// The page's WooPay Connect client (wc-woopayments-woopay-common), which the Blocks WooPay script shares.
	function getWooPayConnect() {
		return getWooPayProtocol().getConnect( {
			woopayHost: config.woopayHost,
			woopayMerchantId: config.woopayMerchantId,
			testMode: config.testMode,
			ajaxUrl: config.ajaxUrl,
			platformTrackerNonce: config.platformTrackerNonce,
			// Client 11.1.0 woopay/connect/woopay-connect-iframe.js:98-101.
			iframeTitle: __( 'WooPay Connect Direct Checkout', 'woocommerce' ),
		} );
	}

	// With shouldTimeOut, the fallback answers when WooPay does not reply within the connect timeout.
	function sendWooPayConnectMessage(
		message,
		callbackName,
		fallback,
		shouldTimeOut
	) {
		return getWooPayConnect().sendMessage(
			message,
			callbackName,
			fallback,
			shouldTimeOut
		);
	}

	function sendPreemptiveSessionDataToWooPay( sessionData ) {
		return getWooPayConnect().sendPreemptiveSessionData( sessionData );
	}

	function fetchPreferredCardFromWooPay() {
		return getWooPayConnect()
			.fetchPreferredCard()
			.then( function ( card ) {
				getWooPayProtocol().setCachedPreferredCard( card );

				if (
					! getWooPayProtocol().isSamePreferredCard(
						card,
						currentPreferredCard
					)
				) {
					currentPreferredCard = card;
					renderWooPayExpressButton();
				}

				return card;
			} );
	}

	currentPreferredCard = getWooPayProtocol().getCachedPreferredCard();

	function postWooPayAjax( endpoint, data ) {
		var url = buildWooPayAjaxUrl( endpoint );

		if ( ! url ) {
			return null;
		}

		return $.post( url, data );
	}

	function getTrackingAjaxUrl() {
		return config.ajaxUrl;
	}

	function getTrackingNonce() {
		return config.platformTrackerNonce;
	}

	function recordUserEvent( eventName, eventProperties ) {
		var tracksUrl = config.tracksUrl;
		var nonce = getTrackingNonce();
		var body;

		if (
			! eventName ||
			// wp_localize_script() sends a false flag as ''.
			! config.isShopperTrackingEnabled ||
			! tracksUrl ||
			! nonce ||
			! window.fetch ||
			! window.FormData
		) {
			return;
		}

		body = new window.FormData();
		body.append( 'tracksNonce', nonce );
		body.append( 'tracksEventName', eventName );
		body.append( 'tracksEventProp', JSON.stringify( eventProperties || {} ) );

		// The REST nonce keeps a logged-in shopper's session, which the Tracks nonce is tied to.
		window.fetch( tracksUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: config.tracksRestNonce
				? { 'X-WP-Nonce': config.tracksRestNonce }
				: {},
			body: body,
		} ).catch( function () {} );
	}

	function getTrackingSource() {
		return getWooPayButtonSettings().context || 'checkout';
	}

	function isProductPageWooPayButton() {
		var container = document.getElementById( 'wcpay-woopay-button' );
		var buttonSettings = getWooPayButtonSettings();

		return (
			( container && container.getAttribute( 'data-product_page' ) === '1' ) ||
			buttonSettings.context === 'product'
		);
	}

	function getProductFormElement() {
		return (
			document.querySelector( 'form.cart' ) ||
			document.querySelector( 'form.wp-block-add-to-cart-with-options' )
		);
	}

	// Client 11.1.0's name (woopay-express-checkout-button.js:124): when it answers no, it also shows the error and ends
	// the WooPay request.
	function canAddProductToCart() {
		var form = getProductFormElement();
		var button;

		if ( ! isProductPageWooPayButton() || ! form ) {
			return true;
		}

		if (
			form.classList.contains( 'wp-block-add-to-cart-with-options' ) &&
			form.classList.contains( 'is-invalid' )
		) {
			setError( config.confirmationErrorMessage || '' );
			isWooPayRequesting = false;
			return false;
		}

		button = form.querySelector( '.single_add_to_cart_button' );
		if (
			button &&
			( button.disabled ||
				button.classList.contains( 'disabled' ) ||
				button.classList.contains( 'wc-variation-selection-needed' ) ||
				button.classList.contains( 'wc-variation-is-unavailable' ) )
		) {
			setError( config.confirmationErrorMessage || '' );
			isWooPayRequesting = false;
			return false;
		}

		return true;
	}

	function deleteSkipWooPayCookie() {
		getWooPayProtocol().deleteSkipWooPayCookie();
	}

	function setFormDataValue( data, name, value ) {
		if ( name.slice( -2 ) === '[]' ) {
			name = name.slice( 0, -2 );
			if ( ! data[ name ] ) {
				data[ name ] = [];
			}
			data[ name ].push( value );
			return;
		}

		data[ name ] = value;
	}

	function getProductFormData() {
		var form = getProductFormElement();
		var data = {};
		var productIdField;
		var addToCartButton;
		var fields;

		if ( ! form ) {
			return data;
		}

		fields = form.querySelectorAll( 'input, select, textarea' );
		fields.forEach( function ( field ) {
			if (
				! field.name ||
				field.name === 'add-to-cart' ||
				field.disabled ||
				( ( field.type === 'checkbox' || field.type === 'radio' ) &&
					! field.checked )
			) {
				return;
			}

			setFormDataValue( data, field.name, field.value );
		} );

		if ( ! data.product_id ) {
			productIdField = form.querySelector( 'input[name="product_id"]' );
			addToCartButton = form.querySelector(
				'.single_add_to_cart_button[name="add-to-cart"]'
			);
			if ( productIdField && productIdField.value ) {
				data.product_id = productIdField.value;
			} else if ( addToCartButton && addToCartButton.value ) {
				data.product_id = addToCartButton.value;
			}
		}

		if ( ! data.quantity ) {
			data.quantity = '1';
		}

		return data;
	}

	function isValidWooPayMinimumSessionData( sessionData ) {
		return (
			sessionData &&
			sessionData.blog_id &&
			sessionData.data &&
			sessionData.data.session &&
			sessionData.data.iv &&
			sessionData.data.hash
		);
	}

	function getWooPayMinimumSessionRedirectUrl( sessionData ) {
		var params;

		if (
			! config.woopayHost ||
			! isValidWooPayMinimumSessionData( sessionData )
		) {
			return '';
		}

		params = new window.URLSearchParams( {
			checkout_redirect: '1',
			blog_id: sessionData.blog_id,
			session: sessionData.data.session,
			iv: sessionData.data.iv,
			hash: sessionData.data.hash,
		} );

		return config.woopayHost + '/woopay/?' + params.toString();
	}

	function isValidWooPayRedirectUrl( redirectUrl ) {
		var parsedUrl;

		try {
			parsedUrl = new window.URL( redirectUrl );
			return (
				parsedUrl.origin === getWooPayConnectOrigin() &&
				!! parsedUrl.searchParams.get( 'platform_checkout_key' )
			);
		} catch ( error ) {
			if ( error ) {
				return false;
			}
			return false;
		}
	}

	function requestWooPaySession( encryptedData ) {
		return new Promise( function ( resolve, reject ) {
			var data = { _ajax_nonce: config.woopaySessionNonce || '' };
			if ( encryptedData ) {
				data.encrypted_data = encryptedData;
			}

			var request = postWooPayAjax( 'get_woopay_session', data );

			if ( ! request || ! request.done ) {
				reject();
				return;
			}

			request.done( resolve );
			if ( request.fail ) {
				request.fail( reject );
			}
		} );
	}

	function getDirectCheckoutHref( element ) {
		if ( element.tagName && element.tagName.toLowerCase() === 'a' ) {
			return element.href;
		}

		var link = element.querySelector( 'a[href]' );
		return link ? link.href : '';
	}

	function setDirectCheckoutLoading( element, loading ) {
		if ( loading ) {
			element.setAttribute( 'aria-disabled', 'true' );
			element.classList.add( 'is-woopay-direct-checkout-loading' );
			return;
		}

		element.removeAttribute( 'aria-disabled' );
		element.classList.remove( 'is-woopay-direct-checkout-loading' );
	}

	function runDirectCheckout( loggedIn ) {
		if ( loggedIn ) {
			return sendWooPayConnectMessage(
				{ action: 'getEncryptedData' },
				'getEncryptedData',
				null,
				true
			)
				.then( requestWooPaySession )
				.then( function ( sessionData ) {
					if ( ! isValidWooPayMinimumSessionData( sessionData ) ) {
						return Promise.reject();
					}

					return sendWooPayConnectMessage(
						{
							action: 'setRedirectSessionData',
							value: sessionData,
						},
						'setRedirectSessionData',
						null,
						true
					);
				} )
				.then( function ( redirectData ) {
					if (
						! redirectData ||
						! isValidWooPayRedirectUrl( redirectData.redirect_url )
					) {
						return Promise.reject();
					}

					return redirectData.redirect_url;
				} );
		}

		return sendWooPayConnectMessage(
			{ action: 'isWooPayReachable' },
			'getIsWooPayReachable',
			false,
			true
		).then( function ( reachable ) {
			var redirectUrl;

			if ( ! reachable ) {
				return Promise.reject();
			}

			redirectUrl = getWooPayMinimumSessionRedirectUrl(
				config.woopayMinimumSessionData
			);
			return redirectUrl ? redirectUrl : Promise.reject();
		} );
	}

	function attachDirectCheckoutListeners() {
		if ( document.body.wooPayDirectCheckoutAttached ) {
			return;
		}
		document.body.wooPayDirectCheckoutAttached = true;

		document.body.wooPayDirectCheckoutHandler = function ( event ) {
			var element =
				event.target && event.target.closest
					? event.target.closest( proceedToCheckoutSelector )
					: null;
			var originalHref;

			if ( ! element ) {
				return;
			}

			originalHref = getDirectCheckoutHref( element );
			if ( ! originalHref ) {
				return;
			}

			event.preventDefault();
			if ( element.wooPayDirectCheckoutActive ) {
				return;
			}
			element.wooPayDirectCheckoutActive = true;
			setDirectCheckoutLoading( element, true );

			runDirectCheckout( directCheckoutLoggedIn )
				.then( navigate )
				.catch( function () {
					element.wooPayDirectCheckoutActive = false;
					setDirectCheckoutLoading( element, false );
					navigate( originalHref );
				} );
		};
		document.body.addEventListener(
			'click',
			document.body.wooPayDirectCheckoutHandler
		);
	}

	// A shopper who opted out of WooPay keeps the store's checkout (client 11.2.0 direct-checkout/index.js:248, :290).
	function shouldRunDirectCheckout() {
		return (
			config.isWooPayDirectCheckoutEnabled &&
			! getWooPayProtocol().shouldSkipWooPay()
		);
	}

	function initializeDirectCheckout() {
		if ( ! shouldRunDirectCheckout() ) {
			return;
		}

		sendWooPayConnectMessage(
			{ action: 'getIsUserLoggedIn' },
			'getIsUserLoggedIn',
			false,
			true
		).then( function ( loggedIn ) {
			directCheckoutLoggedIn = loggedIn;
			attachDirectCheckoutListeners();
		} );
	}

	// Resolves the stringified Tracks identity WooPay stitches its own
	// events to: the tk_ai cookie when present, otherwise the platform's
	// get_identity bridge. Never rejects.
	function getTracksIdentity() {
		var ajaxUrl = getTrackingAjaxUrl();
		var nonce = getTrackingNonce();
		var cookie = ( document.cookie || '' )
			.split( ';' )
			.map( function ( entry ) {
				return entry.trim();
			} )
			.find( function ( entry ) {
				return entry.indexOf( 'tk_ai=' ) === 0;
			} );
		var body;

		if ( cookie ) {
			return Promise.resolve(
				JSON.stringify( { _ut: 'anon', _ui: cookie.substring( 6 ) } )
			);
		}

		if ( ! ajaxUrl || ! nonce || ! window.fetch || ! window.FormData ) {
			return Promise.resolve( undefined );
		}

		body = new window.FormData();
		body.append( 'tracksNonce', nonce );
		body.append( 'action', 'get_identity' );

		return window
			.fetch( ajaxUrl, { method: 'POST', body: body } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				return data && data.success && data.data && data.data._ui && data.data._ut
					? JSON.stringify( data.data )
					: undefined;
			} )
			.catch( function () {
				return undefined;
			} );
	}

	// The plugin's request(): nested objects become bracketed form keys and
	// empty values are left out.
	function appendFormData( formData, data, prefix ) {
		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];
			var name = prefix ? prefix + '[' + key + ']' : key;

			if ( typeof value === 'string' || typeof value === 'number' ) {
				formData.append( name, value );
			} else if ( value && typeof value === 'object' ) {
				appendFormData( formData, value, name );
			}
		} );

		return formData;
	}

	// The plugin's express-button showErrorMessage(): a Blocks notice on the
	// cart and checkout blocks, a server-rendered WooCommerce notice elsewhere.
	function showExpressErrorMessage( context, message ) {
		if (
			window.wcSettings &&
			window.wcSettings.wcBlocksConfig &&
			context !== 'product' &&
			window.wp &&
			window.wp.data
		) {
			window.wp.data
				.dispatch( 'core/notices' )
				.createNotice( 'error', message, { context: 'wc/' + context } );
			return;
		}

		window
			.fetch( getTrackingAjaxUrl(), {
				method: 'POST',
				body: new window.URLSearchParams( {
					action: 'woopay_express_checkout_button_show_error_notice',
					_ajax_nonce: config.woopayButtonNonce || '',
					context: context,
					message: message,
				} ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				var noticesWrapper = document.querySelector(
					'.woocommerce-notices-wrapper'
				);
				var wrapper;

				if ( ! response || ! response.success || ! noticesWrapper ) {
					return;
				}

				wrapper = document.createElement( 'div' );
				wrapper.innerHTML = response.data.notice;
				noticesWrapper.insertBefore( wrapper, null );
				noticesWrapper.scrollIntoView( {
					behavior: 'smooth',
					block: 'center',
				} );
			} )
			.catch( function () {} );
	}

	// This script's WooPay requests: a multipart body with bracketed keys for nested values (appendFormData()).
	function postWooPayFormData( endpoint, data ) {
		return window
			.fetch( buildWooPayAjaxUrl( endpoint ), {
				method: 'POST',
				body: appendFormData( new window.FormData(), data ),
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	// The values the WooPay button's OTP flow (wc-woopayments-woopay-common) reads, from this script's config.
	function getExpressOtpArgs( context ) {
		return {
			wcAjaxUrl: config.wcAjaxUrl,
			post: postWooPayFormData,
			initWooPayNonce: config.initWooPayNonce,
			woopaySessionNonce: config.woopaySessionNonce,
			orderId: config.order_id,
			key: config.key,
			billingEmail: config.billing_email,
			getInitAppearance: function () {
				return config.isWooPayGlobalThemeSupportEnabled
					? config.woopayAppearance
					: null;
			},
			getInitFontRules: function () {
				return config.isWooPayGlobalThemeSupportEnabled
					? config.woopayFontRules
					: null;
			},
			getSessionAppearance: function () {
				return config.woopayAppearance;
			},
			woopayHost: config.woopayHost,
			testMode: config.testMode,
			wcpayVersionNumber: config.wcpayVersionNumber,
			isWoopayFirstPartyAuthEnabled: config.isWoopayFirstPartyAuthEnabled,
			otpIframeTitle: config.woopayOtpIframeTitle,
			otpCloseLabel: config.woopayOtpCloseLabel,
			unavailableMessage: config.woopayExpressUnavailableMessage,
			showError: function ( message ) {
				showExpressErrorMessage( context, message );
			},
			navigate: function ( url ) {
				navigate( url );
			},
			getRedirectUrl: appendRedirectionParams,
			// updated_checkout renders the WooPay button again (renderWooPayExpressButton()), so the button that opened
			// the dialog may have been replaced while it was open; the new one gets focus.
			getFocusTarget: function ( previouslyFocused ) {
				if (
					! previouslyFocused.isConnected &&
					previouslyFocused.classList &&
					previouslyFocused.classList.contains( 'woopay-express-button' )
				) {
					return document.querySelector(
						'#wcpay-woopay-button .woopay-express-button'
					);
				}

				return previouslyFocused;
			},
		};
	}

	// Opens the WooPay button's one-time-code dialog once the Tracks identity resolves.
	function expressCheckoutIframe( context, emailSelector ) {
		return getTracksIdentity().then( function ( identity ) {
			var emailInput = document.querySelector( emailSelector );
			// The order-pay page has no email field; client 11.1.0 takes the
			// order's email there (express-checkout-iframe.js:22-33). Native
			// takes the billing email the page may give this visitor.
			var email =
				config.pay_for_order === 'true'
					? config.billing_email
					: emailInput && emailInput.value;

			getWooPayProtocol().openExpressOtpIframe(
				getExpressOtpArgs( context ),
				context,
				email || config.woopaySessionEmail,
				identity
			);
		} );
	}

	function continueWooPay() {
		expressCheckoutIframe( getTrackingSource(), '#billing_email' ).then(
			function () {
				isWooPayRequesting = false;
			},
			function () {
				isWooPayRequesting = false;
			}
		);
	}

	function getWooPaySessionRequestData() {
		return {
			_ajax_nonce: config.woopaySessionNonce || '',
			appearance: config.woopayAppearance || null,
			font_rules: config.woopayFontRules || [],
			email: getBillingEmail(),
			order_id: config.order_id || '',
			key: config.key || '',
			billing_email: config.billing_email || '',
		};
	}

	function showWooPaySessionError() {
		isWooPayRequesting = false;
		showExpressErrorMessage(
			getTrackingSource(),
			__( 'Something went wrong. Please try again.', 'woocommerce' )
		);
	}

	// Client 11.2.0 woopay-express-checkout-button.js:254-337: an answer without a session shows the error and sends the
	// next click to the OTP flow; a request with no answer or a Connect that does not answer shows the error and keeps
	// this flow.
	function continueWooPayFirstPartyAuth() {
		var request = postWooPayAjax(
			'get_woopay_session',
			getWooPaySessionRequestData()
		);

		if ( ! request || ! request.done ) {
			isWooPayRequesting = false;
			return;
		}

		request.done( function ( response ) {
			if ( ! isValidWooPayMinimumSessionData( response ) ) {
				isWooPayOtpFlowNext = true;
				showWooPaySessionError();
				return;
			}

			sendPreemptiveSessionDataToWooPay( response ).then( function (
				sessionResponse
			) {
				if ( sessionResponse && sessionResponse.is_error ) {
					isWooPayOtpFlowNext = true;
					continueWooPay();
					return;
				}

				if ( ! sessionResponse || ! sessionResponse.redirect_url ) {
					showWooPaySessionError();
					return;
				}

				navigate( appendRedirectionParams( sessionResponse.redirect_url ) );
				isWooPayRequesting = false;
			} );
		} );

		if ( request.fail ) {
			// The client's fetch reads a JSON error body, such as the 503 for a missing blog ID, as an answer without a
			// session.
			request.fail( function ( jqXHR ) {
				if ( jqXHR && jqXHR.responseJSON ) {
					isWooPayOtpFlowNext = true;
				}
				showWooPaySessionError();
			} );
		}
	}

	function prepareProductCartForWooPay( onSuccess ) {
		var productData;
		var request;

		if ( ! isProductPageWooPayButton() ) {
			onSuccess();
			return;
		}

		productData = getProductFormData();
		if ( ! productData.product_id ) {
			setError( config.confirmationErrorMessage || '' );
			isWooPayRequesting = false;
			return;
		}

		request = postWooPayAjax(
			'add_to_cart',
			Object.assign(
				{
					security: config.addToCartNonce || '',
				},
				productData
			)
		);

		if ( ! request || ! request.done ) {
			isWooPayRequesting = false;
			return;
		}

		request.done( function ( response ) {
			if ( response && response.error ) {
				setError( config.confirmationErrorMessage || '' );
				isWooPayRequesting = false;
				return;
			}

			onSuccess();
		} );

		if ( request.fail ) {
			request.fail( function ( jqXHR ) {
				var form;

				isWooPayRequesting = false;
				// A failed woocommerce_add_to_cart_validation answers 400 { error: true, submit: true }: submit the product
				// form so the extension's own notice shows, as client 11.1.0 does (woopay-express-checkout-button.js:186-196).
				if ( getAddToCartFailure( jqXHR ).submit ) {
					form = getProductFormElement();
					if ( form ) {
						form.submit();
						return;
					}
				}

				setError( config.confirmationErrorMessage || '' );
			} );
		}
	}

	function getAddToCartFailure( jqXHR ) {
		var body = jqXHR && jqXHR.responseJSON;

		if ( ! body && jqXHR && typeof jqXHR.responseText === 'string' ) {
			try {
				body = JSON.parse( jqXHR.responseText );
			} catch ( error ) {
				body = null;
			}
		}

		return body && typeof body === 'object' ? body : {};
	}

	function initWooPay( event ) {
		if ( event && event.preventDefault ) {
			event.preventDefault();
		}

		if ( isWooPayRequesting ) {
			return;
		}

		recordUserEvent( 'woopay_button_click', {
			source: getTrackingSource(),
		} );

		deleteSkipWooPayCookie();

		if ( ! canAddProductToCart() ) {
			return;
		}

		isWooPayRequesting = true;
		prepareProductCartForWooPay(
			config.isWoopayFirstPartyAuthEnabled && ! isWooPayOtpFlowNext
				? continueWooPayFirstPartyAuth
				: continueWooPay
		);
	}

	function normalizeButtonSize( settings ) {
		var heightSizeMap = {
			40: 'small',
			48: 'medium',
			55: 'large',
		};
		var size = settings.size || '';

		if ( heightSizeMap[ String( settings.height || '' ) ] ) {
			return heightSizeMap[ String( settings.height ) ];
		}

		return [ 'small', 'medium', 'large' ].indexOf( size ) !== -1
			? size
			: 'medium';
	}

	function getWooPayButtonLabel( type ) {
		var labels = config.woopayButtonLabels || {};
		var defaultLabels = {
			default: __( 'WooPay', 'woocommerce' ),
			buy: __( 'Buy with WooPay', 'woocommerce' ),
			donate: __( 'Donate with WooPay', 'woocommerce' ),
			book: __( 'Book with WooPay', 'woocommerce' ),
		};

		return labels[ type ] || defaultLabels[ type ] || defaultLabels.default;
	}

	function getWooPayButtonPrefix( type ) {
		if ( 'default' === type ) {
			return '';
		}

		return getWooPayButtonLabel( type ).replace( /\s*WooPay\s*$/, '' );
	}

	function getWooPayButtonContent( settings, preferredCard ) {
		var type = settings.type || 'default';
		var prefix = getWooPayButtonPrefix( type );
		var icon =
			'dark' === ( settings.theme || 'dark' )
				? wooPayIconDark
				: wooPayIconLight;
		var displayName = preferredCard
			? getPreferredCardDisplayName( preferredCard )
			: '';

		if ( preferredCard ) {
			return (
				'<span class="button-content woopay-button-content-card">' +
				'<span class="woopay-button-logo">' +
				icon +
				'</span>' +
				'<span class="woopay-button-separator" aria-hidden="true"></span>' +
				'<span class="woopay-button-card-brand" aria-hidden="true">' +
				escapeHtml( displayName ) +
				'</span>' +
				'<span class="woopay-button-last4">' +
				escapeHtml( preferredCard.last4 ) +
				'</span>' +
				'</span>'
			);
		}

		return (
			'<span class="button-content">' +
			( prefix ? '<span>' + escapeHtml( prefix ) + '</span>' : '' ) +
			icon +
			'</span>'
		);
	}

	function renderWooPayExpressButton() {
		var container = document.getElementById( 'wcpay-woopay-button' );
		var settings = getWooPayButtonSettings();
		var type = settings.type || 'default';
		var preferredCard = currentPreferredCard;
		var button;
		var measuredWidth;
		var widthType;
		var displayedCard;
		var hadFocus;

		// The Blocks express button renders and owns its own wrapper.
		if (
			! container ||
			container.classList.contains( 'wcpay-core-woopay-express' ) ||
			! config.isWooPayEnabled ||
			! config.shouldShowWooPayButton
		) {
			return;
		}

		button = document.createElement(
			config.isWoopayFirstPartyAuthEnabled ? 'a' : 'button'
		);
		if ( config.isWoopayFirstPartyAuthEnabled ) {
			button.href = config.woopayHost || '#';
		} else {
			button.type = 'button';
		}
		button.className = 'woopay-express-button';
		button.setAttribute( 'data-type', type );
		button.setAttribute( 'data-theme', settings.theme || 'dark' );
		button.setAttribute( 'data-size', normalizeButtonSize( settings ) );
		button.style.height = ( settings.height || '48' ) + 'px';
		button.style.borderRadius = ( settings.radius || '4' ) + 'px';
		button.addEventListener( 'click', initWooPay );

		hadFocus = container.contains( document.activeElement );
		container.innerHTML = '';
		container.appendChild( button );
		// updated_checkout renders the button again; a shopper on the old one stays on the new one. Client 11.1.0
		// mounts a new button the same way and focus drops to the page (express-button/index.js:39-62).
		if ( hadFocus ) {
			button.focus();
		}

		// Client 11.1.0 measures the rendered button and only shows the
		// preferred card once it is at least 220px wide
		// (woopay-express-checkout-button.js:31-32,375-381,387-392).
		measuredWidth = button.getBoundingClientRect().width;
		widthType = measuredWidth > buttonWidthThreshold ? 'wide' : 'narrow';
		button.setAttribute( 'data-width-type', widthType );
		displayedCard =
			preferredCard && measuredWidth >= cardDisplayWidthThreshold
				? preferredCard
				: null;

		button.setAttribute(
			'aria-label',
			displayedCard
				? sprintf(
						/* translators: %1$s: card brand name (e.g. "Visa"), %2$s: last four digits of the card. */
						__( 'WooPay with %1$s ending in %2$s', 'woocommerce' ),
						getPreferredCardDisplayName( displayedCard ),
						displayedCard.last4
				  )
				: getWooPayButtonLabel( type )
		);
		button.innerHTML = getWooPayButtonContent( settings, displayedCard );

		recordUserEvent( 'woopay_button_load', {
			source: getTrackingSource(),
		} );
	}

	// Client 11.1.0 checkout-page-save-user.js:52-58, :274-302 with use-selected-payment-method.js and use-woopay-user.js:
	// the offer applies only while WooPayments is selected with a new card and the shopper is not a WooPay user.
	function isSaveUserOfferApplicable() {
		var selectedMethod = document.querySelector(
			'input[name="payment_method"]:checked'
		);
		var newTokenRadio = document.querySelector(
			'#wc-woocommerce_payments-payment-token-new'
		);
		var isNewCardChosen = newTokenRadio
			? newTokenRadio.checked
			: ! document.querySelector(
					'[type=radio][name="wc-woocommerce_payments-payment-token"]'
			  );

		return !! (
			selectedMethod &&
			selectedMethod.value === 'woocommerce_payments' &&
			isNewCardChosen &&
			! isWooPayUser
		);
	}

	// A hidden offer posts nothing with the order. Consent travels with the form only, as on the client's classic
	// checkout (checkout-page-save-user.js:158-160 sends session requests from Blocks only), so nothing else needs clearing.
	function updateSaveUserVisibility() {
		var container = document.getElementById( 'wcpay-woopay-save-user' );
		var isApplicable = isSaveUserOfferApplicable();

		if ( ! container ) {
			return;
		}

		container.hidden = ! isApplicable;
		container
			.querySelectorAll( 'input' )
			.forEach( function ( input ) {
				input.disabled = ! isApplicable;
			} );
	}

	// Client 11.1.0 checkout-page-save-user.js:93-111: keep digits and +, and read a number without a country code as US
	// (WooPay is US-only).
	function normalizeWooPayPhone( phone ) {
		return getWooPayProtocol().normalizePhone( phone );
	}

	// Core's mobile phone validation, loaded only when the shopper opts in. Resolves null when it cannot load.
	function loadPhoneValidation() {
		return getWooPayProtocol().loadPhoneValidation(
			config.woopayPhoneValidationScriptUrl
		);
	}

	// True, false, or null while the validation script is not loaded.
	function isWooPayPhoneValid() {
		var phoneField = document.getElementById( 'woopay_user_phone_field_full' );

		if ( ! window.wcWooPaymentsPhoneValidation || ! phoneField ) {
			return null;
		}

		return window.wcWooPaymentsPhoneValidation.validatePhoneNumber(
			normalizeWooPayPhone( phoneField.value )
		);
	}

	// Client 11.1.0 checkout-page-save-user.js:176-181, :389-398: the classic message shows for an invalid number, on
	// blur and when the shopper places the order; it does not stop the submission. Without the validation script the
	// number cannot be judged, so nothing shows.
	function updateWooPayPhoneError() {
		var checkbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		var error = document.getElementById(
			'validate-error-invalid-woopay-phone-number'
		);
		var isValid = isWooPayPhoneValid();

		if ( ! error ) {
			return;
		}

		error.hidden = ! (
			checkbox &&
			checkbox.checked &&
			isSaveUserOfferApplicable() &&
			isValid === false
		);
	}

	// The number WooPay receives goes in the posted field with its country code; mobile_enter is recorded once the
	// number becomes valid (client 11.1.0 checkout-page-save-user.js:169-174).
	function syncWooPayPhone() {
		var checkbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		var phoneField = document.getElementById(
			'woopay_user_phone_field_full'
		);
		var fullPhoneField = document.querySelector(
			'input[name="woopay_user_phone_field[full]"]'
		);
		var isPhoneValid = !! (
			checkbox &&
			checkbox.checked &&
			isWooPayPhoneValid()
		);

		if ( fullPhoneField && phoneField ) {
			fullPhoneField.value = normalizeWooPayPhone( phoneField.value );
		}

		if ( isPhoneValid && ! hasPhoneForMobileEnter ) {
			recordUserEvent( 'checkout_woopay_save_my_info_mobile_enter' );
		}
		hasPhoneForMobileEnter = isPhoneValid;
	}

	// Client 11.1.0 client/components/woopay/save-user/additional-information.js.
	function createWooPaySaveUserAdditionalInfo() {
		var additionalInfo = document.createElement( 'div' );
		additionalInfo.className = 'additional-information';
		additionalInfo.textContent = config.woopayAdditionalInfoText || '';

		return additionalInfo;
	}

	// Client 11.1.0 client/components/woopay/save-user/agreement.js.
	function createWooPaySaveUserAgreement() {
		var agreement = document.createElement( 'div' );
		var links = {
			termsOfService: {
				href: 'https://wordpress.com/tos/',
				label: config.woopayTermsOfServiceLabel,
				onClick: function () {
					recordUserEvent( 'checkout_save_my_info_tos_click' );
				},
			},
			privacyPolicy: {
				href: 'https://automattic.com/privacy/',
				label: config.woopayPrivacyPolicyLabel,
				onClick: function () {
					recordUserEvent(
						'checkout_save_my_info_privacy_policy_click'
					);
				},
			},
		};

		agreement.className = 'tos';
		String( config.woopayAgreementText || '' )
			.split( /<(termsOfService|privacyPolicy)\s*\/>/ )
			.forEach( function ( part, index ) {
				var spec = links[ part ];
				var node = document.createTextNode( part );
				if ( index % 2 ) {
					node = document.createElement( 'a' );
					node.href = spec.href;
					node.target = '_blank';
					node.rel = 'noopener noreferrer';
					node.textContent = spec.label || '';
					node.addEventListener( 'click', spec.onClick );
				}
				agreement.appendChild( node );
			} );

		return agreement;
	}

	function renderWooPaySaveUserFields() {
		var form = document.querySelector( 'form.checkout' );
		var insertionPoint;
		var container;
		var checkbox;
		var phoneField;
		var sourceField;
		var viewportField;
		var fields;
		var billingPhoneField;

		// A Blocks checkout on the page owns save my info, even beside a classic form (client 11.2.0
		// checkout/woopay/index.js:23-26).
		if (
			! form ||
			! config.isWooPayEnabled ||
			! config.forceNetworkSavedCards ||
			document.querySelector( '.wc-block-checkout' ) ||
			document.getElementById( 'wcpay-woopay-save-user' )
		) {
			return;
		}

		container = document.createElement( 'div' );
		container.id = 'wcpay-woopay-save-user';
		container.className = 'wcpay-woopay-save-user';
		container.innerHTML =
			'<p class="form-row form-row-wide">' +
			'<label for="save_user_in_woopay">' +
			'<input type="checkbox" id="save_user_in_woopay" name="save_user_in_woopay" value="true" /> ' +
			escapeHtml( config.woopaySaveUserLabel ) +
			'</label>' +
			'</p>' +
			'<div class="save-details-form form-row form-row-wide">' +
			'<label for="woopay_user_phone_field_full">' +
			escapeHtml( config.woopayPhoneLabel ) +
			'</label>' +
			'<input type="tel" id="woopay_user_phone_field_full" autocomplete="tel" aria-describedby="validate-error-invalid-woopay-phone-number" />' +
			// The number WooPay receives, with its country code, as the client's phone input posts it.
			'<input type="hidden" name="woopay_user_phone_field[full]" />' +
			'<p id="validate-error-invalid-woopay-phone-number" class="woopay-phone-number-error" role="alert" hidden>' +
			escapeHtml( __( 'Please enter a valid mobile phone number.', 'woocommerce' ) ) +
			'</p>' +
			'</div>' +
			'<input type="hidden" name="woopay_source_url" />' +
			'<input type="hidden" name="woopay_viewport" />' +
			'<input type="hidden" name="woopay_is_blocks" value="false" />';
		fields = container.querySelector( '.save-details-form' );
		fields.appendChild( createWooPaySaveUserAdditionalInfo() );
		fields.appendChild( createWooPaySaveUserAgreement() );

		insertionPoint = form.querySelector( '.form-row.place-order' );
		if ( insertionPoint && insertionPoint.parentNode ) {
			insertionPoint.parentNode.insertBefore( container, insertionPoint );
		} else {
			form.appendChild( container );
		}

		checkbox = container.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		phoneField = container.querySelector( '#woopay_user_phone_field_full' );
		sourceField = container.querySelector(
			'input[name="woopay_source_url"]'
		);
		viewportField = container.querySelector(
			'input[name="woopay_viewport"]'
		);

		checkbox.checked = !! config.PRE_CHECK_SAVE_MY_INFO;
		fields.hidden = ! checkbox.checked;
		// Client 11.1.0 checkout-page-save-user.js:118-124: the checkout page, not the browser URL.
		sourceField.value = config.woopaySourceUrl || '';
		// Client 11.2.0 checkout-page-save-user.js:62-63, :364-365.
		viewportField.value =
			document.documentElement.clientWidth +
			'x' +
			document.documentElement.clientHeight;

		// The offer and a pre-checked box are recorded by the WooPay email check when it finds no WooPay user, as in
		// client 11.1.0 checkout/woopay/email-input-iframe.js:411-427, not again when the fields render.
		// Client 11.1.0 checkout-page-save-user.js:93-111, :225-232: the number starts from the billing phone.
		billingPhoneField = document.getElementById( 'billing_phone' );
		phoneField.value = billingPhoneField ? billingPhoneField.value : '';
		syncWooPayPhone();

		function validateWhenOptedIn() {
			if ( ! checkbox.checked ) {
				return;
			}
			loadPhoneValidation().then( function () {
				syncWooPayPhone();
				updateWooPayPhoneError();
			} );
		}

		checkbox.addEventListener( 'change', function () {
			fields.hidden = ! checkbox.checked;
			recordUserEvent( 'checkout_save_my_info_click', {
				status: checkbox.checked ? 'checked' : 'unchecked',
			} );
			validateWhenOptedIn();
			updateWooPayPhoneError();
		} );
		phoneField.addEventListener( 'input', syncWooPayPhone );
		phoneField.addEventListener( 'blur', function () {
			syncWooPayPhone();
			updateWooPayPhoneError();
		} );
		$( form ).on( 'checkout_place_order', function () {
			updateWooPayPhoneError();
		} );
		updateSaveUserVisibility();
		validateWhenOptedIn();
	}

	$( document.body ).on( 'updated_checkout', function () {
		renderWooPayExpressButton();
		renderWooPaySaveUserFields();
	} );
	$( document.body ).on( 'payment_method_selected', updateSaveUserVisibility );
	document.addEventListener( 'change', function ( event ) {
		if (
			event.target &&
			( event.target.name === 'payment_method' ||
				event.target.name === 'wc-woocommerce_payments-payment-token' )
		) {
			updateSaveUserVisibility();
		}
	} );
	// Dispatched by the WooPay email check in the classic checkout script.
	window.addEventListener( 'woopayUserCheck', function ( event ) {
		isWooPayUser = !! ( event.detail && event.detail.isRegisteredUser );
		updateSaveUserVisibility();
	} );
	$( document.body ).on( 'updated_cart_totals', function () {
		// Client 11.1.0 woopay/express-button/index.js:95-106 renders the button again on both refresh events: a cart
		// update replaces the markup it lived in.
		renderWooPayExpressButton();
		if ( shouldRunDirectCheckout() ) {
			attachDirectCheckoutListeners();
		}
	} );

	$( function () {
		renderWooPayExpressButton();
		renderWooPaySaveUserFields();
		// Client 11.1.0 fetches the preferred card from its express-button bundle, which loads only where the button shows
		// (class-wc-payments-woopay-button-handler.php:166-170, express-button/index.js:115-120).
		if ( config.isWooPayEnabled && config.shouldShowWooPayButton ) {
			fetchPreferredCardFromWooPay();
		}
		initializeDirectCheckout();
	} );

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports.__test__ = {
			setNavigate: function ( callback ) {
				navigate = callback;
			},
		};
	}
} )( jQuery, window, document );
