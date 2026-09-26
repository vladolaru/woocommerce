import type { APIResponse } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import { CLASSIC_CHECKOUT_PATH } from './classic-card-checkout';

/**
 * Puts a shortcode checkout page under the store for the length of one journey.
 *
 * The native store has no standing Classic checkout page. The only thing that
 * has ever created one is the card-testing protection controller, which makes
 * the page as a side effect of forcing protection on and refuses to run when a
 * page with that slug already exists. A protection-off Classic journey needs the
 * page without the setting, so it needs its own provisioner - and that
 * provisioner has to leave nothing behind, or the protection-on case that runs
 * next in the same file fails at its own exactness check.
 *
 * Two states are handled and told apart in the evidence. When the slug is free
 * this run creates the page, proves it by reading it back, and deletes it again,
 * proving the deletion too. When a page already holds the slug this run adopts
 * it, changes nothing, and proves at the end that it is byte-identical to what
 * it found. Anything else - two pages on one slug, a read-back that does not
 * match, a deletion that does not take - fails rather than guesses.
 */

const CLASSIC_CHECKOUT_SLUG = 'classic-checkout';
const PAGES_ENDPOINT = '/wp-json/wp/v2/pages';
const CHECKOUT_SHORTCODE = '[woocommerce_checkout]';

export interface ClassicCheckoutTarget {
	pageId: number;
	slug: typeof CLASSIC_CHECKOUT_SLUG;
	path: typeof CLASSIC_CHECKOUT_PATH;
}

export interface ClassicCheckoutPageScope {
	readonly classicCheckout: Readonly< ClassicCheckoutTarget >;
	/** Whether this run created the page, and so will remove it again. */
	readonly runOwned: boolean;
}

interface PageRecord {
	id: number;
	slug: string;
	status: string;
	contentRaw: string;
}

function fail( message: string ): never {
	throw new Error( `Classic checkout page ${ message }` );
}

function quarantine(
	message: string,
	reason: 'restoration-failed' | 'cleanup-failed',
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments Classic checkout page ${ message }`,
		reason,
		primaryError
	);
}

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		fail(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	try {
		return await response.json();
	} catch ( error ) {
		fail( `${ description } returned no JSON: ${ String( error ) }` );
	}
}

function toPageRecord( value: unknown, description: string ): PageRecord {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `${ description } is not a single page object.` );
	}
	const page = value as {
		id?: unknown;
		slug?: unknown;
		status?: unknown;
		content?: unknown;
	};
	const content =
		typeof page.content === 'object' && page.content !== null
			? ( page.content as { raw?: unknown } ).raw
			: undefined;
	if (
		! Number.isSafeInteger( page.id ) ||
		Number( page.id ) <= 0 ||
		typeof page.slug !== 'string' ||
		typeof page.status !== 'string' ||
		typeof content !== 'string'
	) {
		fail(
			`${ description } is missing an exact id, slug, status, or raw content.`
		);
	}
	return {
		id: page.id as number,
		slug: page.slug,
		status: page.status,
		contentRaw: content,
	};
}

async function findBySlug(
	session: ProviderWriteSession,
	description: string
): Promise< PageRecord[] > {
	const listed = await readJson(
		await session.adminApi.get(
			`${ PAGES_ENDPOINT }?slug=${ CLASSIC_CHECKOUT_SLUG }&status=any&context=edit&per_page=20`
		),
		description
	);
	if ( ! Array.isArray( listed ) ) {
		fail( `${ description } did not return a page collection.` );
	}
	return listed.map( ( page, index ) =>
		toPageRecord( page, `${ description } entry ${ index + 1 }` )
	);
}

async function readById(
	session: ProviderWriteSession,
	pageId: number,
	description: string
): Promise< PageRecord > {
	return toPageRecord(
		await readJson(
			await session.adminApi.get(
				`${ PAGES_ENDPOINT }/${ pageId }?context=edit`
			),
			description
		),
		description
	);
}

function readCreatedPageId( value: unknown ): number {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( 'creation did not return a single page object.' );
	}
	const id = ( value as { id?: unknown } ).id;
	if ( ! Number.isSafeInteger( id ) || Number( id ) <= 0 ) {
		fail(
			'creation returned no page ID, so a page may exist that nothing here can remove.'
		);
	}
	return id as number;
}

function sameRecord( left: PageRecord, right: PageRecord ): boolean {
	return (
		left.id === right.id &&
		left.slug === right.slug &&
		left.status === right.status &&
		left.contentRaw === right.contentRaw
	);
}

async function removeCreatedPage(
	session: ProviderWriteSession,
	pageId: number
): Promise< void > {
	const deleted = await session.performWrite( () =>
		session.adminApi.delete( `${ PAGES_ENDPOINT }/${ pageId }?force=true` )
	);
	if ( ! deleted.ok() ) {
		fail(
			`deletion failed: HTTP ${ deleted.status() } ${ await deleted.text() }`
		);
	}

	const remaining = await findBySlug( session, 'deletion verification' );
	if ( remaining.length !== 0 ) {
		fail(
			`deletion left ${ remaining.length } page(s) on slug ${ CLASSIC_CHECKOUT_SLUG }.`
		);
	}
}

function scopeFor(
	pageId: number,
	runOwned: boolean
): ClassicCheckoutPageScope {
	return Object.freeze( {
		classicCheckout: Object.freeze( {
			pageId,
			slug: CLASSIC_CHECKOUT_SLUG,
			path: CLASSIC_CHECKOUT_PATH,
		} ),
		runOwned,
	} );
}

async function withAdoptedPage< Result >(
	session: ProviderWriteSession,
	snapshot: PageRecord,
	callback: ( scope: ClassicCheckoutPageScope ) => Promise< Result >
): Promise< Result > {
	if ( snapshot.status !== 'publish' ) {
		fail(
			`slug ${ CLASSIC_CHECKOUT_SLUG } is held by a ${ snapshot.status } page, which no shopper can reach.`
		);
	}
	if ( ! snapshot.contentRaw.includes( CHECKOUT_SHORTCODE ) ) {
		fail(
			`the standing page on slug ${ CLASSIC_CHECKOUT_SLUG } renders no ${ CHECKOUT_SHORTCODE }.`
		);
	}

	let primaryError: unknown;
	let result: Result | undefined;
	try {
		result = await callback( scopeFor( snapshot.id, false ) );
	} catch ( error ) {
		primaryError = error;
	}

	// Nothing was changed, so restoration is a proof rather than an action: the
	// standing page must be exactly what this run found.
	let restorationError: unknown;
	try {
		const current = await readById(
			session,
			snapshot.id,
			'adopted page verification'
		);
		if ( ! sameRecord( current, snapshot ) ) {
			restorationError = new Error(
				'the adopted Classic checkout page changed during the journey.'
			);
		}
	} catch ( error ) {
		restorationError = error;
	}

	if ( restorationError !== undefined ) {
		throw quarantine(
			'was adopted and could not be proven unchanged.',
			'restoration-failed',
			primaryError ?? restorationError
		);
	}
	if ( primaryError !== undefined ) {
		throw primaryError;
	}
	return result as Result;
}

async function withCreatedPage< Result >(
	session: ProviderWriteSession,
	runId: string,
	callback: ( scope: ClassicCheckoutPageScope ) => Promise< Result >
): Promise< Result > {
	const title = `WooPayments native E2E Classic checkout ${ runId }`;
	const contentRaw = `<!-- ${ runId } -->\n${ CHECKOUT_SHORTCODE }`;
	// Only the identity is taken from the creation response. Whether WordPress
	// returns editable content on a POST depends on how it resolves the request
	// context, and a page that exists but cannot be parsed here would be a page
	// nothing removes. The full record comes from the read-back below, which is
	// inside the removal guard.
	const createdId = readCreatedPageId(
		await readJson(
			await session.performWrite( () =>
				session.adminApi.post( PAGES_ENDPOINT, {
					data: {
						title,
						slug: CLASSIC_CHECKOUT_SLUG,
						status: 'publish',
						content: contentRaw,
					},
				} )
			),
			'creation'
		)
	);

	// Read the page back before anything depends on it. WordPress silently
	// suffixes a slug that is already taken by a trashed page, and a journey
	// pointed at `classic-checkout-2` would drive a page no assertion here
	// describes.
	let verificationError: unknown;
	try {
		const readBack = await readById(
			session,
			createdId,
			'creation verification'
		);
		if (
			! sameRecord( readBack, {
				id: createdId,
				slug: CLASSIC_CHECKOUT_SLUG,
				status: 'publish',
				contentRaw,
			} )
		) {
			verificationError = new Error(
				'the created Classic checkout page is not the exact requested page.'
			);
		}
	} catch ( error ) {
		verificationError = error;
	}

	if ( verificationError !== undefined ) {
		// Nothing has been driven yet, so the honest outcome is a clean removal
		// and a plain failure. Only a removal that cannot be proven leaves state
		// behind worth quarantining.
		await removeCreatedPage( session, createdId );
		throw verificationError;
	}

	let primaryError: unknown;
	let result: Result | undefined;
	try {
		result = await callback( scopeFor( createdId, true ) );
	} catch ( error ) {
		primaryError = error;
	}

	let removalError: unknown;
	try {
		await removeCreatedPage( session, createdId );
	} catch ( error ) {
		removalError = error;
	}

	if ( removalError !== undefined ) {
		throw quarantine(
			'was created and could not be proven removed, so the next Classic journey would find a page it does not own.',
			'restoration-failed',
			primaryError ?? removalError
		);
	}
	if ( primaryError !== undefined ) {
		throw primaryError;
	}
	return result as Result;
}

export async function withClassicCheckoutPage< Result >(
	session: ProviderWriteSession,
	runId: string,
	callback: ( scope: ClassicCheckoutPageScope ) => Promise< Result >
): Promise< Result > {
	if ( ! runId || runId !== session.runId ) {
		fail( 'provisioning requires the exact active run ID.' );
	}
	// The page is a local write, but it is only ever made inside an owned
	// provider interval, so the lock check belongs here too: a lost lock means
	// another run may be driving this store.
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'classic-checkout-page' );

	const existing = await findBySlug( session, 'lookup' );
	if ( existing.length > 1 ) {
		fail(
			`slug ${ CLASSIC_CHECKOUT_SLUG } is held by ${ existing.length } pages; exactly zero or one is required.`
		);
	}

	if ( existing.length === 1 ) {
		return withAdoptedPage( session, existing[ 0 ], callback );
	}

	return withCreatedPage( session, runId, callback );
}
