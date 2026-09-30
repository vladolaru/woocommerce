/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import moment from 'moment';

/**
 * Internal dependencies
 */
import { PayoutsOverviewCard } from '../overview/components/payouts-overview-card';
import type { WooPaymentsDepositsOverview } from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const TEST_LOCALE = 'woopayments-weekday-test';

const createOverview = (
	weeklyAnchor: string
): WooPaymentsDepositsOverview => ( {
	balance: {
		available: [ { amount: 1000, currency: 'usd' } ],
		pending: [ { amount: 0, currency: 'usd' } ],
		instant: [],
	},
	account: {
		default_currency: 'usd',
		deposits_enabled: true,
		deposits_blocked: false,
		deposits_schedule: {
			delay_days: 7,
			interval: 'weekly',
			weekly_anchor: weeklyAnchor,
		},
		completed_waiting_period: true,
		minimum_scheduled_deposit_amounts: { usd: 500 },
		default_external_accounts: [],
	},
	deposit: { last_paid: [] },
} );

// Client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:46-65`: the English anchor names a day, shown in moment's current locale.
describe( 'overview payout schedule weekday', () => {
	let previousLocale: string;

	beforeEach( () => {
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
		previousLocale = moment.locale();
		// WordPress core loads the site's weekday names into moment the same way (`moment.updateLocale()` in script-loader.php).
		moment.defineLocale( TEST_LOCALE, {
			parentLocale: 'en',
			weekdays: [
				'dimanche',
				'lundi',
				'mardi',
				'mercredi',
				'jeudi',
				'vendredi',
				'samedi',
			],
		} );
		moment.locale( TEST_LOCALE );
	} );

	afterEach( () => {
		moment.locale( previousLocale );
		moment.defineLocale( TEST_LOCALE, null );
	} );

	it( 'shows the weekly payout day in the site language', () => {
		const { container } = render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				overview={ createOverview( 'friday' ) }
				recentPayouts={ [] }
			/>
		);

		expect(
			container.querySelector(
				'.woocommerce-woopayments-overview__schedule'
			)
		).toHaveTextContent(
			'Available funds are automatically dispatched every vendredi.'
		);
	} );
} );
