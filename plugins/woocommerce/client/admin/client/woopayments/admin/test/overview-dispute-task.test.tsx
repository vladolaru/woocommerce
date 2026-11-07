/**
 * External dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { buildOverviewTasks } from '../overview/components/overview-tasks';
import type {
	WooPaymentsOverviewDispute,
	WooPaymentsOverviewDisputesSummary,
} from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// Client 11.2.0 `overview/task-list/__tests__/tasks.test.js:23-60`: the single dispute's cached row.
const ACTIVE_DISPUTE = { dispute_id: 'dp_1', charge_id: 'ch_mock' };

const getDisputeTasks = (
	summary: WooPaymentsOverviewDisputesSummary | null,
	activeDispute?: WooPaymentsOverviewDispute
) =>
	buildOverviewTasks( {
		showUpdateDetailsTask: false,
		shell: {} as unknown as Parameters<
			typeof buildOverviewTasks
		>[ 0 ][ 'shell' ],
		disputesSummary: summary,
		activeDispute,
		onOpenUpdateBusinessDetails: jest.fn(),
	} );

const mockNow = ( isoDate: string ) =>
	jest.spyOn( Date, 'now' ).mockReturnValue( new Date( isoDate ).getTime() );

// Client 11.2.0 `overview/task-list/tasks/dispute-task.tsx:19-176`, tested in `__tests__/tasks.test.js:324-707`.
describe( 'overview dispute task (client 11.2.0 dispute-task.tsx)', () => {
	const originalDateSettings = getSettings();

	beforeEach( () => {
		// The client test's `wcpaySettings.dateFormat` and its Etc/GMT+5 site timezone.
		setSettings( {
			...originalDateSettings,
			formats: { ...originalDateSettings.formats, date: 'M j, Y' },
			timezone: {
				...originalDateSettings.timezone,
				offset: '-5',
				string: '',
			},
		} );
		mockNow( '2023-02-01T08:00:00.000Z' );
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
	} );

	afterEach( () => {
		setSettings( originalDateSettings );
		jest.restoreAllMocks();
	} );

	it.each( [
		[
			'one dispute due today, with its row',
			'2023-02-01T08:00:00.000Z',
			{
				count: 1,
				amount_by_currency: { usd: 1000 },
				earliest_due_by: '2023-02-01 23:59:59',
			},
			ACTIVE_DISPUTE,
			{
				key: 'dispute-resolution-task-dp_1',
				title: 'Respond to a dispute for $10.00 – Last day',
				content: 'Respond today by 6:59 PM',
				actionLabel: 'Respond now',
				isUrgent: true,
			},
		],
		[
			'one dispute due in six days',
			'2023-01-27T08:00:00.000Z',
			{
				count: 1,
				amount_by_currency: { usd: 1000 },
				earliest_due_by: '2023-02-01 23:59:59',
			},
			ACTIVE_DISPUTE,
			{
				key: 'dispute-resolution-task-dp_1',
				title: 'Respond to a dispute for $10.00',
				content: 'By Feb 1, 2023 – 6 days left to respond',
				actionLabel: 'Respond now',
				isUrgent: false,
			},
		],
		[
			'one dispute due tomorrow in the site timezone, within 24 hours',
			'2023-02-01T23:00:00.000Z',
			{
				count: 1,
				amount_by_currency: { usd: 1000 },
				earliest_due_by: '2023-02-02 13:00:00',
			},
			undefined,
			{
				key: 'dispute-resolution-task-summary',
				title: 'Respond to a dispute for $10.00',
				content: 'By Feb 2, 2023 – 14 hours left to respond',
				actionLabel: 'See disputes',
				isUrgent: true,
			},
		],
		[
			'one dispute due at 00:30 tomorrow in the site timezone',
			'2023-02-01T10:00:00.000Z',
			{
				count: 1,
				amount_by_currency: { usd: 1000 },
				earliest_due_by: '2023-02-02 05:30:00',
			},
			undefined,
			{
				title: 'Respond to a dispute for $10.00',
				content: 'By Feb 2, 2023 – 20 hours left to respond',
			},
		],
		[
			'one dispute due today without an amount',
			'2023-02-01T08:00:00.000Z',
			{
				count: 1,
				amount_by_currency: [],
				earliest_due_by: '2023-02-01 23:59:59',
			},
			undefined,
			{
				key: 'dispute-resolution-task-summary',
				title: 'Respond to an active dispute – Last day',
				content: 'Respond today by 6:59 PM',
				actionLabel: 'See disputes',
			},
		],
		[
			'51 disputes in one currency, the earliest overdue',
			'2023-02-01T08:00:00.000Z',
			{
				count: 51,
				amount_by_currency: { usd: 5100 },
				earliest_due_by: '2023-01-31 23:59:59',
			},
			undefined,
			{
				key: 'dispute-resolution-task-summary',
				title: 'Respond to 51 active disputes for a total of $51.00',
				content: 'Response overdue',
				actionLabel: 'See disputes',
				isUrgent: true,
			},
		],
		[
			'disputes in two currencies',
			'2023-01-27T08:00:00.000Z',
			{
				count: 3,
				amount_by_currency: { usd: 2000, eur: 1000 },
				earliest_due_by: '2023-02-01 23:59:59',
			},
			undefined,
			{
				key: 'dispute-resolution-task-summary',
				title: 'Respond to 3 active disputes',
				content: 'By Feb 1, 2023 – 6 days left to respond',
				actionLabel: 'See disputes',
			},
		],
		[
			'disputes without amounts',
			'2023-02-01T08:00:00.000Z',
			{
				count: 2,
				amount_by_currency: [],
				earliest_due_by: '2023-02-03 23:59:59',
			},
			undefined,
			{
				key: 'dispute-resolution-task-summary',
				title: 'Respond to 2 active disputes',
				content: 'By Feb 3, 2023 – 3 days left to respond',
			},
		],
	] )(
		'builds the task for %s',
		( _scenario, now, summary, activeDispute, expected ) => {
			mockNow( now );

			expect( getDisputeTasks( summary, activeDispute ) ).toEqual( [
				expect.objectContaining( expected ),
			] );
		}
	);

	it.each( [
		[ 'no summary', null ],
		[
			'a zero count',
			{ count: 0, earliest_due_by: '2023-02-03 23:59:59' },
		],
		[ 'a null deadline', { count: 1, earliest_due_by: null } ],
		[
			'a deadline after seven days',
			{ count: 1, earliest_due_by: '2023-02-09 23:59:59' },
		],
	] )( 'builds no task for %s', ( _scenario, summary ) => {
		expect( getDisputeTasks( summary ) ).toEqual( [] );
	} );
} );
