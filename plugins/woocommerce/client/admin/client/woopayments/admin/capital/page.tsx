/**
 * External dependencies
 */
import {
	EllipsisMenu,
	MenuItem,
	MenuTitle,
	Table,
} from '@woocommerce/components';
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
	formatCapitalPercent,
	getCapitalLoanTransactionsRoute,
	getCapitalLoanTransactionsUrl,
} from './active-loan-summary';
import {
	formatExplicitCurrency,
	formatSiteDateTime,
	getErrorMessage,
} from '../money-movement/utils';
import { usePersistedHiddenFields } from '../money-movement/view-preferences';
import { handleSettingsPaymentsProviderRouteClick } from '../utils';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import { StatusChip } from '../overview/components/status-chip';
import {
	LiveStatusMessage,
	reportListLoadError,
} from '../money-movement/table';
import { getWooPaymentsSettingsBootstrap } from '../../settings/bootstrap';

// Client 11.1.0 `capital/index.tsx:82-84, 118, 157`: loan dates in the site date format.
const getLoanStatus = ( loan: WooPaymentsCapitalLoan ) =>
	loan.fully_paid_at
		? sprintf(
				/* translators: %s: loan paid-off date. */
				__( 'Paid off: %s', 'woocommerce' ),
				formatSiteDateTime( loan.fully_paid_at, false )
			)
		: __( 'Active', 'woocommerce' );

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

	// Client 11.1.0 `capital/index.tsx` places the Overview's loan card on the page as it is.
	return (
		<ActiveLoanSummary
			details={ summary.details }
			activeLoanId={
				loans.find( ( loan ) => ! loan.fully_paid_at )?.stripe_loan_id
			}
			baseClassName="woocommerce-woopayments-capital__active-loan"
		/>
	);
};

const LoanStatusChip = ( { loan }: { loan: WooPaymentsCapitalLoan } ) => (
	<StatusChip
		message={ getLoanStatus( loan ) }
		type={ loan.fully_paid_at ? 'primary' : 'warning' }
	/>
);

const LoanActionLink = ( { loan }: { loan: WooPaymentsCapitalLoan } ) => (
	<a
		className="woocommerce-woopayments-capital__loan-action"
		href={ getCapitalLoanTransactionsUrl( loan.stripe_loan_id ) }
		onClick={ handleSettingsPaymentsProviderRouteClick(
			getCapitalLoanTransactionsRoute( loan.stripe_loan_id )
		) }
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

// Client 11.3.0 capital/index.tsx:34-77,212-237.
const loanColumns = [
	{
		key: 'paid_out_at',
		cellClassName: 'is-start-aligned',
		label: __( 'Disbursed', 'woocommerce' ),
		required: true,
		cell: ( loan: WooPaymentsCapitalLoan ) =>
			formatSiteDateTime( loan.paid_out_at, false ),
	},
	{
		key: 'status',
		cellClassName: 'is-start-aligned',
		label: __( 'Status', 'woocommerce' ),
		cell: ( loan: WooPaymentsCapitalLoan ) => (
			<LoanStatusChip loan={ loan } />
		),
	},
	{
		key: 'amount',
		label: __( 'Amount', 'woocommerce' ),
		required: true,
		cell: ( loan: WooPaymentsCapitalLoan ) =>
			formatExplicitCurrency( loan.amount, loan.currency ),
	},
	{
		key: 'fee_amount',
		label: __( 'Fixed fee', 'woocommerce' ),
		cell: ( loan: WooPaymentsCapitalLoan ) =>
			formatExplicitCurrency( loan.fee_amount, loan.currency ),
	},
	{
		key: 'withhold_rate',
		label: __( 'Withhold rate', 'woocommerce' ),
		cell: ( loan: WooPaymentsCapitalLoan ) =>
			formatCapitalPercent( loan.withhold_rate ),
	},
	{
		key: 'first_paydown_at',
		label: __( 'First paydown', 'woocommerce' ),
		cell: ( loan: WooPaymentsCapitalLoan ) =>
			formatSiteDateTime( loan.first_paydown_at, false ),
	},
	{
		key: 'actions',
		label: __( 'Actions', 'woocommerce' ),
		required: true,
		cell: ( loan: WooPaymentsCapitalLoan ) => (
			<LoanActionLink loan={ loan } />
		),
	},
];
const optionalColumns = loanColumns.filter( ( column ) => ! column.required );
const optionalKeys = optionalColumns.map( ( column ) => column.key );

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
					formatExplicitCurrency( totalAmount, currency )
				) }
			</span>
			<span>
				{ sprintf(
					/* translators: %s: formatted Capital loan fixed-fee total. */
					__( '%s fixed fees', 'woocommerce' ),
					formatExplicitCurrency( totalFees, currency )
				) }
			</span>
		</div>
	);
};

const hasActiveLoan = () =>
	(
		getWooPaymentsSettingsBootstrap().accountLoans as
			{ has_active_loan?: unknown } | undefined
	 )?.has_active_loan === true;

export const WooPaymentsCapitalPage = () => {
	const [ summary, setSummary ] = useState< WooPaymentsCapitalSummary >( {} );
	const [ loans, setLoans ] = useState< WooPaymentsCapitalLoan[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );

	const { visibleFields, saveFields } = usePersistedHiddenFields(
		'wc_payments_capital_loans_hidden_columns',
		optionalKeys
	);
	const visibleColumns = loanColumns.filter(
		( column ) => column.required || visibleFields.includes( column.key )
	);

	const toggleColumn = ( key: string ) => {
		saveFields(
			visibleFields.includes( key )
				? visibleFields.filter( ( field ) => field !== key )
				: [ ...visibleFields, key ]
		);
	};

	useEffect( () => {
		let isMounted = true;

		const loadCapitalData = async () => {
			// Client 11.1.0 mounts the active loan card, and its summary request, only for an account with an active loan.
			// Client 11.2.0 `capital/index.tsx:216-230`: the summary and the list load apart, so either can fail alone.
			const [ summaryResult, loansResult ] = await Promise.allSettled( [
				hasActiveLoan()
					? getWooPaymentsCapitalActiveLoanSummary()
					: Promise.resolve( {} as WooPaymentsCapitalSummary ),
				getWooPaymentsCapitalLoans(),
			] );

			if ( ! isMounted ) {
				return;
			}

			if ( summaryResult.status === 'fulfilled' ) {
				setSummary( summaryResult.value );
			} else {
				// Client 11.2.0 `data/capital/resolvers.ts:31-40`.
				reportListLoadError(
					__(
						'Error retrieving the active loan summary.',
						'woocommerce'
					)
				);
			}

			if ( loansResult.status === 'fulfilled' ) {
				setLoans( loansResult.value );
				setErrorMessage( null );
			} else {
				setLoans( [] );
				setErrorMessage(
					getErrorMessage(
						loansResult.reason,
						__(
							'Unable to load WooPayments Capital Loans.',
							'woocommerce'
						)
					)
				);
			}

			setIsLoading( false );
		};

		void loadCapitalData();

		return () => {
			isMounted = false;
		};
	}, [] );

	const hasLoans = ! isLoading && ! errorMessage && loans.length > 0;
	const statusMessage = isLoading
		? __( 'Loading Capital Loans…', 'woocommerce' )
		: errorMessage ||
			( loans.length === 0
				? __( 'No Capital loans found.', 'woocommerce' )
				: __( 'Capital Loans loaded.', 'woocommerce' ) );

	return (
		<div className="woocommerce-woopayments-capital">
			<WooPaymentsTestModeNotice currentPage="loans" />
			<section
				className="woocommerce-woopayments-capital__section"
				aria-busy={ isLoading }
			>
				<h2>{ __( 'Capital Loans', 'woocommerce' ) }</h2>
				<LiveStatusMessage
					isError={ !! errorMessage }
					className={
						hasLoans
							? 'screen-reader-text'
							: 'woocommerce-woopayments-capital__status'
					}
				>
					{ statusMessage }
				</LiveStatusMessage>
			</section>
			{ summary.details && ! errorMessage && (
				<CapitalActiveLoanSummary summary={ summary } loans={ loans } />
			) }
			{ hasLoans && (
				<section className="woocommerce-woopayments-capital__section">
					<div className="woocommerce-woopayments-capital__table-heading">
						<h3 id="woocommerce-woopayments-all-loans-heading">
							{ __( 'All loans', 'woocommerce' ) }
						</h3>
						<EllipsisMenu
							label={ __(
								'Choose which values to display',
								'woocommerce'
							) }
							placement="bottom-end"
							renderContent={ () => (
								<>
									<MenuTitle>
										{ __( 'Columns:', 'woocommerce' ) }
									</MenuTitle>
									{ optionalColumns.map(
										( { key, label } ) => (
											<MenuItem
												key={ key }
												checked={ visibleFields.includes(
													key
												) }
												isCheckbox
												isClickable
												onInvoke={ () =>
													toggleColumn( key )
												}
											>
												{ label }
											</MenuItem>
										)
									) }
								</>
							) }
						/>
					</div>
					<LoanListSummary loans={ loans } />
					<div className="woocommerce-woopayments-capital__table">
						<Table
							className="woocommerce-woopayments-capital__table-scroll"
							caption={ __( 'All loans', 'woocommerce' ) }
							headers={ visibleColumns }
							rows={ loans.map( ( loan ) =>
								visibleColumns.map( ( { cell } ) => ( {
									display: cell( loan ),
								} ) )
							) }
						/>
					</div>
				</section>
			) }
		</div>
	);
};

export default WooPaymentsCapitalPage;
