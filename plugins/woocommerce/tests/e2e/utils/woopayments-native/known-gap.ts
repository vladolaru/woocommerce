import type { TestInfo } from '@playwright/test';

// @ts-expect-error Shared with the bare-Node ledger validator, so it stays .mjs.
import {
	isAnchoredMessagePattern,
	LOCAL_GAP_ID_PATTERN,
} from './known-gap-format.mjs';

export const KNOWN_GAP_ANNOTATION = 'woopayments-known-gap';
export const KNOWN_GAP_SENTINEL = '[WCPAY_KNOWN_GAP:';

type KnownWooPaymentsGap = {
	id: string;
	owner: string;
	reference: string;
	fingerprint: {
		errorName: string;
		messagePattern: RegExp;
	};
};

function assertGapDefinition( gap: KnownWooPaymentsGap ): void {
	if ( ! LOCAL_GAP_ID_PATTERN.test( gap.id ) ) {
		throw new Error( `Invalid WooPayments known-gap ID: ${ gap.id }` );
	}
	if ( ! gap.owner.trim() ) {
		throw new Error( `${ gap.id } must have a nonempty owner.` );
	}
	if ( ! gap.reference.trim() ) {
		throw new Error( `${ gap.id } must have a nonempty reference.` );
	}
	if ( ! isAnchoredMessagePattern( gap.fingerprint.messagePattern.source ) ) {
		throw new Error( `${ gap.id } must use an anchored message pattern.` );
	}
}

export async function expectKnownWooPaymentsGap(
	testInfo: TestInfo,
	gap: KnownWooPaymentsGap,
	exercise: () => Promise< unknown >
): Promise< never > {
	assertGapDefinition( gap );
	try {
		await exercise();
	} catch ( error ) {
		const normalized =
			error instanceof Error ? error : new Error( String( error ) );
		if (
			normalized.name !== gap.fingerprint.errorName ||
			! gap.fingerprint.messagePattern.test( normalized.message )
		) {
			throw normalized;
		}

		testInfo.annotations.push( {
			type: KNOWN_GAP_ANNOTATION,
			description: `${ gap.id }|${ gap.owner }|${ gap.reference }`,
		} );
		testInfo.fail(
			true,
			`${ gap.id } is an exact, tracked native product gap.`
		);
		throw new Error(
			`${ KNOWN_GAP_SENTINEL }${ gap.id }] ${ normalized.message }`,
			{ cause: normalized }
		);
	}

	throw new Error(
		`[WCPAY_KNOWN_GAP_UNEXPECTED_PASS:${ gap.id }] The tracked gap no longer reproduces.`
	);
}
