/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import {
	ActiveLoanSummary,
	getActiveCapitalLoanId,
} from '../capital/active-loan-summary';
import { hasStyleRule } from './helpers/style-rules';

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

const formatDate = ( timestamp: number ) =>
	new Date( timestamp * 1000 ).toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );

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
		expect(
			getValue(
				`Repaid this period (until ${ formatDate( 1644889167 ) })`
			)
		).toBe( '$1.23 of $24.68 minimum' );
		expect( getValue( 'Loan disbursed' ) ).toBe( formatDate( 1643889167 ) );
		expect( getValue( 'Loan amount' ) ).toBe( '$1,000.00' );
		expect( getValue( 'Fixed fee' ) ).toBe( '$150.00' );
		expect( getValue( 'Withhold rate' ) ).toBe( '10%' );
		expect( getValue( 'First paydown' ) ).toBe( formatDate( 1643999167 ) );
	} );

	// Client 11.1.0 `components/active-loan-summary/index.tsx:130-275` and `style.scss`: an Overview card whose header
	// holds the title and the transactions link, then a row of two blocks and a row of five, split by dividers.
	it( 'renders the client card with a header and divided rows', () => {
		render(
			<ActiveLoanSummary
				details={ details }
				activeLoanId="flxln_123456"
				headingLevel={ 2 }
			/>
		);

		const heading = screen.getByRole( 'heading', {
			level: 2,
			name: 'Active loan overview',
		} );
		const header = heading.closest( '.components-card__header' );
		const transactionsLink = screen.getByRole( 'link', {
			name: 'View transactions',
		} );
		expect( header ).toContainElement( transactionsLink );
		expect( transactionsLink ).toHaveClass( 'is-link' );
		expect(
			screen.getByRole( 'region', { name: 'Active loan overview' } )
		).toHaveClass( 'components-card' );

		const rows = Array.from(
			document.querySelectorAll(
				'.woocommerce-woopayments-loan-summary__row'
			)
		);
		const termsOf = ( index: number ) =>
			Array.from( rows[ index ].querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			);
		expect( rows ).toHaveLength( 2 );
		expect( termsOf( 0 ) ).toEqual( [
			'Total repaid',
			`Repaid this period (until ${ formatDate( 1644889167 ) })`,
		] );
		expect( termsOf( 1 ) ).toEqual( [
			'Loan disbursed',
			'Loan amount',
			'Fixed fee',
			'Withhold rate',
			'First paydown',
		] );
		// The repaid amounts are the large figures.
		expect( screen.getByText( '$12.34' ) ).toHaveClass( 'is-big' );
		expect( screen.getByText( '$1.23' ) ).toHaveClass( 'is-big' );

		const [ firstBlock, secondBlock ] = Array.from( rows[ 1 ].children );
		expect(
			hasStyleRule(
				secondBlock,
				'capital/active-loan-summary.scss',
				'border-inline-start',
				'1px solid #e0e0e0'
			)
		).toBe( true );
		expect(
			hasStyleRule(
				firstBlock,
				'capital/active-loan-summary.scss',
				'border-inline-start',
				'1px solid #e0e0e0'
			)
		).toBe( false );
		expect(
			hasStyleRule(
				rows[ 1 ],
				'capital/active-loan-summary.scss',
				'border-top',
				'1px solid #e0e0e0'
			)
		).toBe( true );
		// Five blocks do not fit a phone, so under 600px each row stacks its blocks, divided by top borders.
		expect(
			hasStyleRule(
				rows[ 1 ],
				'capital/active-loan-summary.scss',
				'flex-direction',
				'column',
				'max-width: 600px'
			)
		).toBe( true );
		expect(
			hasStyleRule(
				secondBlock,
				'capital/active-loan-summary.scss',
				'border-top',
				'1px solid #e0e0e0',
				'max-width: 600px'
			)
		).toBe( true );
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
