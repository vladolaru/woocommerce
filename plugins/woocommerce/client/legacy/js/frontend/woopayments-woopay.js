/* eslint-disable max-len */
/* global jQuery, wcpay_core_woopay_config, wcpay_core_checkout_config */
( function ( $, window, document ) {
	'use strict';

	var config =
		window.wcpay_core_woopay_config ||
		window.wcpay_core_checkout_config ||
		{};
	var navigate = function ( url ) {
		window.location.href = url;
	};
	var isWooPayRequesting = false;
	var isInitWooPayRequesting = false;
	var wooPayConnectPostMessagePromise = null;
	var wooPayConnectCallbacks = {};
	var wooPayConnectListenerAttached = false;
	var preferredCardFetchPromise = null;
	var currentPreferredCard = null;
	var preferredCardCacheKey = 'woopay_preferred_card';
	var wooPayConnectTimeout = 5000;
	var directCheckoutLoggedIn = false;
	var hasPhoneForMobileEnter = false;
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
		amex: 'American Express',
		diners: 'Diners Club',
		discover: 'Discover',
		jcb: 'JCB',
		mastercard: 'Mastercard',
		unionpay: 'Union Pay',
		visa: 'Visa',
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

	function buildWooPayAjaxUrl( endpoint ) {
		if ( ! config.wcAjaxUrl ) {
			return '';
		}

		return config.wcAjaxUrl.replace(
			'%%endpoint%%',
			'wcpay_' + endpoint
		);
	}

	function getWooPayButtonSettings() {
		return config.woopayButton || {};
	}

	function getBillingEmail() {
		var field = document.getElementById( 'billing_email' );

		return field && field.value ? field.value : config.woopaySessionEmail || '';
	}

	function escapeHtml( value ) {
		var element = document.createElement( 'div' );

		element.textContent = value || '';

		return element.innerHTML;
	}

	function normalizeCardBrand( brand ) {
		return brandAliases[ brand ] || brand;
	}

	function isValidPreferredCard( card ) {
		return (
			card &&
			typeof card.brand === 'string' &&
			card.brand.length > 0 &&
			typeof card.last4 === 'string' &&
			/^\d{4}$/.test( card.last4 )
		);
	}

	function isSamePreferredCard( firstCard, secondCard ) {
		return (
			( ! firstCard && ! secondCard ) ||
			( firstCard &&
				secondCard &&
				firstCard.brand === secondCard.brand &&
				firstCard.last4 === secondCard.last4 )
		);
	}

	function getCachedPreferredCard() {
		var parsed;

		try {
			parsed = JSON.parse(
				window.localStorage.getItem( preferredCardCacheKey )
			);
		} catch ( error ) {
			return null;
		}

		return isValidPreferredCard( parsed ) ? parsed : null;
	}

	function setCachedPreferredCard( card ) {
		try {
			if ( isValidPreferredCard( card ) ) {
				window.localStorage.setItem(
					preferredCardCacheKey,
					JSON.stringify( {
						brand: card.brand,
						last4: card.last4,
					} )
				);
				return;
			}

			window.localStorage.removeItem( preferredCardCacheKey );
		} catch ( error ) {}
	}

	function getPreferredCardDisplayName( preferredCard ) {
		var normalizedBrand = normalizeCardBrand( preferredCard.brand );

		return brandDisplayNames[ normalizedBrand ] || normalizedBrand;
	}

	function getWooPayConnectOrigin() {
		try {
			return new window.URL( config.woopayHost ).origin;
		} catch ( error ) {
			return '';
		}
	}

	function getWooPayConnectUrl() {
		var params;

		if ( ! config.woopayHost || ! window.URLSearchParams ) {
			return config.woopayHost ? config.woopayHost + '/connect/' : '';
		}

		params = new window.URLSearchParams( {
			source_url: window.location.href,
		} );

		if ( config.woopayMerchantId ) {
			params.append( 'blogId', config.woopayMerchantId );
		}

		if ( config.testMode !== undefined ) {
			params.append( 'testMode', config.testMode ? 'true' : 'false' );
		}

		return config.woopayHost + '/connect/?' + params.toString();
	}

	function resolveWooPayConnectCallback( callbackName, value ) {
		if ( ! wooPayConnectCallbacks[ callbackName ] ) {
			return;
		}

		wooPayConnectCallbacks[ callbackName ]( value );
		delete wooPayConnectCallbacks[ callbackName ];
	}

	function attachWooPayConnectListener() {
		if ( wooPayConnectListenerAttached ) {
			return;
		}

		window.addEventListener( 'message', function ( event ) {
			var data = event.data || {};
			var origin = getWooPayConnectOrigin();

			if ( ! origin || event.origin !== origin ) {
				return;
			}

			switch ( data.action ) {
				case 'set_preemptive_session_data_success':
					resolveWooPayConnectCallback(
						'setPreemptiveSessionData',
						data.value || {}
					);
					break;
				case 'set_preemptive_session_data_error':
					resolveWooPayConnectCallback( 'setPreemptiveSessionData', {
						is_error: true,
					} );
					break;
				case 'get_preferred_payment_method_success':
					resolveWooPayConnectCallback(
						'getPreferredPaymentMethod',
						data.value || null
					);
					break;
				case 'get_is_user_logged_in_success':
					resolveWooPayConnectCallback(
						'getIsUserLoggedIn',
						!! data.value
					);
					break;
				case 'get_is_woopay_reachable_success':
					resolveWooPayConnectCallback(
						'getIsWooPayReachable',
						!! data.value
					);
					break;
				case 'get_encrypted_data_success':
					resolveWooPayConnectCallback(
						'getEncryptedData',
						data.value || null
					);
					break;
				case 'set_redirect_session_data_success':
					resolveWooPayConnectCallback(
						'setRedirectSessionData',
						data.value || null
					);
					break;
				case 'set_redirect_session_data_error':
					resolveWooPayConnectCallback( 'setRedirectSessionData', null );
					break;
			}
		} );

		wooPayConnectListenerAttached = true;
	}

	function getWooPayConnectPostMessage() {
		var existingIframe;

		existingIframe = document.getElementById( 'woopay-connect-iframe' );
		if ( wooPayConnectPostMessagePromise && existingIframe ) {
			return wooPayConnectPostMessagePromise;
		}

		if ( wooPayConnectPostMessagePromise && ! existingIframe ) {
			wooPayConnectPostMessagePromise = null;
			preferredCardFetchPromise = null;
		}

		if ( existingIframe ) {
			return Promise.resolve( function ( message ) {
				existingIframe.contentWindow.postMessage(
					message,
					config.woopayHost
				);
			} );
		}

		if ( ! config.woopayHost ) {
			return Promise.reject();
		}

		wooPayConnectPostMessagePromise = new Promise( function ( resolve ) {
			var iframe = document.createElement( 'iframe' );
			iframe.id = 'woopay-connect-iframe';
			iframe.src = getWooPayConnectUrl();
			iframe.height = 0;
			iframe.width = 0;
			iframe.title = 'WooPay Connect';
			iframe.style.border = 'none';
			iframe.style.display = 'block';
			iframe.style.visibility = 'hidden';
			iframe.style.position = 'fixed';
			iframe.style.height = '0';
			iframe.style.width = '0';
			iframe.style.pointerEvents = 'none';
			iframe.addEventListener( 'load', function () {
				resolve( function ( message ) {
					iframe.contentWindow.postMessage(
						message,
						config.woopayHost
					);
				} );
			} );

			document.body.appendChild( iframe );
		} );

		return wooPayConnectPostMessagePromise;
	}

	function sendWooPayConnectMessage( message, callbackName, fallback, timeout ) {
		attachWooPayConnectListener();

		return new Promise( function ( resolve ) {
			var timeoutId;
			var settled = false;

			wooPayConnectCallbacks[ callbackName ] = function ( value ) {
				if ( settled ) {
					return;
				}

				settled = true;
				if ( timeoutId ) {
					window.clearTimeout( timeoutId );
				}
				resolve( value );
			};

			if ( timeout ) {
				timeoutId = window.setTimeout( function () {
					resolveWooPayConnectCallback( callbackName, fallback );
				}, wooPayConnectTimeout );
			}

			getWooPayConnectPostMessage()
				.then( function ( postMessage ) {
					if ( wooPayConnectCallbacks[ callbackName ] ) {
						postMessage( message );
					}
				} )
				.catch( function () {
					resolveWooPayConnectCallback( callbackName, fallback );
				} );
		} );
	}

	function sendPreemptiveSessionDataToWooPay( sessionData ) {
		return sendWooPayConnectMessage(
			{
				action: 'setPreemptiveSessionData',
				value: sessionData,
			},
			'setPreemptiveSessionData',
			{
				is_error: true,
			},
			true
		);
	}

	function fetchPreferredCardFromWooPay() {
		if (
			preferredCardFetchPromise &&
			! document.getElementById( 'woopay-connect-iframe' )
		) {
			preferredCardFetchPromise = null;
		}

		if ( preferredCardFetchPromise ) {
			return preferredCardFetchPromise;
		}

		preferredCardFetchPromise = sendWooPayConnectMessage(
			{
				action: 'getPreferredPaymentMethod',
			},
			'getPreferredPaymentMethod',
			null,
			true
		).then( function ( card ) {
			card = isValidPreferredCard( card ) ? card : null;
			setCachedPreferredCard( card );

			if ( ! isSamePreferredCard( card, currentPreferredCard ) ) {
				currentPreferredCard = card;
				renderWooPayExpressButton();
			}

			return card;
		} );

		return preferredCardFetchPromise;
	}

	currentPreferredCard = getCachedPreferredCard();

	function getWooPayViewport() {
		return window.innerWidth && window.innerWidth < 783
			? 'mobile'
			: 'desktop';
	}

	function postWooPayAjax( endpoint, data ) {
		var url = buildWooPayAjaxUrl( endpoint );

		if ( ! url ) {
			return null;
		}

		return $.post( url, data );
	}

	function getTrackingAjaxUrl() {
		return config.ajaxUrl || config.ajax_url;
	}

	function getTrackingNonce() {
		if ( config.platformTrackerNonce || config.platform_tracker_nonce ) {
			return config.platformTrackerNonce || config.platform_tracker_nonce;
		}

		return config.nonce && config.nonce.platform_tracker;
	}

	function recordUserEvent( eventName, eventProperties ) {
		var ajaxUrl = getTrackingAjaxUrl();
		var nonce = getTrackingNonce();
		var body;

		if (
			! eventName ||
			config.isShopperTrackingEnabled === false ||
			config.is_shopper_tracking_enabled === false ||
			! ajaxUrl ||
			! nonce ||
			! window.fetch ||
			! window.FormData
		) {
			return;
		}

		body = new window.FormData();
		body.append( 'tracksNonce', nonce );
		body.append( 'action', 'platform_tracks' );
		body.append( 'tracksEventName', eventName );
		body.append( 'tracksEventProp', JSON.stringify( eventProperties || {} ) );

		window.fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
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
		var cookies = document.cookie ? document.cookie.split( ';' ) : [];
		var hasSkipCookie = cookies.some( function ( cookie ) {
			return cookie.trim() === 'skip_woopay=1';
		} );

		if ( hasSkipCookie ) {
			document.cookie =
				'skip_woopay=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
		}
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

	function isValidEncryptedIdentity( encryptedData ) {
		return (
			encryptedData &&
			typeof encryptedData.data === 'string' &&
			encryptedData.data.length > 0 &&
			typeof encryptedData.iv === 'string' &&
			encryptedData.iv.length > 0 &&
			typeof encryptedData.hash === 'string' &&
			encryptedData.hash.length > 0
		);
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
			var request = postWooPayAjax( 'get_woopay_session', {
				_ajax_nonce: config.woopaySessionNonce || '',
				encrypted_data: encryptedData,
			} );

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
				.then( function ( encryptedData ) {
					if ( ! isValidEncryptedIdentity( encryptedData ) ) {
						return Promise.reject();
					}

					return requestWooPaySession( encryptedData );
				} )
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

	function attachProceedToCheckoutTracking() {
		if ( getTrackingSource() !== 'cart' ) {
			return;
		}
		document.body.addEventListener(
			'click',
			function ( event ) {
				if (
					event.target.closest &&
					event.target.closest( proceedToCheckoutSelector )
				) {
					recordUserEvent( 'wcpay_proceed_to_checkout_button_click', {
						woopay_direct_checkout:
							!! config.isWooPayDirectCheckoutEnabled &&
							( document.cookie || '' ).indexOf(
								'skip_woopay=1'
							) === -1,
					} );
				}
			},
			true
		);
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

	function initializeDirectCheckout() {
		if ( ! config.isWooPayDirectCheckoutEnabled ) {
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

	function validateEmail( value ) {
		var pattern = new RegExp(
			/^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[0-9a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]){2,}\.?$/i
		);

		return pattern.test( value );
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

	// The plugin's initWooPay(): one init_woopay request in flight per page,
	// since WooPay's <Login> sends the redirect message twice.
	function requestInitWooPay( userEmail, userSession ) {
		var themed = !! config.isWooPayGlobalThemeSupportEnabled;

		if ( isInitWooPayRequesting ) {
			return undefined;
		}

		isInitWooPayRequesting = true;

		return window
			.fetch( buildWooPayAjaxUrl( 'init_woopay' ), {
				method: 'POST',
				body: appendFormData( new window.FormData(), {
					_wpnonce: config.initWooPayNonce || '',
					appearance: themed ? config.woopayAppearance : null,
					font_rules: themed ? config.woopayFontRules : null,
					email: userEmail,
					user_session: userSession,
					order_id: config.order_id,
					key: config.key,
					billing_email: config.billing_email,
				} ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.finally( function () {
				isInitWooPayRequesting = false;
			} );
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

	// Port of the plugin's express-button expressCheckoutIframe(): opens the
	// WooPay one-time-code iframe as a centered modal. The verified code
	// hands the platform session back through postMessage, and init_woopay
	// starts WooPay with it.
	function expressCheckoutIframe( context, emailSelector ) {
		var fullScreenModalBreakpoint = 768;
		var woopayHost = config.woopayHost || '';
		var iframeHeaderValue = true;
		var tracksUserId;
		var userEmail = '';
		var iframeWrapper = document.createElement( 'div' );
		var iframe = document.createElement( 'iframe' );

		iframeWrapper.setAttribute( 'role', 'dialog' );
		iframeWrapper.setAttribute( 'aria-modal', 'true' );
		iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

		iframe.title = config.woopayOtpIframeTitle || '';
		iframe.classList.add( 'woopay-otp-iframe' );
		// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
		iframe.classList.add( 'intrinsic-ignore' );

		// Tracks the iframe header state; the default must match the platform's.
		function getWindowSize() {
			if (
				( fullScreenModalBreakpoint <= window.innerWidth &&
					iframeHeaderValue ) ||
				( fullScreenModalBreakpoint > window.innerWidth &&
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
		}

		// Center the iframe, or fill the window below the breakpoint.
		function setPopoverPosition() {
			var iframeRect;

			if ( fullScreenModalBreakpoint > window.innerWidth ) {
				iframe.style.left = '0';
				iframe.style.right = '';
				iframe.style.top = '0';
				return;
			}

			iframeRect = iframe.getBoundingClientRect();
			iframe.style.top =
				Math.floor( window.innerHeight / 2 - iframeRect.height / 2 ) + 'px';
			iframe.style.left =
				Math.floor( window.innerWidth / 2 - iframeRect.width / 2 ) + 'px';
		}

		function closeIframe() {
			window.removeEventListener( 'resize', getWindowSize );
			window.removeEventListener( 'resize', setPopoverPosition );
			window.removeEventListener( 'pageshow', onPageShow );
			window.removeEventListener( 'message', onMessage );
			document.removeEventListener( 'keyup', onKeyUp );

			iframeWrapper.remove();
			iframe.classList.remove( 'open' );

			document.body.style.overflow = '';
		}

		function onInitWooPayResponse( response ) {
			// The iframe was closed meanwhile.
			if ( ! document.querySelector( '.woopay-otp-iframe' ) ) {
				return;
			}

			if ( response && response.result === 'success' ) {
				navigate( response.url );
				return;
			}

			showExpressErrorMessage(
				context,
				config.woopayExpressUnavailableMessage || ''
			);
			closeIframe();
		}

		function onMessage( event ) {
			var data = event.data || {};
			var request;

			if ( ! woopayHost || event.origin !== getWooPayConnectOrigin() ) {
				return;
			}

			switch ( data.action ) {
				case 'otp_email_submitted':
					userEmail = data.userEmail;
					break;
				case 'redirect_to_woopay_skip_session_init':
					if ( data.redirectUrl ) {
						navigate( data.redirectUrl );
					}
					break;
				case 'redirect_to_platform_checkout':
				case 'redirect_to_woopay':
					request = requestInitWooPay(
						userEmail || data.userEmail,
						data.platformCheckoutUserSession
					);

					// The second redirect message finds a request in flight.
					if ( request ) {
						request.then( onInitWooPayResponse, function () {
							onInitWooPayResponse( null );
						} );
					}
					break;
				case 'close_modal':
					closeIframe();
					break;
				case 'iframe_height':
					if ( data.height > 300 ) {
						if ( fullScreenModalBreakpoint <= window.innerWidth ) {
							iframe.style.height = data.height + 'px';
							iframe.style.top =
								Math.floor( window.innerHeight / 2 - data.height / 2 ) +
								'px';
						} else {
							iframe.style.height = '';
							iframe.style.top = '';
						}
					}
					break;
				default:
				// Only respond to expected actions (otp_validation_failed included).
			}
		}

		function onPageShow( event ) {
			if ( event.persisted ) {
				// Safari needs the iframe closed on bfcache restore.
				closeIframe();
			}
		}

		function onKeyUp( event ) {
			if ( event.key === 'Escape' ) {
				closeIframe();
			}
		}

		function openIframe( email ) {
			var urlParams = new window.URLSearchParams();

			// Only one OTP iframe at a time.
			if ( document.querySelector( '.woopay-otp-iframe' ) ) {
				return;
			}

			window.addEventListener( 'pageshow', onPageShow );
			window.addEventListener( 'message', onMessage );
			document.addEventListener( 'keyup', onKeyUp );

			// wp_localize_script serves the boolean as '1' or ''.
			urlParams.append( 'testMode', !! config.testMode );
			urlParams.append(
				'needsHeader',
				fullScreenModalBreakpoint > window.innerWidth
			);
			urlParams.append( 'wcpayVersion', config.wcpayVersionNumber );

			if ( email && validateEmail( email ) ) {
				userEmail = email;
				urlParams.append( 'email', email );
			}
			urlParams.append(
				'is_blocks',
				!! ( window.wcSettings && window.wcSettings.wcBlocksConfig )
			);
			urlParams.append( 'is_express', 'true' );
			urlParams.append( 'express_context', context );
			urlParams.append( 'source_url', window.location.href );
			urlParams.append(
				'viewport',
				document.documentElement.clientWidth +
					'x' +
					document.documentElement.clientHeight
			);

			if ( tracksUserId ) {
				urlParams.append( 'tracksUserIdentity', tracksUserId );
			}

			iframe.src = woopayHost + '/otp/?' + urlParams.toString();

			document.body.insertBefore( iframeWrapper, null );

			setPopoverPosition();

			iframe.focus();
		}

		iframe.addEventListener( 'load', function () {
			iframeHeaderValue = true;

			if ( config.isWoopayFirstPartyAuthEnabled ) {
				window
					.fetch( buildWooPayAjaxUrl( 'get_woopay_session' ), {
						method: 'POST',
						body: appendFormData( new window.FormData(), {
							_ajax_nonce: config.woopaySessionNonce || '',
							order_id: config.order_id,
							key: config.key,
							billing_email: config.billing_email,
							appearance: config.woopayAppearance,
						} ),
					} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( response ) {
						if ( response && response.data && response.data.session ) {
							iframe.contentWindow.postMessage(
								{ action: 'setSessionData', value: response },
								woopayHost
							);
						}
					} )
					.catch( function () {} );
			}

			getWindowSize();
			window.addEventListener( 'resize', getWindowSize );

			setPopoverPosition();
			window.addEventListener( 'resize', setPopoverPosition );

			iframe.classList.add( 'open' );
		} );

		iframeWrapper.insertBefore( iframe, null );
		iframeWrapper.addEventListener( 'click', closeIframe );

		return getTracksIdentity().then( function ( identity ) {
			var emailInput = document.querySelector( emailSelector );

			tracksUserId = identity;
			openIframe(
				( emailInput && emailInput.value ) || config.woopaySessionEmail
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
				continueWooPay();
				return;
			}

			sendPreemptiveSessionDataToWooPay( response ).then( function (
				sessionResponse
			) {
				if ( sessionResponse && sessionResponse.is_error ) {
					continueWooPay();
					return;
				}

				if ( sessionResponse && sessionResponse.redirect_url ) {
					navigate( sessionResponse.redirect_url );
				}
				isWooPayRequesting = false;
			} );
		} );

		if ( request.fail ) {
			request.fail( function () {
				setError( config.confirmationErrorMessage || '' );
				isWooPayRequesting = false;
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
			request.fail( function () {
				setError( config.confirmationErrorMessage || '' );
				isWooPayRequesting = false;
			} );
		}
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
			config.isWoopayFirstPartyAuthEnabled
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
			default: 'WooPay',
			buy: 'Buy with WooPay',
			donate: 'Donate with WooPay',
			book: 'Book with WooPay',
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
		var label = preferredCard
			? 'WooPay with ' +
			  getPreferredCardDisplayName( preferredCard ) +
			  ' ending in ' +
			  preferredCard.last4
			: getWooPayButtonLabel( type );
		var button;

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
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'data-type', type );
		button.setAttribute( 'data-theme', settings.theme || 'dark' );
		button.setAttribute( 'data-size', normalizeButtonSize( settings ) );
		button.style.height = ( settings.height || '48' ) + 'px';
		button.style.borderRadius = ( settings.radius || '4' ) + 'px';
		button.innerHTML = getWooPayButtonContent( settings, preferredCard );
		button.addEventListener( 'click', initWooPay );

		container.innerHTML = '';
		container.appendChild( button );

		recordUserEvent( 'woopay_button_load', {
			source: getTrackingSource(),
		} );
	}

	function sendWooPayPhoneData( empty ) {
		var checkbox = document.querySelector(
			'input[name="save_user_in_woopay"]'
		);
		var phoneField = document.querySelector(
			'input[name="woopay_user_phone_field[full]"]'
		);
		var sourceField = document.querySelector(
			'input[name="woopay_source_url"]'
		);
		var viewportField = document.querySelector(
			'input[name="woopay_viewport"]'
		);
		var hasPhone = !! (
			checkbox &&
			checkbox.checked &&
			phoneField &&
			phoneField.value.trim()
		);

		if ( hasPhone && ! hasPhoneForMobileEnter ) {
			recordUserEvent( 'checkout_woopay_save_my_info_mobile_enter' );
		}
		hasPhoneForMobileEnter = hasPhone;

		postWooPayAjax( 'set_woopay_phone_number', {
			_wpnonce: config.woopaySessionNonce || '',
			empty: empty ? 'true' : '',
			save_user_in_woopay:
				checkbox && checkbox.checked ? 'true' : 'false',
			woopay_source_url: sourceField ? sourceField.value : window.location.href,
			woopay_is_blocks: 'false',
			woopay_viewport: viewportField ? viewportField.value : getWooPayViewport(),
			woopay_user_phone_field: {
				full: phoneField ? phoneField.value : '',
			},
		} );
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

		if (
			! form ||
			! config.isWooPayEnabled ||
			! config.forceNetworkSavedCards ||
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
			'<input type="tel" id="woopay_user_phone_field_full" name="woopay_user_phone_field[full]" autocomplete="tel" />' +
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
		phoneField = container.querySelector(
			'input[name="woopay_user_phone_field[full]"]'
		);
		sourceField = container.querySelector(
			'input[name="woopay_source_url"]'
		);
		viewportField = container.querySelector(
			'input[name="woopay_viewport"]'
		);

		checkbox.checked = !! config.PRE_CHECK_SAVE_MY_INFO;
		fields.hidden = ! checkbox.checked;
		sourceField.value = window.location.href;
		viewportField.value = getWooPayViewport();

		recordUserEvent( 'checkout_woopay_save_my_info_offered' );
		if ( checkbox.checked ) {
			recordUserEvent( 'checkout_save_my_info_click', {
				status: 'checked',
			} );
		}

		checkbox.addEventListener( 'change', function () {
			fields.hidden = ! checkbox.checked;
			recordUserEvent( 'checkout_save_my_info_click', {
				status: checkbox.checked ? 'checked' : 'unchecked',
			} );
			sendWooPayPhoneData( false );
		} );
		phoneField.addEventListener( 'blur', function () {
			sendWooPayPhoneData( false );
		} );
	}

	$( document.body ).on( 'updated_checkout', function () {
		renderWooPayExpressButton();
		renderWooPaySaveUserFields();
	} );
	$( document.body ).on( 'updated_cart_totals', function () {
		if ( config.isWooPayDirectCheckoutEnabled ) {
			attachDirectCheckoutListeners();
		}
	} );

	$( function () {
		renderWooPayExpressButton();
		renderWooPaySaveUserFields();
		fetchPreferredCardFromWooPay();
		initializeDirectCheckout();
		attachProceedToCheckoutTracking();
	} );

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports.__test__ = {
			setNavigate: function ( callback ) {
				navigate = callback;
			},
		};
	}
} )( jQuery, window, document );
