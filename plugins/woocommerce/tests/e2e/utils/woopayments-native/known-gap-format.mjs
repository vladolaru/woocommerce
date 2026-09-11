/**
 * The known-gap identity contract, shared by the two ends that must agree on
 * it: the runtime helper that declares a gap during a pilot
 * (utils/woopayments-native/known-gap.ts) and the ledger validator that accepts
 * the recorded evidence (bin/lib/woopayments-migration-evidence.mjs). Defined
 * once so widening the ID scheme cannot make a gap the runtime accepts and the
 * ledger rejects.
 *
 * Plain .mjs so both the Playwright-transpiled TypeScript and the bare Node
 * validator can import it.
 */

export const LOCAL_GAP_ID_PATTERN = /^WPNATIVE-GAP-[0-9]{4}$/;

/**
 * A gap fingerprint must match a whole message, never a substring, so a
 * loosened pattern cannot silently start absorbing unrelated failures.
 *
 * @param {string} patternSource The regular expression source text.
 * @return {boolean} Whether the pattern is anchored at both ends.
 */
export const isAnchoredMessagePattern = ( patternSource ) =>
	patternSource.startsWith( '^' ) && patternSource.endsWith( '$' );
