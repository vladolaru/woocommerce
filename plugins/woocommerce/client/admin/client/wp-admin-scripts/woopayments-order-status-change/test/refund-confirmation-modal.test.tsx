/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { RefundConfirmationModal } from '../refund-confirmation-modal';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as unknown as jest.Mock;

const originalLocation = window.location;
const reload = jest.fn();

const renderModal = ( props: Partial< { onClose: () => void } > = {} ) => {
	const onClose = props.onClose ?? jest.fn();
	const onError = jest.fn();

	render(
		<RefundConfirmationModal
			previousStatus="wc-processing"
			refundAmount={ 42.5 }
			formattedRefundAmount="$42.50"
			refundedAmount={ 7.5 }
			onClose={ onClose }
			onError={ onError }
		/>
	);

	return { onClose, onError };
};

const getRequestBody = (): URLSearchParams =>
	mockApiFetch.mock.calls[ 0 ][ 0 ].body as URLSearchParams;

describe( 'RefundConfirmationModal', () => {
	beforeAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, reload },
		} );
	} );

	afterAll( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
	} );

	beforeEach( () => {
		jest.clearAllMocks();

		document.body.innerHTML = `
			<form>
				<p class="form-field">
					<select id="order_status">
						<option value="wc-processing">Processing</option>
						<option value="wc-refunded">Refunded</option>
					</select>
				</p>
			</form>
		`;

		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-refunded';

		window.woocommerce_admin_meta_boxes = {
			ajax_url: '/wp-admin/admin-ajax.php',
			post_id: 123,
			order_item_nonce: 'nonce-abc',
		};
	} );

	it( 'labels the confirm action with the formatted refund amount', () => {
		renderModal();

		expect(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		).toBeInTheDocument();
	} );

	it( 'posts the refund through the gateway with api_refund set', async () => {
		mockApiFetch.mockResolvedValue( { success: true, data: {} } );
		renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		);

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledTimes( 1 )
		);

		const request = mockApiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.url ).toBe( '/wp-admin/admin-ajax.php' );
		expect( request.method ).toBe( 'POST' );

		const body = getRequestBody();
		expect( body.get( 'action' ) ).toBe( 'woocommerce_refund_line_items' );
		expect( body.get( 'order_id' ) ).toBe( '123' );
		expect( body.get( 'security' ) ).toBe( 'nonce-abc' );
		expect( body.get( 'refund_amount' ) ).toBe( '42.5' );
		expect( body.get( 'refunded_amount' ) ).toBe( '7.5' );
		// WC_AJAX compares this against the string 'true'; anything else makes
		// the refund local-only.
		expect( body.get( 'api_refund' ) ).toBe( 'true' );
	} );

	it( 'reloads the screen once the refund succeeds', async () => {
		mockApiFetch.mockResolvedValue( { success: true, data: {} } );
		renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		);

		await waitFor( () => expect( reload ).toHaveBeenCalled() );
	} );

	it( 'surfaces the server error and restores the dropdown when the refund fails', async () => {
		mockApiFetch.mockResolvedValue( {
			success: false,
			data: { error: 'Refund failed at the gateway.' },
		} );
		const { onClose, onError } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		);

		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith(
				'Refund failed at the gateway.'
			)
		);
		expect( onClose ).toHaveBeenCalled();
		expect(
			( document.getElementById( 'order_status' ) as HTMLSelectElement )
				.value
		).toBe( 'wc-processing' );
		expect( reload ).not.toHaveBeenCalled();
	} );

	it( 'falls back to a generic message when a rejection carries none', async () => {
		mockApiFetch.mockRejectedValue( {} );
		const { onError } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		);

		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith(
				'Error processing refund. Please try again.'
			)
		);
	} );

	it( 'restores the dropdown and refunds nothing when the merchant cancels', async () => {
		const { onClose, onError } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel' } )
		);

		expect( mockApiFetch ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
		expect( onError ).not.toHaveBeenCalled();
		expect(
			( document.getElementById( 'order_status' ) as HTMLSelectElement )
				.value
		).toBe( 'wc-processing' );
	} );

	it( 'refuses to post without the order screen AJAX context', async () => {
		delete window.woocommerce_admin_meta_boxes;
		const { onError } = renderModal();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Refund $42.50' } )
		);

		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith(
				'Error processing refund. Please try again.'
			)
		);
		expect( mockApiFetch ).not.toHaveBeenCalled();
	} );
} );
