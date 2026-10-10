/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * The collecting panel's REST routes, under the core-owned `wc/v3/paypal-wallet` namespace.
 */
export const COLLECTING_PATH = '/wc/v3/paypal-wallet/collecting';

export type MerchantState =
	| 'no_account'
	| 'email_unconfirmed'
	| 'confirmed_not_connected'
	| 'connected';

export type HeldOrder = {
	id: number;
	number: string;
	/** UTC timestamp, in seconds, after which PayPal returns the payment to the customer. */
	deadline: number;
	edit_url: string;
};

export type CollectingData = {
	state: 'connected' | 'platform_connected' | 'collecting' | 'dormant';
	payee_email: string;
	can_change_payee_email: boolean;
	merchant_state: MerchantState;
	held_orders: HeldOrder[];
	held_orders_count: number;
	earliest_deadline: number | null;
	transport_ready: boolean;
	/** The reconcile's onboarding outcome; only in the check-status answer. */
	check?: string;
};

export const updatePayeeEmail = ( email: string ) =>
	apiFetch< CollectingData >( {
		path: `${ COLLECTING_PATH }/payee`,
		method: 'POST',
		data: { email },
	} );

export const checkStatus = () =>
	apiFetch< CollectingData >( {
		path: `${ COLLECTING_PATH }/check-status`,
		method: 'POST',
	} );

export const requestReferral = () =>
	apiFetch< { url: string } >( {
		path: `${ COLLECTING_PATH }/referral`,
		method: 'POST',
	} );
