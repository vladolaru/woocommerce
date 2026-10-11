/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsCapitalSummary } from './types';
import {
	formatExplicitCurrency,
	formatSiteDateTime,
} from '../money-movement/utils';
import {
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../utils';
import './active-loan-summary.scss';

export const formatCapitalPercent = ( value: number ) =>
	`${ Number( ( value * 100 ).toFixed( 2 ) ) }%`;

/**
 * The transactions list filtered by a loan, with the advanced filters open so the merchant sees and can clear the loan
 * filter, like client 11.1.0 (`capital/index.tsx:102-108`, `components/active-loan-summary/index.tsx:140-146`).
 *
 * @param loanId The Stripe loan ID.
 */
export const getCapitalLoanTransactionsRoute = ( loanId: string ) =>
	`/woopayments/transactions?type=charge&filter=advanced&loan_id_is=${ encodeURIComponent(
		loanId
	) }`;

export const getCapitalLoanTransactionsUrl = ( loanId: string ) =>
	getSettingsPaymentsProviderRouteUrl(
		getCapitalLoanTransactionsRoute( loanId )
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

// Client 11.1.0 `components/active-loan-summary/index.tsx:27-38`: a title over its value; the page lists them as terms.
const Block = ( {
	title,
	children,
}: {
	title: ReactNode;
	children: ReactNode;
} ) => (
	<FlexBlock className="woocommerce-woopayments-loan-summary__block">
		<dt>{ title }</dt>
		<dd>{ children }</dd>
	</FlexBlock>
);

// Client 11.1.0 `<big>%s</big> of %s`: the repaid amount is the large figure.
const withBigAmount = ( text: string ) =>
	createInterpolateElement( text, {
		big: <span className="is-big" />,
	} );

/**
 * The active loan card, shared by Overview and Capital Loans.
 *
 * Client 11.1.0 `components/active-loan-summary/index.tsx:125-277`: a header with the title and the transactions link,
 * then the two repaid figures in one row and the five loan facts in another.
 *
 * @param props               Component props.
 * @param props.details       Active loan summary details.
 * @param props.activeLoanId  Active loan ID; links the loan's transactions when set.
 * @param props.headingLevel  Heading level for the card title.
 * @param props.baseClassName Extra class for the card, naming the page it sits on.
 */
export const ActiveLoanSummary = ( {
	details,
	activeLoanId = '',
	headingLevel = 3,
	baseClassName = '',
}: {
	details: NonNullable< WooPaymentsCapitalSummary[ 'details' ] >;
	activeLoanId?: string;
	headingLevel?: 2 | 3;
	baseClassName?: string;
} ) => {
	const Heading = headingLevel === 2 ? 'h2' : 'h3';
	const headingId = useInstanceId(
		ActiveLoanSummary,
		'woocommerce-woopayments-loan-summary-heading'
	);
	const totalDue = details.advance_amount + details.fee_amount;
	const periodDue =
		details.current_repayment_interval.paid_amount +
		details.current_repayment_interval.remaining_amount;

	return (
		<Card
			as="section"
			className={ [
				'woocommerce-woopayments-loan-summary',
				baseClassName,
			]
				.filter( Boolean )
				.join( ' ' ) }
			aria-labelledby={ headingId }
		>
			<CardHeader className="woocommerce-woopayments-loan-summary__header">
				<Heading
					id={ headingId }
					className="woocommerce-woopayments-overview-card__title"
				>
					{ __( 'Active loan overview', 'woocommerce' ) }
				</Heading>
				{ activeLoanId && (
					<Button
						variant="link"
						href={ getCapitalLoanTransactionsUrl( activeLoanId ) }
						onClick={ handleSettingsPaymentsProviderRouteClick(
							getCapitalLoanTransactionsRoute( activeLoanId )
						) }
						__next40pxDefaultSize
					>
						{ __( 'View transactions', 'woocommerce' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody className="woocommerce-woopayments-loan-summary__body">
				<Flex
					as="dl"
					align="normal"
					className="woocommerce-woopayments-loan-summary__row"
				>
					<Block title={ __( 'Total repaid', 'woocommerce' ) }>
						{ withBigAmount(
							sprintf(
								/* translators: 1: paid amount, 2: total amount. */
								__( '<big>%1$s</big> of %2$s', 'woocommerce' ),
								formatExplicitCurrency(
									details.paid_amount,
									details.currency
								),
								formatExplicitCurrency(
									totalDue,
									details.currency
								)
							)
						) }
					</Block>
					<Block
						title={ sprintf(
							/* translators: %s: repayment period due date. */
							__(
								'Repaid this period (until %s)',
								'woocommerce'
							),
							// Client 11.1.0 `components/active-loan-summary/index.tsx:206-208, 243-271`: the site date format.
							formatSiteDateTime(
								details.current_repayment_interval.due_at,
								false
							)
						) }
					>
						{ withBigAmount(
							sprintf(
								/* translators: 1: paid amount, 2: total period amount. */
								__(
									'<big>%1$s</big> of %2$s minimum',
									'woocommerce'
								),
								formatExplicitCurrency(
									details.current_repayment_interval
										.paid_amount,
									details.currency
								),
								formatExplicitCurrency(
									periodDue,
									details.currency
								)
							)
						) }
					</Block>
				</Flex>
				<Flex
					as="dl"
					align="normal"
					className="woocommerce-woopayments-loan-summary__row is-bottom-row"
				>
					<Block title={ __( 'Loan disbursed', 'woocommerce' ) }>
						{ formatSiteDateTime(
							details.advance_paid_out_at,
							false
						) }
					</Block>
					<Block title={ __( 'Loan amount', 'woocommerce' ) }>
						{ formatExplicitCurrency(
							details.advance_amount,
							details.currency
						) }
					</Block>
					<Block title={ __( 'Fixed fee', 'woocommerce' ) }>
						{ formatExplicitCurrency(
							details.fee_amount,
							details.currency
						) }
					</Block>
					<Block title={ __( 'Withhold rate', 'woocommerce' ) }>
						{ formatCapitalPercent( details.withhold_rate ) }
					</Block>
					<Block title={ __( 'First paydown', 'woocommerce' ) }>
						{ formatSiteDateTime(
							details.repayments_begin_at,
							false
						) }
					</Block>
				</Flex>
			</CardBody>
		</Card>
	);
};
