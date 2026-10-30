/**
 * Internal dependencies
 */
import { createConnect } from '../connect';
import { loadPhoneValidation, normalizePhone } from '../phone-validation';

const WOOPAY_HOST = 'https://pay.woo.test';

const flushPromises = async () => {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
	}
};

describe( 'WooPay Connect (shared)', () => {
	let postMessage;

	beforeEach( () => {
		postMessage = jest.fn();
		Object.defineProperty(
			window.HTMLIFrameElement.prototype,
			'contentWindow',
			{
				configurable: true,
				get() {
					return { postMessage };
				},
			}
		);
		document.cookie = 'tk_ai=anon-id; path=/';
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		document.cookie =
			'tk_ai=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
		delete window.HTMLIFrameElement.prototype.contentWindow;
	} );

	const loadConnectIframe = async () => {
		await flushPromises();
		document
			.getElementById( 'woopay-connect-iframe' )
			.dispatchEvent( new window.Event( 'load' ) );
		await flushPromises();
	};

	test.each( [
		[ '1', 'true' ],
		[ '', 'false' ],
		[ undefined, null ],
	] )(
		'reads testMode %p by truthiness in the connect URL',
		async ( testMode, expected ) => {
			createConnect( {
				woopayHost: WOOPAY_HOST,
				woopayMerchantId: '123',
				testMode,
				iframeTitle: 'WooPay Connect',
			} ).fetchPreferredCard();
			await flushPromises();

			const iframe = document.getElementById( 'woopay-connect-iframe' );
			const url = new URL( iframe.src );
			expect( iframe.title ).toBe( 'WooPay Connect' );
			expect( url.searchParams.get( 'testMode' ) ).toBe( expected );
			expect( url.searchParams.get( 'blogId' ) ).toBe( '123' );
		}
	);

	// Each script keeps its own Connect client over the page's one iframe, so the classic and Blocks WooPay scripts on
	// one page each ask for the preferred card, as before the protocol was shared.
	test( 'gives each script its own client over one connect iframe', async () => {
		const args = { woopayHost: WOOPAY_HOST, iframeTitle: 'WooPay Connect' };
		const first = createConnect( args ).fetchPreferredCard();
		const second = createConnect( args ).fetchPreferredCard();

		await loadConnectIframe();

		expect( document.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );
		expect( postMessage ).toHaveBeenCalledTimes( 2 );

		window.dispatchEvent(
			new window.MessageEvent( 'message', {
				origin: WOOPAY_HOST,
				data: {
					action: 'get_preferred_payment_method_success',
					value: { brand: 'visa', last4: '4242' },
				},
			} )
		);

		await expect( first ).resolves.toEqual( {
			brand: 'visa',
			last4: '4242',
		} );
		await expect( second ).resolves.toEqual( {
			brand: 'visa',
			last4: '4242',
		} );
	} );
} );

describe( 'WooPay save-user phone helpers (shared)', () => {
	afterEach( () => {
		delete window.wcWooPaymentsPhoneValidation;
		document.head.innerHTML = '';
	} );

	test( 'normalizes the phone WooPay receives', () => {
		expect( normalizePhone( '(555) 123-4567' ) ).toBe( '+15551234567' );
		expect( normalizePhone( '+44 20 7946 0958' ) ).toBe( '+442079460958' );
		expect( normalizePhone( undefined ) ).toBe( '+1' );
	} );

	test( 'loads the phone validation script once and resolves null without a URL', async () => {
		await expect( loadPhoneValidation( '' ) ).resolves.toBeNull();

		const first = loadPhoneValidation( 'https://example.test/phone.js' );
		const second = loadPhoneValidation( 'https://example.test/phone.js' );
		const scripts = document.head.querySelectorAll( 'script' );

		expect( first ).toBe( second );
		expect( scripts ).toHaveLength( 1 );

		window.wcWooPaymentsPhoneValidation = {
			validatePhoneNumber: () => true,
		};
		scripts[ 0 ].onload();

		await expect( first ).resolves.toBe(
			window.wcWooPaymentsPhoneValidation
		);
	} );
} );
