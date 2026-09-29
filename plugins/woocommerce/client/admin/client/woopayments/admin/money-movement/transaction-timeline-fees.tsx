/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsTimelineEvent } from './types';
import { formatAmount, formatExplicitCurrency } from './utils';
import { getWooPaymentsAmountFromMinorUnits } from '../../currency';

// Ports the captured-event body of client 11.1.0: map-events.js (the legacy `fee_rates` path)
// and envelope/compose.js with fee-breakdown-label-map.ts (the `fee_breakdown_v1` path).

type TimelineRecord = Record< string, unknown >;

export const getRecord = ( value: unknown ): TimelineRecord | undefined =>
	value && typeof value === 'object' && ! Array.isArray( value )
		? ( value as TimelineRecord )
		: undefined;

export const getString = (
	record: TimelineRecord | undefined,
	key: string
): string | undefined => {
	const value = record?.[ key ];

	return typeof value === 'string' && value ? value : undefined;
};

export const getNumber = (
	record: TimelineRecord | undefined,
	key: string
): number | undefined => {
	const value = record?.[ key ];

	return typeof value === 'number' && Number.isFinite( value )
		? value
		: undefined;
};

/**
 * Formats a minor-unit amount, printing zero without a minus sign like the client's formatCurrency.
 *
 * @param amount   Amount in minor units.
 * @param currency Currency code.
 */
export const formatMoney = ( amount: number, currency?: string ) =>
	formatAmount( amount === 0 ? 0 : amount, currency || 'usd' );

/**
 * Formats a minor-unit amount like formatMoney(), with the currency code when the client's `formatExplicitCurrency()` adds it.
 *
 * @param amount     Amount in minor units.
 * @param currency   Currency code.
 * @param skipSymbol Whether to trim off the currency symbol.
 */
export const formatExplicitMoney = (
	amount: number,
	currency?: string,
	skipSymbol = false
) =>
	formatExplicitCurrency(
		amount === 0 ? 0 : amount,
		currency || 'usd',
		skipSymbol
	);

const getDisplaySymbol = ( currency: string ) => {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currency.toUpperCase(),
		} )
			.formatToParts( 0 )
			.find( ( part ) => part.type === 'currency' )?.value;
	} catch {
		return undefined;
	}
};

// Client hasSameSymbol(), compared on the symbol formatAmount() actually prints.
const hasSameSymbol = ( first?: string, second?: string ) => {
	if ( ! first || ! second ) {
		return false;
	}

	if ( first.toUpperCase() === second.toUpperCase() ) {
		return false;
	}

	const symbol = getDisplaySymbol( first );

	return !! symbol && symbol === getDisplaySymbol( second );
};

// Client formatFee(): a rate fraction as a percentage with up to three decimals.
const formatFeePercentage = ( rate: number ) =>
	Number( ( rate * 100 ).toFixed( 3 ) );

/**
 * Formats a currency conversion line like the client's formatFX(), for example `€1.00 → 1.13467 USD: $12.47`.
 *
 * @param fromCurrency Source currency code.
 * @param fromAmount   Source amount in minor units.
 * @param toCurrency   Target currency code.
 * @param toAmount     Target amount in minor units.
 */
export const formatFx = (
	fromCurrency?: string,
	fromAmount?: number,
	toCurrency?: string,
	toAmount?: number
) => {
	if (
		! fromCurrency ||
		! toCurrency ||
		fromAmount === undefined ||
		toAmount === undefined ||
		fromAmount === 0
	) {
		return undefined;
	}

	const toMajor = getWooPaymentsAmountFromMinorUnits;
	const rate = Math.abs(
		toMajor( toAmount, toCurrency ) / toMajor( fromAmount, fromCurrency )
	);
	const unit = toMajor( 100, fromCurrency ) === 1 ? 100 : 1;
	const formattedRate = rate
		.toFixed( rate < 1 ? 6 : 5 )
		.replace( /\.?0+$/, '' );

	// Client 11.1.0 `multi-currency/client/utils/currency/index.js:260-276` `formatFX()`.
	return `${ formatExplicitMoney(
		unit,
		fromCurrency,
		true
	) } → ${ formattedRate } ${ toCurrency.toUpperCase() }: ${ formatExplicitMoney(
		Math.abs( toAmount ),
		toCurrency
	) }`;
};

export const getTransactionDetails = ( event: WooPaymentsTimelineEvent ) =>
	getRecord( event.transaction_details );

// Client isFXEvent().
export const isFxEvent = ( event: WooPaymentsTimelineEvent ) => {
	const details = getTransactionDetails( event );
	const customerCurrency = getString( details, 'customer_currency' );
	const storeCurrency = getString( details, 'store_currency' );

	return (
		!! customerCurrency &&
		!! storeCurrency &&
		customerCurrency !== storeCurrency
	);
};

// Client composeFXString().
export const composeFxString = ( event: WooPaymentsTimelineEvent ) => {
	if ( ! isFxEvent( event ) ) {
		return undefined;
	}

	const details = getTransactionDetails( event );

	return formatFx(
		getString( details, 'customer_currency' ),
		getNumber( details, 'customer_amount_captured' ) ??
			getNumber( details, 'customer_amount' ),
		getString( details, 'store_currency' ),
		getNumber( details, 'store_amount_captured' ) ??
			getNumber( details, 'store_amount' )
	);
};

const taxDescriptions: Record< string, string > = {
	'AT VAT': __( 'AT VAT', 'woocommerce' ),
	'BE VAT': __( 'BE VAT', 'woocommerce' ),
	'BG VAT': __( 'BG VAT', 'woocommerce' ),
	'CY VAT': __( 'CY VAT', 'woocommerce' ),
	'CZ VAT': __( 'CZ VAT', 'woocommerce' ),
	'DE VAT': __( 'DE VAT', 'woocommerce' ),
	'DK VAT': __( 'DK VAT', 'woocommerce' ),
	'EE VAT': __( 'EE VAT', 'woocommerce' ),
	'ES VAT': __( 'ES VAT', 'woocommerce' ),
	'FI VAT': __( 'FI VAT', 'woocommerce' ),
	'FR VAT': __( 'FR VAT', 'woocommerce' ),
	'GB VAT': __( 'UK VAT', 'woocommerce' ),
	'GR VAT': __( 'GR VAT', 'woocommerce' ),
	'HR VAT': __( 'HR VAT', 'woocommerce' ),
	'HU VAT': __( 'HU VAT', 'woocommerce' ),
	'IE VAT': __( 'IE VAT', 'woocommerce' ),
	'IT VAT': __( 'IT VAT', 'woocommerce' ),
	'LT VAT': __( 'LT VAT', 'woocommerce' ),
	'LU VAT': __( 'LU VAT', 'woocommerce' ),
	'LV VAT': __( 'LV VAT', 'woocommerce' ),
	'MT VAT': __( 'MT VAT', 'woocommerce' ),
	'NO VAT': __( 'NO VAT', 'woocommerce' ),
	'NL VAT': __( 'NL VAT', 'woocommerce' ),
	'PL VAT': __( 'PL VAT', 'woocommerce' ),
	'PT VAT': __( 'PT VAT', 'woocommerce' ),
	'RO VAT': __( 'RO VAT', 'woocommerce' ),
	'SE VAT': __( 'SE VAT', 'woocommerce' ),
	'SI VAT': __( 'SI VAT', 'woocommerce' ),
	'SK VAT': __( 'SK VAT', 'woocommerce' ),
	'AU GST': __( 'AU GST', 'woocommerce' ),
	'NZ GST': __( 'NZ GST', 'woocommerce' ),
	'SG GST': __( 'SG GST', 'woocommerce' ),
	'CH VAT': __( 'CH VAT', 'woocommerce' ),
	'JP JCT': __( 'JP JCT', 'woocommerce' ),
};

// Client getLocalizedTaxDescription().
const getLocalizedTaxDescription = ( description: string ) =>
	Object.prototype.hasOwnProperty.call( taxDescriptions, description )
		? taxDescriptions[ description ]
		: __( 'Tax', 'woocommerce' );

const getFeeRates = ( event: WooPaymentsTimelineEvent ) =>
	getRecord( event.fee_rates );

const getFeeHistory = ( event: WooPaymentsTimelineEvent ) => {
	const history = getFeeRates( event )?.history;

	return Array.isArray( history )
		? history
				.map( getRecord )
				.filter( ( entry ): entry is TimelineRecord => !! entry )
		: undefined;
};

// Client isBaseFeeOnly().
const isBaseFeeOnly = ( event: WooPaymentsTimelineEvent ) => {
	const history = getFeeHistory( event );

	return history?.length === 1 && history[ 0 ].type === 'base';
};

// Client convertAndFormatFeeAmount(): shows a fee in the store currency, converting it when needed.
const convertAndFormatFeeAmount = (
	feeAmount: number,
	feeCurrency: string,
	event: WooPaymentsTimelineEvent
) => {
	const storeCurrency = getString(
		getTransactionDetails( event ),
		'store_currency'
	)?.toUpperCase();
	const exchangeRate = getRecord( getFeeRates( event )?.fee_exchange_rate );

	if (
		( storeCurrency && storeCurrency === feeCurrency.toUpperCase() ) ||
		! isFxEvent( event ) ||
		! exchangeRate
	) {
		return formatMoney( -Math.abs( feeAmount ), feeCurrency );
	}

	const fromAmount = getNumber( exchangeRate, 'from_amount' ) ?? 0;
	const toAmount = getNumber( exchangeRate, 'to_amount' ) ?? 0;
	const fromCurrency = getString( exchangeRate, 'from_currency' ) || '';

	if ( feeAmount === 0 || fromAmount === 0 || toAmount === 0 ) {
		return formatMoney( 0, storeCurrency );
	}

	const convertedAmount =
		feeCurrency.toUpperCase() === fromCurrency.toUpperCase()
			? Math.floor( ( feeAmount * toAmount ) / fromAmount )
			: Math.floor( ( feeAmount * fromAmount ) / toAmount );

	return formatMoney( -Math.abs( convertedAmount ), storeCurrency );
};

// Client composeFeeString().
const composeFeeString = ( event: WooPaymentsTimelineEvent ) => {
	const feeRates = getFeeRates( event );
	const currency = getString( event, 'currency' );

	if ( ! feeRates ) {
		const fee = getNumber( event, 'fee' );

		return fee === undefined
			? undefined
			: sprintf(
					/* translators: %s: formatted fee amount. */
					__( 'Fee: %s', 'woocommerce' ),
					formatMoney( fee, currency )
			  );
	}

	const details = getTransactionDetails( event );
	const beforeTax = getRecord( feeRates.before_tax );
	const baseFeeOnly = isBaseFeeOnly( event );
	const label = baseFeeOnly
		? __( 'Base fee', 'woocommerce' )
		: __( 'Fee', 'woocommerce' );
	let feeAmount: number | undefined;
	let feeCurrency: string | undefined;
	let baseFee: number;
	let baseFeeCurrency: string | undefined;

	if ( isFxEvent( event ) ) {
		feeAmount =
			getNumber( beforeTax, 'amount' ) ||
			getNumber( details, 'customer_fee' );
		feeCurrency =
			getString( beforeTax, 'currency' ) ||
			getString( details, 'customer_currency' );
		baseFee = getNumber( feeRates, 'fixed' ) || 0;
		baseFeeCurrency =
			getString( feeRates, 'fixed_currency' ) || feeCurrency;
	} else {
		feeAmount = beforeTax
			? getNumber( beforeTax, 'amount' )
			: getNumber( event, 'fee' );
		feeCurrency = beforeTax ? getString( beforeTax, 'currency' ) : currency;
		baseFee = getNumber( feeRates, 'fixed' ) ?? 0;
		baseFeeCurrency = getString( feeRates, 'fixed_currency' );
	}

	if ( feeAmount === undefined || ! feeCurrency ) {
		return undefined;
	}

	const formattedFeeAmount = convertAndFormatFeeAmount(
		feeAmount,
		feeCurrency,
		event
	);

	if ( baseFeeOnly && getFeeHistory( event )?.[ 0 ]?.capped ) {
		return sprintf(
			/* translators: 1: fee label, 2: fee cap amount, 3: fee amount. */
			__( '%1$s (capped at %2$s): %3$s', 'woocommerce' ),
			label,
			formatMoney( baseFee, baseFeeCurrency ),
			formattedFeeAmount
		);
	}

	const storeCurrency = getString( details, 'store_currency' );
	const customerCurrency = getString( details, 'customer_currency' );
	const sameSymbol = hasSameSymbol( storeCurrency, customerCurrency );

	return sprintf(
		/* translators: 1: fee label, 2: fee percentage, 3: fixed fee, 4: customer currency code suffix, 5: fee amount, 6: store currency code suffix. */
		__( '%1$s (%2$s%% + %3$s%4$s): %5$s%6$s', 'woocommerce' ),
		label,
		String(
			formatFeePercentage( getNumber( feeRates, 'percentage' ) ?? 0 )
		),
		formatMoney( baseFee, baseFeeCurrency ),
		sameSymbol ? ` ${ customerCurrency }` : '',
		formattedFeeAmount,
		sameSymbol ? ` ${ storeCurrency }` : ''
	);
};

const getFeeHistoryLabel = (
	labelType: string,
	fixedRate: number,
	isCapped: boolean
) => {
	const hasFixedRate = fixedRate !== 0;

	switch ( labelType ) {
		case 'base':
			if ( isCapped ) {
				/* translators: %2$s: capped fee amount. */
				return __( 'Base fee: capped at %2$s', 'woocommerce' );
			}
			return hasFixedRate
				? /* translators: 1: fee percentage, 2: fixed fee. */
				  __( 'Base fee: %1$s%% + %2$s', 'woocommerce' )
				: /* translators: %1$s: fee percentage. */
				  __( 'Base fee: %1$s%%', 'woocommerce' );
		case 'additional-international':
			return hasFixedRate
				? /* translators: 1: fee percentage, 2: fixed fee. */
				  __( 'International card fee: %1$s%% + %2$s', 'woocommerce' )
				: /* translators: %1$s: fee percentage. */
				  __( 'International card fee: %1$s%%', 'woocommerce' );
		case 'additional-fx':
			return hasFixedRate
				? /* translators: 1: fee percentage, 2: fixed fee. */
				  __( 'Currency conversion fee: %1$s%% + %2$s', 'woocommerce' )
				: /* translators: %1$s: fee percentage. */
				  __( 'Currency conversion fee: %1$s%%', 'woocommerce' );
		case 'additional-wcpay-subscription':
			return hasFixedRate
				? /* translators: 1: fee percentage, 2: fixed fee. */
				  __(
						'Subscription transaction fee: %1$s%% + %2$s',
						'woocommerce'
				  )
				: /* translators: %1$s: fee percentage. */
				  __( 'Subscription transaction fee: %1$s%%', 'woocommerce' );
		case 'additional-device':
			return hasFixedRate
				? /* translators: 1: fee percentage, 2: fixed fee. */
				  __(
						'Tap to pay transaction fee: %1$s%% + %2$s',
						'woocommerce'
				  )
				: /* translators: %1$s: fee percentage. */
				  __( 'Tap to pay transaction fee: %1$s%%', 'woocommerce' );
		case 'discount':
			return __( 'Discount', 'woocommerce' );
		default:
			return undefined;
	}
};

// Client feeBreakdown() and composeFeeBreakdown(): one line per fee history entry, hidden for a base fee alone.
const composeFeeBreakdown = ( event: WooPaymentsTimelineEvent ) => {
	const history = getFeeHistory( event );

	if ( ! history || isBaseFeeOnly( event ) ) {
		return undefined;
	}

	const details = getTransactionDetails( event );
	const sameSymbol = hasSameSymbol(
		getString( details, 'store_currency' ),
		getString( details, 'customer_currency' )
	);
	const lines = new Map< string, ReactNode >();

	history.forEach( ( fee ) => {
		const additionalType = getString( fee, 'additional_type' );
		const labelType = `${ getString( fee, 'type' ) ?? '' }${
			additionalType ? `-${ additionalType }` : ''
		}`;
		const fixedRate = getNumber( fee, 'fixed_rate' ) ?? 0;
		const currency = getString( fee, 'currency' ) || 'usd';
		const template = getFeeHistoryLabel(
			labelType,
			fixedRate,
			!! fee.capped
		);

		if ( ! template ) {
			return;
		}

		const percentage = String(
			formatFeePercentage( getNumber( fee, 'percentage_rate' ) ?? 0 )
		);
		const fixed = `${ formatMoney( fixedRate, currency ) }${
			sameSymbol ? ` ${ currency.toUpperCase() }` : ''
		}`;
		const label = sprintf( template, percentage, fixed );

		lines.set(
			labelType,
			labelType === 'discount' ? (
				<>
					{ label }
					<ul className="discount-split-list">
						<li>
							{ sprintf(
								/* translators: %s: percentage number. */
								__( 'Variable fee: %s', 'woocommerce' ),
								percentage
							) + '%' }
						</li>
						<li>
							{ sprintf(
								/* translators: %s: formatted fixed fee. */
								__( 'Fixed fee: %s', 'woocommerce' ),
								fixed
							) }
						</li>
					</ul>
				</>
			) : (
				label
			)
		);
	} );

	if ( ! lines.size ) {
		return undefined;
	}

	return (
		<ul className="fee-breakdown-list">
			{ Array.from( lines.entries() ).map( ( [ labelType, line ] ) => (
				<li key={ labelType }>{ line }</li>
			) ) }
		</ul>
	);
};

// Client composeTaxString().
const composeTaxString = ( event: WooPaymentsTimelineEvent ) => {
	const tax = getRecord( getFeeRates( event )?.tax );
	const amount = getNumber( tax, 'amount' );
	const currency = getString( tax, 'currency' );

	if ( ! amount || ! currency ) {
		return undefined;
	}

	const description = getString( tax, 'description' );
	const percentageRate = getNumber( tax, 'percentage_rate' );

	return sprintf(
		/* translators: 1: tax description, 2: tax percentage, 3: tax amount. */
		__( 'Tax%1$s%2$s: %3$s', 'woocommerce' ),
		description ? ` ${ getLocalizedTaxDescription( description ) }` : '',
		percentageRate ? ` (${ ( percentageRate * 100 ).toFixed( 2 ) }%)` : '',
		convertAndFormatFeeAmount( amount, currency, event )
	);
};

// Client formatNetString(): the legacy capture net, in the store currency for converted charges.
const getLegacyNet = ( event: WooPaymentsTimelineEvent ) => {
	if ( isFxEvent( event ) ) {
		const details = getTransactionDetails( event );
		const captured = getNumber( details, 'store_amount_captured' );
		const fee = getNumber( details, 'store_fee' );

		return captured === undefined || fee === undefined
			? undefined
			: formatExplicitMoney(
					captured - fee,
					getString( details, 'store_currency' )
			  );
	}

	const captured =
		getNumber( event, 'amount_captured' ) ?? getNumber( event, 'amount' );
	const fee = getNumber( event, 'fee' );

	return captured === undefined || fee === undefined
		? undefined
		: formatExplicitMoney( captured - fee, getString( event, 'currency' ) );
};

const getBreakdown = ( event: WooPaymentsTimelineEvent ) =>
	getRecord( event.fee_breakdown_v1 );

const getBreakdownTotal = (
	breakdown: TimelineRecord | undefined,
	key: string
) => getRecord( getRecord( breakdown?.totals )?.[ key ] );

const feeRowLabels: Record< string, string > = {
	base: __( 'Base fee', 'woocommerce' ),
	'additional.international': __( 'International card fee', 'woocommerce' ),
	'additional.fx': __( 'Currency conversion fee', 'woocommerce' ),
	'additional.wcpay-subscription': __(
		'Subscription transaction fee',
		'woocommerce'
	),
	'additional.device': __( 'Device fee', 'woocommerce' ),
	dispute_fee: __( 'Dispute fee', 'woocommerce' ),
	dispute_fee_refund: __( 'Dispute fee refund', 'woocommerce' ),
	processing_fee: __( 'Processing fee', 'woocommerce' ),
};

// Client resolveFeeRowLabel(): the server label, then a known key, then the raw key.
const resolveFeeRowLabel = (
	key: string,
	label: string | undefined,
	meta?: TimelineRecord
) => {
	if ( label ) {
		return label;
	}

	if ( Object.prototype.hasOwnProperty.call( feeRowLabels, key ) ) {
		return feeRowLabels[ key ];
	}

	if ( key.startsWith( 'discount.' ) ) {
		return __( 'Discount', 'woocommerce' );
	}

	if ( key === 'tax_on_fee' ) {
		return (
			getString( meta, 'description' ) ??
			__( 'Tax on fee', 'woocommerce' )
		);
	}

	return key;
};

// Client resolveNoteText(): only the application fee refund note has merchant-facing copy.
const resolveNoteText = ( code: string, meta?: TimelineRecord ) => {
	if ( code !== 'application_fee_refunded' ) {
		return undefined;
	}

	const refundedAmount = getNumber( meta, 'refunded_amount' );
	const refundedCurrency = getString( meta, 'refunded_currency' );

	if ( refundedAmount === undefined || ! refundedCurrency ) {
		return __(
			'WooPayments refunded its application fee on this transaction.',
			'woocommerce'
		);
	}

	const originalAmount = getNumber( meta, 'original_amount' );

	if ( originalAmount === undefined ) {
		return sprintf(
			/* translators: %s: refunded application fee amount. */
			__(
				'WooPayments refunded its %s application fee on this transaction.',
				'woocommerce'
			),
			formatExplicitMoney( refundedAmount, refundedCurrency )
		);
	}

	return sprintf(
		/* translators: 1: refunded amount, 2: application fee before the refund. */
		__(
			'WooPayments refunded %1$s of its %2$s application fee on this transaction.',
			'woocommerce'
		),
		formatExplicitMoney( refundedAmount, refundedCurrency ),
		formatExplicitMoney( originalAmount, refundedCurrency )
	);
};

// Client formatRateText().
const formatRateText = (
	rate: TimelineRecord | undefined,
	fallbackCurrency: string
) => {
	if ( ! rate ) {
		return '';
	}

	const currency = getString( rate, 'fixed_currency' ) || fallbackCurrency;

	if ( rate.capped ) {
		return sprintf(
			/* translators: %s: capped fee amount. */
			__( 'capped at %s', 'woocommerce' ),
			formatMoney(
				getNumber( rate, 'cap_amount' ) ??
					getNumber( rate, 'fixed' ) ??
					0,
				currency
			)
		);
	}

	const parts: string[] = [];
	const percentage = getNumber( rate, 'percentage' ) ?? 0;
	const percentageDisplay = getString( rate, 'percentage_display' );
	const fixed = getNumber( rate, 'fixed' ) ?? 0;

	if ( percentageDisplay ) {
		parts.push( percentageDisplay );
	} else if ( percentage !== 0 ) {
		parts.push(
			`${ Number.parseFloat( ( percentage * 100 ).toFixed( 3 ) ) }%`
		);
	}

	if ( fixed !== 0 ) {
		parts.push( formatMoney( fixed, currency ) );
	}

	return parts.join( ' + ' );
};

// Client composeAdjustmentSplitFeeRow(): a discount with both parts renders as two sub-lines.
const composeAdjustmentSplit = (
	row: TimelineRecord,
	label: string,
	currency: string
) => {
	const rate = getRecord( row.rate );
	const percentage = getNumber( rate, 'percentage' ) ?? 0;
	const fixed = getNumber( rate, 'fixed' ) ?? 0;

	if ( row.kind !== 'adjustment' || percentage === 0 || fixed === 0 ) {
		return undefined;
	}

	return (
		<>
			{ label }
			<ul className="discount-split-list">
				<li>
					{ sprintf(
						/* translators: %s: percentage. */
						__( 'Variable fee: %s', 'woocommerce' ),
						`${ Number.parseFloat(
							( percentage * 100 ).toFixed( 3 )
						) }%`
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: %s: formatted fixed fee. */
						__( 'Fixed fee: %s', 'woocommerce' ),
						formatMoney( fixed, currency )
					) }
				</li>
			</ul>
		</>
	);
};

// Client composeCapturedBodyFromBreakdown().
const composeCapturedBodyFromBreakdown = (
	event: WooPaymentsTimelineEvent,
	breakdown: TimelineRecord
) => {
	const lines: ReactNode[] = [];
	const feeTotal = getBreakdownTotal( breakdown, 'fee' );
	const taxTotal = getBreakdownTotal( breakdown, 'tax' );
	const captureNet = getBreakdownTotal( breakdown, 'capture_net' );
	const storeCurrency = getString( feeTotal, 'currency' ) || 'usd';
	const fx = getRecord( breakdown.fx );
	const rows = Array.isArray( breakdown.rows )
		? breakdown.rows
				.map( getRecord )
				.filter( ( row ): row is TimelineRecord => !! row )
		: [];

	const fxLine = formatFx(
		getString( fx, 'from_currency' ),
		getNumber( fx, 'from_amount' ) ?? 0,
		getString( fx, 'to_currency' ),
		getNumber( fx, 'to_amount' ) ?? 0
	);
	if ( fxLine ) {
		lines.push( fxLine );
	}

	const customerCurrency =
		getString( fx, 'from_currency' ) ??
		getString( getTransactionDetails( event ), 'customer_currency' ) ??
		storeCurrency;
	const sameSymbol = hasSameSymbol( customerCurrency, storeCurrency );
	const currencySuffix = sameSymbol ? ` ${ storeCurrency }` : '';
	const feeAmountText =
		formatMoney(
			getNumber( feeTotal, 'display_amount' ) ??
				-Math.abs( getNumber( feeTotal, 'amount' ) ?? 0 ),
			storeCurrency
		) + currencySuffix;
	const feeRate = getRecord( feeTotal?.rate );
	const totalRateText = formatRateText( feeRate, storeCurrency );
	const feeLabel =
		resolveFeeRowLabel( getString( feeTotal, 'key' ) ?? '', undefined ) ||
		__( 'Fee', 'woocommerce' );

	lines.push(
		totalRateText
			? sprintf(
					/* translators: 1: fee label, 2: fee rate, 3: fee amount. */
					__( '%1$s (%2$s): %3$s', 'woocommerce' ),
					feeLabel,
					getNumber( feeRate, 'fixed' ) && sameSymbol
						? `${ totalRateText }${ currencySuffix }`
						: totalRateText,
					feeAmountText
			  )
			: sprintf(
					/* translators: 1: fee label, 2: fee amount. */
					__( '%1$s: %2$s', 'woocommerce' ),
					feeLabel,
					feeAmountText
			  )
	);

	const feeRows = rows.filter( ( row ) => row.kind !== 'tax' );
	if ( feeRows.length > 1 ) {
		lines.push(
			<ul className="fee-breakdown-list">
				{ feeRows.map( ( row, index ) => {
					const rowKey = getString( row, 'key' ) ?? '';
					const label = resolveFeeRowLabel(
						rowKey,
						getString( row, 'label' ),
						getRecord( row.meta )
					);
					const rowRate = getRecord( row.rate );
					const rowCurrency =
						getString( rowRate, 'fixed_currency' ) ||
						getString( row, 'currency' ) ||
						storeCurrency;
					const rateText = formatRateText( rowRate, rowCurrency );

					return (
						<li key={ `${ rowKey }-${ index }` }>
							{ composeAdjustmentSplit(
								row,
								label,
								rowCurrency
							) ??
								( rateText
									? `${ label }: ${ rateText }`
									: label ) }
						</li>
					);
				} ) }
			</ul>
		);
	}

	const taxAmount = getNumber( taxTotal, 'amount' ) ?? 0;
	if ( taxAmount !== 0 ) {
		const taxRow = rows.find( ( row ) => row.kind === 'tax' );
		const taxLabel = getString( taxRow, 'label' );
		const taxRate = getRecord( taxRow?.rate );
		const taxPercentage = getNumber( taxRate, 'percentage' );
		const taxPercentageText =
			getString( taxRate, 'percentage_display' ) ??
			( taxPercentage
				? `${ ( taxPercentage * 100 ).toFixed( 2 ) }%`
				: '' );

		lines.push(
			sprintf(
				/* translators: 1: tax description, 2: tax percentage, 3: tax amount. */
				__( 'Tax%1$s%2$s: %3$s', 'woocommerce' ),
				taxLabel ? ` ${ getLocalizedTaxDescription( taxLabel ) }` : '',
				taxPercentageText ? ` (${ taxPercentageText })` : '',
				formatMoney(
					getNumber( taxTotal, 'display_amount' ) ??
						-Math.abs( taxAmount ),
					getString( taxTotal, 'currency' ) || storeCurrency
				)
			)
		);
	}

	const captureNetAmount = getNumber( captureNet, 'amount' );
	if ( captureNetAmount !== undefined ) {
		lines.push(
			sprintf(
				/* translators: %s: net payout amount. */
				__( 'Net payout: %s', 'woocommerce' ),
				formatExplicitMoney(
					captureNetAmount,
					getString( captureNet, 'currency' ) || storeCurrency
				)
			)
		);
	}

	( Array.isArray( breakdown.notes ) ? breakdown.notes : [] ).forEach(
		( note ) => {
			const noteRecord = getRecord( note );
			const text = resolveNoteText(
				getString( noteRecord, 'code' ) ?? '',
				getRecord( noteRecord?.meta )
			);

			if ( text ) {
				lines.push( text );
			}
		}
	);

	return lines;
};

/**
 * Builds the captured event's payout amount and body lines, preferring the fee_breakdown_v1 envelope.
 *
 * @param event Captured timeline event.
 */
export const getCapturedDetails = ( event: WooPaymentsTimelineEvent ) => {
	const breakdown = getBreakdown( event );

	if ( breakdown ) {
		const captureNet = getBreakdownTotal( breakdown, 'capture_net' );
		const captureNetAmount = getNumber( captureNet, 'amount' );

		return {
			net:
				captureNetAmount === undefined
					? undefined
					: formatExplicitMoney(
							captureNetAmount,
							getString( captureNet, 'currency' )
					  ),
			body: composeCapturedBodyFromBreakdown( event, breakdown ),
		};
	}

	const net = getLegacyNet( event );

	return {
		net,
		body: [
			composeFxString( event ),
			composeFeeString( event ),
			composeFeeBreakdown( event ),
			composeTaxString( event ),
			net
				? sprintf(
						/* translators: %s: net payout amount. */
						__( 'Net payout: %s', 'woocommerce' ),
						net
				  )
				: undefined,
		].filter( Boolean ) as ReactNode[],
	};
};

/**
 * Returns the envelope's payout impact for dispute events, like the client's getEnvelopeDepositImpact().
 *
 * @param event Dispute timeline event.
 */
export const getEnvelopeDepositImpact = ( event: WooPaymentsTimelineEvent ) => {
	const net = getBreakdownTotal( getBreakdown( event ), 'net' );
	const amount = getNumber( net, 'amount' );

	return amount === undefined
		? undefined
		: {
				amount: Math.abs( amount ),
				currency: getString( net, 'currency' ),
		  };
};
