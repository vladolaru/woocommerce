/* global jQuery, wcpay_core_checkout_config */
( function ( $, window, document ) {
	'use strict';

	var defaultGatewayId = 'woocommerce_payments';
	var configObjectPrefix = 'wcpay_core_checkout_config_';
	var baseConfig = window.wcpay_core_checkout_config || {};
	var appearanceUtils = window.wcpayAppearance;
	var config = baseConfig;
	var navigate = function ( url ) {
		window.location = url;
	};

	// Sentinel submitted in place of a payment method when client-side
	// payment method creation failed, so the server records a failed order.
	// Matches the WooPayments plugin's Payment_Information::PAYMENT_METHOD_ERROR.
	var PAYMENT_METHOD_ERROR_SENTINEL =
		'woocommerce_payments_payment_method_error';
	var gatewayId = config.gatewayId || defaultGatewayId;
	// One Stripe instance per account, shared by every Payment Element, as
	// client 11.1.0 checkout/api/index.js:79-113 keeps one per account.
	var accountStripe = null;
	var platformStripe = null;
	// The Payment Element of each WooPayments gateway on the page, keyed by
	// gateway ID, like client 11.1.0's gatewayUPEComponents
	// (classic/payment-processing.js:40-49). Each entry holds the gateway's
	// `stripe` instance, `isPlatformStripe`, its `elements` group, the
	// `paymentElement`, the `container` it is mounted in, and:
	// - `loadError`: the `loaderror` the element reported. The client refuses
	//   to submit while it is set; without it a shopper whose payment form
	//   failed to load presses the button and gets no message at all.
	// - `ready`: settles when the element reports `ready` (true) or
	//   `loaderror` (false). Stripe.js can leave `elements.submit()` pending
	//   forever when it is called before the element is ready, so submitting
	//   waits for this, for at most PAYMENT_ELEMENT_READY_TIMEOUT_MS.
	var paymentElements = {};
	var PAYMENT_ELEMENT_READY_TIMEOUT_MS = 15000;
	var isWaitingForStripe = false;
	var checkoutSubmitHandlerGatewayIds = {};
	var deviceFingerprint = '';
	var deviceFingerprintRequest = null;
	var isDeviceFingerprintReady = false;
	var isWaitingForDeviceFingerprint = false;
	var isSubmittingWithPaymentMethod = false;
	var isSubmittingWithSetupIntent = false;
	var cardBrandIconsHydratedLabel = null;
	var cardBrandIconsHydrationCleanup = null;
	var expressButtonStates = {};
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
	var classicIconBackgroundSelectors = [
		'#payment',
		'#order_review',
		'form.checkout',
		'body',
	];
	var classicLinkSelectors = [
		'form.checkout a',
		'.woocommerce a',
		'main a',
		'.entry-content a',
		'.site-content a',
		'#content a',
		'#primary a',
		'a',
	];
	var classicFooterLinkSelectors = [ '.site-footer a', 'footer a' ];
	// Client 11.1.0 upe-styles/index.js:187 (classic checkout container) and the '.Container' properties it may set
	// (borderOutlineBackgroundProps).
	var classicContainerSelectors = [ '.woocommerce-checkout-review-order-table' ];
	var classicContainerStyleProps = [
		'backgroundColor',
		'border',
		'borderTop',
		'borderRight',
		'borderBottom',
		'borderLeft',
		'borderRadius',
		'borderWidth',
		'borderColor',
		'borderStyle',
		'borderTopWidth',
		'borderTopColor',
		'borderTopStyle',
		'borderRightWidth',
		'borderRightColor',
		'borderRightStyle',
		'borderBottomWidth',
		'borderBottomColor',
		'borderBottomStyle',
		'borderLeftWidth',
		'borderLeftColor',
		'borderLeftStyle',
		'borderTopLeftRadius',
		'borderTopRightRadius',
		'borderBottomRightRadius',
		'borderBottomLeftRadius',
		'outline',
		'outlineOffset',
		'boxShadow',
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
		var paymentMethodEntry;
		var paymentMethodsConfig;

		if ( keyedConfig && typeof keyedConfig === 'object' ) {
			return keyedConfig;
		}

		if (
			( baseConfig.gatewayId || defaultGatewayId ) === paymentGatewayId
		) {
			return baseConfig;
		}

		paymentMethodEntry = getPaymentMethodEntryForGateway(
			baseConfig,
			paymentGatewayId
		);
		if ( paymentMethodEntry ) {
			paymentMethodsConfig = {};
			paymentMethodsConfig[ paymentMethodEntry.id ] =
				paymentMethodEntry.config;

			return Object.assign( {}, baseConfig, {
				gatewayId: paymentGatewayId,
				paymentMethodId: paymentMethodEntry.id,
				paymentMethodsConfig: paymentMethodsConfig,
				paymentListWalletsConfig: {},
				paymentMethodTypes: [ 'card' ],
			} );
		}

		return null;
	}

	// Client 11.1.0 includes/class-wc-payments-checkout.php:353-373 merges the refreshed paymentMethodsConfig, currency
	// and cartTotal into the page config from a script in the update_order_review fragment, before core re-inits the
	// payment methods. Native reads them from the payment fields WooCommerce re-renders in that fragment
	// (data-wcpay-config, one per WooPayments gateway), every time a config is about to be read; a gateway first
	// rendered after the page loaded adopts its whole config.
	function mergeRefreshedPaymentFieldsConfig() {
		document
			.querySelectorAll( '.wcpay-core-checkout-form[data-wcpay-config]' )
			.forEach( function ( wrapper ) {
				var refreshed;
				var target;

				// Core replaces a changed fragment with new elements (checkout.js:773), so a wrapper already read has nothing new.
				if ( wrapper.wcpayConfigMerged ) {
					return;
				}
				wrapper.wcpayConfigMerged = true;

				try {
					refreshed = JSON.parse(
						wrapper.getAttribute( 'data-wcpay-config' )
					);
				} catch ( error ) {
					return;
				}

				if (
					! refreshed ||
					typeof refreshed !== 'object' ||
					! refreshed.gatewayId
				) {
					return;
				}

				target =
					window[
						getGatewayConfigObjectName( refreshed.gatewayId )
					];
				if (
					( ! target || typeof target !== 'object' ) &&
					( baseConfig.gatewayId || defaultGatewayId ) ===
						refreshed.gatewayId
				) {
					target = baseConfig;
				}

				if ( ! target || typeof target !== 'object' ) {
					window[
						getGatewayConfigObjectName( refreshed.gatewayId )
					] = refreshed;
					return;
				}

				[ 'paymentMethodsConfig', 'currency', 'cartTotal' ].forEach(
					function ( key ) {
						if ( key in refreshed ) {
							target[ key ] = refreshed[ key ];
						}
					}
				);
			} );
	}

	function getGatewayPaymentMethodConfigs( gatewayConfig ) {
		return Object.assign(
			{},
			gatewayConfig.paymentMethodsConfig || {},
			gatewayConfig.paymentListWalletsConfig || {}
		);
	}

	function getPaymentMethodEntryForGateway(
		gatewayConfig,
		paymentGatewayId
	) {
		var paymentMethodsConfig =
			getGatewayPaymentMethodConfigs( gatewayConfig );
		var paymentMethodIds = Object.keys( paymentMethodsConfig );
		var paymentMethodId;
		var index;

		for ( index = 0; index < paymentMethodIds.length; index++ ) {
			paymentMethodId = paymentMethodIds[ index ];
			if (
				paymentMethodsConfig[ paymentMethodId ].gatewayId ===
				paymentGatewayId
			) {
				return {
					id: paymentMethodId,
					config: paymentMethodsConfig[ paymentMethodId ],
				};
			}
		}

		return null;
	}

	function getSelectedGatewayId() {
		var selected = document.querySelector(
			'input[name="payment_method"]:checked'
		);

		return selected ? selected.value : '';
	}

	function getActivePaymentForm() {
		var form = window.wc.customPlaceOrderButton.__getForm();
		var formElement;

		if ( form && form.length ) {
			return form;
		}

		formElement = document.querySelector(
			'form.checkout, form#order_review'
		);

		return formElement ? $( formElement ) : $( 'form.checkout' );
	}

	function setCurrentGatewayConfig( paymentGatewayId ) {
		mergeRefreshedPaymentFieldsConfig();

		var nextGatewayId =
			paymentGatewayId || getSelectedGatewayId() || gatewayId;
		var nextConfig = getConfigForGateway( nextGatewayId ) || baseConfig;

		config = nextConfig;
		gatewayId = nextConfig.gatewayId || nextGatewayId || defaultGatewayId;
	}

	function getKnownGatewayIds() {
		var gatewayIds = {};
		var paymentMethodConfigs =
			getGatewayPaymentMethodConfigs( baseConfig );

		if ( baseConfig.gatewayId || window.wcpay_core_checkout_config ) {
			gatewayIds[ baseConfig.gatewayId || defaultGatewayId ] = true;
		}

		Object.keys( paymentMethodConfigs ).forEach(
			function ( paymentMethodId ) {
				var paymentMethodConfig =
					paymentMethodConfigs[ paymentMethodId ];

				if ( paymentMethodConfig.gatewayId ) {
					gatewayIds[ paymentMethodConfig.gatewayId ] = true;
				}
			}
		);

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

	function selectFallbackPaymentMethod( excludedGatewayId ) {
		var inputs = Array.prototype.slice.call(
			document.querySelectorAll( 'input[name="payment_method"]' )
		);
		var cardInput = getPaymentMethodInput( defaultGatewayId );
		var candidates = cardInput
			? [ cardInput ].concat(
					inputs.filter( function ( input ) {
						return input !== cardInput;
					} )
			  )
			: inputs;
		var fallback = candidates.find( function ( input ) {
			var listItem = input.closest ? input.closest( 'li' ) : null;

			return (
				input.value !== excludedGatewayId &&
				! input.disabled &&
				( ! listItem || listItem.style.display !== 'none' )
			);
		} );

		if ( fallback ) {
			fallback.click();
		}
	}

	function getGatewayPaymentContainer( paymentGatewayId ) {
		var input = getPaymentMethodInput( paymentGatewayId );
		var gatewayElement =
			input && input.closest ? input.closest( 'li' ) : null;
		var container =
			gatewayElement &&
			gatewayElement.querySelector( '#wcpay-core-payment-element' );
		var sharedContainer;
		var owningRow;

		if ( container ) {
			return container;
		}

		sharedContainer = document.getElementById(
			'wcpay-core-payment-element'
		);

		// Without a row to compare against there is nothing to attribute the
		// container to, so the historical fallback stands.
		if ( ! sharedContainer || ! gatewayElement ) {
			return sharedContainer;
		}

		// A container rendered inside another gateway's row belongs to that
		// gateway. Handing it over mounts a Payment Element the shopper never
		// fills in, and then the submit handler treats this gateway as
		// element-backed: it tries to create a card payment method, fails, and
		// returns early without ever posting the checkout, so Place order does
		// nothing at all. Methods that collect nothing here -- the redirect and
		// BNPL gateways -- must simply have no container.
		owningRow = sharedContainer.closest
			? sharedContainer.closest( 'li' )
			: null;
		if ( owningRow && owningRow !== gatewayElement ) {
			return null;
		}

		return sharedContainer;
	}

	function getPaymentMethodConfigForGateway( paymentGatewayId ) {
		var gatewayConfig = getConfigForGateway( paymentGatewayId ) || {};
		var paymentMethodsConfig = gatewayConfig.paymentMethodsConfig || {};
		var paymentMethodEntry = getPaymentMethodEntryForGateway(
			gatewayConfig,
			paymentGatewayId
		);
		var paymentMethodId = gatewayConfig.paymentMethodId;

		if ( ! paymentMethodId && paymentMethodEntry ) {
			paymentMethodId = paymentMethodEntry.id;
		}
		paymentMethodId =
			paymentMethodId || Object.keys( paymentMethodsConfig )[ 0 ] || 'card';

		return (
			paymentMethodsConfig[ paymentMethodId ] ||
			paymentMethodsConfig.card ||
			{}
		);
	}

	function getPaymentMethodIdForGateway( paymentGatewayId ) {
		var gatewayConfig = getConfigForGateway( paymentGatewayId ) || {};
		var paymentMethodEntry = getPaymentMethodEntryForGateway(
			gatewayConfig,
			paymentGatewayId
		);

		return (
			gatewayConfig.paymentMethodId ||
			( paymentMethodEntry && paymentMethodEntry.id ) ||
			Object.keys( gatewayConfig.paymentMethodsConfig || {} )[ 0 ] ||
			''
		);
	}

	function getExpressWalletType( paymentGatewayId ) {
		var paymentMethodId = getPaymentMethodIdForGateway( paymentGatewayId );
		var paymentMethodConfig =
			getPaymentMethodConfigForGateway( paymentGatewayId );

		if ( ! paymentMethodConfig.isExpressCheckout ) {
			return '';
		}

		if ( paymentMethodId === 'apple_pay' ) {
			return 'applePay';
		}

		if ( paymentMethodId === 'google_pay' ) {
			return 'googlePay';
		}

		return '';
	}

	function isPaymentListWalletGateway( paymentGatewayId ) {
		return !! getExpressWalletType( paymentGatewayId );
	}

	function getCheckoutBillingCountry() {
		var input = document.querySelector( '[name="billing_country"]' );
		var customerData = baseConfig.customerData || {};

		return (
			( input && input.value ) ||
			customerData.billing_country ||
			customerData.billingCountry ||
			''
		);
	}

	function togglePaymentMethodsForBillingCountry() {
		var billingCountry = getCheckoutBillingCountry();
		var selectedGatewayId = getSelectedGatewayId();

		getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
			var input = getPaymentMethodInput( paymentGatewayId );
			var listItem =
				input && input.closest ? input.closest( 'li' ) : null;
			var methodConfig =
				getPaymentMethodConfigForGateway( paymentGatewayId );
			var countries = Array.isArray( methodConfig.countries )
				? methodConfig.countries
				: [];
			var isAvailable =
				countries.length === 0 || countries.includes( billingCountry );

			if ( ! listItem ) {
				return;
			}

			if ( isAvailable ) {
				listItem.style.removeProperty( 'display' );
				return;
			}

			listItem.style.display = 'none';
			if (
				paymentGatewayId === selectedGatewayId &&
				paymentGatewayId !== defaultGatewayId
			) {
				selectFallbackPaymentMethod( paymentGatewayId );
			}
		} );
	}

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

	function copyTestNumber( event ) {
		var button;
		var testNumber;
		var icon;
		var status;

		if ( ! ( event.target instanceof window.Element ) ) {
			return;
		}

		button = event.target.closest( '.js-woopayments-copy-test-number' );
		testNumber =
			button && button.textContent ? button.textContent.trim() : '';

		if ( ! button || ! testNumber ) {
			return;
		}

		event.preventDefault();
		icon = button.querySelector( 'i' );
		if ( icon ) {
			icon.setAttribute( 'aria-hidden', 'true' );
		}

		// Success shows only once the clipboard write resolves; the prompt fallback leaves the copy to the shopper.
		if (
			window.navigator.clipboard &&
			typeof window.navigator.clipboard.writeText === 'function'
		) {
			Promise.resolve(
				window.navigator.clipboard.writeText( testNumber )
			).then( showCopied, function () {} );
		} else if ( typeof window.prompt === 'function' ) {
			window.prompt( 'Copy test card number:', testNumber );
		}

		// The status after the button announces the copy (WooPaymentsCheckoutAssets::get_card_testing_instructions()).
		function showCopied() {
			status = button.parentNode
				? button.parentNode.querySelector(
						'.js-woopayments-copy-test-number-status'
				  )
				: null;
			if ( status ) {
				status.textContent =
					status.getAttribute( 'data-copied-message' ) || '';
			}

			button.classList.add( 'state--success' );
			window.setTimeout( function () {
				button.classList.remove( 'state--success' );
				if ( status ) {
					status.textContent = '';
				}
			}, copyTestNumberSuccessDuration );
		}
	}

	// The shared Tracks sender (wc-woopayments-common) with this gateway's tracking values.
	function recordUserEvent( eventName, eventProperties ) {
		window.wc.woopayments.tracks.recordEvent(
			{
				isShopperTrackingEnabled: config.isShopperTrackingEnabled,
				tracksUrl: config.tracksUrl,
				platformTrackerNonce: config.platformTrackerNonce,
				tracksRestNonce: config.tracksRestNonce,
			},
			eventName,
			eventProperties
		);
	}

	function isLinkEnabled( gatewayConfig ) {
		var paymentMethodsConfig =
			( gatewayConfig || config ).paymentMethodsConfig || {};

		return (
			paymentMethodsConfig.link !== undefined &&
			paymentMethodsConfig.card !== undefined
		);
	}

	// Betas the reference client requests on every connected-account
	// Stripe instance; the Link autofill modal only works with Link on.
	function getStripeBetas( gatewayConfig ) {
		var betas = [ 'card_country_event_beta_1' ];

		if ( isLinkEnabled( gatewayConfig ) ) {
			betas.push( 'link_autofill_modal_beta_1' );
		}

		return betas;
	}

	// Client 11.1.0 api/index.js getStripeForUPE(): only the selected method's forceNetworkSavedCards picks the platform
	// account, so the card can be saved for WooPay; it is false for every method but card
	// (includes/class-wc-payments-checkout.php:598-599). The platform instance has no connected account and no betas.
	function shouldUsePlatformStripe() {
		var methodsConfig = config.paymentMethodsConfig || {};
		var methodConfig = methodsConfig[ getStripePaymentMethodTypes()[ 0 ] ];

		return Boolean( methodConfig && methodConfig.forceNetworkSavedCards );
	}

	// The shared instance takes the Link beta from the card gateway's config, the one Link folds into, whichever
	// gateway mounts first. Client 11.1.0 reads it from the page-wide config (classic/event-handlers.js:56-67).
	function createAccountStripe() {
		return window.Stripe( config.publishableKey, {
			locale: config.locale || 'auto',
			stripeAccount: config.accountId || undefined,
			betas: getStripeBetas(
				getConfigForGateway( defaultGatewayId ) || config
			),
		} );
	}

	// Intent confirmation always runs on the connected account (client confirmIntent() uses getStripe( true )).
	function getAccountStripe() {
		accountStripe = accountStripe || createAccountStripe();
		return accountStripe;
	}

	// The platform instance has no connected account and no betas (client 11.1.0 api/index.js:88-95).
	function getPlatformStripe() {
		platformStripe =
			platformStripe ||
			window.Stripe( config.publishableKey, {
				locale: config.locale || 'auto',
			} );
		return platformStripe;
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
					! paymentMethodsConfig[ paymentMethodId ]
						.isExpressCheckout &&
					paymentMethodsConfig[ paymentMethodId ].isReusable
			) {
				terms[ paymentMethodId ] = value;
			}

			return terms;
		},
		{} );
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

	// Unlike getInputValue, absent inputs yield undefined so the resulting
	// billing detail is omitted instead of sent as an empty string.
	function getOptionalInputValue( id ) {
		var input = document.getElementById( id );
		return input && typeof input.value === 'string'
			? input.value
			: undefined;
	}

	function hasCheckoutBillingFields() {
		return checkoutBillingFieldIds.some( function ( id ) {
			return !! document.getElementById( id );
		} );
	}

	function getPreparedCustomerBillingDetails() {
		var customerData = baseConfig.customerData || {};
		var country =
			customerData.billing_country || customerData.billingCountry || '';
		var address = customerData.address
			? Object.assign( {}, customerData.address )
			: country
			? { country: country }
			: {};

		if (
			! customerData.name &&
			! customerData.email &&
			Object.keys( address ).length === 0
		) {
			return null;
		}

		return {
			name: customerData.name || undefined,
			email: customerData.email,
			address: address,
		};
	}

	function getParsedAddressLocale() {
		var params = window.wc_address_i18n_params;

		if ( ! params || typeof params.locale !== 'string' ) {
			return null;
		}

		try {
			return JSON.parse( params.locale.replace( /&quot;/g, '"' ) );
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Tell whether required billing information is missing from the checkout
	 * form. When it is, the submission is left to WooCommerce so its own
	 * validation surfaces the field errors, instead of creating an orphan
	 * PaymentMethod first.
	 */
	function isBillingInformationMissing() {
		var enabledBillingFields = config.enabledBillingFields;
		var name;
		var billingFieldsToValidate;
		var country;
		var locale;

		if ( ! enabledBillingFields ) {
			return false;
		}

		// First and last name are special - one of them filled is enough.
		name = (
			getInputValue( 'billing_first_name' ) +
			' ' +
			getInputValue( 'billing_last_name' )
		).trim();
		if (
			! name &&
			( enabledBillingFields.billing_first_name ||
				enabledBillingFields.billing_last_name )
		) {
			return true;
		}

		billingFieldsToValidate = [
			'billing_email',
			'billing_country',
			'billing_address_1',
			'billing_city',
			'billing_postcode',
		].filter( function ( field ) {
			return !! enabledBillingFields[ field ];
		} );

		country =
			billingFieldsToValidate.indexOf( 'billing_country' ) !== -1
				? getInputValue( 'billing_country' )
				: null;
		locale = getParsedAddressLocale();

		return billingFieldsToValidate.some( function ( fieldName ) {
			var isRequired =
				enabledBillingFields[ fieldName ] &&
				enabledBillingFields[ fieldName ].required;
			var key;
			var localeRule;

			if ( country && locale && fieldName !== 'billing_email' ) {
				key = fieldName.replace( 'billing_', '' );
				localeRule =
					( locale[ country ] && locale[ country ][ key ] ) ||
					( locale.default && locale.default[ key ] );
				if ( localeRule && localeRule.required !== undefined ) {
					isRequired = localeRule.required;
				}
			}

			return isRequired && ! getInputValue( fieldName );
		} );
	}

	function getCheckoutBillingDetails() {
		var firstName = getInputValue( 'billing_first_name' );
		var lastName = getInputValue( 'billing_last_name' );
		var postalCode = getOptionalInputValue( 'billing_postcode' );

		if ( postalCode !== undefined ) {
			// Trim to avoid Stripe AVS mismatches on leading/trailing whitespace.
			postalCode = postalCode.trim();
		}

		if ( ! hasCheckoutBillingFields() ) {
			return getPreparedCustomerBillingDetails();
		}

		return {
			name: ( firstName + ' ' + lastName ).trim() || undefined,
			email: getOptionalInputValue( 'billing_email' ),
			phone: getOptionalInputValue( 'billing_phone' ),
			address: {
				city: getOptionalInputValue( 'billing_city' ),
				country: getOptionalInputValue( 'billing_country' ),
				line1: getOptionalInputValue( 'billing_address_1' ),
				line2: getOptionalInputValue( 'billing_address_2' ),
				postal_code: postalCode,
				state: getOptionalInputValue( 'billing_state' ),
			},
		};
	}

	function getHiddenBillingFields( enabledBillingFields ) {
		// A missing map means a config predating enabledBillingFields: keep the
		// previous all-hidden behavior. A present-but-empty map is a store with
		// every billing field disabled: like the reference client, every field
		// resolves to 'auto' so the Payment Element collects the details itself.
		if ( ! enabledBillingFields ) {
			return {
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
			};
		}

		return {
			name:
				enabledBillingFields.billing_first_name ||
				enabledBillingFields.billing_last_name
					? 'never'
					: 'auto',
			email: enabledBillingFields.billing_email ? 'never' : 'auto',
			phone: enabledBillingFields.billing_phone ? 'never' : 'auto',
			address: {
				country: enabledBillingFields.billing_country
					? 'never'
					: 'auto',
				line1: enabledBillingFields.billing_address_1
					? 'never'
					: 'auto',
				line2: enabledBillingFields.billing_address_2
					? 'never'
					: 'auto',
				city: enabledBillingFields.billing_city ? 'never' : 'auto',
				state: enabledBillingFields.billing_state ? 'never' : 'auto',
				postalCode: enabledBillingFields.billing_postcode
					? 'never'
					: 'auto',
			},
		};
	}

	function getStripePaymentElementOptions() {
		var options = {
			wallets: {
				applePay: 'never',
				googlePay: 'never',
				link:
					isLinkEnabled() && ! isAddPaymentMethodForm()
						? 'auto'
						: 'never',
			},
			// The My Account form always saves, so it shows the card-on-file terms. Client 11.1.0 gets
			// there through a hidden, checked save checkbox (class-wc-payments-checkout.php:513-515).
			terms: getReusablePaymentMethodTerms(
				isAddPaymentMethodForm() ||
					shouldSavePaymentMethod() ||
					config.cartContainsSubscription
					? 'always'
					: 'never'
			),
		};

		if (
			baseConfig.isCheckout &&
			! baseConfig.isOrderPay &&
			! baseConfig.isChangingPayment
		) {
			options.fields = {
				billingDetails: getHiddenBillingFields(
					config.enabledBillingFields
				),
			};
		}

		var preparedBillingDetails = getPreparedCustomerBillingDetails();
		if ( preparedBillingDetails ) {
			options.defaultValues = {
				billingDetails: preparedBillingDetails,
			};
		}

		return options;
	}

	function setPaymentListWalletAvailability( paymentGatewayId, isAvailable ) {
		var input = getPaymentMethodInput( paymentGatewayId );
		var listItem = input && input.closest ? input.closest( 'li' ) : null;

		if ( ! listItem ) {
			return;
		}

		if ( isAvailable ) {
			listItem.style.removeProperty( 'display' );
			return;
		}

		listItem.style.display = 'none';
		if ( input.checked ) {
			selectFallbackPaymentMethod( paymentGatewayId );
		}
	}

	function getExpressCheckoutElementOptions( walletType ) {
		var options = {
			buttonType: {
				applePay: 'plain',
				googlePay: 'plain',
			},
			paymentMethods: {
				applePay: 'never',
				googlePay: 'never',
				link: 'never',
				paypal: 'never',
				amazonPay: 'never',
				klarna: 'never',
			},
		};

		options.paymentMethods[ walletType ] = 'always';

		return options;
	}

	// The wallet reuses its Stripe instance across renders, following the client 11.1.0 API-instance cache
	// (checkout/api/index.js:79-110); only its Elements are rebuilt.
	function resetExpressButtonState( state ) {
		if ( state.element && state.element.unmount ) {
			state.element.unmount();
		}

		state.element = null;
		state.elements = null;
	}

	function registerPaymentListWallet( paymentGatewayId ) {
		var walletType = getExpressWalletType( paymentGatewayId );
		var state;

		if ( ! walletType || expressButtonStates[ paymentGatewayId ] ) {
			return;
		}

		state = {
			element: null,
			elements: null,
			stripe: null,
		};
		expressButtonStates[ paymentGatewayId ] = state;

		window.wc.customPlaceOrderButton.register( paymentGatewayId, {
			render: function ( container, checkoutApi ) {
				mergeRefreshedPaymentFieldsConfig();

				var gatewayConfig =
					getConfigForGateway( paymentGatewayId ) || {};
				var amount = Number( gatewayConfig.cartTotal || 0 );
				var currency = ( gatewayConfig.currency || '' ).toLowerCase();

				resetExpressButtonState( state );
				if (
					! container ||
					! window.Stripe ||
					! gatewayConfig.publishableKey ||
					! isFinite( amount ) ||
					amount <= 0 ||
					! currency
				) {
					setPaymentListWalletAvailability( paymentGatewayId, false );
					return;
				}

				try {
					state.stripe =
						state.stripe ||
						window.Stripe( gatewayConfig.publishableKey, {
							locale: gatewayConfig.locale || 'auto',
							stripeAccount: gatewayConfig.accountId || undefined,
							betas: getStripeBetas(),
						} );
					state.elements = state.stripe.elements( {
						mode: 'payment',
						amount: amount,
						currency: currency,
						paymentMethodCreation: 'manual',
						paymentMethodTypes: [ 'card' ],
					} );
					state.element = state.elements.create(
						'expressCheckout',
						getExpressCheckoutElementOptions( walletType )
					);

					state.element.on( 'ready', function ( event ) {
						var availablePaymentMethods =
							( event && event.availablePaymentMethods ) || {};

						setPaymentListWalletAvailability(
							paymentGatewayId,
							!! availablePaymentMethods[ walletType ]
						);
					} );
					state.element.on( 'click', function ( event ) {
						return Promise.resolve( checkoutApi.validate() ).then(
							function ( validationResult ) {
								if (
									validationResult &&
									validationResult.hasError
								) {
									return;
								}

								event.resolve( {
									emailRequired: true,
									phoneNumberRequired: false,
									shippingAddressRequired: false,
								} );
							}
						);
					} );
					state.element.on( 'confirm', function () {
						return Promise.resolve( state.elements.submit() )
							.then( function ( result ) {
								if ( result && result.error ) {
									return Promise.reject( result.error );
								}

								return state.stripe.createPaymentMethod( {
									elements: state.elements,
								} );
							} )
							.then( function ( result ) {
								if ( result && result.error ) {
									return Promise.reject( result.error );
								}

								setCurrentGatewayConfig( paymentGatewayId );
								appendPaymentFields(
									getActivePaymentForm(),
									result && result.paymentMethod,
									null
								);
								setError( '' );
								checkoutApi.submit();
							} )
							.catch( function ( error ) {
								setError(
									error && error.message ? error.message : ''
								);
							} );
					} );
					state.element.on( 'loaderror', function () {
						setPaymentListWalletAvailability(
							paymentGatewayId,
							false
						);
					} );
					state.element.mount( container );
				} catch ( error ) {
					resetExpressButtonState( state );
					setPaymentListWalletAvailability( paymentGatewayId, false );
				}
			},
			cleanup: function () {
				resetExpressButtonState( state );
			},
		} );
	}

	// A gateway can first render after an update_order_review (for example once a currency change makes it available).
	function registerCheckoutSubmitHandlers() {
		getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
			if ( checkoutSubmitHandlerGatewayIds[ paymentGatewayId ] ) {
				return;
			}

			checkoutSubmitHandlerGatewayIds[ paymentGatewayId ] = true;
			$( 'form.checkout' ).on(
				'checkout_place_order_' + paymentGatewayId,
				function () {
					return handleGatewaySubmission(
						paymentGatewayId,
						$( 'form.checkout' ),
						true
					);
				}
			);
		} );
	}

	function registerPaymentListWallets() {
		if ( ! baseConfig.isExpressCheckoutInPaymentMethodsEnabled ) {
			return;
		}

		getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
			if ( isPaymentListWalletGateway( paymentGatewayId ) ) {
				registerPaymentListWallet( paymentGatewayId );
			}
		} );
	}

	function updatePaymentElementTerms( event ) {
		var component = paymentElements[ gatewayId ];

		if (
			! event.target ||
			event.target.id !== 'wc-' + gatewayId + '-new-payment-method' ||
			! component ||
			typeof component.paymentElement.update !== 'function'
		) {
			return;
		}

		component.paymentElement.update( {
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
		var selectorList = Array.isArray( selectors )
			? selectors
			: [ selectors ];
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

			value =
				appearanceUtils.normalizeAppearanceValueForStripe( rawValue );
			if ( value ) {
				output[ property ] = value;
			}
			return output;
		}, {} );
	}

	function getBackgroundColor( selectors ) {
		var backgroundSelectors = selectors || classicBackgroundSelectors;
		var index;
		var element;
		var color;
		var parsedColor;

		for ( index = 0; index < backgroundSelectors.length; index++ ) {
			element = queryFirst( backgroundSelectors[ index ] );
			if ( ! element || ! window.getComputedStyle ) {
				continue;
			}

			color = window.getComputedStyle( element ).backgroundColor;
			parsedColor = appearanceUtils.parseColor( color );
			if ( color && parsedColor && parsedColor.a >= 0.5 ) {
				return appearanceUtils.normalizeAppearanceValueForStripe(
					color
				);
			}
		}

		return '#ffffff';
	}

	function swapPaymentMethodIconsForTheme() {
		var useDark = ! isColorLight(
			getBackgroundColor( classicIconBackgroundSelectors )
		);

		getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
			var paymentMethodId = getPaymentMethodIdForGateway(
				paymentGatewayId
			);
			var paymentMethodConfig;
			var targetIcon;
			var input;
			var listItem;
			var image;

			if ( 'card' === paymentMethodId ) {
				return;
			}

			paymentMethodConfig = getPaymentMethodConfigForGateway(
				paymentGatewayId
			);
			targetIcon =
				useDark && paymentMethodConfig.darkIcon
					? paymentMethodConfig.darkIcon
					: paymentMethodConfig.icon;
			input = getPaymentMethodInput( paymentGatewayId );
			listItem = input && input.closest ? input.closest( 'li' ) : null;
			image = listItem
				? listItem.querySelector(
						'label .wcpay-payment-method-icon'
				  )
				: null;

			if ( targetIcon && image ) {
				image.src = targetIcon;
			}
		} );
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
		var labelRules = getElementStyles(
			label || input,
			classicTextStyleProps
		);
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

	function getClassicWooPayAppearance( appearance ) {
		var textColor = appearanceUtils.resolveCurrentColor(
			( appearance.variables && appearance.variables.colorText ) ||
				'#000000',
			'#000000'
		);
		var linkRules = getElementStyles(
			queryFirst( classicLinkSelectors ),
			classicTextStyleProps
		);
		var linkColor = appearanceUtils.resolveCurrentColor(
			linkRules.color || textColor,
			textColor
		);
		var footerLinkRules = getElementStyles(
			queryFirst( classicFooterLinkSelectors ),
			[ 'color' ]
		);
		var footerLinkColor = appearanceUtils.resolveCurrentColor(
			footerLinkRules.color || linkColor,
			textColor
		);

		return Object.assign( {}, appearance, {
			rules: Object.assign( {}, appearance.rules, {
				'.Link': Object.assign( {}, linkRules, { color: linkColor } ),
				'.Footer-link': { color: footerLinkColor },
				// Client 11.1.0 upe-styles/index.js:855-860.
				'.Container': getElementStyles(
					queryFirst( classicContainerSelectors ),
					classicContainerStyleProps
				),
			} ),
		} );
	}

	function getClassicCheckoutAppearance( forWooPay ) {
		var version = config.stylesCacheVersion || '';
		var cachedAppearance = appearanceUtils.getCachedAppearance(
			classicCheckoutAppearanceLocation,
			version
		);
		var appearance;
		var cachedWooPayAppearance;
		var wooPayAppearance;

		if ( cachedAppearance ) {
			cachedWooPayAppearance = getClassicWooPayAppearance(
				cachedAppearance
			);
			appearanceUtils.maybePersistWooPayAppearance(
				cachedWooPayAppearance,
				config
			);
			return forWooPay ? cachedWooPayAppearance : cachedAppearance;
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
			window.dispatchEvent(
				new window.Event( 'wcpay-appearance-cached' )
			);
		}
		wooPayAppearance = getClassicWooPayAppearance( appearance );
		appearanceUtils.maybePersistWooPayAppearance( wooPayAppearance, config );

		return forWooPay ? wooPayAppearance : appearance;
	}

	function recordPlaceOrderButtonClick( event ) {
		if (
			! ( event.target instanceof window.Element ) ||
			! event.target.closest( 'form.checkout #place_order' ) ||
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

	// Client 11.2.0 checkout/classic/upe-utils.js:204 reads the token only from the page global.
	function getFraudPreventionToken() {
		return window.wcpayFraudPreventionToken || '';
	}

	// `component` is the Payment Element entry that created the payment method; express wallets pass none.
	function appendPaymentFields( form, paymentMethod, error, component ) {
		var paymentMethodValue = '';

		if ( paymentMethod && paymentMethod.id ) {
			paymentMethodValue = paymentMethod.id;
		} else if ( error ) {
			paymentMethodValue = PAYMENT_METHOD_ERROR_SENTINEL;
		}

		ensureHiddenField( form, 'wcpay-payment-method', paymentMethodValue );
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-code',
			error && error.code ? error.code : ''
		);
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-decline-code',
			error && error.decline_code ? error.decline_code : ''
		);
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-message',
			error && error.message ? error.message : ''
		);
		ensureHiddenField(
			form,
			'wcpay-payment-method-error-type',
			error && error.type ? error.type : ''
		);
		// The buyer *device* fingerprint. The WooPayments plugin sends the
		// FingerprintJS visitor ID here; the Stripe card fingerprint is a
		// different signal entirely and must not be posted under this name.
		ensureHiddenField( form, 'wcpay-fingerprint', deviceFingerprint || '' );
		ensureHiddenField(
			form,
			'wcpay-fraud-prevention-token',
			getFraudPreventionToken()
		);
		// Read by WooPaymentsGateway through WooPaymentsPlatformPaymentMethodContext::CHECKOUT_FIELD; a saved
		// token was never created on the platform here.
		ensureHiddenField(
			form,
			'wcpay-is-platform-payment-method',
			( paymentMethod || error ) &&
				component &&
				component.isPlatformStripe
				? 'true'
				: 'false'
		);
	}

	function getSetupIntentData( response ) {
		if ( response && response.data ) {
			return response.data;
		}

		return response || {};
	}

	function getSetupIntentError( response ) {
		var setupIntent = getSetupIntentData( response );
		var error = setupIntent && setupIntent.error;

		if (
			error &&
			typeof error.message === 'string' &&
			error.message.trim()
		) {
			return error;
		}

		return new Error( config.confirmationErrorMessage || '' );
	}

	function confirmSetupIntentIfNeeded( setupIntent ) {
		var component = paymentElements[ gatewayId ];
		var setupStripe = component ? component.stripe : null;

		if ( setupIntent && setupIntent.status === 'succeeded' ) {
			return Promise.resolve( setupIntent );
		}

		if (
			! setupIntent ||
			! setupIntent.client_secret ||
			! setupStripe ||
			! setupStripe.confirmCardSetup
		) {
			return Promise.reject(
				new Error( config.confirmationErrorMessage || '' )
			);
		}

		return setupStripe
			.confirmCardSetup( setupIntent.client_secret )
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
				'wcpay-fingerprint': deviceFingerprint || '',
				'wcpay-fraud-prevention-token': getFraudPreventionToken(),
				_ajax_nonce: config.createSetupIntentNonce || '',
			} )
				.done( function ( response ) {
					var setupIntent = getSetupIntentData( response );
					if ( response && response.success === false ) {
						reject( getSetupIntentError( response ) );
						return;
					}

					confirmSetupIntentIfNeeded( setupIntent )
						.then( resolve )
						.catch( reject );
				} )
				.fail( function ( jqXHR ) {
					reject(
						getSetupIntentError( jqXHR && jqXHR.responseJSON )
					);
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

	// The trigger's label replaces its images' alt text, so the description names every brand, not just the hidden ones.
	function createCardBrandPopover( label, logos, icons, allIcons ) {
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
		popover.style.gridTemplateColumns = 'repeat(' + itemsPerRow + ', 38px)';
		popover.style.width =
			itemsPerRow * 38 + ( itemsPerRow - 1 ) * 8 + 16 + 'px';

		description.id = popover.id + '-description';
		description.className = 'screen-reader-text';
		description.textContent = allIcons
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
				config.cardBrandLogosLabel ||
					'Show all supported credit card brands'
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
		var testModeBadge;
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
		testModeBadge = sourceLogos.querySelector( '.test-mode.badge' );

		cleanupCardBrandIconsHydration();

		container = document.createElement( 'span' );
		container.className = 'payment-methods--logos';
		logos = document.createElement( 'span' );
		logos.setAttribute( 'data-testid', 'payment-methods-logos' );
		if ( testModeBadge ) {
			container.appendChild( testModeBadge );
		}
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
			createCardBrandPopover( label, logos, additionalIcons, icons );
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

	function submitElements( component ) {
		// A payment element that failed to load can never produce a payment
		// method, and asking it for one leaves the shopper waiting on a
		// promise that will not resolve. Answer with what it already reported.
		if ( component.loadError ) {
			return Promise.reject( new Error( component.loadError ) );
		}

		if ( ! component.elements.submit ) {
			return Promise.resolve( {} );
		}

		return waitForPaymentElementReady( component ).then( function () {
			return component.elements.submit().then( function ( result ) {
				if ( result && result.error ) {
					return Promise.reject( result.error );
				}

				return result || {};
			} );
		} );
	}

	// Resolves once the payment element is ready. Rejects with the element's
	// load error, or with the generic payment error after the time limit.
	function waitForPaymentElementReady( component ) {
		var ready = component.ready;

		if ( ! ready ) {
			return Promise.resolve();
		}

		return new Promise( function ( resolve, reject ) {
			var timer = window.setTimeout( function () {
				reject( new Error( config.genericErrorMessage || '' ) );
			}, PAYMENT_ELEMENT_READY_TIMEOUT_MS );

			ready.then( function ( isReady ) {
				window.clearTimeout( timer );

				if ( isReady ) {
					resolve();
					return;
				}

				reject(
					new Error(
						component.loadError || config.genericErrorMessage || ''
					)
				);
			} );
		} );
	}

	function isMissingRequiredAddressFieldsForBNPL( billingDetails ) {
		var paymentMethodTypes = getStripePaymentMethodTypes() || [];
		var isAffirm = paymentMethodTypes.indexOf( 'affirm' ) !== -1;
		var isAfterpay =
			paymentMethodTypes.indexOf( 'afterpay_clearpay' ) !== -1;
		var address = billingDetails && billingDetails.address;
		var requiredAddressFields;
		var isFieldMissing;

		if ( ( ! isAffirm && ! isAfterpay ) || ! address ) {
			return false;
		}

		// Line2 is not required for Affirm; city and state are not required
		// for Afterpay.
		requiredAddressFields = isAffirm
			? [ 'line1', 'state', 'city', 'postal_code', 'country' ]
			: [ 'line1', 'postal_code', 'country' ];

		isFieldMissing = requiredAddressFields.some( function ( field ) {
			return (
				address[ field ] === '' ||
				address[ field ] === null ||
				address[ field ] === undefined
			);
		} );

		if ( isFieldMissing ) {
			return true;
		}

		// Name is required for Affirm.
		return isAffirm && ! billingDetails.name;
	}

	function createPaymentMethod( component ) {
		return submitElements( component ).then( function () {
			var billingDetails = getCheckoutBillingDetails();
			var request = {
				elements: component.elements,
			};

			if (
				baseConfig.isOrderPay &&
				isMissingRequiredAddressFieldsForBNPL( billingDetails )
			) {
				// These payment methods reject an address object with partial
				// information; remove it entirely so the element gathers the
				// missing fields itself.
				delete billingDetails.address;
			}

			if ( billingDetails ) {
				request.params = {
					billing_details: billingDetails,
				};
			}

			return component.stripe.createPaymentMethod( request );
		} );
	}

	// Tears down one gateway's Payment Element: its container left the page, or it failed to load and the shopper
	// selected its method again (resetFailedPaymentElement()). The shared Stripe instances stay.
	function resetStripePaymentElement( paymentGatewayId ) {
		var component = paymentElements[ paymentGatewayId ];

		if ( component && component.paymentElement.unmount ) {
			component.paymentElement.unmount();
		}

		delete paymentElements[ paymentGatewayId ];
	}

	// A Payment Element that failed to load is rebuilt when the shopper selects its method again, so the
	// reselection retries the load, as rebuilding on every method switch used to. Client 11.1.0 never retries.
	// Keyed on the selected input, not the current config, which falls back to card for other gateways. The old
	// error leaves the gateway's box first; a new `loaderror` writes it back.
	function resetFailedPaymentElement() {
		var selectedGatewayId = getSelectedGatewayId() || gatewayId;
		var failed = paymentElements[ selectedGatewayId ];

		if ( failed && failed.loadError ) {
			failed.loadError = null;
			showPaymentElementLoadError( failed );
			resetStripePaymentElement( selectedGatewayId );
		}
	}

	// A Payment Element shows its load error in its own gateway's box: every element mounts at render, so the one
	// that failed may not be the selected method. Client 11.1.0 classic/payment-processing.js:368-386 also writes it
	// into the failed element's own container.
	function showPaymentElementLoadError( component ) {
		var wrapper = component.container.closest
			? component.container.closest( '.wcpay-core-checkout-form' )
			: null;
		var box = wrapper
			? wrapper.querySelector( '.wcpay-core-payment-errors' )
			: null;

		if ( ! box ) {
			setError( component.loadError );
			return;
		}

		box.textContent = component.loadError || '';
		box.hidden = ! component.loadError;
	}

	/**
	 * Start core's tokenization-form.js, which hides the card form while a
	 * saved method is selected. It starts only on `updated_checkout` or
	 * `wc-credit-card-form-init`, and order-pay fires neither, so fire the
	 * second on every mount, as client 11.1.0's mountStripePaymentElement()
	 * does.
	 */
	function initTokenizationForm() {
		document.body.dispatchEvent( new Event( 'wc-credit-card-form-init' ) );
	}

	// The WooPayments gateways in the page's payment method list, in its order, or the current gateway when the page
	// renders no list.
	function getRenderedGatewayIds() {
		var inputs = Array.prototype.slice.call(
			document.querySelectorAll( 'input[name="payment_method"]' )
		);

		if ( ! inputs.length ) {
			return [ gatewayId ];
		}

		return inputs
			.map( function ( input ) {
				return input.value;
			} )
			.filter( function ( paymentGatewayId ) {
				return !! getConfigForGateway( paymentGatewayId );
			} );
	}

	// The gateways that mount a Payment Element now, each with its container. Gateways rendered without their own
	// list row can resolve to one shared container; it goes to the selected gateway, else to the first of them.
	function getPaymentElementMounts( selectedGatewayId ) {
		var mounts = [];

		getRenderedGatewayIds().forEach( function ( paymentGatewayId ) {
			var container;

			setCurrentGatewayConfig( paymentGatewayId );
			container = getGatewayPaymentContainer( gatewayId );
			if (
				container &&
				! isPaymentListWalletGateway( gatewayId ) &&
				config.isCoreNativeCheckoutAvailable &&
				config.publishableKey
			) {
				mounts.push( { gatewayId: gatewayId, container: container } );
			}
		} );
		setCurrentGatewayConfig( selectedGatewayId );

		return mounts.filter( function ( mount ) {
			var sharing = mounts.filter( function ( other ) {
				return other.container === mount.container;
			} );
			var selected = sharing.filter( function ( other ) {
				return other.gatewayId === selectedGatewayId;
			} );

			return mount === ( selected[ 0 ] || sharing[ 0 ] );
		} );
	}

	// Client 11.1.0 mounts one Payment Element per WooPayments method when checkout renders
	// (classic/event-handlers.js:415-429, payment-processing.js:258-387) and keeps them across method switches, so
	// a method's element is usually ready before the shopper picks it. An element whose container is still on the
	// page is kept; one whose container was re-rendered (updated_checkout) moves into the new one unchanged; one
	// whose gateway no longer renders a container is torn down.
	function initializeStripeElement() {
		var selectedGatewayId;
		var mounts;

		setCurrentGatewayConfig();
		selectedGatewayId = gatewayId;
		hydrateCardBrandIcons();
		mounts = getPaymentElementMounts( selectedGatewayId );

		Object.keys( paymentElements ).forEach( function ( paymentGatewayId ) {
			var isStillMounted = mounts.some( function ( mount ) {
				return mount.gatewayId === paymentGatewayId;
			} );

			if ( ! isStillMounted ) {
				resetStripePaymentElement( paymentGatewayId );
			}
		} );

		if ( ! mounts.length ) {
			return;
		}

		// Client 11.2.0 classic/payment-processing.js:333-340 mounts no Payment Element until the fingerprint
		// resolves, and shows the error instead when it fails.
		if ( ! isDeviceFingerprintReady ) {
			if ( ! isWaitingForDeviceFingerprint ) {
				isWaitingForDeviceFingerprint = true;
				getDeviceFingerprint().then(
					function () {
						isWaitingForDeviceFingerprint = false;
						initializeStripeElement();
					},
					function ( error ) {
						isWaitingForDeviceFingerprint = false;
						setError(
							error && error.message
								? error.message
								: config.fingerprintErrorMessage || ''
						);
					}
				);
			}
			return;
		}

		if ( typeof window.Stripe !== 'function' ) {
			if ( ! isWaitingForStripe ) {
				isWaitingForStripe = true;
				window.wc.woopayments.waitForStripe().then(
					function () {
						isWaitingForStripe = false;
						initializeStripeElement();
					},
					function () {
						isWaitingForStripe = false;
					}
				);
			}
			return;
		}

		mounts.forEach( function ( mount ) {
			setCurrentGatewayConfig( mount.gatewayId );
			mountPaymentElement( mount.gatewayId, mount.container );
		} );
		setCurrentGatewayConfig( selectedGatewayId );
	}

	// Mounts the gateway's Payment Element, creating it on the first call. Reads the gateway's config, which the
	// caller has made current.
	function mountPaymentElement( paymentGatewayId, container ) {
		var component = paymentElements[ paymentGatewayId ];
		var settleReady;
		var isPlatformStripe;
		var elements;

		if ( component ) {
			if ( component.container !== container ) {
				if ( component.paymentElement.unmount ) {
					component.paymentElement.unmount();
				}
				initTokenizationForm();
				component.paymentElement.mount( container );
				component.container = container;
			}
			return;
		}

		isPlatformStripe = shouldUsePlatformStripe();
		component = {
			stripe: isPlatformStripe ? getPlatformStripe() : getAccountStripe(),
			isPlatformStripe: isPlatformStripe,
			elements: null,
			paymentElement: null,
			container: container,
			loadError: null,
			ready: new Promise( function ( resolve ) {
				settleReady = resolve;
			} ),
		};
		elements = component.stripe.elements( getStripeElementsOptions() );
		component.elements = elements;
		component.paymentElement = elements.create(
			'payment',
			getStripePaymentElementOptions()
		);
		component.paymentElement.on( 'ready', function () {
			settleReady( true );
		} );
		component.paymentElement.on( 'loaderror', function ( event ) {
			component.loadError =
				event && event.error && event.error.message
					? event.error.message
					: config.genericErrorMessage || '';
			showPaymentElementLoadError( component );
			settleReady( false );
		} );
		// An edit in the selected element clears the payment error its validation left in the box, which client
		// 11.2.0 keeps until the next submit. A load error shares the box and stays.
		component.paymentElement.on( 'change', function () {
			if (
				paymentElements[ getSelectedGatewayId() ] === component &&
				! component.loadError
			) {
				setError( '' );
			}
		} );
		paymentElements[ paymentGatewayId ] = component;
		initTokenizationForm();
		component.paymentElement.mount( container );
	}

	function createPaymentMethodAndSubmit( form ) {
		var component = paymentElements[ gatewayId ];

		if ( ! component ) {
			// No Payment Element without the fingerprint: the client's submit refuses then too
			// (classic/payment-processing.js:449-465). Retry the fingerprint, which shows its error again.
			if ( ! isDeviceFingerprintReady ) {
				initializeStripeElement();
				return false;
			}
			appendPaymentFields( form, null, null );
			return true;
		}

		// Client 11.1.0 classic/payment-processing.js:82-108, :449-497: block the form before tokenizing, so core's
		// checkout.js ignores another click while the form has `processing`; release it on failure; drop
		// `processing` just before the intentional resubmit, which core would otherwise ignore.
		form.addClass( 'processing' ).block( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6,
			},
		} );

		function submitWithPaymentFields( paymentMethod, error ) {
			appendPaymentFields( form, paymentMethod, error, component );
			isSubmittingWithPaymentMethod = true;
			form.removeClass( 'processing' ).trigger( 'submit' );
		}

		createPaymentMethod( component )
			.then( function ( result ) {
				if ( result.error ) {
					// Submit with the error sentinel so the server records a
					// failed order carrying the decline reason; the failure
					// message is surfaced from the server response.
					submitWithPaymentFields( null, result.error );
					return;
				}

				setError( '' );
				submitWithPaymentFields( result.paymentMethod, null );
			} )
			.catch( function ( error ) {
				form.removeClass( 'processing' ).unblock();
				appendPaymentFields( form, null, error, component );
				setError( error && error.message ? error.message : '' );
				$( document.body ).trigger( 'checkout_error', [
					error && error.message ? error.message : '',
				] );
			} );

		return false;
	}

	function handleGatewaySubmission( paymentGatewayId, form, isCheckoutForm ) {
		setCurrentGatewayConfig( paymentGatewayId );

		if ( ! isSelectedGateway() ) {
			return true;
		}

		if ( isPaymentListWalletGateway( paymentGatewayId ) ) {
			return true;
		}

		if ( isSubmittingWithPaymentMethod ) {
			isSubmittingWithPaymentMethod = false;
			return true;
		}

		if ( isUsingSavedPaymentMethod() ) {
			appendPaymentFields( form, null, null );
			return true;
		}

		// Let WooCommerce's own validation surface missing billing fields
		// instead of creating an orphan PaymentMethod first. Only the checkout
		// form carries billing fields: the pay-for-order and change-payment
		// forms must still tokenize the card, as the plugin does.
		if ( isCheckoutForm && isBillingInformationMissing() ) {
			return true;
		}

		return createPaymentMethodAndSubmit( form );
	}

	function submitAddPaymentMethodForm( formElement ) {
		isSubmittingWithSetupIntent = true;
		formElement.submit();
	}

	function createSetupIntentAndSubmit( formElement ) {
		var component = paymentElements[ gatewayId ];

		if ( ! component ) {
			if ( ! isDeviceFingerprintReady ) {
				initializeStripeElement();
			}
			return true;
		}

		createPaymentMethod( component )
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
				$( formElement ).removeClass( 'processing' ).unblock();
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

	function isChangingPaymentMethodForSubscription() {
		if (
			window.location &&
			/[?&]change_payment_method=/.test( window.location.search || '' )
		) {
			return true;
		}

		return (
			$(
				'form.checkout, form#order_review, form#add_payment_method'
			).find( 'input[name="change_payment_method"]' ).length > 0
		);
	}

	function shouldSavePaymentMethodAfterConfirmation() {
		var currentUrl = new window.URL( window.location.href );

		return (
			currentUrl.searchParams.get( 'save_payment_method' ) === 'yes' ||
			shouldSavePaymentMethod()
		);
	}

	function updateOrderStatusAfterConfirmation(
		confirmation,
		intentId,
		shouldSaveAfterConfirmation
	) {
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
			should_save_payment_method: shouldSaveAfterConfirmation
				? 'true'
				: 'false',
			is_changing_payment: isChangingPaymentMethodForSubscription()
				? 'true'
				: 'false',
		} );
	}

	function consumeConfirmationHash() {
		var currentUrl = new window.URL( window.location.href );

		currentUrl.hash = '';
		currentUrl.searchParams.delete( 'save_payment_method' );
		window.history.replaceState(
			'',
			document.title,
			currentUrl.pathname + currentUrl.search
		);
	}

	function getConfirmationErrorMessage( error ) {
		return error && typeof error.message === 'string'
			? error.message
			: config.confirmationErrorMessage || '';
	}

	function isRetrySafePaymentIntentError( error ) {
		var paymentIntent =
			error && typeof error === 'object' ? error.payment_intent : null;

		return (
			paymentIntent &&
			typeof paymentIntent === 'object' &&
			( paymentIntent.status === 'requires_payment_method' ||
				paymentIntent.status === 'canceled' )
		);
	}

	function getConfirmedIntentId( confirmation, result ) {
		var intent;

		if ( ! result || typeof result !== 'object' || result.error ) {
			return '';
		}

		intent =
			confirmation.type === 'si'
				? result.setupIntent
				: result.paymentIntent;

		return intent &&
			typeof intent === 'object' &&
			typeof intent.id === 'string'
			? intent.id
			: '';
	}

	function confirmRedirectIfPresent() {
		var confirmation = window.wc.woopayments.parseConfirmationHash(
			window.location.hash || ''
		);
		var activePaymentForm;
		var intentId;
		var failedIntentId;
		var confirmationPromise;
		var shouldSaveAfterConfirmation;
		var hasReleasedConfirmationUi = false;

		function blockConfirmationUi() {
			activePaymentForm = getActivePaymentForm();
			activePaymentForm.addClass( 'processing' ).block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				},
			} );
		}

		function releaseConfirmationUi() {
			if ( hasReleasedConfirmationUi ) {
				return;
			}

			hasReleasedConfirmationUi = true;
			activePaymentForm.removeClass( 'processing' ).unblock();
		}

		setCurrentGatewayConfig();

		if ( ! confirmation ) {
			return;
		}

		shouldSaveAfterConfirmation =
			shouldSavePaymentMethodAfterConfirmation();
		consumeConfirmationHash();
		blockConfirmationUi();

		if ( ! config.publishableKey ) {
			setError( config.confirmationErrorMessage || '' );
			return;
		}

		// Client 11.1.0 api.confirmIntent() reaches Stripe through getStripe(), which waits for Stripe.js.
		if ( typeof window.Stripe === 'function' ) {
			confirmWithAccountStripe();
		} else {
			window.wc.woopayments
				.waitForStripe()
				.then( confirmWithAccountStripe, function () {
					setError( config.confirmationErrorMessage || '' );
				} );
		}

		function confirmWithAccountStripe() {
			confirmationPromise = window.wc.woopayments.confirmWithStripe(
				confirmation,
				getAccountStripe()
			);

			if ( ! confirmationPromise ) {
				setError( config.confirmationErrorMessage || '' );
				return;
			}

			confirmationPromise.then(
				function ( result ) {
					if ( ! result || typeof result !== 'object' ) {
						setError( config.confirmationErrorMessage || '' );
						return;
					}

					if ( result.error ) {
						setError( getConfirmationErrorMessage( result.error ) );

						// Report the failed authentication to the server so the
						// order is marked failed synchronously (stock released,
						// failure note recorded) instead of staying
						// pending-payment until a webhook maybe arrives.
						failedIntentId =
							( result.error.payment_intent &&
								result.error.payment_intent.id ) ||
							( result.error.setup_intent &&
								result.error.setup_intent.id ) ||
							'';
						if ( failedIntentId ) {
							updateOrderStatusAfterConfirmation(
								confirmation,
								failedIntentId,
								shouldSaveAfterConfirmation
							);
						}

						if ( isRetrySafePaymentIntentError( result.error ) ) {
							releaseConfirmationUi();
						}
						return;
					}

					intentId = getConfirmedIntentId( confirmation, result );
					if ( ! intentId ) {
						setError( config.confirmationErrorMessage || '' );
						return;
					}

					updateOrderStatusAfterConfirmation(
						confirmation,
						intentId,
						shouldSaveAfterConfirmation
					)
						.done( function ( response ) {
							var resultResponse;
							var returnUrl;

							try {
								resultResponse =
									typeof response === 'string'
										? JSON.parse( response )
										: response;
							} catch ( error ) {
								setError( config.confirmationErrorMessage || '' );
								return;
							}

							if (
								! resultResponse ||
								typeof resultResponse !== 'object'
							) {
								setError( config.confirmationErrorMessage || '' );
								return;
							}

							if ( resultResponse.error ) {
								setError(
									getConfirmationErrorMessage(
										resultResponse.error
									)
								);
								return;
							}

							returnUrl =
								typeof resultResponse.return_url === 'string'
									? resultResponse.return_url.trim()
									: '';
							if ( ! returnUrl ) {
								setError( config.confirmationErrorMessage || '' );
								return;
							}

							try {
								returnUrl = new window.URL(
									returnUrl,
									window.location.href
								);
							} catch ( error ) {
								setError( config.confirmationErrorMessage || '' );
								return;
							}
							if (
								returnUrl.protocol !== 'http:' &&
								returnUrl.protocol !== 'https:'
							) {
								setError( config.confirmationErrorMessage || '' );
								return;
							}

							releaseConfirmationUi();
							window.location.href = returnUrl.href;
						} )
						.fail( function () {
							setError( config.confirmationErrorMessage || '' );
						} );
				},
				function ( error ) {
					setError( getConfirmationErrorMessage( error ) );
				}
			);
		}
	}

	/**
	 * Enqueue the anti-fraud scripts described by the localized fraud-services
	 * config, mirroring the WooPayments client plugin's fraud-scripts loader.
	 *
	 * Idempotent across scripts sharing the page: the Sift snippet must push
	 * its account, identity and pageview commands exactly once.
	 */
	function enqueueFraudScripts() {
		var fraudConfig = baseConfig.fraudServices;
		var siftConfig;
		var siftQueue;
		var script;

		if ( ! fraudConfig || window.__wooPaymentsFraudScriptsEnqueued ) {
			return;
		}
		window.__wooPaymentsFraudScriptsEnqueued = true;

		if ( fraudConfig.sift ) {
			siftConfig = fraudConfig.sift;
			siftQueue = window._sift = window._sift || [];
			siftQueue.push( [ '_setAccount', siftConfig.beacon_key ] );
			siftQueue.push( [ '_setUserId', siftConfig.user_id ] );
			siftQueue.push( [ '_setSessionId', siftConfig.session_id ] );
			siftQueue.push( [ '_trackPageview' ] );

			if (
				! document.querySelector(
					'[src="https://cdn.sift.com/s.js"]'
				)
			) {
				script = document.createElement( 'script' );
				script.src = 'https://cdn.sift.com/s.js';
				script.async = true;
				document.body.appendChild( script );
			}
		}

		// Stripe.js is already a registered dependency of this script; the
		// injection below only covers the config-driven case where it is not,
		// matching the plugin's loader.
		if (
			fraudConfig.stripe &&
			! document.querySelector( '[src^="https://js.stripe.com/v3"]' )
		) {
			script = document.createElement( 'script' );
			script.src = 'https://js.stripe.com/v3';
			script.async = true;
			document.body.appendChild( script );
		}
	}

	/**
	 * Compute the buyer *device* fingerprint the platform's risk rules score
	 * on, as client 11.2.0 utils/fingerprint.js getFingerprint(). A failed
	 * attempt is retried by the next call, as the client calls it per mount.
	 */
	function getDeviceFingerprint() {
		if ( deviceFingerprintRequest ) {
			return deviceFingerprintRequest;
		}

		deviceFingerprintRequest = new Promise( function ( resolve ) {
			// A blocked wc-woopayments-fingerprintjs script leaves no FingerprintJS.
			if ( ! window.FingerprintJS || ! window.FingerprintJS.load ) {
				throw new Error( config.fingerprintErrorMessage || '' );
			}
			resolve( window.FingerprintJS.load( { monitoring: false } ) );
		} )
			.then( function ( agent ) {
				if ( ! agent ) {
					throw new Error( config.fingerprintErrorMessage || '' );
				}
				return agent.get();
			} )
			.then(
				function ( result ) {
					deviceFingerprint = ( result && result.visitorId ) || '';
					isDeviceFingerprintReady = true;
				},
				function ( error ) {
					deviceFingerprintRequest = null;
					throw error;
				}
			);

		return deviceFingerprintRequest;
	}


	// ------------------------------------------------------------------
	// WooPay email check: the WooPay protocol both checkouts share (wc-woopayments-woopay-common, client 11.2.0
	// checkout/woopay/email-input-iframe.js) with this checkout's billing email field, notices and requests.
	// ------------------------------------------------------------------
	function getWooPayProtocol() {
		return window.wc.woopayments.woopay;
	}

	// The Customizer preview iframe or a post preview.
	function isPreviewing() {
		return getWooPayProtocol().isPreviewing( {
			isPreview: baseConfig.isPreview,
		} );
	}

	// This checkout's WooPay requests: jQuery posts, nested values as bracketed keys.
	function postWooPayAjax( endpoint, data ) {
		return new Promise( function ( resolve, reject ) {
			$.post(
				getWooPayProtocol().buildAjaxUrl(
					{ wcAjaxUrl: baseConfig.wcAjaxUrl },
					endpoint
				),
				data
			)
				.done( resolve )
				.fail( reject );
		} );
	}

	// The shared Tracks identity resolver (wc-woopayments-common) with the base config's values.
	function getTracksIdentity() {
		return window.wc.woopayments.tracks.getIdentity( {
			ajaxUrl: baseConfig.ajaxUrl,
			platformTrackerNonce: baseConfig.platformTrackerNonce,
		} );
	}

	// The plugin's resolveWoopayAppearance(): server-computed appearance
	// first, DOM extraction on the shortcode checkout as the fallback.
	function getWooPayEmailInputAppearance() {
		if ( ! baseConfig.isWooPayGlobalThemeSupportEnabled ) {
			return null;
		}

		if ( baseConfig.woopayAppearance ) {
			return baseConfig.woopayAppearance;
		}

		return baseConfig.isShortcodeCheckout
			? getClassicCheckoutAppearance( true )
			: null;
	}

	// The values the WooPay email check reads, from the base config. init_woopay sends the shortcode checkout's own
	// appearance and fonts, read from the page, as the plugin's initWooPay() does.
	function getWooPayEmailInputArgs() {
		return {
			wcAjaxUrl: baseConfig.wcAjaxUrl,
			post: postWooPayAjax,
			initWooPayNonce: baseConfig.initWooPayNonce,
			woopaySessionNonce: baseConfig.woopaySessionNonce,
			orderId: baseConfig.orderId || '',
			key: baseConfig.key || '',
			billingEmail: baseConfig.billing_email || '',
			getInitAppearance: function () {
				if ( ! baseConfig.isWooPayGlobalThemeSupportEnabled ) {
					return null;
				}

				return baseConfig.isShortcodeCheckout
					? getClassicCheckoutAppearance( true )
					: baseConfig.woopayAppearance || null;
			},
			getInitFontRules: function () {
				if ( ! baseConfig.isWooPayGlobalThemeSupportEnabled ) {
					return null;
				}

				return baseConfig.isShortcodeCheckout
					? appearanceUtils.getFontRulesFromPage()
					: baseConfig.woopayFontRules || null;
			},
			getSessionAppearance: getWooPayEmailInputAppearance,
			woopayHost: baseConfig.woopayHost,
			woopayMerchantId: baseConfig.woopayMerchantId,
			woopaySignatureNonce: baseConfig.woopaySignatureNonce,
			wcpayVersionNumber: baseConfig.wcpayVersionNumber,
			testMode: baseConfig.testMode,
			isWoopayFirstPartyAuthEnabled:
				baseConfig.isWoopayFirstPartyAuthEnabled,
			woopayIsCountryAvailable: baseConfig.woopayIsCountryAvailable,
			isBlocksCheckout: false,
			otpIframeTitle: baseConfig.woopayOtpIframeTitle,
			otpCloseLabel: baseConfig.woopayOtpCloseLabel,
			unavailableMessage: baseConfig.woopayUnavailableMessage,
			recordEvent: recordUserEvent,
			navigate: function ( url ) {
				navigate( url );
			},
			// Placing the order cancels the lookup and closes the iframe.
			onPlaceOrder: function ( callback ) {
				var checkoutForm = document.querySelector( 'form[name="checkout"]' );

				if ( checkoutForm ) {
					checkoutForm.addEventListener( 'submit', callback );
				}
			},
			// Client 11.1.0 reads window.woopayCheckout; native localizes the flag with the WooPay config.
			isSaveMyInfoPreChecked: function () {
				return (
					window.wcpay_core_woopay_config &&
					window.wcpay_core_woopay_config.PRE_CHECK_SAVE_MY_INFO
				);
			},
		};
	}

	function handleWooPayEmailInput( selector ) {
		var woopayEmailInput = document.querySelector( selector );

		// Pay-for-order pages carry their own WooPay entry point.
		if ( baseConfig.isOrderPay || ! woopayEmailInput ) {
			return;
		}

		// The plugin resolves the Tracks identity before wiring anything so
		// the OTP URL always carries it.
		getTracksIdentity().then( function ( identity ) {
			getWooPayProtocol().handleEmailInput(
				getWooPayEmailInputArgs(),
				woopayEmailInput,
				identity
			);
		} );
	}
	$( function () {
		enqueueFraudScripts();
		if (
			baseConfig.isWooPayEnabled &&
			baseConfig.isWooPayEmailInputEnabled &&
			! isPreviewing()
		) {
			handleWooPayEmailInput( '#billing_email' );
		}
		registerPaymentListWallets();
		togglePaymentMethodsForBillingCountry();
		initializeStripeElement();
		swapPaymentMethodIconsForTheme();
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
		if (
			( window.location.hash || '' ).indexOf( '#wcpay-confirm-' ) === 0
		) {
			confirmRedirectIfPresent();
		}
	} );

	$( document.body ).on( 'updated_checkout', function () {
		mergeRefreshedPaymentFieldsConfig();
		registerCheckoutSubmitHandlers();
		registerPaymentListWallets();
		togglePaymentMethodsForBillingCountry();
		initializeStripeElement();
		swapPaymentMethodIconsForTheme();
	} );

	// Switching methods keeps every mounted Payment Element; the pass only mounts what is missing.
	$( document.body ).on( 'payment_method_selected', function () {
		togglePaymentMethodsForBillingCountry();
		resetFailedPaymentElement();
		initializeStripeElement();
		swapPaymentMethodIconsForTheme();
	} );

	registerCheckoutSubmitHandlers();

	$( 'form#order_review' ).on( 'submit', function () {
		var paymentGatewayId = getSelectedGatewayId();

		if ( getKnownGatewayIds().indexOf( paymentGatewayId ) === -1 ) {
			return true;
		}

		return handleGatewaySubmission( paymentGatewayId, $( this ), false );
	} );

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports.__test__ = {
			setNavigate: function ( callback ) {
				navigate = callback;
			},
		};
	}
} )( jQuery, window, document );
