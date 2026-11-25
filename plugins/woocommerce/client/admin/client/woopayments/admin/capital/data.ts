/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { fetchSummary } from '../money-movement/utils';
import type {
	WooPaymentsCapitalLoan,
	WooPaymentsCapitalLoansResponse,
	WooPaymentsCapitalSummary,
} from './types';

const CAPITAL_PATH = '/wc/v3/payments/capital';

export const getWooPaymentsCapitalActiveLoanSummary =
	(): Promise< WooPaymentsCapitalSummary > =>
		fetchSummary< WooPaymentsCapitalSummary >(
			`${ CAPITAL_PATH }/active_loan_summary`
		);

export const getWooPaymentsCapitalLoans = async (): Promise<
	WooPaymentsCapitalLoan[]
> => {
	const response = await apiFetch<
		WooPaymentsCapitalLoansResponse | WooPaymentsCapitalLoan[]
	>( {
		path: `${ CAPITAL_PATH }/loans`,
		method: 'GET',
	} );

	return Array.isArray( response ) ? response : response.data || [];
};
