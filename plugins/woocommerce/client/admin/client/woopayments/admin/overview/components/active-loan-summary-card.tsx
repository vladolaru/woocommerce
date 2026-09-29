/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getWooPaymentsCapitalActiveLoanSummary } from '../../capital/data';
import type { WooPaymentsCapitalSummary } from '../../capital/types';
import {
	ActiveLoanSummary,
	getActiveCapitalLoanId,
} from '../../capital/active-loan-summary';

export const ActiveLoanSummaryCard = ( {
	hasActiveLoan,
	loans = [],
}: {
	hasActiveLoan?: boolean;
	loans?: string[];
} ) => {
	const [ summary, setSummary ] =
		useState< WooPaymentsCapitalSummary | null >( null );

	useEffect( () => {
		let isMounted = true;

		if ( ! hasActiveLoan ) {
			setSummary( null );
			return () => {
				isMounted = false;
			};
		}

		getWooPaymentsCapitalActiveLoanSummary()
			.then( ( nextSummary ) => {
				if ( isMounted ) {
					setSummary( nextSummary );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setSummary( null );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ hasActiveLoan ] );

	if ( ! hasActiveLoan || ! summary?.details ) {
		return null;
	}

	return (
		<section className="woocommerce-woopayments-overview-card woocommerce-woopayments-active-loan">
			<ActiveLoanSummary
				details={ summary.details }
				activeLoanId={ getActiveCapitalLoanId( loans ) }
				headingLevel={ 2 }
				baseClassName="woocommerce-woopayments-active-loan"
			/>
		</section>
	);
};
