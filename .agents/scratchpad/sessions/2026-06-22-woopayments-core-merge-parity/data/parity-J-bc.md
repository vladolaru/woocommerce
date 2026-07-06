---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-J
created: 2026-06-22 22:55
target: backward compatibility
---

# Parity batch J — BC: removed / changed public surfaces

Oracle: WooPayments client at `~/Work/a8c/woocommerce-payments` (**v10.8.0**, branch `develop`).
Core: `exp/core-native-payments` at `~/Work/a8c/woocommerce-develop-2`.

Classification legend: BC-RISK / PARITY / NEW-IN-CORE / NOT-A-RISK.

---

## Finding 1 — c49baad5 (HIGH): removed public onboarding-task filters

**Verdict: BC-RISK (confirmed hard break). The client hooks BOTH filters, unconditionally.**

### What core removed

The entire onboarding task class was deleted on this branch:

- `plugins/woocommerce/src/Admin/Features/OnboardingTasks/Tasks/WooCommercePayments.php` — **does not exist** on `exp/core-native-payments` (exists on `trunk`).
- Deleted in commit **7699deb62f** `refactor(payments): remove deprecated woopayments onboarding surface` (Tue Jun 16 2026). The commit removed 307 lines of that one file plus the task-list registration, fills, note class, note facade, and welcome-page typings (19 files, -963 lines net).

On `trunk` the deleted class fired the two documented public filters:

- `git show trunk:.../Tasks/WooCommercePayments.php:59` → `return apply_filters( 'woocommerce_admin_woopayments_onboarding_task_badge', '' );` (docblock `@since 8.2.0`, in `get_badge()`).
- `git show trunk:.../Tasks/WooCommercePayments.php:87` → `return apply_filters( 'woocommerce_admin_woopayments_onboarding_task_additional_data', null );` (docblock `@since 9.4.0`, in `get_additional_data()`).

Core grep on this branch: the **only** remaining mention of either filter name is the changelog file `plugins/woocommerce/changelog/fix-native-payments-review-bc-deprecations`. There is **no replacement `apply_filters()` and no `_deprecated_hook()`** call anywhere in `plugins/woocommerce/`. The filters are gone silently from a code-contract standpoint.

### Does the CLIENT rely on them? — YES, both.

`includes/class-wc-payments-incentives-service.php` (the shipping v10.8.0 client):

- **L80**: `add_filter( 'woocommerce_admin_woopayments_onboarding_task_badge', [ $this, 'onboarding_task_badge' ] );`
- **L81**: `add_filter( 'woocommerce_admin_woopayments_onboarding_task_additional_data', [ $this, 'onboarding_task_additional_data' ], 20 );`
- Callbacks defined at L133 (`onboarding_task_badge`) and L150 (`onboarding_task_additional_data`); they inject the connect-incentive `task_badge` and `wooPaymentsIncentiveId` into the onboarding task.
- Registration is **unconditional**: `WC_Payments_Incentives_Service` is instantiated in `includes/class-wc-payments.php:592` and `init_hooks()` is called at `includes/class-wc-payments.php:608` during plugin init — no feature gate around the `add_filter` calls.
- The client even asserts the badge filter is hooked in its own test suite: `tests/unit/test-class-wc-payments-incentives-service.php:108` → `has_filter( 'woocommerce_admin_woopayments_onboarding_task_badge', ... )`.

So when v10.8.0 runs on this core branch, both `add_filter` calls succeed but **nothing ever fires them** → the incentive badge and `wooPaymentsIncentiveId` are silently dropped. The callbacks become dead code. No fatal error (these are filter registrations, not `do_action` on a missing function), but the documented extension point is broken without notice.

### Severity / mitigation context

- **Mitigation present:** core deletes the whole legacy task and replaces it with a native incentive surface that owns the badge/additional-data path itself — `plugins/woocommerce/src/Internal/Admin/Suggestions/Incentives/WooPayments.php` (exists) and `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsController.php`. The client comment at `includes/class-wc-payments-incentives-service.php:66` already notes it shares the same incentive cache keys as `\Automattic\WooCommerce\Internal\Admin\Suggestions\Incentives\WooPayments`, so the native surface can render the same incentive. The legacy task/action entry points are kept as redirects into Settings Payments (per the deletion commit message). So the *user-visible* incentive is likely still shown via the native path — practical UX impact is limited.
- **But the contract break is real:** these are documented, versioned (`@since 8.2.0` / `@since 9.4.0`) public filters with third-party-facing docblocks. Any extension (not just WooPayments) that hooked them now silently no-ops. WordPress convention for removing a public hook is to keep a `_deprecated_hook()` shim (or, here, retain the `apply_filters()` call where the equivalent value is computed) so consumers get a deprecation notice rather than silent breakage.

### Recommendation

BC-RISK requiring action. Either (a) re-fire equivalent `apply_filters()` for both names from the native incentive surface so existing `add_filter` callbacks still influence the rendered incentive, or (b) at minimum call `_deprecated_hook( 'woocommerce_admin_woopayments_onboarding_task_badge', 'x.y.z', 'native Settings Payments onboarding' )` (and the `_additional_data` equivalent) so consumers are warned. The changelog already documents the removal, but a changelog line is not a runtime deprecation and does not satisfy the "maintain backward compatibility" goal for code that hooks these filters.

**Confidence: HIGH.** File-level deletion confirmed via git; client `add_filter` on both names confirmed at exact lines; no replacement/deprecation in core confirmed by grep.

---

## Finding 2 — 9d771cef (LOW): new constant `WOOPAYMENTS_SECTION_NAME` missing `@since`

**Verdict: NOT-A-RISK for the compatibility question (section slug value matches the client). Doc nit only.**

### Core

`plugins/woocommerce/includes/admin/settings/class-wc-settings-payment-gateways.php:39`:

```php
const WOOPAYMENTS_SECTION_NAME = 'woocommerce_payments';
```

The `@since` tag is indeed missing (the sibling constants like `BACS_SECTION_NAME`, `CHEQUE_SECTION_NAME` also lack inline `@since`, so it is consistent with the file's existing style, not a regression). This is a documentation nit, not a behavioral BC concern.

### The real compatibility question — does the VALUE match the client's section slug? — YES.

The WC settings "section" slug for a payment gateway is, by WC convention, the gateway's own ID. The client's gateway ID is:

- `includes/class-wc-payment-gateway-wcpay.php:76` → `const GATEWAY_ID = 'woocommerce_payments';`

and the client deep-links into the settings section using exactly that slug throughout, e.g.:

- `includes/class-wc-payment-gateway-wcpay.php:1100` → `admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments`
- `includes/class-wc-payments-account.php:949` → `'section' => 'woocommerce_payments'`
- `includes/admin/class-wc-payments-admin.php:547` → `'wc-settings&tab=checkout&section=woocommerce_payments'`
- `includes/class-wc-payments-vat-redirect-service.php:51`, `src/Internal/Service/DisputeReadinessService.php:221,268`, `includes/subscriptions/class-wc-payments-subscriptions-disabler.php:569,610` — all use `section=woocommerce_payments` / `'woocommerce_payments'`.

Core's `WOOPAYMENTS_SECTION_NAME` value (`'woocommerce_payments'`) is **identical** to the client's `GATEWAY_ID` and to every client deep-link section slug. So the settings deep-links the client emits continue to resolve to the same section. No value mismatch → no functional BC break.

### Recommendation

Optional polish only: add an `@since x.y.z` to the new constant for documentation consistency with the broader codebase convention. No compatibility action required — the slug value is correct and matches the client.

**Confidence: HIGH** on the value-match (grep-confirmed `GATEWAY_ID = 'woocommerce_payments'` and many client deep-links). The `@since` omission is cosmetic.

---

## Batch summary

| id | classification | client depends? | note |
|----|----------------|-----------------|------|
| c49baad5 | **BC-RISK** | YES — both filters | client `add_filter`s both onboarding-task filters unconditionally; core deleted them with no `_deprecated_hook()`. Native incentive surface mitigates UX, but the public filter contract is broken silently. |
| 9d771cef | **NOT-A-RISK** | n/a (value matches) | section slug `'woocommerce_payments'` == client `GATEWAY_ID`; deep-links still resolve. Missing `@since` is a doc nit only. |
