/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsCapitalActiveLoanSummary,
	getWooPaymentsCapitalLoans,
} from './data';
import type {
	WooPaymentsCapitalLoan,
	WooPaymentsCapitalSummary,
} from './types';
import {
	ActiveLoanSummary,
	formatCapitalDate,
	formatCapitalPercent,
	getCapitalLoanTransactionsUrl,
} from './active-loan-summary';
import { formatWooPaymentsAmount } from '../overview/utils';
import { getErrorMessage } from '../money-movement/utils';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';

const getLoanStatus = ( loan: WooPaymentsCapitalLoan ) =>
	loan.fully_paid_at
		? sprintf(
				/* translators: %s: loan paid-off date. */
				__( 'Paid off: %s', 'woocommerce' ),
				formatCapitalDate( loan.fully_paid_at )
		  )
		: __( 'Active', 'woocommerce' );

const getLoanStatusClassName = ( loan: WooPaymentsCapitalLoan ) =>
	loan.fully_paid_at
		? 'woocommerce-woopayments-capital__status-chip is-paid-off'
		: 'woocommerce-woopayments-capital__status-chip is-active';

const CapitalActiveLoanSummary = ( {
	summary,
	loans,
}: {
	summary: WooPaymentsCapitalSummary;
	loans: WooPaymentsCapitalLoan[];
} ) => {
	if ( ! summary.details ) {
		return null;
	}

	return (
		<section className="woocommerce-woopayments-capital__section">
			<ActiveLoanSummary
				details={ summary.details }
				activeLoanId={
					loans.find( ( loan ) => ! loan.fully_paid_at )
						?.stripe_loan_id
				}
				baseClassName="woocommerce-woopayments-capital"
			/>
		</section>
	);
};

const LoanStatusChip = ( { loan }: { loan: WooPaymentsCapitalLoan } ) => (
	<span className={ getLoanStatusClassName( loan ) }>
		{ getLoanStatus( loan ) }
	</span>
);

const getLoanTransactionsUrl = ( loan: WooPaymentsCapitalLoan ) =>
	getCapitalLoanTransactionsUrl( loan.stripe_loan_id );

const LoanActionLink = ( { loan }: { loan: WooPaymentsCapitalLoan } ) => (
	<a
		className="woocommerce-woopayments-capital__loan-action"
		href={ getLoanTransactionsUrl( loan ) }
	>
		{ __( 'View transactions', 'woocommerce' ) }
		<span className="screen-reader-text">
			{ sprintf(
				/* translators: %s: loan ID. */
				__( 'for loan %s', 'woocommerce' ),
				loan.stripe_loan_id
			) }
		</span>
	</a>
);

const LoanListSummary = ( { loans }: { loans: WooPaymentsCapitalLoan[] } ) => {
	if ( loans.length === 0 ) {
		return null;
	}

	const currencies = Array.from(
		new Set( loans.map( ( loan ) => loan.currency ) )
	);
	const loanCount = sprintf(
		/* translators: %d: number of Capital loans. */
		_n( '%d loan', '%d loans', loans.length, 'woocommerce' ),
		loans.length
	);

	if ( currencies.length !== 1 ) {
		return (
			<div className="woocommerce-woopayments-capital__list-summary">
				<span>{ loanCount }</span>
			</div>
		);
	}

	const currency = currencies[ 0 ];
	const totalAmount = loans.reduce(
		( total, loan ) => total + loan.amount,
		0
	);
	const totalFees = loans.reduce(
		( total, loan ) => total + loan.fee_amount,
		0
	);

	return (
		<div className="woocommerce-woopayments-capital__list-summary">
			<span>{ loanCount }</span>
			<span>
				{ sprintf(
					/* translators: %s: formatted Capital loan total amount. */
					__( '%s total', 'woocommerce' ),
					formatWooPaymentsAmount( totalAmount, currency )
				) }
			</span>
			<span>
				{ sprintf(
					/* translators: %s: formatted Capital loan fixed-fee total. */
					__( '%s fixed fees', 'woocommerce' ),
					formatWooPaymentsAmount( totalFees, currency )
				) }
			</span>
		</div>
	);
};

export const WooPaymentsCapitalPage = () => {
	const [ summary, setSummary ] = useState< WooPaymentsCapitalSummary >( {} );
	const [ loans, setLoans ] = useState< WooPaymentsCapitalLoan[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );

	useEffect( () => {
		let isMounted = true;

		const loadCapitalData = async () => {
			setIsLoading( true );

			try {
				const [ nextSummary, nextLoans ] = await Promise.all( [
					getWooPaymentsCapitalActiveLoanSummary(),
					getWooPaymentsCapitalLoans(),
				] );

				if ( ! isMounted ) {
					return;
				}

				setSummary( nextSummary );
				setLoans( nextLoans );
				setErrorMessage( null );
			} catch ( error ) {
				if ( isMounted ) {
					setLoans( [] );
					setErrorMessage(
						getErrorMessage(
							error,
							__(
								'Unable to load WooPayments Capital Loans.',
								'woocommerce'
							)
						)
					);
				}
			} finally {
				if ( isMounted ) {
					setIsLoading( false );
				}
			}
		};

		void loadCapitalData();

		return () => {
			isMounted = false;
		};
	}, [] );

	const hasLoans = ! isLoading && ! errorMessage && loans.length > 0;
	const statusMessage =
		( isLoading && __( 'Loading Capital Loans…', 'woocommerce' ) ) ||
		errorMessage ||
		( ! isLoading &&
			! errorMessage &&
			loans.length === 0 &&
			__( 'No Capital loans found.', 'woocommerce' ) ) ||
		( hasLoans && __( 'Capital Loans loaded.', 'woocommerce' ) ) ||
		'';

	return (
		<div className="woocommerce-woopayments-capital">
			<WooPaymentsTestModeNotice currentPage="loans" />
			<section
				className="woocommerce-woopayments-capital__section"
				aria-busy={ isLoading }
			>
				<h2>{ __( 'Capital Loans', 'woocommerce' ) }</h2>
				<p
					className={
						hasLoans
							? 'screen-reader-text'
							: 'woocommerce-woopayments-capital__status'
					}
					role={ errorMessage ? 'alert' : 'status' }
					aria-live={ errorMessage ? 'assertive' : 'polite' }
				>
					{ statusMessage }
				</p>
			</section>
			{ summary.details && ! errorMessage && (
				<CapitalActiveLoanSummary summary={ summary } loans={ loans } />
			) }
			{ hasLoans && (
				<section className="woocommerce-woopayments-capital__section">
					<h3>{ __( 'All loans', 'woocommerce' ) }</h3>
					<LoanListSummary loans={ loans } />
					<table className="woocommerce-woopayments-capital__table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Disbursed', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'Amount', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'Fixed fee', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'Withhold rate', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'First paydown', 'woocommerce' ) }
								</th>
								<th scope="col">
									{ __( 'Actions', 'woocommerce' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ loans.map( ( loan ) => (
								<tr key={ loan.stripe_loan_id }>
									<td>
										{ formatCapitalDate(
											loan.paid_out_at
										) }
									</td>
									<td>
										<LoanStatusChip loan={ loan } />
									</td>
									<td>
										{ formatWooPaymentsAmount(
											loan.amount,
											loan.currency
										) }
									</td>
									<td>
										{ formatWooPaymentsAmount(
											loan.fee_amount,
											loan.currency
										) }
									</td>
									<td>
										{ formatCapitalPercent(
											loan.withhold_rate
										) }
									</td>
									<td>
										{ formatCapitalDate(
											loan.first_paydown_at
										) }
									</td>
									<td>
										<LoanActionLink loan={ loan } />
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
			) }
		</div>
	);
};

export default WooPaymentsCapitalPage;
