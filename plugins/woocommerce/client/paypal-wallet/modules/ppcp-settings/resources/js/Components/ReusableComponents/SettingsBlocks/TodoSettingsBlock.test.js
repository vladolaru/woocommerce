import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import TodoSettingsBlock from './TodoSettingsBlock';

const mockCompleteOnClick = jest.fn();
const mockSelectTab = jest.fn( () => Promise.resolve() );

// Stable references: the component resets its state in an effect that depends on `dismissedTodos`.
const mockSelection = { completedTodos: [], dismissedTodos: [] };

jest.mock( '@wordpress/data', () => ( {
	useSelect: () => mockSelection,
	useDispatch: () => ( { completeOnClick: mockCompleteOnClick } ),
} ) );

jest.mock( '@ppcp-settings/data/todos', () => ( {
	STORE_NAME: 'todos-store',
} ) );

jest.mock( '@ppcp-settings/utils/tabSelector', () => ( {
	TAB_IDS: { SETTINGS: 'settings-tab' },
	useSelectTab: () => mockSelectTab,
} ) );

const todosData = [
	{
		id: 'todo-1',
		title: 'Connect your account',
		description: 'Finish the setup.',
		action: { type: 'tab', tab: 'settings', section: 'section-1' },
	},
];

describe( 'TodoSettingsBlock', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the row action and the dismiss control as two separate buttons', () => {
		render(
			<TodoSettingsBlock
				todosData={ todosData }
				setActiveModal={ jest.fn() }
				onDismissTodo={ jest.fn() }
			/>
		);

		const action = screen.getByRole( 'button', {
			name: /Connect your account/,
		} );
		const dismiss = screen.getByRole( 'button', {
			name: 'Dismiss todo item',
		} );

		expect( action ).not.toContainElement( dismiss );
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 2 );
	} );

	it( 'runs the todo action when the row button is clicked', () => {
		render(
			<TodoSettingsBlock
				todosData={ todosData }
				setActiveModal={ jest.fn() }
				onDismissTodo={ jest.fn() }
			/>
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: /Connect your account/ } )
		);

		expect( mockSelectTab ).toHaveBeenCalledWith(
			'settings-tab',
			'section-1',
			false
		);
	} );

	it( 'dismisses a todo without running its action', () => {
		jest.useFakeTimers();
		const onDismissTodo = jest.fn();

		render(
			<TodoSettingsBlock
				todosData={ todosData }
				setActiveModal={ jest.fn() }
				onDismissTodo={ onDismissTodo }
			/>
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Dismiss todo item' } )
		);
		jest.advanceTimersByTime( 300 );

		expect( onDismissTodo ).toHaveBeenCalledWith( 'todo-1' );
		expect( mockSelectTab ).not.toHaveBeenCalled();

		jest.useRealTimers();
	} );
} );
