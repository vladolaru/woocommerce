/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { UpdateBusinessDetailsModal } from '../overview/components/update-business-details-modal';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const ACCOUNT_LINK =
	'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview&wcpay-login=1&_wpnonce=abc123';

// Client 11.1.0 `overview/modal/update-business-details/index.tsx` opens the bare account link; native keeps
// noopener,noreferrer as a recorded improvement (N-194).
describe( 'WooPayments Overview update business details modal link', () => {
	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'opens the bare account link without added query arguments', async () => {
		const openSpy = jest.spyOn( window, 'open' ).mockReturnValue( null );
		render(
			<UpdateBusinessDetailsModal
				shell={ createRecordedOverviewShell( {
					status: 'restricted',
					past_due: true,
					account_link: ACCOUNT_LINK,
				} ) }
				onClose={ jest.fn() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Update business details' } )
		);

		expect( openSpy ).toHaveBeenCalledTimes( 1 );
		expect( openSpy ).toHaveBeenCalledWith(
			ACCOUNT_LINK,
			'_blank',
			'noopener,noreferrer'
		);
	} );

	it( 'disables the update button when the account link is empty', () => {
		render(
			<UpdateBusinessDetailsModal
				shell={ createRecordedOverviewShell( {
					status: 'restricted',
					past_due: true,
					account_link: '',
				} ) }
				onClose={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Update business details' } )
		).toBeDisabled();
	} );
} );
