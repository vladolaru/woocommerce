/**
 * External dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { buildOverviewTasks } from '../overview/components/overview-tasks';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// Client 11.1.0 `overview/task-list/__tests__/tasks.test.js:14-104`: cached disputes store `due_by` as a UTC `Y-m-d H:i:s` string.
const createDispute = ( overrides: Record< string, unknown > = {} ) => ( {
	dispute_id: 'dp_1',
	charge_id: 'ch_mock',
	amount: 1000,
	currency: 'usd',
	status: 'needs_response',
	due_by: '2023-02-01 23:59:59',
	...overrides,
} );

const activeDisputes = [
	createDispute(),
	createDispute( { dispute_id: 'dp_2', due_by: '2023-02-03 23:59:59' } ),
	createDispute( {
		dispute_id: 'dp_3',
		currency: 'eur',
		due_by: '2023-02-07 23:59:59',
	} ),
	// The client ignores a dispute with an empty due date.
	createDispute( { dispute_id: 'dp_1', due_by: '' } ),
];

const getDisputeTasks = ( disputes: Record< string, unknown >[] ) =>
	buildOverviewTasks( {
		showUpdateDetailsTask: false,
		shell: {
			wpcom_reconnect_url: '',
		} as unknown as Parameters< typeof buildOverviewTasks >[ 0 ][ 'shell' ],
		disputes,
		onOpenUpdateBusinessDetails: jest.fn(),
	} );

const mockNow = ( isoDate: string ) =>
	jest.spyOn( Date, 'now' ).mockReturnValue( new Date( isoDate ).getTime() );

describe( 'overview dispute task copy (client 11.1.0 dispute-task.tsx:115-220)', () => {
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

	it( 'marks a single dispute due within 24 hours as its last day and shows the local due time', () => {
		expect( getDisputeTasks( [ activeDisputes[ 0 ] ] ) ).toEqual( [
			expect.objectContaining( {
				key: 'dispute-resolution-task-dp_1',
				title: 'Respond to a dispute for $10.00 – Last day',
				content: 'Respond today by 6:59 PM',
				actionLabel: 'Respond now',
			} ),
		] );
	} );

	it( 'shows the due date and the time left for a single dispute due later in the week', () => {
		mockNow( '2023-01-27T08:00:00.000Z' );

		expect( getDisputeTasks( [ activeDisputes[ 0 ] ] ) ).toEqual( [
			expect.objectContaining( {
				key: 'dispute-resolution-task-dp_1',
				title: 'Respond to a dispute for $10.00',
				content: 'By Feb 1, 2023 – 6 days left to respond',
				actionLabel: 'Respond now',
			} ),
		] );
	} );

	it( 'counts every active dispute in the title and the final-day ones in the content', () => {
		expect( getDisputeTasks( activeDisputes ) ).toEqual( [
			expect.objectContaining( {
				key: 'dispute-resolution-task-dp_1-dp_2-dp_3',
				title: 'Respond to 3 active disputes',
				content: 'Final day to respond to 1 of the disputes',
				actionLabel: 'See disputes',
			} ),
		] );
	} );

	it( 'totals single-currency disputes and counts the final-day ones', () => {
		expect( getDisputeTasks( activeDisputes.slice( 0, 2 ) ) ).toEqual( [
			expect.objectContaining( {
				key: 'dispute-resolution-task-dp_1-dp_2',
				title: 'Respond to 2 active disputes for a total of $20.00',
				content: 'Final day to respond to 1 of the disputes',
				actionLabel: 'See disputes',
			} ),
		] );
	} );

	it( 'counts every active dispute in the title and the ones due within 7 days in the content', () => {
		mockNow( '2023-01-27T08:00:00.000Z' );

		expect( getDisputeTasks( activeDisputes ) ).toEqual( [
			expect.objectContaining( {
				key: 'dispute-resolution-task-dp_1-dp_2-dp_3',
				title: 'Respond to 3 active disputes',
				content: 'Last week to respond to 1 of the disputes',
				actionLabel: 'See disputes',
			} ),
		] );
	} );

	it( 'shows no dispute task when no dispute is due within 7 days', () => {
		mockNow( '2023-01-24T08:00:00.000Z' );

		expect( getDisputeTasks( activeDisputes ) ).toEqual( [] );
	} );

	// Client 11.1.0 `disputes/utils.ts:41-61` `isDueWithin()`: a past-due dispute is not due within any window.
	it( 'shows no dispute task when the only dispute is past due', () => {
		mockNow( '2023-02-02T08:00:00.000Z' );

		expect( getDisputeTasks( [ activeDisputes[ 0 ] ] ) ).toEqual( [] );
	} );
} );
