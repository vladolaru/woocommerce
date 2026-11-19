/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { OverviewTaskList } from '../overview/components/overview-task-list';
import { buildOverviewTasks } from '../overview/components/overview-tasks';
import { saveOption } from '../../settings/data/actions';

const mockCreateSuccessNotice = jest.fn();

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: jest.fn( ( storeName ) =>
			storeName === 'core/notices'
				? { createSuccessNotice: mockCreateSuccessNotice }
				: actual.dispatch( storeName )
		),
	};
} );

jest.mock( '../../settings/data/actions', () => ( {
	saveOption: jest.fn(),
} ) );

const mockSaveOption = saveOption as jest.MockedFunction< typeof saveOption >;

const NOW = new Date( '2026-06-19T12:00:00.000Z' ).getTime();
const DAY_IN_MS = 24 * 60 * 60 * 1000;

// The two needs-response rows from the recorded native :8889 disputes list; see the file's `_meta`.
const RECORDED_AWAITING_RESPONSE = (
	JSON.parse(
		fs.readFileSync(
			path.join( __dirname, 'fixtures/recorded-disputes-list.json' ),
			'utf8'
		)
	).response.data as Array< Record< string, unknown > >
 ).filter( ( dispute ) => dispute.status === 'needs_response' );

// The disputes summary states `earliest_due_by` as a UTC `Y-m-d H:i:s` string.
const formatDueBy = ( timestamp: number ) =>
	new Date( timestamp ).toISOString().slice( 0, 19 ).replace( 'T', ' ' );

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
	onClick: jest.fn(),
	...overrides,
} );

describe( 'OverviewTaskList', () => {
	beforeEach( () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( NOW );
		mockSaveOption.mockReset();
		mockSaveOption.mockResolvedValue( undefined );
		mockCreateSuccessNotice.mockClear();
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

	it( 'records the client dispute task click and opens the disputes list', async () => {
		// Expected properties: client 11.2.0 `overview/task-list/tasks/dispute-task.tsx:54-58`.
		const tasks = buildOverviewTasks( {
			shell: {
				account: { connected: true },
				account_status: { requirements: { errors: [] } },
			} as never,
			disputesSummary: {
				count: RECORDED_AWAITING_RESPONSE.length,
				amount_by_currency: [],
				earliest_due_by: formatDueBy( NOW + DAY_IN_MS ),
			},
			onOpenUpdateBusinessDetails: jest.fn(),
		} ).filter( ( task ) => task.key.startsWith( 'dispute' ) );
		render(
			<OverviewTaskList
				tasks={ tasks }
				visibility={ createVisibility() }
			/>
		);

		const originalLocation = window.location;
		const mockAssign = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { assign: mockAssign },
		} );
		try {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'See disputes' } )
			);
		} finally {
			Object.defineProperty( window, 'location', {
				configurable: true,
				value: originalLocation,
			} );
		}

		expect( mockAssign ).toHaveBeenCalledWith(
			expect.stringContaining(
				'path=%2Fwoopayments%2Fdisputes&filter=awaiting_response'
			)
		);
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{ task: 'dispute-resolution-task', active_dispute_count: 2 }
		);
	} );

	// Client 11.3.0 `overview/task-list/index.js:56-104, :204-206`.
	it( 'dismisses a dismissable task at once, saves it, and puts it back on Undo', async () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask(
						'dispute-resolution-task-summary',
						'Dispute task'
					),
					createTask(
						'early-fraud-warning-task-ch_one',
						'Warning task',
						{
							isDismissable: true,
						}
					),
				] }
				visibility={ createVisibility( {
					dismissed_todo_tasks: [ 'earlier-task' ],
				} ) }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Task Options' } )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Dismiss' } )
		);

		await waitFor( () =>
			expect(
				screen.queryByText( 'Warning task' )
			).not.toBeInTheDocument()
		);
		expect( mockSaveOption ).toHaveBeenLastCalledWith(
			'woocommerce_dismissed_todo_tasks',
			[ 'earlier-task', 'early-fraud-warning-task-ch_one' ]
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Task dismissed',
			{ actions: [ { label: 'Undo', onClick: expect.any( Function ) } ] }
		);

		await act( async () => {
			mockCreateSuccessNotice.mock.calls[ 0 ][ 1 ].actions[ 0 ].onClick();
		} );

		expect( await screen.findByText( 'Warning task' ) ).toBeInTheDocument();
		expect( mockSaveOption ).toHaveBeenLastCalledWith(
			'woocommerce_dismissed_todo_tasks',
			[ 'earlier-task' ]
		);
	} );

	it( 'offers no task options on a task that cannot be dismissed', () => {
		render(
			<OverviewTaskList
				tasks={ [
					createTask( 'update-business-details', 'Details task' ),
					createTask(
						'dispute-resolution-task-summary',
						'Dispute task'
					),
				] }
				visibility={ createVisibility() }
			/>
		);

		expect( screen.getByText( 'Details task' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Dispute task' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Task Options' } )
		).not.toBeInTheDocument();
	} );

	// Client 11.3.0 `overview/task-list/index.js:166-173`: open at first, folding to the first five.
	it( 'folds a list of more than five tasks to the first five', async () => {
		render(
			<OverviewTaskList
				tasks={ [ 1, 2, 3, 4, 5, 6 ].map( ( index ) =>
					createTask( `task-${ index }`, `Task ${ index }` )
				) }
				visibility={ createVisibility() }
			/>
		);
		// CollapsibleList keeps the tasks past the fifth in a container of their own, open while it carries the wrapper class.
		const getFold = ( title: string ) =>
			screen
				.getByText( title )
				.closest( 'div.woocommerce-experimental-list' )
				?.parentElement ?? null;

		expect( getFold( 'Task 5' ) ).toBeNull();
		expect( getFold( 'Task 6' ) ).toHaveClass(
			'woocommerce-experimental-list-wrapper'
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: /Hide tasks/ } )
		);

		expect( getFold( 'Task 6' ) ).not.toHaveClass(
			'woocommerce-experimental-list-wrapper'
		);
		expect( getFold( 'Task 5' ) ).toBeNull();
		await userEvent.click(
			screen.getByRole( 'button', { name: /Show tasks/ } )
		);
		expect( getFold( 'Task 6' ) ).toHaveClass(
			'woocommerce-experimental-list-wrapper'
		);
	} );
} );
