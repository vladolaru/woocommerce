/**
 * Adapter for the WC AJAX order endpoints this module shares with the classic
 * button stack.
 *
 * Every assumption about that contract lives here: ppc-change-cart,
 * ppc-create-order, ppc-approve-order, ppc-update-shipping. Keep the
 * request/response shapes in sync with the endpoint contract tests.
 */

import SingleProductActionHandler from '@ppcp-button/ActionHandler/SingleProductActionHandler';
import { payerData } from '@ppcp-button/Helper/PayerData';
import { cartPayerData } from '@ppcp-button/Helper/CartPayerData';
import { postJson, postStoreApi } from './utils/api';
import { describeError, logEvent } from './utils/diagnostics';
import { FundingSources } from './utils/fundingSources';
import { amountFromCartTotals } from './utils/amount';
import { continuationRedirectUrl } from './utils/continuation';

// The ID of the PayPal gateway, which processes every order.
const GATEWAY_ID = 'ppcp-gateway';

/**
 * The shopper's details for a create-order request, read from wherever the
 * current checkout keeps them.
 *
 * PayPal's risk engine identifies a buyer partly by their email address, and a
 * card it has never seen has nothing else to be matched against. The classic
 * checkout holds the details in billing inputs; the block checkout has no such
 * inputs and keeps them in the cart store. Anywhere else there is no billing
 * form to read, so nothing is sent.
 *
 * @param {string} context - The page context.
 * @return {?Object} The payer, or null when no details are available.
 */
function payerFor( context ) {
	if ( context === 'checkout' ) {
		return payerData();
	}

	if ( context === 'checkout-block' ) {
		return cartPayerData();
	}

	return null;
}

/**
 * Navigation seam: window.location is not mockable under jsdom, so
 * redirects go through this indirection to stay unit-testable.
 */
export const navigation = {
	assign: ( url ) => window.location.assign( url ),
};

/**
 * Submits the pay-for-order form after selecting the paying gateway, so the
 * approved order is captured by that gateway (not whichever method radio
 * happens to be checked) and WC issues the order-received redirect.
 *
 * @throws {Error} When jQuery or the pay-order form is unavailable.
 */
function submitPayOrderForm() {
	if ( typeof jQuery === 'undefined' ) {
		// eslint-disable-next-line no-console
		console.error(
			'[ppcp-sdk-v6] cannot submit pay-for-order: jQuery is unavailable on this page.'
		);
		throw new Error( 'Could not submit the order.' );
	}

	const form = jQuery( 'form#order_review' );
	if ( ! form.length ) {
		// eslint-disable-next-line no-console
		console.error(
			'[ppcp-sdk-v6] cannot submit pay-for-order: form#order_review was not found in the DOM.'
		);
		throw new Error( 'Order form not found.' );
	}

	const gatewayRadio = document.querySelector(
		`#payment_method_${ GATEWAY_ID }`
	);
	if ( gatewayRadio && ! gatewayRadio.checked ) {
		gatewayRadio.checked = true;
		jQuery( gatewayRadio ).trigger( 'change' );
	}

	form.trigger( 'submit' );
}

/**
 * The form describing the viewed product.
 *
 * Exported because the viewed-total watcher watches this same form for changes,
 * and the two must not disagree about which one it is.
 *
 * @return {?HTMLElement} The form, or null when the page has none.
 */
export function productForm() {
	// Classic themes render form.cart, block themes
	// form.wc-block-add-to-cart-with-options; locate via the field.
	const idElement =
		document.querySelector( 'form [name="add-to-cart"]' ) ||
		document.querySelector( 'form [name="product_id"]' );

	return idElement?.closest( 'form' ) ?? null;
}

/**
 * Collects products from the single product form for ppc-change-cart.
 *
 * Reuses SingleProductActionHandler, which handles simple, variable, grouped and
 * booking products plus extra third-party form fields.
 *
 * @return {Object[]} Products in the { id, quantity, variations, extra, booking } shape.
 * @throws {Error} When the product form cannot be found.
 */
function getProductsFromForm() {
	const form = productForm();
	if ( ! form ) {
		throw new Error( 'Product form not found.' );
	}

	const handler = new SingleProductActionHandler( null, null, form, null );

	return handler.getProducts().map( ( product ) => product.data() );
}

/**
 * Adds the viewed product to the cart (ppc-change-cart).
 *
 * Empties the cart first, so a repeat call is safe. The server validates the
 * product as part of this, so an invalid one fails before any order exists.
 *
 * @param {Object} config - The wc_ppcp_sdk_v6 config object.
 * @return {Promise<Object[]>} The resulting purchase units.
 */
export async function changeCart( config ) {
	return postJson( config.ajax.change_cart, {
		products: getProductsFromForm(),
	} );
}

/**
 * Prices the viewed product without touching the cart (ppc-sdk-v6-simulate-cart).
 *
 * For totals that must be known before the shopper acts, which changeCart() cannot
 * answer because it adds the product to the real cart.
 *
 * TODO (phase 3): product-page Pay Later eligibility still uses the localized
 * config.amount, which is the cart total whenever the cart is not empty. It
 * should read this instead.
 *
 * @param {Object} config - The wc_ppcp_sdk_v6 config object.
 * @return {Promise<{total: string, currency_code: string}>} The simulated total.
 */
export async function simulateCart( config ) {
	return postJson( config.ajax.simulate_cart, {
		products: getProductsFromForm(),
	} );
}

/**
 * Creates a PayPal order via the existing WC AJAX endpoints.
 *
 * On product pages the viewed product is first added to the cart
 * (ppc-change-cart), matching the v5 flow; the returned purchase units
 * are passed to ppc-create-order, which derives the product-context
 * return URL from them.
 *
 * @param {Object} config        - The wc_ppcp_sdk_v6 config object.
 * @param {string} context       - The page context.
 * @param {string} fundingSource - The funding source (paypal, venmo, paylater).
 * @return {Promise<{orderId: string}>} The created PayPal order id.
 */
export async function createOrder( config, context, fundingSource ) {
	const units = context === 'product' ? await changeCart( config ) : [];

	const body = {
		context,
		purchase_units: units,
		payment_method: GATEWAY_ID,
		funding_source: fundingSource || FundingSources.PAYPAL,
		save_order_in_session: 1,
	};

	// Pay-for-order: the server builds the order from the existing WC order,
	// identified by these, rather than from the cart.
	if ( context === 'pay-now' && config.pay_now ) {
		body.order_id = config.pay_now.order_id;
		body.order_key = config.pay_now.order_key;
	}

	if ( context === 'checkout' ) {
		// The serialized form lets the server run the early WC checkout
		// validation before creating the order, so the buyer sees form errors
		// before approving.
		const form = document.querySelector( 'form.checkout' );
		if ( form ) {
			body.form_encoded = new URLSearchParams(
				new FormData( form )
			).toString();
			body.createaccount =
				!! form.querySelector( '#createaccount' )?.checked;
		}
	}

	const payer = payerFor( context );
	if ( payer ) {
		body.payer = payer;
	}

	const data = await postJson( config.ajax.create_order, body );

	return { orderId: data.id };
}

/**
 * Reports an approved PayPal order and takes the buyer wherever it leads.
 *
 * should_create_wc_order is requested except on classic checkout and for Venmo
 * with vaulting, and the server decides: with the Pay Now
 * experience it creates the WC order and responds with order_received_url,
 * otherwise it only stores the approved order in the session and the gateway
 * processes it on Place Order. On classic checkout the WC checkout form is
 * submitted after approval instead.
 *
 * @param {Object} config        - The wc_ppcp_sdk_v6 config object.
 * @param {string} context       - The page context.
 * @param {string} fundingSource - The funding source used for payment.
 * @param {string} orderId       - The PayPal order ID.
 */
export async function approveOrder( config, context, fundingSource, orderId ) {
	// Pay-for-order: the WC order already exists. Approve it into the session
	// (never request WC-order creation — that would create a duplicate, since
	// is_checkout() is false during this AJAX call) and submit the pay-order
	// form so the paying gateway captures the existing order and redirects to
	// the order-received page.
	if ( context === 'pay-now' ) {
		await approveOrderInSession( config, fundingSource, orderId );
		submitPayOrderForm();
		return;
	}

	// Never on classic checkout: its form submit must create the order, and an
	// order created here would consume the reCAPTCHA result that submit re-checks.
	// False routes us through the classic form submit below instead.
	const canCreateOrder =
		context !== 'checkout' &&
		( ! config.vaulting_enabled || fundingSource !== FundingSources.VENMO );

	const body = {
		order_id: orderId,
		funding_source: fundingSource,
		should_create_wc_order: canCreateOrder,
	};

	let data;
	try {
		data = await postJson( config.ajax.approve_order, body );
	} catch ( error ) {
		if ( ! canCreateOrder ) {
			throw error;
		}

		// The retry below hides the refusal, so record it first.
		logEvent(
			config,
			'approve-order-retried',
			`${ fundingSource } order=${ orderId } ${ describeError( error ) }`
		);

		// e.g. One-Touch approval without a shipping option; fall back
		// to the classic continuation on checkout.
		data = await postJson( config.ajax.approve_order, {
			order_id: orderId,
			funding_source: fundingSource,
			should_create_wc_order: false,
		} );
	}

	if ( data?.order_received_url ) {
		navigation.assign( data.order_received_url );
		return;
	}

	if ( context === 'checkout' && typeof jQuery !== 'undefined' ) {
		const checkoutForm = jQuery( 'form.checkout' );
		if ( checkoutForm.length ) {
			// The approved order must be processed by the gateway that created
			// it, not whichever payment method radio happens to be checked, so
			// the express path switches to PayPal.
			const gatewayRadio = document.querySelector(
				`#payment_method_${ GATEWAY_ID }`
			);
			if ( gatewayRadio && ! gatewayRadio.checked ) {
				gatewayRadio.checked = true;
				jQuery( gatewayRadio ).trigger( 'change' );
			}

			checkoutForm.trigger( 'submit' );
			return;
		}
	}

	// Continuation: the buyer completes the order on the checkout page. Cache-
	// busted because a cached checkout would carry no continuation payload and
	// show the express buttons again for an already-approved order.
	navigation.assign( continuationRedirectUrl( config ) );
}

/**
 * Fetches the full PayPal order (ppc-get-order).
 *
 * Used by the block express flow to read the buyer's PayPal address,
 * which the v6 session onApprove does not provide.
 *
 * @param {Object} config  - The wc_ppcp_sdk_v6 config object.
 * @param {string} orderId - The PayPal order ID.
 * @return {Promise<Object>} The PayPal order (Orders v2 shape).
 */
export async function getOrder( config, orderId ) {
	return postJson( config.ajax.get_order, {
		order_id: orderId,
	} );
}

/**
 * Approves the order and stores it in the WC session without creating the
 * WC order or redirecting.
 *
 * The block checkout submit creates the WC order through the gateway, so
 * unlike the classic approveOrder this must not create it or navigate away.
 *
 * @param {Object} config        - The wc_ppcp_sdk_v6 config object.
 * @param {string} fundingSource - The funding source used for payment.
 * @param {string} orderId       - The PayPal order ID.
 * @return {Promise<void>} Resolves when the order has been approved.
 */
export async function approveOrderInSession( config, fundingSource, orderId ) {
	await postJson( config.ajax.approve_order, {
		order_id: orderId,
		funding_source: fundingSource,
		should_create_wc_order: false,
	} );
}

/**
 * Patches the PayPal order with totals recalculated from the WC cart.
 *
 * @param {Object} config  - The wc_ppcp_sdk_v6 config object.
 * @param {string} orderId - The PayPal order ID.
 * @return {Promise<void>} Resolves when the order has been patched.
 */
export async function updateShipping( config, orderId ) {
	await postJson( config.ajax.update_shipping, {
		order_id: orderId,
	} );
}

/**
 * Fetches the current cart from the WC Store API.
 *
 * @param {Object} config - The wc_ppcp_sdk_v6 config object.
 * @return {Promise<?Object>} The cart, or null when it could not be read.
 */
export async function fetchCart( config ) {
	try {
		const response = await fetch( config.ajax.wc_store_api.cart, {
			credentials: 'same-origin',
		} );

		return await response.json();
	} catch {
		return null;
	}
}

/**
 * Fetches the current cart total from the WC Store API, for refreshing
 * amount-sensitive eligibility (Pay Later thresholds) after cart changes.
 *
 * @param {Object} config - The wc_ppcp_sdk_v6 config object.
 * @return {Promise<string>} The total as a decimal string, or '' on failure.
 */
export async function fetchCartTotal( config ) {
	const cart = await fetchCart( config );

	return amountFromCartTotals( cart?.totals );
}

/**
 * Writes the shopper's shipping address to the WC customer.
 *
 * The Store API merges partial addresses server-side and answers with the
 * recalculated cart, so the caller learns the new totals and rates from the
 * same request.
 *
 * @param {Object} config  - The wc_ppcp_sdk_v6 config object.
 * @param {Object} address - WC address fields.
 * @return {Promise<?Object>} The recalculated cart.
 */
export async function updateCustomerAddress( config, address ) {
	const storeApi = config.ajax.wc_store_api;

	return postStoreApi( storeApi, storeApi.update_customer, {
		shipping_address: address,
	} );
}

/**
 * Selects a shipping rate on the WC cart.
 *
 * @param {Object} config - The wc_ppcp_sdk_v6 config object.
 * @param {string} rateId - The WC rate id, e.g. flat_rate:3.
 * @return {Promise<?Object>} The recalculated cart.
 */
export async function selectShippingRate( config, rateId ) {
	const storeApi = config.ajax.wc_store_api;

	return postStoreApi( storeApi, storeApi.select_shipping_rate, {
		rate_id: rateId,
	} );
}
