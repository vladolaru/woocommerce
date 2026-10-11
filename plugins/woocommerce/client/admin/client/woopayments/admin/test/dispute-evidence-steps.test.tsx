/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { DisputeEvidenceForm } from '../money-movement/dispute-evidence-form';
import { updateWooPaymentsDispute } from '../money-movement/data';
import { useGetSettings } from '../../settings/data/hooks';
import type { WooPaymentsDispute } from '../money-movement/types';

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/a11y', () => ( {
	speak: jest.fn(),
} ) );

jest.mock( '../money-movement/data', () => ( {
	updateWooPaymentsDispute: jest.fn(),
	uploadWooPaymentsDisputeFile: jest.fn(),
} ) );

jest.mock( '../../settings/data/hooks', () => ( {
	useGetSettings: jest.fn(),
} ) );

const mockUpdateDispute = updateWooPaymentsDispute as jest.MockedFunction<
	typeof updateWooPaymentsDispute
>;

// The platform's disputes/:id answer, a Stripe dispute with its charge expanded (wpcom class-disputes-controller.php:293-305),
// reduced to the fields the form reads. A digital product, so the form has two steps: Purchase info and Review.
const makeDispute = (
	overrides: Partial< WooPaymentsDispute > = {}
): WooPaymentsDispute => ( {
	id: 'dp_test',
	reason: 'fraudulent',
	status: 'needs_response',
	amount: 5000,
	currency: 'usd',
	created: 1771423200,
	charge: {
		id: 'ch_test',
		created: 1771336800,
		billing_details: { name: 'Ada Lovelace' },
	},
	evidence: {},
	evidence_details: { due_by: 1772028000 },
	metadata: { __product_type: 'digital_product_or_service' },
	...overrides,
} );

const renderForm = ( dispute: WooPaymentsDispute ) =>
	render( <DisputeEvidenceForm dispute={ dispute } fileDetails={ {} } /> );

beforeEach( () => {
	mockUpdateDispute.mockReset();
	mockUpdateDispute.mockImplementation( async ( _id, data ) =>
		makeDispute( { evidence: data.evidence } )
	);
	( useGetSettings as jest.Mock ).mockReset();
} );

describe( 'Dispute evidence step labels', () => {
	// Client 11.1.0 `disputes/new-evidence/index.tsx:812-821` and `:1586-1590`: a step label click saves the draft, then changes step.
	it( 'saves the draft and opens the step when its label is clicked', async () => {
		renderForm( makeDispute() );
		const description = screen.getByRole( 'textbox', {
			name: 'Product or service description',
		} );
		await userEvent.clear( description );
		await userEvent.type( description, 'Downloaded software.' );

		await userEvent.click(
			screen.getByRole( 'button', { name: '2 Review' } )
		);

		expect(
			await screen.findByRole( 'textbox', { name: 'Cover letter' } )
		).toBeInTheDocument();
		expect( mockUpdateDispute ).toHaveBeenCalledTimes( 1 );
		expect( mockUpdateDispute ).toHaveBeenCalledWith(
			'dp_test',
			expect.objectContaining( {
				evidence: expect.objectContaining( {
					product_description: 'Downloaded software.',
				} ),
				metadata: expect.objectContaining( {
					__product_type: 'digital_product_or_service',
				} ),
				submit: false,
			} )
		);
	} );

	it( 'stays on the step when the draft save fails', async () => {
		mockUpdateDispute.mockRejectedValue( new Error( 'Platform down' ) );
		renderForm( makeDispute() );

		await userEvent.click(
			screen.getByRole( 'button', { name: '2 Review' } )
		);

		expect(
			await screen.findByText( 'Platform down' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'textbox', { name: 'Cover letter' } )
		).not.toBeInTheDocument();
	} );

	it( 'opens the step without saving when the dispute is closed', async () => {
		renderForm( makeDispute( { status: 'won' } ) );

		await userEvent.click(
			screen.getByRole( 'button', { name: '2 Review' } )
		);

		expect(
			await screen.findByRole( 'textbox', { name: 'Cover letter' } )
		).toBeInTheDocument();
		expect( mockUpdateDispute ).not.toHaveBeenCalled();
	} );
} );
