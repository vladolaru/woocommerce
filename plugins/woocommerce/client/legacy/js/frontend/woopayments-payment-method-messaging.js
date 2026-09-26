/* global jQuery */
( function ( $, window, document ) {
	'use strict';

	var elementsLocations = {
		bnplProductPage: 'bnpl_product_page',
		bnplClassicCart: 'bnpl_classic_cart',
	};

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

	function getStripeElementsOptions( config, location ) {
		var appearanceUtils = window.wcpayAppearance || null;
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
		if ( appearance ) {
			options.appearance = appearance;
		}

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

	function updateLayoutForReadyElement( config, paymentMessageElement ) {
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
			return;
		}

		style = window.getComputedStyle( referenceElement );
		bottomMargin = style.marginBottom || '0px';
		paymentMessageContainer.style.setProperty(
			'--wc-bnpl-margin-bottom',
			bottomMargin
		);

		if (
			paymentMessageElement &&
			typeof paymentMessageElement.on === 'function'
		) {
			paymentMessageElement.on( 'ready', function () {
				if ( config.isCart ) {
					paymentMessageContainer.classList.add( 'ready' );
				}
			} );
		}
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

	function bindProductEvents( config, paymentMessageElement ) {
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
			} ).then( function ( response ) {
				config.cartTotal = response.total;
				initializeBnplSiteMessaging( config );
				window.setTimeout( function () {
					$( '.pmme-loading' ).remove();
					$( '#payment-method-message' ).show();
					$( '#payment-method-message' ).addClass( 'pmme-updated' );
				}, 1000 );
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

		paymentMessageElement = initializeBnplSiteMessaging( config );
		bindProductEvents( config, paymentMessageElement );
	} );
} )( jQuery, window, document );
