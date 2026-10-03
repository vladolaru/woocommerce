/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { WooPaymentsVatModal } from '../documents/vat-modal';
import {
	saveWooPaymentsVatDetails,
	validateWooPaymentsVatNumber,
} from '../documents/data';

jest.mock( '../documents/data', () => ( {
	saveWooPaymentsVatDetails: jest.fn(),
	validateWooPaymentsVatNumber: jest.fn(),
} ) );

const mockSaveVat = saveWooPaymentsVatDetails as jest.MockedFunction<
	typeof saveWooPaymentsVatDetails
>;
const mockValidateVat = validateWooPaymentsVatNumber as jest.MockedFunction<
	typeof validateWooPaymentsVatNumber
>;

describe( 'WooPaymentsVatModal', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockValidateVat.mockResolvedValue( {
			valid: true,
			vat_number: '123456789',
			name: 'Ada Bakery',
			address: '1 Market Street',
			country_code: 'DE',
		} );
		mockSaveVat.mockResolvedValue( {
			vat_number: 'DE 123456789',
			name: 'Ada Bakery',
			address: '2 Market Street',
		} );
	} );

	it( 'keeps focus on the edited tax details field while details are updated', async () => {
		const onCompleted = jest.fn();

		render(
			<WooPaymentsVatModal
				country="DE"
				onClose={ jest.fn() }
				onCompleted={ onCompleted }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'I have a valid VAT Number',
			} )
		);
		await userEvent.type(
			screen.getByLabelText( 'VAT Number' ),
			'123456789'
		);
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );

		const businessName = await screen.findByLabelText( 'Business name' );
		expect( businessName ).toHaveValue( 'Ada Bakery' );
		expect( businessName ).toHaveFocus();

		const address = screen.getByLabelText( 'Address' );
		await userEvent.clear( address );
		await userEvent.type( address, '2 Market Street' );

		expect( address ).toHaveFocus();

		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );

		await waitFor( () =>
			expect( mockSaveVat ).toHaveBeenCalledWith( {
				vat_number: '123456789',
				name: 'Ada Bakery',
				address: '2 Market Street',
			} )
		);
		expect( onCompleted ).toHaveBeenCalledWith( {
			vat_number: 'DE 123456789',
			name: 'Ada Bakery',
			address: '2 Market Street',
		} );
	} );

	// Prefixes, tax ID names and error wording from client 11.1.0
	// `client/vat/form/tasks/vat-number-task.tsx:30-171` and its `index.test.tsx:53-60`.
	it( 'pre-fills the Swiss prefix and validates the number without it', async () => {
		render(
			<WooPaymentsVatModal
				country="CH"
				onClose={ jest.fn() }
				onCompleted={ jest.fn() }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'I have a valid VAT Number',
			} )
		);
		const field = screen.getByLabelText( 'VAT Number' );
		expect( field ).toHaveValue( 'CHE ' );
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByText(
				'8 to 12 digits with your country code prefix, for example DE 123456789.'
			)
		).toBeInTheDocument();

		await userEvent.type( field, '123.456.789' );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );

		expect( mockValidateVat ).toHaveBeenCalledWith( '123.456.789' );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Confirm your business details',
			} )
		).toBeInTheDocument();
	} );

	it( 'names the Japanese Corporate Number in the refusal of an invalid one', async () => {
		mockValidateVat.mockRejectedValue( {
			code: 'wcpay_invalid_tax_number',
			message: 'Error: The provided VAT number failed validation.',
		} );

		render(
			<WooPaymentsVatModal
				country="JP"
				onClose={ jest.fn() }
				onCompleted={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Set your Corporate Number' } )
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'I have a valid Corporate Number',
			} )
		);
		const field = screen.getByLabelText( 'Corporate Number' );
		expect( field ).toHaveValue( '' );
		await userEvent.type( field, '12345' );
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Continue' } )
			);
		} );

		expect( mockValidateVat ).toHaveBeenCalledWith( '12345' );
		await waitFor( () =>
			expect(
				document.querySelector( '.components-notice__content' )
			).toHaveTextContent(
				'The provided Corporate Number failed validation.'
			)
		);
	} );

	it( 'saves business details without a VAT number when the merchant has none', async () => {
		const onCompleted = jest.fn();

		render(
			<WooPaymentsVatModal
				country="DE"
				onClose={ jest.fn() }
				onCompleted={ onCompleted }
			/>
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Continue' } )
		);
		await userEvent.type(
			screen.getByLabelText( 'Business name' ),
			'Ada Bakery'
		);
		await userEvent.type(
			screen.getByLabelText( 'Address' ),
			'1 Market Street'
		);
		await act( async () => {
			await userEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );

		expect( mockValidateVat ).not.toHaveBeenCalled();
		expect( mockSaveVat ).toHaveBeenCalledWith( {
			vat_number: null,
			name: 'Ada Bakery',
			address: '1 Market Street',
		} );
	} );
} );
