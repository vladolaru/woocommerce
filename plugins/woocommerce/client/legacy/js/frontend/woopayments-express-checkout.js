/* global jQuery, wcpayExpressCheckoutParams */
( function ( $, window, document ) {
	'use strict';

	var config = window.wcpayExpressCheckoutParams || {};
	var cachedCartData = null;
	var elements = null;
	var expressElement = null;
	var tokenizedCartSession = null;
	// An open Apple Pay / Google Pay sheet is locked to the currency it opened
	// with; remember it to detect cart-currency drift mid-sheet.
	var elementCurrency = null;
	var productAddToCartPromise = Promise.resolve();
	var productAddToCartErrorMessage = '';
	// What the product-page button was priced from when the sheet opened; the click puts the sheet's working cart
	// in `cachedCartData`, and closing the sheet brings this back.
	var productPricedCartData = null;
	// Counts product-page sheet opens, so the late answers of a closed sheet's cart requests leave a newer sheet's cart
	// session alone.
	var productCartGeneration = 0;
	// The last address the shopper chose in the open sheet; cancel puts it into the page form.
	var lastSelectedAddress = null;
	var resolvedProductCurrency = '';
	var productCurrencyResolutionPromise = null;
	var productEnabledMethodCeiling = Array.isArray( config.enabled_methods )
		? config.enabled_methods.slice()
		: [];
	var iapiPreviewRequestId = 0;
	var iapiLastSelection = null;
	var iapiSelectionRefreshTimer = null;
	var iapiObserverInstalled = false;
	var expressButtonAvailable = false;
	var variationChangeAwaitsButton = false;
	var navigate = function ( url ) {
		window.location.href = url;
	};

	// This const defines the max number of shipping options that can be handled by the ECE.
	// More than 9 options will prevent the UI from behaving correctly.
	var SHIPPING_RATES_UPPER_LIMIT_COUNT = 9;

	var GENERIC_PAYMENT_ERROR_MESSAGE =
		'Unable to process this payment, please try again.';
	var ERRORS_BEFORE_PERSISTENCE = [
		'woocommerce_rest_invalid_billing_email',
		'woocommerce_rest_invalid_order',
		'woocommerce_rest_invalid_user',
		'woocommerce_rest_invalid_address',
		'woocommerce_rest_invalid_address_country',
	];

	var ORDER_ATTRIBUTION_ELEMENT_ID =
		'wcpay-express-checkout__order-attribution-inputs';

	function getApiFetch() {
		return window.wp && window.wp.apiFetch;
	}

	function getButtonContext() {
		return (
			config.button_context ||
			( config.button && config.button.context ) ||
			'checkout'
		);
	}

	function isProduct() {
		return getButtonContext() === 'product';
	}

	function isPayForOrder() {
		return getButtonContext() === 'pay_for_order' && config.order_id;
	}

	function isBlockSurface() {
		return config.has_block && getButtonContext() !== 'pay_for_order';
	}

	function isPaymentRequestEnabled() {
		return (
			Array.isArray( config.enabled_methods ) &&
			config.enabled_methods.indexOf( 'payment_request' ) !== -1
		);
	}

	function isAmazonPayEnabled() {
		return (
			Array.isArray( config.enabled_methods ) &&
			config.enabled_methods.indexOf( 'amazon_pay' ) !== -1 &&
			getPaymentMethodTypes().indexOf( 'amazon_pay' ) !== -1
		);
	}

	function getPaymentMethodTypes() {
		var paymentMethodTypes = Array.isArray( config.payment_method_types )
			? config.payment_method_types
			: [ 'card' ];

		paymentMethodTypes = paymentMethodTypes.filter( function ( type ) {
			return [ 'card', 'amazon_pay' ].indexOf( type ) !== -1;
		} );

		return paymentMethodTypes.length ? paymentMethodTypes : [ 'card' ];
	}

	function normalizeCurrency( currency ) {
		return typeof currency === 'string' && currency
			? currency.toLowerCase()
			: '';
	}

	function getLocalizedProductCurrency() {
		return normalizeCurrency(
			( config.product && config.product.currency ) ||
				( config.checkout && config.checkout.currency_code )
		);
	}

	function getStoreApiCurrency() {
		return (
			isProduct() && resolvedProductCurrency
				? resolvedProductCurrency
				: ( config.checkout && config.checkout.currency_code ) || ''
		).toUpperCase();
	}

	function resolveProductCurrency( fallback ) {
		var currency = normalizeCurrency( fallback );
		var ready =
			window.wcpayAsyncCurrency && window.wcpayAsyncCurrency.ready;

		if ( ! ready || typeof ready.then !== 'function' ) {
			return Promise.resolve( currency );
		}

		return new Promise( function ( resolve ) {
			var watchdog = window.setTimeout( function () {
				resolve( currency );
			}, 6000 );

			Promise.resolve( ready ).then(
				function ( resolved ) {
					window.clearTimeout( watchdog );
					resolve( normalizeCurrency( resolved ) || currency );
				},
				function () {
					window.clearTimeout( watchdog );
					resolve( currency );
				}
			);
		} );
	}

	function applyProductPreviewMethods( cartData ) {
		var methods =
			cartData &&
			cartData.extensions &&
			cartData.extensions.wcpay &&
			cartData.extensions.wcpay.express_checkout_methods;

		if ( ! Array.isArray( methods ) ) {
			return false;
		}

		methods = methods.filter( function ( method, index ) {
			return (
				[ 'payment_request', 'amazon_pay' ].indexOf( method ) !== -1 &&
				productEnabledMethodCeiling.indexOf( method ) !== -1 &&
				methods.indexOf( method ) === index
			);
		} );

		config.enabled_methods = methods;
		config.payment_method_types = methods.reduce( function (
			types,
			method
		) {
			if ( 'payment_request' === method ) {
				types.push( 'card' );
			}
			if ( 'amazon_pay' === method ) {
				types.push( 'amazon_pay' );
			}
			return types;
		}, [] );

		return isPaymentRequestEnabled() || isAmazonPayEnabled();
	}

	function shouldUseConfirmationTokens() {
		// Defaults to true when the flag is absent; the platform's
		// ece_confirmation_tokens_disabled kill switch turns it off.
		return Boolean(
			config.flags &&
			config.flags.isEceUsingConfirmationTokens !== undefined
				? config.flags.isEceUsingConfirmationTokens
				: true
		);
	}

	function getStoreApiHeaders(
		includeSessionNonce,
		includeTokenizedCartNonce
	) {
		var nonce = config.nonce || {};
		var headers = {};

		if ( nonce.store_api_nonce ) {
			headers.Nonce = nonce.store_api_nonce;
		}

		if (
			includeTokenizedCartNonce !== false &&
			nonce.tokenized_cart_nonce
		) {
			headers[ 'X-WooPayments-Tokenized-Cart-Nonce' ] =
				nonce.tokenized_cart_nonce;
		}

		if ( includeSessionNonce && nonce.tokenized_cart_session_nonce ) {
			headers[ 'X-WooPayments-Tokenized-Cart-Session-Nonce' ] =
				nonce.tokenized_cart_session_nonce;
		}

		if ( isProduct() && tokenizedCartSession !== null ) {
			headers[ 'X-WooPayments-Tokenized-Cart-Session' ] =
				tokenizedCartSession;
		}

		return headers;
	}

	/**
	 * Read a product-page Store API response: keep its nonce and, unless told otherwise, its tokenized cart session.
	 *
	 * @param {Object}  response     The raw response (`parse: false`).
	 * @param {boolean} adoptSession False when the session in the response must not replace the current one.
	 * @return {*} The response body.
	 */
	function normalizeStoreApiResponse( response, adoptSession ) {
		var nextNonce;
		var nextSession;

		if (
			! isProduct() ||
			! response ||
			! response.headers ||
			typeof response.headers.get !== 'function'
		) {
			return response;
		}

		nextNonce = response.headers.get( 'Nonce' );
		if ( nextNonce ) {
			config.nonce = config.nonce || {};
			config.nonce.store_api_nonce = nextNonce;
		}

		nextSession = response.headers.get(
			'X-WooPayments-Tokenized-Cart-Session'
		);
		if (
			adoptSession !== false &&
			nextSession !== null &&
			nextSession !== undefined
		) {
			tokenizedCartSession = nextSession;
		}

		if ( typeof response.json === 'function' ) {
			return response.json();
		}

		return response;
	}

	function addQueryArgs( path, args ) {
		var query = Object.keys( args )
			.filter( function ( key ) {
				return (
					args[ key ] !== undefined &&
					args[ key ] !== null &&
					args[ key ] !== ''
				);
			} )
			.map( function ( key ) {
				return (
					encodeURIComponent( key ) +
					'=' +
					encodeURIComponent( args[ key ] )
				);
			} )
			.join( '&' );

		return query ? path + '?' + query : path;
	}

	/**
	 * Send a Store API cart request with the tokenized cart headers.
	 *
	 * @param {Object}   options        apiFetch options.
	 * @param {Function} [adoptSession] Asked when the answer arrives whether its tokenized session replaces the
	 *                                  current one (always, when omitted).
	 * @return {Promise} The response body.
	 */
	function requestCart( options, adoptSession ) {
		var apiFetch = getApiFetch();
		var includeSessionNonce = isProduct();
		var requestOptions = Object.assign( {}, options, {
			// Pin the currency the page was rendered with, so cache-optimized
			// or geolocation-driven multi-currency setups can't serve the
			// request in a different currency than the wallet sheet shows.
			path: addQueryArgs( options.path, {
				currency: getStoreApiCurrency(),
			} ),
			headers: Object.assign(
				{},
				getStoreApiHeaders( includeSessionNonce, true ),
				options.headers || {}
			),
		} );

		if ( isProduct() ) {
			requestOptions.parse = false;
		}

		return apiFetch( requestOptions ).then( function ( response ) {
			return normalizeStoreApiResponse(
				response,
				! adoptSession || adoptSession()
			);
		} );
	}

	function requestOrder( options ) {
		var apiFetch = getApiFetch();

		return apiFetch(
			Object.assign( {}, options, {
				headers: Object.assign(
					{},
					getStoreApiHeaders( false, false ),
					options.headers || {}
				),
			} )
		);
	}

	function getCart() {
		if ( isPayForOrder() ) {
			return requestOrder( {
				method: 'GET',
				path: addQueryArgs( '/wc/store/v1/order/' + config.order_id, {
					key: config.key,
					billing_email: config.billing_email,
				} ),
			} );
		}

		return requestCart( {
			method: 'GET',
			path: '/wc/store/v1/cart',
		} );
	}

	function applyWpFilters( hookName, value, extraArg, secondExtraArg ) {
		if (
			window.wp &&
			window.wp.hooks &&
			typeof window.wp.hooks.applyFilters === 'function'
		) {
			return window.wp.hooks.applyFilters(
				hookName,
				value,
				extraArg,
				secondExtraArg
			);
		}

		return value;
	}

	function decodeEntities( text ) {
		var textarea;

		if ( ! text || String( text ).indexOf( '&' ) === -1 ) {
			return text;
		}

		textarea = document.createElement( 'textarea' );
		textarea.innerHTML = text;

		return textarea.value;
	}

	function displayPricesIncludeTax() {
		return Boolean(
			config.checkout && config.checkout.display_prices_with_tax
		);
	}

	/**
	 * GooglePay/ApplePay expect prices in the smallest unit Stripe bills in.
	 * The Store API reports amounts in WooCommerce minor units
	 * (`currency_minor_unit`), which can differ from what Stripe expects
	 * (e.g. JPY configured with two WooCommerce decimals against Stripe's
	 * zero-decimal yen). Rescale, rounding only when narrowing precision;
	 * widening and same-scale conversions are already integer-exact.
	 *
	 * @param {number} price       The price to format.
	 * @param {Object} priceObject The price object returned by the Store API.
	 * @return {number} The price amount in the unit Stripe expects.
	 */
	function transformPrice( price, priceObject ) {
		var stripeMinorUnit =
			config.checkout && config.checkout.stripe_minor_unit !== undefined
				? config.checkout.stripe_minor_unit
				: 2;
		var currencyMinorUnit =
			priceObject && priceObject.currency_minor_unit !== undefined
				? priceObject.currency_minor_unit
				: 2;
		var converted =
			price * Math.pow( 10, stripeMinorUnit - currencyMinorUnit );

		return stripeMinorUnit < currencyMinorUnit
			? Math.round( converted )
			: converted;
	}

	function isSubscriptionData( subscriptionData ) {
		if ( Array.isArray( subscriptionData ) ) {
			return subscriptionData.length > 0;
		}

		if (
			typeof subscriptionData !== 'object' ||
			subscriptionData === null
		) {
			return false;
		}

		return (
			typeof subscriptionData.billing_period === 'string' &&
			subscriptionData.billing_period.length > 0 &&
			typeof subscriptionData.billing_interval === 'number' &&
			subscriptionData.billing_interval > 0
		);
	}

	/**
	 * Tell whether the cart contains any subscription schedule (trial or
	 * recurring). WC Subscriptions exposes subscription data on the Store API
	 * response in two places: `extensions.subscriptions` on the cart (initial
	 * purchases; empty for renewal carts) and on each cart item
	 * (renewals/resubscribes/switches). Checking both keeps the detection
	 * robust across cart shapes.
	 */
	function cartHasAnySubscription( cartData ) {
		var schedules = cartData && cartData.extensions
			? cartData.extensions.subscriptions
			: undefined;

		if ( Array.isArray( schedules ) && schedules.length > 0 ) {
			return true;
		}

		if ( ! cartData || ! Array.isArray( cartData.items ) ) {
			return false;
		}

		return cartData.items.some( function ( item ) {
			return isSubscriptionData(
				item && item.extensions
					? item.extensions.subscriptions
					: undefined
			);
		} );
	}

	function getSetupFutureUsageForCart( cartData ) {
		return cartHasAnySubscription( cartData ) ? 'off_session' : null;
	}

	function cartCurrencyDriftedFromElement( cartData ) {
		var cartCurrency =
			cartData && cartData.totals && cartData.totals.currency_code
				? cartData.totals.currency_code.toLowerCase()
				: '';

		return Boolean(
			elementCurrency && cartCurrency && elementCurrency !== cartCurrency
		);
	}

	// `event.reject()` only surfaces the wallet's generic "address
	// unsupported" message, so the real reason is also surfaced as a notice.
	function getCurrencyMismatchMessage( cartData ) {
		var from = ( elementCurrency || '' ).toUpperCase();
		var to = cartData.totals.currency_code.toUpperCase();

		return (
			'This express payment started in ' +
			from +
			' and cannot switch to ' +
			to +
			' for the address you selected. Choose a different shipping ' +
			'address, or use the regular checkout to pay in ' +
			to +
			'.'
		);
	}

	function getTotalAmount( cartData ) {
		if (
			cartData &&
			cartData.total &&
			cartData.total.amount !== undefined
		) {
			// Product-page payload amounts are prepared server-side in
			// Stripe minor units already.
			return Math.max( parseInt( cartData.total.amount || 0, 10 ), 0 );
		}

		var totals = cartData && cartData.totals ? cartData.totals : {};
		var total = parseInt( totals.total_price || 0, 10 );
		var refund = parseInt( totals.total_refund || 0, 10 );

		return Math.max(
			applyWpFilters(
				'wcpay.express-checkout.total-amount',
				transformPrice( total - refund, totals ),
				cartData
			),
			0
		);
	}

	function getCurrency( cartData ) {
		return (
			( cartData && cartData.currency ) ||
			( cartData && cartData.total && cartData.total.currency ) ||
			( cartData && cartData.totals && cartData.totals.currency_code ) ||
			( config.checkout && config.checkout.currency_code ) ||
			'usd'
		).toLowerCase();
	}

	function clampButtonHeight( height ) {
		var parsedHeight = parseInt( height, 10 );

		if ( ! Number.isFinite( parsedHeight ) ) {
			return 48;
		}

		return Math.min( Math.max( parsedHeight, 40 ), 55 );
	}

	function getButtonTheme( method ) {
		var theme = ( config.button && config.button.theme ) || 'dark';

		if ( theme === 'light-outline' ) {
			return method === 'applePay' ? 'white-outline' : 'white';
		}

		if ( theme === 'light' ) {
			return 'white';
		}

		return 'black';
	}

	function getButtonType( method ) {
		var type = ( config.button && config.button.type ) || 'default';

		if ( type === 'default' ) {
			return 'plain';
		}

		if ( method === 'applePay' ) {
			return [ 'buy', 'donate', 'book', 'check-out' ].indexOf( type ) !==
				-1
				? type
				: 'plain';
		}

		return [ 'buy', 'donate', 'book', 'checkout' ].indexOf( type ) !== -1
			? type
			: 'plain';
	}

	function getButtonOptions() {
		return {
			buttonHeight: clampButtonHeight(
				config.button && config.button.height
			),
			buttonTheme: {
				applePay: getButtonTheme( 'applePay' ),
				googlePay: getButtonTheme( 'googlePay' ),
			},
			buttonType: {
				applePay: getButtonType( 'applePay' ),
				googlePay: getButtonType( 'googlePay' ),
			},
			layout: {
				overflow: 'never',
			},
			paymentMethods: {
				applePay: isPaymentRequestEnabled() ? 'always' : 'never',
				googlePay: isPaymentRequestEnabled() ? 'always' : 'never',
				link: 'never',
				paypal: 'never',
				amazonPay: isAmazonPayEnabled() ? 'auto' : 'never',
				klarna: 'never',
			},
		};
	}

	function getStripeElementsOptions( cartData ) {
		var amount = getTotalAmount( cartData );
		var currency = getCurrency( cartData );
		// The product payload shape carries no Store API extensions; fall back
		// to the localized subscription flag there.
		var setupFutureUsage =
			cartData && cartData.totals
				? getSetupFutureUsageForCart( cartData )
				: ( config.has_subscription ? 'off_session' : null );
		var useConfirmationTokens = shouldUseConfirmationTokens();
		var options;

		elementCurrency = currency;

		options = {
			mode: 'payment',
			amount: amount,
			currency: currency,
			loader: 'never',
		};

		// Without confirmation tokens, the payment method is created manually
		// at confirm time (https://docs.stripe.com/js/elements_object/create_without_intent).
		if ( ! useConfirmationTokens ) {
			options.paymentMethodCreation = 'manual';
			return options;
		}

		options.paymentMethodTypes = getPaymentMethodTypes();

		if ( config.is_manual_capture ) {
			options.captureMethod = 'manual';
		}

		if ( setupFutureUsage ) {
			options.setupFutureUsage = setupFutureUsage;
		}

		return options;
	}

	function getStripe() {
		if (
			! window.Stripe ||
			! config.stripe ||
			! config.stripe.publishableKey
		) {
			return null;
		}

		var betas = [ 'card_country_event_beta_1' ];

		if ( config.stripe.linkEnabled ) {
			// The reference client's express surfaces share the connected-account
			// Stripe instance, which opts into the Link autofill beta when Link
			// is enabled - mirror that from the server-computed flag.
			betas.push( 'link_autofill_modal_beta_1' );
		}

		return window.Stripe( config.stripe.publishableKey, {
			locale: config.stripe.locale || 'auto',
			stripeAccount: config.stripe.accountId,
			betas: betas,
		} );
	}

	function getTrackingNonce() {
		return (
			( config.nonce && config.nonce.platform_tracker ) ||
			config.platformTrackerNonce ||
			config.platform_tracker_nonce
		);
	}

	function recordUserEvent( eventName, eventProperties ) {
		var ajaxUrl = config.ajax_url || config.ajaxUrl;
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
		body.append(
			'tracksEventProp',
			JSON.stringify( eventProperties || {} )
		);

		window
			.fetch( ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.catch( function () {} );
	}

	function getExpressCheckoutLoadTrackingEvent( paymentMethod ) {
		var eventNames = {
			applePay: 'applepay_button_load',
			googlePay: 'gpay_button_load',
		};

		return eventNames[ paymentMethod ];
	}

	function getExpressCheckoutClickTrackingEvent( expressPaymentType ) {
		var eventNames = {
			apple_pay: 'applepay_button_click',
			google_pay: 'gpay_button_click',
		};

		return eventNames[ expressPaymentType ];
	}

	function recordExpressCheckoutLoadEvents( availablePaymentMethods ) {
		Object.keys( availablePaymentMethods || {} ).forEach( function (
			paymentMethod
		) {
			var eventName;

			if ( ! availablePaymentMethods[ paymentMethod ] ) {
				return;
			}

			eventName = getExpressCheckoutLoadTrackingEvent( paymentMethod );
			if ( eventName ) {
				recordUserEvent( eventName, {
					source: getButtonContext(),
				} );
			}
		} );
	}

	function recordExpressCheckoutClickEvent( expressPaymentType ) {
		var eventName =
			getExpressCheckoutClickTrackingEvent( expressPaymentType );

		if ( eventName ) {
			recordUserEvent( eventName, {
				source: getButtonContext(),
			} );
		}
	}

	// Client 11.1.0 shortcode-buttons-express/button-ui.js:43-45 (`addClass( 'is-ready' ).show()`).
	function showExpressContainer() {
		var container = document.getElementById(
			'wcpay-express-checkout-element'
		);

		if ( container ) {
			container.style.display = '';
			container.classList.add( 'is-ready' );
		}
	}

	// Client 11.1.0 shortcode-buttons-express/button-ui.js:39-41 (`removeClass( 'is-ready' ).hide()`): a hidden
	// wallet takes no space and no click.
	function hideExpressContainer() {
		var container = document.getElementById(
			'wcpay-express-checkout-element'
		);

		if ( container ) {
			container.classList.remove( 'is-ready' );
			container.style.display = 'none';
		}
	}

	function setSeparatorHidden( hidden ) {
		var separator = document.getElementById(
			'wcpay-express-checkout-button-separator'
		);

		if ( separator ) {
			separator.hidden = hidden;
		}
	}

	function showExpressButton() {
		showExpressContainer();
		setSeparatorHidden( false );
	}

	function hideExpressButton() {
		hideExpressContainer();
		setSeparatorHidden( true );
	}

	// Client 11.1.0 event-handlers.js:290-302: the whole page is blocked while the wallet sheet is open, so the form
	// behind it cannot change the sheet's cart. Without jQuery BlockUI on the page both do nothing (register row 238).
	function blockPage() {
		if ( typeof $.blockUI === 'function' ) {
			$.blockUI( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				},
			} );
		}
	}

	function unblockPage() {
		if ( typeof $.unblockUI === 'function' ) {
			$.unblockUI();
		}
	}

	/**
	 * Client 11.1.0 updateShortcodeField() (utils/shipping-fields.js:28-53): a country or state select takes the option
	 * whose value or name matches, any other field takes the value.
	 *
	 * @param {string} formSelector Selector of the form holding the field.
	 * @param {string} fieldName    Field name.
	 * @param {string} value        New value.
	 */
	function updateShortcodeField( formSelector, fieldName, value ) {
		var field = document.querySelector(
			formSelector + ' [name="' + fieldName + '"]'
		);
		var match;

		if ( ! field ) {
			return;
		}

		if ( field.tagName === 'SELECT' && /country|state/.test( fieldName ) ) {
			match = Array.from( field.options ).find( function ( option ) {
				return (
					option.value === value ||
					option.textContent.trim().toLowerCase() ===
						value.toLowerCase()
				);
			} );

			if ( match ) {
				field.value = match.value;
				$( field ).trigger( 'change' ).trigger( 'close' );
			}
		} else {
			field.value = value;
			$( field ).trigger( 'change' );
		}
	}

	/**
	 * Put the address chosen in the sheet into the classic cart's shipping calculator or the classic checkout's billing
	 * fields. Client 11.1.0 updateShippingAddressUI() and updateShortcodeShippingUI() (utils/shipping-fields.js:70-127):
	 * nothing on other pages, nothing for CA and GB, whose wallet postcodes are redacted.
	 *
	 * @param {Object} eventAddress The `shippingaddresschange` event's address.
	 */
	function updateShippingAddressUI( eventAddress ) {
		var context = getButtonContext();
		var address;
		var recalculateButton;

		if (
			( context !== 'cart' && context !== 'checkout' ) ||
			[ 'CA', 'GB' ].indexOf( eventAddress.country ) !== -1
		) {
			return;
		}

		address = normalizeAddress( eventAddress );
		[ 'country', 'state', 'city', 'postcode' ].forEach( function ( key ) {
			if ( ! address[ key ] ) {
				return;
			}

			if ( context === 'cart' ) {
				updateShortcodeField(
					'form.woocommerce-shipping-calculator',
					'calc_shipping_' + key,
					address[ key ]
				);
			} else {
				updateShortcodeField(
					'form.woocommerce-checkout',
					'billing_' + key,
					address[ key ]
				);
			}
		} );

		if ( context === 'cart' ) {
			recalculateButton = document.querySelector(
				'form.woocommerce-shipping-calculator [name="calc_shipping"]'
			);

			if ( recalculateButton ) {
				recalculateButton.click();
			}
		}
	}

	// Client 11.1.0 completePayment() (shortcode-buttons-express/index.js:214-217, event-handlers.js:316-318).
	function completePayment( url ) {
		blockPage();
		navigate( url );
	}

	function setError( message ) {
		var notices = document.querySelector( '.woocommerce-notices-wrapper' );
		var error;

		if ( ! notices || ! message ) {
			return;
		}

		error = document.createElement( 'div' );
		error.className = 'woocommerce-error';
		error.textContent = message;
		notices.appendChild( error );
	}

	function splitName( name ) {
		var parts = ( name || '' ).trim().split( /\s+/ ).filter( Boolean );

		return {
			first_name: parts.shift() || '',
			last_name: parts.join( ' ' ),
		};
	}

	function normalizeAddress( address, fallback ) {
		address = address || {};
		fallback = fallback || {};

		return {
			first_name: address.first_name || fallback.first_name || '',
			last_name: address.last_name || fallback.last_name || '',
			company: address.company || fallback.company || '',
			address_1:
				address.address_1 || address.line1 || fallback.address_1 || '',
			address_2:
				address.address_2 || address.line2 || fallback.address_2 || '',
			city: address.city || fallback.city || '',
			state: address.state || fallback.state || '',
			postcode:
				address.postcode ||
				address.postal_code ||
				fallback.postcode ||
				'',
			country: address.country || fallback.country || '',
			email: address.email || fallback.email || '',
			phone: address.phone || fallback.phone || '',
		};
	}

	function normalizePhone( phone ) {
		return ( phone || '' ).replace( /[() -]/g, '' );
	}

	function getWalletBillingPhone( event ) {
		var billingDetails = event && event.billingDetails;

		return normalizePhone(
			( billingDetails && billingDetails.phone ) ||
				( event && event.payerPhone )
		);
	}

	function getBillingAddress( event ) {
		var billingDetails = event && event.billingDetails;
		var nameParts = splitName( billingDetails && billingDetails.name );

		// Some wallets provide a single name; checkout requires a last name.
		nameParts.last_name = nameParts.last_name || '-';

		return normalizeAddress(
			Object.assign(
				{},
				nameParts,
				billingDetails && billingDetails.address,
				{
					email: billingDetails && billingDetails.email,
					phone: getWalletBillingPhone( event ),
				}
			),
			cachedCartData && cachedCartData.billing_address
		);
	}

	function displayLoginConfirmation( expressPaymentType ) {
		var loginConfirmation = config.login_confirmation;
		var paymentTypesMap = {
			apple_pay: 'Apple Pay',
			google_pay: 'Google Pay',
			amazon_pay: 'Amazon Pay',
			paypal: 'PayPal',
			link: 'Link',
		};
		var message;

		if ( ! loginConfirmation ) {
			return;
		}

		// Replace the dialog text with the specific express checkout type,
		// and remove the asterisk markers.
		message = ( loginConfirmation.message || '' )
			.replace( /\*\*.*?\*\*/, paymentTypesMap[ expressPaymentType ] )
			.replace( /\*\*/g, '' );

		if ( window.confirm( message ) ) {
			navigate( loginConfirmation.redirect_url );
		}
	}

	function initOrderAttribution() {
		var orderAttributionInputs;

		// The PHP-rendered element may not be present on all surfaces.
		if ( ! document.getElementById( ORDER_ATTRIBUTION_ELEMENT_ID ) ) {
			orderAttributionInputs = document.createElement(
				'wc-order-attribution-inputs'
			);
			orderAttributionInputs.id = ORDER_ATTRIBUTION_ELEMENT_ID;
			document.body.appendChild( orderAttributionInputs );
		}

		// Manually calling the helper to ensure the hidden inputs are
		// populated with attribution data.
		if (
			window.wc_order_attribution &&
			typeof window.wc_order_attribution.setOrderTracking === 'function'
		) {
			window.wc_order_attribution.setOrderTracking(
				window.wc_order_attribution.params &&
					window.wc_order_attribution.params.allowTracking
			);
		}
	}

	function getPlaceOrderExtensions() {
		var inputs = document.querySelectorAll(
			'#' + ORDER_ATTRIBUTION_ELEMENT_ID + ' input'
		);
		var orderAttributionData = {};
		var extensions = {};

		// The attribution script can load after our init ran; retry once at
		// collection time so a late load doesn't silently drop attribution.
		if ( ! inputs.length ) {
			initOrderAttribution();
			inputs = document.querySelectorAll(
				'#' + ORDER_ATTRIBUTION_ELEMENT_ID + ' input'
			);
		}

		inputs.forEach( function ( input ) {
			var name = ( input.name || '' ).replace(
				'wc_order_attribution_',
				''
			);

			if ( name && input.value ) {
				orderAttributionData[ name ] = input.value;
			}
		} );

		if ( Object.keys( orderAttributionData ).length ) {
			extensions[ 'woocommerce/order-attribution' ] =
				orderAttributionData;
		}

		return applyWpFilters(
			'wcpay.express-checkout.cart-place-order-extension-data',
			extensions
		);
	}

	function createPaymentCredential( stripe ) {
		if ( shouldUseConfirmationTokens() ) {
			return stripe
				.createConfirmationToken( { elements: elements } )
				.then( function ( result ) {
					if ( result && result.error ) {
						throw new Error( result.error.message );
					}

					return result.confirmationToken.id;
				} );
		}

		return stripe
			.createPaymentMethod( { elements: elements } )
			.then( function ( result ) {
				if ( result && result.error ) {
					throw new Error( result.error.message );
				}

				return result.paymentMethod.id;
			} );
	}

	function getPaymentData( paymentCredentialId ) {
		return [
			{
				key: shouldUseConfirmationTokens()
					? 'wcpay-confirmation-token'
					: 'wcpay-payment-method',
				value: paymentCredentialId,
			},
			{
				key: 'wcpay-express-payment-method-types',
				value: JSON.stringify( getPaymentMethodTypes() ),
			},
			{
				key: 'wcpay-express-checkout-context',
				value: getButtonContext(),
			},
			{
				key: 'wcpay-fraud-prevention-token',
				value:
					window.wcpayFraudPreventionToken === null ||
					window.wcpayFraudPreventionToken === undefined
						? ''
						: window.wcpayFraudPreventionToken,
			},
		];
	}

	function placeOrder( confirmationTokenId, event ) {
		if ( isPayForOrder() ) {
			// Keep merchant address and tax fields; only fill missing contact data.
			var billingAddress = Object.assign(
				{},
				cachedCartData && cachedCartData.billing_address
			);
			var shippingAddress = Object.assign(
				{},
				cachedCartData && cachedCartData.shipping_address
			);
			var billingDetails = event && event.billingDetails;
			var walletEmail = billingDetails && billingDetails.email;
			var walletPhone = getWalletBillingPhone( event );

			if ( ! billingAddress.email ) {
				billingAddress.email = walletEmail;
			}
			if ( ! billingAddress.phone ) {
				billingAddress.phone = walletPhone;
			}
			if ( ! shippingAddress.phone ) {
				shippingAddress.phone = walletPhone;
			}

			var submittedBillingEmail = billingAddress.email;

			return requestOrder( {
				method: 'POST',
				path: '/wc/store/v1/checkout/' + config.order_id,
				data: {
					key: config.key,
					// The stored email authorizes this request; wallet email is mutation data.
					billing_email: config.billing_email,
					payment_method: 'woocommerce_payments',
					billing_address: billingAddress,
					shipping_address: shippingAddress,
					payment_data: getPaymentData( confirmationTokenId ),
					extensions: getPlaceOrderExtensions(),
				},
			} ).then(
				function ( response ) {
					// The Store API has persisted the submitted address before payment returns.
					if ( submittedBillingEmail ) {
						config.billing_email = submittedBillingEmail;
					}

					return response;
				},
				function ( error ) {
					// These validation and authorization errors occur before address persistence.
					if (
						ERRORS_BEFORE_PERSISTENCE.indexOf(
							error && error.code
						) === -1 &&
						submittedBillingEmail
					) {
						config.billing_email = submittedBillingEmail;
					}

					throw error;
				}
			);
		}

		var placeOrderHeaders = {
			'X-WooPayments-Tokenized-Cart': true,
		};

		// Lets the server reject placement when the cart's currency drifted
		// away from the one the Element booted with.
		if ( elementCurrency ) {
			placeOrderHeaders[ 'X-WooPayments-Payment-Currency' ] =
				elementCurrency;
		}

		return requestCart( {
			method: 'POST',
			path: '/wc/store/v1/checkout',
			headers: placeOrderHeaders,
			data: {
				payment_method: 'woocommerce_payments',
				billing_address: getBillingAddress( event ),
				// Refresh the shipping address from the wallet sheet, now that
				// the customer is placing the order. Stripe provides no
				// separate shipping phone, so the billing one is reused.
				shipping_address:
					event && event.shippingAddress
						? Object.assign(
								transformShippingAddress(
									event.shippingAddress.name || '',
									event.shippingAddress.address
								),
								getWalletBillingPhone( event )
									? {
											phone: getWalletBillingPhone(
												event
											),
									  }
									: {}
						  )
						: cachedCartData && cachedCartData.shipping_address,
				payment_data: getPaymentData( confirmationTokenId ),
				extensions: getPlaceOrderExtensions(),
			},
		} );
	}

	function parseConfirmationHash( url ) {
		var hashIndex = ( url || '' ).indexOf( '#wcpay-confirm-' );
		var match;
		var clientSecret;

		if ( hashIndex === -1 ) {
			return null;
		}

		match = url
			.substring( hashIndex )
			.match(
				/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/
			);

		if ( ! match ) {
			return null;
		}

		clientSecret = decodeURIComponent( match[ 3 ] );

		return {
			type: match[ 1 ],
			orderId: decodeURIComponent( match[ 2 ] ),
			clientSecret: clientSecret,
			nonce: decodeURIComponent( match[ 4 ] ),
			confirmationToken: match[ 5 ]
				? decodeURIComponent( match[ 5 ] )
				: '',
		};
	}

	function requestOrderStatusUpdate( confirmation, intentId ) {
		var body = new window.FormData();

		body.append( 'action', 'update_order_status' );
		body.append( 'order_id', confirmation.orderId );
		body.append( '_ajax_nonce', confirmation.nonce );
		body.append( 'intent_id', intentId );
		body.append( 'should_save_payment_method', 'false' );
		body.append( 'is_changing_payment', 'false' );

		return window
			.fetch( config.ajax_url || config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function confirmIntentAndRedirect( confirmation ) {
		var stripe = getStripe();
		var confirmationPromise;

		if ( ! stripe ) {
			return Promise.reject(
				new Error( GENERIC_PAYMENT_ERROR_MESSAGE )
			);
		}

		if (
			confirmation.type === 'si' &&
			confirmation.confirmationToken &&
			stripe.confirmSetup
		) {
			confirmationPromise = stripe.confirmSetup( {
				clientSecret: confirmation.clientSecret,
				confirmParams: {
					confirmation_token: confirmation.confirmationToken,
				},
				redirect: 'if_required',
			} );
		} else if ( stripe.handleNextAction ) {
			confirmationPromise = stripe.handleNextAction( {
				clientSecret: confirmation.clientSecret,
			} );
		}

		if ( ! confirmationPromise ) {
			return Promise.reject(
				new Error( GENERIC_PAYMENT_ERROR_MESSAGE )
			);
		}

		return confirmationPromise
			.then( function ( result ) {
				var intent;
				var failedIntentId;
				var paymentError;

				if ( ! result || typeof result !== 'object' ) {
					throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
				}

				if ( result.error ) {
					// Report the failed authentication so the order is
					// marked failed synchronously instead of staying
					// pending until a webhook maybe arrives.
					failedIntentId =
						( result.error.payment_intent &&
							result.error.payment_intent.id ) ||
						( result.error.setup_intent &&
							result.error.setup_intent.id ) ||
						'';
					if ( failedIntentId ) {
						requestOrderStatusUpdate(
							confirmation,
							failedIntentId
						).catch( function () {} );
					}

					throw new Error(
						result.error.message || GENERIC_PAYMENT_ERROR_MESSAGE
					);
				}

				intent =
					confirmation.type === 'si'
						? result.setupIntent
						: result.paymentIntent;

				if ( ! intent || typeof intent.id !== 'string' ) {
					throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
				}

				if (
					intent.last_payment_error &&
					intent.last_payment_error.message
				) {
					paymentError = intent.last_payment_error.message;
				}

				// When a wallet sheet is closed, Stripe resolves without an
				// error but the intent status stays requires_action.
				if ( intent.status === 'requires_action' ) {
					paymentError = 'Payment requires additional action.';
				}

				return requestOrderStatusUpdate( confirmation, intent.id ).then(
					function ( response ) {
						if ( paymentError ) {
							throw new Error( paymentError );
						}

						return response;
					}
				);
			} )
			.then( function ( response ) {
				var returnUrl;

				if ( response && response.error ) {
					throw new Error(
						( response.error && response.error.message ) ||
							GENERIC_PAYMENT_ERROR_MESSAGE
					);
				}

				returnUrl =
					response && typeof response.return_url === 'string'
						? response.return_url.trim()
						: '';

				if ( ! returnUrl ) {
					throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
				}

				returnUrl = new window.URL( returnUrl, window.location.href );

				if (
					returnUrl.protocol !== 'http:' &&
					returnUrl.protocol !== 'https:'
				) {
					throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
				}

				completePayment( returnUrl.href );
			} );
	}

	function getPaymentDetail( paymentResult, key ) {
		var details =
			paymentResult && Array.isArray( paymentResult.payment_details )
				? paymentResult.payment_details
				: [];
		var detail = details.find( function ( entry ) {
			return entry && entry.key === key;
		} );

		return detail && detail.value ? detail.value : '';
	}

	function redirectToOrder( response ) {
		var paymentResult = response && response.payment_result;
		var redirectUrl;
		var confirmation;

		if ( ! paymentResult || paymentResult.payment_status !== 'success' ) {
			return Promise.reject(
				new Error(
					( response && response.message ) ||
						getPaymentDetail( paymentResult, 'errorMessage' ) ||
						GENERIC_PAYMENT_ERROR_MESSAGE
				)
			);
		}

		// The Store API escapes redirect_url, which empties a bare
		// `#wcpay-confirm-...` hash; the raw value stays in payment_details.
		redirectUrl =
			paymentResult.redirect_url ||
			getPaymentDetail( paymentResult, 'redirect' );

		if ( ! redirectUrl ) {
			return Promise.resolve();
		}

		confirmation = parseConfirmationHash( redirectUrl );

		// When the intent needs a next action (SCA/3DS), the server responds
		// with a `#wcpay-confirm-...` redirect. Navigating to a bare hash
		// would run no confirmation on express surfaces, so confirm the
		// intent here and navigate to the authenticated return URL instead.
		if ( confirmation ) {
			return confirmIntentAndRedirect( confirmation );
		}

		completePayment( redirectUrl );
		return Promise.resolve();
	}

	function getFieldValue( form, selector ) {
		var value = '';

		form.querySelectorAll( selector ).forEach( function ( field ) {
			if ( ! value && field.value ) {
				value = field.value;
			}
		} );

		return value;
	}

	function getSelectedProduct() {
		var form =
			document.querySelector( 'form.cart' ) ||
			document.querySelector(
				'form.wp-block-add-to-cart-with-options'
			);
		var quantityField;
		var variationId;
		var variation = [];
		var id;
		var quantity;
		var isIapiForm;

		if ( ! form ) {
			return null;
		}

		isIapiForm = form.classList.contains(
			'wp-block-add-to-cart-with-options'
		);
		if ( isIapiForm && form.classList.contains( 'is-invalid' ) ) {
			return null;
		}

		quantityField = form.querySelector( 'input[name="quantity"]' );
		variationId = parseInt(
			getFieldValue( form, 'input[name="variation_id"]' ) || 0,
			10
		);
		id = parseInt(
			getFieldValue(
				form,
				isIapiForm
					? 'input[name="add-to-cart"], input[name="product_id"]'
					: 'button[name="add-to-cart"], input[name="add-to-cart"]'
			) || 0,
			10
		);
		quantity = parseFloat( ( quantityField && quantityField.value ) || 1 );

		if ( ! id ) {
			return null;
		}

		form.querySelectorAll(
			'select[name^="attribute_"], input[name^="attribute_"]'
		).forEach( function ( field ) {
			if ( field.name && field.value ) {
				variation.push( {
					attribute: field.name,
					value: field.value,
				} );
			}
		} );

		return {
			id: isIapiForm ? id : variationId || id,
			quantity: quantity > 0 ? quantity : 1,
			variation: variation,
		};
	}

	function requestIapiProductPreview( product ) {
		var apiFetch = getApiFetch();
		var headers = Object.assign( {}, getStoreApiHeaders( true, true ), {
			'X-WooPayments-Tokenized-Cart-Session': '',
			'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
		} );

		function requestPreview() {
			return apiFetch( {
				method: 'POST',
				path: addQueryArgs( '/wc/store/v1/cart/add-item', {
					currency: getStoreApiCurrency(),
				} ),
				headers: headers,
				data: product,
				parse: false,
			} ).then( function ( response ) {
				var nextNonce =
					response &&
					response.headers &&
					typeof response.headers.get === 'function'
						? response.headers.get( 'Nonce' )
						: null;

				if ( nextNonce ) {
					config.nonce = config.nonce || {};
					config.nonce.store_api_nonce = nextNonce;
				}

				return response && typeof response.json === 'function'
					? response.json()
					: response;
			} );
		}

		return productCurrencyResolutionPromise
			? productCurrencyResolutionPromise.then( requestPreview )
			: requestPreview();
	}

	function applyProductPreview( cartData ) {
		if (
			! elements &&
			resolvedProductCurrency !== getLocalizedProductCurrency() &&
			! applyProductPreviewMethods( cartData )
		) {
			hideExpressButton();
			return Promise.resolve();
		}

		if ( ! elements ) {
			return initializeExpressCheckout( cartData );
		}

		cachedCartData = cartData;
		return updateElementsForCart( cartData );
	}

	function refreshIapiProductPreview() {
		var selectedProduct = getSelectedProduct();
		var product = filterSelectedProduct( selectedProduct );
		var selection = JSON.stringify( selectedProduct );
		var requestId;

		if ( ! product ) {
			iapiPreviewRequestId++;
			return;
		}

		requestId = ++iapiPreviewRequestId;
		iapiLastSelection = selection;
		return requestIapiProductPreview( product ).then(
			function ( cartData ) {
				if ( requestId !== iapiPreviewRequestId ) {
					return;
				}

				if ( selection !== JSON.stringify( getSelectedProduct() ) ) {
					return refreshIapiProductPreview();
				}

				return applyProductPreview( cartData );
			},
			function () {
				if ( requestId !== iapiPreviewRequestId ) {
					return;
				}

				if ( selection !== JSON.stringify( getSelectedProduct() ) ) {
					return refreshIapiProductPreview();
				}
			}
		);
	}

	function watchIapiVariationSelection() {
		var form;
		var variationSelectors;

		if (
			! isProduct() ||
			iapiObserverInstalled ||
			typeof window.MutationObserver !== 'function'
		) {
			return;
		}

		form = document.querySelector(
			'form.wp-block-add-to-cart-with-options'
		);
		if ( ! form ) {
			return;
		}

		variationSelectors = form.querySelectorAll(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute'
		);
		if ( ! variationSelectors.length ) {
			return;
		}

		iapiObserverInstalled = true;
		iapiLastSelection = JSON.stringify( getSelectedProduct() );

		variationSelectors.forEach( function ( selector ) {
			new window.MutationObserver( function () {
				var selection = JSON.stringify( getSelectedProduct() );

				window.clearTimeout( iapiSelectionRefreshTimer );
				iapiSelectionRefreshTimer = window.setTimeout( function () {
					selection = JSON.stringify( getSelectedProduct() );
					iapiSelectionRefreshTimer = null;

					if ( selection === iapiLastSelection ) {
						return;
					}

					refreshIapiProductPreview();
				}, 250 );
			} ).observe( selector, {
				subtree: true,
				childList: true,
				attributes: true,
			} );
		} );
	}

	/**
	 * Port of the client 11.1.0 `debounce()` (shortcode-buttons-express/debounce.js:13-32) without its unused
	 * `immediate` mode: run `func` once, `wait` ms after the last call.
	 *
	 * @param {number}   wait Milliseconds to wait after the last call.
	 * @param {Function} func Function to run.
	 * @return {Function} Debounced function.
	 */
	function debounce( wait, func ) {
		var timeout;

		return function () {
			var context = this;
			var args = arguments;

			window.clearTimeout( timeout );
			timeout = window.setTimeout( function () {
				timeout = null;
				func.apply( context, args );
			}, wait );
		};
	}

	// Client 11.1.0 utils/wc-product-page-selectors.js:19-26.
	function getAddToCartButtonElement() {
		return (
			document.querySelector( '.single_add_to_cart_button' ) ||
			document.querySelector(
				'.wp-block-add-to-cart-with-options button[type="submit"]'
			)
		);
	}

	// Client 11.1.0 utils/wc-product-page-selectors.js:79-81.
	function isIapiBlock() {
		return Boolean(
			document.querySelector( '.wp-block-add-to-cart-with-options' )
		);
	}

	/**
	 * Tell whether the product form cannot add the product to the cart yet (no variation chosen, an unavailable
	 * combination, an invalid block form). Client 11.1.0 utils/wc-product-page-selectors.js:232-242.
	 *
	 * @return {boolean} Whether add to cart is blocked.
	 */
	function isAddToCartBlocked() {
		var form;
		var button;

		if ( isIapiBlock() ) {
			form = document.querySelector( '.wp-block-add-to-cart-with-options' );
			return Boolean( form && form.classList.contains( 'is-invalid' ) );
		}

		button = getAddToCartButtonElement();
		return Boolean( button && button.classList.contains( 'disabled' ) );
	}

	// Client 11.1.0 utils/wc-product-page-selectors.js:253-262.
	function isVariationUnavailable() {
		var button;

		if ( isIapiBlock() ) {
			return false;
		}

		button = getAddToCartButtonElement();
		return Boolean(
			button && button.classList.contains( 'wc-variation-is-unavailable' )
		);
	}

	/**
	 * Cover the wallet button while its amount is being refreshed. Client 11.1.0 shortcode-buttons-express/button-ui.js:18-26;
	 * blockUI comes with WooCommerce's product-page scripts, which the client relies on in the same way.
	 */
	function blockExpressButton() {
		var $container = $( '#wcpay-express-checkout-element' );

		if (
			typeof $container.block !== 'function' ||
			$container.data( 'blockUI.isBlocked' )
		) {
			return;
		}

		$container.block( { message: null } );
	}

	/**
	 * Client 11.1.0 shortcode-buttons-express/button-ui.js:28-31, which also shows the container. Native shows it only
	 * once the wallet reported a payment method, so a browser without one gets no empty button area.
	 */
	function unblockExpressButton() {
		var $container = $( '#wcpay-express-checkout-element' );

		if ( expressButtonAvailable ) {
			showExpressContainer();
		}

		if ( typeof $container.unblock === 'function' ) {
			$container.unblock();
		}
	}

	/**
	 * Re-price the product-page wallet from an ephemeral cart of the current form selection.
	 *
	 * Port of the client 11.1.0 `wcpay.express-checkout.update-button-data` action (shortcode-buttons-express/index.js:597-674):
	 * a product the form cannot add yet leaves the button unblocked and unchanged; otherwise the button stays blocked
	 * until the new amount reaches Elements, and a cart that cannot be priced or is not eligible hides it.
	 *
	 * @return {Promise} Settles once the wallet reflects the selection.
	 */
	function updateButtonData() {
		var selectedProduct;
		var product;
		var requestId;

		if ( isAddToCartBlocked() ) {
			unblockExpressButton();
			return Promise.resolve();
		}

		document
			.querySelectorAll( '.woocommerce-error' )
			.forEach( function ( notice ) {
				notice.remove();
			} );
		blockExpressButton();

		selectedProduct = getSelectedProduct();
		product = filterSelectedProduct( selectedProduct );
		requestId = ++iapiPreviewRequestId;
		iapiLastSelection = JSON.stringify( selectedProduct );

		if ( ! product ) {
			hideExpressButton();
			return Promise.resolve();
		}

		return requestIapiProductPreview( product )
			.then( function ( cartData ) {
				var wasMounted = Boolean( elements );

				if ( requestId !== iapiPreviewRequestId ) {
					return;
				}

				unblockExpressButton();

				return applyProductPreview( cartData ).then( function () {
					// A first mount decides eligibility itself.
					if ( ! wasMounted ) {
						return;
					}

					if (
						! applyWpFilters(
							'wcpay.express-checkout.is-cart-eligible',
							getTotalAmount( cartData ) > 0,
							cartData
						)
					) {
						hideExpressButton();
					} else if ( expressButtonAvailable ) {
						showExpressButton();
					}
				} );
			} )
			.catch( function () {
				if ( requestId === iapiPreviewRequestId ) {
					hideExpressButton();
				}
			} );
	}

	function getAddressLine( address, index ) {
		if ( address && Array.isArray( address.addressLine ) ) {
			return address.addressLine[ index ] || '';
		}

		if ( index === 0 ) {
			return address.line1 || address.line_1 || address.address_1 || '';
		}

		return address.line2 || address.line_2 || address.address_2 || '';
	}

	function transformShippingAddress( name, address ) {
		var split;
		address = address || {};
		split = splitName( name || address.recipient || address.name || '' );

		return normalizeAddress(
			{
				first_name: split.first_name,
				last_name: split.last_name,
				company: '',
				address_1: getAddressLine( address, 0 ),
				address_2: getAddressLine( address, 1 ),
				city: address.city || address.locality || '',
				state: address.state || address.region || '',
				postcode: address.postal_code || address.postcode || '',
				country: address.country || '',
			},
			cachedCartData && cachedCartData.shipping_address
		);
	}

	function getShippingRates( cartData ) {
		var includeTax = displayPricesIncludeTax();
		var baseRates =
			cartData &&
			cartData.shipping_rates &&
			cartData.shipping_rates[ 0 ] &&
			Array.isArray( cartData.shipping_rates[ 0 ].shipping_rates )
				? cartData.shipping_rates[ 0 ].shipping_rates
				: [];
		var rates = applyWpFilters(
			'wcpay.express-checkout.shipping-rates',
			baseRates,
			cartData
		);

		if ( ! Array.isArray( rates ) || ! rates.length ) {
			return [];
		}

		return rates
			.slice()
			.sort( function ( rateA, rateB ) {
				if ( rateA.selected === rateB.selected ) {
					return 0;
				}

				// Rates with `selected: true` come first.
				return rateA.selected ? -1 : 1;
			} )
			.slice( 0, SHIPPING_RATES_UPPER_LIMIT_COUNT )
			.map( function ( rate ) {
				var metaData = Array.isArray( rate.meta_data )
					? rate.meta_data
					: [];

				return {
					id: rate.rate_id,
					displayName: decodeEntities( rate.name ),
					amount: transformPrice(
						includeTax
							? parseInt( rate.price || 0, 10 ) +
									parseInt( rate.taxes || 0, 10 )
							: parseInt( rate.price || 0, 10 ),
						rate
					),
					deliveryEstimate: [ 'pickup_address', 'pickup_details' ]
						.map( function ( key ) {
							var entry = metaData.find( function ( metadata ) {
								return metadata.key === key;
							} );

							return entry && entry.value;
						} )
						.filter( Boolean )
						.map( decodeEntities )
						.join( ' - ' ),
				};
			} );
	}

	function getDisplayItems( rawCartData ) {
		var includeTax = displayPricesIncludeTax();
		// Allow extensions to manipulate the individual items returned by the backend.
		var cartData = applyWpFilters(
			'wcpay.express-checkout.map-line-items',
			rawCartData
		);
		var items = Array.isArray( cartData.items ) ? cartData.items : [];
		var totals = cartData.totals || {};
		var totalAmount;
		var totalAmountOfDisplayItems;
		var displayItems = items.map( function ( item ) {
			var itemTotals = item.totals || {};

			return {
				amount: transformPrice(
					includeTax && item.totals
						? parseInt( itemTotals.line_subtotal, 10 ) +
								parseInt( itemTotals.line_subtotal_tax, 10 )
						: parseInt(
								itemTotals.line_subtotal ||
									( item.prices && item.prices.price ) ||
									0,
								10
						  ),
					item.totals || item.prices || {}
				),
				name: [
					item.name,
					item.quantity > 1 && '(x' + item.quantity + ')',
					item.variation && item.variation.length > 0 && '-',
					item.variation &&
						item.variation
							.map( function ( variation ) {
								return (
									variation.attribute +
									': ' +
									variation.value
								);
							} )
							.join( ', ' ),
					item.item_data && item.item_data.length > 0 && '-',
					item.item_data &&
						item.item_data
							.map( function ( itemData ) {
								return (
									( itemData.name || itemData.key ) +
									': ' +
									itemData.value
								);
							} )
							.join( ', ' ),
				]
					.filter( Boolean )
					.map( decodeEntities )
					.join( ' ' ),
			};
		} );
		var shippingAmount = parseInt( totals.total_shipping || '0', 10 );
		var discountsAmount = parseInt( totals.total_discount || '0', 10 );
		var feesAmount = parseInt( totals.total_fees || '0', 10 );
		var taxAmount = parseInt( totals.total_tax || '0', 10 );
		var refundAmount = parseInt( totals.total_refund || '0', 10 );

		if ( shippingAmount ) {
			displayItems.push( {
				amount: transformPrice(
					includeTax
						? shippingAmount +
								parseInt(
									totals.total_shipping_tax || '0',
									10
								)
						: shippingAmount,
					totals
				),
				name: 'Shipping',
			} );
		}

		if ( discountsAmount ) {
			displayItems.push( {
				amount: -transformPrice(
					includeTax
						? discountsAmount +
								parseInt(
									totals.total_discount_tax || '0',
									10
								)
						: discountsAmount,
					totals
				),
				name: 'Discount',
			} );
		}

		if ( feesAmount ) {
			displayItems.push( {
				amount: transformPrice(
					includeTax
						? feesAmount +
								parseInt( totals.total_fees_tax || '0', 10 )
						: feesAmount,
					totals
				),
				name: 'Fees',
			} );
		}

		if ( taxAmount && ! includeTax ) {
			displayItems.push( {
				amount: transformPrice( taxAmount, totals ),
				name: 'Tax',
			} );
		}

		if ( refundAmount ) {
			displayItems.push( {
				amount: -transformPrice( refundAmount, totals ),
				name: 'Refund',
			} );
		}

		totalAmount = transformPrice(
			parseInt( totals.total_price || 0, 10 ) -
				parseInt( totals.total_refund || 0, 10 ),
			totals
		);
		totalAmountOfDisplayItems = displayItems.reduce( function (
			accumulator,
			item
		) {
			return accumulator + item.amount;
		},
		0 );

		// If the total is even slightly less than the sum of the line items
		// (rounding on individual items/taxes/shipping, or the
		// `woocommerce_tax_round_at_subtotal` setting), Stripe throws an
		// error - in that case, show only the total to the customer.
		if ( totalAmount < totalAmountOfDisplayItems ) {
			return [];
		}

		return displayItems;
	}

	function updateElementsForCart( cartData ) {
		var amount = getTotalAmount( cartData );
		var updateOptions = shouldUseConfirmationTokens()
			? { setupFutureUsage: getSetupFutureUsageForCart( cartData ) }
			: {};

		if ( amount > 0 ) {
			updateOptions.amount = amount;
		}

		// `elements.update()` returns nothing on js.stripe.com/v3 and a promise from Stripe.js dahlia on
		// (https://docs.stripe.com/js/elements_object/update); callers chain on the result either way, as the
		// client's `await elements.update()` does (shortcode-buttons-express/index.js:653, event-handlers.js:122).
		if ( elements && typeof elements.update === 'function' ) {
			return Promise.resolve( elements.update( updateOptions ) );
		}

		return Promise.resolve();
	}

	/**
	 * Tell whether the server sent usable product-page data.
	 *
	 * Client 11.1.0 (shortcode-buttons-express/index.js:489-495, :504-512) prices a Product Bundle, and a product the
	 * server could not price, from an ephemeral cart of the selected product: a bundle's own price leaves out its
	 * priced-individually items.
	 *
	 * @return {boolean} Whether the localized product data can seed the wallet amount.
	 */
	/**
	 * Tell whether the server product data prices what the form would add: the server prices one unit
	 * (WooPaymentsExpressCheckoutService::get_product_data()), while the quantity field can start above one (a minimum
	 * quantity, a failed add-to-cart, a browser form restore). Native departure from client 11.1.0, whose first open
	 * keeps the one-unit payload (shortcode-buttons-express/index.js:87-92, :110-127, :504-512).
	 *
	 * @return {boolean} Whether the button can be priced from the server product data.
	 */
	function serverProductDataPricesForm() {
		var selectedProduct = getSelectedProduct();

		// A form that cannot add the product yet opens no sheet; choosing the variation re-prices at the live quantity.
		return (
			hasServerProductData() &&
			( ! selectedProduct ||
				selectedProduct.quantity === 1 ||
				isAddToCartBlocked() )
		);
	}

	function hasServerProductData() {
		return Boolean(
			config.product &&
				config.product.total &&
				config.product.product_type !== 'bundle'
		);
	}

	/**
	 * Register the extension compatibility filters the client 11.1.0 classic express checkout bundle imports.
	 */
	function registerExtensionCompatibility() {
		var hooks = window.wp && window.wp.hooks;

		if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
			return;
		}

		// WooCommerce Deposits: send the shopper's deposit choice with the product
		// (client shortcode-buttons-express/compatibility/wc-deposits.js:15-33).
		hooks.addFilter(
			'wcpay.express-checkout.cart-add-item',
			'automattic/wcpay/express-checkout',
			function ( productData ) {
				var depositsData = {};
				var checked;

				if ( ! productData ) {
					return productData;
				}

				if ( document.querySelector( 'input[name=wc_deposit_option]' ) ) {
					checked = document.querySelector(
						'input[name=wc_deposit_option]:checked'
					);
					depositsData.wc_deposit_option = checked
						? checked.value
						: undefined;
				}

				if (
					document.querySelector( 'input[name=wc_deposit_payment_plan]' )
				) {
					checked = document.querySelector(
						'input[name=wc_deposit_payment_plan]:checked'
					);
					depositsData.wc_deposit_payment_plan = checked
						? checked.value
						: undefined;
				}

				return Object.assign( {}, productData, depositsData );
			}
		);

		// Product Bundles: items bundled by another item are part of its price
		// (client shortcode-buttons-express/compatibility/wc-product-bundles.js:6-19).
		// Registered before the Subscriptions filters, as the client imports it
		// (shortcode-buttons-express/index.js:16-17), so a bundle's recurring price
		// is split over its parent only.
		hooks.addFilter(
			'wcpay.express-checkout.map-line-items',
			'automattic/wcpay/express-checkout',
			function ( cartData ) {
				if ( ! cartData || ! Array.isArray( cartData.items ) ) {
					return cartData;
				}

				return Object.assign( {}, cartData, {
					items: cartData.items.filter( function ( item ) {
						return ! (
							item.extensions &&
							item.extensions.bundles &&
							item.extensions.bundles.bundled_by
						);
					} ),
				} );
			}
		);

		registerSubscriptionsCompatibility( hooks );
	}

	/**
	 * Tell whether a Store API cart item is a subscription with a free trial.
	 *
	 * @param {Object} item Store API cart item.
	 * @return {boolean} Whether the item has a trial.
	 */
	function isTrialSubscriptionItem( item ) {
		var subscriptionData =
			item && item.extensions && item.extensions.subscriptions;

		return Boolean( subscriptionData ) && subscriptionData.trial_length > 0;
	}

	function getSubscriptionSchedules( cartData ) {
		var subscriptions =
			cartData && cartData.extensions
				? cartData.extensions.subscriptions
				: null;

		return Array.isArray( subscriptions ) ? subscriptions : null;
	}

	function hasTrialSubscriptionItems( cartData ) {
		if (
			! cartData ||
			! cartData.items ||
			! cartData.extensions ||
			! cartData.extensions.subscriptions
		) {
			return false;
		}

		return cartData.items.some( isTrialSubscriptionItem );
	}

	function getSubscriptionShippingRates( cartData ) {
		var subscriptions = getSubscriptionSchedules( cartData );
		var index;
		var rates;

		if ( ! subscriptions ) {
			return null;
		}

		for ( index = 0; index < subscriptions.length; index++ ) {
			rates =
				subscriptions[ index ].shipping_rates &&
				subscriptions[ index ].shipping_rates[ 0 ] &&
				subscriptions[ index ].shipping_rates[ 0 ].shipping_rates;

			if ( rates && rates.length > 0 ) {
				return rates;
			}
		}

		return null;
	}

	function hasTrialSubscriptionWithDeferredShipping( cartData ) {
		var mainRates =
			cartData &&
			cartData.shipping_rates &&
			cartData.shipping_rates[ 0 ] &&
			cartData.shipping_rates[ 0 ].shipping_rates;

		if ( ! hasTrialSubscriptionItems( cartData ) ) {
			return false;
		}

		// Free trials move the rates from the cart to the subscription extension.
		if ( mainRates && mainRates.length > 0 ) {
			return false;
		}

		return getSubscriptionShippingRates( cartData ) !== null;
	}

	function isZeroTotalTrialCart( cartData ) {
		return (
			hasTrialSubscriptionItems( cartData ) &&
			parseInt(
				( cartData.totals && cartData.totals.total_price ) || '0',
				10
			) === 0
		);
	}

	/**
	 * Sum the recurring totals of every subscription schedule in the cart.
	 *
	 * @param {Object} cartData Store API cart.
	 * @return {Object|null} `{ amount, totals }`, or null without a recurring total.
	 */
	function getRecurringCartTotal( cartData ) {
		var subscriptions = getSubscriptionSchedules( cartData );
		var totalRecurring = 0;
		var totalItems = 0;
		var totalTax = 0;
		var totalShipping = 0;
		var totalShippingTax = 0;
		var currencyMinorUnit = 2;
		var taxLines = [];

		if ( ! subscriptions ) {
			return null;
		}

		subscriptions.forEach( function ( subscription ) {
			var totals = subscription.totals;
			var selectedRate;

			if ( ! totals || ! totals.total_price ) {
				return;
			}

			totalRecurring += parseInt( totals.total_price, 10 );
			totalItems += parseInt( totals.total_items || '0', 10 );
			totalTax += parseInt( totals.total_tax || '0', 10 );

			// During free trials the totals may leave out a selected shipping
			// rate, because shipping is deferred: read the rate instead.
			selectedRate =
				subscription.shipping_rates &&
				subscription.shipping_rates[ 0 ] &&
				Array.isArray( subscription.shipping_rates[ 0 ].shipping_rates )
					? subscription.shipping_rates[ 0 ].shipping_rates.find(
							function ( rate ) {
								return rate.selected;
							}
					  )
					: undefined;
			if ( selectedRate ) {
				totalShipping += parseInt( selectedRate.price || '0', 10 );
				totalShippingTax += parseInt( selectedRate.taxes || '0', 10 );
			} else {
				totalShipping += parseInt( totals.total_shipping || '0', 10 );
				totalShippingTax += parseInt(
					totals.total_shipping_tax || '0',
					10
				);
			}

			currencyMinorUnit = valueOr(
				totals.currency_minor_unit,
				currencyMinorUnit
			);

			if ( totals.tax_lines ) {
				taxLines.push.apply( taxLines, totals.tax_lines );
			}
		} );

		if ( totalRecurring === 0 ) {
			return null;
		}

		return {
			amount: totalRecurring,
			currencyMinorUnit: currencyMinorUnit,
			totals: Object.assign(
				{},
				( subscriptions[ 0 ] && subscriptions[ 0 ].totals ) ||
					cartData.totals,
				{
					total_price: String( totalRecurring ),
					total_items: String( totalItems ),
					total_tax: String( totalTax ),
					total_shipping: String( totalShipping ),
					total_shipping_tax: String( totalShippingTax ),
					tax_lines: taxLines,
				}
			),
		};
	}

	function getLocalizedBillingPeriod( period, interval ) {
		var plurals = {
			day: 'days',
			week: 'weeks',
			month: 'months',
			year: 'years',
		};

		if ( interval > 1 ) {
			return interval + ' ' + ( plurals[ period ] || period + 's' );
		}

		return period;
	}

	// What `??` does; this script keeps to the syntax it already uses.
	function valueOr( value, fallback ) {
		return value === undefined || value === null ? fallback : value;
	}

	function formatRecurringTotal( subscription ) {
		var totals = subscription.totals;
		var amount = parseInt( totals.total_price, 10 );
		var minorUnit = valueOr( totals.currency_minor_unit, 2 );
		var parts = ( amount / Math.pow( 10, minorUnit ) )
			.toFixed( minorUnit )
			.split( '.' );
		var whole = parts[ 0 ].replace(
			/\B(?=(\d{3})+(?!\d))/g,
			valueOr( totals.currency_thousand_separator, ',' )
		);
		var formatted = parts[ 1 ]
			? whole +
			  valueOr( totals.currency_decimal_separator, '.' ) +
			  parts[ 1 ]
			: whole;

		return (
			valueOr( totals.currency_prefix, '' ) +
			formatted +
			valueOr( totals.currency_suffix, '' ) +
			' / ' +
			getLocalizedBillingPeriod(
				subscription.billing_period,
				valueOr( subscription.billing_interval, 1 )
			)
		);
	}

	/**
	 * Show the recurring price and its first payment date on free-trial items, and the
	 * recurring amounts for a $0 cart (client wc-subscriptions.js:413-541).
	 *
	 * @param {Object} cartData Store API cart.
	 * @return {Object} Cart with subscription line items.
	 */
	function mapSubscriptionLineItems( cartData ) {
		var subscriptions = getSubscriptionSchedules( cartData );
		var recurringTotalLabel = 'Recurring total';
		var isZeroTotalCart;
		var modifiedItems;
		var recurringTotal;

		if ( ! hasTrialSubscriptionItems( cartData ) || ! subscriptions ) {
			return cartData;
		}

		isZeroTotalCart =
			parseInt(
				( cartData.totals && cartData.totals.total_price ) || '0',
				10
			) === 0;
		modifiedItems = cartData.items.slice();

		subscriptions.forEach( function ( subscription ) {
			var matchingItemsCount = cartData.items.filter( function ( item ) {
				return (
					item.extensions &&
					item.extensions.subscriptions &&
					item.extensions.subscriptions.billing_period ===
						subscription.billing_period
				);
			} ).length;
			var itemRecurringPrice;

			if ( matchingItemsCount === 0 ) {
				return;
			}

			itemRecurringPrice = Math.round(
				parseInt(
					( subscription.totals && subscription.totals.total_items ) ||
						'0',
					10
				) / matchingItemsCount
			);

			modifiedItems.forEach( function ( item, index ) {
				var itemSubscription =
					item.extensions && item.extensions.subscriptions;

				if (
					! itemSubscription ||
					! ( itemSubscription.trial_length > 0 ) ||
					itemSubscription.billing_period !==
						subscription.billing_period
				) {
					return;
				}

				// Each item is handled once, whether schedules share a billing
				// period or the filter runs again on its own output.
				if (
					( item.item_data || [] ).some( function ( data ) {
						return data.name === recurringTotalLabel;
					} )
				) {
					return;
				}

				modifiedItems[ index ] = Object.assign(
					{},
					item,
					{
						name: item.name + ' (recurring)',
						item_data: ( item.item_data || [] ).concat( [
							{
								name: recurringTotalLabel,
								value:
									formatRecurringTotal( subscription ) +
									' on ' +
									subscription.next_payment_date,
							},
						] ),
					},
					// Only a $0 cart (a pure free trial) shows recurring prices;
					// with a sign-up fee the items show what is paid today.
					isZeroTotalCart
						? {
								totals: Object.assign( {}, item.totals, {
									line_subtotal: String( itemRecurringPrice ),
									line_total: String( itemRecurringPrice ),
								} ),
						  }
						: {}
				);
			} );
		} );

		recurringTotal = isZeroTotalCart
			? getRecurringCartTotal( cartData )
			: null;
		if ( ! recurringTotal ) {
			return Object.assign( {}, cartData, { items: modifiedItems } );
		}

		return Object.assign( {}, cartData, {
			items: modifiedItems,
			totals: Object.assign( {}, cartData.totals, {
				total_price: String( recurringTotal.amount ),
				total_items: recurringTotal.totals.total_items || '0',
				total_tax: recurringTotal.totals.total_tax || '0',
				total_shipping: recurringTotal.totals.total_shipping || '0',
				total_shipping_tax:
					recurringTotal.totals.total_shipping_tax || '0',
				tax_lines: recurringTotal.totals.tax_lines || [],
			} ),
		} );
	}

	/**
	 * Register the WooCommerce Subscriptions filters of client 11.1.0
	 * (client/express-checkout/compatibility/wc-subscriptions.js:289-541), which the
	 * Blocks express checkout imports from its compatibility module.
	 *
	 * @param {Object} hooks The wp.hooks API.
	 */
	function registerSubscriptionsCompatibility( hooks ) {
		var namespace = 'automattic/wcpay/express-checkout/wc-subscriptions';

		// A free trial with nothing to pay today shows the recurring total.
		hooks.addFilter(
			'wcpay.express-checkout.total-amount',
			namespace,
			function ( total, cartData ) {
				var recurringTotal;

				if ( ! isZeroTotalTrialCart( cartData ) ) {
					return total;
				}

				recurringTotal = getRecurringCartTotal( cartData );

				return recurringTotal
					? transformPrice(
							recurringTotal.amount,
							recurringTotal.totals
					  )
					: total;
			}
		);

		// The shopper still authorizes the recurring payment of a $0 free trial.
		hooks.addFilter(
			'wcpay.express-checkout.is-cart-eligible',
			namespace,
			function ( isEligible, cartData ) {
				var recurringTotal;

				if ( isEligible ) {
					return true;
				}

				if ( isZeroTotalTrialCart( cartData ) ) {
					recurringTotal = getRecurringCartTotal( cartData );

					return recurringTotal !== null && recurringTotal.amount > 0;
				}

				return isEligible;
			}
		);

		hooks.addFilter(
			'wcpay.express-checkout.shipping-rates',
			namespace,
			function ( shippingRates, cartData ) {
				if ( shippingRates && shippingRates.length > 0 ) {
					return shippingRates;
				}

				if ( ! hasTrialSubscriptionWithDeferredShipping( cartData ) ) {
					return shippingRates;
				}

				return getSubscriptionShippingRates( cartData ) || shippingRates;
			}
		);

		hooks.addFilter(
			'wcpay.express-checkout.shipping-package-id',
			namespace,
			function ( packageId, cartData, rateId ) {
				var subscriptions = getSubscriptionSchedules( cartData );
				var subscriptionIndex;
				var packageIndex;
				var packages;
				var shippingPackage;

				if (
					! hasTrialSubscriptionWithDeferredShipping( cartData ) ||
					! subscriptions
				) {
					return packageId;
				}

				for (
					subscriptionIndex = 0;
					subscriptionIndex < subscriptions.length;
					subscriptionIndex++
				) {
					packages = subscriptions[ subscriptionIndex ].shipping_rates;
					if ( ! Array.isArray( packages ) ) {
						continue;
					}

					for (
						packageIndex = 0;
						packageIndex < packages.length;
						packageIndex++
					) {
						shippingPackage = packages[ packageIndex ];
						if (
							shippingPackage &&
							Array.isArray( shippingPackage.shipping_rates ) &&
							shippingPackage.shipping_rates.some(
								function ( rate ) {
									return rate.rate_id === rateId;
								}
							) &&
							shippingPackage.package_id
						) {
							return shippingPackage.package_id;
						}
					}
				}

				return packageId;
			}
		);

		hooks.addFilter(
			'wcpay.express-checkout.map-line-items',
			namespace,
			mapSubscriptionLineItems
		);
	}

	function filterSelectedProduct( product ) {
		return applyWpFilters(
			'wcpay.express-checkout.cart-add-item',
			product
		);
	}

	function filterShippingPackageId( packageId, cartData, rateId ) {
		return applyWpFilters(
			'wcpay.express-checkout.shipping-package-id',
			packageId,
			cartData,
			rateId
		);
	}

	function addSelectedProductToCart( product, generation ) {
		tokenizedCartSession = '';

		return requestCart(
			{
				method: 'POST',
				path: '/wc/store/v1/cart/add-item',
				data: product,
			},
			function () {
				return generation === productCartGeneration;
			}
		)
			.then( function ( cartData ) {
				// The sheet's working cart. Elements keeps the amount the sheet opened with until a shipping event
				// updates it, as on the client (shortcode-buttons-express/index.js:321, event-handlers.js:122).
				cachedCartData = cartData;

				return cartData;
			} )
			.catch( function ( error ) {
				return emptyProductCart( generation ).then( function () {
					throw error;
				} );
			} );
	}

	function startProductCartRequest() {
		var selectedProduct = getSelectedProduct();
		var product;

		if ( iapiSelectionRefreshTimer !== null ) {
			window.clearTimeout( iapiSelectionRefreshTimer );
			iapiSelectionRefreshTimer = null;
		}

		iapiLastSelection = JSON.stringify( selectedProduct );
		product = filterSelectedProduct( selectedProduct );

		iapiPreviewRequestId++;
		productAddToCartErrorMessage = '';
		productCartGeneration++;
		// A closed sheet whose cart is still being emptied left its working cart in `cachedCartData`; the button is
		// still priced from `productPricedCartData`.
		if ( tokenizedCartSession === null ) {
			productPricedCartData = cachedCartData;
		} else {
			cachedCartData = productPricedCartData;
		}

		if ( ! product ) {
			productAddToCartErrorMessage =
				'Unable to add this product to the cart.';
			return false;
		}

		productAddToCartPromise = addSelectedProductToCart(
			product,
			productCartGeneration
		).catch(
			function ( error ) {
				productAddToCartErrorMessage =
					( error && error.message ) ||
					'Unable to add this product to the cart.';
				setError( productAddToCartErrorMessage );
				unblockPage();
				throw error;
			}
		);
		productAddToCartPromise.catch( function () {} );

		return true;
	}

	function getPendingShippingRate() {
		return {
			id: 'pending',
			displayName: 'Pending',
			amount: 0,
		};
	}

	function handleShippingAddressChange( event ) {
		// Recorded before the request, so a change the store could not price still counts (client 11.1.0
		// event-handlers.js:93).
		lastSelectedAddress = event.address;

		// Please note that `event.address` might not contain all the fields.
		// Some fields might not be present (like `line_1` or `line_2`) due to
		// semi-anonymized data.
		return requestCart( {
			method: 'POST',
			path: '/wc/store/v1/cart/update-customer',
			headers: {
				'X-WooPayments-Tokenized-Cart': true,
			},
			data: {
				shipping_address: transformShippingAddress(
					event.name,
					event.address
				),
			},
		} )
			.then( function ( cartData ) {
				var shippingRates;

				if ( cartCurrencyDriftedFromElement( cartData ) ) {
					setError( getCurrencyMismatchMessage( cartData ) );
					unblockPage();
					event.reject();
					return;
				}

				shippingRates = getShippingRates( cartData );

				// When no shipping options are returned, the API still responds
				// with a 200 status code. Ensure options are present - otherwise
				// the ECE dialog won't update correctly.
				if ( ! shippingRates.length ) {
					event.reject();
					return;
				}

				cachedCartData = cartData;

				return updateElementsForCart( cartData ).then( function () {
					event.resolve( {
						shippingRates: shippingRates,
						lineItems: getDisplayItems( cartData ),
					} );
				} );
			} )
			.catch( function () {
				// The sheet stays open on the same cart (client 11.1.0 event-handlers.js:130-132, :173-175); cancel
				// empties it.
				event.reject();
			} );
	}

	function handleShippingRateChange( event ) {
		var rateId = event && event.shippingRate ? event.shippingRate.id : '';

		return requestCart( {
			method: 'POST',
			path: '/wc/store/v1/cart/select-shipping-rate',
			data: {
				package_id: filterShippingPackageId(
					0,
					cachedCartData,
					rateId
				),
				rate_id: rateId,
			},
		} )
			.then( function ( cartData ) {
				if ( cartCurrencyDriftedFromElement( cartData ) ) {
					setError( getCurrencyMismatchMessage( cartData ) );
					unblockPage();
					event.reject();
					return;
				}

				cachedCartData = cartData;

				return updateElementsForCart( cartData ).then( function () {
					event.resolve( {
						lineItems: getDisplayItems( cartData ),
					} );
				} );
			} )
			.catch( function () {
				// The sheet stays open on the same cart (client 11.1.0 event-handlers.js:130-132, :173-175); cancel
				// empties it.
				event.reject();
			} );
	}

	/**
	 * Empty the ephemeral cart of a product-page sheet and forget its session.
	 *
	 * Once a newer sheet opened, the session belongs to that sheet: nothing is sent or reset for the older one, whose
	 * tokenized session is left to WooCommerce's session expiry.
	 *
	 * @param {number} [generation] The sheet whose cart to empty; the current one by default.
	 * @return {Promise} Settles once the cart was emptied.
	 */
	function emptyProductCart( generation ) {
		var forget = function () {
			if ( generation === productCartGeneration ) {
				tokenizedCartSession = null;
				cachedCartData = productPricedCartData;
			}
		};

		if ( generation === undefined ) {
			generation = productCartGeneration;
		}

		if (
			! isProduct() ||
			tokenizedCartSession === null ||
			generation !== productCartGeneration
		) {
			return Promise.resolve();
		}

		// The answer carries the session being deleted; it never becomes the current one.
		return requestCart(
			{
				method: 'GET',
				path: '/wc/store/v1/cart',
				headers: {
					'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
				},
			},
			function () {
				return false;
			}
		).then( forget, forget );
	}

	function getClickOptions() {
		// Before a Store API cart prices the product page, the button holds the server product data, which has no
		// `totals` (client 11.1.0 getOnClickOptions(), shortcode-buttons-express/index.js:95-128).
		var productData =
			isProduct() && ! ( cachedCartData && cachedCartData.totals )
				? cachedCartData || config.product
				: null;
		var shippingAddressRequired;
		var shippingRates;
		var lineItems;

		if ( productData ) {
			shippingAddressRequired = Boolean( productData.needs_shipping );
			lineItems = ( productData.displayItems || [] ).map( function (
				item
			) {
				return {
					name: item.label,
					amount: item.amount,
				};
			} );
		} else {
			shippingAddressRequired = isPayForOrder()
				? false
				: Boolean(
						cachedCartData
							? cachedCartData.needs_shipping
							: config.checkout && config.checkout.needs_shipping
				  );
			shippingRates =
				cachedCartData && cachedCartData.needs_shipping
					? getShippingRates( cachedCartData )
					: undefined;
			lineItems = cachedCartData
				? getDisplayItems( cachedCartData )
				: undefined;
		}

		// Fallback for initialization (and initialization _only_), before an
		// address is provided by the ECE.
		if (
			shippingAddressRequired &&
			( ! shippingRates || ! shippingRates.length )
		) {
			shippingRates = [ getPendingShippingRate() ];
		}

		return {
			business: {
				name: config.store_name || '',
			},
			emailRequired: true,
			phoneNumberRequired: Boolean(
				config.checkout && config.checkout.needs_payer_phone
			),
			shippingAddressRequired: shippingAddressRequired,
			allowedShippingCountries:
				( config.checkout &&
					config.checkout.allowed_shipping_countries ) ||
				[],
			shippingRates: shippingRates,
			lineItems: lineItems,
		};
	}

	async function initExpressCheckout( previewCartData ) {
		var stripe;
		var total;
		var initialProductSelection;
		var previewRequestId;

		if (
			isBlockSurface() ||
			( ! isProduct() &&
				! ( isPaymentRequestEnabled() || isAmazonPayEnabled() ) ) ||
			! getApiFetch() ||
			! document.getElementById( 'wcpay-express-checkout-element' )
		) {
			return;
		}

		try {
			if ( previewCartData ) {
				cachedCartData = previewCartData;
			} else if ( isProduct() ) {
				var localizedProductCurrency = getLocalizedProductCurrency();
				var ready =
					window.wcpayAsyncCurrency &&
					window.wcpayAsyncCurrency.ready;

				previewRequestId = ++iapiPreviewRequestId;
				initialProductSelection = JSON.stringify( getSelectedProduct() );
				if ( ! ready || typeof ready.then !== 'function' ) {
					resolvedProductCurrency = localizedProductCurrency;
					productCurrencyResolutionPromise = null;
					if ( ! serverProductDataPricesForm() ) {
						return refreshIapiProductPreview();
					}
					cachedCartData = config.product;
				} else {
					productCurrencyResolutionPromise = resolveProductCurrency(
						localizedProductCurrency
					);
					resolvedProductCurrency =
						await productCurrencyResolutionPromise;

					if ( previewRequestId !== iapiPreviewRequestId ) {
						return;
					}

					if (
						initialProductSelection !==
						JSON.stringify( getSelectedProduct() )
					) {
						return refreshIapiProductPreview();
					}

					if (
						resolvedProductCurrency !== localizedProductCurrency ||
						! serverProductDataPricesForm()
					) {
						return refreshIapiProductPreview();
					}

					cachedCartData = config.product;
				}
			} else {
				cachedCartData = await getCart();
			}
		} catch ( error ) {
			hideExpressButton();
			return;
		}

		if ( ! ( isPaymentRequestEnabled() || isAmazonPayEnabled() ) ) {
			hideExpressButton();
			return;
		}

		total = getTotalAmount( cachedCartData );
		// Extensions may make a $0 cart eligible, as WooCommerce Subscriptions does
		// for a free trial (client shortcode-buttons-express/index.js:519-525).
		if (
			! applyWpFilters(
				'wcpay.express-checkout.is-cart-eligible',
				total > 0,
				cachedCartData
			)
		) {
			hideExpressButton();
			return;
		}

		stripe = getStripe();
		if ( ! stripe ) {
			return;
		}

		if ( expressElement && expressElement.unmount ) {
			expressElement.unmount();
		}

		expressButtonAvailable = false;
		elements = stripe.elements(
			getStripeElementsOptions( cachedCartData )
		);
		expressElement = elements.create(
			'expressCheckout',
			getButtonOptions()
		);

		expressElement.on( 'ready', function ( event ) {
			var availablePaymentMethods = event && event.availablePaymentMethods;

			if ( ! availablePaymentMethods ) {
				return;
			}

			// Client 11.1.0 shortcode-buttons-express/index.js:451-459: shown only when at least one method is available.
			if ( Object.values( availablePaymentMethods ).filter( Boolean ).length ) {
				expressButtonAvailable = true;
				showExpressButton();
			}
			recordExpressCheckoutLoadEvents( availablePaymentMethods );
		} );

		expressElement.on( 'click', async function ( event ) {
			var clickOptions;

			// If login is required for checkout, display the redirect
			// confirmation dialog instead of opening the wallet sheet.
			if ( config.login_confirmation ) {
				displayLoginConfirmation( event && event.expressPaymentType );
				return;
			}

			// Client 11.1.0 shortcode-buttons-express/index.js:291-309: the sheet does not open for a product the form
			// cannot add yet.
			if ( isProduct() && isAddToCartBlocked() ) {
				window.alert(
					isVariationUnavailable()
						? ( window.wc_add_to_cart_variation_params &&
								window.wc_add_to_cart_variation_params
									.i18n_unavailable_text ) ||
								'Sorry, this product is unavailable. Please choose a different combination.'
						: 'Please select your product options before proceeding.'
				);
				return;
			}

			recordExpressCheckoutClickEvent(
				event && event.expressPaymentType
			);

			try {
				if ( isProduct() ) {
					if ( ! startProductCartRequest() ) {
						throw new Error( productAddToCartErrorMessage );
					}
				}

				clickOptions = getClickOptions();
				blockPage();
				event.resolve( clickOptions );
			} catch ( error ) {
				setError(
					( error && error.message ) ||
						GENERIC_PAYMENT_ERROR_MESSAGE
				);
				unblockPage();
				await emptyProductCart();
			}
		} );

		expressElement.on( 'shippingaddresschange', async function ( event ) {
			await productAddToCartPromise.catch( function () {} );

			if ( productAddToCartErrorMessage ) {
				// Pretending like everything is fine - the payment will not be
				// confirmed in the `confirm` handler later. This prevents a
				// misleading "invalid shipping address" message on the payment
				// sheet.
				event.resolve();
				return;
			}

			await handleShippingAddressChange( event );
		} );

		expressElement.on( 'shippingratechange', async function ( event ) {
			await handleShippingRateChange( event );
		} );

		expressElement.on( 'confirm', async function ( event ) {
			var submitResult;
			var paymentCredentialId;
			var response;

			try {
				if ( isProduct() ) {
					await productAddToCartPromise;
					if ( productAddToCartErrorMessage ) {
						throw new Error( productAddToCartErrorMessage );
					}
				}

				submitResult = await elements.submit();
				if ( submitResult && submitResult.error ) {
					throw new Error( submitResult.error.message );
				}

				paymentCredentialId = await createPaymentCredential( stripe );

				response = await placeOrder( paymentCredentialId, event );
				await redirectToOrder( response );
			} catch ( error ) {
				setError(
					( error && error.message ) ||
						GENERIC_PAYMENT_ERROR_MESSAGE
				);
				unblockPage();
				await emptyProductCart();
			}
		} );

		// Client 11.1.0 (shortcode-buttons-express/index.js:432-446, event-handlers.js:320-326): once the product reached
		// the cart, empty it, put the sheet's last address into the page form, and unblock the page; the button stays as
		// it is.
		expressElement.on( 'cancel', function () {
			var generation = productCartGeneration;

			productAddToCartPromise
				.catch( function () {} )
				.then( function () {
					return emptyProductCart( generation );
				} );

			if ( lastSelectedAddress ) {
				updateShippingAddressUI( lastSelectedAddress );
			}
			lastSelectedAddress = null;
			unblockPage();
		} );

		expressElement.mount( '#wcpay-express-checkout-element' );
	}

	function initializeExpressCheckout( previewCartData ) {
		return initExpressCheckout( previewCartData ).then(
			function ( result ) {
				watchIapiVariationSelection();
				return result;
			},
			function ( error ) {
				watchIapiVariationSelection();
				throw error;
			}
		);
	}

	registerExtensionCompatibility();

	$( function () {
		var debouncedUpdateButtonData;
		var pricedQuantities;

		if ( isBlockSurface() ) {
			return;
		}

		// The container starts in the stylesheet's hidden state (no `is-ready`) and the separator in the state the
		// server rendered (hidden unless a WooPay button shows), as on the client.
		initOrderAttribution();

		if ( isProduct() ) {
			// A changed WooCommerce Deposits choice re-prices the wallet (client wc-deposits.js:7-14).
			document
				.querySelectorAll(
					'input[name=wc_deposit_option],input[name=wc_deposit_payment_plan]'
				)
				.forEach( function ( input ) {
					input.addEventListener( 'change', function () {
						updateButtonData();
					} );
				} );

			// A classic variation change re-prices the wallet (client wc-product-page.js:21-24).
			$( document.body ).on(
				'woocommerce_variation_has_changed',
				function () {
					variationChangeAwaitsButton = isAddToCartBlocked();
					updateButtonData();
				}
			);

			// Native departure from the client: WooCommerce fires the change above before it enables the add-to-cart
			// button for a variation chosen on a cleared form (it does so on `show_variation`), so the change finds
			// the form blocked and skips the re-price. Re-price that change once WooCommerce shows the variation.
			$( document.body ).on( 'show_variation', function () {
				if ( ! variationChangeAwaitsButton ) {
					return;
				}

				variationChangeAwaitsButton = false;
				updateButtonData();
			} );

			// A quantity change blocks the button at once, so it cannot be clicked at a stale amount, and re-prices the
			// wallet 250ms after the last one (client wc-product-page.js:64-83, delegated through jQuery). Native
			// departure: also on `change`, the only event WooCommerce's quantity steppers fire. A quantity already
			// priced (the page-load value, or the last change) is skipped, so typing and then leaving the field re-prices once.
			debouncedUpdateButtonData = debounce( 250, updateButtonData );
			pricedQuantities = new WeakMap();
			// What the button is priced at when the page loads: the server's one unit, or the form's quantity priced by the
			// first mount.
			document
				.querySelectorAll( '.quantity .qty' )
				.forEach( function ( field ) {
					pricedQuantities.set( field, field.value );
				} );
			$( '.quantity' ).on( 'input change', '.qty', function () {
				var pricedQuantity = pricedQuantities.get( this );

				if ( this.value === pricedQuantity ) {
					return;
				}

				pricedQuantities.set( this, this.value );
				blockExpressButton();
				debouncedUpdateButtonData();
			} );
		}

		if (
			getButtonContext() !== 'checkout' ||
			getButtonContext() === 'pay_for_order'
		) {
			initializeExpressCheckout();
		}

		$( document.body ).on( 'updated_checkout', function () {
			cachedCartData = null;
			return initializeExpressCheckout();
		} );

		$( document.body ).on( 'updated_cart_totals', function () {
			cachedCartData = null;
			return initializeExpressCheckout();
		} );
	} );
	// Expose internals for unit testing only.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports.__test__ = {
			transformPrice: transformPrice,
			getShippingRates: getShippingRates,
			getDisplayItems: getDisplayItems,
			getTotalAmount: getTotalAmount,
			getStripeElementsOptions: getStripeElementsOptions,
			getSetupFutureUsageForCart: getSetupFutureUsageForCart,
			handleShippingAddressChange: handleShippingAddressChange,
			handleShippingRateChange: handleShippingRateChange,
			placeOrder: placeOrder,
			redirectToOrder: redirectToOrder,
			getStripe: getStripe,
			displayLoginConfirmation: displayLoginConfirmation,
			createPaymentCredential: createPaymentCredential,
			parseConfirmationHash: parseConfirmationHash,
			setNavigate: function ( callback ) {
				navigate = callback;
			},
			getElementCurrency: function () {
				return elementCurrency;
			},
			setState: function ( state ) {
				if ( 'elements' in state ) {
					elements = state.elements;
				}
				if ( 'elementCurrency' in state ) {
					elementCurrency = state.elementCurrency;
				}
				if ( 'cachedCartData' in state ) {
					cachedCartData = state.cachedCartData;
				}
				if ( 'tokenizedCartSession' in state ) {
					tokenizedCartSession = state.tokenizedCartSession;
				}
			},
		};
	}
} )( jQuery, window, document );
