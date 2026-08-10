/**
 * Provider test cards, named by the provider behaviour they select.
 *
 * These are the numbers the provider documents for its sandbox; they select
 * an outcome at the provider rather than exercising anything in Core. A card
 * used to exercise client-side field validation - a Luhn-failing number, a
 * past expiry, a truncated security code - is an input to the form and not a
 * provider behaviour, so those stay local to the test that reasons about them.
 *
 * Expiry and security codes carry no meaning to the provider beyond being
 * well-formed and in the future. They match the WooPayments extension suite's
 * fixtures so a native run and an extension run can be compared field by field.
 *
 * The basic `4242` card is deliberately absent. It is still written inline in
 * `drivers/checkout.ts`, `drivers/classic-card-checkout.ts` and
 * `provider-card-evidence.ts`, and it has to stay there for now: those files
 * sit inside the source bundles of already-closed contracts, whose evidence
 * records a SHA-256 over the bundle and binds each reviewer verdict to that
 * same hash. Adding an import changes the bundle, so a purely cosmetic
 * de-duplication would invalidate closure evidence, and rewriting the hash to
 * match would assert that reviewers approved bytes they never read. Fold that
 * card in here when one of those files is next re-attested for a real reason.
 */

export interface ProviderTestCard {
	/** Provider test number. */
	number: string;
	/** Four digits, MMYY, matching how the checkout drivers fill the field. */
	expiry: string;
	securityCode: string;
}

/**
 * Requires a 3D Secure 2 challenge, which the provider presents as a mock
 * page offering completion or cancellation. This is the card the provider
 * documents for triggering a challenge in a sandbox.
 */
export const THREE_DS_2_CARD: ProviderTestCard = {
	number: '4000000000003220',
	expiry: '0445',
	securityCode: '626',
};

/**
 * Requires authentication and presents a one-time-passcode challenge.
 */
export const THREE_DS_OTP_CARD: ProviderTestCard = {
	number: '4000002500003155',
	expiry: '0445',
	securityCode: '626',
};

/**
 * Presents a challenge and then declines after it is completed, so an
 * authenticated payment can still fail.
 */
export const THREE_DS_DECLINED_CARD: ProviderTestCard = {
	number: '4000008400001629',
	expiry: '0645',
	securityCode: '626',
};

/**
 * Payment method the provider resolves to a card that always requires
 * authentication, for confirming a PaymentIntent server-side with no browser.
 * Used for the off-session shape, where there is no shopper present to answer
 * a challenge and the intent must surface `requires_action` instead.
 */
export const AUTHENTICATION_REQUIRED_PAYMENT_METHOD =
	'pm_card_authenticationRequired';
