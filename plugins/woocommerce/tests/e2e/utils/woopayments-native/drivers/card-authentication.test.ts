import { expect, test } from '@playwright/test';

import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	completeCardAuthentication,
	type CardAuthenticationBrowser,
	type ChallengeResponse,
} from './card-authentication';

interface StubOptions {
	outerFrame?: boolean;
	challengeFrame?: boolean;
	challengeReady?: boolean;
	dismissed?: boolean;
	respondError?: Error;
}

interface Stub extends CardAuthenticationBrowser {
	readonly calls: string[];
}

function stubBrowser( options: StubOptions = {} ): Stub {
	const {
		outerFrame = true,
		challengeFrame = true,
		challengeReady = true,
		dismissed = true,
		respondError,
	} = options;
	const calls: string[] = [];

	return {
		calls,
		async waitForOuterFrame() {
			calls.push( 'waitForOuterFrame' );
			return outerFrame;
		},
		async waitForChallengeFrame() {
			calls.push( 'waitForChallengeFrame' );
			return challengeFrame;
		},
		async waitForChallengeReady() {
			calls.push( 'waitForChallengeReady' );
			return challengeReady;
		},
		async respondToChallenge( response: ChallengeResponse ) {
			calls.push( `respondToChallenge:${ response }` );
			if ( respondError ) {
				throw respondError;
			}
		},
		async waitForChallengeDismissed() {
			calls.push( 'waitForChallengeDismissed' );
			return dismissed;
		},
	};
}

test( 'a presented challenge answered with complete yields dismissed evidence', async () => {
	const browser = stubBrowser();

	const evidence = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser,
	} );

	expect( evidence ).toEqual( {
		expectation: 'challenge',
		authenticationSurfacePresented: true,
		challengePresented: true,
		response: 'complete',
		challengeDismissed: true,
	} );
	expect( browser.calls ).toContain( 'respondToChallenge:complete' );
} );

test( 'a presented challenge can be failed on purpose', async () => {
	const browser = stubBrowser();

	const evidence = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'fail',
		browser,
	} );

	expect( evidence.response ).toBe( 'fail' );
	expect( browser.calls ).toContain( 'respondToChallenge:fail' );
} );

test( 'an expected challenge that never appears fails instead of passing', async () => {
	const browser = stubBrowser( { challengeFrame: false } );

	await expect(
		completeCardAuthentication( {
			expectation: 'challenge',
			response: 'complete',
			browser,
		} )
	).rejects.toThrow( /no readable challenge frame/ );

	expect( browser.calls ).not.toContain( 'respondToChallenge:complete' );
} );

test( 'an expected challenge with no authentication surface at all is distinguished', async () => {
	const browser = stubBrowser( { outerFrame: false } );

	await expect(
		completeCardAuthentication( {
			expectation: 'challenge',
			response: 'complete',
			browser,
		} )
	).rejects.toThrow( /no authentication surface at all/ );

	// The inner frame is never consulted when the outer one is absent.
	expect( browser.calls ).not.toContain( 'waitForChallengeFrame' );
} );

test( 'a frictionless expectation passes only when no challenge is presented', async () => {
	const browser = stubBrowser( { outerFrame: false, challengeFrame: false } );

	const evidence = await completeCardAuthentication( {
		expectation: 'frictionless',
		browser,
	} );

	expect( evidence ).toEqual( {
		expectation: 'frictionless',
		authenticationSurfacePresented: false,
		challengePresented: false,
	} );
	expect( browser.calls ).not.toContain( 'waitForChallengeReady' );
} );

test( 'a frictionless expectation fails when a challenge is presented', async () => {
	await expect(
		completeCardAuthentication( {
			expectation: 'frictionless',
			browser: stubBrowser(),
		} )
	).rejects.toThrow( /without a challenge, but one was presented/ );
} );

test( 'expecting a challenge without saying how to answer it is rejected', async () => {
	await expect(
		completeCardAuthentication( {
			expectation: 'challenge',
			browser: stubBrowser(),
		} )
	).rejects.toThrow( /must say how to answer it/ );
} );

test( 'expecting no challenge while supplying a response is rejected', async () => {
	await expect(
		completeCardAuthentication( {
			expectation: 'frictionless',
			response: 'complete',
			browser: stubBrowser(),
		} )
	).rejects.toThrow( /cannot also answer one/ );
} );

test( 'a challenge that stays on screen after the response quarantines', async () => {
	const error = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser: stubBrowser( { dismissed: false } ),
	} ).catch( ( thrown: unknown ) => thrown );

	expect( error ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( error as ResourceQuarantineRequiredError ).reasonCode ).toBe(
		'uncertain-provider-write'
	);
	expect( ( error as Error ).message ).toMatch( /outcome is unknown/ );
} );

test( 'a response that throws quarantines rather than failing plainly', async () => {
	const error = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser: stubBrowser( {
			respondError: new Error( 'detached frame' ),
		} ),
	} ).catch( ( thrown: unknown ) => thrown );

	expect( error ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( error as Error ).message ).toMatch( /may have been submitted/ );
} );

test( 'failures before any response are plain errors, not quarantines', async () => {
	const error = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser: stubBrowser( { challengeFrame: false } ),
	} ).catch( ( thrown: unknown ) => thrown );

	expect( error ).toBeInstanceOf( Error );
	expect( error ).not.toBeInstanceOf( ResourceQuarantineRequiredError );
} );

test( 'a frictionless expectation fails when the surface opened but no challenge could be read', async () => {
	// The regression that matters: this is what a renamed challenge-frame
	// locator looks like. Passing here would make every frictionless
	// assertion survive a locator that matches nothing.
	const browser = stubBrowser( { outerFrame: true, challengeFrame: false } );

	await expect(
		completeCardAuthentication( {
			expectation: 'frictionless',
			browser,
		} )
	).rejects.toThrow( /could not be read/ );
} );

test( 'a challenge still loading fails before the response rather than after it', async () => {
	const browser = stubBrowser( { challengeReady: false } );

	const error = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser,
	} ).catch( ( thrown: unknown ) => thrown );

	// Nothing was dispatched, so this must not consume a quarantine.
	expect( error ).not.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( error as Error ).message ).toMatch( /Nothing was submitted/ );
	expect( browser.calls ).not.toContain( 'respondToChallenge:complete' );
} );

test( 'an unrecognized response is rejected before the challenge is answered', async () => {
	const browser = stubBrowser();

	await expect(
		completeCardAuthentication( {
			expectation: 'challenge',
			// A JavaScript caller the union cannot reach.
			response: 'complte' as ChallengeResponse,
			browser,
		} )
	).rejects.toThrow( /does not recognize the response complte/ );

	expect(
		browser.calls.some( ( call ) =>
			call.startsWith( 'respondToChallenge' )
		)
	).toBe( false );
} );

test( 'a quarantine carries the underlying error for diagnosis', async () => {
	const primaryError = new Error( 'detached frame' );

	const error = await completeCardAuthentication( {
		expectation: 'challenge',
		response: 'complete',
		browser: stubBrowser( { respondError: primaryError } ),
	} ).catch( ( thrown: unknown ) => thrown );

	expect( ( error as ResourceQuarantineRequiredError ).primaryError ).toBe(
		primaryError
	);
} );
