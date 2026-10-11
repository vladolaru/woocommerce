/* global jQuery */

( function ( $ ) {
	'use strict';

	function renderOptions( $select, tokens, selectedValue, labels ) {
		labels = labels || {};
		$select.empty();

		if ( ! tokens.length ) {
			$select
				.append(
					$( '<option />', {
						value: '0',
						text: labels.noPaymentMethods || '',
					} )
				)
				.prop( 'disabled', true );
			return;
		}

		$select.prop( 'disabled', false );
		tokens.forEach( function ( token ) {
			var tokenId = String( token.tokenId );
			$select.append(
				$( '<option />', {
					value: tokenId,
					text: token.displayName,
					selected:
						tokenId === String( selectedValue || '' ) ||
						( ! selectedValue && token.isDefault ),
				} )
			);
		} );
	}

	function refreshTokens( container, userId ) {
		var $container = $( container );
		var data = $container.data( 'wcpay-pm-selector' );
		var $select = $container.find( 'select' );

		if ( ! data || ! data.ajaxUrl || ! data.nonce || ! userId ) {
			return;
		}

		$select.prop( 'disabled', true );
		$.post( data.ajaxUrl, {
			action: 'wcpay_get_user_payment_tokens',
			nonce: data.nonce,
			user_id: userId,
			gateway_id: data.gatewayId,
		} )
			.done( function ( response ) {
				var tokens =
					response && response.success && response.data
						? response.data.tokens || []
						: [];
				renderOptions( $select, tokens, data.value, {
					noPaymentMethods: data.noPaymentMethodsLabel,
				} );
				data.tokens = tokens;
				data.userId = userId;
				$container.data( 'wcpay-pm-selector', data );
			} )
			.fail( function () {
				renderOptions( $select, [], data.value, {
					noPaymentMethods: data.noPaymentMethodsLabel,
				} );
			} );
	}

	$( function () {
		var $customer = $( '#customer_user' );
		var $selectors = $( '.wcpay-subscription-payment-method' );

		$selectors.each( function () {
			var data = $( this ).data( 'wcpay-pm-selector' );
			if ( data && data.tokens ) {
				renderOptions( $( this ).find( 'select' ), data.tokens, data.value, {
					noPaymentMethods: data.noPaymentMethodsLabel,
				} );
			}
		} );

		$customer.on( 'change', function () {
			var userId = $( this ).val();
			$selectors.each( function () {
				refreshTokens( this, userId );
			} );
		} );
	} );
} )( jQuery );
