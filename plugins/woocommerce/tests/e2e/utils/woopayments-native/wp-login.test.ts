import { expect, test, type Page } from '@playwright/test';

import { waitForWordPressLoginReady } from './wp-login';

test( 'waits for WordPress to focus a login field before entering credentials', async () => {
	let isReady: ( () => boolean ) | undefined;
	const page = {
		waitForFunction: async ( predicate: () => boolean ) => {
			isReady = predicate;
		},
	} as unknown as Page;
	const originalDocument = Object.getOwnPropertyDescriptor(
		globalThis,
		'document'
	);
	const originalWindow = Object.getOwnPropertyDescriptor(
		globalThis,
		'window'
	);
	const activeElement = { id: 'loginform' };

	Object.defineProperty( globalThis, 'document', {
		configurable: true,
		value: { activeElement },
	} );
	Object.defineProperty( globalThis, 'window', {
		configurable: true,
		value: { zxcvbn: () => undefined },
	} );

	try {
		await waitForWordPressLoginReady( page );

		expect( isReady ).toBeDefined();
		expect( isReady?.() ).toBe( false );

		activeElement.id = 'user_pass';
		expect( isReady?.() ).toBe( true );

		activeElement.id = 'user_login';
		expect( isReady?.() ).toBe( true );
	} finally {
		if ( originalDocument ) {
			Object.defineProperty( globalThis, 'document', originalDocument );
		} else {
			delete ( globalThis as { document?: unknown } ).document;
		}
		if ( originalWindow ) {
			Object.defineProperty( globalThis, 'window', originalWindow );
		} else {
			delete ( globalThis as { window?: unknown } ).window;
		}
	}
} );
