# Native WooPayments — follow-up obligations

One entry per outstanding obligation for the native WooPayments implementation. Entries are appended when work is deliberately deferred, and removed (with a pointer to the resolving change) when done. This file is the durable home for implementation follow-ups that would otherwise live only in session scratchpads; harness-scoped coverage gaps stay in `tools/woopayments-merge/HARNESS.md` §3 and harness decisions in `tests/e2e/tests/woopayments-native/DECISIONS.md`.

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

### WooPay email-input/OTP and direct-checkout front ends not ported (S7, D3)

The checkout config publishes `isWooPayEmailInputEnabled` and `isWooPayDirectCheckoutEnabled` as `false` because neither flow has a native JS consumer (no OTP iframe, no `handleWooPayEmailInput`, no `encryptedData` producer). The email-input/OTP port is a scheduled follow-up slice of the parity programme; it re-enables the flag, ports the plugin's `wcpay_is_woopay_email_input_enabled` filter, and adds the `encrypted_data` branch to the session email fallback chain. The direct-checkout front end has no scheduled port; if it lands, flip its flag in `WooPaymentsWooPaySessionService::get_woopay_frontend_config()`.

### wcpay_woopay_is_signed_with_blog_token stays strengthen-only (S7, finding 12 — deliberate divergence)

The plugin's filter can grant access to WooPay session callbacks on its own (`return apply_filters( ..., Rest_Authentication::is_signed_with_blog_token() )`); native applies it strengthen-only — it can restrict but never grant when the request is unsigned. This is deliberate hardening: the only grant-mode consumers found are the WCPay dev-tools plugin and the plugin's own unit tests. Cost: local/proxied WooPay dev setups that rely on the grant behavior (e.g. dev-tools `mock_rest_authentication_is_signed_with_blog_token`) do not work against a native store; anyone needing local WooPay e2e against native must drive the real Jetpack signature state instead. Revisit only if a production consumer of the grant behavior surfaces.

### Verified-email restore drain across runtime cutover (S7, finding 9 rider)

A verified-email WooPay order detaches its customer id and schedules `woopay_restore_order_customer_id` ten minutes out. Both the native runtime and the plugin register a handler for that hook, so pending restores drain under either owner — but if the native runtime stops registering (arbiter hands ownership back) while the plugin is absent, scheduled events fire with no handler and the order keeps `customer_id 0` with the real id parked in `woopay_merchant_customer_id` meta. The plugin drains pending schedules at deactivation; native has no deactivation moment. If runtime cutover tooling gains a disable path, drain or re-run these events there.

### Go-live nudge surface delta: inbox note vs settings-page notice (S8, finding 14 / D4)

The plugin renders its test-to-live nudge as a React notice on the payment settings pages (attach-rate framework) whose CTA one-click flips `test_mode` off when a live account is connected; native has no port of that framework, so the nudge ships as the WC Admin inbox note `wc-payments-notes-test-to-live` whose CTA links to the payment settings instead. The bookkeeping (enable-date option, eligibility transients) is a verbatim port and stands regardless of surface. Revisit the surface only if product judges the placement or the one-click CTA material — escalate rather than porting the attach-rate notice framework.

### Native settings-response Multi-Currency flag default differs from the plugin's feature default (S8, Q6 observation)

The plugin's `WC_Payments_Features::is_customer_multi_currency_enabled()` reads `_wcpay_feature_customer_multi_currency` with default `'1'` (enabled); the native settings response reads the same option with default `'0'` (`WooPaymentsSettingsService::get_feature_flags()`). The S8 store-setup snapshot and MC auto-add guard use the plugin default for wire parity, so the divergence is now confined to the settings-response projection. Align (or justify) the settings-response default in a future settings-contract pass.

### Oracle upstream: /settings business_support_address save path fatals (S8 measurement observation)

The plugin's own `POST /wc/v3/payments/settings` with an `account_business_support_address` object dies with a `TypeError`: `Update_Account::set_business_support_address()` type-hints `string` while the REST boundary validates an array. Measured live on the pristine `:8082` oracle during S8. Native sends the nested object, which is what the platform maps onto Stripe's `business_profile.support_address` dict, so native is correct; this is a candidate upstream report against the WooPayments plugin, not a native change.
