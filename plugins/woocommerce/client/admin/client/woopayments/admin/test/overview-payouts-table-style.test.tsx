/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { PayoutsOverviewCard } from '../overview/components/payouts-overview-card';
import type { WooPaymentsDepositsOverview } from '../overview/types';
import { hasStyleRule } from './helpers/style-rules';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const isStyled = (
	element: { matches: ( selector: string ) => boolean },
	property: string,
	value: string,
	media = ''
) => hasStyleRule( element, 'style.scss', property, value, media );

const overview = {
	balance: {
		available: [ { amount: 1000, currency: 'usd' } ],
		pending: [ { amount: 0, currency: 'usd' } ],
		instant: [],
	},
	account: {
		default_currency: 'usd',
		deposits_schedule: { interval: 'daily' },
		default_external_accounts: [],
	},
	deposit: { last_paid: [] },
} as unknown as WooPaymentsDepositsOverview;

// Client 11.1.0 `components/deposits-overview/style.scss:41-44,98-102`: the calendar icon sits inline beside its date,
// and under 605px the date column takes 45%.
describe( 'Overview payout history table', () => {
	beforeEach( () => {
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				overview={ overview }
				recentPayouts={ [
					{
						id: 'po_test',
						date: 1781740800000,
						type: 'deposit',
						amount: 1000,
						status: 'paid',
						bankAccount: 'TEST BANK **** 1234 (USD)',
						currency: 'usd',
					},
				] }
				accountStatus={ {
					account_link: '',
					deposits: {
						restrictions: 'deposits_unrestricted',
						completed_waiting_period: true,
						minimum_scheduled_deposit_amounts: {},
					},
				} }
			/>
		);
	} );

	it( 'keeps the calendar icon on the line of its date', () => {
		const dateLink = screen.getByRole( 'link', { name: 'June 18, 2026' } );
		const wrapper = dateLink.parentElement;
		const icon = wrapper?.querySelector( 'svg' );

		if ( ! wrapper || ! icon ) {
			throw new Error( 'The date link has no wrapper with an icon.' );
		}

		expect( wrapper ).not.toBe( dateLink.closest( 'td' ) );
		expect( wrapper ).toContainElement( icon );
		expect( isStyled( wrapper, 'display', 'flex' ) ).toBe( true );
		expect( isStyled( wrapper, 'align-items', 'center' ) ).toBe( true );
		expect( isStyled( icon, 'flex-shrink', '0' ) ).toBe( true );
	} );

	it( 'widens the date column to 45% below 605px', () => {
		const dateHeader = screen.getByRole( 'columnheader', {
			name: 'Dispatch date',
		} );

		expect(
			isStyled( dateHeader, 'width', '45%', 'max-width: 605px' )
		).toBe( true );
	} );
} );
