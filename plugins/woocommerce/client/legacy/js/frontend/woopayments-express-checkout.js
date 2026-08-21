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

	// This const defines the max number of shipping options that can be handled by the ECE.
	// More than 9 options will prevent the UI from behaving correctly.
	var SHIPPING_RATES_UPPER_LIMIT_COUNT = 9;

	var GENERIC_PAYMENT_ERROR_MESSAGE =
		'Unable to process this payment, please try again.';

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

	function normalizeStoreApiResponse( response ) {
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
		if ( nextSession !== null && nextSession !== undefined ) {
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

	function requestCart( options ) {
		var apiFetch = getApiFetch();
		var includeSessionNonce = isProduct();
		var requestOptions = Object.assign( {}, options, {
			// Pin the currency the page was rendered with, so cache-optimized
			// or geolocation-driven multi-currency setups can't serve the
			// request in a different currency than the wallet sheet shows.
			path: addQueryArgs( options.path, {
				currency: (
					( config.checkout && config.checkout.currency_code ) ||
					''
				).toUpperCase(),
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

		return apiFetch( requestOptions ).then( normalizeStoreApiResponse );
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
		var options;

		elementCurrency = currency;

		options = {
			mode: 'payment',
			amount: amount,
			currency: currency,
			loader: 'never',
			paymentMethodTypes: getPaymentMethodTypes(),
		};

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

		return window.Stripe( config.stripe.publishableKey, {
			locale: config.stripe.locale || 'auto',
			stripeAccount: config.stripe.accountId,
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

	function showExpressButton() {
		var container = document.getElementById(
			'wcpay-express-checkout-element'
		);
		var separator = document.getElementById(
			'wcpay-express-checkout-button-separator'
		);

		if ( container ) {
			container.classList.add( 'is-ready' );
		}

		if ( separator ) {
			separator.hidden = false;
		}
	}

	function hideExpressButton() {
		var container = document.getElementById(
			'wcpay-express-checkout-element'
		);
		var separator = document.getElementById(
			'wcpay-express-checkout-button-separator'
		);

		if ( container ) {
			container.classList.remove( 'is-ready' );
		}

		if ( separator ) {
			separator.hidden = true;
		}
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
			window.location.href = loginConfirmation.redirect_url;
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

	function getPaymentData( confirmationTokenId ) {
		return [
			{
				key: 'wcpay-confirmation-token',
				value: confirmationTokenId,
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
			return requestOrder( {
				method: 'POST',
				path: '/wc/store/v1/checkout/' + config.order_id,
				data: {
					key: config.key,
					billing_email: config.billing_email,
					payment_method: 'woocommerce_payments',
					billing_address:
						cachedCartData && cachedCartData.billing_address,
					shipping_address:
						cachedCartData && cachedCartData.shipping_address,
					payment_data: getPaymentData( confirmationTokenId ),
					extensions: getPlaceOrderExtensions(),
				},
			} );
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

				if ( ! result || typeof result !== 'object' ) {
					throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
				}

				if ( result.error ) {
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

				return requestOrderStatusUpdate( confirmation, intent.id );
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

				window.location.href = returnUrl.href;
			} );
	}

	function redirectToOrder( response ) {
		var redirectUrl =
			response &&
			response.payment_result &&
			response.payment_result.redirect_url;
		var confirmation;

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

		window.location.href = redirectUrl;
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
		var form = document.querySelector( 'form.cart' );
		var quantityField;
		var variationId;
		var variation = [];
		var id;
		var quantity;

		if ( ! form ) {
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
				'button[name="add-to-cart"], input[name="add-to-cart"]'
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
			id: variationId || id,
			quantity: quantity > 0 ? quantity : 1,
			variation: variation,
		};
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
		var updateOptions = {
			setupFutureUsage: getSetupFutureUsageForCart( cartData ),
		};

		if ( amount > 0 ) {
			updateOptions.amount = amount;
		}

		if ( elements && typeof elements.update === 'function' ) {
			return elements.update( updateOptions );
		}

		return Promise.resolve();
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

	function addSelectedProductToCart( product ) {
		tokenizedCartSession = '';

		return requestCart( {
			method: 'POST',
			path: '/wc/store/v1/cart/add-item',
			data: product,
		} )
			.then( function ( cartData ) {
				cachedCartData = cartData;

				return updateElementsForCart( cartData ).then( function () {
					return cartData;
				} );
			} )
			.catch( function ( error ) {
				return emptyProductCart().then( function () {
					throw error;
				} );
			} );
	}

	function startProductCartRequest() {
		var product = filterSelectedProduct( getSelectedProduct() );

		productAddToCartErrorMessage = '';

		if ( ! product ) {
			productAddToCartErrorMessage =
				'Unable to add this product to the cart.';
			return false;
		}

		productAddToCartPromise = addSelectedProductToCart( product ).catch(
			function ( error ) {
				productAddToCartErrorMessage =
					( error && error.message ) ||
					'Unable to add this product to the cart.';
				setError( productAddToCartErrorMessage );
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
				event.reject();
				return emptyProductCart();
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
				event.reject();
				return emptyProductCart();
			} );
	}

	function emptyProductCart() {
		if ( ! isProduct() || tokenizedCartSession === null ) {
			return Promise.resolve();
		}

		return requestCart( {
			method: 'GET',
			path: '/wc/store/v1/cart',
			headers: {
				'X-WooPayments-Tokenized-Cart-Is-Ephemeral-Cart': '1',
			},
		} )
			.then( function () {
				tokenizedCartSession = null;
				cachedCartData = null;
			} )
			.catch( function () {
				tokenizedCartSession = null;
				cachedCartData = null;
			} );
	}

	function getClickOptions() {
		var shippingAddressRequired = isPayForOrder()
			? false
			: Boolean(
					cachedCartData
						? cachedCartData.needs_shipping
						: config.checkout && config.checkout.needs_shipping
			  );
		var shippingRates =
			cachedCartData && cachedCartData.needs_shipping
				? getShippingRates( cachedCartData )
				: undefined;

		var lineItems;

		// Fallback for initialization (and initialization _only_), before an
		// address is provided by the ECE.
		if (
			shippingAddressRequired &&
			( ! shippingRates || ! shippingRates.length )
		) {
			shippingRates = [ getPendingShippingRate() ];
		}

		if ( cachedCartData ) {
			lineItems = getDisplayItems( cachedCartData );
		} else if ( isProduct() && config.product ) {
			lineItems = ( config.product.displayItems || [] ).map( function (
				item
			) {
				return {
					name: item.label,
					amount: item.amount,
				};
			} );
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

	async function initExpressCheckout() {
		var stripe;
		var total;

		if (
			isBlockSurface() ||
			! ( isPaymentRequestEnabled() || isAmazonPayEnabled() ) ||
			! getApiFetch() ||
			! document.getElementById( 'wcpay-express-checkout-element' )
		) {
			return;
		}

		try {
			cachedCartData = isProduct() ? config.product : await getCart();
		} catch ( error ) {
			hideExpressButton();
			return;
		}

		total = getTotalAmount( cachedCartData );
		if ( total <= 0 ) {
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

		elements = stripe.elements(
			getStripeElementsOptions( cachedCartData )
		);
		expressElement = elements.create(
			'expressCheckout',
			getButtonOptions()
		);

		expressElement.on( 'ready', function ( event ) {
			if ( event && event.availablePaymentMethods ) {
				showExpressButton();
				recordExpressCheckoutLoadEvents(
					event.availablePaymentMethods
				);
			}
		} );

		expressElement.on( 'click', async function ( event ) {
			// If login is required for checkout, display the redirect
			// confirmation dialog instead of opening the wallet sheet.
			if ( config.login_confirmation ) {
				displayLoginConfirmation( event && event.expressPaymentType );
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

				event.resolve( getClickOptions() );
			} catch ( error ) {
				setError(
					( error && error.message ) ||
						GENERIC_PAYMENT_ERROR_MESSAGE
				);
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
			var confirmationResult;
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

				confirmationResult = await stripe.createConfirmationToken( {
					elements: elements,
				} );

				if ( confirmationResult && confirmationResult.error ) {
					throw new Error( confirmationResult.error.message );
				}

				response = await placeOrder(
					confirmationResult.confirmationToken.id,
					event
				);
				await redirectToOrder( response );
			} catch ( error ) {
				setError(
					( error && error.message ) ||
						GENERIC_PAYMENT_ERROR_MESSAGE
				);
				await emptyProductCart();
			}
		} );

		expressElement.on( 'cancel', function () {
			emptyProductCart();
			hideExpressButton();
		} );

		expressElement.mount( '#wcpay-express-checkout-element' );
	}

	$( function () {
		if ( isBlockSurface() ) {
			return;
		}

		hideExpressButton();
		initOrderAttribution();

		if (
			getButtonContext() !== 'checkout' ||
			getButtonContext() === 'pay_for_order'
		) {
			initExpressCheckout();
		}

		$( document.body ).on( 'updated_checkout', function () {
			cachedCartData = null;
			return initExpressCheckout();
		} );

		$( document.body ).on( 'updated_cart_totals', function () {
			cachedCartData = null;
			return initExpressCheckout();
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
			displayLoginConfirmation: displayLoginConfirmation,
			parseConfirmationHash: parseConfirmationHash,
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
