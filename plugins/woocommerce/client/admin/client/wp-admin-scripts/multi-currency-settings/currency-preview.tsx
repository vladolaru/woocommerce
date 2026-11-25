/**
 * External dependencies
 */
import { TextControlWithAffixes } from '@woocommerce/components';
import CurrencyFactory, { type SymbolPosition } from '@woocommerce/currency';
import { useState } from '@wordpress/element';
import type { ComponentType } from 'react';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { MultiCurrencyCurrency } from './types';

// The component is untyped JavaScript; these are the props this preview passes (client currency-preview.js:54-78).
const AffixedTextControl = TextControlWithAffixes as unknown as ComponentType< {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	prefix?: string;
	suffix?: string;
	disabled?: boolean;
} >;

interface CurrencyPreviewProps {
	storeCurrency: MultiCurrencyCurrency;
	targetCurrency: MultiCurrencyCurrency;
	// The manual rate being edited, or null to use the target currency's fetched rate.
	manualRate: string | null;
	rounding: string;
	charm: string;
}

// Client 11.1.0 single-currency/currency-preview.js:27-47: convert, round up to the rounding step, add the charm, format in the target currency.
const getConvertedPrice = (
	price: string,
	{ targetCurrency, manualRate, rounding, charm }: CurrencyPreviewProps
): string => {
	const amount = parseFloat( price.replace( /,/g, '.' ) );
	const converted =
		amount * parseFloat( String( manualRate || targetCurrency.rate ) );
	const roundingStep = parseFloat( rounding );
	const rounded = roundingStep
		? Math.ceil( converted / roundingStep ) * roundingStep
		: converted;
	const charmed = rounded + parseFloat( charm );

	if ( isNaN( charmed ) ) {
		return __( 'Please enter a valid number', 'woocommerce' );
	}

	return CurrencyFactory( {
		code: targetCurrency.code,
		symbol: targetCurrency.symbol,
		symbolPosition: targetCurrency.symbol_position as SymbolPosition,
		thousandSeparator: targetCurrency.thousand_separator ?? ',',
		decimalSeparator: targetCurrency.decimal_separator ?? '.',
		precision:
			targetCurrency.num_decimals ??
			( targetCurrency.is_zero_decimal ? 0 : 2 ),
	} ).formatAmount( charmed );
};

export function CurrencyPreview( props: CurrencyPreviewProps ) {
	const { storeCurrency, targetCurrency } = props;
	const [ price, setPrice ] = useState( '20' );
	// Client 11.1.0 currency-preview.js:53-69: the symbol leads only for the 'left' position.
	const isSymbolOnLeft = storeCurrency.symbol_position === 'left';

	return (
		<div className="woocommerce-multi-currency-settings__preview">
			<h3>{ __( 'Preview', 'woocommerce' ) }</h3>
			<p>
				{ sprintf(
					/* translators: 1: Store currency name, 2: Target currency name. */
					__(
						'Enter a price in your default currency (%1$s) to see it converted to %2$s using the exchange rate and formatting rules above.',
						'woocommerce'
					),
					storeCurrency.name,
					targetCurrency.name
				) }
			</p>
			<div className="woocommerce-multi-currency-settings__preview-fields">
				<AffixedTextControl
					label={ storeCurrency.name }
					prefix={ isSymbolOnLeft ? storeCurrency.symbol : undefined }
					suffix={ isSymbolOnLeft ? undefined : storeCurrency.symbol }
					value={ price }
					onChange={ setPrice }
				/>
				<AffixedTextControl
					label={ targetCurrency.name }
					value={ getConvertedPrice( price, props ) }
					onChange={ () => null }
					disabled
				/>
			</div>
		</div>
	);
}
