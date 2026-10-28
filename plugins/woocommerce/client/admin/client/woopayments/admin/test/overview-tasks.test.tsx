/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';

/**
 * Internal dependencies
 */
import {
	buildOverviewTasks,
	formatTaskCurrency,
	getVisibleOverviewTasks,
} from '../overview/components/overview-tasks';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const DAY_IN_MS = 24 * 60 * 60 * 1000;
const NOW = new Date( '2026-06-19T12:00:00.000Z' ).getTime();

const createShell = ( overrides: Record< string, unknown > = {} ) => ( {
	account: {
		id: 'acct_test',
		mode: 'live',
		connected: true,
		working: true,
		details_submitted: true,
		test_mode: false,
		test_mode_onboarding: false,
		dev_mode: false,
		test_drive: false,
		sandbox: false,
		live: true,
	},
	account_status: {
		status: 'complete',
		current_deadline: 0,
		past_due: false,
		account_link: 'https://connect.example/update',
		requirements: {
			errors: [],
		},
		details_submitted: true,
		payments_enabled: true,
		deposits_enabled: true,
	},
	disputes_awaiting_response_count: null,
	overview_tasks_visibility: {
		dismissed_todo_tasks: [],
		deleted_todo_tasks: [],
		remind_me_later_todo_tasks: {},
	},
	is_connection_success_modal_dismissed: false,
	urls: {
		overview_page: '',
		settings: '',
		onboarding: '',
		setup: '',
	},
	...overrides,
} );

// A needs-response row from the recorded native :8889 disputes list; see the file's `_meta`.
const [ RECORDED_DISPUTE ] = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, 'fixtures/recorded-disputes-list.json' ),
		'utf8'
	)
).response.data as Array< Record< string, unknown > >;

// The disputes summary states `earliest_due_by` as a UTC `Y-m-d H:i:s` string.
const formatDueBy = ( timestamp: number ) =>
	new Date( timestamp ).toISOString().slice( 0, 19 ).replace( 'T', ' ' );

// The awaiting-response summary of the recorded dispute alone, due later today (client 11.2.0
// `overview/task-list/__tests__/tasks.test.js` summary shape).
const createSummary = ( overrides: Record< string, unknown > = {} ) => ( {
	count: 1,
	amount_by_currency: {
		[ RECORDED_DISPUTE.currency as string ]: RECORDED_DISPUTE.amount,
	},
	earliest_due_by: formatDueBy( NOW + 6 * 60 * 60 * 1000 ),
	...overrides,
} );

describe( 'overview task builders', () => {
	beforeEach( () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( NOW );
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: {
				adminUrl: 'https://example.com/wp-admin/',
			},
		} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'builds an incomplete setup task that routes to native onboarding', () => {
		const shell = createShell( {
			account_status: {
				...createShell().account_status,
				status: 'restricted',
				details_submitted: false,
			},
		} );

		const task = buildOverviewTasks( {
			shell,
			disputesSummary: null,
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		expect( task ).toMatchObject( {
			key: 'complete-setup',
			title: 'Finish setting up WooPayments',
			actionLabel: 'Finish setup',
		} );
		expect( task.href ).toContain( 'path=%2Fwoopayments%2Fonboarding' );
		expect( task.href ).toContain( 'source=wcpay-finish-setup-task' );
		expect( task.href ).toContain( 'from=WCPAY_OVERVIEW' );
	} );

	// The click behavior is covered in `overview-update-business-details-task-link.test.tsx`.
	it( 'builds a restricted-soon update task with the deadline', () => {
		const shell = createShell( {
			account_status: {
				...createShell().account_status,
				status: 'restricted_soon',
				current_deadline: Math.floor( ( NOW + DAY_IN_MS ) / 1000 ),
				requirements: {
					errors: [
						{
							code: 'verification_document_missing_front',
							reason: 'Upload the front of the document.',
						},
					],
				},
			},
		} );

		const task = buildOverviewTasks( {
			shell,
			disputesSummary: null,
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		expect( task ).toMatchObject( {
			key: 'update-business-details',
			title: 'Update WooPayments business details',
			actionLabel: 'Update',
		} );
		expect( task.content ).toContain( 'Update by' );
	} );

	it( 'filters generic requirement errors from the update details task content', () => {
		const shell = createShell( {
			account_status: {
				...createShell().account_status,
				status: 'restricted_soon',
				current_deadline: Math.floor( ( NOW + DAY_IN_MS ) / 1000 ),
				requirements: {
					errors: [
						{
							code: 'invalid_value_other',
							reason: 'Generic Stripe requirement.',
						},
						{
							code: 'verification_document_missing_front',
							reason: 'Upload the front of the document.',
						},
					],
				},
			},
		} );

		const task = buildOverviewTasks( {
			shell,
			disputesSummary: null,
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		expect( task.actionLabel ).toBe( 'Update' );
		expect( task.content ).toContain(
			'The uploaded file was missing the front of the document.'
		);
		expect( task.content ).not.toContain( 'Generic Stripe requirement.' );
	} );

	it( 'uses the more-details action when multiple requirement errors remain visible', () => {
		const shell = createShell( {
			account_status: {
				...createShell().account_status,
				status: 'restricted_soon',
				current_deadline: Math.floor( ( NOW + DAY_IN_MS ) / 1000 ),
				requirements: {
					errors: [
						{
							code: 'verification_document_missing_front',
						},
						{
							code: 'verification_document_missing_back',
						},
					],
				},
			},
		} );

		const task = buildOverviewTasks( {
			shell,
			disputesSummary: null,
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		expect( task ).toMatchObject( {
			key: 'update-business-details',
			actionLabel: 'More details',
		} );
		expect( task.content ).toContain( 'Update by' );
		expect( task.content ).not.toContain(
			'The uploaded file was missing the front of the document.'
		);
	} );

	it( 'builds an urgent single dispute task that links to the transaction details route', () => {
		const task = buildOverviewTasks( {
			showUpdateDetailsTask: false,
			shell: createShell(),
			disputesSummary: createSummary(),
			activeDispute: RECORDED_DISPUTE,
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		// Client 11.2.0 `dispute-task.tsx:80-88, 116-138`: due today in the site timezone adds the last-day suffix.
		expect( task ).toMatchObject( {
			key: `dispute-resolution-task-${ RECORDED_DISPUTE.dispute_id }`,
			title: 'Respond to a dispute for $50.00 – Last day',
			actionLabel: 'Respond now',
			level: 1,
		} );
		expect( task.href ).toContain(
			'path=%2Fwoopayments%2Ftransactions%2Fdetails'
		);
		expect( task.href ).toContain( `id=${ RECORDED_DISPUTE.charge_id }` );
	} );

	it( 'builds a multiple-dispute task that links to the awaiting-response dispute list', () => {
		const task = buildOverviewTasks( {
			showUpdateDetailsTask: false,
			shell: createShell(),
			disputesSummary: createSummary( {
				count: 2,
				amount_by_currency: { usd: 3500 },
			} ),
			onOpenUpdateBusinessDetails: jest.fn(),
		} )[ 0 ];

		expect( task ).toMatchObject( {
			key: 'dispute-resolution-task-summary',
			title: 'Respond to 2 active disputes for a total of $35.00',
			actionLabel: 'See disputes',
		} );
		expect( task.href ).toContain( 'path=%2Fwoopayments%2Fdisputes' );
		expect( task.href ).toContain( 'filter=awaiting_response' );
	} );

	// Client 11.2.0 `overview/task-list/tasks/dispute-task.tsx:89-90, 111-113`: red when overdue or due within 72 hours,
	// yellow before.
	it.each( [
		[ 'overdue', -DAY_IN_MS, true ],
		[ 'due in one day', DAY_IN_MS, true ],
		[ 'due in five days', 5 * DAY_IN_MS, false ],
	] )(
		'marks the dispute task urgent only when the earliest dispute is overdue or due within 72 hours (%s)',
		( _label, dueIn, isUrgent ) => {
			const task = buildOverviewTasks( {
				showUpdateDetailsTask: false,
				shell: createShell(),
				disputesSummary: createSummary( {
					count: 2,
					earliest_due_by: formatDueBy( NOW + dueIn ),
				} ),
				onOpenUpdateBusinessDetails: jest.fn(),
			} )[ 0 ];

			expect( task.isUrgent ).toBe( isUrgent );
		}
	);

	// Client 11.1.0 `overview/index.js:105` calls getTasks() without showGoLiveTask: the go-live task is a WC Home task only.
	it( 'never builds the go-live task, even for a connected test-mode account', () => {
		const tasks = buildOverviewTasks( {
			showUpdateDetailsTask: false,
			shell: createShell( {
				account: {
					...createShell().account,
					live: false,
					test_drive: true,
					test_mode_onboarding: true,
					dev_mode: false,
				},
			} ),
			disputesSummary: null,
			onOpenUpdateBusinessDetails: jest.fn(),
		} );

		expect( tasks ).toEqual( [] );
	} );

	// Client 11.1.0 `overview/index.js:105-111` sorts with `taskSort()` (`task-list/tasks.tsx:99-110`): completed tasks last.
	it( 'lists a completed task after the open ones', () => {
		const tasks = buildOverviewTasks( {
			shell: createShell(),
			disputesSummary: createSummary(),
			activeDispute: RECORDED_DISPUTE,
			onOpenUpdateBusinessDetails: jest.fn(),
		} );

		expect(
			tasks.map( ( { key, completed } ) => [ key, !! completed ] )
		).toEqual( [
			[
				`dispute-resolution-task-${ RECORDED_DISPUTE.dispute_id }`,
				false,
			],
			[ 'update-business-details', true ],
		] );
	} );

	it( 'filters dismissed, deleted, and currently snoozed tasks', () => {
		const tasks = [
			{ key: 'visible', title: 'Visible' },
			{ key: 'dismissed', title: 'Dismissed' },
			{ key: 'deleted', title: 'Deleted' },
			{ key: 'snoozed', title: 'Snoozed' },
		];

		expect(
			getVisibleOverviewTasks(
				tasks,
				{
					dismissed_todo_tasks: [ 'dismissed' ],
					deleted_todo_tasks: [ 'deleted' ],
					remind_me_later_todo_tasks: {
						snoozed: NOW + DAY_IN_MS,
					},
				},
				NOW
			)
		).toEqual( [ tasks[ 0 ] ] );
	} );

	it( 'formats dispute task currency amounts', () => {
		expect( formatTaskCurrency( 1000, 'usd' ) ).toBe( '$10.00' );
	} );
} );
