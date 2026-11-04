/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { OverviewTaskList } from '../overview/components/overview-task-list';
import { buildOverviewTasks } from '../overview/components/overview-tasks';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

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
} );
