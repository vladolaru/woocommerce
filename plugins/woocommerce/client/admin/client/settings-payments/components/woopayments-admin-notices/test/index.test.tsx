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
import { recordPaymentsEvent } from '~/settings-payments/utils';

const mockCreateErrorNotice = jest.fn();
const mockCreateSuccessNotice = jest.fn();
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
		createSuccessNotice: mockCreateSuccessNotice,
	} ) ),
	useDispatch: jest.fn( () => ( {
		invalidateResolutionForStoreSelector: mockInvalidatePaymentProviders,
	} ) ),
	resolveSelect: jest.fn( () => ( {
		getPaymentProviders: mockResolvePaymentProviders,
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
	},
};

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
		mockResolvePaymentProviders.mockResolvedValue( [] );
	} );

	afterEach( () => {
		focusTarget.remove();
		jest.clearAllMocks();
		jest.restoreAllMocks();
	} );

	it( 'shows the exact test-to-live message and semantic controls, recording shown once', async () => {
		const { rerender } = render(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( notice.message, { selector: 'p' } )
		).toBeInTheDocument();
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
		const currentNotice = { ...notice };
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
			<WooPaymentsAdminNotices
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
		expect(
			screen.getByText( notice.message, { selector: 'p' } )
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
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		const politeRegion = await waitFor( () => {
			const region = document.getElementById( 'a11y-speak-polite' );
			expect( region?.textContent?.trim() ).toBe(
				`Notice: ${ notice.message }`
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
		await act(
			async () => rejectRequest?.( new Error( 'Request failed' ) )
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

			expect(
				screen.getByText( message, { selector: 'p' } )
			).toBeInTheDocument();
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
		expect(
			screen.getByText( currentNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect( promote ).toHaveFocus();
	} );

	it( 'shows the exact one-and-done copy and semantic controls', async () => {
		render(
			<WooPaymentsAdminNotices
				notice={ oneAndDoneNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ jest.fn() }
			/>
		);

		expect(
			screen.getByText( oneAndDoneNotice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Promote my store' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Maybe later' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Dismiss WooPayments notice' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Promote my store' } )
		).not.toBeInTheDocument();
	} );

	it( 'records one-and-done dismissal before keyboard promotion navigation without double submission', async () => {
		const currentNotice = { ...oneAndDoneNotice };
		let finishDismiss: ( value: { success: boolean } ) => void = () => {};
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
		await userEvent.keyboard( '{Enter}{Enter}' );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			url: currentNotice._links.dismiss.href,
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
			return url === oneAndDoneNotice._links.dismiss.href
				? new Promise( ( _resolve, reject ) => {
						rejectRequest = reject;
				  } )
				: Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
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

	it( 'keeps a failed live-mode switch on the notice with actionable feedback', async () => {
		let rejectRequest: ( ( error: Error ) => void ) | undefined;
		( apiFetch as jest.Mock ).mockImplementation( ( request ) => {
			if ( request.path === '/wc/v3/payments/settings' ) {
				return new Promise( ( _resolve, reject ) => {
					rejectRequest = reject;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const initialHref = window.location.href;
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ notice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		const liveModeButton = screen.getByRole( 'button', {
			name: 'Turn on live payments',
		} );
		await userEvent.click( liveModeButton );
		expect( liveModeButton ).toHaveAttribute( 'aria-disabled', 'true' );
		await act(
			async () => rejectRequest?.( new Error( 'Request failed' ) )
		);

		await waitFor( () =>
			expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
				'We could not turn on live payments. Please try again.',
				{ type: 'snackbar', explicitDismiss: true }
			)
		);
		expect( window.location.href ).toBe( initialHref );
		expect(
			screen.getByText( notice.message, { selector: 'p' } )
		).toBeInTheDocument();
		expect( liveModeButton ).toHaveFocus();
		expect( liveModeButton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( onDismiss ).not.toHaveBeenCalled();
	} );

	it( 'preserves live-mode success feedback and closes the notice', async () => {
		const currentNotice = { ...notice };
		let resolveRequest: ( () => void ) | undefined;
		( apiFetch as jest.Mock ).mockImplementation( ( request ) => {
			if ( request.path === '/wc/v3/payments/settings' ) {
				return new Promise< void >( ( resolve ) => {
					resolveRequest = resolve;
				} );
			}
			return Promise.resolve( { success: true } );
		} );
		const onDismiss = jest.fn();
		render(
			<WooPaymentsAdminNotices
				notice={ currentNotice }
				focusTargetRef={ { current: focusTarget } }
				onDismiss={ onDismiss }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Turn on live payments' } )
		);
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/settings',
			method: 'POST',
			data: { is_test_mode_enabled: false },
		} );
		expect( recordPaymentsEvent ).toHaveBeenCalledWith(
			'reactivate_payments_button_click',
			expect.objectContaining( {
				provider_id: 'woocommerce_payments',
			} )
		);

		await act( async () => resolveRequest?.() );
		await waitFor( () => expect( onDismiss ).toHaveBeenCalledTimes( 1 ) );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'WooPayments is now processing live payments (real payment methods and charges).',
			{ type: 'snackbar', explicitDismiss: false }
		);
		expect( mockInvalidatePaymentProviders ).toHaveBeenCalledWith(
			'getPaymentProviders'
		);
		expect( focusTarget ).toHaveFocus();
		expect( mockAssign ).not.toHaveBeenCalled();
	} );

	it( 'suppresses the same stale notice after remount but announces a fresh payload', async () => {
		const cachedNotice = postKycNotice(
			7,
			'Your store is open. Now bring in your first customer.'
		);
		mockResolvePaymentProviders.mockRejectedValue(
			new Error( 'Provider refresh failed' )
		);
		const onDismiss = jest.fn();
		const firstRender = render(
			<WooPaymentsAdminNotices
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
			<WooPaymentsAdminNotices
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

		const freshNotice = postKycNotice(
			14,
			'Two weeks on, still no first sale?'
		);
		render(
			<WooPaymentsAdminNotices
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
		const currentNotice = { ...notice };
		mockResolvePaymentProviders.mockRejectedValue(
			new Error( 'Provider refresh failed' )
		);
		const now = new Date( '2026-09-24T00:00:00Z' ).getTime();
		const dateNow = jest.spyOn( Date, 'now' ).mockReturnValue( now );
		const onDismiss = jest.fn();
		const firstRender = render(
			<WooPaymentsAdminNotices
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
			<WooPaymentsAdminNotices
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
			<WooPaymentsAdminNotices
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
