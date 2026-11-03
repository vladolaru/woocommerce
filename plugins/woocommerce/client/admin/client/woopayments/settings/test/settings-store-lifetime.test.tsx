/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { createRef, StrictMode } from '@wordpress/element';
import { paymentSettingsStore } from '@woocommerce/data';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { WooPaymentsAdminNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { registerStoreUnlessTaken } from '../../data/register-store';
import { useGetSettings, useSettings } from '../data/hooks';
import { store as settingsStore } from '../data/store';
import { WooPaymentsSettingsPage } from '../settings-page';
import { SettingsSubpage } from '../settings-subpage';
import { FraudProtectionAdvancedSettingsPage } from '../fraud-protection/advanced';
import { DisputeEvidenceForm } from '../../admin/money-movement/dispute-evidence-form';
import type { WooPaymentsDispute } from '../../admin/money-movement/types';
import { ReactivateLivePaymentsButton } from '~/settings-payments/components/buttons/reactivate-live-payments-button';
import { WooPaymentsAdminNotices } from '~/settings-payments/components/woopayments-admin-notices';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// The test setup's `core/notices` store has no notice creators.
jest.mock( '@wordpress/data', () => {
	const data = jest.requireActual( '@wordpress/data' );
	const notices = {
		createErrorNotice: jest.fn(),
		createInfoNotice: jest.fn(),
		createSuccessNotice: jest.fn(),
	};

	return {
		...data,
		dispatch: ( storeName: string ) =>
			storeName === 'core/notices' ? notices : data.dispatch( storeName ),
	};
} );
jest.mock( '@woocommerce/data', () => {
	const data = jest.requireActual( '@wordpress/data' );

	return {
		paymentSettingsStore: data.createReduxStore( 'test/payment-settings', {
			reducer: ( state = {} ) => state,
			selectors: { getPaymentProviders: () => [] },
		} ),
	};
} );
jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsEvent: jest.fn(),
} ) );
jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

const SETTINGS_PATH = '/wc/v3/payments/settings';
const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
// The settings the server holds: a live account still in test mode, Basic fraud protection, saved cards on.
let server: Record< string, unknown >;
const posts: Array< Record< string, unknown > > = [];

const setUpServer = () => {
	server = {
		is_wcpay_enabled: true,
		is_test_mode_enabled: true,
		is_saved_cards_enabled: true,
		is_debug_log_enabled: false,
		enabled_payment_method_ids: [ 'card' ],
		available_payment_method_ids: [ 'card' ],
		current_protection_level: 'basic',
		advanced_fraud_protection_settings: [],
		store_currency: 'USD',
		account_statement_descriptor: 'EXAMPLE STORE',
		account_communications_email: 'owner@example.com',
		account_business_support_email: 'help@example.com',
		account_business_support_phone: '+12025550123',
		fraud_protection: {
			decline_on_avs_failure: false,
			decline_on_cvc_failure: true,
			is_welcome_tour_dismissed: true,
		},
		fraud_protection_allowed_countries: { type: 'all', countries: [] },
	};
	posts.length = 0;
	mockApiFetch.mockImplementation( ( options ) => {
		const { path, method, data } = options as {
			path?: string;
			method?: string;
			data?: Record< string, unknown >;
		};

		if ( path === SETTINGS_PATH && method?.toLowerCase() === 'post' ) {
			posts.push( data ?? {} );
			server = { ...server, ...data };

			return Promise.resolve( {} );
		}

		return Promise.resolve( path === SETTINGS_PATH ? { ...server } : {} );
	} );
};

const testToLiveNotice: WooPaymentsAdminNotice = {
	id: 'test_to_live',
	message: "You're ready to take real payments.",
	primary: { kind: 'disable_test_mode', label: 'Turn on live payments' },
	_links: {
		shown: { href: '/admin-notices/test_to_live/shown' },
		dismiss: { href: '/admin-notices/test_to_live/dismiss' },
		cta: { href: '/admin-notices/test_to_live/cta' },
	},
} as WooPaymentsAdminNotice;

// Stands in for an express checkout Customize page, which reads the settings store as the settings page does.
const CustomizeSettings = () =>
	useSettings().isLoading ? null : <p>Customize settings loaded</p>;

const renderSettingsPage = async () => {
	const page = render( <WooPaymentsSettingsPage /> );
	await screen.findByRole( 'checkbox', { name: 'Enable test mode' } );

	return page;
};

const settingsReads = () =>
	mockApiFetch.mock.calls.filter( ( [ options ] ) => {
		const { path, method } = options as { path?: string; method?: string };

		return path === SETTINGS_PATH && ! method;
	} ).length;

const settle = () =>
	act( () => new Promise( ( resolve ) => setTimeout( resolve ) ) );

const save = async () => {
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Save changes' } )
	);
	await waitFor( () => expect( posts ).toHaveLength( 1 ) );
};

const isSettingsRequest = ( options: unknown, method?: string ) => {
	const request = options as { path?: string; method?: string };

	return (
		request.path === SETTINGS_PATH &&
		request.method?.toLowerCase() === method
	);
};

// Holds the next settings read, or with 'post' the next save: the server reads or applies it when asked, and the
// answer arrives only on release.
const holdNext = ( method?: string ) => {
	const serve = mockApiFetch.getMockImplementation()!;
	const hold: { release?: ( error?: Error ) => Promise< void > } = {};
	mockApiFetch.mockImplementation( ( options ) => {
		if ( hold.release || ! isSettingsRequest( options, method ) ) {
			return serve( options );
		}
		const answer = serve( options );

		return new Promise( ( resolve, reject ) => {
			hold.release = ( error ) =>
				act( async () =>
					error ? reject( error ) : resolve( await answer )
				);
		} );
	} );

	return hold;
};

const failNextRead = () => {
	const serve = mockApiFetch.getMockImplementation()!;
	let hasFailed = false;
	mockApiFetch.mockImplementation( ( options ) => {
		if ( hasFailed || ! isSettingsRequest( options ) ) {
			return serve( options );
		}
		hasFailed = true;

		return Promise.reject( new Error( 'Network error' ) );
	} );
};

// On the Payments list, "Turn on live payments" turns test mode off on the server.
const goLive = async () => {
	const payments = render(
		<WooPaymentsAdminNotices
			notice={ testToLiveNotice }
			focusTargetRef={ createRef() }
			onDismiss={ () => undefined }
		/>
	);
	await userEvent.click( screen.getByText( 'Turn on live payments' ) );
	await waitFor( () => expect( server.is_test_mode_enabled ).toBe( false ) );
	payments.unmount();
	posts.length = 0;
};

// A test dispute the evidence form can open, which reads the settings for its cover letter.
const evidenceDispute = {
	id: 'dp_test',
	reason: 'fraudulent',
	status: 'needs_response',
	amount: 5000,
	currency: 'usd',
	created: 1771423200,
	charge: { id: 'ch_test', created: 1771336800 },
	evidence: {},
	evidence_details: { due_by: 1772028000 },
	metadata: { __product_type: 'digital_product_or_service' },
} as WooPaymentsDispute;

// Opens the settings page, or the given route, on an empty store and leaves it while its read, answering with test
// mode on, is held.
const leaveWhileReading = async ( route = <WooPaymentsSettingsPage /> ) => {
	await act( () => dispatch( settingsStore ).resetSettings() );
	const read = holdNext();
	const settingsPage = render( route );
	await waitFor( () => expect( read.release ).toBeDefined() );
	settingsPage.unmount();
	await settle();

	return read;
};

// Saves the settings page with test mode on and leaves it while the server has applied the save but its answer is held.
const leaveWhileSaving = async () => {
	const settingsPage = await renderSettingsPage();
	const saveRequest = holdNext( 'post' );
	await userEvent.click(
		screen.getByRole( 'checkbox', { name: 'Log error messages' } )
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Save changes' } )
	);
	await waitFor( () => expect( saveRequest.release ).toBeDefined() );
	settingsPage.unmount();
	await settle();

	return saveRequest;
};

// A reader of the settings store outside the settings routes that keeps no visit of its own.
const EvidenceFormSettings = () =>
	useGetSettings().account_business_support_email ? (
		<p>Evidence form settings loaded</p>
	) : null;

// The settings routes in one root, as the Payments settings router renders them.
const SettingsRoute = ( { route }: { route: 'settings' | 'customize' } ) =>
	route === 'settings' ? (
		<WooPaymentsSettingsPage />
	) : (
		<SettingsSubpage
			headingId="woopayments-express-checkout-settings-payment_request"
			title="Apple Pay / Google Pay"
			backPath="/woopayments/settings"
			from="woopayments_express_checkout_settings"
		>
			<CustomizeSettings />
		</SettingsSubpage>
	);

const strictRoute = ( route: 'settings' | 'customize' ) => (
	<StrictMode>
		<SettingsRoute route={ route } />
	</StrictMode>
);

describe( 'WooPayments settings store across settings routes', () => {
	beforeAll( () => {
		registerStoreUnlessTaken( paymentSettingsStore );
		registerStoreUnlessTaken( settingsStore );
	} );

	beforeEach( () => {
		window.wcSettings = { adminUrl: 'http://example.com/wp-admin/' };
		jest.spyOn( window, 'confirm' ).mockReturnValue( true );
		setUpServer();
		// Each row starts from the server's settings, whatever the row before left in the store.
		dispatch( settingsStore ).updateSettings( { ...server } );
	} );

	afterEach( () => jest.restoreAllMocks() );

	it.each( [
		[
			'the "Turn on live payments" notice',
			'Turn on live payments',
			() => (
				<WooPaymentsAdminNotices
					notice={ testToLiveNotice }
					focusTargetRef={ createRef() }
					onDismiss={ () => undefined }
				/>
			),
		],
		[
			'the "Reactivate payments" row button',
			'Reactivate payments',
			() => <ReactivateLivePaymentsButton settingsHref="#" />,
		],
	] )(
		'saves live mode after going live through %s and coming back',
		async ( _path, label, GoLive ) => {
			const settingsPage = await renderSettingsPage();
			settingsPage.unmount();
			// The merchant reads the Payments list before going live, so any read made on the way out has landed.
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve ) )
			);

			const payments = render( <GoLive /> );
			await userEvent.click( screen.getByText( label ) );
			await waitFor( () =>
				expect( server.is_test_mode_enabled ).toBe( false )
			);
			payments.unmount();
			posts.length = 0;

			await renderSettingsPage();
			await waitFor( () =>
				expect(
					screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
				).not.toBeChecked()
			);
			await userEvent.click(
				screen.getByRole( 'checkbox', { name: 'Log error messages' } )
			);
			await save();

			expect( posts[ 0 ] ).toEqual(
				expect.objectContaining( {
					is_test_mode_enabled: false,
					is_debug_log_enabled: true,
				} )
			);
		}
	);

	it( 'saves live mode after leaving while the settings still load, then going live and coming back', async () => {
		const serve = mockApiFetch.getMockImplementation();
		let finishRead: ( () => void ) | undefined;
		mockApiFetch.mockImplementation( ( options ) => {
			const { path, method } = options as {
				path?: string;
				method?: string;
			};

			if ( path === SETTINGS_PATH && ! method && ! finishRead ) {
				// The first read answers with the settings the server held when it was asked: test mode on.
				const held = { ...server };

				return new Promise( ( resolve ) => {
					finishRead = () => resolve( held );
				} );
			}

			return serve!( options );
		} );
		await act( () => dispatch( settingsStore ).resetSettings() );

		const settingsPage = render( <WooPaymentsSettingsPage /> );
		await waitFor( () => expect( finishRead ).toBeDefined() );
		settingsPage.unmount();
		await settle();
		await act( async () => finishRead!() );
		await settle();

		const payments = render(
			<WooPaymentsAdminNotices
				notice={ testToLiveNotice }
				focusTargetRef={ createRef() }
				onDismiss={ () => undefined }
			/>
		);
		await userEvent.click( screen.getByText( 'Turn on live payments' ) );
		await waitFor( () =>
			expect( server.is_test_mode_enabled ).toBe( false )
		);
		payments.unmount();
		posts.length = 0;

		await renderSettingsPage();
		await waitFor( () =>
			expect(
				screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
			).not.toBeChecked()
		);
		await userEvent.click(
			screen.getByRole( 'checkbox', { name: 'Log error messages' } )
		);
		await save();

		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( { is_test_mode_enabled: false } )
		);
	} );

	it.each( [
		[
			"a settings read from the last visit answers after the next visit's read",
			async () => {
				const read = await leaveWhileReading();
				await goLive();
				await renderSettingsPage();
				await read.release!();
			},
		],
		[
			"a settings read from the last visit answers while the next visit's read runs",
			async () => {
				const read = await leaveWhileReading();
				await goLive();
				const nextRead = holdNext();
				render( <WooPaymentsSettingsPage /> );
				await waitFor( () => expect( nextRead.release ).toBeDefined() );
				await read.release!();
				await settle();
				// The visit still waits for its own read.
				expect(
					screen.queryByRole( 'checkbox', {
						name: 'Enable test mode',
					} )
				).not.toBeInTheDocument();
				await nextRead.release!();
				await screen.findByRole( 'checkbox', {
					name: 'Enable test mode',
				} );
			},
		],
		[
			'a settings read from the dispute evidence form answers after the settings page opens',
			async () => {
				const read = await leaveWhileReading(
					<DisputeEvidenceForm
						dispute={ evidenceDispute }
						fileDetails={ {} }
					/>
				);
				await goLive();
				render( <WooPaymentsSettingsPage /> );
				await settle();
				await read.release!();
				await screen.findByRole( 'checkbox', {
					name: 'Enable test mode',
				} );
			},
		],
		[
			"a settings save from the last visit answers after the next visit's read",
			async () => {
				const saveRequest = await leaveWhileSaving();
				await goLive();
				await renderSettingsPage();
				await saveRequest.release!();
			},
		],
		[
			"a settings save from the last visit fails after the next visit's read",
			async () => {
				const saveRequest = await leaveWhileSaving();
				await goLive();
				await renderSettingsPage();
				await saveRequest.release!( new Error( 'Network error' ) );
			},
		],
	] )( 'saves live mode when %s', async ( _case, visit ) => {
		await visit();
		await settle();

		expect( select( settingsStore ).isSavingSettings() ).toBe( false );
		expect( select( settingsStore ).getSavingError() ).toBeNull();
		expect(
			screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
		).not.toBeChecked();
		await userEvent.click(
			screen.getByRole( 'checkbox', { name: 'Log error messages' } )
		);
		await save();
		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( { is_test_mode_enabled: false } )
		);
	} );

	it( "leaves nothing to save when the next visit's read fails", async () => {
		const read = await leaveWhileReading();
		await read.release!();
		await settle();
		await goLive();
		failNextRead();

		render( <WooPaymentsSettingsPage /> );
		await settle();

		expect( select( settingsStore ).getSettings() ).toEqual( {} );
		expect(
			await screen.findByRole( 'button', { name: 'Save changes' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		// Not after an edit either: the stale test mode never goes out.
		await userEvent.click(
			screen.getByRole( 'checkbox', { name: 'Log error messages' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		await settle();
		expect( posts ).toHaveLength( 0 );
	} );

	it( 'reads the settings again for a visit after another reader loaded them', async () => {
		( await renderSettingsPage() ).unmount();
		await settle();
		const evidenceForm = render( <EvidenceFormSettings /> );
		await screen.findByText( 'Evidence form settings loaded' );
		evidenceForm.unmount();
		await goLive();
		mockApiFetch.mockClear();

		await renderSettingsPage();
		await settle();

		expect( settingsReads() ).toBe( 1 );
		expect(
			screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
		).not.toBeChecked();
	} );

	it.each( [
		[
			'the first settings page visit',
			async () => {
				await act( () => dispatch( settingsStore ).resetSettings() );
				mockApiFetch.mockClear();
				render( strictRoute( 'settings' ) );
				await screen.findByRole( 'checkbox', {
					name: 'Enable test mode',
				} );
			},
		],
		[
			'a first visit whose read started before the route opened',
			async () => {
				await act( () => dispatch( settingsStore ).resetSettings() );
				mockApiFetch.mockClear();
				const read = holdNext();
				select( settingsStore ).getSettings();
				await settle();
				// Not under Strict Mode: its simulated unmount closes the visit, so the remount reads again.
				render( <SettingsRoute route="settings" /> );
				await read.release!();
				await screen.findByRole( 'checkbox', {
					name: 'Enable test mode',
				} );
			},
		],
		[
			'a move from the settings page to a Customize page',
			async () => {
				const routes = render( strictRoute( 'settings' ) );
				await screen.findByRole( 'checkbox', {
					name: 'Enable test mode',
				} );
				await settle();
				mockApiFetch.mockClear();
				routes.rerender( strictRoute( 'customize' ) );
				await screen.findByText( 'Customize settings loaded' );
			},
		],
		[
			'a move with unsaved edits from the settings page to a Customize page',
			async () => {
				const routes = render( strictRoute( 'settings' ) );
				await userEvent.click(
					await screen.findByRole( 'checkbox', {
						name: 'Log error messages',
					} )
				);
				await settle();
				mockApiFetch.mockClear();
				routes.rerender( strictRoute( 'customize' ) );
				await screen.findByText( 'Customize settings loaded' );
			},
		],
	] )( 'reads the settings once on %s', async ( _visit, visit ) => {
		await visit();
		await settle();

		expect( settingsReads() ).toBe( 1 );
	} );

	it.each( [
		[ 'only the level changed', false, false ],
		[ 'another setting changed too', true, true ],
	] )(
		'asks before opening the advanced fraud rules when %s',
		async ( _case, untickSavedCards, asks ) => {
			await renderSettingsPage();
			if ( untickSavedCards ) {
				await userEvent.click(
					screen.getByRole( 'checkbox', {
						name: 'Enable payments via saved cards',
					} )
				);
			}

			await userEvent.click(
				screen.getByRole( 'radio', { name: 'Advanced' } )
			);
			await userEvent.click(
				screen.getByRole( 'link', { name: 'Configure' } )
			);

			expect( window.confirm ).toHaveBeenCalledTimes( asks ? 1 : 0 );
			expect( window.location.search ).toContain(
				'path=%2Fwoopayments%2Fsettings%2Ffraud-protection'
			);
		}
	);

	it( 'leaves the edits the merchant was told may be lost out of the advanced fraud rules save', async () => {
		const settingsPage = await renderSettingsPage();
		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Enable payments via saved cards',
			} )
		);
		await userEvent.click(
			screen.getByRole( 'radio', { name: 'Advanced' } )
		);
		await userEvent.click(
			screen.getByRole( 'link', { name: 'Configure' } )
		);
		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		settingsPage.unmount();

		render( <FraudProtectionAdvancedSettingsPage /> );
		// The route change settles before the merchant acts on the new page.
		await act( async () => undefined );
		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'Enable AVS Mismatch filter',
			} )
		);
		await save();

		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( {
				is_saved_cards_enabled: true,
				current_protection_level: 'advanced',
			} )
		);
	} );

	it( 'leaves an edit made on a subpage out of the next settings page save', async () => {
		( await renderSettingsPage() ).unmount();
		const subpage = render(
			<SettingsSubpage
				headingId="woopayments-express-checkout-settings-payment_request"
				title="Apple Pay / Google Pay"
				backPath="/woopayments/settings"
				from="woopayments_express_checkout_settings"
			>
				<CustomizeSettings />
			</SettingsSubpage>
		);
		await act( async () => undefined );
		await screen.findByText( 'Customize settings loaded' );
		// One of its controls, left unsaved after the leave prompt.
		act( () => {
			dispatch( settingsStore ).updateIsDebugLogEnabled( true );
		} );
		subpage.unmount();

		await renderSettingsPage();
		await act( async () => undefined );
		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'Enable payments via saved cards',
			} )
		);
		await save();

		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( {
				is_debug_log_enabled: false,
				is_saved_cards_enabled: false,
			} )
		);
	} );
} );
