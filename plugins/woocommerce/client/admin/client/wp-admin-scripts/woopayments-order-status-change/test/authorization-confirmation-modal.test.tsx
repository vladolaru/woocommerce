/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { AuthorizationConfirmationModal } from '../authorization-confirmation-modal';

const DOCS_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/authorize-and-capture/';

const submit = jest.fn();
const requestSubmit = jest.fn();

const renderModal = ( action: 'capture' | 'cancel' ) => {
	const onClose = jest.fn();

	render(
		<AuthorizationConfirmationModal
			action={ action }
			previousStatus="wc-on-hold"
			onClose={ onClose }
		/>
	);

	return { onClose };
};

const getMessage = () =>
	( screen.getByText( /Do you want to continue\?/ ).textContent ?? '' )
		// `ExternalLink` adds a hidden "(opens in a new tab)" and an arrow.
		.replace( /\s*\(opens in a new tab\)|↗/g, '' )
		.replace( /\s+/g, ' ' );

// Source: client 11.1.0 `client/order/order-status-change-strategies/index.tsx:60-196`.
describe( 'AuthorizationConfirmationModal', () => {
	beforeEach( () => {
		jest.clearAllMocks();

		document.body.innerHTML = `
			<form>
				<p class="form-field">
					<select id="order_status">
						<option value="wc-on-hold">On hold</option>
						<option value="wc-completed">Completed</option>
						<option value="wc-cancelled">Cancelled</option>
					</select>
				</p>
			</form>
		`;

		// jsdom implements neither form submission entry point.
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		form.submit = submit;
		form.requestSubmit = requestSubmit;
	} );

	it( 'uses the client copy and links for capturing the payment', () => {
		renderModal( 'capture' );

		expect(
			screen.getByRole( 'dialog', { name: 'Capture payment' } )
		).toBeInTheDocument();
		expect( getMessage() ).toBe(
			'This order has been authorized but payment has not been captured yet. Changing the status to completed will also capture the payment. Do you want to continue?'
		);
		expect(
			screen.getByRole( 'link', {
				name: /authorized but payment has not been captured/,
			} )
		).toHaveAttribute( 'href', `${ DOCS_URL }#authorize-vs-capture` );
		expect(
			screen.getByRole( 'link', { name: /capture the payment/ } )
		).toHaveAttribute(
			'href',
			`${ DOCS_URL }#capturing-authorized-payments`
		);
		expect(
			screen.getByRole( 'button', {
				name: 'Complete order and capture payment',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeInTheDocument();
	} );

	it( 'uses the client copy and links for cancelling the payment', () => {
		renderModal( 'cancel' );

		expect(
			screen.getByRole( 'dialog', { name: 'Cancel payment' } )
		).toBeInTheDocument();
		expect( getMessage() ).toBe(
			'This order has been authorized but payment has not been captured yet. Changing the status to cancelled will also cancel the payment. Do you want to continue?'
		);
		expect(
			screen.getByRole( 'link', {
				name: /authorized but payment has not been captured/,
			} )
		).toHaveAttribute( 'href', `${ DOCS_URL }#authorize-vs-capture` );
		expect(
			screen.getByRole( 'link', { name: /cancel the payment/ } )
		).toHaveAttribute( 'href', `${ DOCS_URL }#cancelling-authorizations` );
		expect(
			screen.getByRole( 'button', { name: 'Cancel order and payment' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeInTheDocument();
	} );

	it( 'submits the order form when the merchant confirms', async () => {
		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-completed';
		const { onClose } = renderModal( 'capture' );

		await userEvent.click(
			screen.getByRole( 'button', {
				name: 'Complete order and capture payment',
			} )
		);

		expect( requestSubmit ).toHaveBeenCalledTimes( 1 );
		expect( submit ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
		expect( field ).toHaveValue( 'wc-completed' );
	} );

	it( 'restores the previous status and submits nothing on Cancel', async () => {
		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-cancelled';
		const { onClose } = renderModal( 'cancel' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Cancel' } )
		);

		expect( requestSubmit ).not.toHaveBeenCalled();
		expect( submit ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
		expect( field ).toHaveValue( 'wc-on-hold' );
	} );

	it( 'restores the previous status and submits nothing when dismissed with Escape', async () => {
		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-completed';
		const { onClose } = renderModal( 'capture' );

		await userEvent.keyboard( '{Escape}' );

		expect( requestSubmit ).not.toHaveBeenCalled();
		await waitFor( () => expect( onClose ).toHaveBeenCalledTimes( 1 ) );
		expect( field ).toHaveValue( 'wc-on-hold' );
	} );

	// Client 11.1.0 `client/components/confirmation-modal`: a footer that right-aligns
	// the secondary back-out button before the primary action.
	const expectClientFooter = ( backOut: string, confirm: string ) => {
		const backOutButton = screen.getByRole( 'button', { name: backOut } );
		const confirmButton = screen.getByRole( 'button', { name: confirm } );
		const actions = backOutButton.parentElement as HTMLElement;

		expect( actions ).toHaveClass(
			'woocommerce-woopayments-order-status-change__modal-actions'
		);
		expect( confirmButton.parentElement ).toBe( actions );
		expect( window.getComputedStyle( actions ).display ).toBe( 'flex' );
		expect( window.getComputedStyle( actions ).justifyContent ).toBe(
			'flex-end'
		);
		expect( backOutButton ).toHaveClass( 'is-secondary' );
		expect( confirmButton ).toHaveClass( 'is-primary' );
		expect(
			// eslint-disable-next-line no-bitwise
			backOutButton.compareDocumentPosition( confirmButton ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();

		return { actions, backOutButton, confirmButton };
	};

	it.each( [
		[ 'capture' as const, 'Complete order and capture payment' ],
		[ 'cancel' as const, 'Cancel order and payment' ],
	] )(
		'lays out the %s dialog like the client: separator, then Cancel and the action on the right',
		( action, confirm ) => {
			renderModal( action );

			const { actions } = expectClientFooter( 'Cancel', confirm );
			const separator = document.querySelector(
				'.woocommerce-woopayments-order-status-change__modal-separator'
			);

			expect( separator?.tagName ).toBe( 'HR' );
			expect( separator?.nextElementSibling ).toBe( actions );
		}
	);

	it( 'restores the previous status and submits nothing when closed with the close control', async () => {
		const field = document.getElementById(
			'order_status'
		) as HTMLSelectElement;
		field.value = 'wc-completed';
		const { onClose } = renderModal( 'capture' );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Close' } )
		);

		expect( requestSubmit ).not.toHaveBeenCalled();
		expect( submit ).not.toHaveBeenCalled();
		await waitFor( () => expect( onClose ).toHaveBeenCalledTimes( 1 ) );
		expect( field ).toHaveValue( 'wc-on-hold' );
	} );
} );
