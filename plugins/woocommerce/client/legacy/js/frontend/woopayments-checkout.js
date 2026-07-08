/* global jQuery, wcpay_core_checkout_config */
( function ( $, window, document ) {
	'use strict';

	var defaultGatewayId = 'woocommerce_payments';
	var configObjectPrefix = 'wcpay_core_checkout_config_';
	var baseConfig = window.wcpay_core_checkout_config || {};
	var appearanceUtils = window.wcpayAppearance;
	var config = baseConfig;
	var gatewayId = config.gatewayId || defaultGatewayId;
	var stripe = null;
	var elements = null;
	var paymentElement = null;
	var paymentElementGatewayId = null;
	var paymentElementContainer = null;
	var isSubmittingWithPaymentMethod = false;
	var isSubmittingWithSetupIntent = false;
	var cardBrandIconsHydratedLabel = null;
	var cardBrandIconsHydrationCleanup = null;
	var classicCheckoutAppearanceLocation = 'classic_checkout';
	var classicInputStyleProps = [
		'backgroundColor',
		'border',
		'borderColor',
		'borderRadius',
		'borderStyle',
		'borderWidth',
		'boxShadow',
		'color',
		'fontFamily',
		'fontSize',
		'fontWeight',
		'letterSpacing',
		'lineHeight',
		'outline',
		'padding',
		'paddingTop',
		'paddingRight',
		'paddingBottom',
		'paddingLeft',
		'textDecoration',
		'textShadow',
		'textTransform',
		'transition',
	];
	var classicTextStyleProps = [
		'color',
		'fontFamily',
		'fontSize',
		'fontWeight',
		'letterSpacing',
		'lineHeight',
		'padding',
		'paddingTop',
		'paddingRight',
		'paddingBottom',
		'paddingLeft',
		'textDecoration',
		'textShadow',
		'textTransform',
		'transition',
	];
	var classicBackgroundSelectors = [
		'li.wc_payment_method .wc-payment-form',
		'li.wc_payment_method .payment_box',
		'#payment',
		'#order_review',
		'form.checkout',
		'body',
	];
	var checkoutBillingFieldIds = [
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'billing_phone',
		'billing_city',
		'billing_country',
		'billing_address_1',
		'billing_address_2',
		'billing_postcode',
		'billing_state',
	];
	var copyTestNumberSuccessDuration = 2000;

	function getGatewayConfigObjectName( paymentGatewayId ) {
		return (
			configObjectPrefix +
			String( paymentGatewayId || '' ).replace( /[^A-Za-z0-9_]/g, '_' )
		);
	}

	function getConfigForGateway( paymentGatewayId ) {
		var keyedConfig =
			window[ getGatewayConfigObjectName( paymentGatewayId ) ];

		if ( keyedConfig && typeof keyedConfig === 'object' ) {
			return keyedConfig;
		}

		if ( ( baseConfig.gatewayId || defaultGatewayId ) === paymentGatewayId ) {
			return baseConfig;
		}

		return null;
	}

	function getSelectedGatewayId() {
		var selected = document.querySelector(
			'input[name="payment_method"]:checked'
		);

		return selected ? selected.value : '';
	}

	function setCurrentGatewayConfig( paymentGatewayId ) {
		var nextGatewayId =
			paymentGatewayId || getSelectedGatewayId() || gatewayId;
		var nextConfig = getConfigForGateway( nextGatewayId ) || baseConfig;

		config = nextConfig;
		gatewayId = nextConfig.gatewayId || nextGatewayId || defaultGatewayId;
	}

	function getKnownGatewayIds() {
		var gatewayIds = {};

		if ( baseConfig.gatewayId || window.wcpay_core_checkout_config ) {
			gatewayIds[ baseConfig.gatewayId || defaultGatewayId ] = true;
		}

		Object.keys( window ).forEach( function ( key ) {
			var gatewayConfig;

			if ( key.indexOf( configObjectPrefix ) !== 0 ) {
				return;
			}

			gatewayConfig = window[ key ];
			if ( gatewayConfig && gatewayConfig.gatewayId ) {
				gatewayIds[ gatewayConfig.gatewayId ] = true;
			}
		} );

		Array.prototype.slice
			.call( document.querySelectorAll( 'input[name="payment_method"]' ) )
			.forEach( function ( input ) {
				if ( getConfigForGateway( input.value ) ) {
					gatewayIds[ input.value ] = true;
				}
			} );

		return Object.keys( gatewayIds );
	}

	function isSelectedGateway() {
		var selectedGatewayId = getSelectedGatewayId();

		if ( selectedGatewayId ) {
			return selectedGatewayId === gatewayId;
		}

		return (
			$( 'input[name="payment_method"]' ).filter(
				'[value="' + gatewayId + '"]'
			).length === 1
		);
	}

	function getPaymentMethodInput( paymentGatewayId ) {
		return Array.prototype.slice
			.call( document.querySelectorAll( 'input[name="payment_method"]' ) )
			.find( function ( input ) {
				return input.value === paymentGatewayId;
			} );
	}

	function getGatewayPaymentContainer( paymentGatewayId ) {
		var input = getPaymentMethodInput( paymentGatewayId );
		var gatewayElement = input && input.closest ? input.closest( 'li' ) : null;
		var container =
			gatewayElement &&
			gatewayElement.querySelector( '#wcpay-core-payment-element' );

		return container || document.getElementById( 'wcpay-core-payment-element' );
	}

	function setError( message ) {
		var errorElement = document.getElementById(
			'wcpay-core-payment-errors'
		);
		if ( ! errorElement ) {
			return;
		}

		errorElement.textContent = message || '';
		errorElement.hidden = ! message;
	}

	function copyTestNumber( event ) {
		var button;
		var testNumber;
		var icon;

		if ( ! ( event.target instanceof window.Element ) ) {
			return;
		}

		button = event.target.closest( '.js-woopayments-copy-test-number' );
		testNumber = button && button.textContent ? button.textContent.trim() : '';

		if ( ! button || ! testNumber ) {
			return;
		}

		event.preventDefault();
		icon = button.querySelector( 'i' );
		if ( icon ) {
			icon.setAttribute( 'aria-hidden', 'true' );
		}

		if (
			window.navigator.clipboard &&
			typeof window.navigator.clipboard.writeText === 'function'
		) {
			window.navigator.clipboard.writeText( testNumber );
		} else if ( typeof window.prompt === 'function' ) {
			window.prompt( 'Copy test card number:', testNumber );
		}

		button.classList.add( 'state--success' );
		window.setTimeout( function () {
			button.classList.remove( 'state--success' );
		}, copyTestNumberSuccessDuration );
	}

	function recordUserEvent( eventName, eventProperties ) {
		var ajaxUrl = config.ajaxUrl || config.ajax_url;
		var nonce = config.platformTrackerNonce || config.platform_tracker_nonce;
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

	function isLinkEnabled() {
		var paymentMethodsConfig = config.paymentMethodsConfig || {};

		return (
			paymentMethodsConfig.link !== undefined &&
			paymentMethodsConfig.card !== undefined
		);
	}

	function getStripePaymentMethodTypes() {
		if (
			Array.isArray( config.paymentMethodTypes ) &&
			config.paymentMethodTypes.length
		) {
			return config.paymentMethodTypes;
		}

		return isLinkEnabled() ? [ 'card', 'link' ] : [ 'card' ];
	}

	function getReusablePaymentMethodTerms( value ) {
		var paymentMethodsConfig = config.paymentMethodsConfig || {};

		return Object.keys( paymentMethodsConfig ).reduce( function (
			terms,
			paymentMethodId
		) {
			if (
				paymentMethodId !== 'link' &&
				paymentMethodsConfig[ paymentMethodId ].isReusable
			) {
				terms[ paymentMethodId ] = value;
			}

			return terms;
		}, {} );
	}

	function isAddPaymentMethodForm() {
		return !! document.getElementById( 'add_payment_method' );
	}

	function shouldSavePaymentMethod() {
		var savePaymentMethodCheckbox = document.getElementById(
			'wc-' + gatewayId + '-new-payment-method'
		);

		return !! (
			savePaymentMethodCheckbox && savePaymentMethodCheckbox.checked
		);
	}

	function isUsingSavedPaymentMethod() {
		var newPaymentTokenInput = document.getElementById(
			'wc-' + gatewayId + '-payment-token-new'
		);

		return !! newPaymentTokenInput && ! newPaymentTokenInput.checked;
	}

	function getInputValue( id ) {
		var input = document.getElementById( id );
		return input && typeof input.value === 'string' ? input.value : '';
	}

	function hasCheckoutBillingFields() {
		return checkoutBillingFieldIds.some( function ( id ) {
			return !! document.getElementById( id );
		} );
	}

	function getCheckoutBillingDetails() {
		var firstName = getInputValue( 'billing_first_name' );
		var lastName = getInputValue( 'billing_last_name' );
		var postalCode = getInputValue( 'billing_postcode' ).trim();

		if ( ! hasCheckoutBillingFields() ) {
			return null;
		}

		return {
			name: ( firstName + ' ' + lastName ).trim(),
			email: getInputValue( 'billing_email' ),
			phone: getInputValue( 'billing_phone' ),
			address: {
				city: getInputValue( 'billing_city' ),
				country: getInputValue( 'billing_country' ),
				line1: getInputValue( 'billing_address_1' ),
				line2: getInputValue( 'billing_address_2' ),
				postal_code: postalCode,
				state: getInputValue( 'billing_state' ),
			},
		};
	}

	function getStripePaymentElementOptions() {
		return {
			fields: {
				billingDetails: {
					name: 'never',
					email: 'never',
					phone: 'never',
					address: {
						country: 'never',
						line1: 'never',
						line2: 'never',
						city: 'never',
						state: 'never',
						postalCode: 'never',
					},
				},
			},
			wallets: {
				applePay: 'never',
				googlePay: 'never',
				link:
					isLinkEnabled() && ! isAddPaymentMethodForm()
						? 'auto'
						: 'never',
			},
			terms: getReusablePaymentMethodTerms(
				shouldSavePaymentMethod() || config.cartContainsSubscription
					? 'always'
					: 'never'
			),
		};
	}

	function updatePaymentElementTerms( event ) {
		if (
			! event.target ||
			event.target.id !== 'wc-' + gatewayId + '-new-payment-method' ||
			! paymentElement ||
			typeof paymentElement.update !== 'function'
		) {
			return;
		}

		paymentElement.update( {
			terms: getReusablePaymentMethodTerms(
				event.target.checked ? 'always' : 'never'
			),
		} );
	}

	function getBrightness( color ) {
		return ( color.r * 299 + color.g * 587 + color.b * 114 ) / 1000;
	}

	function compositeAgainstWhite( color ) {
		return {
			r: Math.round( color.r * color.a + 255 * ( 1 - color.a ) ),
			g: Math.round( color.g * color.a + 255 * ( 1 - color.a ) ),
			b: Math.round( color.b * color.a + 255 * ( 1 - color.a ) ),
			a: 1,
		};
	}

	function isColorLight( color ) {
		var parsedColor = appearanceUtils.parseColor( color );
		if ( ! parsedColor ) {
			return true;
		}

		return (
			getBrightness(
				parsedColor.a < 1
					? compositeAgainstWhite( parsedColor )
					: parsedColor
			) > 125
		);
	}

	function toDashed( value ) {
		return value.replace( /[A-Z]/g, function ( match ) {
			return '-' + match.toLowerCase();
		} );
	}

	function queryFirst( selectors ) {
		var selectorList = Array.isArray( selectors ) ? selectors : [ selectors ];
		var index;
		var element;

		for ( index = 0; index < selectorList.length; index++ ) {
			try {
				element = document.querySelector( selectorList[ index ] );
			} catch ( error ) {
				element = null;
			}

			if ( element ) {
				return element;
			}
		}

		return null;
	}

	function getElementStyles( element, properties ) {
		var styles;

		if ( ! element || ! window.getComputedStyle ) {
			return {};
		}

		styles = window.getComputedStyle( element );
		return properties.reduce( function ( output, property ) {
			var rawValue = styles.getPropertyValue( toDashed( property ) );
			var value;

			if ( appearanceUtils.containsAlphaColor( rawValue ) ) {
				return output;
			}

			value = appearanceUtils.normalizeAppearanceValueForStripe( rawValue );
			if ( value ) {
				output[ property ] = value;
			}
			return output;
		}, {} );
	}

	function getBackgroundColor() {
		var index;
		var element;
		var color;
		var parsedColor;

		for ( index = 0; index < classicBackgroundSelectors.length; index++ ) {
			element = queryFirst( classicBackgroundSelectors[ index ] );
			if ( ! element || ! window.getComputedStyle ) {
				continue;
			}

			color = window.getComputedStyle( element ).backgroundColor;
			parsedColor = appearanceUtils.parseColor( color );
			if ( color && parsedColor && parsedColor.a >= 0.5 ) {
				return appearanceUtils.normalizeAppearanceValueForStripe( color );
			}
		}

		return '#ffffff';
	}

	function getClassicCheckoutAppearanceFromPage() {
		var input = queryFirst( [
			'#billing_first_name',
			'form.checkout input[type="text"]',
			'form.checkout input[type="email"]',
			'form.checkout .input-text',
		] );
		var label = queryFirst( [
			'.woocommerce-checkout .form-row label',
			'form.checkout label',
		] );
		var text = queryFirst( [
			'#payment .payment_methods li .payment_box fieldset',
			'.woocommerce-checkout .form-row',
			'form.checkout',
			'.woocommerce',
		] );
		var backgroundColor = getBackgroundColor();
		var inputRules = getElementStyles( input, classicInputStyleProps );
		var labelRules = getElementStyles( label || input, classicTextStyleProps );
		var textRules = getElementStyles(
			text || label || input,
			classicTextStyleProps
		);
		var tabRules = getElementStyles( input, classicInputStyleProps );
		var appearance;

		if ( ! input || ! Object.keys( inputRules ).length ) {
			return null;
		}

		appearance = {
			variables: {
				colorBackground: backgroundColor,
				colorText: textRules.color,
				fontFamily: textRules.fontFamily,
				fontSizeBase: textRules.fontSize,
			},
			theme: isColorLight( backgroundColor ) ? 'stripe' : 'night',
			labels: 'floating',
			rules: {
				'.Input': inputRules,
				'.Input--invalid': inputRules,
				'.Label': labelRules,
				'.Label--resting': {
					fontSize: labelRules.fontSize,
				},
				'.Block': {
					backgroundColor: backgroundColor,
				},
				'.Tab': tabRules,
				'.Tab:hover': tabRules,
				'.Tab--selected': tabRules,
				'.TabIcon:hover': {
					color: tabRules.color,
				},
				'.TabIcon--selected': {
					color: tabRules.color,
				},
				'.Text': textRules,
				'.Text--redirect': textRules,
			},
		};

		return appearanceUtils.normalizeAppearanceForStripe( appearance );
	}

	function getClassicCheckoutAppearance() {
		var version = config.stylesCacheVersion || '';
		var cachedAppearance = appearanceUtils.getCachedAppearance(
			classicCheckoutAppearanceLocation,
			version
		);
		var appearance;

		if ( cachedAppearance ) {
			return cachedAppearance;
		}

		appearance = getClassicCheckoutAppearanceFromPage();
		if ( ! appearance ) {
			return null;
		}

		appearanceUtils.dispatchAppearanceEvent(
			appearance,
			classicCheckoutAppearanceLocation
		);
		if ( appearanceUtils.isAppearanceValid( appearance ) ) {
			appearanceUtils.setCachedAppearance(
				classicCheckoutAppearanceLocation,
				version,
				appearance
			);
			window.dispatchEvent( new window.Event( 'wcpay-appearance-cached' ) );
		}

		return appearance;
	}

	function recordPlaceOrderButtonClick( event ) {
		if (
			! ( event.target instanceof window.Element ) ||
			! event.target.closest( '#place_order' ) ||
			! isSelectedGateway()
		) {
			return;
		}

		recordUserEvent( 'checkout_place_order_button_click' );
	}

	function ensureHiddenField( form, name, value ) {
		var field = form.find( 'input[name="' + name + '"]' );
		if ( ! field.length ) {
			field = $( '<input />', {
				type: 'hidden',
				name: name,
			} ).appendTo( form );
		}

		field.val( value || '' );
	}

	function ensureFormHiddenField( formElement, name, value ) {
		var field = formElement.querySelector( 'input[name="' + name + '"]' );
		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = name;
			formElement.appendChild( field );
		}

		field.value = value || '';
	}

	function getFraudPreventionToken() {
		return (
			config.fraudPreventionToken ||
			window.wcpayFraudPreventionToken ||
			''
		);
	}

	function appendPaymentFields( form, paymentMethod, error ) {
		var fingerprint =
			paymentMethod && paymentMethod.card
				? paymentMethod.card.fingerprint
				: '';

		ensureHiddenField(
			form,
			'wcpay-payment-method',
			paymentMethod && paymentMethod.id ? paymentMethod.id : ''
		);
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-code',
			error && error.code ? error.code : ''
		);
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-message',
			error && error.message ? error.message : ''
		);
		ensureHiddenField( form, 'wcpay-fingerprint', fingerprint || '' );
		ensureHiddenField(
			form,
			'wcpay-fraud-prevention-token',
			getFraudPreventionToken()
		);
	}

	function getSetupIntentData( response ) {
		if ( response && response.data ) {
			return response.data;
		}

		return response || {};
	}

	function confirmSetupIntentIfNeeded( setupIntent ) {
		if ( setupIntent && setupIntent.status === 'succeeded' ) {
			return Promise.resolve( setupIntent );
		}

		if (
			! setupIntent ||
			! setupIntent.client_secret ||
			! stripe ||
			! stripe.confirmSetup
		) {
			return Promise.reject(
				new Error( config.confirmationErrorMessage || '' )
			);
		}

		return stripe
			.confirmSetup( {
				clientSecret: setupIntent.client_secret,
				redirect: 'if_required',
			} )
			.then( function ( result ) {
				if ( result.error ) {
					return Promise.reject( result.error );
				}

				return result.setupIntent || setupIntent;
			} );
	}

	function createSetupIntent( paymentMethodId ) {
		return new Promise( function ( resolve, reject ) {
			$.post( config.ajaxUrl, {
				action: 'create_setup_intent',
				'wcpay-payment-method': paymentMethodId,
				_ajax_nonce: config.createSetupIntentNonce || '',
			} )
				.done( function ( response ) {
					var setupIntent = getSetupIntentData( response );
					if ( response && response.success === false ) {
						reject( setupIntent.error || setupIntent );
						return;
					}

					confirmSetupIntentIfNeeded( setupIntent )
						.then( resolve )
						.catch( reject );
				} )
				.fail( function () {
					reject( new Error( config.confirmationErrorMessage || '' ) );
				} );
		} );
	}

	function getStripeElementsOptions() {
		var amount = Number( config.cartTotal || 0 );
		var appearance;
		var fontRules;
		var options = {
			mode:
				! isAddPaymentMethodForm() && amount > 0 && isFinite( amount )
					? 'payment'
					: 'setup',
			loader: 'never',
			currency: ( config.currency || 'usd' ).toLowerCase(),
			paymentMethodCreation: 'manual',
			paymentMethodTypes: getStripePaymentMethodTypes(),
		};

		appearance = getClassicCheckoutAppearance();
		if ( appearance ) {
			options.appearance = appearance;
		}

		fontRules = appearanceUtils.getFontRulesFromPage();
		if ( fontRules.length ) {
			options.fonts = fontRules;
		}

		if ( 'payment' === options.mode ) {
			options.amount = amount;
		}

		return options;
	}

	function getCardBrandIcons() {
		var paymentMethodsConfig = config.paymentMethodsConfig || {};
		var cardConfig = paymentMethodsConfig.card || {};

		return Array.isArray( cardConfig.cardBrandIcons )
			? cardConfig.cardBrandIcons
			: [];
	}

	function getCardBrandLogosLabel() {
		var label = document.querySelector(
			'label[for="payment_method_' + gatewayId + '"]'
		);
		var input;
		var listItem;

		if ( label ) {
			return label;
		}

		input = document.querySelector(
			'input[name="payment_method"][value="' + gatewayId + '"]'
		);
		listItem = input && input.closest ? input.closest( 'li' ) : null;

		return listItem ? listItem.querySelector( 'label' ) : null;
	}

	function getMaxVisibleCardBrandIcons() {
		if ( window.innerWidth >= 768 && window.innerWidth <= 900 ) {
			return 1;
		}

		return 4;
	}

	function createCardBrandImage( icon ) {
		var image = document.createElement( 'img' );
		image.src = icon.src || '';
		image.alt = icon.alt || icon.id || '';
		image.width = 38;
		image.height = 24;
		return image;
	}

	function cleanupCardBrandIconsHydration() {
		if ( ! cardBrandIconsHydrationCleanup ) {
			return;
		}

		cardBrandIconsHydrationCleanup();
		cardBrandIconsHydratedLabel = null;
		cardBrandIconsHydrationCleanup = null;
	}

	function closeCardBrandPopover( popover, logos, handlers, restoreFocus ) {
		handlers = handlers || ( popover && popover.__wcpayHandlers );

		if ( popover && popover.parentNode ) {
			popover.parentNode.removeChild( popover );
		}

		if ( logos ) {
			logos.setAttribute( 'aria-expanded', 'false' );
		}

		if ( handlers ) {
			document.removeEventListener( 'mousedown', handlers.outsideClick );
			document.removeEventListener( 'keydown', handlers.escapeKey );
		}

		if ( popover ) {
			popover.__wcpayHandlers = null;
		}

		if (
			restoreFocus &&
			logos &&
			logos.focus &&
			document.body.contains( logos )
		) {
			logos.focus();
		}
	}

	function createCardBrandPopover( label, logos, icons ) {
		var popover = document.createElement( 'span' );
		var description = document.createElement( 'span' );
		var handlers = {};
		var itemsPerRow = Math.min( icons.length, 5 );

		popover.id = 'wcpay-core-payment-methods-popover';
		popover.className = 'logo-popover payment-methods--logos-popover';
		popover.setAttribute( 'role', 'dialog' );
		popover.setAttribute( 'tabindex', '-1' );
		popover.setAttribute(
			'aria-label',
			config.cardBrandPopoverLabel || 'Supported credit card brands'
		);
		popover.setAttribute( 'aria-describedby', popover.id + '-description' );
		popover.style.gridTemplateColumns =
			'repeat(' + itemsPerRow + ', 38px)';
		popover.style.width =
			itemsPerRow * 38 + ( itemsPerRow - 1 ) * 8 + 16 + 'px';

		description.id = popover.id + '-description';
		description.className = 'screen-reader-text';
		description.textContent = icons
			.map( function ( icon ) {
				return icon.alt || icon.id || '';
			} )
			.filter( Boolean )
			.join( ', ' );
		popover.appendChild( description );

		icons.forEach( function ( icon ) {
			popover.appendChild( createCardBrandImage( icon ) );
		} );

		handlers.outsideClick = function ( event ) {
			if (
				! popover.contains( event.target ) &&
				! logos.contains( event.target )
			) {
				closeCardBrandPopover( popover, logos, handlers );
			}
		};
		handlers.escapeKey = function ( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeCardBrandPopover( popover, logos, handlers, true );
			}
		};
		popover.__wcpayHandlers = handlers;

		label.appendChild( popover );
		document.addEventListener( 'mousedown', handlers.outsideClick );
		document.addEventListener( 'keydown', handlers.escapeKey );
		logos.setAttribute( 'aria-expanded', 'true' );
		if ( popover.focus ) {
			popover.focus();
		}

		return popover;
	}

	function updateCardBrandLogos( logos, label, icons ) {
		var maxVisibleIcons = getMaxVisibleCardBrandIcons();
		var visibleIcons = icons.slice( 0, maxVisibleIcons );
		var additionalIcons = icons.slice( visibleIcons.length );
		var count;

		logos.innerHTML = '';
		visibleIcons.forEach( function ( icon ) {
			logos.appendChild( createCardBrandImage( icon ) );
		} );

		if ( additionalIcons.length ) {
			count = document.createElement( 'span' );
			count.className = 'payment-methods--logos-count';
			count.textContent = '+ ' + additionalIcons.length;
			logos.appendChild( count );
			logos.setAttribute( 'role', 'button' );
			logos.setAttribute( 'tabindex', '0' );
			logos.setAttribute( 'aria-haspopup', 'dialog' );
			logos.setAttribute(
				'aria-controls',
				'wcpay-core-payment-methods-popover'
			);
			logos.setAttribute(
				'aria-label',
				config.cardBrandLogosLabel || 'Show all supported credit card brands'
			);
			logos.setAttribute(
				'aria-expanded',
				label.querySelector( '.logo-popover' ) ? 'true' : 'false'
			);
		} else {
			logos.removeAttribute( 'role' );
			logos.removeAttribute( 'tabindex' );
			logos.removeAttribute( 'aria-haspopup' );
			logos.removeAttribute( 'aria-controls' );
			logos.removeAttribute( 'aria-label' );
			logos.removeAttribute( 'aria-expanded' );
		}
	}

	function hydrateCardBrandIcons() {
		var label = getCardBrandLogosLabel();
		var icons = getCardBrandIcons();
		var existingLogos;
		var sourceLogos;
		var container;
		var logos;
		var resizeHandler;

		if (
			cardBrandIconsHydratedLabel &&
			! document.body.contains( cardBrandIconsHydratedLabel )
		) {
			cleanupCardBrandIconsHydration();
		}

		if ( ! label || ! icons.length ) {
			return;
		}

		existingLogos = label.querySelector(
			'[data-testid="payment-methods-logos"]'
		);
		if ( existingLogos ) {
			return;
		}

		sourceLogos =
			label.querySelector( '.wcpay-core-card-brand-icons' ) ||
			label.querySelector( '.payment-methods--logos' ) ||
			label.querySelector( 'img' );
		if ( ! sourceLogos ) {
			return;
		}

		cleanupCardBrandIconsHydration();

		container = document.createElement( 'span' );
		container.className = 'payment-methods--logos';
		logos = document.createElement( 'span' );
		logos.setAttribute( 'data-testid', 'payment-methods-logos' );
		container.appendChild( logos );

		updateCardBrandLogos( logos, label, icons );

		logos.addEventListener( 'click', function ( event ) {
			var popover;
			var additionalIcons = icons.slice( getMaxVisibleCardBrandIcons() );

			if ( ! additionalIcons.length ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			popover = label.querySelector( '.logo-popover' );
			if ( popover ) {
				closeCardBrandPopover( popover, logos );
				return;
			}
			createCardBrandPopover( label, logos, additionalIcons );
		} );

		logos.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				logos.click();
			}

			if ( event.key === 'Escape' ) {
				closeCardBrandPopover(
					label.querySelector( '.logo-popover' ),
					logos
				);
			}
		} );

		resizeHandler = function () {
			var popover = label.querySelector( '.logo-popover' );

			if ( popover ) {
				closeCardBrandPopover( popover, logos );
			}
			updateCardBrandLogos( logos, label, icons );
		};
		window.addEventListener( 'resize', resizeHandler );
		cardBrandIconsHydratedLabel = label;
		cardBrandIconsHydrationCleanup = function () {
			window.removeEventListener( 'resize', resizeHandler );
			closeCardBrandPopover(
				label.querySelector( '.logo-popover' ),
				logos
			);
		};

		sourceLogos.replaceWith( container );
	}

	function submitElements() {
		if ( ! elements || ! elements.submit ) {
			return Promise.resolve( {} );
		}

		return elements.submit().then( function ( result ) {
			if ( result && result.error ) {
				return Promise.reject( result.error );
			}

			return result || {};
		} );
	}

	function createPaymentMethod() {
		return submitElements().then( function () {
			var billingDetails = getCheckoutBillingDetails();
			var request = {
				elements: elements,
			};

			if ( billingDetails ) {
				request.params = {
					billing_details: billingDetails,
				};
			}

			return stripe.createPaymentMethod( request );
		} );
	}

	function initializeStripeElement() {
		setCurrentGatewayConfig();
		var container = getGatewayPaymentContainer( gatewayId );
		hydrateCardBrandIcons();
		if (
			! container ||
			! config.isCoreNativeCheckoutAvailable ||
			! config.publishableKey ||
			! window.Stripe
		) {
			return;
		}

		if ( paymentElement && paymentElementGatewayId !== gatewayId ) {
			if ( paymentElement.unmount ) {
				paymentElement.unmount();
			}
			stripe = null;
			elements = null;
			paymentElement = null;
			paymentElementContainer = null;
			paymentElementGatewayId = null;
		}

		if ( paymentElement ) {
			if ( paymentElementContainer !== container ) {
				if ( paymentElement.unmount ) {
					paymentElement.unmount();
				}
				paymentElement.mount( container );
				paymentElementContainer = container;
			}
			return;
		}

		stripe = window.Stripe( config.publishableKey, {
			locale: config.locale || 'auto',
			stripeAccount: config.accountId || undefined,
		} );
		elements = stripe.elements( getStripeElementsOptions() );
		paymentElement = elements.create(
			'payment',
			getStripePaymentElementOptions()
		);
		paymentElement.mount( container );
		paymentElementContainer = container;
		paymentElementGatewayId = gatewayId;
	}

	function createPaymentMethodAndSubmit() {
		var form = $( 'form.checkout' );

		if ( ! stripe || ! elements ) {
			appendPaymentFields( form, null, null );
			return true;
		}

		createPaymentMethod()
			.then( function ( result ) {
				if ( result.error ) {
					appendPaymentFields( form, null, result.error );
					setError( result.error.message );
					$( document.body ).trigger( 'checkout_error', [
						result.error.message,
					] );
					return;
				}

				appendPaymentFields( form, result.paymentMethod, null );
				setError( '' );
				isSubmittingWithPaymentMethod = true;
				form.trigger( 'submit' );
			} )
			.catch( function ( error ) {
				appendPaymentFields( form, null, error );
				setError( error && error.message ? error.message : '' );
				$( document.body ).trigger( 'checkout_error', [
					error && error.message ? error.message : '',
				] );
			} );

		return false;
	}

	function submitAddPaymentMethodForm( formElement ) {
		isSubmittingWithSetupIntent = true;
		formElement.submit();
	}

	function createSetupIntentAndSubmit( formElement ) {
		if ( ! stripe || ! elements ) {
			return true;
		}

		createPaymentMethod()
			.then( function ( result ) {
				if ( result.error ) {
					setError( result.error.message );
					return Promise.reject( result.error );
				}

				return createSetupIntent( result.paymentMethod.id );
			} )
			.then( function ( setupIntent ) {
				if ( ! setupIntent || ! setupIntent.id ) {
					setError( config.confirmationErrorMessage || '' );
					return;
				}

				ensureFormHiddenField(
					formElement,
					'wcpay-setup-intent',
					setupIntent.id
				);
				ensureFormHiddenField(
					formElement,
					'wcpay-fraud-prevention-token',
					getFraudPreventionToken()
				);
				setError( '' );
				submitAddPaymentMethodForm( formElement );
			} )
			.catch( function ( error ) {
				setError( error && error.message ? error.message : '' );
			} );

		return false;
	}

	function handleAddPaymentMethodSubmit( event ) {
		var formElement = event.target;
		var selectedGateway = formElement.querySelector(
			'input[name="payment_method"]:checked'
		);

		if ( ! selectedGateway ) {
			return true;
		}

		setCurrentGatewayConfig( selectedGateway.value );

		if ( selectedGateway.value !== gatewayId ) {
			return true;
		}

		if ( isSubmittingWithSetupIntent ) {
			isSubmittingWithSetupIntent = false;
			return true;
		}

		event.preventDefault();
		return createSetupIntentAndSubmit( formElement );
	}

	function parseConfirmationHash( hash ) {
		var match = ( hash || '' ).match(
			/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/
		);
		var clientSecret;

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
			intentId: clientSecret.split( '_secret_' )[ 0 ],
		};
	}

	function isChangingPaymentMethodForSubscription() {
		if (
			window.location &&
			/[?&]change_payment_method=/.test( window.location.search || '' )
		) {
			return true;
		}

		return (
			$( 'form.checkout, form#order_review, form#add_payment_method' )
				.find( 'input[name="change_payment_method"]' ).length > 0
		);
	}

	function updateOrderStatusAfterConfirmation( confirmation, intentId ) {
		if (
			! config.ajaxUrl ||
			! confirmation ||
			! confirmation.orderId ||
			! confirmation.nonce ||
			! intentId
		) {
			return $.Deferred().resolve().promise();
		}

		return $.post( config.ajaxUrl, {
			action: 'update_order_status',
			order_id: confirmation.orderId,
			_ajax_nonce: confirmation.nonce,
			intent_id: intentId,
			should_save_payment_method: 'false',
			is_changing_payment: isChangingPaymentMethodForSubscription()
				? 'true'
				: 'false',
		} );
	}

	function confirmRedirectIfPresent() {
		var confirmation = parseConfirmationHash( window.location.hash || '' );
		var intentId;
		var confirmationPromise;

		setCurrentGatewayConfig();

		if ( ! confirmation || ! config.publishableKey || ! window.Stripe ) {
			return;
		}

		stripe =
			stripe ||
			window.Stripe( config.publishableKey, {
				locale: config.locale || 'auto',
				stripeAccount: config.accountId || undefined,
			} );

		if ( confirmation.type === 'si' ) {
			if ( confirmation.confirmationToken && stripe.confirmSetup ) {
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
		} else if ( stripe.handleNextAction ) {
			confirmationPromise = stripe.handleNextAction( {
				clientSecret: confirmation.clientSecret,
			} );
		}

		if ( ! confirmationPromise ) {
			return;
		}

		confirmationPromise.then( function ( result ) {
			if ( result.error ) {
				setError( result.error.message );
				return;
			}

			intentId =
				( result.paymentIntent && result.paymentIntent.id ) ||
				( result.setupIntent && result.setupIntent.id ) ||
				confirmation.intentId;

			updateOrderStatusAfterConfirmation( confirmation, intentId )
				.done( function ( response ) {
					var resultResponse =
						typeof response === 'string'
							? JSON.parse( response )
							: response;

					if ( resultResponse.error && resultResponse.error.message ) {
						setError( resultResponse.error.message );
						return;
					}

					if ( resultResponse.return_url ) {
						window.location.href = resultResponse.return_url;
					}
				} )
				.fail( function () {
					setError( config.confirmationErrorMessage || '' );
				} );
		} );
	}

	$( function () {
		initializeStripeElement();
		confirmRedirectIfPresent();
		document.addEventListener( 'click', copyTestNumber );
		document.addEventListener( 'click', recordPlaceOrderButtonClick );
		document.addEventListener( 'change', updatePaymentElementTerms );
		if ( document.getElementById( 'add_payment_method' ) ) {
			document
				.getElementById( 'add_payment_method' )
				.addEventListener( 'submit', handleAddPaymentMethodSubmit );
		}
	} );

	$( window ).on( 'hashchange', function () {
		if ( ( window.location.hash || '' ).indexOf( '#wcpay-confirm-' ) === 0 ) {
			confirmRedirectIfPresent();
		}
	} );

	$( document.body ).on( 'updated_checkout', function () {
		initializeStripeElement();
	} );

	getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
		$( 'form.checkout' ).on(
			'checkout_place_order_' + paymentGatewayId,
			function () {
				setCurrentGatewayConfig( paymentGatewayId );

				if ( ! isSelectedGateway() ) {
					return true;
				}

				if ( isSubmittingWithPaymentMethod ) {
					isSubmittingWithPaymentMethod = false;
					return true;
				}

				if ( isUsingSavedPaymentMethod() ) {
					appendPaymentFields( $( 'form.checkout' ), null, null );
					return true;
				}

				return createPaymentMethodAndSubmit();
			}
		);
	} );
} )( jQuery, window, document );
