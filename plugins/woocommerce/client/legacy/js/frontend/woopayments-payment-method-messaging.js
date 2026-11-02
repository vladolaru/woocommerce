/* global jQuery */
( function ( $, window, document ) {
	'use strict';

	var elementsLocations = {
		bnplProductPage: 'bnpl_product_page',
		bnplClassicCart: 'bnpl_classic_cart',
	};
	// Client 11.2.0 checkout/upe-styles/index.js:91-133 (appearanceSelectors.bnplProductPage, .bnplClassicCart): where
	// the message's text and background are read from.
	var appearanceSelectors = {
		bnpl_product_page: {
			text: [ '.product .cart .quantity' ],
			background: [
				'#payment-method-message',
				'#main > .product > div.summary.entry-summary',
				'#main > .product',
				'#main',
				'body',
			],
		},
		bnpl_classic_cart: {
			text: [ '.cart .quantity' ],
			background: [
				'#payment-method-message',
				'#main .entry-content .cart_totals',
				'#main .entry-content',
				'#main',
				'body',
			],
		},
	};
	// Client 11.2.0 checkout/upe-styles/upe-styles.js: the .Text properties Stripe accepts.
	var textStyleProps = [
		'color',
		'padding',
		'paddingTop',
		'paddingRight',
		'paddingBottom',
		'paddingLeft',
		'fontFamily',
		'fontSize',
		'lineHeight',
		'letterSpacing',
		'fontWeight',
		'fontVariation',
		'textDecoration',
		'textShadow',
		'textTransform',
		'-webkit-font-smoothing',
		'-moz-osx-font-smoothing',
		'transition',
	];

	function getConfig() {
		return window.wcpayStripeSiteMessaging || null;
	}

	function getMessageContainer() {
		return document.getElementById( 'payment-method-message' );
	}

	function parseIntOrReturnZero( value ) {
		var result = parseInt( value, 10 );
		return isNaN( result ) ? 0 : result;
	}

	function buildAjaxUrl( config, endpoint ) {
		return String( config.wcAjaxUrl || '' ).replace(
			'%%endpoint%%',
			'wcpay_' + endpoint
		);
	}

	function request( config, endpoint, args ) {
		var formData = new window.FormData();

		Object.keys( args ).forEach( function ( key ) {
			formData.append( key, args[ key ] );
		} );

		return window
			.fetch( buildAjaxUrl( config, endpoint ), {
				method: 'POST',
				body: formData,
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function getProductVariation( config, variationId ) {
		var productVariations = config.productVariations || {};
		return productVariations[ variationId ] || null;
	}

	function getProductAmount( config ) {
		var productId = config.productId || 'base_product';
		var productVariation = getProductVariation( config, productId );

		return productVariation ? productVariation.amount : 0;
	}

	function getProductCurrency( config ) {
		var productId = config.productId || 'base_product';
		var productVariation = getProductVariation( config, productId );

		return productVariation ? productVariation.currency : config.currencyCode;
	}

	function getElementsLocation( config ) {
		return config.isCart
			? elementsLocations.bnplClassicCart
			: elementsLocations.bnplProductPage;
	}

	function queryFirst( selectors ) {
		var i;
		var element;

		for ( i = 0; i < selectors.length; i++ ) {
			element = document.querySelector( selectors[ i ] );
			if ( element ) {
				return element;
			}
		}

		return null;
	}

	function getTextRules( appearanceUtils, element ) {
		var styles = element ? window.getComputedStyle( element ) : null;
		var rules = {};

		if ( ! styles ) {
			return rules;
		}

		textStyleProps.forEach( function ( prop ) {
			var raw = styles.getPropertyValue(
				prop.replace( /[A-Z]/g, function ( letter ) {
					return '-' + letter.toLowerCase();
				} )
			);
			var value;
			var color;

			if ( appearanceUtils.containsAlphaColor( raw ) ) {
				return;
			}
			value = appearanceUtils.normalizeAppearanceValueForStripe( raw );
			if ( ! value ) {
				return;
			}
			if ( 'color' === prop ) {
				color = appearanceUtils.parseColor( value );
				value =
					color && color.a < 1
						? appearanceUtils.toRgbString(
								appearanceUtils.compositeAgainstWhite( color )
						  )
						: value;
			}
			rules[ prop ] = value;
		} );

		return rules;
	}

	function getBackgroundColor( appearanceUtils, selectors ) {
		var i;
		var element;
		var color;
		var parsed;

		for ( i = 0; i < selectors.length; i++ ) {
			element = document.querySelector( selectors[ i ] );
			color = element ? window.getComputedStyle( element ).backgroundColor : '';
			parsed = color ? appearanceUtils.parseColor( color ) : null;
			if ( parsed && parsed.a >= 0.5 ) {
				return color;
			}
		}

		return '#ffffff';
	}

	function isColorLight( appearanceUtils, color ) {
		var parsed = appearanceUtils.parseColor( color );

		if ( ! parsed ) {
			return true;
		}
		if ( parsed.a < 1 ) {
			parsed = appearanceUtils.compositeAgainstWhite( parsed );
		}

		return ( parsed.r * 299 + parsed.g * 587 + parsed.b * 114 ) / 1000 > 125;
	}

	// Client 11.2.0 product-details/bnpl-site-messaging/index.js:66-73 computes the appearance from the page when none is
	// cached (checkout/upe-styles/index.js getAppearance). The messaging element reads the theme, the text variables and
	// the .Text rules (https://docs.stripe.com/elements/payment-method-messaging), so those are what is computed; the
	// client's input, label and tab rules, read from hidden copies of a form field, have nothing to style here.
	function getAppearanceFromPage( appearanceUtils, location ) {
		var selectors = appearanceSelectors[ location ];
		var textRules = getTextRules(
			appearanceUtils,
			queryFirst( selectors.text )
		);
		var backgroundColor = getBackgroundColor(
			appearanceUtils,
			selectors.background
		);

		return appearanceUtils.normalizeAppearanceForStripe( {
			variables: {
				colorBackground: backgroundColor,
				colorText: textRules.color,
				fontFamily: textRules.fontFamily,
				fontSizeBase: textRules.fontSize,
			},
			theme: isColorLight( appearanceUtils, backgroundColor )
				? 'stripe'
				: 'night',
			labels: 'above',
			rules: {
				'.Text': textRules,
				'.Text--redirect': textRules,
			},
		} );
	}

	function getStripeElementsOptions( config, location ) {
		var appearanceUtils =
			( window.wc && window.wc.woopayments && window.wc.woopayments.appearance ) ||
			null;
		var options = {};
		var appearance;
		var fontRules;

		if ( ! appearanceUtils ) {
			return options;
		}

		appearance = appearanceUtils.getCachedAppearance(
			location,
			config.stylesCacheVersion
		);
		if ( ! appearance ) {
			appearance = getAppearanceFromPage( appearanceUtils, location );
			appearanceUtils.dispatchAppearanceEvent( appearance, location );
			appearanceUtils.setCachedAppearance(
				location,
				config.stylesCacheVersion,
				appearance
			);
		}
		options.appearance = appearance;

		fontRules = appearanceUtils.getFontRulesFromPage();
		if ( fontRules.length ) {
			options.fonts = fontRules;
		}

		return options;
	}

	function getMessagingOptions( config ) {
		var amount = config.isCart
			? parseIntOrReturnZero( config.cartTotal )
			: parseIntOrReturnZero( getProductAmount( config ) );

		return {
			amount: amount,
			currency: config.currencyCode || 'USD',
			paymentMethodTypes: config.paymentMethods || [],
			countryCode: config.country,
		};
	}

	// The message takes the bottom margin of the price or the cart totals table above it; returns that margin.
	function setMessageMargin() {
		var paymentMessageContainer = getMessageContainer();
		var priceElement =
			document.querySelector( '.price' ) ||
			document.querySelector( '.wp-block-woocommerce-product-price' );
		var cartTotalElement = document.querySelector(
			'.cart_totals .shop_table'
		);
		var referenceElement = priceElement || cartTotalElement;
		var style;
		var bottomMargin;

		if ( ! paymentMessageContainer || ! referenceElement ) {
			return null;
		}

		style = window.getComputedStyle( referenceElement );
		bottomMargin = style.marginBottom || '0px';
		paymentMessageContainer.style.setProperty(
			'--wc-bnpl-margin-bottom',
			bottomMargin
		);

		return bottomMargin;
	}

	function updateLayoutForReadyElement( config, paymentMessageElement ) {
		var bottomMargin = setMessageMargin();

		if ( null === bottomMargin ) {
			return;
		}

		if (
			paymentMessageElement &&
			typeof paymentMessageElement.on === 'function'
		) {
			paymentMessageElement.on( 'ready', function () {
				// A cart update moves the element into a new container, so the current one is read here.
				if ( config.isCart && getMessageContainer() ) {
					getMessageContainer().classList.add( 'ready' );
					pinCartMessageHeight( bottomMargin );
				}
			} );
		}
	}

	// Keep the cart message's height on .cart-collaterals, which a cart update leaves in place, for the stylesheet's
	// --wc-bnpl-* rules, so the next Update cart holds the space and shows a loader of that size. Client 11.2.0 measures
	// once, two seconds after ready (product-details/bnpl-site-messaging/index.js:144-197), which reads Stripe's unsized
	// frame while the message is below the fold; the values are written again each time the frame grows.
	var cartFrameObserver = null;
	var pinnedCartFrameHeight = 0;

	function pinCartMessageHeight( bottomMargin ) {
		var cartCollaterals = document.querySelector( '.cart-collaterals' );
		var frame = document.querySelector(
			'.cart_totals .__PrivateStripeElement'
		);

		if (
			! cartCollaterals ||
			! frame ||
			typeof window.ResizeObserver !== 'function'
		) {
			return;
		}

		if ( ! cartFrameObserver ) {
			cartFrameObserver = new window.ResizeObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					writeCartMessageHeight(
						cartCollaterals,
						entry.target,
						bottomMargin
					);
				} );
			} );
		}
		// A cart update replaces the frame; only the current one is watched.
		cartFrameObserver.disconnect();
		cartFrameObserver.observe( frame );
	}

	function writeCartMessageHeight( cartCollaterals, frame, bottomMargin ) {
		var message = getMessageContainer();
		var frameHeight = parseFloat( window.getComputedStyle( frame ).height );
		var marginBottom = parseFloat( bottomMargin ) || 0;

		// Only growth counts: a hidden or replaced message reports a smaller frame, and the pin keeps the space.
		if ( ! message || ! ( frameHeight > pinnedCartFrameHeight ) ) {
			return;
		}
		pinnedCartFrameHeight = frameHeight;

		// The message's own height comes from the pinned variable; drop it to measure the message as rendered.
		cartCollaterals.style.removeProperty( '--wc-bnpl-height' );
		cartCollaterals.style.setProperty(
			'--wc-bnpl-height',
			parseFloat( window.getComputedStyle( message ).height ) +
				marginBottom +
				'px'
		);
		cartCollaterals.style.setProperty(
			'--wc-bnpl-container-height',
			frameHeight - 12 + 'px'
		);
		cartCollaterals.style.setProperty(
			'--wc-bnpl-loader-margin',
			marginBottom + 2 + 'px'
		);
		message.style.setProperty( '--wc-bnpl-margin-bottom', '-4px' );
	}

	// Client 11.1.0 client/checkout/api/index.js:57-71 waits up to 600 seconds for Stripe.js, checking every 100 ms,
	// because page optimizers can defer it past the page render.
	function waitForStripe() {
		return new Promise( function ( resolve, reject ) {
			var waited = 0;
			var timer;

			if ( typeof window.Stripe === 'function' ) {
				resolve( window.Stripe );
				return;
			}

			timer = window.setInterval( function () {
				waited += 100;
				if ( typeof window.Stripe === 'function' ) {
					window.clearInterval( timer );
					resolve( window.Stripe );
				} else if ( waited > 600 * 1000 ) {
					window.clearInterval( timer );
					reject( new Error( 'Stripe object not found' ) );
				}
			}, 100 );
		} );
	}

	function initializeBnplSiteMessaging( config ) {
		var paymentMessageContainer = getMessageContainer();
		var stripe;
		var elements;
		var paymentMessageElement;
		var location;

		if (
			! paymentMessageContainer ||
			! window.Stripe ||
			! config.publishableKey
		) {
			return null;
		}

		if ( ! config.isCart && ! config.shouldShowPMME ) {
			paymentMessageContainer.style.setProperty( 'display', 'none' );
		}

		location = getElementsLocation( config );
		stripe = window.Stripe( config.publishableKey, {
			locale: config.locale || 'auto',
			stripeAccount: config.accountId || undefined,
		} );
		elements = stripe.elements( getStripeElementsOptions( config, location ) );
		paymentMessageElement = elements.create(
			'paymentMethodMessaging',
			getMessagingOptions( config )
		);
		paymentMessageElement.mount( '#payment-method-message' );
		updateLayoutForReadyElement( config, paymentMessageElement );

		return paymentMessageElement;
	}

	// Core replaces the cart totals on Update cart, message container included. The element moves into the new container
	// with the new total, so the page keeps one Stripe instance and element once one exists (client 11.2.0 builds a new
	// pair each time, product-details/index.js:174-190, bnpl-site-messaging/index.js:51-87).
	function remountCartMessage( config, paymentMessageElement ) {
		var options = getMessagingOptions( config );

		// No element was built at load (no message container then): build one now.
		if ( ! paymentMessageElement ) {
			initializeBnplSiteMessaging( config );
			return;
		}

		paymentMessageElement.unmount();
		paymentMessageElement.update( {
			amount: options.amount,
			currency: options.currency,
		} );
		paymentMessageElement.mount( '#payment-method-message' );
		setMessageMargin();
	}

	function bindMessagingEvents( config, paymentMessageElement ) {
		var quantityInput = $( '.quantity input[type=number]' );
		var productCurrency = getProductCurrency( config );
		var hasVariations =
			Object.keys( config.productVariations || {} ).length > 1;

		function updateBnplPaymentMessage( amount, currency, quantity ) {
			var totalAmount =
				parseIntOrReturnZero( amount ) *
				parseIntOrReturnZero( quantity || 1 );

			if (
				totalAmount <= 0 ||
				! currency ||
				! paymentMessageElement ||
				typeof paymentMessageElement.update !== 'function'
			) {
				return;
			}

			paymentMessageElement.update( {
				amount: totalAmount,
				currency: currency,
			} );
		}

		function resetBnplPaymentMessage() {
			updateBnplPaymentMessage(
				getProductAmount( config ),
				productCurrency,
				quantityInput.val()
			);
		}

		quantityInput.on( 'change', function ( event ) {
			var amount = getProductAmount( config );
			var variationId = $( 'input[name="variation_id"]' ).val();
			var variation = hasVariations
				? getProductVariation( config, variationId )
				: null;

			if ( variation ) {
				amount = variation.amount;
			}

			updateBnplPaymentMessage(
				amount,
				productCurrency,
				event.target.value
			);

			request( config, 'check_bnpl_availability', {
				security: config.nonce.is_bnpl_available,
				price: amount * event.target.value,
				currency: productCurrency,
				country: config.country,
			} )
				.then( function ( response ) {
					if ( response.success && response.data.is_available ) {
						$( '#payment-method-message' ).slideDown();
					} else {
						$( '#payment-method-message' ).slideUp();
					}
				} )
				.catch( function () {} );
		} );

		$( document.body ).on( 'updated_cart_totals', function () {
			$( '#payment-method-message' ).before(
				'<div class="pmme-loading"></div>'
			);
			$( '#payment-method-message' ).hide();
			request( config, 'get_cart_total', {
				security: config.nonce.get_cart_total,
			} )
				.then( function ( response ) {
					config.cartTotal = response.total;
					remountCartMessage( config, paymentMessageElement );
					window.setTimeout( function () {
						$( '.pmme-loading' ).remove();
						$( '#payment-method-message' ).show();
						$( '#payment-method-message' ).addClass( 'pmme-updated' );
					}, 1000 );
				} )
				.catch( function () {
					// Without a new total the message stays hidden rather than show the old amount.
					$( '.pmme-loading' ).remove();
				} );
		} );

		if ( hasVariations ) {
			$( '.single_variation_wrap' ).on( 'show_variation', function (
				event,
				variation
			) {
				var productVariation = getProductVariation(
					config,
					variation.variation_id
				);

				if ( ! productVariation ) {
					return;
				}

				updateBnplPaymentMessage(
					productVariation.amount,
					productCurrency,
					quantityInput.val()
				);
			} );

			$( '.variations' ).on( 'change', function ( event ) {
				if ( event.target.value === '' ) {
					resetBnplPaymentMessage();
				}
			} );

			$( '.reset_variations' ).on( 'click', resetBnplPaymentMessage );
		}
	}

	$( function () {
		var config = getConfig();
		var paymentMessageContainer;
		var paymentMessageElement;

		if ( ! config || config.isCartBlock ) {
			return;
		}

		paymentMessageContainer = getMessageContainer();
		if ( ! config.shouldInitializePMME ) {
			if ( paymentMessageContainer ) {
				paymentMessageContainer.style.setProperty( 'display', 'none' );
			}
			return;
		}

		if ( ! config.publishableKey ) {
			return;
		}

		function mountAndBind() {
			paymentMessageElement = initializeBnplSiteMessaging( config );
			bindMessagingEvents( config, paymentMessageElement );
		}

		// Client 11.1.0 product-details/bnpl-site-messaging/index.js:81 awaits api.getStripe(), which waits for Stripe.js.
		if ( typeof window.Stripe === 'function' ) {
			mountAndBind();
		} else {
			waitForStripe().then( mountAndBind, function () {} );
		}
	} );
} )( jQuery, window, document );
