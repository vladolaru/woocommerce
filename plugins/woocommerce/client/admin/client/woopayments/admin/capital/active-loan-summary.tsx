/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { WooPaymentsCapitalSummary } from './types';
import { formatWooPaymentsAmount } from '../overview/utils';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';

const getDateValue = ( value: string | number ): string | number => {
	if ( typeof value === 'number' ) {
		return value < 10000000000 ? value * 1000 : value;
	}

	const match = value
		.trim()
		.match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/ );

	if ( ! match ) {
		return value;
	}

	const [ , year, month, day, hour, minute, second ] = match;

	return Date.UTC(
		Number( year ),
		Number( month ) - 1,
		Number( day ),
		Number( hour ),
		Number( minute ),
		Number( second )
	);
};

export const formatCapitalDate = ( value?: string | number | null ) => {
	if ( ! value ) {
		return '-';
	}

	const date = new Date( getDateValue( value ) );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	return date.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
};

export const formatCapitalPercent = ( value: number ) =>
	`${ Number( ( value * 100 ).toFixed( 2 ) ) }%`;

export const getCapitalLoanTransactionsUrl = ( loanId: string ) =>
	getSettingsPaymentsProviderRouteUrl(
		`/woopayments/transactions?loan_id_is=${ encodeURIComponent( loanId ) }`
	);

/**
 * Get the active loan ID from the account's `<loan id>|<status>` loan strings.
 *
 * Client 11.1.0 `components/active-loan-summary/index.tsx:114-123`.
 *
 * @param loans Account loan strings.
 */
export const getActiveCapitalLoanId = ( loans: string[] = [] ) => {
	for ( const loan of loans ) {
		const [ loanId, status ] = loan.split( '|' );

		if ( status === 'active' && loanId ) {
			return loanId;
		}
	}

	return '';
};

/**
 * The active loan header and its seven summary fields, shared by Overview and Capital Loans.
 *
 * Client 11.1.0 `components/active-loan-summary/index.tsx:125-277`.
 *
 * @param props               Component props.
 * @param props.details       Active loan summary details.
 * @param props.activeLoanId  Active loan ID; links the loan's transactions when set.
 * @param props.headingLevel  Heading level for the section title.
 * @param props.baseClassName BEM block the header and summary classes hang off.
 */
export const ActiveLoanSummary = ( {
	details,
	activeLoanId = '',
	headingLevel = 3,
	baseClassName,
}: {
	details: NonNullable< WooPaymentsCapitalSummary[ 'details' ] >;
	activeLoanId?: string;
	headingLevel?: 2 | 3;
	baseClassName: string;
} ) => {
	const Heading = headingLevel === 2 ? 'h2' : 'h3';
	const totalDue = details.advance_amount + details.fee_amount;
	const periodDue =
		details.current_repayment_interval.paid_amount +
		details.current_repayment_interval.remaining_amount;

	return (
		<>
			<div className={ `${ baseClassName }__section-header` }>
				<Heading>
					{ __( 'Active loan overview', 'woocommerce' ) }
				</Heading>
				{ activeLoanId && (
					<a
						className={ `${ baseClassName }__view-transactions` }
						href={ getCapitalLoanTransactionsUrl( activeLoanId ) }
					>
						{ __( 'View transactions', 'woocommerce' ) }
					</a>
				) }
			</div>
			<dl className={ `${ baseClassName }__summary` }>
				<div>
					<dt>{ __( 'Total repaid', 'woocommerce' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: 1: paid amount, 2: total amount. */
							__( '%1$s of %2$s', 'woocommerce' ),
							formatWooPaymentsAmount(
								details.paid_amount,
								details.currency
							),
							formatWooPaymentsAmount(
								totalDue,
								details.currency
							)
						) }
					</dd>
				</div>
				<div>
					<dt>
						{ sprintf(
							/* translators: %s: repayment period due date. */
							__(
								'Repaid this period (until %s)',
								'woocommerce'
							),
							formatCapitalDate(
								details.current_repayment_interval.due_at
							)
						) }
					</dt>
					<dd>
						{ sprintf(
							/* translators: 1: paid amount, 2: total period amount. */
							__( '%1$s of %2$s minimum', 'woocommerce' ),
							formatWooPaymentsAmount(
								details.current_repayment_interval.paid_amount,
								details.currency
							),
							formatWooPaymentsAmount(
								periodDue,
								details.currency
							)
						) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Loan disbursed', 'woocommerce' ) }</dt>
					<dd>
						{ formatCapitalDate( details.advance_paid_out_at ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Loan amount', 'woocommerce' ) }</dt>
					<dd>
						{ formatWooPaymentsAmount(
							details.advance_amount,
							details.currency
						) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Fixed fee', 'woocommerce' ) }</dt>
					<dd>
						{ formatWooPaymentsAmount(
							details.fee_amount,
							details.currency
						) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Withhold rate', 'woocommerce' ) }</dt>
					<dd>{ formatCapitalPercent( details.withhold_rate ) }</dd>
				</div>
				<div>
					<dt>{ __( 'First paydown', 'woocommerce' ) }</dt>
					<dd>
						{ formatCapitalDate( details.repayments_begin_at ) }
					</dd>
				</div>
			</dl>
		</>
	);
};
