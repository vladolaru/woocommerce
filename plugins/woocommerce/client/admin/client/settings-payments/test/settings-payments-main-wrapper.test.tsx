const mockNativeRoutesLoaded = jest.fn();

jest.mock( '~/settings-payments/register-provider-routes', () => {
	mockNativeRoutesLoaded();
	const { registerSettingsPaymentsProviderRoute } = jest.requireActual(
		'~/settings-payments/provider-routes'
	);
	registerSettingsPaymentsProviderRoute( {
		id: 'test-native-overview',
		path: '/woopayments/overview',
		element: <div>Native overview page</div>,
	} );

	return {};
} );
jest.mock( '~/settings-payments/settings-payments-main', () => ( {
	__esModule: true,
	default: () => <div>Main payments list</div>,
} ) );
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
// Fresh module copies would load yjs (via core-data) more than once; nothing here uses the core-data store.
jest.mock( '@wordpress/core-data', () => ( {} ) );

const setPaymentsPath = ( path?: string ) => {
	const query = path ? `&path=${ path }` : '';
	window.history.replaceState(
		{},
		'',
		`/wp-admin/admin.php?page=wc-settings&tab=checkout${ query }`
	);
};

// The native routes load once per page, so each test gets fresh module copies.
const loadModules = async () => {
	jest.resetModules();

	return {
		// The pure entry registers no test hooks, so it can be loaded inside a test.
		rtl: await import( '@testing-library/react/pure' ),
		navigation: await import( '@woocommerce/navigation' ),
		settingsPayments: await import( '~/settings-payments' ),
	};
};

const renderWrapper = async () => {
	const { rtl, navigation, settingsPayments } = await loadModules();
	const { SettingsPaymentsMainWrapper } = settingsPayments;

	rtl.render( <SettingsPaymentsMainWrapper /> );

	return { ...rtl, history: navigation.getHistory() };
};

// Lets dynamic imports and lazy chunks settle.
const flushImports = () => new Promise( ( resolve ) => setTimeout( resolve ) );

describe( 'SettingsPaymentsMainWrapper native provider routes', () => {
	let cleanup: () => void = () => {};

	// The first fresh load transforms every module; keep that cost out of the first test's timeout.
	beforeAll( async () => {
		await loadModules();
		await import( '~/settings-payments/register-provider-routes' );
	}, 30000 );

	beforeEach( () => {
		mockNativeRoutesLoaded.mockClear();
		window.scrollTo = jest.fn();
	} );

	afterEach( () => {
		cleanup();
		setPaymentsPath();
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
		'does not load the native routes on %s',
		async ( _label, path, expectedPage ) => {
			setPaymentsPath( path );
			const { screen, act, cleanup: done } = await renderWrapper();
			cleanup = done;

			expect(
				await screen.findByText( expectedPage )
			).toBeInTheDocument();
			await act( flushImports );

			expect( mockNativeRoutesLoaded ).not.toHaveBeenCalled();
		}
	);

	it( 'loads and renders a native route on a direct /woopayments load', async () => {
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
			await screen.findByText( 'Native overview page' )
		).toBeInTheDocument();
		expect( mockNativeRoutesLoaded ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'loads and renders a native route after navigating into /woopayments', async () => {
		setPaymentsPath();
		const { screen, act, history, cleanup: done } = await renderWrapper();
		cleanup = done;

		expect(
			await screen.findByText( 'Main payments list' )
		).toBeInTheDocument();
		expect( mockNativeRoutesLoaded ).not.toHaveBeenCalled();

		act( () => {
			history.push(
				'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview'
			);
		} );

		expect(
			screen.queryByText( 'Main payments list' )
		).not.toBeInTheDocument();
		expect(
			await screen.findByText( 'Native overview page' )
		).toBeInTheDocument();
		expect( mockNativeRoutesLoaded ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'falls back instead of loading forever when the routes chunk fails, and retries on the next mount', async () => {
		mockNativeRoutesLoaded.mockImplementationOnce( () => {
			throw new Error( 'ChunkLoadError' );
		} );
		setPaymentsPath( '/woopayments/overview' );
		const { rtl, settingsPayments } = await loadModules();
		const { SettingsPaymentsMainWrapper } = settingsPayments;

		const first = rtl.render( <SettingsPaymentsMainWrapper /> );
		expect(
			await rtl.screen.findByText( 'Main payments list' )
		).toBeInTheDocument();
		expect(
			rtl.screen.queryByText( 'Loading WooPayments…' )
		).not.toBeInTheDocument();
		first.unmount();

		rtl.render( <SettingsPaymentsMainWrapper /> );
		cleanup = rtl.cleanup;

		expect(
			await rtl.screen.findByText( 'Native overview page' )
		).toBeInTheDocument();
		expect( mockNativeRoutesLoaded ).toHaveBeenCalledTimes( 2 );
	} );
} );
