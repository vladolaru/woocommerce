---
session: 2026-06-22-woopayments-core-merge-parity
type: report
by: claude
created: 2026-06-22 23:30
target: exp/core-native-payments — regression-vs-parity vs WooPayments client v10.8.0
reconciles:
  - data/parity-A-webhooks.md
  - data/parity-B-payment-core.md
  - data/parity-C-woopay-session.md
  - data/parity-D-blocks-xss.md
  - data/parity-E-api-client.md
  - data/parity-F1-rest-account-capital.md
  - data/parity-F2-rest-mobile-file.md
  - data/parity-G-settings-customer.md
  - data/parity-H-mc-core.md
  - data/parity-I-mc-compat.md
  - data/parity-J-bc.md
  - data/parity-K-a11y.md
  - data/parity-L-tests.md
status: final
---

# WooPayments core-merge: regression vs. parity

Oracle: WooPayments client **v10.8.0** (`develop`) at `~/Work/a8c/woocommerce-payments`. Each of the 45 review findings was compared against the matching client code path by a dedicated subagent reading both sides.

## Headline

**None of the 4 "criticals" is a regression.** Two are core *improving on* the client; two are faithfully-ported pre-existing issues (hardening opportunities, not merge defects). The genuine regressions cluster in the High/Medium tier, and there is **one hard backward-compatibility break**.

- **6 regressions** (core behaves worse than the shipping client) — must fix for a regression-free merge.
- **1 hard BC break** — the client itself depends on a removed public surface.
- **3 "do better than the client"** items where core faithfully ported a real (often critical-severity) flaw.
- **5 review findings to correct** — the client comparison shows they are non-issues, by-design parity, or mis-scoped.
- The remaining ~30 are **parity** (pre-existing in the client) or **new-in-core where core is equal-or-better**.

---

## A. Regressions — core is worse than the client (FIX THESE)

| id | sev | finding | client does it right | fix |
|----|-----|---------|----------------------|-----|
| **bfcb6ce5** | high | `apply_checkout_outcome()` throwing after a successful charge leaves the order permanently `pending`, no note/log/ref | Client persists the intent/charge reference **before** the status flip and has an explicit catch that converts any post-charge exception into a logged soft-success preserving order status (`class-wc-payment-gateway-wcpay.php:1266-1294`, `class-wc-payments-order-service.php:1230-1249`) | Wrap `apply_checkout_outcome()`; persist the payment reference before re-throw; log at error; mirror the client's soft-success-on-post-charge-failure |
| **0b11bf8a** | med→**high** | Webhook-supplied dispute `status` + URL `sprintf`'d into HTML order notes unescaped | Client wraps every dispute note in `esc_interpolated_html()` which `esc_html()`s the webhook values (`class-wc-payments-order-service.php:2296`, `Utils.php:67`) | Restore escaping (`esc_html`/`esc_url`, or port `esc_interpolated_html`). **Also a BC/dedup risk:** differing note strings break the exact-match `order_note_exists()` dedupe → duplicate notes on client→core migration |
| **147606e9** | med | `wcpay_list_transactions_request` (and the sibling `…_reports_request`) filter now passes a `get_params()`-only value object | Client fires the filter with a full `Request` object that has `final public function send()` (`includes/core/server/class-request.php:336`) | Restore a `send()`-capable contract for the filtered object, or `_deprecated_*` the old shape. Extensions calling `$request->send()` inside the filter **fatal** today |
| **deb2d8c9** | med | Account/business fields written into the local `wcpay` settings option from raw request params, and the client's per-field `validate_callback`s (descriptor/address/email/phone) dropped | Client never persists raw params locally — it round-trips to the server (`class-wc-rest-payments-settings-controller.php:933`) and enforces per-field validators at the REST boundary | Restore per-field `validate_callback`s; don't persist unsanitized params into the local option |
| **5076c7dc** | med | WooPay `get_session()` catches `Throwable` and returns `WP_Error` with **no log** | Client logs the failure reason (`class-wc-rest-woopay-session-controller.php:69`, `Logger::log`) | Add `wc_get_logger()->error()` in the catch; keep the generic public message |
| **d3111113** | low | `REPORTING_API` constant dropped from the API client but its docblock left orphaned | Client has `const REPORTING_API = 'reporting'` (`class-wc-payments-api-client.php:58`) | Restore the constant or remove the dangling docblock |

---

## B. Hard backward-compatibility break (FIX)

| id | sev | finding | evidence | fix |
|----|-----|---------|----------|-----|
| **c49baad5** | high | The branch **deletes** the `Tasks/WooCommercePayments.php` onboarding task and its two documented public filters (`woocommerce_admin_woopayments_onboarding_task_badge` @since 8.2.0, `…_additional_data` @since 9.4.0) with no `_deprecated_hook()` and no replacement `apply_filters()` | **The shipping client hooks BOTH** in `WC_Payments_Incentives_Service::init_hooks()` (`class-wc-payments-incentives-service.php:80-81`), unconditionally, and asserts it in its own tests | Re-fire equivalent `apply_filters()` from the native incentive surface, or at minimum `_deprecated_hook()` both names. A changelog line is not a runtime deprecation |

---

## C. "Do better than the client" — faithfully-ported real flaws (PARITY, not regressions)

These are **not** merge regressions (the client has the identical flaw), but several are high/critical severity and core can beat the oracle. Note the BC nuance on the filter ones.

| id | sev | finding | client status | recommendation |
|----|-----|---------|---------------|----------------|
| **c17bc8f3** | crit | `wcpay_woopay_is_signed_with_blog_token` filter lets any plugin bypass WooPay session auth | **PARITY** — filter exists in client since 5.9.0 (`class-wc-rest-woopay-session-controller.php:97`, `woopay/class-woopay-session.php:864`) | Harden **strengthen-only** (`real_check() && (bool) apply_filters(...)`) so existing hooks keep working — preserves BC while closing the bypass. Core can lead; the client can follow |
| **195a39ae** | crit | `testingInstructions` → `dangerouslySetInnerHTML`; escaping happens before the `wcpay_payment_fields_js_config` filter | **PARITY** — client blocks path identical (`client/checkout/blocks/payment-processor.js:263`; escape-before-filter at `class-wc-payments-checkout.php:588` vs filter at :283) | Apply `wp_kses_post()` to the value **after** the filter (or sanitize in the blocks render). Test-mode only, but criticals deserve the belt-and-suspenders |
| **47644832** | med | SetupIntent attached without verifying `intent.customer` == user's Stripe customer | **PARITY** — client `add_payment_method()` (`class-wc-payment-gateway-wcpay.php:4266`) also checks only `status==succeeded`; both site-scoped (same-store, not cross-account) | Add the ownership check — a clean win over the client |
| **1062b791 / f42b6e68 / f35bc174 / e0e84777** | high | MC perf: uncached `has_multi_currency_orders` SELECT, N+1 per-currency `get_option`, `debug_backtrace()` per price filter, uncached cart-subscription-type | **PARITY** — all inherited verbatim from `includes/multi-currency/*`; core already marginally improves two of them | Optional perf pass; each is a measurable win over the client on multi-currency stores |

---

## D. Corrections to the original review (client comparison changes the verdict)

| id | original | corrected verdict | why |
|----|----------|-------------------|-----|
| **53de2d4b** | critical regression (webhook TOCTOU) | **Core IMPROVES on client** — harden, don't block | Client has **zero** inbound webhook idempotency (controller calls `process()` directly). Core's processed-event marker is net-new and strictly better; the TOCTOU window is real but narrower than stated and partly covered by per-side-effect idempotency. Still worth a real `wp_cache_add()` lock, but it is not a regression |
| **7c43c8da** | critical (concurrent refund dropped) | **NEW-IN-CORE, keep the design** | Client has no refund lock and uses random idempotency keys, so it never serializes refunds at all. Core's lock at worst transiently rejects a concurrent duplicate with a retriable `WP_Error` — never lost money. Stricter than the client |
| **048c849b** | medium (unauthenticated file endpoint) | **By-design parity — downgrade/close** | Client's file GET route uses `permission_callback => []` (functionally `__return_true`); both gate **inside the handler** to a `business_logo`/`business_icon` purpose allowlist with a purpose cache. Core copied this byte-for-byte. Not unauthenticated disclosure |
| **339eeb0c** | high (unguarded `$response['data']` fatal) | **Effectively a non-issue** | The sole supplier (`WooPaymentsFailedEventsProvider`) normalizes `data` to an array on every path; the fatal can't fire through the wiring. A cosmetic `?? []` would make it self-evident. (Client uses inline `?? []`) |
| **106f2823** | medium (accept-dispute modal focus) | **Likely not a bug (parity)** | Both client and core use WP `<Modal>` whose `useFocusReturn()` restores focus on dismiss. Core's extra focus machinery fires only on successful *accept*, deliberately moving focus to the resolved heading — intentional, arguably improved |
| **7c7309ac** | low (assertTrue(true) placeholder) | **Stale ref; NEW-IN-CORE** | The cited line is fully asserted; the real `assertTrue(true)` calls are benign route-assertion helpers paired with `$this->fail()`. Cutover is core-only with a substantive test suite |

---

## E. Parity / new-in-core, low priority (no merge regression)

Pre-existing in the client and faithfully ported (fix opportunistically, on their own merit, not as regressions): `0175d50d` (disputes_summary `0[match]`), `6022b9aa` (hyphen regex), `54b15aba` (250 µs backoff — identical), `6ea1b4a8` `24a6f21d` `3eece18f` `4be7ee7c` (account/capital REST), `1a302332` `8965abd1` `8ad0a91a` (mobile/timezone REST), `6a3e12e5` (WooPay message sanitize), `61a68295` (duplicate customer), `7522b93d` (nonce-less referrer Tracks), `b6ab58da` (analytics esc_sql), `23405cc0` `11adbec5` `3593fad3` `5bd7b33b` (test gaps shared with client). `a9e93194` (settings save) is parity where **core already improved** (single batched write). New-in-core, equal-or-better or minor: `bd8596d0` (VAT-in-path is core-only; add `rawurlencode`), a11y nits where core hand-rolled instead of reusing client/shared components — `90565c18` `99309eb5` `bccc465d` `901cb561` (core-only, worth fixing to "do better"). `9d771cef` is not a BC risk (section slug value matches the client's `woocommerce_payments`).

---

## Recommended action order

1. **Regression-free gate (Section A + B):** `bfcb6ce5`, `0b11bf8a`, `147606e9`, `deb2d8c9`, `5076c7dc`, `d3111113`, and the BC break `c49baad5`. These are where core is worse than, or breaks, the shipping client.
2. **Beat the client (Section C):** harden `c17bc8f3` (strengthen-only, BC-safe) and `195a39ae`; add the `47644832` ownership check; take the MC perf wins.
3. **Correct the review record (Section D):** re-rate the two non-regression "criticals", close `048c849b`, downgrade `339eeb0c`/`106f2823`/`7c7309ac`.
4. **Opportunistic (Section E):** shared client bugs — fix in both or note as known parity.
