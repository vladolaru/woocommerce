import type { Page } from '@playwright/test';

/**
 * Wait for WordPress's delayed login-focus callback before entering credentials.
 * The callback clears the password field before focusing either login field.
 */
export async function waitForWordPressLoginReady(
	page: Page
): Promise< void > {
	await page.waitForFunction( () => {
		return [ 'user_login', 'user_pass' ].includes(
			document.activeElement?.id ?? ''
		);
	} );
}
