/**
 * External dependencies
 */
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { buildOverviewTasks } from '../overview/components/overview-tasks';
import type { WooPaymentsOverviewAccountStatus } from '../overview/types';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// The nonce-protected `wcpay-login` link the Overview shell returns (`WooPaymentsOverviewService::get_dashboard_login_url()`).
const ACCOUNT_LINK =
	'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview&wcpay-login=1&_wpnonce=abc123';

const ID_NUMBER_MISMATCH = {
	code: 'verification_document_id_number_mismatch',
	reason: 'Stripe reason for the ID number.',
};
const ADDRESS_INVALID = {
	code: 'invalid_address_city_state_postal_code',
	reason: 'Stripe reason for the address.',
};

const buildTask = (
	accountStatus: Partial< WooPaymentsOverviewAccountStatus >
) => {
	const shell = createRecordedOverviewShell( {
		account_link: ACCOUNT_LINK,
		...accountStatus,
	} );
	const onOpenUpdateBusinessDetails = jest.fn();
	const [ task ] = buildOverviewTasks( {
		shell,
		disputes: [],
		onOpenUpdateBusinessDetails,
		onActivatePayments: jest.fn(),
	} );

	return { shell, task, onOpenUpdateBusinessDetails };
};

// Client 11.1.0 `overview/task-list/tasks/update-business-details-task.tsx:107-136`.
describe( 'WooPayments Overview update-business-details task click', () => {
	let openSpy: jest.SpyInstance;

	beforeEach( () => {
		openSpy = jest.spyOn( window, 'open' ).mockReturnValue( null );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		( recordEvent as jest.Mock ).mockClear();
	} );

	it.each( [
		[ 'no requirement errors', { status: 'restricted', past_due: true } ],
		[
			'one requirement error',
			{
				status: 'restricted_soon',
				current_deadline: 1791219600,
				requirements: { errors: [ ID_NUMBER_MISMATCH ] },
			},
		],
	] )(
		'opens the account link in a new tab with %s',
		( _label, accountStatus ) => {
			const { task, onOpenUpdateBusinessDetails } =
				buildTask( accountStatus );

			expect( task.key ).toBe( 'update-business-details' );
			expect( task.actionLabel ).toBe( 'Update' );
			expect( task.href ).toBeUndefined();

			task.onClick?.();

			expect( recordEvent ).toHaveBeenCalledTimes( 1 );
			expect( recordEvent ).toHaveBeenCalledWith(
				'wcpay_account_details_link_clicked',
				{ source: 'wcpay-update-business-details-task' }
			);
			expect( openSpy ).toHaveBeenCalledTimes( 1 );
			expect( openSpy ).toHaveBeenCalledWith(
				`${ ACCOUNT_LINK }&from=WCPAY_OVERVIEW&source=wcpay-update-business-details-task`,
				'_blank'
			);
			expect( onOpenUpdateBusinessDetails ).not.toHaveBeenCalled();
		}
	);

	it.each( [
		[ 'submitted', true ],
		[ 'unsubmitted', false ],
	] )(
		'opens the modal with several requirement errors and %s details',
		( _label, detailsSubmitted ) => {
			const { shell, task, onOpenUpdateBusinessDetails } = buildTask( {
				status: 'restricted',
				past_due: true,
				details_submitted: detailsSubmitted,
				requirements: {
					errors: [ ID_NUMBER_MISMATCH, ADDRESS_INVALID ],
				},
			} );

			expect( task.actionLabel ).toBe( 'More details' );
			expect( task.href ).toBeUndefined();

			task.onClick?.();

			expect( onOpenUpdateBusinessDetails ).toHaveBeenCalledWith( shell );
			expect( openSpy ).not.toHaveBeenCalled();
			expect( recordEvent ).not.toHaveBeenCalled();
		}
	);

	it( 'does nothing on click for a complete account', () => {
		const { task, onOpenUpdateBusinessDetails } = buildTask( {
			status: 'complete',
		} );

		task.onClick?.();

		expect( onOpenUpdateBusinessDetails ).not.toHaveBeenCalled();
		expect( openSpy ).not.toHaveBeenCalled();
		expect( recordEvent ).not.toHaveBeenCalled();
	} );
} );
