import merge from 'deepmerge';
import { loadScript } from '@paypal/paypal-js';
import { keysToCamelCase } from '../Helper/Utils';
import widgetBuilder from './WidgetBuilder';
import {
	handleShippingOptionsChange,
	handleShippingAddressChange,
} from '../Helper/ShippingHandler.js';
import { PaymentContext } from '../Helper/CheckoutMethodState';

class Renderer {
	constructor( defaultSettings, onSmartButtonClick, onSmartButtonsInit ) {
		this.defaultSettings = defaultSettings;
		this.onSmartButtonClick = onSmartButtonClick;
		this.onSmartButtonsInit = onSmartButtonsInit;

		this.buttonsOptions = {};
		this.onButtonsInitListeners = {};

		this.renderedSources = new Set();

		this.reloadEventName = 'ppcp-reload-buttons';
	}

	/**
	 * Determine is PayPal smart buttons are used by inspecting the existing plugin configuration:
	 * If the url-param "components" contains a "buttons" element, smart buttons are enabled.
	 *
	 * @return {boolean} True, if smart buttons are present on the page.
	 */
	get useSmartButtons() {
		if ( PaymentContext.Preview === this.defaultSettings?.context ) {
			return true;
		}

		const components = this.defaultSettings?.url_params?.components || '';

		return components.split( ',' ).includes( 'buttons' );
	}

	render( contextConfig, settingsOverride = {} ) {
		const settings = merge( this.defaultSettings, settingsOverride );

		if ( this.useSmartButtons ) {
			this.renderButtons(
				settings.button.wrapper,
				settings.button.style,
				contextConfig
			);
		}
	}

	renderButtons( wrapper, style, contextConfig, fundingSource = null ) {
		if (
			! document.querySelector( wrapper ) ||
			this.isAlreadyRendered( wrapper, fundingSource )
		) {
			// Try to render registered buttons again in case they were removed from the DOM by an external source.
			widgetBuilder.renderButtons( [ wrapper, fundingSource ] );
			return;
		}

		if ( fundingSource ) {
			contextConfig.fundingSource = fundingSource;
		}

		let venmoButtonClicked = false;

		const buttonsOptions = () => {
			const options = {
				style,
				...contextConfig,
				onClick: ( data, actions ) => {
                    let result;
					if ( this.onSmartButtonClick ) {
						result = this.onSmartButtonClick( data, actions );
					}

					venmoButtonClicked = data.fundingSource === 'venmo';
                    return result;
				},
				onInit: ( data, actions ) => {
					if ( this.onSmartButtonsInit ) {
						this.onSmartButtonsInit( data, actions );
					}
					this.handleOnButtonsInit( wrapper, data, actions );
				},
			};

			// Check the condition and add the handler if needed
			if (
				this.shouldEnableShippingCallback() &&
				! this.defaultSettings.server_side_shipping_callback.enabled
			) {
				options.onShippingOptionsChange = ( data, actions ) => {
					const shippingOptionsChange =
						! this.isVenmoButtonClickedWhenVaultingIsEnabled(
							venmoButtonClicked
						)
							? handleShippingOptionsChange(
									data,
									actions,
									this.defaultSettings
							  )
							: null;

					return shippingOptionsChange;
				};
				options.onShippingAddressChange = ( data, actions ) => {
					const shippingAddressChange =
						! this.isVenmoButtonClickedWhenVaultingIsEnabled(
							venmoButtonClicked
						)
							? handleShippingAddressChange(
									data,
									actions,
									this.defaultSettings
							  )
							: null;

					return shippingAddressChange;
				};
			}

			if ( this.shouldEnableAppSwitch() ) {
				options.appSwitchWhenAvailable = true;
			}

			return options;
		};

		jQuery( document )
			.off( this.reloadEventName, wrapper )
			.on(
				this.reloadEventName,
				wrapper,
				( event, settingsOverride = {}, triggeredFundingSource ) => {
					// Only accept events from the matching funding source
					if (
						fundingSource &&
						triggeredFundingSource &&
						triggeredFundingSource !== fundingSource
					) {
						return;
					}

					const settings = merge(
						this.defaultSettings,
						settingsOverride
					);
					let scriptOptions = keysToCamelCase( settings.url_params );
					scriptOptions = merge(
						scriptOptions,
						settings.script_attributes
					);

					loadScript( scriptOptions ).then( ( paypal ) => {
						widgetBuilder.setPaypal( paypal );
						widgetBuilder.registerButtons(
							[ wrapper, fundingSource ],
							buttonsOptions()
						);
						widgetBuilder.renderAll();
					} );
				}
			);

		this.renderedSources.add(
			wrapper + ( fundingSource ? fundingSource : '' )
		);

		if ( window.paypal?.Buttons ) {
			widgetBuilder.registerButtons(
				[ wrapper, fundingSource ],
				buttonsOptions()
			);
			widgetBuilder.renderButtons( [ wrapper, fundingSource ] );
		}
	}

	isVenmoButtonClickedWhenVaultingIsEnabled = ( venmoButtonClicked ) => {
		return venmoButtonClicked && this.defaultSettings.vaultingEnabled;
	};

	shouldEnableShippingCallback = () => {
		const needShipping =
			this.defaultSettings.needShipping ||
			this.defaultSettings.context === 'product';

		return (
			this.defaultSettings.should_handle_shipping_in_paypal &&
			needShipping
		);
	};

	shouldEnableAppSwitch = () => {
		// AppSwitch should only be enabled in Pay Now flows with server side shipping callback.
		return (
			this.defaultSettings.appswitch.enabled &&
			! this.defaultSettings.final_review_enabled &&
			this.defaultSettings.server_side_shipping_callback.enabled
		);
	};

	isAlreadyRendered( wrapper, fundingSource ) {
		return this.renderedSources.has( wrapper + ( fundingSource ?? '' ) );
	}

	/**
	 * Clears the rendered state for a wrapper (across all funding sources) and empties
	 * its DOM so a subsequent render() rebuilds the buttons with a fresh configuration.
	 * Used when the cart changes the button flow, e.g. a coupon turning a subscription
	 * cart into a $0 total that must switch to the save-without-purchase flow.
	 *
	 * @param {string} wrapper The wrapper selector.
	 */
	resetRenderedButtons( wrapper ) {
		for ( const key of Array.from( this.renderedSources ) ) {
			if ( key === wrapper || key.startsWith( wrapper ) ) {
				this.renderedSources.delete( key );
			}
		}
		widgetBuilder.removeButtons( wrapper );
		const element = document.querySelector( wrapper );
		if ( element ) {
			element.innerHTML = '';
		}
	}

	onButtonsInit( wrapper, handler, reset ) {
		this.onButtonsInitListeners[ wrapper ] = reset
			? []
			: this.onButtonsInitListeners[ wrapper ] || [];

		this.onButtonsInitListeners[ wrapper ].push( handler );
	}

	handleOnButtonsInit( wrapper, data, actions ) {
		this.buttonsOptions[ wrapper ] = {
			data,
			actions,
		};

		if ( this.onButtonsInitListeners[ wrapper ] ) {
			for ( const handler of this.onButtonsInitListeners[ wrapper ] ) {
				if ( typeof handler !== 'function' ) {
					continue;
				}

				handler( { wrapper, ...this.buttonsOptions[ wrapper ] } );
			}
		}
	}

	disableSmartButtons( wrapper ) {
		if ( ! this.buttonsOptions[ wrapper ] ) {
			return;
		}

		try {
			this.buttonsOptions[ wrapper ].actions.disable();
		} catch ( err ) {
			console.warn( 'Failed to disable buttons: ' + err );
		}
	}

	enableSmartButtons( wrapper ) {
		if ( ! this.buttonsOptions[ wrapper ] ) {
			return;
		}

		try {
			this.buttonsOptions[ wrapper ].actions.enable();
		} catch ( err ) {
			console.warn( 'Failed to enable buttons: ' + err );
		}
	}
}

export default Renderer;
