/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { CancelConfirmationModal } from '../cancel-confirmation-modal';

const submit = jest.fn();
const requestSubmit = jest.fn();

const renderModal = () => {
	const onClose = jest.fn();

	render(
		<CancelConfirmationModal
			previousStatus="wc-processing"
			onClose={ onClose }
		/>
	);

	return { onClose };
};

describe( 'CancelConfirmationModal', () => {
	beforeEach( () => {
		jest.clearAllMocks();

		document.body.innerHTML = `
			<form>
				<p class="form-field">
					<select id="order_status">
						<option value="wc-processing">Processing</option>
						<option value="wc-cancelled">Cancelled</option>
					</select>
				</p>
			</form>
		`;

		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-cancelled';

		// jsdom implements neither form submission entry point.
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		form.submit = submit;
		form.requestSubmit = requestSubmit;
	} );

	it( 'offers the refund documentation alongside both actions', () => {
		renderModal();

		expect(
			screen.getByRole( 'link', { name: /how to issue refunds/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Do nothing' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Cancel order' } )
		).toBeInTheDocument();
	} );

	it( 'submits the order form when the merchant confirms the cancellation', async () => {
		const { onClose } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel order' } )
		);

		// requestSubmit(), not submit(): the order screen binds submit
		// handlers (the unsaved-changes guard among them) that the raw DOM
		// submit() would skip.
		expect( requestSubmit ).toHaveBeenCalled();
		expect( submit ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
		expect(
			( document.getElementById( 'order_status' ) as HTMLSelectElement )
				.value
		).toBe( 'wc-cancelled' );
	} );

	it( 'restores the dropdown and submits nothing when the merchant backs out', async () => {
		const { onClose } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Do nothing' } )
		);

		expect( submit ).not.toHaveBeenCalled();
		expect( requestSubmit ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
		expect(
			( document.getElementById( 'order_status' ) as HTMLSelectElement )
				.value
		).toBe( 'wc-processing' );
	} );
} );
