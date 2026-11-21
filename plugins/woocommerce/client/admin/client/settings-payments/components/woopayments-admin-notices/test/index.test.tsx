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
import { WooPaymentsAdminNoticeItem } from '..';

const mockCreateErrorNotice = jest.fn();
const mockInvalidatePaymentProviders = jest.fn();
const mockResolvePaymentProviders = jest.fn();
const mockAssign = jest.fn();
const originalLocation = window.location;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/data', () => ( { paymentSettingsStore: {} } ) );
jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	dispatch: jest.fn( () => ( {
		createErrorNotice: mockCreateErrorNotice,
	} ) ),
	useDispatch: jest.fn( () => ( {
		invalidateResolutionForStoreSelector: mockInvalidatePaymentProviders,
	} ) ),
	resolveSelect: jest.fn( () => ( {
		getPaymentProviders: mockResolvePaymentProviders,
	} ) ),
} ) );

const oneAndDoneNotice: WooPaymentsAdminNotice = {
	id: 'one_and_done',
	message:
		"Your store made its first sale. Now bring more shoppers in with Woo's marketing tools.",
	primary: {
		kind: 'navigate_and_dismiss',
		label: 'Promote my store',
		href: '/wp-admin/admin.php?page=wc-admin&path=/marketing',
	},
	secondary: { kind: 'snooze', label: 'Maybe later' },
	_links: {
		shown: { href: '/admin-notices/one_and_done/shown' },
		dismiss: { href: '/admin-notices/one_and_done/dismiss' },
		snooze: { href: '/admin-notices/one_and_done/snooze' },
		cta: { href: '/admin-notices/one_and_done/cta' },
	},
};

const requestKeys = () =>
	( apiFetch as jest.Mock ).mock.calls.map( ( [ options ] ) =>
		Object.keys( options ).sort()
	);

describe( 'WooPaymentsAdminNoticeItem', () => {
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
		mockResolvePaymentProviders.mockResolvedValue( [] );
	} );

	afterEach( () => {
		focusTarget.remove();
		jest.clearAllMocks();
		jest.restoreAllMocks();
	} );

	it( 'shows the exact one-and-done copy and semantic controls, recording shown once with no request body', async () => {
		const notice = { ...oneAndDoneNotice };
		const { rerender } = render(
			<WooPaymentsAdminNoticeItem
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( notice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Promote my store' } )
		).not.toHaveAttribute( 'href' );
		expect(
			screen.queryByRole( 'link', { name: 'Promote my store' } )
		).not.toBeInTheDocument();
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
		expect( requestKeys() ).toEqual( [ [ 'method', 'url' ] ] );

		rerender(
			<WooPaymentsAdminNoticeItem
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ 'Maybe later', 'snooze' ],
		[ 'Dismiss WooPayments notice', 'dismiss' ],
		[ 'Promote my store', 'cta' ],
	] as const )(
		'sends "%s" as a %s post with no request body, even for a notice that still carries a stage',
		async ( name, action ) => {
			// A notice cached before the stage was retired.
			const notice = {
				...oneAndDoneNotice,
				stage: 7,
			} as WooPaymentsAdminNotice;
			const onDismiss = jest.fn();
			render(
				<WooPaymentsAdminNoticeItem
					notice={ notice }
					focusTargetRef={ { current: focusTarget } }
					onDismiss={ onDismiss }
				/>
			);

			await userEvent.click( screen.getByRole( 'button', { name } ) );

			await waitFor( () =>
				expect( onDismiss ).toHaveBeenCalledTimes( 1 )
			);
			expect( apiFetch ).toHaveBeenCalledWith( {
				url: notice._links[ action ]?.href,
				method: 'POST',
			} );
			expect( requestKeys() ).toEqual( [
				[ 'method', 'url' ],
				[ 'method', 'url' ],
			] );
		}
	);

	it( 'raises no error when the background shown request fails', async () => {
		( apiFetch as jest.Mock ).mockRejectedValue(
			new Error( 'Request failed' )
		);
		render(
			<WooPaymentsAdminNoticeItem
				notice={ oneAndDoneNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith( {
				url: oneAndDoneNotice._links.shown.href,
				method: 'POST',
			} )
		);
		// Let the rejected request settle before checking nothing was raised.
		await new Promise( ( resolve ) => setTimeout( resolve ) );

		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
		expect(
			screen.getByText( oneAndDoneNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'keeps the snooze control busy, then returns focus when the notice closes', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		let finishSnooze: ( value: { success: boolean } ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			if ( url === currentNotice._links.snooze?.href ) {
				return new Promise( ( resolve ) => {
					finishSnooze = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const snooze = screen.getByRole( 'button', { name: 'Maybe later' } );
		await userEvent.click( snooze );
		expect( apiFetch ).toHaveBeenCalledWith( {
			url: currentNotice._links.snooze?.href,
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

	it( 'leaves focus where the user moved it while the notice action ran', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		let finishSnooze: ( value: { success: boolean } ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			if ( url === currentNotice._links.snooze?.href ) {
				return new Promise( ( resolve ) => {
					finishSnooze = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		const elsewhere = document.createElement( 'button' );
		document.body.appendChild( elsewhere );
		render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		try {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Maybe later' } )
			);
			elsewhere.focus();
			await act( async () => {
				finishSnooze( { success: true } );
			} );
			await waitFor( () =>
				expect( onDismiss ).toHaveBeenCalledTimes( 1 )
			);

			expect( elsewhere ).toHaveFocus();
		} finally {
			elsewhere.remove();
		}
	} );

	it( 'leaves focus where the user moved it when the notice was removed while its action ran', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		let finishSnooze: ( value: { success: boolean } ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			if ( url === currentNotice._links.snooze?.href ) {
				return new Promise( ( resolve ) => {
					finishSnooze = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		const elsewhere = document.createElement( 'button' );
		document.body.appendChild( elsewhere );
		const { unmount } = render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		try {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Maybe later' } )
			);
			// A provider refresh removes the notice while the settings page stays.
			unmount();
			elsewhere.focus();
			await act( async () => {
				finishSnooze( { success: true } );
			} );
			await waitFor( () =>
				expect( onDismiss ).toHaveBeenCalledTimes( 1 )
			);

			expect( elsewhere ).toHaveFocus();
		} finally {
			elsewhere.remove();
		}
	} );

	it( 'retains the notice and announces an action failure', async () => {
		let rejectRequest: ( error: Error ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			return url === oneAndDoneNotice._links.dismiss.href
				? new Promise( ( _resolve, reject ) => {
						rejectRequest = reject;
					} )
				: Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNoticeItem
				notice={ oneAndDoneNotice }
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
		expect(
			screen.getByText( oneAndDoneNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect( onDismiss ).not.toHaveBeenCalled();
		expect( dismiss ).toHaveFocus();
		await waitFor( () =>
			expect( dismiss ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
	} );

	it( 'announces the notice message once while action state and error feedback change', async () => {
		let rejectRequest: ( ( error: Error ) => void ) | undefined;
		render(
			<WooPaymentsAdminNoticeItem
				notice={ oneAndDoneNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		const politeRegion = await waitFor( () => {
			const region = document.getElementById( 'a11y-speak-polite' );
			expect( region?.textContent?.trim() ).toBe(
				`Notice: ${ oneAndDoneNotice.message }`
			);
			return region!;
		} );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		let liveRegionMutationCount = 0;
		const observer = new window.MutationObserver( ( mutations ) => {
			liveRegionMutationCount += mutations.length;
		} );
		observer.observe( politeRegion, {
			childList: true,
			characterData: true,
			subtree: true,
		} );
		( apiFetch as jest.Mock ).mockImplementation(
			() =>
				new Promise( ( _resolve, reject ) => {
					rejectRequest = reject;
				} )
		);

		const dismiss = screen.getByRole( 'button', {
			name: 'Dismiss WooPayments notice',
		} );
		await userEvent.click( dismiss );
		await act( async () =>
			rejectRequest?.( new Error( 'Request failed' ) )
		);
		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'We could not update this notice. Please try again.',
				{ type: 'snackbar', explicitDismiss: true }
			)
		);

		await act( async () => undefined );
		observer.disconnect();
		expect( liveRegionMutationCount ).toBe( 0 );
	} );

	it.each( [
		[ 'Enter', '{Enter}' ],
		[ 'Space', ' ' ],
	] )(
		'records terminal dismissal before %s button activation navigates',
		async ( _keyName, key ) => {
			const currentNotice = { ...oneAndDoneNotice };
			let finishDismiss: ( value: {
				success: boolean;
			} ) => void = () => {};
			( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
				if ( url === currentNotice._links.cta?.href ) {
					return new Promise( ( resolve ) => {
						finishDismiss = resolve;
					} );
				}
				return Promise.resolve( { success: true } );
			} );
			render(
				<WooPaymentsAdminNoticeItem
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
				url: currentNotice._links.cta?.href,
				method: 'POST',
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

	it( 'ordinary keyboard dismissal posts only the dismissal and does not navigate', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith( {
				url: currentNotice._links.shown.href,
				method: 'POST',
			} )
		);
		( apiFetch as jest.Mock ).mockClear();

		const dismiss = screen.getByRole( 'button', {
			name: 'Dismiss WooPayments notice',
		} );
		await userEvent.tab();
		await userEvent.tab();
		await userEvent.tab();
		expect( dismiss ).toHaveFocus();
		await userEvent.keyboard( ' ' );

		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			url: currentNotice._links.dismiss.href,
			method: 'POST',
		} );
		expect( mockAssign ).not.toHaveBeenCalled();
		expect( focusTarget ).toHaveFocus();
	} );

	it( 'records one-and-done dismissal before keyboard promotion navigation without double submission', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		let finishDismiss: ( value: { success: boolean } ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			if ( url === currentNotice._links.cta?.href ) {
				return new Promise( ( resolve ) => {
					finishDismiss = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		render(
			<WooPaymentsAdminNoticeItem
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
		await userEvent.keyboard( '{Enter}{Enter}' );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			url: currentNotice._links.cta?.href,
			method: 'POST',
		} );
		expect( mockAssign ).not.toHaveBeenCalled();

		await act( async () => finishDismiss( { success: true } ) );
		await waitFor( () =>
			expect( mockAssign ).toHaveBeenCalledWith(
				currentNotice.primary.href
			)
		);
	} );

	it( 'keeps one-and-done visible and focused when promotion dismissal fails', async () => {
		let rejectRequest: ( error: Error ) => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation( ( { url } ) => {
			return url === oneAndDoneNotice._links.cta?.href
				? new Promise( ( _resolve, reject ) => {
						rejectRequest = reject;
					} )
				: Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNoticeItem
				notice={ oneAndDoneNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const promote = screen.getByRole( 'button', {
			name: 'Promote my store',
		} );
		await userEvent.click( promote );
		await act( async () => rejectRequest( new Error( 'Request failed' ) ) );

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'We could not update this notice. Please try again.',
				{ type: 'snackbar', explicitDismiss: true }
			)
		);
		expect(
			screen.getByText( oneAndDoneNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect( promote ).toHaveFocus();
		expect( onDismiss ).not.toHaveBeenCalled();
		expect( mockAssign ).not.toHaveBeenCalled();
	} );

	it( 'suppresses the same stale notice after remount but announces a fresh payload', async () => {
		const cachedNotice = { ...oneAndDoneNotice };
		mockResolvePaymentProviders.mockRejectedValue(
			new Error( 'Provider refresh failed' )
		);
		const onDismiss = jest.fn();
		const firstRender = render(
			<WooPaymentsAdminNoticeItem
				notice={ cachedNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Dismiss WooPayments notice',
			} )
		);
		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		firstRender.unmount();
		( apiFetch as jest.Mock ).mockClear();
		const politeRegion = document.getElementById( 'a11y-speak-polite' );
		if ( politeRegion ) {
			politeRegion.textContent = '';
		}

		const staleRemount = render(
			<WooPaymentsAdminNoticeItem
				notice={ cachedNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);
		expect(
			screen.queryByText( cachedNotice.message, { selector: 'p' } )
		).not.toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( politeRegion ).toHaveTextContent( '' );
		staleRemount.unmount();

		const freshNotice = { ...oneAndDoneNotice };
		render(
			<WooPaymentsAdminNoticeItem
				notice={ freshNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( freshNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( politeRegion ).toHaveTextContent(
				`Notice: ${ freshNotice.message }`
			)
		);
	} );

	it( 'allows the same cached snooze notice after its seven-day window', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		mockResolvePaymentProviders.mockRejectedValue(
			new Error( 'Provider refresh failed' )
		);
		const now = new Date( '2026-09-24T00:00:00Z' ).getTime();
		const dateNow = jest.spyOn( Date, 'now' ).mockReturnValue( now );
		const onDismiss = jest.fn();
		const firstRender = render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Maybe later' } )
		);
		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		firstRender.unmount();

		const suppressedRemount = render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);
		expect(
			screen.queryByText( currentNotice.message, { selector: 'p' } )
		).not.toBeInTheDocument();
		suppressedRemount.unmount();

		dateNow.mockReturnValue( now + 7 * 24 * 60 * 60 * 1000 + 1 );
		render(
			<WooPaymentsAdminNoticeItem
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);
		expect(
			screen.getByText( currentNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		dateNow.mockRestore();
	} );
} );
