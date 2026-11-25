/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	WooPaymentsTestModeNotice,
	type WooPaymentsTestModeNoticePage,
} from '../test-mode-notice';
import { mockAccountMode } from './helpers/test-mode-account';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const SETTINGS_URL =
	'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings';
const TEST_ACCOUNTS_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/';

const mockMode = ( {
	testMode,
	devMode = false,
}: {
	testMode: boolean;
	devMode?: boolean;
} ) => mockAccountMode( testMode, devMode );

const getNotice = () =>
	document.querySelector(
		'.woocommerce-woopayments-test-mode-notice'
	) as Element;

// Expected copy: client 11.1.0 components/test-mode-notice/__tests__/__snapshots__/index.test.tsx.snap.
describe( 'WooPaymentsTestModeNotice', () => {
	beforeEach( () => {
		window.wcSettings = { adminUrl: 'http://example.com/wp-admin' };
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValue( {} );
	} );

	it.each( [
		[ 'deposits', 'payouts' ],
		[ 'disputes', 'disputes' ],
		[ 'documents', 'documents' ],
		[ 'loans', 'loans' ],
		[ 'payments', 'payments' ],
		[ 'transactions', 'transactions' ],
	] as Array< [ WooPaymentsTestModeNoticePage, string ] > )(
		'shows the %s list copy in test mode',
		( page, resource ) => {
			mockMode( { testMode: true } );

			render( <WooPaymentsTestModeNotice currentPage={ page } /> );

			const notice = getNotice();
			expect( notice ).toHaveTextContent(
				`Viewing test ${ resource }. To view live ${ resource }, disable test mode in WooPayments settings.`
			);
			expect(
				screen.getByRole( 'link', { name: 'WooPayments settings' } )
			).toHaveAttribute( 'href', SETTINGS_URL );
		}
	);

	// Client 11.2.0 components/test-mode-notice/index.tsx:34-41, 182-190.
	it.each( [
		[
			'deposits',
			'WooPayments is in test mode, so only test payouts are shown. To view live payouts, disable test mode in WooPayments settings.',
		],
		[
			'disputes',
			'WooPayments is in test mode, so only test disputes are shown. To view live disputes, disable test mode in WooPayments settings.',
		],
		[
			'payments',
			'WooPayments is in test mode, so only test transactions are shown. To view live transactions, disable test mode in WooPayments settings.',
		],
		[
			'transactions',
			'WooPayments is in test mode, so only test transactions are shown. To view live transactions, disable test mode in WooPayments settings.',
		],
	] as Array< [ WooPaymentsTestModeNoticePage, string ] > )(
		'shows the %s details copy in test mode',
		( page, expected ) => {
			mockMode( { testMode: true } );

			render(
				<WooPaymentsTestModeNotice currentPage={ page } isDetailsView />
			);

			expect( getNotice() ).toHaveTextContent( expected );
			expect(
				screen.getByRole( 'link', { name: 'WooPayments settings' } )
			).toHaveAttribute( 'href', SETTINGS_URL );
		}
	);

	// Client 11.3.0 client/components/test-mode-notice/index.tsx:119-128.
	it.each( [ false, true ] )(
		'uses the transactions record prefix on staging (details: %s)',
		( isDetailsView ) => {
			window.wcSettings = {
				admin: {
					woopaymentsSettings: {
						devMode: true,
						testMode: true,
						devModeReason: 'This site is set up as a staging site.',
					},
				},
			};
			render(
				<WooPaymentsTestModeNotice
					currentPage="payments"
					isDetailsView={ isDetailsView }
				/>
			);
			expect( getNotice() ).toHaveTextContent(
				'Viewing test transactions. Your store is in development mode, so payments are simulated. This site is set up as a staging site. Real payments only work on your live store. Learn more'
			);
			expect(
				screen.getByRole( 'link', { name: /Learn more/ } )
			).toHaveAttribute(
				'href',
				`${ TEST_ACCOUNTS_URL }#developer-notes`
			);
		}
	);

	it( 'renders nothing in live mode', () => {
		mockMode( { testMode: false } );

		const { container } = render(
			<WooPaymentsTestModeNotice currentPage="transactions" />
		);

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing when no mode was preloaded', () => {
		const { container } = render(
			<WooPaymentsTestModeNotice currentPage="loans" />
		);

		expect( container ).toBeEmptyDOMElement();
	} );

	// Client index.tsx:245 reads `wcpaySettings.testMode`: the notice costs no request.
	it( 'makes no request to decide or render the notice', async () => {
		mockMode( { testMode: true, devMode: true } );

		render( <WooPaymentsTestModeNotice currentPage="transactions" /> );
		await act( () => Promise.resolve() );

		expect( getNotice() ).toBeInTheDocument();
		expect( mockApiFetch ).not.toHaveBeenCalled();
	} );
} );
