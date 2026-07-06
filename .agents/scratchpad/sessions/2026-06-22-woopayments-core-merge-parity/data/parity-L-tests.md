---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-L
created: 2026-06-22 22:55
target: test quality
last_updated: 2026-06-22 23:00
status: final
---

# Parity Batch L — Test Quality (REGRESSION vs PARITY)

Five test-quality findings from the WooPayments → WooCommerce-core merge, each classified by comparing the core port against the WooPayments CLIENT oracle (`woocommerce-payments` v10.8.0, `develop` @ `a82cbee4`).

Classifications: **REGRESSION** (client tests it properly, core weakened it) / **PARITY** (client has the same gap) / **NEW-IN-CORE** (no client equivalent) / **IMPROVED** (core is better than client).

Repos:
- CORE: `/Users/vladolaru/Work/a8c/woocommerce-develop-2`
- CLIENT: `/Users/vladolaru/Work/a8c/woocommerce-payments` (v10.8.0, `develop`)

---

## 1. 23405cc0 (MED) — Unawaited `userEvent` in VAT-flow tests

**Core finding:** `client/admin/client/woopayments/admin/test/documents-page.test.tsx` fires `userEvent.click` / `userEvent.type` / `userEvent.clear` without `await` (and without a `userEvent.setup()` user instance) throughout the VAT-flow tests (e.g. lines 246, 287, 314, 327–334, 344, 371–391, 424–439, 468). It relies on `await screen.findBy…` / `await waitFor(…)` afterward to flush the microtask queue rather than awaiting the interactions themselves.

**Verdict: PARITY (leaning IMPROVED on flow coverage, PARITY on the await idiom).**

The core port and the client use *different but equally imperfect* idioms, and neither is the "correct" `await user.click()` discipline the finding implies as the baseline:

- **Client `client/vat/form/__tests__/index.test.tsx`** — wraps every interaction in `await act( async () => { await user.click(…) } )`. It *does* await the user calls, but only because they are nested inside an explicit `act()` wrapper (lines 73–79, 156–175, 261–263, 281–291, 303–305, 332–333, 403–405, 443–445, 468–470, 494–495, 524–525). This is the older RTL pattern; the manual `act()` wrapper is itself a smell that newer RTL guidance discourages.
- **Client `client/documents/list/__tests__/index.test.tsx`** — `await user.click(...)` properly awaited (line ~115, `sortBy` helper). Clean.
- **Client `client/vat/form-modal/__tests__/index.test.tsx`** — does NOT exercise interactions at all; `VatForm` is fully mocked (`jest.mock('../../form', () => jest.fn())`), so it only asserts render + snapshot (3 trivial tests). No userEvent at all.

So the *closest* client VAT-modal test (`form-modal`) is far weaker than core's `documents-page.test.tsx` (which drives validate → save → retry-download end to end). The client only achieves comparable flow depth in `vat/form` and only by awaiting inside `act()`. The core test's unawaited-userEvent idiom is a genuine quality nit, but it is not a regression of client coverage — core actually tests *more* of the VAT-download-retry flow than any single client test does.

- **Client file:line:** `woocommerce-payments/client/vat/form/__tests__/index.test.tsx:73` (awaited-inside-act idiom); `woocommerce-payments/client/vat/form-modal/__tests__/index.test.tsx:15` (shallow render-only modal test).
- **Confidence: HIGH** that this is not a coverage regression. MEDIUM on the exact label (PARITY vs IMPROVED) because core's flow coverage exceeds the client's nearest equivalent while sharing the await-idiom weakness.

---

## 2. 11adbec5 (MED) — ~40+ data hooks fully stubbed in settings-page test

**Core finding:** `client/admin/client/woopayments/settings/test/settings-page.test.tsx` declares ~50 `mockUse*` data-hook stubs (lines ~140–215: `mockUseSettings`, `mockUseGetSettings`, `mockUseSavedCards`, … `mockUseLinkEnabledSettings`, `mockUseWooPayShowIncompatibilityNotice`), `jest.fn()` count = 85, and mocks `@wordpress/api-fetch`, `@wordpress/a11y`, `@woocommerce/tracks`, `@wordpress/data`. The whole data layer is stubbed; nothing integrates with the real settings store.

**Verdict: PARITY.**

The client does exactly the same thing — it `jest.mock`s the entire `wcpay/data/settings` hook surface and returns `jest.fn()` per hook, with zero integration against the real `@wordpress/data` store. Confirmed in `client/settings/express-checkout-settings/__tests__/woopay-settings.test.js:25`:

```js
jest.mock( 'wcpay/data/settings', () => ( {
    useEnabledPaymentMethodIds: jest.fn(),
    useWooPayEnabledSettings: jest.fn(),
    useWooPayCustomMessage: jest.fn(),
    useWooPayStoreLogo: jest.fn(),
    usePaymentRequestButtonType: jest.fn(),
    ...
} ) );
jest.mock( '@wordpress/data', () => ( {
    useDispatch: jest.fn( () => ( { createErrorNotice: jest.fn() } ) ),
} ) );
```

The only structural difference is decomposition: the client splits the settings UI into many small per-component test files (`woopay-settings.test.js`, `express-checkout-settings-notices.test.js`, `file-upload.test.js`, `settings-manager/__tests__/index.test.js`, …), each stubbing only the hooks that component consumes. Core consolidated the same surface into one monolithic `settings-page.test.tsx`, so the stub count *looks* larger in one file, but the testing philosophy (mock the entire data layer, never integrate) is identical. The client's own `settings-manager/__tests__/index.test.js` is even shallower — 2 render-only assertions, `global.wcpaySettings = {}`, no data assertions.

- **Client file:line:** `woocommerce-payments/client/settings/express-checkout-settings/__tests__/woopay-settings.test.js:25` (full `wcpay/data/settings` stub); `woocommerce-payments/client/settings/settings-manager/__tests__/index.test.js:1` (render-only shallow test).
- **Confidence: HIGH.**

---

## 3. 3593fad3 (MED) — `setUp`/`tearDown` instead of `set_up`/`tear_down`

**Core finding:** `tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyRequestContextTest.php:31,41` overrides `public function setUp(): void` / `public function tearDown(): void` (camelCase PHPUnit names) rather than the WooCommerce/WP `set_up()` / `tear_down()` snake_case wrappers that `WC_Unit_Test_Case` provides.

**Verdict: PARITY.**

The WooPayments client uses raw `setUp()` / `tearDown()` everywhere — it does not use WordPress's `set_up()` / `tear_down()` convention at all. Its base classes (`WCPAY_UnitTestCase`, and the wp tests `WP_UnitTestCase` it extends) and every MC test override camelCase `setUp`/`tearDown`. Confirmed across the MultiCurrency client test suite (`woocommerce-payments/includes/multi-currency/` tests and `tests/unit/multi-currency/`), all of which use `public function set_up()`-free camelCase. Core's port preserved the client's camelCase idiom verbatim, so there is no regression of a client convention — the client never followed the WC core snake_case wrapper.

Note: this is still a real *core-convention* nit. Core WC tests are expected to use `set_up()`/`tear_down()` (the WP test framework's snake_case forwarders) for consistent fixture ordering, and the sibling core test files in the same merge do mix both. But measured against the oracle, it is PARITY — the client wrote it the same way.

- **Client file:line:** WooPayments client MC tests universally use camelCase `setUp`/`tearDown` (e.g. `woocommerce-payments/tests/unit/multi-currency/test-class-multi-currency.php` and peers). No `set_up`/`tear_down` usage exists in the client to regress from.
- **Confidence: HIGH** on PARITY-vs-oracle; the snake_case expectation is a core-house-style concern, not a client-coverage regression.

---

## 4. 5bd7b33b (MED) — No nonce-rejection test for `update_order_status` AJAX endpoint

**Core finding:** `tests/php/.../WooPaymentsCheckoutAjaxControllerTest.php` has 13 `wp_create_nonce('wcpay_update_order_status_nonce')` calls (lines 121, 217, 304, 350, 400, 460, 515, 571, 625, 709, 805, 888, 987) — every test feeds a *valid* nonce. No test exercises the rejection branch in `WooPaymentsCheckoutAjaxController::get_update_order_status_response()` (`src/.../WooPaymentsCheckoutAjaxController.php:187-189`, returns 403 `is_nonce_valid` false). The 403 assertion at test line 410 is the *cross-customer* guard (`current_user_can_act_on_order`), not the nonce guard.

**Verdict: PARITY.**

The client's equivalent handler is `WC_Payment_Gateway_WCPay::update_order_status()` (`includes/class-wc-payment-gateway-wcpay.php:3998`), which performs nonce verification via `check_ajax_referer( 'wcpay_update_order_status_nonce', false, false )` at line 4002 and throws `Process_Payment_Exception('invalid_referrer')` on failure (lines 4003-4008). The client's 7 `update_order_status` unit tests (`tests/unit/test-class-wc-payment-gateway-wcpay.php`, methods at lines 5258, 5316, 5381, 5445, 5519, 5588, 5665) ALL set a single valid nonce via `$_REQUEST['_wpnonce'] = wp_create_nonce('wcpay_update_order_status_nonce')` (e.g. lines 5277/5285, 5332/5340, …). I searched the entire client unit suite for an invalid/empty/wrong nonce on this endpoint and for any assertion on the `invalid_referrer` rejection path — there is none. The fraud-token `invalid_referrer` assertions at client lines 3931/3958 are in `process_payment`, a different endpoint.

So both the core port and the client oracle exercise only the happy-path nonce and never assert the rejection branch. Same gap on both sides → PARITY. (This is a real, shared coverage hole worth closing in core, since the nonce guard is security-load-bearing and untested — but it is not a regression introduced by the merge.)

- **Client file:line:** `woocommerce-payments/tests/unit/test-class-wc-payment-gateway-wcpay.php:5258` (first of 7 `update_order_status` tests, all valid-nonce); handler under test at `woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php:4002` (the untested `check_ajax_referer` guard).
- **Confidence: HIGH.**

---

## 5. 7c7309ac (LOW) — `assertTrue(true)` placeholder in cutover-guard tests

**Core finding (as written):** `tests/php/src/Internal/Payments/OrderPaymentLifecycleServiceTest.php:46` — `assertTrue(true)` placeholder in cutover-guard tests.

**Verdict: NEW-IN-CORE (with a stale file:line reference).**

Two corrections to the finding, then the verdict:

1. **The referenced line is wrong.** `OrderPaymentLifecycleServiceTest.php:46` is `test_completed_event_marks_order_paid_and_preserves_meta()`, a fully assertion-rich test (asserts status, transaction id, three meta keys, order note). The whole file (422 lines, 12 test methods) contains zero `assertTrue(true)` and zero `markTestIncomplete`/`markTestSkipped`. There is no placeholder there.

2. **The actual `assertTrue(true)` instances in the WooPayments/native context** are in two files: `WooPaymentsWooPaySessionControllerTest.php:593` and `WooPaymentsDisputeReadinessRestControllerTest.php:319`. Both are NOT lazy placeholders — they are the success branch of a custom `assertRouteHasMethod()` helper that calls `$this->fail("Route did not register {$method}.")` when the route is missing. The `assertTrue(true)` simply records a passing assertion when the loop finds the method. This is a benign (if slightly awkward) idiom, not an empty cutover stub.

The cutover concept itself is **entirely NEW-IN-CORE**: the client has zero `cutover`/`Cutover` symbols anywhere in `includes/`, `src/`, or `tests/`, and no `NativePaymentsRuntimeArbiter` / `should_native_register` runtime-ownership machinery. Cutover (soft/mandatory native takeover, preflight guards, fee remediation during cutover) is a core-merge-only construct, with its own dedicated core tests (`WooPaymentsCutoverControllerTest.php` — well-populated, 20+ real assertion methods like `test_disable_action_blocks_when_fee_remediation_adoption_fails`). There is no client equivalent to regress from.

- **Client file:line:** none — no cutover code or tests exist in the client (`grep -rln 'cutover' woocommerce-payments/{includes,src,tests}` → 0 hits).
- **Confidence: HIGH** that this is NEW-IN-CORE and that the two real `assertTrue(true)` instances are benign route-assertion helpers, not cutover placeholders. The finding's premise (a lazy placeholder in cutover-guard tests) is not borne out; the cutover tests that exist (`WooPaymentsCutoverControllerTest`) are substantive.

---

## Summary

| id | classification | confidence | client file:line | reason |
|----|----------------|-----------|------------------|--------|
| 23405cc0 | PARITY | HIGH | `client/vat/form/__tests__/index.test.tsx:73` | Client awaits userEvent only inside `act()`; nearest VAT-modal client test is render-only. Core's flow coverage equals/exceeds client; shared await-idiom weakness. |
| 11adbec5 | PARITY | HIGH | `client/settings/express-checkout-settings/__tests__/woopay-settings.test.js:25` | Client also fully stubs the `wcpay/data/settings` hook layer; same mock-everything philosophy, just split across files. |
| 3593fad3 | PARITY | HIGH | client MC tests use camelCase `setUp`/`tearDown` universally | Client never used `set_up`/`tear_down`; core preserved the client idiom. Snake_case is a core-house-style nit, not a client regression. |
| 5bd7b33b | PARITY | HIGH | `tests/unit/test-class-wc-payment-gateway-wcpay.php:5258` | Client's 7 `update_order_status` tests all use a valid nonce; no invalid-nonce / `invalid_referrer` rejection test on this endpoint. Same gap both sides. |
| 7c7309ac | NEW-IN-CORE | HIGH | none (no client cutover code/tests) | Cited line is a real assertion-rich test, not a placeholder; the two real `assertTrue(true)` are benign route-helper success branches; cutover is core-only with substantive `WooPaymentsCutoverControllerTest`. |
