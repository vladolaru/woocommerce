/**
 * External dependencies
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { _x, sprintf } from '@wordpress/i18n';
import moment from 'moment';

/**
 * Internal dependencies
 */
import { formatCurrency } from '../currency-format';
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
	WooPaymentsInstantBalance,
	WooPaymentsMoneyAmount,
} from './types';

export { getSettingsPaymentsProviderRouteUrl } from '../utils';

export const normalizeCurrencyCode = ( currency?: string | null ) =>
	( currency || 'usd' ).toUpperCase();

// Client 11.1.0 `formatCurrency()`: zero-decimal aware and in the store's currency format; a negated zero reads "$0.00".
export const formatWooPaymentsAmount = (
	amount: number,
	currency?: string | null
) =>
	formatCurrency(
		amount === 0 ? 0 : amount,
		normalizeCurrencyCode( currency )
	);

export const getDefaultCurrency = (
	overview: WooPaymentsDepositsOverview | null
) => {
	const accountCurrency = overview?.account.default_currency;
	if ( accountCurrency ) {
		return accountCurrency.toLowerCase();
	}

	return (
		overview?.balance?.available?.[ 0 ]?.currency ||
		overview?.balance?.pending?.[ 0 ]?.currency ||
		'usd'
	).toLowerCase();
};

export const getAmountForCurrency = (
	amounts: WooPaymentsMoneyAmount[] | undefined,
	currency: string
) =>
	amounts?.find(
		( amount ) => amount.currency.toLowerCase() === currency.toLowerCase()
	)?.amount ?? 0;

const addCurrency = ( currencies: Set< string >, currency?: string | null ) => {
	if ( currency ) {
		currencies.add( currency.toLowerCase() );
	}
};

export const getBalanceCurrencyOptions = (
	overview: WooPaymentsDepositsOverview | null
) => {
	const currencies = new Set< string >();
	const defaultCurrency = overview?.account.default_currency?.toLowerCase();

	overview?.deposit?.last_paid?.forEach( ( deposit ) =>
		addCurrency( currencies, deposit.currency )
	);
	overview?.balance?.available?.forEach( ( amount ) =>
		addCurrency( currencies, amount.currency )
	);
	overview?.balance?.pending?.forEach( ( amount ) =>
		addCurrency( currencies, amount.currency )
	);
	overview?.balance?.instant?.forEach( ( amount ) =>
		addCurrency( currencies, amount.currency )
	);

	const currencyOptions = Array.from( currencies );

	if ( defaultCurrency && currencyOptions.includes( defaultCurrency ) ) {
		return [
			defaultCurrency,
			...currencyOptions.filter(
				( currency ) => currency !== defaultCurrency
			),
		];
	}

	return currencyOptions;
};

export const getSelectedBalanceCurrency = (
	overview: WooPaymentsDepositsOverview | null,
	selectedCurrency?: string | null
) => {
	const currencies = getBalanceCurrencyOptions( overview );
	const normalizedSelectedCurrency = selectedCurrency?.toLowerCase();

	if (
		normalizedSelectedCurrency &&
		currencies.includes( normalizedSelectedCurrency )
	) {
		return normalizedSelectedCurrency;
	}

	return currencies[ 0 ] || getDefaultCurrency( overview );
};

export const getInstantBalanceForCurrency = (
	overview: WooPaymentsDepositsOverview | null,
	currency: string
): WooPaymentsInstantBalance | null =>
	overview?.balance?.instant?.find(
		( instantBalance ) =>
			instantBalance.currency.toLowerCase() === currency.toLowerCase()
	) ?? null;

export const getMonthlyAnchorLabel = ( anchor: number ) => {
	if ( anchor === 31 ) {
		return _x(
			'last day of every month',
			'monthly payout schedule anchor',
			'woocommerce'
		);
	}

	if ( [ 1, 21 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%dst', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	if ( [ 2, 22 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%dnd', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	if ( [ 3, 23 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%drd', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	return sprintf(
		/* translators: %d: Day of the month. */
		_x( '%dth', 'monthly payout schedule day option', 'woocommerce' ),
		anchor
	);
};

/**
 * The payout date in the site date format, read as UTC, as client 11.1.0 `utils/date-time.ts` `formatDateTimeFromString()` shows it.
 *
 * @param payout The payout.
 */
export const formatPayoutSiteDate = (
	payout: Pick< WooPaymentsDeposit, 'date' | 'created' >
) => {
	const rawDate = payout.date || payout.created || '';
	const date = moment.utc(
		typeof rawDate === 'number' && rawDate < 10000000000
			? rawDate * 1000
			: rawDate
	);

	return date.isValid()
		? dateI18n(
				getDateSettings().formats.date,
				date.toISOString(),
				undefined
		  )
		: '-';
};

export const formatPayoutStatus = ( status: string ) =>
	status
		.replace( /_/g, ' ' )
		.replace( /^\w/, ( match ) => match.toUpperCase() );
