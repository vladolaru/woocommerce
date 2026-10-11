/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import apiFetch from '@wordpress/api-fetch';
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { PayoutBankAccount } from '../payout-bank-account';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const mockApiFetch = apiFetch as unknown as jest.Mock;

const readRecordedResponse = ( file: string ) =>
	JSON.parse(
		fs.readFileSync(
			path.join( __dirname, '../../admin/test/fixtures', file ),
			'utf8'
		)
	).response;

// Recorded native :8889 responses; see each file's `_meta`. The recorded account is a test drive, so its shell has no account link.
const RECORDED_SHELL = readRecordedResponse( 'recorded-overview-shell.json' );
const RECORDED_OVERVIEW = readRecordedResponse(
	'recorded-deposits-overview-all.json'
);

// The nonce-protected dashboard login the shell sends for an account that is not a test drive.
const ACCOUNT_LINK =
	'https://store.example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview&wcpay-login=1&_wpnonce=abc123';

const mockResponses = ( {
	accountLink = ACCOUNT_LINK,
	externalAccountStatus = 'new',
	overviewAccount = {},
}: {
	accountLink?: string;
	externalAccountStatus?: string;
	overviewAccount?: Record< string, unknown >;
} = {} ) =>
	mockApiFetch.mockImplementation( ( { path: requestPath } ) => {
		if (
			requestPath === '/wc-admin/settings/payments/woopayments/overview'
		) {
			return Promise.resolve( {
				...RECORDED_SHELL,
				account_status: {
					...RECORDED_SHELL.account_status,
					account_link: accountLink,
				},
			} );
		}

		if ( requestPath === '/wc/v3/payments/deposits/overview-all' ) {
			return Promise.resolve( {
				...RECORDED_OVERVIEW,
				account: {
					...RECORDED_OVERVIEW.account,
					default_external_accounts: [
						{ currency: 'usd', status: externalAccountStatus },
					],
					...overviewAccount,
				},
			} );
		}

		return Promise.reject( new Error( `Unexpected ${ requestPath }` ) );
	} );

// Client 11.1.0 `settings/deposits/index.js:209-257` reads `accountStatus.accountLink` from `wcpaySettings` (`settings/index.js:26`),
// which `class-wc-payments-account.php:382` fills; the native Overview shell carries it as `account_status.account_link`.
describe( 'PayoutBankAccount', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
	} );

	it( 'links Manage in Stripe to the account link from the shell', async () => {
		mockResponses();

		render( <PayoutBankAccount /> );

		expect(
			await screen.findByRole( 'link', { name: /^Manage in Stripe/ } )
		).toHaveAttribute( 'href', ACCOUNT_LINK );
	} );

	it( 'ignores an account link on the payouts overview response, which the platform never sends', async () => {
		mockResponses( {
			accountLink: '',
			overviewAccount: { account_link: 'https://stale.example/link' },
		} );

		render( <PayoutBankAccount /> );

		expect(
			await screen.findByText(
				'Manage and update your bank account information to receive payouts.'
			)
		).toBeInTheDocument();
		// Let both requests settle before checking that no link appeared.
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect(
			screen.queryByRole( 'link', { name: /^Manage in Stripe/ } )
		).not.toBeInTheDocument();
	} );

	it( 'links the failed-payout notice to the shell account link in a new tab', async () => {
		mockResponses( { externalAccountStatus: 'errored' } );

		render( <PayoutBankAccount /> );

		const updateLink = await screen.findByRole( 'link', {
			name: /^update your bank account details/,
		} );
		// Client 11.1.0 `components/deposits-overview/deposit-notices.tsx:183-216`.
		const href = new URL( updateLink.getAttribute( 'href' ) ?? '' );
		expect( Object.fromEntries( href.searchParams ) ).toEqual( {
			page: 'wc-settings',
			tab: 'checkout',
			path: '/woopayments/overview',
			'wcpay-login': '1',
			_wpnonce: 'abc123',
			from: 'WCPAY_PAYOUTS',
			source: 'wcpay-payout-failure-notice',
		} );
		expect( updateLink ).toHaveAttribute( 'target', '_blank' );
	} );
} );
