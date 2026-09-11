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
	var stripe = null;
	var elements = null;
	var paymentElement = null;
	var paymentElementGatewayId = null;
	var paymentElementContainer = null;
	// The `loaderror` the payment element reported, when it reported one. The
	// WooPayments client plugin keeps the same flag and refuses to submit while
	// it is set; without it a shopper whose payment form failed to load presses
	// the button and gets no navigation, no message and no explanation.
	var paymentElementLoadError = null;
	var deviceFingerprint = '';
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
		var customButtonApi =
			window.wc && window.wc.customPlaceOrderButton;
		var form;
		var formElement;

		if (
			customButtonApi &&
			typeof customButtonApi.__getForm === 'function'
		) {
			form = customButtonApi.__getForm();
			if ( form && form.length ) {
				return form;
			}
		}

		formElement = document.querySelector(
			'form.checkout, form#order_review'
		);

		return formElement ? $( formElement ) : $( 'form.checkout' );
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
		var customerData =
			baseConfig.customerData || window.wcpayCustomerData || {};

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
		var nonce =
			config.platformTrackerNonce || config.platform_tracker_nonce;
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

	function isLinkEnabled() {
		var paymentMethodsConfig = config.paymentMethodsConfig || {};

		return (
			paymentMethodsConfig.link !== undefined &&
			paymentMethodsConfig.card !== undefined
		);
	}

	// Betas the reference client requests on every connected-account
	// Stripe instance; the Link autofill modal only works with Link on.
	function getStripeBetas() {
		var betas = [ 'card_country_event_beta_1' ];

		if ( isLinkEnabled() ) {
			betas.push( 'link_autofill_modal_beta_1' );
		}

		return betas;
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
		var customerData =
			baseConfig.customerData || window.wcpayCustomerData || {};
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
			terms: getReusablePaymentMethodTerms(
				shouldSavePaymentMethod() || config.cartContainsSubscription
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

	function resetExpressButtonState( state ) {
		if ( state.element && state.element.unmount ) {
			state.element.unmount();
		}

		state.element = null;
		state.elements = null;
		state.stripe = null;
	}

	function registerPaymentListWallet( paymentGatewayId ) {
		var customButtonApi =
			window.wc && window.wc.customPlaceOrderButton;
		var walletType = getExpressWalletType( paymentGatewayId );
		var state;

		if (
			! walletType ||
			! customButtonApi ||
			typeof customButtonApi.register !== 'function' ||
			expressButtonStates[ paymentGatewayId ]
		) {
			return;
		}

		state = {
			element: null,
			elements: null,
			stripe: null,
		};
		expressButtonStates[ paymentGatewayId ] = state;

		customButtonApi.register( paymentGatewayId, {
			render: function ( container, checkoutApi ) {
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
					state.stripe = window.Stripe( gatewayConfig.publishableKey, {
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

	function getClassicCheckoutAppearance() {
		var version = config.stylesCacheVersion || '';
		var cachedAppearance = appearanceUtils.getCachedAppearance(
			classicCheckoutAppearanceLocation,
			version
		);
		var appearance;

		if ( cachedAppearance ) {
			appearanceUtils.maybePersistWooPayAppearance(
				cachedAppearance,
				config
			);
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
			window.dispatchEvent(
				new window.Event( 'wcpay-appearance-cached' )
			);
		}
		appearanceUtils.maybePersistWooPayAppearance( appearance, config );

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
		popover.style.gridTemplateColumns = 'repeat(' + itemsPerRow + ', 38px)';
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
		// A payment element that failed to load can never produce a payment
		// method, and asking it for one leaves the shopper waiting on a
		// promise that will not resolve. Answer with what it already reported.
		if ( paymentElementLoadError ) {
			return Promise.reject( new Error( paymentElementLoadError ) );
		}

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

	function createPaymentMethod() {
		return submitElements().then( function () {
			var billingDetails = getCheckoutBillingDetails();
			var request = {
				elements: elements,
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

			return stripe.createPaymentMethod( request );
		} );
	}

	function resetStripePaymentElement() {
		if ( paymentElement && paymentElement.unmount ) {
			paymentElement.unmount();
		}

		stripe = null;
		elements = null;
		paymentElement = null;
		paymentElementContainer = null;
		paymentElementGatewayId = null;
		paymentElementLoadError = null;
	}

	function initializeStripeElement() {
		setCurrentGatewayConfig();
		var container = getGatewayPaymentContainer( gatewayId );
		hydrateCardBrandIcons();
		if ( isPaymentListWalletGateway( gatewayId ) ) {
			resetStripePaymentElement();
			return;
		}
		if (
			! container ||
			! config.isCoreNativeCheckoutAvailable ||
			! config.publishableKey ||
			! window.Stripe
		) {
			return;
		}

		if ( paymentElement && paymentElementGatewayId !== gatewayId ) {
			resetStripePaymentElement();
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
			betas: getStripeBetas(),
		} );
		elements = stripe.elements( getStripeElementsOptions() );
		paymentElement = elements.create(
			'payment',
			getStripePaymentElementOptions()
		);
		paymentElementLoadError = null;
		paymentElement.on( 'loaderror', function ( event ) {
			paymentElementLoadError =
				event && event.error && event.error.message
					? event.error.message
					: config.genericErrorMessage || '';
			setError( paymentElementLoadError );
		} );
		paymentElement.mount( container );
		paymentElementContainer = container;
		paymentElementGatewayId = gatewayId;
	}

	function createPaymentMethodAndSubmit( form ) {
		if ( ! stripe || ! elements ) {
			appendPaymentFields( form, null, null );
			return true;
		}

		createPaymentMethod()
			.then( function ( result ) {
				if ( result.error ) {
					// Submit with the error sentinel so the server records a
					// failed order carrying the decline reason; the failure
					// message is surfaced from the server response.
					appendPaymentFields( form, null, result.error );
					isSubmittingWithPaymentMethod = true;
					form.trigger( 'submit' );
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
		var confirmation = parseConfirmationHash( window.location.hash || '' );
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

		if ( ! config.publishableKey || ! window.Stripe ) {
			setError( config.confirmationErrorMessage || '' );
			return;
		}

		stripe =
			stripe ||
			window.Stripe( config.publishableKey, {
				locale: config.locale || 'auto',
				stripeAccount: config.accountId || undefined,
				betas: getStripeBetas(),
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
	 * on. Best-effort: fingerprinting must never block or break checkout.
	 */
	function computeDeviceFingerprint() {
		if ( ! window.FingerprintJS || ! window.FingerprintJS.load ) {
			return;
		}
		window.FingerprintJS.load( { monitoring: false } )
			.then( function ( agent ) {
				return agent.get();
			} )
			.then( function ( result ) {
				deviceFingerprint = ( result && result.visitorId ) || '';
			} )
			.catch( function () {
				// Fingerprinting is best-effort; checkout proceeds without it.
			} );
	}


	// ------------------------------------------------------------------
	// WooPay email-input / OTP flow — port of the plugin's
	// checkout/woopay/email-input-iframe.js. Looks the typed billing email
	// up at WooPay and, for a registered shopper, opens WooPay's
	// one-time-code iframe anchored to the field; a verified code hands the
	// platform session back through postMessage and redirects to WooPay.
	// ------------------------------------------------------------------
	var wooPayEmailInputWaitTime = 500;
	var wooPayFullScreenModalBreakpoint = 768;
	var isWooPayInitRequesting = false;

	// The plugin's isPreviewing(): the Customizer preview iframe (which
	// carries customize_messenger_channel) or a post preview.
	function isPreviewing() {
		return (
			new window.URLSearchParams( window.location.search ).get(
				'customize_messenger_channel'
			) !== null || !! baseConfig.isPreview
		);
	}

	function getWooPayHostOrigin() {
		try {
			return new window.URL( baseConfig.woopayHost ).origin;
		} catch ( error ) {
			return '';
		}
	}

	function buildWooPayAjaxUrl( endpoint ) {
		return ( baseConfig.wcAjaxUrl || '' ).replace(
			'%%endpoint%%',
			'wcpay_' + endpoint
		);
	}

	function validateWooPayEmail( value ) {
		// Borrowed from WooCommerce checkout.js with a slight tweak to add
		// `{2,}` to the end and make the TLD at least 2 characters.
		/* eslint-disable */
		var pattern = new RegExp(
			/^([a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+(\.[a-z\d!#$%&'*+\-\/=?^_`{|}~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]+)*|"((([ \t]*\r\n)?[ \t]+)?([\x01-\x08\x0b\x0c\x0e-\x1f\x7f\x21\x23-\x5b\x5d-\x7e\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|\\[\x01-\x09\x0b\x0c\x0d-\x7f\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))*(([ \t]*\r\n)?[ \t]+)?")@(([a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[a-z\d\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])\.)+([a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]|[a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF][a-z\d\-._~\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]*[0-9a-z\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]){2,}\.?$/i
		);
		/* eslint-enable */
		return pattern.test( value );
	}

	// The shopper opted out of WooPay for this session (back button from
	// WooPay, or ?skip_woopay=true); the cookie extends the skip to the
	// whole session.
	function shouldSkipWooPay() {
		var skipWooPayCookie = document.cookie
			.split( ';' )
			.filter( function ( cookie ) {
				return cookie.indexOf( 'skip_woopay' ) !== -1;
			} )[ 0 ];
		var parts;

		if ( ! skipWooPayCookie ) {
			return false;
		}

		parts = skipWooPayCookie.split( '=' );

		return parts[ 0 ].trim() === 'skip_woopay' && parts[ 1 ].trim() === '1';
	}

	// Called when the shopper explicitly opts back into WooPay.
	function deleteSkipWooPayCookie() {
		if ( ! shouldSkipWooPay() ) {
			return;
		}

		document.cookie =
			'skip_woopay=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
	}

	function getTracksIdentityCookieValue() {
		var nameEq = 'tk_ai=';
		var cookies = document.cookie.split( ';' );
		var i;
		var cookie;

		for ( i = 0; i < cookies.length; i++ ) {
			cookie = cookies[ i ].replace( /^\s+/, '' );
			if ( cookie.indexOf( nameEq ) === 0 ) {
				return cookie.substring( nameEq.length );
			}
		}

		return undefined;
	}

	// Resolves the stringified Tracks identity WooPay stitches its own
	// events to — from the tk_ai cookie when present, otherwise through
	// the platform's get_identity bridge. Never rejects.
	function getTracksIdentity() {
		var ajaxUrl = baseConfig.ajaxUrl || baseConfig.ajax_url;
		var nonce =
			baseConfig.platformTrackerNonce ||
			baseConfig.platform_tracker_nonce;
		var cookieIdentity = getTracksIdentityCookieValue();
		var body;

		if ( cookieIdentity ) {
			return Promise.resolve(
				JSON.stringify( { _ut: 'anon', _ui: cookieIdentity } )
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
				if ( ! response.ok ) {
					return undefined;
				}

				return response.json().then( function ( data ) {
					if (
						data &&
						data.success &&
						data.data &&
						data.data._ui &&
						data.data._ut
					) {
						return JSON.stringify( data.data );
					}

					return undefined;
				} );
			} )
			.catch( function () {
				return undefined;
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
			? getClassicCheckoutAppearance()
			: null;
	}

	// The plugin's initWooPay(): a single init_woopay request in flight per
	// page — WooPay's <Login> re-renders and fires the redirect message
	// twice, and the second call must return undefined.
	function initWooPayFromEmailInput( email, userSession ) {
		var appearance = null;
		var fontRules = null;

		if ( isWooPayInitRequesting ) {
			return undefined;
		}

		isWooPayInitRequesting = true;

		if ( baseConfig.isWooPayGlobalThemeSupportEnabled ) {
			if ( baseConfig.isShortcodeCheckout ) {
				appearance = getClassicCheckoutAppearance();
				fontRules = appearanceUtils.getFontRulesFromPage();
			} else {
				appearance = baseConfig.woopayAppearance || null;
				fontRules = baseConfig.woopayFontRules || null;
			}
		}

		return new Promise( function ( resolve, reject ) {
			$.post( buildWooPayAjaxUrl( 'init_woopay' ), {
				_wpnonce: baseConfig.initWooPayNonce || '',
				appearance: appearance,
				font_rules: fontRules,
				email: email,
				user_session: userSession,
				order_id: baseConfig.orderId || '',
				key: baseConfig.key || '',
				billing_email: baseConfig.billing_email || '',
			} )
				.done( resolve )
				.fail( reject )
				.always( function () {
					isWooPayInitRequesting = false;
				} );
		} );
	}

	function handleWooPayEmailInput( selector ) {
		var woopayEmailInput = document.querySelector( selector );
		var timer;
		var tracksUserId;
		var spinner;
		var parentDiv;
		var iframeWrapper;
		var iframe;
		var iframeArrow;
		var errorMessage;
		var searchParams;
		var isSkipWoopayCookieSet;
		var customerClickedBackButton;
		var iframeHeaderValue = true;
		var abortController;
		var checkoutForm;
		var followingDay;
		var pathname;

		// Pay-for-order pages carry their own WooPay entry point.
		if ( baseConfig.isOrderPay || ! woopayEmailInput ) {
			return;
		}

		// The plugin resolves the Tracks identity before wiring anything so
		// the OTP URL always carries it.
		getTracksIdentity().then( function ( identity ) {
			tracksUserId = identity;
			wireWooPayEmailInput();
		} );

		function wireWooPayEmailInput() {
			spinner = document.createElement( 'div' );
			parentDiv = woopayEmailInput.parentNode;
			spinner.classList.add( 'wc-block-components-spinner' );

			// The OTP iframe wrapper (the dimmed backdrop on wide viewports).
			iframeWrapper = document.createElement( 'div' );
			iframeWrapper.setAttribute( 'role', 'dialog' );
			iframeWrapper.setAttribute( 'aria-modal', 'true' );
			iframeWrapper.classList.add( 'woopay-otp-iframe-wrapper' );

			iframe = document.createElement( 'iframe' );
			iframe.title = baseConfig.woopayOtpIframeTitle || '';
			iframe.classList.add( 'woopay-otp-iframe' );
			// Keep twentytwenty.intrinsicRatioVideos from resizing the iframe.
			iframe.classList.add( 'intrinsic-ignore' );

			iframeArrow = document.createElement( 'span' );
			iframeArrow.setAttribute( 'aria-hidden', 'true' );
			iframeArrow.classList.add( 'arrow' );

			// A back-button return from WooPay (or ?skip_woopay=true) must not
			// bounce the shopper straight back; the cookie extends the skip to
			// the whole session.
			searchParams = new window.URLSearchParams( window.location.search );
			isSkipWoopayCookieSet = shouldSkipWooPay();
			customerClickedBackButton =
				( typeof window.performance !== 'undefined' &&
					window.performance.getEntriesByType &&
					window.performance.getEntriesByType( 'navigation' )[ 0 ] &&
					window.performance.getEntriesByType( 'navigation' )[ 0 ]
						.type === 'back_forward' ) ||
				searchParams.get( 'skip_woopay' ) === 'true' ||
				isSkipWoopayCookieSet;

			if ( customerClickedBackButton && ! isSkipWoopayCookieSet ) {
				followingDay = new Date( Date.now() + 24 * 60 * 60 * 1000 );
				document.cookie =
					'skip_woopay=1; path=/; expires=' +
					followingDay.toUTCString();
			}

			// Tracks the iframe header state; the default must match the
			// platform's default.
			function getWindowSize() {
				if (
					( wooPayFullScreenModalBreakpoint <= window.innerWidth &&
						iframeHeaderValue ) ||
					( wooPayFullScreenModalBreakpoint > window.innerWidth &&
						! iframeHeaderValue )
				) {
					iframeHeaderValue = ! iframeHeaderValue;
					iframe.contentWindow.postMessage(
						{ action: 'setHeader', value: iframeHeaderValue },
						baseConfig.woopayHost
					);
				}

				// Prevent scrolling while the iframe is open.
				document.body.style.overflow = 'hidden';
			}

			// Positions the popover to the right of the input unless the window
			// is too narrow, in which case it sticks 50px from the right edge.
			function setPopoverPosition() {
				var anchorRect;
				var iframeRect;
				var topOffset;
				var scrollTop;

				if ( wooPayFullScreenModalBreakpoint > window.innerWidth ) {
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
					topOffset = 50;
					scrollTop =
						document.documentElement.scrollTop +
						woopayEmailInput.getBoundingClientRect().top -
						iframe.getBoundingClientRect().height / 2 -
						topOffset;
					window.scrollTo( { top: scrollTop } );
				}

				anchorRect = woopayEmailInput.getBoundingClientRect();
				iframeRect = iframe.getBoundingClientRect();

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
					iframeArrow.style.right = iframeRect.width + 50 + 'px';
				} else {
					iframe.style.left = anchorRect.right + 5 + 'px';
					iframe.style.right = '';
					iframeArrow.style.left = anchorRect.right - 10 + 'px';
					iframeArrow.style.right = '';
				}
			}

			iframe.addEventListener( 'load', function () {
				iframeHeaderValue = true;

				if ( baseConfig.isWoopayFirstPartyAuthEnabled ) {
					$.post( buildWooPayAjaxUrl( 'get_woopay_session' ), {
						_ajax_nonce: baseConfig.woopaySessionNonce || '',
						order_id: baseConfig.orderId || '',
						key: baseConfig.key || '',
						billing_email: baseConfig.billing_email || '',
						appearance: getWooPayEmailInputAppearance(),
					} ).done( function ( response ) {
						if ( response && response.data && response.data.session ) {
							iframe.contentWindow.postMessage(
								{ action: 'setSessionData', value: response },
								baseConfig.woopayHost
							);
						}
					} );
				}

				getWindowSize();
				window.addEventListener( 'resize', getWindowSize );

				setPopoverPosition();
				window.addEventListener( 'resize', setPopoverPosition );

				iframe.classList.add( 'open' );
			} );

			iframeWrapper.insertBefore( iframeArrow, null );
			iframeWrapper.insertBefore( iframe, null );

			errorMessage = document.createElement( 'div' );
			errorMessage.textContent = baseConfig.woopayUnavailableMessage || '';
			errorMessage.classList.add( 'wc-block-checkout__guest-checkout-notice' );

			function closeIframe( focus ) {
				window.removeEventListener( 'resize', getWindowSize );
				window.removeEventListener( 'resize', setPopoverPosition );

				iframeWrapper.remove();
				iframe.classList.remove( 'open' );

				if ( focus !== false ) {
					woopayEmailInput.focus();
				}

				document.body.style.overflow = '';
			}

			iframeWrapper.addEventListener( 'click', function () {
				closeIframe();
			} );

			function openIframe( email ) {
				var urlParams;
				var checkoutPermalink =
					window.wcSettings &&
					window.wcSettings.storePages &&
					window.wcSettings.storePages.checkout &&
					window.wcSettings.storePages.checkout.permalink;

				// Only one OTP iframe at a time.
				if ( document.querySelector( '.woopay-otp-iframe' ) ) {
					return;
				}

				urlParams = new window.URLSearchParams();
				urlParams.append( 'email', email );
				urlParams.append( 'testMode', !! baseConfig.testMode );
				urlParams.append(
					'needsHeader',
					wooPayFullScreenModalBreakpoint > window.innerWidth
				);
				urlParams.append( 'wcpayVersion', baseConfig.wcpayVersionNumber );
				urlParams.append( 'is_blocks', 'false' );
				urlParams.append(
					'source_url',
					checkoutPermalink || window.location.href
				);
				urlParams.append(
					'viewport',
					document.documentElement.clientWidth +
						'x' +
						document.documentElement.clientHeight
				);

				if ( tracksUserId ) {
					urlParams.append( 'tracksUserIdentity', tracksUserId );
				}

				iframe.src =
					baseConfig.woopayHost + '/otp/?' + urlParams.toString();

				parentDiv.insertBefore( iframeWrapper, null );

				setPopoverPosition();

				iframe.focus();
			}

			function showErrorMessage() {
				parentDiv.insertBefore( errorMessage, null );
			}

			document.addEventListener( 'keyup', function ( event ) {
				if ( event.key === 'Escape' ) {
					closeIframe();
				}
			} );

			// Placing the order before the lookup returns cancels the WooPay
			// request and closes the iframe.
			abortController = window.AbortController
				? new window.AbortController()
				: null;

			if ( abortController ) {
				abortController.signal.addEventListener( 'abort', function () {
					spinner.remove();
					closeIframe( false );
				} );

				checkoutForm = document.querySelector( 'form[name="checkout"]' );
				if ( checkoutForm ) {
					checkoutForm.addEventListener( 'submit', function () {
						abortController.abort();
					} );
				}
			}

			function dispatchUserExistEvent( userExist ) {
				window.dispatchEvent(
					new window.CustomEvent( 'woopayUserCheck', {
						detail: { isRegisteredUser: userExist },
					} )
				);
			}

			function woopayLocateUser( email, shouldOpenIframe ) {
				parentDiv.insertBefore( spinner, woopayEmailInput );

				if ( parentDiv.contains( errorMessage ) ) {
					parentDiv.removeChild( errorMessage );
				}

				recordUserEvent( 'checkout_email_address_woopay_check' );

				new Promise( function ( resolve, reject ) {
					$.post( buildWooPayAjaxUrl( 'get_woopay_signature' ), {
						_ajax_nonce: baseConfig.woopaySignatureNonce || '',
					} )
						.done( resolve )
						.fail( reject );
				} )
					.then( function ( response ) {
						if ( response && response.success ) {
							return response.data;
						}

						throw new Error( 'Request for signature failed.' );
					} )
					.then( function ( data ) {
						if ( data && data.signature ) {
							return data.signature;
						}

						throw new Error( 'Signature not found.' );
					} )
					.then( function ( signature ) {
						var emailExistsQuery = new window.URLSearchParams();

						emailExistsQuery.append( 'email', email );
						emailExistsQuery.append(
							'test_mode',
							!! baseConfig.testMode
						);
						emailExistsQuery.append(
							'wcpay_version',
							baseConfig.wcpayVersionNumber
						);
						emailExistsQuery.append(
							'blog_id',
							baseConfig.woopayMerchantId
						);
						emailExistsQuery.append( 'request_signature', signature );

						return window.fetch(
							baseConfig.woopayHost +
								'/wp-json/platform-checkout/v1/user/exists?' +
								emailExistsQuery.toString(),
							abortController ? { signal: abortController.signal } : {}
						);
					} )
					.then( function ( response ) {
						if ( response.status !== 200 ) {
							showErrorMessage();
						}

						return response.json();
					} )
					.then( function ( data ) {
						dispatchUserExistEvent( data[ 'user-exists' ] );

						if ( data[ 'user-exists' ] ) {
							if ( shouldOpenIframe !== false ) {
								openIframe( email );
							}
						} else if ( data.code !== 'rest_invalid_param' ) {
							recordUserEvent( 'checkout_woopay_save_my_info_offered' );

							if (
								window.woopayCheckout &&
								window.woopayCheckout.PRE_CHECK_SAVE_MY_INFO
							) {
								recordUserEvent( 'checkout_save_my_info_click', {
									status: 'checked',
								} );
							}
						}
					} )
					.catch( function ( err ) {
						// Only surface connection errors reaching WooPay.
						if (
							! baseConfig.woopayIsCountryAvailable ||
							err.name !== 'TypeError'
						) {
							return;
						}

						showErrorMessage();
					} )
					.then( function () {
						spinner.remove();
					} );
			}

			woopayEmailInput.addEventListener( 'input', function ( e ) {
				var email = e.currentTarget.value;

				window.clearTimeout( timer );
				spinner.remove();

				timer = window.setTimeout( function () {
					// Always show the checkbox until the email belongs to a
					// WooPay user.
					dispatchUserExistEvent( false );

					if ( validateWooPayEmail( email ) ) {
						woopayLocateUser( email );
					}
				}, wooPayEmailInputWaitTime );
			} );

			window.addEventListener( 'message', function ( e ) {
				var promise;
				var woopayOrigin = getWooPayHostOrigin();

				// Fail closed: no resolvable WooPay origin, no trusted sender.
				if ( ! woopayOrigin || e.origin !== woopayOrigin ) {
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
					case 'redirect_to_woopay':
						promise = initWooPayFromEmailInput(
							woopayEmailInput.value,
							e.data.platformCheckoutUserSession
						);

						// WooPay's <Login> re-renders and sends the message
						// twice; the second init is skipped.
						if ( ! promise ) {
							break;
						}

						promise
							.then( function ( response ) {
								// The iframe was closed meanwhile.
								if (
									! document.querySelector( '.woopay-otp-iframe' )
								) {
									return;
								}
								if ( response && response.result === 'success' ) {
									deleteSkipWooPayCookie();
									navigate( response.url );
								} else {
									showErrorMessage();
									closeIframe( false );
								}
							} )
							.catch( function () {
								showErrorMessage();
								closeIframe( false );
							} );
						break;
					case 'otp_validation_failed':
						break;
					case 'close_modal':
						closeIframe();
						break;
					case 'iframe_height':
						if ( e.data.height > 300 ) {
							if (
								wooPayFullScreenModalBreakpoint <= window.innerWidth
							) {
								// Attach the iframe to the right of the input.
								iframe.style.height = e.data.height + 'px';
								iframe.style.top =
									Math.floor(
										woopayEmailInput.getBoundingClientRect()
											.top -
											e.data.height / 2
									) + 'px';
								iframeArrow.style.top =
									Math.floor(
										woopayEmailInput.getBoundingClientRect()
											.top +
											woopayEmailInput.getBoundingClientRect()
												.height /
												2 -
											parseFloat(
												window.getComputedStyle(
													iframeArrow
												)[ 'border-right-width' ]
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

			window.addEventListener( 'pageshow', function ( event ) {
				if ( event.persisted ) {
					// Safari needs the iframe closed on bfcache restore.
					closeIframe( false );
				}
			} );

			if (
				woopayEmailInput.value &&
				validateWooPayEmail( woopayEmailInput.value )
			) {
				woopayLocateUser( woopayEmailInput.value, false );
			}

			if ( customerClickedBackButton ) {
				// The shopper returned via the back button: they exist. Wait for
				// the window to settle before announcing it.
				window.setTimeout( function () {
					dispatchUserExistEvent( true );
				}, 2000 );

				recordUserEvent( 'woopay_skipped', {} );

				searchParams.delete( 'skip_woopay' );

				pathname = window.location.pathname;
				if ( searchParams.toString() !== '' ) {
					pathname += '?' + searchParams.toString();
				}

				window.history.replaceState( null, null, pathname );

				// Safari needs the iframe closed here too.
				closeIframe( false );
			}
		}
	}

	$( function () {
		enqueueFraudScripts();
		computeDeviceFingerprint();
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
		togglePaymentMethodsForBillingCountry();
		initializeStripeElement();
		swapPaymentMethodIconsForTheme();
	} );

	$( document.body ).on( 'payment_method_selected', function () {
		togglePaymentMethodsForBillingCountry();
		initializeStripeElement();
		swapPaymentMethodIconsForTheme();
	} );

	getKnownGatewayIds().forEach( function ( paymentGatewayId ) {
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
