/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect } from '@wordpress/data';
import {
	getSettings as getDateSettings,
	setSettings as setDateSettings,
} from '@wordpress/date';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsDisputeReadiness,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';
import { REQUIREMENT_ERROR_MESSAGES } from '../overview/components/requirement-error-messages';
import type {
	WooPaymentsOverviewAccountStatus,
	WooPaymentsOverviewShell,
} from '../overview/types';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../../settings/data/actions', () => ( { saveOption: jest.fn() } ) );

// The client shows the update-details task only after the Stripe banner fails to load (`overview/index.js:72-74,328-338`).
jest.mock( '../overview/components/stripe-notifications-banner', () => {
	const { useEffect } = jest.requireActual( '@wordpress/element' );

	return ( {
		onLoadError,
	}: {
		onLoadError: ( error: { error: { type: string } } ) => void;
	} ) => {
		// eslint-disable-next-line react-hooks/exhaustive-deps
		useEffect( () => onLoadError( { error: { type: 'api_error' } } ), [] );
		return null;
	};
} );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsDisputeReadiness: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// 2026-10-05 17:00 UTC, which the client formats as "5pm Oct 5, 2026" (`ga M j, Y`).
const DEADLINE = 1791219600;
const TITLE = 'Update WooPayments business details';

const ID_NUMBER_MISMATCH = {
	code: 'verification_document_id_number_mismatch',
	reason: 'Stripe reason for the ID number.',
};
const ADDRESS_INVALID = {
	code: 'invalid_address_city_state_postal_code',
	reason: 'Stripe reason for the address.',
};

// Client 11.1.0 `overview/task-list/strings.tsx:9-287`, in source order.
const CLIENT_REQUIREMENT_ERROR_CODES = [
	'invalid_address_city_state_postal_code',
	'invalid_street_address',
	'invalid_tos_acceptance',
	'invalid_representative_country',
	'verification_document_address_mismatch',
	'verification_document_address_missing',
	'verification_document_corrupt',
	'verification_document_country_not_supported',
	'verification_document_dob_mismatch',
	'verification_document_duplicate_type',
	'verification_document_expired',
	'verification_document_failed_copy',
	'verification_document_failed_greyscale',
	'verification_document_failed_other',
	'verification_document_failed_test_mode',
	'verification_document_fraudulent',
	'verification_document_id_number_mismatch',
	'verification_document_id_number_missing',
	'verification_document_incomplete',
	'verification_document_invalid',
	'verification_document_issue_or_expiry_date_missing',
	'verification_document_manipulated',
	'verification_document_missing_back',
	'verification_document_missing_front',
	'verification_document_name_mismatch',
	'verification_document_name_missing',
	'verification_document_nationality_mismatch',
	'verification_document_not_readable',
	'verification_document_not_signed',
	'verification_document_not_uploaded',
	'verification_document_photo_mismatch',
	'verification_document_too_large',
	'verification_document_type_not_supported',
	'verification_failed_address_match',
	'verification_failed_business_iec_number',
	'verification_failed_document_match',
	'verification_failed_id_number_match',
	'verification_failed_keyed_identity',
	'verification_failed_keyed_match',
	'verification_failed_name_match',
	'verification_failed_residential_address',
	'verification_failed_tax_id_match',
	'verification_failed_tax_id_not_issued',
	'verification_failed_other',
	'verification_missing_owners',
	'verification_missing_executives',
	'verification_requires_additional_memorandum_of_associations',
	'invalid_dob_age_under_18',
];

const renderWithAccountStatus = async (
	accountStatus: Partial< WooPaymentsOverviewAccountStatus >,
	overrides: Partial< WooPaymentsOverviewShell > = {}
) => {
	( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
		createRecordedOverviewShell(
			{ account_link: 'https://example.com/login', ...accountStatus },
			overrides
		)
	);

	render( <WooPaymentsOverviewPage /> );

	await screen.findByRole( 'heading', { name: 'Account details' } );
};

const getTask = async ( title = TITLE ) => {
	// Client 11.1.0 `overview/task-list`: each task is a TaskItem row titled in its title span.
	const task = (
		await screen.findByText( title, {
			selector: '.woocommerce-task-list__item-title',
		} )
	).closest( 'li' );
	if ( ! task ) {
		throw new Error( `No task item for "${ title }".` );
	}

	return task;
};

describe( 'WooPayments Overview update-business-details parity', () => {
	const originalDateSettings = getDateSettings();

	beforeAll( () => {
		setDateSettings( {
			...originalDateSettings,
			timezone: {
				offset: '0',
				offsetFormatted: '',
				string: '',
				abbr: '',
			},
		} );
	} );

	afterAll( () => {
		setDateSettings( originalDateSettings );
	} );

	beforeEach( () => {
		( useSelect as jest.Mock ).mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [],
		} );
		( getWooPaymentsDepositsOverview as jest.Mock ).mockReturnValue(
			new Promise( () => {} )
		);
		( getWooPaymentsRecentDeposits as jest.Mock ).mockResolvedValue( {
			data: [],
		} );
		( getWooPaymentsDisputeReadiness as jest.Mock ).mockResolvedValue( {
			overview: { enabled: false },
		} );
	} );

	describe( 'requirement error copy', () => {
		it( 'keys all 48 client messages by the client codes', () => {
			expect( Object.keys( REQUIREMENT_ERROR_MESSAGES ) ).toEqual(
				CLIENT_REQUIREMENT_ERROR_CODES
			);
		} );

		it( 'renders the client link messages with their links', () => {
			render(
				<>{ REQUIREMENT_ERROR_MESSAGES.invalid_tos_acceptance }</>
			);

			expect(
				screen.getByText( /The existing terms of service signature/ )
			).toHaveTextContent(
				'The existing terms of service signature has been invalidated because the account’s tax ID has changed. The account needs to accept the terms of service again. For more information, see this documentation.'
			);
			expect(
				screen.getByRole( 'link', { name: 'this documentation' } )
			).toHaveAttribute(
				'href',
				'https://stripe.com/docs/connect/update-verified-information'
			);
		} );
	} );

	describe( 'task', () => {
		it( 'shows the single mapped error and the deadline for restricted_soon', async () => {
			await renderWithAccountStatus( {
				status: 'restricted_soon',
				current_deadline: DEADLINE,
				requirements: { errors: [ ID_NUMBER_MISMATCH ] },
			} );

			const task = await getTask();

			expect( task ).toHaveTextContent(
				'The company ID number on the account could not be verified. Correct any errors in the ID number field or upload a document that includes the ID number. Update by 5pm Oct 5, 2026 to avoid a disruption in payouts.'
			);
			expect(
				within( task ).getByRole( 'button', { name: 'Update' } )
			).toBeInTheDocument();
		} );

		it( 'shows only the deadline sentence for restricted_soon with several errors', async () => {
			await renderWithAccountStatus( {
				status: 'restricted_soon',
				current_deadline: DEADLINE,
				requirements: {
					errors: [ ID_NUMBER_MISMATCH, ADDRESS_INVALID ],
				},
			} );

			const task = await getTask();

			expect(
				within( task ).getByText(
					'Update by 5pm Oct 5, 2026 to avoid a disruption in payouts.'
				)
			).toBeInTheDocument();
			expect(
				within( task ).getByRole( 'button', { name: 'More details' } )
			).toBeInTheDocument();
		} );

		it( 'falls back to the platform reason for a code the client does not map', async () => {
			await renderWithAccountStatus( {
				status: 'restricted',
				past_due: true,
				requirements: {
					errors: [
						{ code: 'unmapped_code', reason: 'Platform reason.' },
					],
				},
			} );

			expect(
				within( await getTask() ).getByText( 'Platform reason.' )
			).toBeInTheDocument();
			expect(
				screen.queryByText( 'unmapped_code' )
			).not.toBeInTheDocument();
		} );

		it( 'explains the disabled account for past-due restricted accounts', async () => {
			await renderWithAccountStatus( {
				status: 'restricted',
				past_due: true,
			} );

			expect(
				within( await getTask() ).getByText(
					'Payments and payouts are disabled for this account until missing business information is updated.'
				)
			).toBeInTheDocument();
		} );

		it( 'explains unfinished setup for past-due restricted accounts that never submitted details', async () => {
			await renderWithAccountStatus( {
				status: 'restricted',
				past_due: true,
				details_submitted: false,
			} );

			const task = await getTask( 'Finish setting up WooPayments' );

			expect(
				within( task ).getByText(
					'Payments and payouts are disabled for this account until setup is completed.'
				)
			).toBeInTheDocument();
			expect(
				within( task ).getByRole( 'button', { name: 'Finish setup' } )
			).toBeInTheDocument();
		} );

		// The client only has "Update by" copy for restricted_soon with a deadline, and past-due copy for restricted.
		it.each( [
			[ 'restricted_soon', { current_deadline: null } ],
			[ 'restricted_partially', { current_deadline: DEADLINE } ],
			[ 'pending_verification', { current_deadline: DEADLINE } ],
			[ 'restricted', { current_deadline: DEADLINE, past_due: false } ],
		] )(
			'shows the title without a description for %s',
			async ( status, fields ) => {
				await renderWithAccountStatus( { status, ...fields } );

				const task = await getTask();

				// Only the action button sits under the title.
				expect(
					task.querySelector(
						'.woocommerce-task-list__item-expandable-content'
					)
				).toHaveTextContent( /^Update$/ );
			}
		);

		// Client 11.1.0 `@woocommerce/experimental` TaskItem shows a completed task as done, with no action button.
		it( 'shows the task as done, with no Update or View details action, for a complete account', async () => {
			await renderWithAccountStatus( { status: 'complete' } );

			const task = await getTask();

			expect( task ).toHaveClass( 'complete' );
			expect(
				within( task ).queryByRole( 'button', { name: 'Update' } )
			).not.toBeInTheDocument();
			expect(
				within( task ).queryByRole( 'button', { name: 'View details' } )
			).not.toBeInTheDocument();
		} );

		it( 'cannot be dismissed or snoozed, and ignores stored dismissals and snoozes', async () => {
			await renderWithAccountStatus(
				{ status: 'restricted', past_due: true },
				{
					overview_tasks_visibility: {
						dismissed_todo_tasks: [ 'update-business-details' ],
						deleted_todo_tasks: [],
						remind_me_later_todo_tasks: {
							'update-business-details': Date.now() + 60000,
						},
					},
				}
			);

			const task = await getTask();

			expect(
				within( task ).queryByRole( 'button', { name: /Dismiss/ } )
			).not.toBeInTheDocument();
			expect(
				within( task ).queryByRole( 'button', {
					name: /Remind me later/,
				} )
			).not.toBeInTheDocument();
		} );

		it( 'ignores a stored dismissal of the finish-setup task', async () => {
			await renderWithAccountStatus(
				{
					status: 'restricted',
					past_due: true,
					details_submitted: false,
				},
				{
					overview_tasks_visibility: {
						dismissed_todo_tasks: [],
						deleted_todo_tasks: [ 'complete-setup' ],
						remind_me_later_todo_tasks: {},
					},
				}
			);

			expect(
				await getTask( 'Finish setting up WooPayments' )
			).toBeInTheDocument();
		} );
	} );

	describe( 'modal', () => {
		it( 'lists the mapped errors under the restricted description', async () => {
			await renderWithAccountStatus( {
				status: 'restricted',
				past_due: true,
				requirements: {
					errors: [ ID_NUMBER_MISMATCH, ADDRESS_INVALID ],
				},
			} );

			await userEvent.click(
				within( await getTask() ).getByRole( 'button', {
					name: 'More details',
				} )
			);

			const modal = screen.getByRole( 'dialog', {
				name: 'Update business details',
			} );
			expect(
				within( modal ).getByText(
					'Payments and payouts are disabled for this account until missing information is updated. Please update the following information in the Stripe dashboard.'
				)
			).toBeInTheDocument();
			expect(
				within( modal ).getByText(
					'The company ID number on the account could not be verified. Correct any errors in the ID number field or upload a document that includes the ID number.'
				)
			).toBeInTheDocument();
			expect(
				within( modal ).getByText(
					'The combination of the city, state, and postal code in the provided address could not be validated.'
				)
			).toBeInTheDocument();
			expect(
				within( modal ).queryByText( ID_NUMBER_MISMATCH.code )
			).not.toBeInTheDocument();
			expect(
				within( modal ).getByRole( 'button', {
					name: 'Update business details',
				} )
			).toBeInTheDocument();
		} );

		it( 'says "Update by" with the deadline for restricted_soon', async () => {
			await renderWithAccountStatus( {
				status: 'restricted_soon',
				current_deadline: DEADLINE,
				requirements: {
					errors: [ ID_NUMBER_MISMATCH, ADDRESS_INVALID ],
				},
			} );

			await userEvent.click(
				within( await getTask() ).getByRole( 'button', {
					name: 'More details',
				} )
			);

			expect(
				within(
					screen.getByRole( 'dialog', {
						name: 'Update business details',
					} )
				).getByText(
					'Additional information is required to verify your business. Update by 5pm Oct 5, 2026 to avoid a disruption in payouts.'
				)
			).toBeInTheDocument();
		} );
	} );
} );
