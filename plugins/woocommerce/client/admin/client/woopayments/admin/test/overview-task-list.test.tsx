/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { getHistory } from '@woocommerce/navigation';
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

// A row as the early fraud warnings route answers it.
const EARLY_FRAUD_WARNING = {
	order_id: 2483,
	order_number: '2483',
	charge_id: 'ch_warning_2483',
	created: 1795035195,
};

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

	// Client 11.3.0 `overview/task-list/tasks/dispute-task.tsx:54-78`: the action records the click, then moves inside the
	// app with `getHistory().push()`.
	it( 'records the client dispute task click and opens the disputes list inside the app, without a page load', async () => {
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

		const push = jest
			.spyOn( getHistory(), 'push' )
			.mockImplementation( () => undefined );
		const originalLocation = window.location;
		const mockAssign = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, assign: mockAssign },
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

		expect( push ).toHaveBeenCalledWith(
			'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes&filter=awaiting_response'
		);
		expect( mockAssign ).not.toHaveBeenCalled();
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{ task: 'dispute-resolution-task', active_dispute_count: 2 }
		);
	} );

	// Client 11.3.0 `early-fraud-warning-task.tsx:28-40`: the action moves inside the app with `getHistory().push()`.
	it( 'opens the payment details of an early fraud warning inside the app, without a page load', async () => {
		const tasks = buildOverviewTasks( {
			shell: {
				account: { connected: true },
				account_status: { requirements: { errors: [] } },
			} as never,
			disputesSummary: null,
			showUpdateDetailsTask: false,
			activeEarlyFraudWarnings: [ EARLY_FRAUD_WARNING ],
			onOpenUpdateBusinessDetails: jest.fn(),
		} );
		render(
			<OverviewTaskList
				tasks={ tasks }
				visibility={ createVisibility() }
			/>
		);

		const push = jest
			.spyOn( getHistory(), 'push' )
			.mockImplementation( () => undefined );
		const originalLocation = window.location;
		const mockAssign = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, assign: mockAssign },
		} );
		try {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Review payment' } )
			);
		} finally {
			Object.defineProperty( window, 'location', {
				configurable: true,
				value: originalLocation,
			} );
		}

		expect( push ).toHaveBeenCalledWith(
			'admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_warning_2483'
		);
		expect( mockAssign ).not.toHaveBeenCalled();
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{
				task: 'early-fraud-warning-task',
				active_early_fraud_warning_count: 1,
			}
		);
	} );

	// Client 11.3.0 `overview/task-list/index.js:189` with `expandable: true` on `update-business-details-task.tsx:164`,
	// `dispute-task.tsx:100` and `early-fraud-warning-task.tsx:59`.
	it.each( [
		[ 'update-business-details', 'Update WooPayments business details' ],
		[ 'dispute-resolution-task', 'Respond to an active dispute' ],
		[
			'early-fraud-warning-task',
			'Review order #2483 flagged for potential fraud',
		],
	] )(
		'folds and unfolds the %s task when its row is clicked',
		async ( keyPrefix, title ) => {
			const tasks = buildOverviewTasks( {
				shell: {
					account: { connected: true },
					account_status: {
						status: 'restricted',
						past_due: true,
						details_submitted: true,
						account_link: 'https://connect.example/update',
						requirements: { errors: [] },
					},
				} as never,
				disputesSummary: {
					count: 1,
					amount_by_currency: [],
					earliest_due_by: formatDueBy( NOW + DAY_IN_MS ),
				},
				activeEarlyFraudWarnings: [ EARLY_FRAUD_WARNING ],
				onOpenUpdateBusinessDetails: jest.fn(),
			} ).filter( ( task ) => task.key.startsWith( keyPrefix ) );
			render(
				<OverviewTaskList
					tasks={ tasks }
					visibility={ createVisibility() }
				/>
			);
			const row = screen
				.getByText( title )
				.closest( '.woocommerce-task-list__item' );

			expect( row ).toHaveClass( 'expanded' );
			await userEvent.click( screen.getByText( title ) );
			expect( row ).not.toHaveClass( 'expanded' );
			await userEvent.click( screen.getByText( title ) );
			expect( row ).toHaveClass( 'expanded' );
		}
	);

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
