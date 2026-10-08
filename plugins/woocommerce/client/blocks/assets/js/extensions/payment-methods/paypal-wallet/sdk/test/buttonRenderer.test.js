// errorHandler pulls in the classic button module; the button click path is
// not exercised here.
jest.mock( '../utils/errorHandler', () => ( { handleError: jest.fn() } ) );

import {
	createMethodButton,
	renderButtons,
} from '../components/buttonRenderer';

const noop = () => {};

describe( 'createMethodButton', () => {
	test( 'sets pay later product details as properties before returning', () => {
		const button = createMethodButton( {
			method: 'paylater',
			styles: {},
			session: {},
			createOrderFn: noop,
			payLaterDetails: { productCode: 'PAYLATER', countryCode: 'US' },
		} );

		expect( button.tagName.toLowerCase() ).toBe(
			'paypal-pay-later-button'
		);
		expect( button.productCode ).toBe( 'PAYLATER' );
		expect( button.countryCode ).toBe( 'US' );
		// Configured but not yet inserted into the DOM.
		expect( button.isConnected ).toBe( false );
	} );

	test( 'applies colour, height and border radius to the element', () => {
		const button = createMethodButton( {
			method: 'paypal',
			styles: {
				colorClass: 'paypal-blue',
				height: '48px',
				borderRadius: '8px',
			},
			session: {},
			createOrderFn: noop,
		} );

		expect( button.className ).toBe( 'paypal-blue' );
		expect( button.style.height ).toBe( '48px' );
		expect(
			button.style.getPropertyValue( '--paypal-button-border-radius' )
		).toBe( '8px' );
	} );

	test( 'sets the border radius on the property each element actually reads', () => {
		// The Venmo element ignores --paypal-button-border-radius, so a single
		// shared property leaves Venmo square while the others round.
		const venmo = createMethodButton( {
			method: 'venmo',
			styles: { borderRadius: '30px' },
			session: {},
			createOrderFn: noop,
		} );

		expect(
			venmo.style.getPropertyValue( '--venmo-button-border-radius' )
		).toBe( '30px' );
		expect(
			venmo.style.getPropertyValue( '--paypal-button-border-radius' )
		).toBe( '' );

		const payLater = createMethodButton( {
			method: 'paylater',
			styles: { borderRadius: '30px' },
			session: {},
			createOrderFn: noop,
			payLaterDetails: { productCode: 'PAYLATER' },
		} );

		expect(
			payLater.style.getPropertyValue( '--paypal-button-border-radius' )
		).toBe( '30px' );
	} );

	test( 'returns null for pay later without product details', () => {
		const button = createMethodButton( {
			method: 'paylater',
			styles: {},
			session: {},
			createOrderFn: noop,
			payLaterDetails: null,
		} );

		expect( button ).toBeNull();
	} );

	test( 'returns null for an unknown method', () => {
		const button = createMethodButton( {
			method: 'nope',
			styles: {},
			session: {},
			createOrderFn: noop,
		} );

		expect( button ).toBeNull();
	} );
} );

describe( 'renderButtons', () => {
	test.each( [
		[ 'skips', false ],
		[ 'renders', true ],
	] )(
		'%s the Venmo button when venmoEnabled is %s, with a valid session',
		( _verb, venmoEnabled ) => {
			const wrapper = document.createElement( 'div' );
			document.body.appendChild( wrapper );

			renderButtons( {
				wrapper,
				sessions: { paypal: {}, venmo: {} },
				styles: {},
				createOrderForFunding: () => noop,
				venmoEnabled,
			} );

			expect( wrapper.querySelector( 'venmo-button' ) !== null ).toBe(
				venmoEnabled
			);
			expect( wrapper.querySelector( 'paypal-button' ) ).not.toBeNull();

			document.body.removeChild( wrapper );
		}
	);

	test( 'skips the pay later button when payLaterEnabled is not set, even with a valid session and product details', () => {
		const wrapper = document.createElement( 'div' );
		document.body.appendChild( wrapper );

		const rendered = renderButtons( {
			wrapper,
			sessions: { paypal: {}, paylater: {} },
			styles: {},
			createOrderForFunding: () => noop,
			payLaterDetails: { productCode: 'PAYLATER' },
		} );

		expect( wrapper.querySelector( 'paypal-pay-later-button' ) ).toBeNull();
		expect(
			rendered.some( ( el ) => el.tagName === 'PAYPAL-PAY-LATER-BUTTON' )
		).toBe( false );

		document.body.removeChild( wrapper );
	} );

	test( 'renders the pay later button when payLaterEnabled is true and product details are present', () => {
		const wrapper = document.createElement( 'div' );
		document.body.appendChild( wrapper );

		const rendered = renderButtons( {
			wrapper,
			sessions: { paypal: {}, paylater: {} },
			styles: {},
			createOrderForFunding: () => noop,
			payLaterDetails: { productCode: 'PAYLATER' },
			payLaterEnabled: true,
		} );

		expect(
			wrapper.querySelector( 'paypal-pay-later-button' )
		).not.toBeNull();
		expect(
			rendered.some( ( el ) => el.tagName === 'PAYPAL-PAY-LATER-BUTTON' )
		).toBe( true );

		document.body.removeChild( wrapper );
	} );
} );
