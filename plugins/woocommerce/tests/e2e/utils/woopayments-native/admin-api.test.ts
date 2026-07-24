import {
	expect,
	test,
	type BrowserContext,
	type Locator,
	type Page,
} from '@playwright/test';

import {
	authenticateAdminContext,
	getBlocksCardFrameSelector,
	submitBlocksCheckout,
} from '../../fixtures/woopayments-native';

test( 'authenticates REST through the exact browser cookie and nonce session', async () => {
	const actions: string[] = [];
	let headers: Record< string, string > | undefined;
	const field = ( name: string ) =>
		( {
			fill: async ( value: string ) => {
				actions.push( `fill:${ name }:${ value }` );
			},
		} as Locator );
	const page = {
		close: async () => {
			actions.push( 'close' );
		},
		evaluate: async () => 'rest-nonce',
		getByLabel: ( name: string ) => {
			if ( name !== 'Username or Email Address' ) {
				throw new Error( `Unexpected label locator: ${ name }` );
			}
			return field( name );
		},
		getByRole: ( role: string, options?: { name?: string } ) => {
			if ( role === 'textbox' && options?.name === 'Password' ) {
				return field( options.name );
			}
			return {
				click: async () => {
					actions.push( 'click:Log In' );
				},
			} as Locator;
		},
		goto: async ( url: string ) => {
			actions.push( `goto:${ url }` );
		},
		waitForURL: async ( url: string ) => {
			actions.push( `wait:${ url }` );
		},
	} as unknown as Page;
	const context = {
		newPage: async () => page,
		setExtraHTTPHeaders: async ( value: Record< string, string > ) => {
			headers = value;
		},
	} as unknown as BrowserContext;

	await authenticateAdminContext( context, {
		username: 'pilot-admin',
		password: 'pilot-password',
	} );

	expect( actions ).toEqual( [
		'goto:wp-login.php',
		'fill:Username or Email Address:pilot-admin',
		'fill:Password:pilot-password',
		'click:Log In',
		'wait:**/wp-admin/**',
		'close',
	] );
	expect( headers ).toEqual( {
		'X-WP-Nonce': 'rest-nonce',
	} );
} );

test( 'targets the runtime-owned Blocks card frame', () => {
	expect( getBlocksCardFrameSelector( 'client' ) ).toContain(
		'.wcpay-payment-element'
	);
	expect( getBlocksCardFrameSelector( 'native' ) ).toContain(
		'#wcpay-core-blocks-payment-element'
	);
	expect( getBlocksCardFrameSelector( 'transition' ) ).toContain(
		'#wcpay-core-blocks-payment-element'
	);
} );

test( 'retries a swallowed Blocks checkout click only while Core remains idle', async () => {
	let clicks = 0;
	let stateChecks = 0;
	const button = {
		click: async () => {
			clicks += 1;
		},
	} as Locator;
	const page = {
		getByRole: () => button,
		url: () => 'http://store.test/checkout/',
		waitForRequest: async () => {
			throw new Error( 'No checkout request started.' );
		},
		waitForFunction: async () => {
			stateChecks += 1;
			if ( stateChecks === 1 ) {
				throw new Error( 'Core remained idle.' );
			}
		},
	} as unknown as Page;

	await submitBlocksCheckout( page, ( checkoutButton ) =>
		checkoutButton.click()
	);

	expect( clicks ).toBe( 2 );
	expect( stateChecks ).toBe( 2 );
} );

test( 'does not retry after the Blocks checkout request starts while Core looks idle', async () => {
	let clicks = 0;
	let observedRequest = false;
	const button = {
		click: async () => {
			clicks += 1;
		},
	} as Locator;
	const page = {
		getByRole: () => button,
		url: () => 'http://store.test/checkout/',
		waitForRequest: async (
			predicate: ( request: {
				method: () => string;
				url: () => string;
			} ) => boolean
		) => {
			observedRequest = predicate( {
				method: () => 'POST',
				url: () => 'http://store.test/wp-json/wc/store/v1/checkout',
			} );
		},
		waitForFunction: async () => {
			throw new Error( 'Core still looked idle.' );
		},
	} as unknown as Page;

	await submitBlocksCheckout( page, ( checkoutButton ) =>
		checkoutButton.click()
	);

	expect( observedRequest ).toBe( true );
	expect( clicks ).toBe( 1 );
} );

test( 'fails after the bounded Blocks checkout click attempts stay idle', async () => {
	let clicks = 0;
	const button = {
		click: async () => {
			clicks += 1;
		},
	} as Locator;
	const page = {
		getByRole: () => button,
		url: () => 'http://store.test/checkout/',
		waitForRequest: async () => {
			throw new Error( 'No checkout request started.' );
		},
		waitForFunction: async () => {
			throw new Error( 'Core remained idle.' );
		},
	} as unknown as Page;

	await expect(
		submitBlocksCheckout( page, ( checkoutButton ) =>
			checkoutButton.click()
		)
	).rejects.toThrow( /did not start after 3 attempts/i );
	expect( clicks ).toBe( 3 );
} );
