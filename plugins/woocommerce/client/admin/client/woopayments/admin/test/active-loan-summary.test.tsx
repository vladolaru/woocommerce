/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import {
	ActiveLoanSummary,
	getActiveCapitalLoanId,
} from '../capital/active-loan-summary';

// Client 11.1.0 `components/active-loan-summary/__tests__/index.test.js` fixture, with the platform's fractional withhold rate.
const details = {
	advance_amount: 100000,
	advance_paid_out_at: 1643889167,
	currency: 'usd',
	current_repayment_interval: {
		due_at: 1644889167,
		paid_amount: 123,
		remaining_amount: 2345,
	},
	fee_amount: 15000,
	paid_amount: 1234,
	remaining_amount: 9876,
	repayments_begin_at: 1643999167,
	withhold_rate: 0.1,
};

// Client 11.1.0 `components/active-loan-summary/index.tsx` formats these in the site date format (default "F j, Y").
const DUE_AT = 'February 15, 2022';

const getValue = ( term: string | RegExp ) =>
	screen.getByText( term ).closest( 'div' )?.querySelector( 'dd' )
		?.textContent;

describe( 'ActiveLoanSummary', () => {
	beforeEach( () => {
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
	} );

	it( 'renders the seven client summary fields', () => {
		render(
			<ActiveLoanSummary
				details={ details }
				activeLoanId="flxln_123456"
				headingLevel={ 2 }
			/>
		);

		expect(
			screen.getByRole( 'heading', {
				level: 2,
				name: 'Active loan overview',
			} )
		).toBeInTheDocument();
		expect( getValue( 'Total repaid' ) ).toBe( '$12.34 of $1,150.00' );
		expect( getValue( `Repaid this period (until ${ DUE_AT })` ) ).toBe(
			'$1.23 of $24.68 minimum'
		);
		expect( getValue( 'Loan disbursed' ) ).toBe( 'February 3, 2022' );
		expect( getValue( 'Loan amount' ) ).toBe( '$1,000.00' );
		expect( getValue( 'Fixed fee' ) ).toBe( '$150.00' );
		expect( getValue( 'Withhold rate' ) ).toBe( '10%' );
		expect( getValue( 'First paydown' ) ).toBe( 'February 4, 2022' );
	} );

	// Client 11.1.0 `components/active-loan-summary/index.tsx:130-275`: the card's header holds the title and the
	// transactions link, then the repaid figures come before the loan terms.
	it( 'shows the title, the transactions link and the figures in the client order', () => {
		render(
			<ActiveLoanSummary
				details={ details }
				activeLoanId="flxln_123456"
				headingLevel={ 2 }
			/>
		);

		const card = screen.getByRole( 'region', {
			name: 'Active loan overview',
		} );
		expect(
			within( card ).getByRole( 'heading', {
				level: 2,
				name: 'Active loan overview',
			} )
		).toBeInTheDocument();
		expect(
			within( card ).getByRole( 'link', { name: 'View transactions' } )
		).toBeInTheDocument();
		expect(
			Array.from(
				card.querySelectorAll( 'dt' ),
				( term ) => term.textContent
			)
		).toEqual( [
			'Total repaid',
			`Repaid this period (until ${ DUE_AT })`,
			'Loan disbursed',
			'Loan amount',
			'Fixed fee',
			'Withhold rate',
			'First paydown',
		] );
	} );

	it( 'links the active loan transactions', () => {
		render(
			<ActiveLoanSummary
				details={ details }
				activeLoanId="flxln_123456"
			/>
		);

		expect(
			screen.getByRole( 'link', { name: 'View transactions' } )
		).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&loan_id_is=flxln_123456'
		);
	} );

	it( 'omits the transactions link without an active loan ID', () => {
		render( <ActiveLoanSummary details={ details } /> );

		expect(
			screen.queryByRole( 'link', { name: 'View transactions' } )
		).not.toBeInTheDocument();
	} );

	it( 'reads the active loan ID from the account loan strings', () => {
		expect(
			getActiveCapitalLoanId( [
				'flxln_paid|paid',
				'flxln_123456|active',
				'flxln_other|active',
			] )
		).toBe( 'flxln_123456' );
		expect( getActiveCapitalLoanId( [ 'flxln_paid|paid' ] ) ).toBe( '' );
		expect( getActiveCapitalLoanId() ).toBe( '' );
	} );
} );
