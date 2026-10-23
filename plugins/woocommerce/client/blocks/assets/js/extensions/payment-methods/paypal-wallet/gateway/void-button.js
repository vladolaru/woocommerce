import { hide, show } from '../button/modules/Helper/Hiding';

document.addEventListener( 'DOMContentLoaded', function () {
	const refundButton = document.querySelector( 'button.refund-items' );
	if ( ! refundButton ) {
		return;
	}

	refundButton.insertAdjacentHTML(
		'afterend',
		`<button class="button" type="button" id="pcpVoid">${ PcpVoidButton.button_text }</button>`
	);

	hide( refundButton );

	const voidButton = document.querySelector( '#pcpVoid' );

	voidButton.addEventListener( 'click', async () => {
		// eslint-disable-next-line no-alert -- Native confirmation, as WooCommerce's own order screen script uses; no notice or modal helper is loaded on this page.
		if ( ! window.confirm( PcpVoidButton.popup_text ) ) {
			return;
		}

		voidButton.setAttribute( 'disabled', 'disabled' );

		const res = await fetch( PcpVoidButton.ajax.void.endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				nonce: PcpVoidButton.ajax.void.nonce,
				wc_order_id: PcpVoidButton.wc_order_id,
			} ),
		} );

		const data = await res.json();

		if ( ! data.success ) {
			hide( voidButton );
			show( refundButton );

			// eslint-disable-next-line no-alert -- Native alert, as WooCommerce's own order screen script uses; no notice helper is loaded on this page.
			alert( PcpVoidButton.error_text );

			throw Error( data.data.message );
		}

		location.reload();
	} );
} );
