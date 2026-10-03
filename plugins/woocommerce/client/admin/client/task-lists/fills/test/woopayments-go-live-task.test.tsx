/**
 * External dependencies
 */
import type { TaskListType, TaskType } from '@woocommerce/data';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );
// Fresh module copies would load yjs (via core-data) more than once; nothing here uses the core-data store.
jest.mock( '@wordpress/core-data', () => ( {} ) );
// Keep the other core task fills out of this test; only the WooPayments go-live fill matters here.
jest.mock( '~/task-lists/fills/PaymentGatewaySuggestions', () => ( {} ) );
jest.mock( '~/task-lists/fills/shipping', () => ( {} ) );
jest.mock( '~/task-lists/fills/Marketing', () => ( {} ) );
jest.mock( '~/task-lists/fills/appearance', () => ( {} ) );
jest.mock( '~/task-lists/fills/tax', () => ( {} ) );
jest.mock( '~/task-lists/fills/deprecated-tasks', () => ( {} ) );
jest.mock( '~/task-lists/fills/launch-your-store', () => ( {} ) );
jest.mock( '~/task-lists/fills/products', () => ( {} ) );
jest.mock( '~/task-lists/fills/import-products', () => ( {} ) );
jest.mock( '~/task-lists/fills/shipping-recommendation', () => ( {} ) );
jest.mock( '~/task-lists/fills/utils', () => ( {
	isImportProduct: () => false,
} ) );

const GO_LIVE_PLUGIN = 'woocommerce-woopayments-task-go-live';

const taskList = ( taskIds: string[] ) =>
	( {
		id: 'extended',
		tasks: taskIds.map( ( id ) => ( { id } ) as TaskType ),
	} ) as TaskListType;

// Each test needs a fresh fills module, so every registry it touches is loaded fresh too.
const loadModules = async () => {
	jest.resetModules();

	return {
		data: await import( '@wordpress/data' ),
		wcData: await import( '@woocommerce/data' ),
		plugins: await import( '@wordpress/plugins' ),
		// The pure entry registers no test hooks, so it can be loaded inside a test.
		rtl: await import( '@testing-library/react/pure' ),
		components: await import( '@wordpress/components' ),
		onboarding: await import( '@woocommerce/onboarding' ),
	};
};

type Modules = Awaited< ReturnType< typeof loadModules > >;

// Stands in for what the task list's `getTaskLists` resolver stores once the REST request returns.
const resolveTaskLists = ( modules: Modules, taskLists: TaskListType[] ) => {
	const { dispatch } = modules.data;
	const store = dispatch( modules.wcData.onboardingStore );

	store.getTaskListsSuccess( taskLists );
	store.finishResolution( 'getTaskLists', [] );
};

const importFills = () => import( '~/task-lists/fills' );

// Lets the dynamic import of the fill settle.
const flushImports = () => new Promise( ( resolve ) => setTimeout( resolve ) );

describe( 'WC Home task fills: WooPayments go-live task', () => {
	// The first fresh load transforms every module; keep that cost out of the first test's timeout.
	beforeAll( async () => {
		await loadModules();
		await importFills();
		await import( '~/woopayments/home-tasks/go-live-task' );
	}, 30000 );

	it( 'does not load the go-live fill when the task list lacks the task', async () => {
		const modules = await loadModules();
		resolveTaskLists( modules, [ taskList( [ 'products' ] ) ] );

		await importFills();
		await flushImports();

		expect( modules.plugins.getPlugin( GO_LIVE_PLUGIN ) ).toBeUndefined();
	} );

	it( 'loads the go-live fill when the task list already contains the task', async () => {
		const modules = await loadModules();
		resolveTaskLists( modules, [
			taskList( [ 'products', 'go-live-payments' ] ),
		] );

		await importFills();
		await flushImports();

		expect( modules.plugins.getPlugin( GO_LIVE_PLUGIN ) ).toBeDefined();
	} );

	it( 'loads the go-live fill into a rendered task slot once the task list resolves with the task', async () => {
		const modules = await loadModules();
		const { render, screen, act } = modules.rtl;
		const { SlotFillProvider } = modules.components;
		const { PluginArea } = modules.plugins;
		const { WooOnboardingTaskListItem } = modules.onboarding;

		await importFills();
		render(
			<SlotFillProvider>
				<WooOnboardingTaskListItem.Slot
					id="go-live-payments"
					fillProps={ {
						defaultTaskItem: () => (
							<button type="button">Activate payments</button>
						),
					} }
				/>
				<PluginArea scope="woocommerce-tasks" />
			</SlotFillProvider>
		);
		await act( flushImports );

		expect(
			screen.queryByRole( 'button', { name: 'Activate payments' } )
		).not.toBeInTheDocument();

		await act( async () => {
			resolveTaskLists( modules, [
				taskList( [ 'products', 'go-live-payments' ] ),
			] );
			await flushImports();
		} );

		expect(
			await screen.findByRole( 'button', { name: 'Activate payments' } )
		).toBeInTheDocument();
		modules.rtl.cleanup();
	} );
} );
