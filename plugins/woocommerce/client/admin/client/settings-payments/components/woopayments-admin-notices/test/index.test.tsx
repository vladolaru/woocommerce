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
const mockInvalidatePaymentProviders = jest.fn();
const mockAssign = jest.fn();
const originalLocation = window.location;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/data', () => ( { paymentSettingsStore: {} } ) );
jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	dispatch: jest.fn( () => ( { createErrorNotice: mockCreateErrorNotice } ) ),
	useDispatch: jest.fn( () => ( {
		invalidateResolutionForStoreSelector: mockInvalidatePaymentProviders,
	} ) ),
} ) );
jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsEvent: jest.fn(),
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

const postKycNotice = (
	stage: 7 | 14 | 30,
	message: string
): WooPaymentsAdminNotice => ( {
	id: 'post_kyc_activation',
	stage,
	message,
	primary: {
		kind: 'navigate_and_dismiss',
		label: 'Promote my store',
		href: '/wp-admin/admin.php?page=wc-admin&path=/marketing',
	},
	_links: {
		shown: { href: '/admin-notices/post_kyc_activation/shown' },
		dismiss: { href: '/admin-notices/post_kyc_activation/dismiss' },
	},
} );

describe( 'WooPaymentsAdminNotices', () => {
	let focusTarget = document.createElement( 'div' );

	beforeAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, assign: mockAssign },
		} );
	} );

	afterAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
	} );

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
		expect( mockInvalidatePaymentProviders ).toHaveBeenCalledWith(
			'getPaymentProviders'
		);
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

	it.each( [
		[ 7, 'Your store is open. Now bring in your first customer.' ],
		[ 14, 'Two weeks on, still no first sale?' ],
		[ 30, "A month in. Let's get your first sale." ],
	] as const )(
		'shows the exact day %d post-KYC notice with a transactional promotion button',
		async ( stage, message ) => {
			const currentNotice = postKycNotice( stage, message );
			render(
				<WooPaymentsAdminNotices
					notice={ currentNotice }
					focusTargetRef={ { current: focusTarget } }
					onDismiss={ jest.fn() }
				/>
			);

			expect( screen.getByText( message ) ).toBeInTheDocument();
			const promote = screen.getByRole( 'button', {
				name: 'Promote my store',
			} );
			expect( promote ).not.toHaveAttribute( 'href' );
			expect(
				screen.queryByRole( 'link', { name: 'Promote my store' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Maybe later' } )
			).not.toBeInTheDocument();
			await waitFor( () =>
				expect( apiFetch ).toHaveBeenCalledWith( {
					url: currentNotice._links.shown.href,
					method: 'POST',
					data: { stage },
				} )
			);
		}
	);

	it.each( [
		[ 'Enter', '{Enter}' ],
		[ 'Space', ' ' ],
	] )(
		'records terminal dismissal before %s button activation navigates',
		async ( _keyName, key ) => {
			const currentNotice = postKycNotice(
				7,
				'Your store is open. Now bring in your first customer.'
			);
			let finishDismiss: ( value: {
				success: boolean;
			} ) => void = () => {};
			( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
				if ( url === currentNotice._links.dismiss.href ) {
					return new Promise( ( resolve ) => {
						finishDismiss = resolve;
					} );
				}
				return Promise.resolve( { success: true } );
			} );
			render(
				<WooPaymentsAdminNotices
					notice={ currentNotice }
					focusTargetRef={ { current: focusTarget } }
					onDismiss={ jest.fn() }
				/>
			);

			const promote = screen.getByRole( 'button', {
				name: 'Promote my store',
			} );
			await userEvent.tab();
			expect( promote ).toHaveFocus();
			await userEvent.keyboard( key );

			expect( apiFetch ).toHaveBeenCalledWith( {
				url: currentNotice._links.dismiss.href,
				method: 'POST',
				data: { stage: 7 },
			} );
			expect( mockAssign ).not.toHaveBeenCalled();

			await act( async () => {
				finishDismiss( { success: true } );
			} );
			await waitFor( () =>
				expect( mockAssign ).toHaveBeenCalledWith(
					currentNotice.primary.href
				)
			);
		}
	);

	it( 'ordinary keyboard dismissal writes only the current post-KYC stage', async () => {
		const currentNotice = postKycNotice(
			14,
			'Two weeks on, still no first sale?'
		);
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith( {
				url: currentNotice._links.shown.href,
				method: 'POST',
				data: { stage: 14 },
			} )
		);
		( apiFetch as jest.Mock ).mockClear();

		const dismiss = screen.getByRole( 'button', {
			name: 'Dismiss WooPayments notice',
		} );
		await userEvent.tab();
		await userEvent.tab();
		expect( dismiss ).toHaveFocus();
		await userEvent.keyboard( ' ' );

		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			url: currentNotice._links.dismiss.href,
			method: 'POST',
			data: { stage: 14 },
		} );
		expect( mockAssign ).not.toHaveBeenCalled();
		expect( focusTarget ).toHaveFocus();
	} );

	it( 'keeps a failed promotion dismissal visible and prevents navigation', async () => {
		const currentNotice = postKycNotice(
			30,
			"A month in. Let's get your first sale."
		);
		let rejectRequest: ( error: Error ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			return url === currentNotice._links.dismiss.href
				? new Promise( ( _resolve, reject ) => {
						rejectRequest = reject;
				  } )
				: Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const promote = screen.getByRole( 'button', {
			name: 'Promote my store',
		} );
		await userEvent.tab();
		expect( promote ).toHaveFocus();
		await userEvent.keyboard( '{Enter}' );
		await act( async () => rejectRequest( new Error( 'Request failed' ) ) );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'We could not update this notice. Please try again.',
				{ type: 'snackbar', explicitDismiss: true }
			)
		);
		expect( mockAssign ).not.toHaveBeenCalled();
		expect( onDismiss ).not.toHaveBeenCalled();
		expect( screen.getByText( currentNotice.message ) ).toBeInTheDocument();
		expect( promote ).toHaveFocus();
	} );
} );
