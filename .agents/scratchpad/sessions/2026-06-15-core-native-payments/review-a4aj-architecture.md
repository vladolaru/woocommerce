---
session: 2026-06-15-core-native-payments
type: review
by: "subagent:architecture-reviewer"
created: 2026-06-20 07:17
tool: pirategoat-tools:architecture-reviewer
target: A4aj native WooPayments Express Checkout settings preview
reconciles:
  - analysis-a4aj-express-checkout-preview-parity.md
status: draft
last_updated: 2026-06-20 07:20
---

# A4aj Architecture Review

## Scope

Reviewed the current uncommitted A4aj slice on `exp/core-native-payments`, limited to the user-requested files: `WooPaymentsSettingsService.php`, `WooPaymentsSettingsServiceTest.php`, `express-checkout-preview.tsx`, `appearance-settings.tsx`, `notices.tsx`, `style.scss`, and `express-checkout-settings.test.tsx`. I also read the A4aj analysis and browser evidence, traced the settings save path, compared the preview Stripe payload with existing native checkout/ECE payloads, and checked the reference WooPayments preview behavior in the local `woocommerce-payments` clone.

## Finding

### Medium: Stripe.js retry can hang the settings preview after one transient script failure

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx:94`

Problem: `loadStripeFactory()` looks up an existing script by `STRIPE_SCRIPT_ID` and, when it finds one, only attaches fresh `load`/`error` listeners to that existing element. On the first network/CSP/ad-block failure, `resolveUnavailable()` clears `stripeScriptPromise` but leaves the failed `<script id="woopayments-settings-stripe-js">` in `document.head`. A later route remount or retry then finds that already-failed element, adds listeners at lines 106-107, and waits for load/error events that the browser will not replay. The new promise can remain pending forever, leaving `AppleGooglePayPreview` rendered as an empty min-height container instead of the fail-closed notice. The new Jest retry test at `express-checkout-settings.test.tsx:621` manually dispatches a later `load` event on the same failed script element, so it does not cover the browser lifecycle that will occur after an actual failed script request.

Impact: This is not a checkout money-path issue, but it weakens the lazy route-local Stripe adapter. A transient Stripe.js load failure on the Apple Pay / Google Pay settings route can make the preview unrecoverable within the current admin SPA session until a full page reload, and future settings-local Stripe consumers would inherit the same stuck global script state if they reuse this loader.

Pattern/Fix: Treat the script element as a tiny route-local loader state machine. Mark successful/failed state on the element or keep module-level state, remove the failed script before resolving `undefined`, and create a fresh script on the next attempt. Update the retry test to assert a new script element is appended after an error rather than dispatching a synthetic load on the failed element. Estimated effort: 0.5-1 hour.

Confidence: 0.88.

## Positive Architecture Notes

- The new settings payload exposes only public Stripe preview config under `express_checkout_preview.stripe` (`publishableKey`, `accountId`, `locale`) and sources it from `WooPaymentsAccountService`, matching the existing native checkout and express checkout payload shape. I did not find unnecessary Stripe Billing, WooPay session, or raw account-data coupling in this slice.
- The live preview adapter stays settings-local and does not add `@stripe/react-stripe-js` or `@stripe/stripe-js` to the admin bundle. Stripe.js is only attempted on HTTPS with a publishable key, and the local HTTP browser proof showed only the express checkout settings chunks loaded for the target route.
- The settings save contract remains tolerant of the new read-only payload: the frontend posts the full settings state as before, and `WooPaymentsSettingsService::update_settings()` persists only mapped/allowlisted keys before returning a fresh server projection.

## Residual Risks

- The browser proof exercised the deterministic HTTP fallback, not a real HTTPS wallet-available Apple Pay / Google Pay render. Source and Jest coverage cover the Stripe options, but a live wallet preview remains an environment-dependent risk.
- The preview remains hard-coded to WooPay plus Apple Pay / Google Pay. That matches the current route behavior, but a future express method with a real preview will need a small preview registry or method-capability shape instead of adding more booleans inside `ExpressCheckoutPreview`.
