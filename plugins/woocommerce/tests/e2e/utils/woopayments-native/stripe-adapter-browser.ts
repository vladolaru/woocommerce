import type { Page } from '@playwright/test';

const ADAPTER_PATH = '/wp-content/mu-plugins/stripe-messaging-adapter.js';

interface StripeAdapterSnapshot {
	scripts: string[];
	resources: string[];
	frames: string[];
	errors: unknown;
	calls: unknown;
	origin: string;
}

function isProviderUrl( value: string ): boolean {
	try {
		const hostname = new URL( value ).hostname.toLowerCase();
		return (
			hostname === 'stripe.com' ||
			hostname.endsWith( '.stripe.com' ) ||
			hostname === 'stripecdn.com' ||
			hostname.endsWith( '.stripecdn.com' ) ||
			hostname === 'stripe.network' ||
			hostname.endsWith( '.stripe.network' )
		);
	} catch {
		return false;
	}
}

function readRecord( value: unknown ): Record< string, unknown > | null {
	return typeof value === 'object' && value !== null
		? ( value as Record< string, unknown > )
		: null;
}

export interface StrictStripeAdapterBrowserOracle {
	assertPaymentMounted: ( mount: string ) => Promise< void >;
}

/**
 * Observe a fixture checkout from before navigation and prove that Core used
 * the strict local payment adapter without loading Stripe provider runtime.
 */
export function startStrictStripeAdapterBrowserOracle(
	page: Page
): StrictStripeAdapterBrowserOracle {
	const requestedUrls: string[] = [];
	page.on( 'request', ( request ) => {
		if ( isProviderUrl( request.url() ) ) {
			requestedUrls.push( request.url() );
		}
	} );

	return {
		async assertPaymentMounted( mount: string ): Promise< void > {
			const snapshot = await page.evaluate< StripeAdapterSnapshot >(
				() => ( {
					scripts: Array.from(
						document.scripts,
						( script ) => script.src
					).filter( Boolean ),
					resources: performance
						.getEntriesByType( 'resource' )
						.map( ( entry ) => entry.name ),
					frames: Array.from(
						document.querySelectorAll< HTMLIFrameElement >(
							'iframe'
						),
						( frame ) => frame.src
					).filter( Boolean ),
					errors: Reflect.get(
						window,
						'__wooPaymentsStripeAdapterErrors'
					),
					calls: Reflect.get(
						window,
						'__wooPaymentsStripePaymentCalls'
					),
					origin: window.location.origin,
				} )
			);
			const providerUrls = [
				...requestedUrls,
				...snapshot.scripts,
				...snapshot.resources,
				...snapshot.frames,
			].filter( isProviderUrl );
			if ( providerUrls.length > 0 ) {
				throw new Error(
					`Strict Stripe adapter observed provider URLs: ${ [
						...new Set( providerUrls ),
					].join( ', ' ) }`
				);
			}

			const stripeScripts = snapshot.scripts.filter( ( source ) => {
				try {
					return new URL( source ).pathname === ADAPTER_PATH;
				} catch {
					return false;
				}
			} );
			if ( stripeScripts.length === 0 ) {
				throw new Error(
					`Strict Stripe adapter script ${ ADAPTER_PATH } was not rendered.`
				);
			}
			if (
				! stripeScripts.some(
					( source ) => new URL( source ).origin === snapshot.origin
				)
			) {
				throw new Error(
					`Strict Stripe adapter script must be same-origin with ${ snapshot.origin }.`
				);
			}

			if ( ! Array.isArray( snapshot.errors ) ) {
				throw new Error(
					'Strict Stripe adapter error telemetry is missing.'
				);
			}
			if ( snapshot.errors.length > 0 ) {
				throw new Error(
					`Strict Stripe adapter errors: ${ snapshot.errors.join(
						', '
					) }`
				);
			}
			if ( ! Array.isArray( snapshot.calls ) ) {
				throw new Error(
					'Strict Stripe adapter payment telemetry is missing.'
				);
			}
			const matchingCall = snapshot.calls.some( ( value ) => {
				const call = readRecord( value );
				return (
					call?.type === 'payment' &&
					call.mount === mount &&
					Array.isArray( call.lifecycle ) &&
					call.lifecycle.includes( 'mount' )
				);
			} );
			if ( ! matchingCall ) {
				throw new Error(
					`Strict Stripe adapter recorded no payment mount for ${ mount }.`
				);
			}
		},
	};
}
