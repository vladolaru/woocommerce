/**
 * Internal dependencies
 */
import { loadPhoneValidation, normalizePhone } from '../phone-validation';
import {
	dispatchFromWindow,
	getIframeWindow,
	postFromWooPay,
} from './utils/post-from-woopay';

const WOOPAY_HOST = 'https://pay.woo.test';

const flushPromises = async () => {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
	}
};

// The client lives for the page, so each case gets a fresh copy of the module.
const loadGetConnect = () => {
	let getConnect;
	jest.isolateModules( () => {
		( { getConnect } = require( '../connect' ) );
	} );
	return getConnect;
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
		jest.useRealTimers();
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
			loadGetConnect()( {
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

	const sendWooPayMessage = ( data ) => postFromWooPay( data, WOOPAY_HOST );

	const getPreemptiveSession = ( connect, value ) =>
		connect.sendMessage(
			{ action: 'setPreemptiveSessionData', value },
			'setPreemptiveSessionData',
			null,
			true
		);

	// The classic and Blocks WooPay scripts both run on a Blocks cart or checkout with direct checkout on. Client 11.2.0
	// asks once per page (express-button/preferred-card-fetch.ts), and direct checkout asks for no card.
	test( 'gives the classic and Blocks WooPay scripts one client, which asks for the preferred card once', async () => {
		const getConnect = loadGetConnect();
		const classicConnect = getConnect( {
			woopayHost: WOOPAY_HOST,
			iframeTitle: 'WooPay Connect Direct Checkout',
		} );
		const blocksConnect = getConnect( {
			woopayHost: WOOPAY_HOST,
			iframeTitle: 'WooPay Connect',
		} );
		const first = classicConnect.fetchPreferredCard();
		const second = blocksConnect.fetchPreferredCard();

		await loadConnectIframe();

		expect( blocksConnect ).toBe( classicConnect );
		expect( document.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );
		expect( postMessage ).toHaveBeenCalledTimes( 1 );

		sendWooPayMessage( {
			action: 'get_preferred_payment_method_success',
			value: { brand: 'visa', last4: '4242' },
		} );

		await expect( first ).resolves.toEqual( {
			brand: 'visa',
			last4: '4242',
		} );
		await expect( second ).resolves.toEqual( {
			brand: 'visa',
			last4: '4242',
		} );
	} );

	// Hosted WooPay answers every request and its answers name none (build/connect.js), so with one client two requests
	// under one name wait on one list. A newer request taking the callback would leave the older one waiting for its
	// 5 s deadline, and its own answer unused.
	test( 'settles two requests under one name from their two answers, without a deadline', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'first' ).then( ( value ) =>
			settled.push( [ 'first', value ] )
		);
		getPreemptiveSession( connect, 'second' ).then( ( value ) =>
			settled.push( [ 'second', value ] )
		);

		await loadConnectIframe();
		expect( postMessage ).toHaveBeenCalledTimes( 2 );

		sendWooPayMessage( {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/first' },
		} );
		await flushPromises();
		expect( settled ).toEqual( [
			[ 'first', { redirect_url: 'https://pay.woo.test/first' } ],
		] );

		sendWooPayMessage( { action: 'set_preemptive_session_data_error' } );
		await flushPromises();
		expect( settled ).toEqual( [
			[ 'first', { redirect_url: 'https://pay.woo.test/first' } ],
			[ 'second', { is_error: true } ],
		] );

		await jest.advanceTimersByTimeAsync( 5000 );
		expect( settled ).toHaveLength( 2 );
	} );

	test( 'keeps the newer request waiting when the older one reaches its deadline', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'first' ).then( ( value ) =>
			settled.push( [ 'first', value ] )
		);
		await loadConnectIframe();
		await jest.advanceTimersByTimeAsync( 1000 );
		getPreemptiveSession( connect, 'second' ).then( ( value ) =>
			settled.push( [ 'second', value ] )
		);
		await jest.advanceTimersByTimeAsync( 4000 );
		expect( settled ).toEqual( [ [ 'first', null ] ] );

		sendWooPayMessage( {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/second' },
		} );
		await flushPromises();
		expect( settled[ 1 ] ).toEqual( [
			'second',
			{ redirect_url: 'https://pay.woo.test/second' },
		] );
	} );

	// A request sent to an iframe that left the page gets no answer, so it must not stand in front of the new iframe's.
	test( 'gives the answer of a replacement iframe to the request sent to it', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'lost' ).then( ( value ) =>
			settled.push( [ 'lost', value ] )
		);
		await loadConnectIframe();

		document.getElementById( 'woopay-connect-iframe' ).remove();
		getPreemptiveSession( connect, 'replacement' ).then( ( value ) =>
			settled.push( [ 'replacement', value ] )
		);
		await loadConnectIframe();
		sendWooPayMessage( {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/replacement' },
		} );
		await flushPromises();

		expect( settled ).toEqual( [
			[
				'replacement',
				{ redirect_url: 'https://pay.woo.test/replacement' },
			],
		] );

		await jest.advanceTimersByTimeAsync( 5000 );
		expect( settled[ 1 ] ).toEqual( [ 'lost', null ] );
	} );

	// Another element under the id, with no wait of this client's on it: nothing sent to the old iframe is answered, and
	// the new one takes messages only once its connect page loads.
	test( 'follows the iframe element when another takes its id', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'lost' ).then( ( value ) =>
			settled.push( [ 'lost', value ] )
		);
		await loadConnectIframe();
		expect( postMessage ).toHaveBeenCalledTimes( 1 );

		const replacement = document.createElement( 'iframe' );
		replacement.id = 'woopay-connect-iframe';
		replacement.src = `${ WOOPAY_HOST }/connect/`;
		document
			.getElementById( 'woopay-connect-iframe' )
			.replaceWith( replacement );

		getPreemptiveSession( connect, 'replacement' ).then( ( value ) =>
			settled.push( [ 'replacement', value ] )
		);
		await flushPromises();
		expect( postMessage ).toHaveBeenCalledTimes( 1 );

		replacement.dispatchEvent( new window.Event( 'load' ) );
		await flushPromises();
		expect( postMessage ).toHaveBeenCalledTimes( 2 );

		sendWooPayMessage( {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/replacement' },
		} );
		await flushPromises();
		expect( settled ).toEqual( [
			[
				'replacement',
				{ redirect_url: 'https://pay.woo.test/replacement' },
			],
		] );

		await jest.advanceTimersByTimeAsync( 5000 );
		expect( settled[ 1 ] ).toEqual( [ 'lost', null ] );
	} );

	// Answers name no request, but the source tells two iframes apart: an answer the old iframe had already sent belongs to
	// a request the old iframe took, not to the new iframe's.
	test( 'takes no answer from an iframe another has replaced', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'old' ).then( ( value ) =>
			settled.push( [ 'old', value ] )
		);
		await loadConnectIframe();
		const oldIframe = document.getElementById( 'woopay-connect-iframe' );
		const oldWindow = getIframeWindow( oldIframe );

		const replacement = document.createElement( 'iframe' );
		replacement.id = 'woopay-connect-iframe';
		replacement.src = `${ WOOPAY_HOST }/connect/`;
		oldIframe.replaceWith( replacement );
		getPreemptiveSession( connect, 'new' ).then( ( value ) =>
			settled.push( [ 'new', value ] )
		);
		replacement.dispatchEvent( new window.Event( 'load' ) );
		await flushPromises();

		dispatchFromWindow( oldWindow, {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/old' },
		} );
		await flushPromises();
		expect( settled ).toEqual( [] );

		sendWooPayMessage( {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/new' },
		} );
		await flushPromises();
		expect( settled ).toEqual( [
			[ 'new', { redirect_url: 'https://pay.woo.test/new' } ],
		] );
	} );

	// A browser answers null for a removed iframe's window, where jsdom keeps it, so the row sets that null by hand. A
	// message whose frame was discarded carries a null source too, and any frame on WooPay's origin can send one.
	test( 'settles nothing for a null source once the iframe has left the page', async () => {
		jest.useFakeTimers();
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		const settled = [];
		getPreemptiveSession( connect, 'detached' ).then( ( value ) =>
			settled.push( [ 'detached', value ] )
		);
		await loadConnectIframe();

		const iframe = document.getElementById( 'woopay-connect-iframe' );
		iframe.remove();
		Object.defineProperty( iframe, 'contentWindow', {
			configurable: true,
			value: null,
		} );

		dispatchFromWindow( null, {
			action: 'set_preemptive_session_data_success',
			value: { redirect_url: 'https://pay.woo.test/another-frame' },
		} );
		await flushPromises();
		expect( settled ).toEqual( [] );

		await jest.advanceTimersByTimeAsync( 5000 );
		expect( settled ).toEqual( [ [ 'detached', null ] ] );
	} );

	test( 'asks for the preferred card again when another iframe takes the id', async () => {
		const connect = loadGetConnect()( { woopayHost: WOOPAY_HOST } );
		connect.fetchPreferredCard();
		await loadConnectIframe();
		sendWooPayMessage( {
			action: 'get_preferred_payment_method_success',
			value: { brand: 'visa', last4: '4242' },
		} );
		await flushPromises();

		const replacement = document.createElement( 'iframe' );
		replacement.id = 'woopay-connect-iframe';
		replacement.src = `${ WOOPAY_HOST }/connect/`;
		document
			.getElementById( 'woopay-connect-iframe' )
			.replaceWith( replacement );

		const card = connect.fetchPreferredCard();
		replacement.dispatchEvent( new window.Event( 'load' ) );
		await flushPromises();
		expect( postMessage ).toHaveBeenCalledTimes( 2 );

		sendWooPayMessage( {
			action: 'get_preferred_payment_method_success',
			value: { brand: 'amex', last4: '0005' },
		} );
		await expect( card ).resolves.toEqual( {
			brand: 'amex',
			last4: '0005',
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

		expect( scripts ).toHaveLength( 1 );

		window.wcWooPaymentsPhoneValidation = {
			validatePhoneNumber: () => true,
		};
		scripts[ 0 ].onload();

		await expect( first ).resolves.toBe(
			window.wcWooPaymentsPhoneValidation
		);
		await expect( second ).resolves.toBe(
			window.wcWooPaymentsPhoneValidation
		);
	} );
} );
