import { subscribe, select, dispatch } from '@wordpress/data';
import { initTodoSync } from './todo-state-sync';

jest.mock( '@wordpress/data', () => ( {
	subscribe: jest.fn(),
	select: jest.fn(),
	dispatch: jest.fn(),
} ) );

describe( 'initTodoSync()', () => {
	let paymentState;
	let completedTodos;
	let setCompletedTodos;
	let listener;
	let consoleError;

	// The fork's payment store holds no Apple Pay, Google Pay, Fastlane or card button key, which the triggers also name.
	const forkState = ( overrides = {} ) => ( {
		'ppcp-gateway': { enabled: true },
		venmo: { enabled: false },
		'pay-later': { enabled: false },
		'ppcp-pwc': { enabled: false },
		...overrides,
	} );

	beforeEach( () => {
		jest.clearAllMocks();
		consoleError = jest.spyOn( console, 'error' ).mockImplementation();

		paymentState = forkState();
		completedTodos = [];
		setCompletedTodos = jest.fn();

		subscribe.mockImplementation( ( callback ) => {
			listener = callback;
		} );
		select.mockImplementation( ( storeName ) =>
			storeName === 'wc/paypal/payment'
				? { persistentData: () => paymentState }
				: { getCompletedTodos: () => completedTodos }
		);
		dispatch.mockReturnValue( { setCompletedTodos } );

		initTodoSync();
		// The first run only records the starting payment state.
		listener();
	} );

	afterEach( () => {
		consoleError.mockRestore();
	} );

	test( 'marks only enable_pwc completed when Pay with Crypto is enabled and the other trigger keys are absent', () => {
		paymentState = forkState( { 'ppcp-pwc': { enabled: true } } );

		expect( () => listener() ).not.toThrow();

		expect( setCompletedTodos ).toHaveBeenCalledTimes( 1 );
		expect( setCompletedTodos ).toHaveBeenCalledWith( [ 'enable_pwc' ] );
		expect( consoleError ).not.toHaveBeenCalled();
	} );

	test( 'dispatches nothing when a change touches none of the triggers', () => {
		paymentState = forkState( {
			venmo: { enabled: true },
			paypalShowLogo: true,
		} );

		expect( () => listener() ).not.toThrow();

		expect( setCompletedTodos ).not.toHaveBeenCalled();
		expect( consoleError ).not.toHaveBeenCalled();
	} );
} );
