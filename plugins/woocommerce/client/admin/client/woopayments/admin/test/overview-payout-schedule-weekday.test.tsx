/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render } from '@testing-library/react';
import moment from 'moment';

/**
 * Internal dependencies
 */
import { PayoutsOverviewCard } from '../overview/components/payouts-overview-card';
import type {
	WooPaymentsDepositsOverview,
	WooPaymentsOverviewAccountStatus,
} from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const TEST_LOCALE = 'woopayments-weekday-test';

const readRecordedResponse = ( file: string ) =>
	JSON.parse(
		fs.readFileSync( path.join( __dirname, 'fixtures', file ), 'utf8' )
	).response;

// Recorded native :8889 responses; see each file's `_meta`.
const RECORDED_OVERVIEW = readRecordedResponse(
	'recorded-deposits-overview-all.json'
) as WooPaymentsDepositsOverview;
const RECORDED_ACCOUNT_STATUS = (
	readRecordedResponse( 'recorded-overview-shell.json' ) as {
		account_status: WooPaymentsOverviewAccountStatus;
	}
 ).account_status;

// The recorded account switched to weekly payouts on the given day.
const createOverview = (
	weeklyAnchor: string
): WooPaymentsDepositsOverview => ( {
	...RECORDED_OVERVIEW,
	account: {
		...RECORDED_OVERVIEW.account,
		deposits_schedule: {
			delay_days: 7,
			interval: 'weekly',
			weekly_anchor: weeklyAnchor,
		},
	},
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
				overview={ createOverview( 'friday' ) }
				accountStatus={ RECORDED_ACCOUNT_STATUS }
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
