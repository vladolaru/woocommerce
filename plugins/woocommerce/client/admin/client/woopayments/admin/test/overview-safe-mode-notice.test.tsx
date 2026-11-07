/**
 * External dependencies
 */
import { render, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { OverviewNotices } from '../overview/components/overview-notices';

type SettingsWindow = typeof window & {
	wcSettings?: {
		adminUrl?: string;
		admin?: { woopaymentsSettings?: Record< string, unknown > };
	};
};

const setSafeModeFlag = ( value: unknown ) => {
	const settingsWindow = window as SettingsWindow;
	settingsWindow.wcSettings = {
		adminUrl: 'https://example.com/wp-admin/',
		admin: {
			woopaymentsSettings:
				value === undefined ? {} : { isJetpackIdcActive: value },
		},
	};
};

// Client 11.1.0 components/jetpack-idc-notice/index.tsx, rendered on the Overview (overview/index.js:240).
describe( 'OverviewNotices Safe Mode notice', () => {
	afterEach( () => {
		delete ( window as SettingsWindow ).wcSettings;
	} );

	it( 'asks the merchant to act, linking to the WooPayments settings, while the store is in Safe Mode', () => {
		setSafeModeFlag( true );

		const { container } = render( <OverviewNotices /> );
		const page = within( container );

		expect(
			page.getByText( 'Your site is currently in Safe Mode.', {
				exact: false,
			} )
		).toBeInTheDocument();
		expect(
			page.getByRole( 'link', { name: 'Please take action' } )
		).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings'
		);
	} );

	it.each( [
		[ 'not in Safe Mode', false ],
		[ 'without the flag', undefined ],
	] )( 'shows no Safe Mode notice %s', ( _name, value ) => {
		setSafeModeFlag( value );

		const { container } = render( <OverviewNotices /> );

		expect(
			within( container ).queryByText(
				'Your site is currently in Safe Mode.',
				{
					exact: false,
				}
			)
		).not.toBeInTheDocument();
	} );
} );
