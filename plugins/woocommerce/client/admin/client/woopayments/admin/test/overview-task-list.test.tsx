/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { OverviewTaskList } from '../overview/components/overview-task-list';
import { buildOverviewTasks } from '../overview/components/overview-tasks';
import { saveOption } from '../../settings/data/actions';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );
jest.mock( '../../settings/data/actions', () => ( {
	saveOption: jest.fn(),
} ) );

const mockCreateSuccessNotice = jest.fn();

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) => {
			if ( storeName === 'core/notices' ) {
				return {
					createSuccessNotice: mockCreateSuccessNotice,
				};
			}

			return actual.dispatch( storeName );
		} ),
	};
} );

const mockSaveOption = saveOption as jest.MockedFunction< typeof saveOption >;

const NOW = new Date( '2026-06-19T12:00:00.000Z' ).getTime();
const DAY_IN_MS = 24 * 60 * 60 * 1000;

const createVisibility = ( overrides = {} ) => ( {
	dismissed_todo_tasks: [],
	deleted_todo_tasks: [],
	remind_me_later_todo_tasks: {},
	...overrides,
} );

const createTask = ( key: string, title: string, overrides = {} ) => ( {
	key,
	title,
	content: `${ title } content`,
	actionLabel: `${ title } action`,
	completed: false,
	isDismissable: true,
	isDeletable: true,
	allowSnooze: true,
	onClick: jest.fn(),
	...overrides,
} );

describe( 'OverviewTaskList', () => {
	beforeEach( () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( NOW );
		mockCreateSuccessNotice.mockClear();
		mockSaveOption.mockReset();
		mockSaveOption.mockResolvedValue( undefined );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'renders only tasks allowed by dismissed, deleted, and snoozed visibility state', () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask( 'visible', 'Visible task' ),
					createTask( 'dismissed', 'Dismissed task' ),
					createTask( 'deleted', 'Deleted task' ),
					createTask( 'snoozed', 'Snoozed task' ),
				] }
				visibility={ createVisibility( {
					dismissed_todo_tasks: [ 'dismissed' ],
					deleted_todo_tasks: [ 'deleted' ],
					remind_me_later_todo_tasks: {
						snoozed: NOW + DAY_IN_MS,
					},
				} ) }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Things to do' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Visible task' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Dismissed task' )
		).not.toBeInTheDocument();
		expect( screen.queryByText( 'Deleted task' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Snoozed task' ) ).not.toBeInTheDocument();
	} );

	it( 'records the client 11.1.0 dispute task click when the task link is followed', async () => {
		// Expected properties: client `overview/task-list/tasks/dispute-task.tsx:52-56`.
		const dispute = { dispute_id: 'dp_1', amount: 1000, currency: 'usd' };
		const tasks = buildOverviewTasks( {
			shell: {
				account: { connected: true },
				account_status: { requirements: { errors: [] } },
			} as never,
			disputes: [
				{ ...dispute, evidence_due_by: NOW + DAY_IN_MS },
				{ ...dispute, dispute_id: 'dp_2', evidence_due_by: NOW },
			],
			onOpenUpdateBusinessDetails: jest.fn(),
			onActivatePayments: jest.fn(),
		} ).filter( ( task ) => task.key.startsWith( 'dispute' ) );
		render(
			<OverviewTaskList
				tasks={ tasks }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'link', { name: 'See disputes' } )
		);

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{ task: 'dispute-resolution-task', active_dispute_count: 2 }
		);
	} );

	it( 'persists dismissed tasks and exposes an undo action', async () => {
		render(
			<OverviewTaskList
				tasks={ [ createTask( 'task-a', 'Task A' ) ] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Dismiss Task A' } )
		);

		expect( mockSaveOption ).toHaveBeenCalledWith(
			'woocommerce_dismissed_todo_tasks',
			[ 'task-a' ]
		);
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();

		act( () => {
			mockCreateSuccessNotice.mock.calls[ 0 ][ 1 ].actions[ 0 ].onClick();
		} );

		expect( mockSaveOption ).toHaveBeenLastCalledWith(
			'woocommerce_dismissed_todo_tasks',
			[]
		);
		expect( screen.getByText( 'Task A' ) ).toBeInTheDocument();
	} );

	it( 'moves focus to the next task action after dismissing a task', async () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask( 'task-a', 'Task A' ),
					createTask( 'task-b', 'Task B' ),
				] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Dismiss Task A' } )
		);

		const nextAction = screen.getByRole( 'button', {
			name: 'Task B action',
		} );

		await waitFor( () => expect( nextAction ).toHaveFocus() );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();
	} );

	it( 'moves focus to the next task action after deleting a task', async () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask( 'task-a', 'Task A' ),
					createTask( 'task-b', 'Task B' ),
				] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Delete Task A' } )
		);

		const nextAction = screen.getByRole( 'button', {
			name: 'Task B action',
		} );

		await waitFor( () => expect( nextAction ).toHaveFocus() );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();
	} );

	it( 'moves focus to the next task action after snoozing a task', async () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask( 'task-a', 'Task A' ),
					createTask( 'task-b', 'Task B' ),
				] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Remind me later Task A' } )
		);

		const nextAction = screen.getByRole( 'button', {
			name: 'Task B action',
		} );

		await waitFor( () => expect( nextAction ).toHaveFocus() );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();
	} );

	it( 'moves focus to the heading and keeps the live status after dismissing the last task', async () => {
		render(
			<OverviewTaskList
				tasks={ [ createTask( 'task-a', 'Task A' ) ] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Dismiss Task A' } )
		);

		const heading = screen.getByRole( 'heading', {
			name: 'Things to do',
		} );
		const status = screen.getByRole( 'status' );

		await waitFor( () => expect( heading ).toHaveFocus() );
		expect( status ).toHaveTextContent( 'Task dismissed.' );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();
	} );

	it( 'persists deleted tasks separately from dismissed tasks', async () => {
		render(
			<OverviewTaskList
				tasks={ [ createTask( 'task-a', 'Task A' ) ] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Delete Task A' } )
		);

		expect( mockSaveOption ).toHaveBeenCalledWith(
			'woocommerce_deleted_todo_tasks',
			[ 'task-a' ]
		);

		const heading = screen.getByRole( 'heading', {
			name: 'Things to do',
		} );
		const status = screen.getByRole( 'status' );

		await waitFor( () => expect( heading ).toHaveFocus() );
		expect( status ).toHaveTextContent( 'Task deleted.' );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();
	} );

	it( 'persists snoozed tasks until tomorrow and can undo the snooze', async () => {
		render(
			<OverviewTaskList
				tasks={ [ createTask( 'task-a', 'Task A' ) ] }
				visibility={ createVisibility() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Remind me later Task A' } )
		);

		expect( mockSaveOption ).toHaveBeenCalledWith(
			'woocommerce_remind_me_later_todo_tasks',
			{
				'task-a': NOW + DAY_IN_MS,
			}
		);

		const heading = screen.getByRole( 'heading', {
			name: 'Things to do',
		} );
		const status = screen.getByRole( 'status' );

		await waitFor( () => expect( heading ).toHaveFocus() );
		expect( status ).toHaveTextContent( 'Task postponed until tomorrow.' );
		expect( screen.queryByText( 'Task A' ) ).not.toBeInTheDocument();

		act( () => {
			mockCreateSuccessNotice.mock.calls[ 0 ][ 1 ].actions[ 0 ].onClick();
		} );

		expect( mockSaveOption ).toHaveBeenLastCalledWith(
			'woocommerce_remind_me_later_todo_tasks',
			{}
		);
		expect( screen.getByText( 'Task A' ) ).toBeInTheDocument();
	} );
} );
