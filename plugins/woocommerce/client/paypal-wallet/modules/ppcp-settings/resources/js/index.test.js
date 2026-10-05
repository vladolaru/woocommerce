/**
 * The settings app mounts into the container the Payments settings app renders, once per time the container appears.
 */
const CONTAINER_ID = 'ppcp-settings-container';

// Lets the observer callback run: jsdom delivers mutation records in a microtask.
const flushMutations = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'settings app mount', () => {
	let createRoot;
	let roots;
	let observers;
	const NativeMutationObserver = global.MutationObserver;

	const addContainer = () => {
		const node = document.createElement( 'div' );
		node.id = CONTAINER_ID;
		document.body.appendChild( node );
		return node;
	};

	const loadEntryPoint = () => {
		jest.isolateModules( () => {
			jest.doMock( 'react-dom/client', () => ( {
				createRoot: jest.fn( () => {
					const root = { render: jest.fn(), unmount: jest.fn() };
					roots.push( root );
					return root;
				} ),
			} ) );
			jest.doMock( './Components/App', () => () => null );

			// The entry point runs on load, so each test needs a fresh module registry and a require().
			/* eslint-disable @typescript-eslint/no-require-imports */
			createRoot = require( 'react-dom/client' ).createRoot;
			require( './index' );
			/* eslint-enable @typescript-eslint/no-require-imports */
		} );
	};

	beforeEach( () => {
		roots = [];
		observers = [];
		global.MutationObserver = class extends NativeMutationObserver {
			constructor( callback ) {
				super( callback );
				observers.push( this );
			}
		};
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		observers.forEach( ( observer ) => observer.disconnect() );
		global.MutationObserver = NativeMutationObserver;
		document.body.innerHTML = '';
	} );

	it( 'mounts once into a container that is already on the page', async () => {
		const node = addContainer();

		loadEntryPoint();
		await flushMutations();

		expect( createRoot ).toHaveBeenCalledTimes( 1 );
		expect( createRoot ).toHaveBeenCalledWith( node );
		expect( roots[ 0 ].render ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does nothing while there is no container', async () => {
		loadEntryPoint();
		await flushMutations();

		expect( createRoot ).not.toHaveBeenCalled();
	} );

	it( 'mounts once when the container appears, and does not mount again for unrelated changes', async () => {
		loadEntryPoint();
		const node = addContainer();
		await flushMutations();

		node.appendChild( document.createElement( 'span' ) );
		document.body.appendChild( document.createElement( 'div' ) );
		await flushMutations();

		expect( createRoot ).toHaveBeenCalledTimes( 1 );
		expect( createRoot ).toHaveBeenCalledWith( node );
	} );

	it( 'unmounts when the container is removed', async () => {
		loadEntryPoint();
		const node = addContainer();
		await flushMutations();

		node.remove();
		await flushMutations();

		expect( roots[ 0 ].unmount ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'mounts once more when the container comes back, without a second app alive at the same time', async () => {
		loadEntryPoint();
		addContainer();
		await flushMutations();
		document.getElementById( CONTAINER_ID ).remove();
		await flushMutations();

		const returned = addContainer();
		await flushMutations();

		expect( createRoot ).toHaveBeenCalledTimes( 2 );
		expect( createRoot ).toHaveBeenLastCalledWith( returned );
		expect( roots[ 0 ].unmount ).toHaveBeenCalledTimes( 1 );
		expect( roots[ 1 ].unmount ).not.toHaveBeenCalled();
	} );

	it( 'replaces the app when a new container takes the place of the old one', async () => {
		loadEntryPoint();
		const first = addContainer();
		await flushMutations();

		first.remove();
		const second = addContainer();
		await flushMutations();

		expect( createRoot ).toHaveBeenCalledTimes( 2 );
		expect( createRoot ).toHaveBeenLastCalledWith( second );
		expect( roots[ 0 ].unmount ).toHaveBeenCalledTimes( 1 );
		expect( roots[ 1 ].unmount ).not.toHaveBeenCalled();
	} );
} );
