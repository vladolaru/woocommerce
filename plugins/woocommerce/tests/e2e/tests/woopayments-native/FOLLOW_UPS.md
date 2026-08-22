# Native WooPayments — follow-up obligations

One entry per outstanding obligation for the native WooPayments implementation. Entries are appended when work is deliberately deferred, and removed (with a pointer to the resolving change) when done. This file is the durable home for follow-ups that would otherwise live only in session scratchpads; harness-scoped coverage gaps stay in `tools/woopayments-merge/HARNESS.md` §3.

## Recurring

### Client-version bump review (D2)

`WooPaymentsClientVersion::VERSION` declares the WooPayments plugin release whose platform behavior the native runtime was verified against (currently `10.8.0`). The platform gates payment-method availability (`minimum_client_version`), account-status mapping, and response shapes on the version each request reports, so the constant must move deliberately, on a cadence:

1. For each WooPayments release above the current constant, review every platform-side `is_client_version_at_least()` gate and `minimum_client_version` entry between the two versions.
2. Port the behavior each gate unlocks into the native runtime.
3. Bump the constant and its pinned-value test in one reviewed change.

Never bumping silently costs native stores new payment methods and response improvements; bumping without the review serves the native runtime responses it does not understand. Owner: whoever ships the native runtime release. First review due: first WooPayments release after the parity-fixes branch merges.

## Deferred mechanisms (from the 2026-08-20 mechanism-parity review programme)

### Pay-for-order express wallet contact backfill (S5, L-2)

The plugin's classic pay-for-order express flow backfills the wallet-provided email/phone onto the order (plugin `client/checkout/express-checkout/order-api.js:56-75`). The native classic pay-for-order express branch never ported that backfill, so a wallet payer's contact details do not reach the order. Pre-existing before S5; not a regression.

### Cannot-combine-currencies typed error (observed during S6)

The plugin parses the platform's "You cannot combine currencies on a single customer." `invalid_request_error` into `Cannot_Combine_Currencies_Exception` carrying the offending currency, which multi-currency checkout consumes for a specific shopper message. Native throws the generic envelope exception. Multi-currency-adjacent; not among the review's 131 findings.

### Typeless-error shopper copy is stricter than the plugin (observed during S6)

For platform errors with an empty `error.type` (for example `wcpay_blocked_by_fraud_rule`, top-level platform codes) the plugin shows the raw platform message to the shopper; native's `WooPaymentsErrorMessages` redacts everything that is not a `card_error` to the generic message. Kept deliberately — native's redaction is the safer behavior — but it is a known bytes divergence from the plugin. Revisit only if shopper-copy parity for these paths becomes a requirement.
