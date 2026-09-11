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

const CHALLENGE_RESPONSES: readonly ChallengeResponse[] = [
	'complete',
	'fail',
];

export interface CardAuthenticationEvidence {
	expectation: AuthenticationExpectation;
	/**
	 * Whether the provider opened its authentication surface at all. Recorded
	 * rather than discarded: without it, a challenge frame that cannot be
	 * found is indistinguishable from a provider that never needed one, and
	 * the frictionless assertion would pass on a rotted locator.
	 */
	authenticationSurfacePresented: boolean;
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
	waitForOuterFrame: ( timeoutMs: number ) => Promise< boolean >;
	/** Resolves true when the challenge frame inside it becomes visible. */
	waitForChallengeFrame: ( timeoutMs: number ) => Promise< boolean >;
	/** Resolves true when the challenge has finished loading. */
	waitForChallengeReady: ( timeoutMs: number ) => Promise< boolean >;
	respondToChallenge: ( response: ChallengeResponse ) => Promise< void >;
	/** Resolves true when the challenge surface is gone. */
	waitForChallengeDismissed: ( timeoutMs: number ) => Promise< boolean >;
}

interface SharedCardAuthenticationOptions {
	browser: CardAuthenticationBrowser;
	frameTimeoutMs?: number;
	settleTimeoutMs?: number;
}

/**
 * The expectation and the response travel together, so a caller cannot name a
 * challenge without saying how to answer it and cannot answer one it says will
 * not appear. Expressing that in the type rather than only at runtime keeps
 * `response` non-optional on the path that clicks: a widened runtime guard
 * would otherwise let `undefined` reach the click, where anything that is not
 * `complete` used to mean Fail.
 */
export type CompleteCardAuthenticationOptions =
	SharedCardAuthenticationOptions &
		(
			| { expectation: 'challenge'; response: ChallengeResponse }
			| { expectation: 'frictionless'; response?: never }
		);

function fail( message: string ): never {
	throw new Error( `Card authentication ${ message }` );
}

/**
 * Answering a challenge can authorize a real charge, so an indeterminate state
 * after the response is a provider write we cannot account for.
 */
function quarantine(
	message: string,
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments card authentication ${ message }`,
		'uncertain-provider-write',
		primaryError
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

	// Runtime guards for callers the type cannot reach, such as JavaScript
	// specs. They restate the union above rather than replacing it.
	if ( expectation === 'challenge' && response === undefined ) {
		fail( 'expecting a challenge must say how to answer it.' );
	}
	if ( expectation === 'frictionless' && response !== undefined ) {
		fail( 'expecting no challenge cannot also answer one.' );
	}
	if (
		response !== undefined &&
		! CHALLENGE_RESPONSES.includes( response )
	) {
		fail(
			`does not recognize the response ${ String(
				response
			) }; expected complete or fail.`
		);
	}

	const authenticationSurfacePresented =
		await browser.waitForOuterFrame( frameTimeoutMs );
	const challengePresented = authenticationSurfacePresented
		? await browser.waitForChallengeFrame( frameTimeoutMs )
		: false;

	if ( expectation === 'frictionless' ) {
		if ( challengePresented ) {
			fail(
				'expected the provider to authenticate without a challenge, but one was presented.'
			);
		}
		// An authentication surface that opened and then yielded no readable
		// challenge is not a frictionless payment. Passing here would make
		// this assertion survive a renamed challenge frame while proving
		// nothing - the same defect this driver exists to avoid.
		if ( authenticationSurfacePresented ) {
			fail(
				'expected the provider to authenticate without a challenge, but it opened an authentication surface whose challenge could not be read. Treating that as frictionless would pass on a locator that no longer matches.'
			);
		}

		return {
			expectation,
			authenticationSurfacePresented: false,
			challengePresented: false,
		};
	}

	if ( ! challengePresented ) {
		fail(
			authenticationSurfacePresented
				? 'expected a challenge, but the provider opened an authentication surface with no readable challenge frame. Either the card no longer triggers authentication or the challenge locator no longer matches; both proofs are void.'
				: 'expected a challenge, but the provider presented no authentication surface at all.'
		);
	}

	// Everything above this line is safe to fail loudly: nothing has been
	// authorized. Everything below can leave a charge behind.
	const challengeReady =
		await browser.waitForChallengeReady( frameTimeoutMs );

	if ( ! challengeReady ) {
		// Fail before the click rather than after. Clicking a challenge that
		// never finished loading times out on a button that was never
		// actionable, which would raise a quarantine for a write that was
		// never dispatched.
		fail(
			'expected the challenge to finish loading before answering it, but it was still loading. Nothing was submitted.'
		);
	}

	try {
		await browser.respondToChallenge( response );
	} catch ( error ) {
		throw quarantine(
			'could not be answered and may have been submitted anyway.',
			error
		);
	}

	const challengeDismissed =
		await browser.waitForChallengeDismissed( settleTimeoutMs );

	if ( ! challengeDismissed ) {
		// A challenge still on screen means the response did not take, or took
		// and left the surface behind. Neither is a known provider state.
		throw quarantine(
			'was answered but the challenge surface remained, so the authentication outcome is unknown.'
		);
	}

	return {
		expectation,
		authenticationSurfacePresented: true,
		challengePresented: true,
		response,
		challengeDismissed: true,
	};
}

/**
 * Turns "the wait timed out" into `false` and lets every other failure
 * through. A blanket catch would fold a strict-mode violation from an
 * ambiguous selector into "not presented", and "not presented" is the passing
 * condition on the frictionless path - so an ambiguous locator would read as
 * proof rather than as the defect it is.
 */
async function absentOnTimeout(
	wait: Promise< void >,
	subject: string
): Promise< boolean > {
	try {
		await wait;
		return true;
	} catch ( error ) {
		if ( ( error as { name?: string } )?.name === 'TimeoutError' ) {
			return false;
		}
		throw new Error(
			`Card authentication could not determine whether ${ subject } was present: ${ String(
				error
			) }`
		);
	}
}

function challengeButtonName( response: ChallengeResponse ): string {
	switch ( response ) {
		case 'complete':
			return 'Complete';
		case 'fail':
			return 'Fail';
		default: {
			// Exhaustive: never silently answer a challenge one way because
			// the caller asked for something unrecognized.
			const unreachable: never = response;
			throw new Error(
				`Card authentication cannot answer a challenge with ${ String(
					unreachable
				) }.`
			);
		}
	}
}

export class PlaywrightCardAuthenticationBrowser
	implements CardAuthenticationBrowser
{
	private readonly page: Page;

	public constructor( page: Page ) {
		this.page = page;
	}

	public async waitForOuterFrame( timeoutMs: number ): Promise< boolean > {
		return absentOnTimeout(
			this.page
				.locator( OUTER_FRAME )
				.waitFor( { state: 'visible', timeout: timeoutMs } ),
			'the provider authentication surface'
		);
	}

	public async waitForChallengeFrame(
		timeoutMs: number
	): Promise< boolean > {
		return absentOnTimeout(
			this.challengeBody().waitFor( {
				state: 'visible',
				timeout: timeoutMs,
			} ),
			'the provider challenge frame'
		);
	}

	public async waitForChallengeReady(
		timeoutMs: number
	): Promise< boolean > {
		return absentOnTimeout(
			this.page
				.frameLocator( OUTER_FRAME )
				.locator( LOADING_INDICATOR )
				.waitFor( { state: 'hidden', timeout: timeoutMs } ),
			'the challenge loading indicator'
		);
	}

	public async respondToChallenge(
		response: ChallengeResponse
	): Promise< void > {
		await this.page
			.frameLocator( OUTER_FRAME )
			.frameLocator( CHALLENGE_FRAME )
			.getByRole( 'button', {
				name: challengeButtonName( response ),
				exact: true,
			} )
			.click();
	}

	public async waitForChallengeDismissed(
		timeoutMs: number
	): Promise< boolean > {
		return absentOnTimeout(
			this.challengeBody().waitFor( {
				state: 'hidden',
				timeout: timeoutMs,
			} ),
			'the challenge frame dismissal'
		);
	}

	private challengeBody() {
		return this.page
			.frameLocator( OUTER_FRAME )
			.frameLocator( CHALLENGE_FRAME )
			.locator( 'body' );
	}
}
