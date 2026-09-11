/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	AutomaticRatesDescriptor,
	CurrencySettingsResponse,
	CurrencySettingsState,
	ExchangeRateType,
	MultiCurrencyCurrency,
} from './types';

const REST_BASE = '/wc/v3/payments/multi-currency';

const decimalCurrencyRoundingOptions = {
	'0': __( 'None', 'woocommerce' ),
	'0.25': '0.25',
	'0.50': '0.50',
	'1.00': '1.00',
	'5.00': '5.00',
	'10.00': '10.00',
};

const zeroDecimalCurrencyRoundingOptions = {
	'1': '1',
	'10': '10',
	'25': '25',
	'50': '50',
	'100': '100',
	'500': '500',
	'1000': '1000',
};

const decimalCurrencyCharmOptions = {
	'0.00': __( 'None', 'woocommerce' ),
	'-0.01': '-0.01',
	'-0.05': '-0.05',
};

const zeroDecimalCurrencyCharmOptions = {
	'0.00': __( 'None', 'woocommerce' ),
	'-1': '-1',
	'-5': '-5',
	'-10': '-10',
	'-20': '-20',
	'-25': '-25',
	'-50': '-50',
	'-100': '-100',
};

interface CurrencySettingsModalProps {
	currency: MultiCurrencyCurrency;
	defaultCurrency: MultiCurrencyCurrency & { rate: number };
	automaticRates: AutomaticRatesDescriptor;
	onClose: () => void;
	onSaved: ( currencyCode: string, manualRate: number | null ) => void;
}

const optionEntries = ( options: Record< string, string > ) =>
	Object.entries( options ).map( ( [ value, label ] ) => ( {
		value,
		label,
	} ) );

const normalizeCurrencySettings = (
	response: CurrencySettingsResponse,
	currency: MultiCurrencyCurrency,
	automaticRates: AutomaticRatesDescriptor
): CurrencySettingsState => ( {
	exchangeRateType:
		response.exchange_rate_type === 'manual' || automaticRates.source === null
			? 'manual'
			: 'automatic',
	manualRate:
		response.manual_rate !== null
			? String( response.manual_rate )
			: currency.rate === null
				? ''
				: String( currency.rate ),
	priceRounding: String(
		response.price_rounding ?? ( currency.is_zero_decimal ? '100' : '1.00' )
	),
	priceCharm: String( response.price_charm ?? '0.00' ),
} );

export function CurrencySettingsModal( {
	currency,
	defaultCurrency,
	automaticRates,
	onClose,
	onSaved,
}: CurrencySettingsModalProps ) {
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const [ settings, setSettings ] = useState< CurrencySettingsState | null >(
		null
	);
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );

	useEffect( () => {
		let isMounted = true;

		apiFetch< CurrencySettingsResponse >( {
			path: `${ REST_BASE }/currencies/${ currency.code }`,
		} )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setSettings(
					normalizeCurrencySettings(
						response,
						currency,
						automaticRates
					)
				);
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				createErrorNotice(
					__( 'Error loading currency settings.', 'woocommerce' )
				);
			} )
			.finally( () => {
				if ( isMounted ) {
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ automaticRates, createErrorNotice, currency ] );

	const roundingOptions = useMemo(
		() =>
			optionEntries(
				currency.is_zero_decimal
					? zeroDecimalCurrencyRoundingOptions
					: decimalCurrencyRoundingOptions
			),
		[ currency.is_zero_decimal ]
	);
	const charmOptions = useMemo(
		() =>
			optionEntries(
				currency.is_zero_decimal
					? zeroDecimalCurrencyCharmOptions
					: decimalCurrencyCharmOptions
			),
		[ currency.is_zero_decimal ]
	);

	const updateSettings = ( values: Partial< CurrencySettingsState > ) => {
		setSettings( ( currentSettings ) =>
			currentSettings
				? {
						...currentSettings,
						...values,
				  }
				: currentSettings
		);
	};
	const hasAutomaticRateSource = automaticRates.source !== null;
	const hasValidManualRate =
		settings !== null &&
		Number.isFinite( Number( settings.manualRate ) ) &&
		Number( settings.manualRate ) > 0;
	const shouldRequireManualRate =
		settings?.exchangeRateType === 'manual' && ! hasValidManualRate;

	const saveSettings = async () => {
		if ( ! settings || isSaving || shouldRequireManualRate ) {
			return;
		}

		setIsSaving( true );

		const manualRate =
			settings.exchangeRateType === 'manual'
				? Number( settings.manualRate )
				: null;
		const data: {
			exchange_rate_type: ExchangeRateType;
			price_rounding: number;
			price_charm: number;
			manual_rate?: number;
		} = {
			exchange_rate_type: settings.exchangeRateType,
			price_rounding: Number( settings.priceRounding ),
			price_charm: Number( settings.priceCharm ),
		};

		if ( manualRate !== null ) {
			data.manual_rate = manualRate;
		}

		try {
			await apiFetch< CurrencySettingsResponse >( {
				path: `${ REST_BASE }/currencies/${ currency.code }`,
				method: 'POST',
				data,
			} );

			createSuccessNotice(
				__( 'Currency settings saved.', 'woocommerce' )
			);
			onSaved( currency.code, manualRate );
			onClose();
		} catch ( error ) {
			createErrorNotice(
				__( 'Error saving currency settings.', 'woocommerce' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const modalTitle = sprintf(
		/* translators: %s: Currency name. */
		__( 'Manage %s settings', 'woocommerce' ),
		currency.name
	);

	return (
		<Modal title={ modalTitle } onRequestClose={ onClose }>
			{ isLoading && (
				<p aria-live="polite">
					<Spinner />
					{ __( 'Loading currency settings…', 'woocommerce' ) }
				</p>
			) }

			{ ! isLoading && ! settings && (
				<p role="alert">
					{ __( 'Unable to load currency settings.', 'woocommerce' ) }
				</p>
			) }

			{ ! isLoading && settings && (
				<div className="woocommerce-multi-currency-settings__currency-settings">
					{ hasAutomaticRateSource ? (
						<RadioControl
							label={ __( 'Exchange rate', 'woocommerce' ) }
							selected={ settings.exchangeRateType }
							options={ [
								{
									label: __(
										'Fetch rates automatically',
										'woocommerce'
										),
										value: 'automatic',
										description:
											! automaticRates.available &&
											automaticRates.source === 'woopayments'
												? __(
														'WooPayments automatic rates are temporarily unavailable. You can use manual rates.',
														'woocommerce'
												  )
												: currency.rate === null
													? __(
															'An automatic rate is not available yet.',
															'woocommerce'
													  )
													: sprintf(
														/* translators: 1: Default currency code, 2: Exchange rate, 3: Target currency code. */
														__(
															'Current rate: 1 %1$s = %2$s %3$s',
															'woocommerce'
														),
														defaultCurrency.code,
														String( currency.rate ),
														currency.code
													),
								},
								{
									label: __( 'Manual', 'woocommerce' ),
									value: 'manual',
								},
							] }
							onChange={ ( value ) =>
								updateSettings( {
									exchangeRateType: value as ExchangeRateType,
								} )
							}
						/>
					) : (
						<fieldset className="components-radio-control">
							<legend className="components-base-control__label">
								{ __( 'Exchange rate', 'woocommerce' ) }
							</legend>
							<div className="components-radio-control__group-wrapper">
								<div className="components-radio-control__option">
									<input
										id={ `woocommerce-multi-currency-settings-${ currency.code }-automatic` }
										className="components-radio-control__input"
										type="radio"
										name={ `woocommerce-multi-currency-settings-${ currency.code }-exchange-rate` }
										value="automatic"
										disabled
										aria-describedby={ `woocommerce-multi-currency-settings-${ currency.code }-automatic-description` }
									/>
									<label
										className="components-radio-control__label"
										htmlFor={ `woocommerce-multi-currency-settings-${ currency.code }-automatic` }
									>
										{ __( 'Fetch rates automatically', 'woocommerce' ) }
									</label>
									<p
										id={ `woocommerce-multi-currency-settings-${ currency.code }-automatic-description` }
										className="components-radio-control__option-description"
									>
										{ __(
											'No automatic-rate provider is available.',
											'woocommerce'
										) }
									</p>
								</div>
								<div className="components-radio-control__option">
									<input
										id={ `woocommerce-multi-currency-settings-${ currency.code }-manual` }
										className="components-radio-control__input"
										type="radio"
										name={ `woocommerce-multi-currency-settings-${ currency.code }-exchange-rate` }
										value="manual"
										checked
										onChange={ () =>
											updateSettings( { exchangeRateType: 'manual' } )
										}
									/>
									<label
										className="components-radio-control__label"
										htmlFor={ `woocommerce-multi-currency-settings-${ currency.code }-manual` }
									>
										{ __( 'Manual', 'woocommerce' ) }
									</label>
								</div>
							</div>
						</fieldset>
					) }
					{ settings.exchangeRateType === 'manual' && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Manual rate', 'woocommerce' ) }
							value={ settings.manualRate }
							help={ __(
								'Enter a positive exchange rate.',
								'woocommerce'
							) }
							aria-invalid={ shouldRequireManualRate }
							onChange={ ( value ) =>
								updateSettings( {
									manualRate: value.replace( /,/g, '.' ),
								} )
							}
						/>
					) }
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Price rounding', 'woocommerce' ) }
						value={ settings.priceRounding }
						options={ roundingOptions }
						onChange={ ( value ) =>
							updateSettings( { priceRounding: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Charm pricing', 'woocommerce' ) }
						value={ settings.priceCharm }
						options={ charmOptions }
						onChange={ ( value ) =>
							updateSettings( { priceCharm: value } )
						}
					/>
					<div className="woocommerce-multi-currency-settings__modal-actions">
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ isSaving }
							disabled={ isSaving || shouldRequireManualRate }
							accessibleWhenDisabled
							onClick={ saveSettings }
						>
							{ __( 'Save changes', 'woocommerce' ) }
						</Button>
					</div>
				</div>
			) }
		</Modal>
	);
}
