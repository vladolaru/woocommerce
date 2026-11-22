/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { OverviewModeNotice } from '../overview/components/overview-notices';
import type { WooPaymentsOverviewAccount } from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const TEST_ACCOUNTS_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/';

const createAccount = (
	overrides: Partial< WooPaymentsOverviewAccount > = {}
): WooPaymentsOverviewAccount => ( {
	id: 'acct_test',
	mode: 'live',
	connected: true,
	working: true,
	details_submitted: true,
	test_mode: false,
	test_mode_onboarding: false,
	dev_mode: false,
	test_drive: false,
	sandbox: false,
	live: true,
	...overrides,
} );

const renderNotice = ( account: WooPaymentsOverviewAccount ) =>
	render(
		<OverviewModeNotice
			account={ account }
			setupUrl="admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding"
		/>
	);

// Client 11.1.0 overview/index.js:265-276 picks the sandbox notice during test-mode onboarding
// (Mode.php:114: dev mode or the onboarding option), otherwise the test-mode notice.
describe( 'OverviewModeNotice', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders nothing for a live account in live mode', () => {
		const { container } = renderNotice( createAccount() );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows the test-mode notice only in test mode and records its Learn more click', async () => {
		const { container } = renderNotice(
			createAccount( { test_mode: true, mode: 'test' } )
		);

		expect( container ).toHaveTextContent(
			'WooPayments is in test mode. All transactions will be simulated. Learn more'
		);
		expect( screen.queryByText( /test account\./ ) ).toBeNull();

		const link = screen.getByRole( 'link', { name: /Learn more/ } );
		expect( link ).toHaveAttribute( 'href', TEST_ACCOUNTS_URL );

		await userEvent.click( link );

		expect( recordEvent ).toHaveBeenCalledTimes( 1 );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_test_mode_learn_more_clicked'
		);
	} );

	it.each( [
		{
			label: 'test account',
			overrides: { test_mode_onboarding: true, test_drive: true },
			heading: "You're using a test account.",
			helpLabel: 'Learn more about test accounts',
			href: TEST_ACCOUNTS_URL,
			properties: { account_type: 'test', is_dev_mode: false },
		},

		{
			label: 'sandbox account',
			overrides: { test_mode_onboarding: true, sandbox: true },
			heading: "You're using a sandbox test account.",
			helpLabel: 'Learn more about sandbox accounts',
			href: 'https://woocommerce.com/document/woopayments/startup-guide/#signup-process',
			properties: { account_type: 'sandbox', is_dev_mode: false },
		},
	] )(
		'shows the sandbox notice for a $label and records its Learn more click',
		async ( { overrides, heading, helpLabel, href, properties } ) => {
			renderNotice(
				createAccount( {
					live: false,
					test_mode: true,
					mode: 'test',
					...overrides,
				} )
			);

			expect( screen.getByText( heading ) ).toBeInTheDocument();
			expect(
				screen.queryByText( 'WooPayments is in test mode.' )
			).toBeNull();
			// Client 11.1.0 `sandbox-mode-switch-to-live-notice`: a banner notice that ends with its help icon.
			const content = screen
				.getByText( heading )
				.closest( '.woopayments-banner-notice__content' );
			expect( content?.lastElementChild ).toContainElement(
				screen.getByRole( 'button', { name: helpLabel } )
			);

			await userEvent.click(
				screen.getByRole( 'button', { name: helpLabel } )
			);
			const link = screen.getByRole( 'link', { name: /^Learn more/ } );
			expect( link ).toHaveAttribute( 'href', href );

			await userEvent.click( link );

			expect( recordEvent ).toHaveBeenCalledTimes( 1 );
			expect( recordEvent ).toHaveBeenCalledWith(
				'wcpay_overview_sandbox_mode_learn_more_clicked',
				properties
			);
		}
	);

	// Client 11.3.0 client/components/sandbox-mode-switch-to-live-notice/index.tsx:54-65,232-245.
	it.each( [ 'test', 'sandbox', 'live' ] )(
		'shows one staging notice and tracks the inline link for %s',
		async ( accountType ) => {
			const original = window.wcSettings;
			window.wcSettings = {
				...original,
				admin: {
					woopaymentsSettings: {
						devModeReason: 'This site is set up as a staging site.',
					},
				},
			};
			try {
				const account = createAccount( {
					dev_mode: true,
					live: accountType === 'live',
					sandbox: accountType === 'sandbox',
					test_drive: accountType === 'test',
					test_mode: true,
				} );
				const off = renderNotice( { ...account, test_mode: false } );
				expect(
					off.container.querySelectorAll(
						'.woopayments-banner-notice'
					)
				).toHaveLength( account.live ? 0 : 1 );
				off.unmount();
				const { container } = renderNotice( account );
				expect(
					container.querySelectorAll( '.woopayments-banner-notice' )
				).toHaveLength( 1 );
				expect(
					container.querySelector(
						'.woopayments-banner-notice__content'
					)?.textContent
				).toBe(
					'Your store is in development mode, so payments are simulated. This site is set up as a staging site. Real payments only work on your live store. Learn more'
				);
				expect(
					screen.queryByRole( 'button' )
				).not.toBeInTheDocument();
				const link = screen.getByRole( 'link', {
					name: /^Learn more/,
				} );
				expect( link ).toHaveAttribute(
					'href',
					`${ TEST_ACCOUNTS_URL }#developer-notes`
				);
				await userEvent.click( link );
				expect( recordEvent ).toHaveBeenCalledTimes( 1 );
				expect( recordEvent ).toHaveBeenCalledWith(
					'wcpay_overview_sandbox_mode_learn_more_clicked',
					{ account_type: accountType, is_dev_mode: true }
				);
			} finally {
				window.wcSettings = original;
			}
		}
	);

	it( 'opens the live payments modal from the test account notice', async () => {
		renderNotice(
			createAccount( {
				live: false,
				test_mode: true,
				test_mode_onboarding: true,
				test_drive: true,
			} )
		);

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'activate your WooPayments account.',
			} )
		);

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_setup_live_payments_modal_open',
			{ from: 'WCPAY_OVERVIEW', source: 'wcpay-overview-page' }
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Activate payments on your store',
		} );
		const activateUrl = new URL(
			within( dialog )
				.getByRole( 'link', { name: 'Activate payments' } )
				.getAttribute( 'href' ) ?? '',
			'https://example.test/wp-admin/'
		);
		expect( activateUrl.searchParams.get( 'path' ) ).toBe(
			'/woopayments/onboarding'
		);
		expect( activateUrl.searchParams.get( 'source' ) ).toBe(
			'wcpay-overview-page'
		);
		expect( activateUrl.searchParams.get( 'from' ) ).toBe(
			'wcpay-setup-live-payments'
		);
	} );
} );
