import type { FrameLocator, Locator } from '@playwright/test';

import type { ProviderTestCard } from '../test-cards';

/**
 * Entering a card into a provider payment element, everywhere native mounts
 * one.
 *
 * This lives on its own rather than inside one family's driver because the
 * hazard it exists for belongs to the element, not to any surface: the element
 * clears every field it holds when its deferred `elements/sessions` response
 * arrives, and it relabels the expiry field across that same re-render. A
 * driver that types once and moves on, or that binds to the pre-hydration
 * label, is not wrong so much as racing — it passes whenever the response
 * happens to land early and fails, opaquely, whenever it does not.
 *
 * The contract here makes the entry a precondition rather than a hope: type,
 * read back, retype until the element holds what was typed, and address the
 * expiry by the name it carries in *both* renders.
 */

/**
 * How many times a card is retyped when the provider payment element throws
 * the entry away.
 *
 * Three is chosen for what it has to cover rather than as a general retry
 * budget: the element discards its fields once per mount, when its deferred
 * `elements/sessions` response lands, so one retype is the expected worst case
 * and the rest is headroom for a second, unexplained reset.
 */
const CARD_ENTRY_ATTEMPTS = 3;

/** How long the element is left to settle before an entry is read back. */
const CARD_ENTRY_SETTLE_MS = 1_500;

/**
 * The budget for one field of one entry attempt.
 *
 * Short on purpose: the failure this guards against detaches the fields while
 * they are being read, and the answer to that is another entry, not a longer
 * wait for a field that has already been thrown away.
 */
const CARD_ENTRY_STEP_TIMEOUT_MS = 5_000;

/**
 * The expiry field of a provider payment element, by the two accessible names
 * it is known to carry.
 *
 * The element mounts a local card form labelled `Expiration date` and then, on
 * its deferred `elements/sessions` response, re-renders the same field as
 * `Expiration (MM/YY)`. A driver bound to the first name addresses a field
 * that stops existing a beat after the form appears, which is exactly the kind
 * of transient-artifact dependency this harness has been bitten by before:
 * everything about the entry looks right until the render the assertion
 * actually needs is the one that has been replaced.
 */
export const CARD_EXPIRY_FIELD_NAME = /^Expiration\b/i;

/**
 * One field of a provider payment element, and how a read-back of it is
 * compared with what was entered.
 *
 * `digits` covers the fields the element reformats as they are typed - a card
 * number gains groups of four, an expiry gains a slash - so the comparison is
 * on the digits rather than on the rendering. `option` covers a select, whose
 * value is returned exactly as it was chosen.
 */
export interface CardEntryField {
	label: string;
	locator: Locator;
	value: string;
	kind: 'digits' | 'option';
	/** Absent on surfaces where the provider does not render this field. */
	optional?: boolean;
}

/** How a caller reports an entry that never held, in its own vocabulary. */
export type CardEntryFailure = ( message: string ) => never;

function defaultCardEntryFailure( message: string ): never {
	throw new Error( `Provider payment element ${ message }` );
}

function cardEntryDigits( value: string ): string {
	return value.replace( /\D/g, '' );
}

function cardEntryComparable( field: CardEntryField, value: string ): string {
	return field.kind === 'digits' ? cardEntryDigits( value ) : value;
}

/**
 * Types a card into a provider payment element and proves the element kept it.
 *
 * The element clears every field it holds when its deferred
 * `elements/sessions` response arrives, and that response can land after the
 * driver has already typed: the typing succeeds, the value is dropped without
 * a word, and the submission that follows is made with an empty card. What
 * that produced was not a visible input failure but a 45-second wait for a
 * success notice that could never appear, reported as an
 * `uncertain-provider-write` for a submission the provider was never even
 * asked about. So the entry is read back here and retyped until the element
 * holds exactly what was typed, which makes the gesture the case claims to
 * perform a precondition of performing it rather than a hope.
 *
 * `fail` lets a caller keep its own failure vocabulary; the default names the
 * element, which is the thing that actually misbehaved.
 */
export async function enterProviderCardEntry(
	fields: readonly CardEntryField[],
	surface: string,
	fail: CardEntryFailure = defaultCardEntryFailure
): Promise< void > {
	let outcome = '';
	for ( let attempt = 1; attempt <= CARD_ENTRY_ATTEMPTS; attempt += 1 ) {
		try {
			const entered: CardEntryField[] = [];
			for ( const field of fields ) {
				if ( field.optional && ( await field.locator.count() ) !== 1 ) {
					continue;
				}
				if ( field.kind === 'option' ) {
					await field.locator.selectOption( field.value, {
						timeout: CARD_ENTRY_STEP_TIMEOUT_MS,
					} );
				} else {
					await field.locator.fill( field.value, {
						timeout: CARD_ENTRY_STEP_TIMEOUT_MS,
					} );
				}
				entered.push( field );
			}

			// The read-back is only worth anything once the reset has had its
			// chance to happen, and it lands a beat after the element mounts
			// rather than while the driver is still typing.
			await new Promise( ( resolve ) =>
				setTimeout( resolve, CARD_ENTRY_SETTLE_MS )
			);

			const lost: string[] = [];
			for ( const field of entered ) {
				const kept = cardEntryComparable(
					field,
					await field.locator.inputValue( {
						timeout: CARD_ENTRY_STEP_TIMEOUT_MS,
					} )
				);
				if ( kept !== cardEntryComparable( field, field.value ) ) {
					lost.push( field.label );
				}
			}
			if ( lost.length === 0 ) {
				return;
			}
			outcome = `dropped ${ lost.join( ', ' ) }`;
		} catch ( error ) {
			// A reset in flight detaches the very fields being read, so an
			// unreadable field is the same event as an emptied one and is
			// retried rather than reported as an input failure.
			outcome = `could not be read back (${
				error instanceof Error
					? error.message.split( '\n' )[ 0 ]
					: error
			})`;
		}
	}

	fail(
		`kept no complete card after ${ CARD_ENTRY_ATTEMPTS } entries into the ${ surface } payment element - the last one ${ outcome } - so a submission would carry an incomplete card.`
	);
}

/**
 * The three fields every native card surface renders, entered through the
 * read-back contract above.
 *
 * Most callers want exactly this and nothing else; the surfaces that also
 * render a billing country or postcode inside the element build their own
 * field list and call `enterProviderCardEntry` directly.
 */
export async function enterProviderCardTriple(
	frame: FrameLocator,
	card: ProviderTestCard,
	surface: string,
	fail?: CardEntryFailure
): Promise< void > {
	await enterProviderCardEntry(
		[
			{
				label: 'card number',
				locator: frame.getByRole( 'textbox', { name: 'Card number' } ),
				value: card.number,
				kind: 'digits',
			},
			{
				label: 'expiry',
				locator: frame.getByRole( 'textbox', {
					name: CARD_EXPIRY_FIELD_NAME,
				} ),
				value: card.expiry,
				kind: 'digits',
			},
			{
				label: 'security code',
				locator: frame.getByRole( 'textbox', {
					name: 'Security code',
				} ),
				value: card.securityCode,
				kind: 'digits',
			},
		],
		surface,
		fail
	);
}
