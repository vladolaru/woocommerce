/**
 * External dependencies
 */
import { CardDivider } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getPaymentMethodDefinition } from '~/woopayments/settings/payment-method-definitions';
import { getWooPaymentsAmountFromMinorUnits } from '../../../currency';
import type { WooPaymentsOverviewAccountFee } from '../types';

interface FeeRate {
	percentage_rate?: number;
	fixed_rate?: number;
	currency?: string;
	discount?: number;
	volume_allowance?: number | null;
	volume_currency?: string | null;
	current_volume?: number | null;
	end_time?: string | null;
}

// Client 11.1.0 `includes/multi-currency/client/utils/currency/index.js:8-19`.
const getCurrencyName = ( currency: string ) => {
	const names: Record< string, string > = {
		aud: __( 'Australian dollar', 'woocommerce' ),
		cad: __( 'Canadian dollar', 'woocommerce' ),
		chf: __( 'Swiss franc', 'woocommerce' ),
		dkk: __( 'Danish krone', 'woocommerce' ),
		eur: __( 'Euro', 'woocommerce' ),
		gbp: __( 'Pound sterling', 'woocommerce' ),
		nok: __( 'Norwegian krone', 'woocommerce' ),
		nzd: __( 'New Zealand dollar', 'woocommerce' ),
		sek: __( 'Swedish krona', 'woocommerce' ),
		usd: __( 'United States (US) dollar', 'woocommerce' ),
	};

	return names[ currency.toLowerCase() ] || currency.toUpperCase();
};

// Client 11.1.0 `utils/fees/index.ts:8-10`.
const formatFee = ( fee = 0 ) => String( Number( ( fee * 100 ).toFixed( 3 ) ) );

const formatFeeCurrency = ( amount = 0, currency = 'usd' ) => {
	const currencyCode = currency.toUpperCase();
	const value = getWooPaymentsAmountFromMinorUnits( amount, currencyCode );

	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currencyCode,
		} ).format( value );
	} catch {
		return `${ value } ${ currencyCode }`;
	}
};

const formatEndDate = ( value: string ) => {
	const date = new Date(
		value.includes( 'T' ) ? value : value.replace( ' ', 'T' )
	);

	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}

	return date.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	} );
};

// Client 11.1.0 `utils/account-fees.tsx:434-464`.
const getPaymentMethodName = ( paymentMethod: string ) => {
	if ( paymentMethod === 'card' ) {
		return __( 'Card transactions', 'woocommerce' );
	}

	if ( paymentMethod === 'card_present' ) {
		return __( 'In-person transactions', 'woocommerce' );
	}

	const title = getPaymentMethodDefinition( paymentMethod )?.label;

	return title
		? sprintf(
				/* translators: %s: Payment method title. */
				__( '%s transactions', 'woocommerce' ),
				title
		  )
		: __( 'Unknown transactions', 'woocommerce' );
};

const formatFeeRate = ( percentage: number, fixed: number, currency: string ) =>
	sprintf(
		/* translators: 1: Percentage part of the fee, 2: percent symbol, 3: Fixed part of the fee. */
		__( '%1$s%2$s + %3$s per transaction', 'woocommerce' ),
		formatFee( percentage ),
		'%',
		formatFeeCurrency( fixed, currency )
	);

// Client 11.1.0 `utils/account-fees.tsx:346-419` (`formatAccountFeesDescription()` with its default formats).
const getFeeDescription = ( base: FeeRate, current: FeeRate ) => {
	const baseCurrency = base.currency || 'usd';
	const baseDescription = formatFeeRate(
		base.percentage_rate ?? 0,
		base.fixed_rate ?? 0,
		baseCurrency
	);

	if (
		current.percentage_rate === base.percentage_rate &&
		current.fixed_rate === base.fixed_rate &&
		current.currency === base.currency
	) {
		return baseDescription;
	}

	const currentDescription = current.discount
		? formatFeeRate(
				( base.percentage_rate ?? 0 ) * ( 1 - current.discount ),
				( base.fixed_rate ?? 0 ) * ( 1 - current.discount ),
				baseCurrency
		  )
		: formatFeeRate(
				current.percentage_rate ?? 0,
				current.fixed_rate ?? 0,
				baseCurrency
		  );
	const discountLabel = current.discount
		? ' ' +
		  sprintf(
				/* translators: 1: Percentage discount, 2: percent symbol. */
				__( '(%1$s%2$s discount)', 'woocommerce' ),
				formatFee( current.discount ),
				'%'
		  )
		: '';

	return createInterpolateElement(
		sprintf(
			/* translators: 1: Base fee that no longer applies, 2: Current fee (e.g. "2.9% + $0.30 per transaction"). */
			__( '<s>%1$s</s> %2$s', 'woocommerce' ),
			baseDescription,
			currentDescription
		) + discountLabel,
		{ s: <s /> }
	);
};

// Client 11.1.0 `components/account-details/account-fees/expiration-bar.js`.
const ExpirationBar = ( { fee }: { fee: FeeRate } ) => {
	if ( ! fee.volume_allowance ) {
		return null;
	}

	const currency = fee.volume_currency ?? fee.currency ?? 'usd';
	const progress = ( fee.current_volume ?? 0 ) / fee.volume_allowance;

	return (
		<div
			className="woocommerce-woopayments-account-fees__progress"
			role="progressbar"
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-valuenow={ Math.round( progress * 100 ) }
		>
			<div className="woocommerce-woopayments-account-fees__progress-track">
				<div
					className="woocommerce-woopayments-account-fees__progress-fill"
					style={ { width: `${ progress * 100 }%` } }
				/>
			</div>
			<span>
				{ formatFeeCurrency( fee.current_volume ?? 0, currency ) }
			</span>
			<span>{ formatFeeCurrency( fee.volume_allowance, currency ) }</span>
		</div>
	);
};

// Client 11.1.0 `components/account-details/account-fees/expiration-description.js`.
const getExpirationDescription = ( fee: FeeRate ) => {
	const currency = fee.volume_currency ?? fee.currency ?? 'usd';

	if ( fee.volume_allowance && fee.end_time ) {
		return sprintf(
			/* translators: 1: Total payment volume until this promotion expires, 2: End date of the promotion. */
			__(
				'Discounted base fee expires after the first %1$s of total payment volume or on %2$s.',
				'woocommerce'
			),
			formatFeeCurrency( fee.volume_allowance, currency ),
			formatEndDate( fee.end_time )
		);
	}

	if ( fee.volume_allowance ) {
		return sprintf(
			/* translators: %s: Total payment volume until this promotion expires. */
			__(
				'Discounted base fee expires after the first %s of total payment volume.',
				'woocommerce'
			),
			formatFeeCurrency( fee.volume_allowance, currency )
		);
	}

	if ( fee.end_time ) {
		return sprintf(
			/* translators: %s: End date of the promotion. */
			__( 'Discounted base fee expires on %s.', 'woocommerce' ),
			formatEndDate( fee.end_time )
		);
	}

	return '';
};

const AccountFee = ( {
	accountFee,
}: {
	accountFee: WooPaymentsOverviewAccountFee;
} ) => {
	const base = ( accountFee.fee.base ?? {} ) as FeeRate;
	const discounts = ( accountFee.fee.discount ?? [] ) as FeeRate[];
	// Like the client, only the first discount shows.
	const current = discounts[ 0 ] ?? base;
	const currency = base.currency || 'usd';
	const expirationDescription = getExpirationDescription( current );

	return (
		<div className="woocommerce-woopayments-account-fees__fee">
			<p>{ getPaymentMethodName( accountFee.payment_method ) }:</p>
			<p>
				{ `${ getCurrencyName(
					currency
				) } (${ currency.toUpperCase() }) ` }
				{ getFeeDescription( base, current ) }
			</p>
			<ExpirationBar fee={ current } />
			{ expirationDescription && (
				<p className="description">{ expirationDescription }</p>
			) }
		</div>
	);
};

/**
 * The active discounts section of the account details card.
 *
 * Client 11.1.0 `components/account-details/account-fees/index.js:42-72`.
 *
 * @param props             Component props.
 * @param props.accountFees Fee structures for enabled payment methods.
 */
export const AccountFees = ( {
	accountFees,
}: {
	accountFees: WooPaymentsOverviewAccountFee[];
} ) => {
	const activeDiscounts = accountFees.filter(
		( accountFee ) => ( accountFee.fee.discount ?? [] ).length > 0
	);

	if ( activeDiscounts.length === 0 ) {
		return null;
	}

	return (
		<div className="woocommerce-woopayments-account-details__fees">
			<CardDivider />
			<h3>{ __( 'Active discounts', 'woocommerce' ) }</h3>
			{ activeDiscounts.map( ( accountFee ) => (
				<AccountFee
					key={ accountFee.payment_method }
					accountFee={ accountFee }
				/>
			) ) }
		</div>
	);
};
