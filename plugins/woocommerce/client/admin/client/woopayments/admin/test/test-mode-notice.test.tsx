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

	// Client index.tsx:185-214: singular noun per page, plural for payouts.
	it.each( [
		[
			'deposits',
			'WooPayments was in test mode when these payouts were created. To view live payouts, disable test mode in WooPayments settings.',
		],
		[
			'disputes',
			'WooPayments was in test mode when this dispute was created. To view live disputes, disable test mode in WooPayments settings.',
		],
		[
			'payments',
			'WooPayments was in test mode when this order was placed. To view live orders, disable test mode in WooPayments settings.',
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

	// Client index.tsx:163-181: dev mode wins over the details view and drops the settings link.
	it.each( [ false, true ] )(
		'shows the development-environment copy in dev mode (details view: %s)',
		( isDetailsView ) => {
			mockMode( { testMode: true, devMode: true } );

			render(
				<WooPaymentsTestModeNotice
					currentPage="deposits"
					isDetailsView={ isDetailsView }
				/>
			);

			expect( getNotice() ).toHaveTextContent(
				'Viewing test payouts. Test mode is active because your store is in a development or staging environment. Learn more'
			);
			expect(
				screen.getByRole( 'link', { name: /Learn more/ } )
			).toHaveAttribute( 'href', TEST_ACCOUNTS_URL );
			expect(
				screen.queryByRole( 'link', { name: 'WooPayments settings' } )
			).not.toBeInTheDocument();
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
