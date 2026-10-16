/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { formatAmount, formatExplicitCurrency } from '../currency';
import { formatWooPaymentsAmount } from '../overview/utils';
import { getTransactionListFields } from '../money-movement/transactions-list-fields';
import type { WooPaymentsTransaction } from '../money-movement/types';

// Client 11.1.0 `multi-currency/client/utils/currency/index.js:156-200` `formatCurrency()`: the minus sign only for
// `amount < 0`, so a zero amount, negated or not, reads "$0.00".
describe( 'WooPayments amount formatting', () => {
	it.each( [
		[ 'zero', 0, '$0.00' ],
		[ 'negated zero', -0, '$0.00' ],
		[ 'a negative amount', -70, '-$0.70' ],
		[ 'a positive amount', 70, '$0.70' ],
	] )( 'formats %s like the client', ( _case, amount, expected ) => {
		expect( formatWooPaymentsAmount( amount, 'usd' ) ).toBe( expected );
		expect( formatAmount( amount, 'usd' ) ).toBe( expected );
	} );

	it( 'keeps the minus sign off a negated zero with an explicit currency code', () => {
		expect( formatExplicitCurrency( -0, 'usd', false, true ) ).toBe(
			'$0.00 USD'
		);
	} );

	it( 'shows a zero fee on a transactions row as $0.00', () => {
		const field = getTransactionListFields( {
			includeDeposit: true,
			includeSubscription: false,
			includeFilters: true,
		} ).find( ( candidate ) => candidate.id === 'fees' );
		const item = {
			transaction_id: 'txn_refund',
			type: 'refund',
			amount: -1099,
			fees: 0,
			net: -1099,
			currency: 'usd',
		} as WooPaymentsTransaction;

		const { container } = render(
			<div>{ field?.render( { item } as never ) }</div>
		);

		expect( container ).toHaveTextContent( /^\$0\.00$/ );
	} );
} );
