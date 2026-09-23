/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import type { WooPaymentsAdminNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { WooPaymentsAdminNotices } from '..';

const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	dispatch: jest.fn( () => ( { createErrorNotice: mockCreateErrorNotice } ) ),
} ) );
jest.mock( '../../buttons/reactivate-live-payments-button', () => ( {
	ReactivateLivePaymentsButton: ( {
		buttonText,
	}: {
		buttonText: string;
	} ) => <button type="button">{ buttonText }</button>,
} ) );

const notice: WooPaymentsAdminNotice = {
	id: 'test_to_live',
	message:
		"You're ready to take real payments. Switch from test mode to start charging customers.",
	primary: {
		kind: 'disable_test_mode',
		label: 'Turn on live payments',
		href: '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings',
	},
	secondary: { kind: 'snooze', label: 'Maybe later' },
	_links: {
		shown: { href: '/admin-notices/test_to_live/shown' },
		dismiss: { href: '/admin-notices/test_to_live/dismiss' },
		snooze: { href: '/admin-notices/test_to_live/snooze' },
	},
};

describe( 'WooPaymentsAdminNotices', () => {
	let focusTarget = document.createElement( 'div' );

	beforeEach( () => {
		focusTarget = document.createElement( 'div' );
		focusTarget.tabIndex = -1;
		document.body.appendChild( focusTarget );
		( apiFetch as jest.Mock ).mockResolvedValue( { success: true } );
	} );

	afterEach( () => {
		focusTarget.remove();
		jest.clearAllMocks();
	} );

	it( 'shows the exact test-to-live message and semantic controls, recording shown once', async () => {
		const { rerender } = render(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		expect( screen.getByText( notice.message ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Turn on live payments' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Maybe later' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Dismiss WooPayments notice' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith( {
				url: notice._links.shown.href,
				method: 'POST',
			} )
		);

		rerender(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the snooze control busy, then returns focus when the notice closes', async () => {
		let finishSnooze: ( value: { success: boolean } ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			if ( url === notice._links.snooze?.href ) {
				return new Promise( ( resolve ) => {
					finishSnooze = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const snooze = screen.getByRole( 'button', { name: 'Maybe later' } );
		await userEvent.click( snooze );
		expect( apiFetch ).toHaveBeenCalledWith( {
			url: notice._links.snooze?.href,
			method: 'POST',
		} );
		expect( snooze ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( onDismiss ).not.toHaveBeenCalled();

		await act( async () => {
			finishSnooze( { success: true } );
		} );
		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		expect( focusTarget ).toHaveFocus();
	} );

	it( 'retains the notice and announces an action failure', async () => {
		let rejectRequest: ( error: Error ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			return url === notice._links.dismiss.href
				? new Promise( ( _resolve, reject ) => {
						rejectRequest = reject;
				  } )
				: Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const dismiss = screen.getByRole( 'button', {
			name: 'Dismiss WooPayments notice',
		} );
		await userEvent.click( dismiss );
		await act( async () => rejectRequest( new Error( 'Request failed' ) ) );
		await waitFor( () => {
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'We could not update this notice. Please try again.',
				{ type: 'snackbar', explicitDismiss: true }
			);
		} );
		expect( screen.getByText( notice.message ) ).toBeInTheDocument();
		expect( onDismiss ).not.toHaveBeenCalled();
		expect( dismiss ).toHaveFocus();
		await waitFor( () =>
			expect( dismiss ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
	} );
} );
