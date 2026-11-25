/**
 * External dependencies
 */
import type { ReactNode } from 'react';

const mockWooPaymentsRoutesLoaded = jest.fn();

jest.mock( '~/woopayments/admin/routes', () => {
	mockWooPaymentsRoutesLoaded();
	const { Route, Routes } = jest.requireActual( 'react-router-dom' );
	const wooPaymentsRoutes = [
		{
			path: '/woopayments/settings',
			element: <div>WooPayments settings page</div>,
		},
		{
			path: '/woopayments/overview',
			element: <div>WooPayments overview page</div>,
		},
	];

	return {
		__esModule: true,
		wooPaymentsRoutes,
		// The chunk's router registers the paths relative to the shell's `/woopayments/*` route.
		default: ( { fallback }: { fallback: ReactNode } ) => (
			<Routes>
				{ wooPaymentsRoutes.map( ( route ) => (
					<Route
						key={ route.path }
						path={ route.path.slice( '/woopayments'.length ) }
						element={ route.element }
					/>
				) ) }
				<Route path="*" element={ fallback } />
			</Routes>
		),
	};
} );
jest.mock( '~/settings-payments/settings-payments-main', () => {
	const { useEffect } = jest.requireActual( 'react' );
	const mount = jest.fn();
	const MockSettingsPaymentsMain = () => {
		useEffect( () => {
			mount();
		}, [] );

		return <div>Main payments list</div>;
	};

	return {
		__esModule: true,
		mockMainMounts: mount,
		default: MockSettingsPaymentsMain,
	};
} );
jest.mock( '~/settings-payments/components/header/header', () => ( {
	Header: () => null,
} ) );
jest.mock( '~/settings-payments/components/list-placeholder', () => ( {
	ListPlaceholder: () => null,
} ) );
jest.mock( '~/settings-payments/settings-payments-offline', () => ( {
	__esModule: true,
	default: () => <div>Offline payments list</div>,
} ) );
jest.mock( '@woocommerce/remote-logging', () => ( {
	captureException: jest.fn(),
} ) );
// Fresh module copies would load yjs (via core-data) more than once; nothing here uses the core-data store.
jest.mock( '@wordpress/core-data', () => ( {} ) );

type OwnerWindow = typeof window & {
	wcSettings: { admin: { woopayments_builtin_owner?: boolean } };
};

// Core preloads this on the Payments settings tab: true only while the built-in WooPayments owns payments.
const setBuiltinOwner = ( owner?: boolean ) => {
	const { admin } = ( window as OwnerWindow ).wcSettings;
	if ( owner === undefined ) {
		delete admin.woopayments_builtin_owner;
	} else {
		admin.woopayments_builtin_owner = owner;
	}
};

const setPaymentsPath = ( path?: string ) => {
	const query = path ? `&path=${ path }` : '';
	window.history.replaceState(
		{},
		'',
		`/wp-admin/admin.php?page=wc-settings&tab=checkout${ query }`
	);
};

const pushPaymentsPath = (
	history: { push: ( to: string ) => void },
	path: string
) =>
	history.push(
		`/wp-admin/admin.php?page=wc-settings&tab=checkout&path=${ path }`
	);

// The lazy chunk resolves once per module registry, so each test gets fresh module copies.
const loadModules = async () => {
	jest.resetModules();

	return {
		// The pure entry registers no test hooks, so it can be loaded inside a test.
		rtl: await import( '@testing-library/react/pure' ),
		navigation: await import( '@woocommerce/navigation' ),
		settingsPayments: await import( '~/settings-payments' ),
		main: ( await import( '~/settings-payments/settings-payments-main' ) ) as unknown as {
			mockMainMounts: jest.Mock;
		},
	};
};

const renderWrapper = async () => {
	const { rtl, navigation, settingsPayments, main } = await loadModules();
	const { SettingsPaymentsMainWrapper } = settingsPayments;

	rtl.render( <SettingsPaymentsMainWrapper /> );

	return {
		...rtl,
		history: navigation.getHistory(),
		mainMounts: main.mockMainMounts,
	};
};

// Lets dynamic imports and lazy chunks settle.
const flushImports = () => new Promise( ( resolve ) => setTimeout( resolve ) );

describe( 'SettingsPaymentsMainWrapper WooPayments routes', () => {
	let cleanup: () => void = () => {};

	// The first fresh load transforms every module; keep that cost out of the first test's timeout.
	beforeAll( async () => {
		await loadModules();
		await import( '~/woopayments/admin/routes' );
	}, 30000 );

	beforeEach( () => {
		mockWooPaymentsRoutesLoaded.mockReset();
		window.scrollTo = jest.fn();
		setBuiltinOwner( true );
	} );

	afterEach( () => {
		cleanup();
		cleanup = () => {};
		setPaymentsPath();
		setBuiltinOwner();
	} );

	it.each( [
		[ 'the main page', undefined, 'Main payments list' ],
		[ 'offline payments', '/offline', 'Offline payments list' ],
		// The onboarding modal opens over the main list.
		[
			'WooPayments onboarding',
			'/woopayments/onboarding',
			'Main payments list',
		],
	] )(
		'does not load the WooPayments routes on %s',
		async ( _label, path, expectedPage ) => {
			setPaymentsPath( path );
			const { screen, act, cleanup: done } = await renderWrapper();
			cleanup = done;

			expect(
				await screen.findByText( expectedPage )
			).toBeInTheDocument();
			await act( flushImports );

			expect( mockWooPaymentsRoutesLoaded ).not.toHaveBeenCalled();
		}
	);

	// Core sends false while the feature is off, while the WooPayments extension owns payments and while a store waits for its switch.
	it.each( [
		[
			'/woopayments/overview',
			'the built-in does not own payments',
			false,
		],
		[
			'/woopayments/settings',
			'the built-in does not own payments',
			false,
		],
		[
			'/woopayments/overview',
			'the ownership preload is missing',
			undefined,
		],
	] )(
		'shows the payments list on %s, as trunk does, without loading the WooPayments routes, when %s',
		async ( path, _label, owner ) => {
			setBuiltinOwner( owner );
			setPaymentsPath( path );
			const { screen, act, cleanup: done } = await renderWrapper();
			cleanup = done;

			expect(
				await screen.findByText( 'Main payments list' )
			).toBeInTheDocument();
			await act( flushImports );

			expect( mockWooPaymentsRoutesLoaded ).not.toHaveBeenCalled();
			expect(
				screen.queryByText( 'Loading WooPayments…' )
			).not.toBeInTheDocument();
		}
	);

	// Built-in stores not yet connected have no `woopaymentsSettings` preload; core still links them to these pages.
	it( 'loads and renders a WooPayments route on a direct /woopayments load by a built-in store not yet connected', async () => {
		setPaymentsPath( '/woopayments/overview' );
		const { screen, cleanup: done } = await renderWrapper();
		cleanup = done;

		expect( screen.getByRole( 'status', { name: '' } ) ).toHaveTextContent(
			'Loading WooPayments…'
		);
		expect(
			screen.queryByText( 'Main payments list' )
		).not.toBeInTheDocument();

		expect(
			await screen.findByText( 'WooPayments overview page' )
		).toBeInTheDocument();
		expect( mockWooPaymentsRoutesLoaded ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'loads and renders a WooPayments route after navigating into /woopayments', async () => {
		setPaymentsPath();
		const { screen, act, history, cleanup: done } = await renderWrapper();
		cleanup = done;

		expect(
			await screen.findByText( 'Main payments list' )
		).toBeInTheDocument();
		expect( mockWooPaymentsRoutesLoaded ).not.toHaveBeenCalled();

		act( () => {
			pushPaymentsPath( history, '/woopayments/overview' );
		} );

		expect(
			screen.queryByText( 'Main payments list' )
		).not.toBeInTheDocument();
		expect(
			await screen.findByText( 'WooPayments overview page' )
		).toBeInTheDocument();
		expect( mockWooPaymentsRoutesLoaded ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders each page on a deep link with other query arguments and on a move between two WooPayments pages', async () => {
		setPaymentsPath( '/woopayments/settings&from=PAYMENTS_SETTINGS' );
		const { screen, act, history, cleanup: done } = await renderWrapper();
		cleanup = done;

		expect(
			await screen.findByText( 'WooPayments settings page' )
		).toBeInTheDocument();

		act( () => {
			pushPaymentsPath( history, '/woopayments/overview' );
		} );

		expect(
			await screen.findByText( 'WooPayments overview page' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'WooPayments settings page' )
		).not.toBeInTheDocument();
		expect( mockWooPaymentsRoutesLoaded ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [ '/woopayments', '/woopayments/unknown' ] )(
		'shows the payments list on %s, a WooPayments path without a page',
		async ( path ) => {
			setPaymentsPath( path );
			const { screen, cleanup: done } = await renderWrapper();
			cleanup = done;

			expect(
				await screen.findByText( 'Main payments list' )
			).toBeInTheDocument();
			expect( mockWooPaymentsRoutesLoaded ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'keeps the same payments list when the WooPayments onboarding opens over it', async () => {
		setPaymentsPath();
		const {
			screen,
			act,
			history,
			mainMounts,
			cleanup: done,
		} = await renderWrapper();
		cleanup = done;

		expect(
			await screen.findByText( 'Main payments list' )
		).toBeInTheDocument();
		expect( mainMounts ).toHaveBeenCalledTimes( 1 );

		act( () => {
			pushPaymentsPath( history, '/woopayments/onboarding' );
		} );
		await act( flushImports );

		expect( screen.getByText( 'Main payments list' ) ).toBeInTheDocument();
		expect( mainMounts ).toHaveBeenCalledTimes( 1 );
		expect( mockWooPaymentsRoutesLoaded ).not.toHaveBeenCalled();
	} );

	describe( 'when the routes chunk fails to load', () => {
		// React logs the error the boundary catches, and jsdom reports it again as uncaught; both are expected here.
		const preventDefault = ( event: Event ) => event.preventDefault();
		let consoleError: jest.SpyInstance;

		beforeAll( () => {
			window.addEventListener( 'error', preventDefault );
		} );

		beforeEach( () => {
			consoleError = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => {} );
		} );

		afterEach( () => {
			consoleError.mockRestore();
		} );

		afterAll( () => {
			window.removeEventListener( 'error', preventDefault );
		} );

		it( 'shows the WooCommerce error screen with Reload Page instead of the payments list', async () => {
			mockWooPaymentsRoutesLoaded.mockImplementationOnce( () => {
				throw new Error( 'ChunkLoadError' );
			} );
			setPaymentsPath( '/woopayments/overview' );
			const { screen, fireEvent, cleanup: done } = await renderWrapper();
			cleanup = done;

			expect(
				await screen.findByText( 'Oops, something went wrong', {
					ignore: '.a11y-speak-region',
				} )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Error: ChunkLoadError' )
			).toBeInTheDocument();
			expect(
				screen.queryByText( 'Main payments list' )
			).not.toBeInTheDocument();
			expect(
				screen.queryByText( 'Loading WooPayments…' )
			).not.toBeInTheDocument();

			const originalLocation = window.location;
			const reload = jest.fn();
			Object.defineProperty( window, 'location', {
				configurable: true,
				value: { ...originalLocation, reload },
			} );
			try {
				fireEvent.click(
					screen.getByRole( 'button', { name: 'Reload Page' } )
				);
			} finally {
				Object.defineProperty( window, 'location', {
					configurable: true,
					value: originalLocation,
				} );
			}
			expect( reload ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
