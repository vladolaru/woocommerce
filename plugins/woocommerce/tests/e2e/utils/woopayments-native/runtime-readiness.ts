export type WooPaymentsRuntime = 'client' | 'native' | 'transition';

export interface RuntimeStatus {
	site_url: string;
	wpcom_blog_id: number;
	runtime_owner: 'plugin' | 'native' | 'none';
	native_enabled: boolean;
	account_id: string;
	account_connected: boolean;
	gateway_enabled: boolean;
	test_mode: boolean;
	enabled_payment_methods: string[];
	last_webhook_fetch: number;
	callback_probe: {
		registered: boolean;
		reachable: boolean;
		wpcom_blog_id: number;
	};
}

export function assertRuntimeReady(
	runtime: WooPaymentsRuntime,
	status: RuntimeStatus,
	expected: { siteUrl: string; wpcomBlogId: number; accountId: string }
): void {
	const expectedOwner = runtime === 'native' ? 'native' : 'plugin';

	if ( status.site_url !== expected.siteUrl ) {
		throw new Error(
			`Runtime readiness failed: site URL ${ status.site_url } does not exactly match ${ expected.siteUrl }.`
		);
	}
	if ( status.wpcom_blog_id !== expected.wpcomBlogId ) {
		throw new Error(
			`Runtime readiness failed: WPCOM blog ID ${ status.wpcom_blog_id } does not exactly match ${ expected.wpcomBlogId }.`
		);
	}
	if ( status.account_id !== expected.accountId ) {
		throw new Error(
			`Runtime readiness failed: account ID ${ status.account_id } does not exactly match ${ expected.accountId }.`
		);
	}
	if ( status.runtime_owner !== expectedOwner ) {
		throw new Error(
			`Runtime readiness failed: expected runtime owner ${ expectedOwner }, received ${ status.runtime_owner }.`
		);
	}
	if ( runtime === 'native' && status.native_enabled !== true ) {
		throw new Error(
			'Runtime readiness failed: the native runtime is not enabled.'
		);
	}
	if ( status.account_connected !== true ) {
		throw new Error(
			'Runtime readiness failed: the WooPayments account is not connected.'
		);
	}
	if ( status.gateway_enabled !== true ) {
		throw new Error(
			'Runtime readiness failed: the WooPayments gateway is not enabled.'
		);
	}
	if ( status.test_mode !== true ) {
		throw new Error(
			'Runtime readiness failed: WooPayments is not in test mode.'
		);
	}
	if ( ! status.enabled_payment_methods.includes( 'card' ) ) {
		throw new Error(
			'Runtime readiness failed: the card capability is not enabled.'
		);
	}
	if (
		status.callback_probe.registered !== true ||
		status.callback_probe.reachable !== true
	) {
		throw new Error(
			'Runtime readiness failed: an owner-approved callback probe has not proved registration and reachability.'
		);
	}
	if ( status.callback_probe.wpcom_blog_id !== expected.wpcomBlogId ) {
		throw new Error(
			`Runtime readiness failed: callback WPCOM blog ID ${ status.callback_probe.wpcom_blog_id } does not exactly match ${ expected.wpcomBlogId }.`
		);
	}
}
