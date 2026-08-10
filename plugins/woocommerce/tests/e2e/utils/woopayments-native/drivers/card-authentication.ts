import type { Page } from '@playwright/test';

import { ResourceQuarantineRequiredError } from '../resource-locks';

/**
 * Drives the provider's 3D Secure challenge and proves it happened.
 *
 * The WooPayments extension suite has an equivalent helper, and its failure
 * mode is the reason this one exists. That helper returns silently when the
 * challenge iframe never appears, so a caller expecting an authentication
 * challenge passes whether or not one was presented - one of the contracts
 * the ledger records as unable to fail. Here an expected challenge that does
 * not appear is an error, and a caller that wants the frictionless path must
 * ask for it by name.
 */

const OUTER_FRAME = 'body > div > iframe[name^="__privateStripeFrame"]';
const CHALLENGE_FRAME = 'iframe[name="stripe-challenge-frame"]';
const LOADING_INDICATOR = '.LightboxModalLoadingIndicator';
const FRAME_TIMEOUT_MS = 20_000;
const SETTLE_TIMEOUT_MS = 20_000;

/**
 * What the caller asserts the provider will do with this card and amount.
 *
 * `challenge` requires a challenge to be presented. `frictionless` requires
 * that none is - the provider authenticated in the background. There is no
 * "either" value on purpose: a run that cannot say which outcome it expects
 * cannot tell a working challenge from a missing one.
 */
export type AuthenticationExpectation = 'challenge' | 'frictionless';

/**
 * How the shopper answers a presented challenge.
 */
export type ChallengeResponse = 'complete' | 'fail';

export interface CardAuthenticationEvidence {
	expectation: AuthenticationExpectation;
	challengePresented: boolean;
	/** Absent when no challenge was presented. */
	response?: ChallengeResponse;
	/** Whether the challenge surface was gone after the response. */
	challengeDismissed?: boolean;
}

/**
 * Browser work this driver needs, kept behind a seam so the decision logic is
 * testable without a page.
 */
export interface CardAuthenticationBrowser {
	/** Resolves true when the provider's outer frame becomes visible. */
	waitForOuterFrame( timeoutMs: number ): Promise< boolean >;
	/** Resolves true when the challenge frame inside it becomes visible. */
	waitForChallengeFrame( timeoutMs: number ): Promise< boolean >;
	/** Waits for the challenge to stop loading before it can be answered. */
	waitForChallengeReady( timeoutMs: number ): Promise< void >;
	respondToChallenge( response: ChallengeResponse ): Promise< void >;
	/** Resolves true when the challenge surface is gone. */
	waitForChallengeDismissed( timeoutMs: number ): Promise< boolean >;
}

export interface CompleteCardAuthenticationOptions {
	expectation: AuthenticationExpectation;
	/** Required for `challenge`; rejected for `frictionless`. */
	response?: ChallengeResponse;
	browser: CardAuthenticationBrowser;
	frameTimeoutMs?: number;
	settleTimeoutMs?: number;
}

function fail( message: string ): never {
	throw new Error( `Card authentication ${ message }` );
}

/**
 * Answering a challenge can authorize a real charge, so an indeterminate state
 * after the response is a provider write we cannot account for.
 */
function quarantine( message: string ): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments card authentication ${ message }`,
		'uncertain-provider-write'
	);
}

export async function completeCardAuthentication(
	options: CompleteCardAuthenticationOptions
): Promise< CardAuthenticationEvidence > {
	const {
		expectation,
		response,
		browser,
		frameTimeoutMs = FRAME_TIMEOUT_MS,
		settleTimeoutMs = SETTLE_TIMEOUT_MS,
	} = options;

	if ( expectation === 'challenge' && response === undefined ) {
		fail( 'expecting a challenge must say how to answer it.' );
	}
	if ( expectation === 'frictionless' && response !== undefined ) {
		fail( 'expecting no challenge cannot also answer one.' );
	}

	const outerFramePresented = await browser.waitForOuterFrame(
		frameTimeoutMs
	);
	const challengePresented = outerFramePresented
		? await browser.waitForChallengeFrame( frameTimeoutMs )
		: false;

	if ( expectation === 'frictionless' ) {
		if ( challengePresented ) {
			fail(
				'expected the provider to authenticate without a challenge, but one was presented.'
			);
		}

		return { expectation, challengePresented: false };
	}

	if ( ! challengePresented ) {
		fail(
			outerFramePresented
				? 'expected a challenge, but the provider presented no challenge frame. A card or amount that no longer triggers authentication proves nothing about the authenticated path.'
				: 'expected a challenge, but the provider presented no authentication surface at all.'
		);
	}

	// Everything above this line is safe to fail loudly: nothing has been
	// authorized. Everything below can leave a charge behind.
	await browser.waitForChallengeReady( frameTimeoutMs );

	const chosen = response as ChallengeResponse;

	try {
		await browser.respondToChallenge( chosen );
	} catch ( error ) {
		throw quarantine(
			`could not be answered and may have been submitted anyway: ${ String(
				error
			) }`
		);
	}

	const challengeDismissed = await browser.waitForChallengeDismissed(
		settleTimeoutMs
	);

	if ( ! challengeDismissed ) {
		// A challenge still on screen means the response did not take, or took
		// and left the surface behind. Neither is a known provider state.
		throw quarantine(
			'was answered but the challenge surface remained, so the authentication outcome is unknown.'
		);
	}

	return {
		expectation,
		challengePresented: true,
		response: chosen,
		challengeDismissed: true,
	};
}

export class PlaywrightCardAuthenticationBrowser
	implements CardAuthenticationBrowser
{
	private readonly page: Page;

	public constructor( page: Page ) {
		this.page = page;
	}

	public async waitForOuterFrame( timeoutMs: number ): Promise< boolean > {
		return this.page
			.locator( OUTER_FRAME )
			.waitFor( { state: 'visible', timeout: timeoutMs } )
			.then( () => true )
			.catch( () => false );
	}

	public async waitForChallengeFrame(
		timeoutMs: number
	): Promise< boolean > {
		return this.challengeBody()
			.waitFor( { state: 'visible', timeout: timeoutMs } )
			.then( () => true )
			.catch( () => false );
	}

	public async waitForChallengeReady( timeoutMs: number ): Promise< void > {
		await this.page
			.frameLocator( OUTER_FRAME )
			.locator( LOADING_INDICATOR )
			.waitFor( { state: 'hidden', timeout: timeoutMs } )
			.catch( () => undefined );
	}

	public async respondToChallenge(
		response: ChallengeResponse
	): Promise< void > {
		await this.page
			.frameLocator( OUTER_FRAME )
			.frameLocator( CHALLENGE_FRAME )
			.getByRole( 'button', {
				name: response === 'complete' ? 'Complete' : 'Fail',
				exact: true,
			} )
			.click();
	}

	public async waitForChallengeDismissed(
		timeoutMs: number
	): Promise< boolean > {
		return this.challengeBody()
			.waitFor( { state: 'hidden', timeout: timeoutMs } )
			.then( () => true )
			.catch( () => false );
	}

	private challengeBody() {
		return this.page
			.frameLocator( OUTER_FRAME )
			.frameLocator( CHALLENGE_FRAME )
			.locator( 'body' );
	}
}
