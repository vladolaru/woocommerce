import { expect, type Page } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';

export async function softCutOverEphemeralStore(
	session: ProviderWriteSession,
	page: Page
): Promise< void > {
	await session.assertCanWrite();
	session.requireEphemeralTransitionAllocation();
	session.requireApprovedProviderFixture( 'soft-cutover' );
	await session.logInAsAdmin( page );
	await page.goto( 'wp-admin/' );
	const cutoverAction = page.getByRole( 'link', {
		name: 'Disable WooPayments',
		exact: true,
	} );
	await expect( cutoverAction ).toHaveAttribute(
		'href',
		/wc_woopayments_cutover_action=disable_woopayments/
	);
	const href = await cutoverAction.getAttribute( 'href' );
	if ( ! href ) {
		throw new Error(
			'The product WooPayments cutover action has no exact URL.'
		);
	}
	const url = new URL( href, session.baseURL );
	if (
		! url.pathname.endsWith( '/wp-admin/admin.php' ) ||
		url.searchParams.get( 'wc_woopayments_cutover_action' ) !==
			'disable_woopayments' ||
		! url.searchParams.get( '_wc_woopayments_cutover_nonce' )
	) {
		throw new Error(
			'The product WooPayments cutover action is not bound to the nonce-protected controller entry point.'
		);
	}
	await session.performWrite( () => cutoverAction.click() );
	await session.assertCurrentRuntimeReady( 'native' );
	await session.logInAsCustomer( page );
}
