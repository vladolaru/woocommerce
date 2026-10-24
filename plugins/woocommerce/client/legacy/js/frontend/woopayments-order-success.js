/* global wc_woopayments_order_success_params */
( function () {
	'use strict';

	var appearanceUtils = window.wcpayAppearance;

	function hasVisibleBackground( value ) {
		var color = appearanceUtils.parseColor( value );
		return color && color.a > 0;
	}

	function effectiveBackgroundColor( element ) {
		var current = element;
		var color;

		while ( current ) {
			color = window.getComputedStyle( current ).backgroundColor;
			if ( hasVisibleBackground( color ) ) {
				return color;
			}
			current = current.parentElement;
		}

		return 'rgb(255, 255, 255)';
	}

	function colorWithAlpha( value, alpha ) {
		var color = appearanceUtils.parseColor( value );
		if ( ! color ) {
			return value;
		}

		return (
			'rgba(' +
			[ color.r, color.g, color.b ].map( Math.round ).join( ', ' ) +
			', ' +
			alpha +
			')'
		);
	}

	function configureColors( container ) {
		var textColor = window.getComputedStyle( container ).color;
		var parentBackground = effectiveBackgroundColor(
			container.parentElement
		);

		container.style.setProperty(
			'--woopayments-multibanco-text-color',
			textColor
		);
		container.style.setProperty(
			'--woopayments-multibanco-bg-color',
			colorWithAlpha( textColor, 0.06 )
		);
		container.style.setProperty(
			'--woopayments-multibanco-border-color',
			colorWithAlpha( textColor, 0.16 )
		);
		container.style.setProperty(
			'--woopayments-multibanco-card-bg-color',
			parentBackground
		);
	}

	function swapDarkIcons() {
		document.querySelectorAll( 'img[data-dark-src]' ).forEach( function ( image ) {
			var wrapper = image.closest(
				'.wc-payment-gateway-method-logo-wrapper'
			);
			var background = appearanceUtils.parseColor(
				effectiveBackgroundColor( wrapper || image.parentElement )
			);
			var luminance;

			if ( ! background ) {
				return;
			}

			luminance =
				( 0.299 * background.r +
					0.587 * background.g +
					0.114 * background.b ) /
				255;
			if ( luminance < 0.5 ) {
				image.src = image.dataset.darkSrc;
			}
		} );
	}

	function copied( button, status ) {
		button.classList.add( 'copied' );
		status.textContent = wc_woopayments_order_success_params.copied;
		window.setTimeout( function () {
			button.classList.remove( 'copied' );
		}, 2000 );
	}

	function copyFailed( value, status ) {
		status.textContent =
			wc_woopayments_order_success_params.copyFailed;
		window.prompt(
			wc_woopayments_order_success_params.copyFailed,
			value
		);
	}

	function copyValue( button, status ) {
		var value = button.dataset.copyValue;
		var clipboard = window.navigator.clipboard;

		if ( ! value ) {
			return;
		}

		if ( clipboard && typeof clipboard.writeText === 'function' ) {
			clipboard
				.writeText( value )
				.then( function () {
					copied( button, status );
				} )
				.catch( function () {
					copyFailed( value, status );
				} );
			return;
		}

		copyFailed( value, status );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById(
			'wc-payment-gateway-multibanco-instructions-container'
		);
		var status;

		swapDarkIcons();

		if ( ! container ) {
			return;
		}

		configureColors( container );
		status = container.querySelector(
			'.woocommerce-woopayments-copy-status'
		);

		container.querySelectorAll( '.copy-btn' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				copyValue( button, status );
			} );
		} );

		container
			.querySelector( '.print-btn' )
			.addEventListener( 'click', function () {
				window.print();
			} );
	} );
} )();
