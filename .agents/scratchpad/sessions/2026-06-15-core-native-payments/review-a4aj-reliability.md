---
session: 2026-06-15-core-native-payments
type: review
by: subagent:reliability-reviewer
created: 2026-06-20 07:17
tool: pirategoat-tools:reliability-reviewer
target: A4aj native WooPayments express checkout settings preview
reconciles:
  - data/a4aj-admin-browser-gate.json
  - data/a4aj-express-preview-browser.json
status: draft
last_updated: 2026-06-20 07:20
---

# A4aj Reliability Review

Scope: read-only reliability/failure-mode review of the current uncommitted A4aj slice on `exp/core-native-payments`, focused on the native WooPayments Express Checkout settings preview and its Stripe.js loader behavior, stale async cleanup, script injection duplication, fallback correctness, fail-closed payload handling, browser/runtime warnings, and test coverage realism.

Files reviewed: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/style.scss`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`, plus adjacent uncommitted wiring in `appearance-settings.tsx`, `notices.tsx`, and `WooPaymentsSettingsServiceTest.php`.

Evidence reviewed: `data/a4aj-admin-browser-gate.json` passed desktop and mobile for `settings-express-payment-request` with no missing tokens, failed responses, console issues, or page errors; `data/a4aj-express-preview-browser.json` showed the intended HTTP fallback preview text on desktop and mobile, with only the unrelated permissions-policy `unload` console issue noted in the prompt.

## Findings

### Medium: Stripe.js retry reuses a failed script node and can leave the preview blank

If `https://js.stripe.com/v3/` fails to load once, `loadStripeFactory()` resolves `undefined` and leaves the failed `<script id="woopayments-settings-stripe-js">` in the document. A later preview mount enters the existing-script path at `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx:94` through `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx:107`, attaches fresh `load`/`error` listeners to that already-failed element, and waits for events the browser will not emit again. The user then gets neither the deterministic fallback notice nor the failed-preview notice because `hasPreviewError` stays false while the promise remains pending, so the preview area can sit blank until a full page reload recreates the DOM.

The new retry test masks this production behavior: `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx:588` through `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx:625` dispatches `load` on the same `failedStripeScript` after the error, but a real failed script element does not later load unless the code removes/replaces it or starts a new request. This means the test proves the module-level promise can be reset, but not that the browser retry path can recover.

Recommendation: On script `error`, remove or mark the failed script before resolving unavailable, and on the next attempt create a fresh script element/request when `window.Stripe` is still absent. Add a bounded timeout for the pending script load so a stalled external request also resolves to the visible failed-preview state. Update the retry test to assert that a second attempt creates/replaces the script node after an error and that a second failure still surfaces the error notice instead of leaving the preview pending.

Confidence: 0.9. Category: `silent-failure`.

## Non-findings and Residual Risks

The settings payload is additive and uses the account service's cached publishable key and account ID; the TS adapter treats missing or malformed preview config as non-previewable and falls back without initializing Stripe. I did not find a fail-open path for incomplete preview payloads in the reviewed changed lines.

Concurrent mounts share the module-level loader promise and the component guards stale async completion with `isCurrent`, so I did not flag a stale set-state issue. Event handler cleanup is mostly bounded by `isCurrent` plus `expressElement.unmount()` on effect cleanup; a minor resource-lifetime risk remains if Stripe emits `ready` with no wallets or `loaderror` after mount, because the component swaps the container for an error notice without explicitly unmounting the Stripe Element at that moment, but I did not see enough blast radius to report it as a finding.

The browser proof covers the HTTP deterministic fallback path only. The HTTPS live Stripe Element path remains unit-test-mocked, so after fixing the retry behavior the next useful verification is an HTTPS browser proof or a browser-level mocked Stripe.js load/error scenario that exercises actual script element behavior.
