import type { Page } from '@playwright/test';

/**
 * Wait for WordPress's asynchronously loaded password-strength script before
 * entering credentials. Its login-page initializer can otherwise clear a
 * password that Playwright filled while the script was still loading.
 */
export async function waitForWordPressLoginReady(
	page: Page
): Promise< void > {
	await page.waitForFunction( () => {
		const loginWindow = window as Window & { zxcvbn?: unknown };
		return typeof loginWindow.zxcvbn === 'function';
	} );
}
