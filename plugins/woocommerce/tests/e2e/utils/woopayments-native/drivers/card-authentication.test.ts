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
	).rejects.toThrow( /presented no challenge frame/ );

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
	const browser = stubBrowser( { challengeFrame: false } );

	const evidence = await completeCardAuthentication( {
		expectation: 'frictionless',
		browser,
	} );

	expect( evidence ).toEqual( {
		expectation: 'frictionless',
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
