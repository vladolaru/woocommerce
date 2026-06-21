---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 19:34
status: draft
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-17-1311.md
---

# A4g Remaining Admin Surfaces

## Prompt Trail

> **Prompt:** "When identifying bigger chunks of implementation (including more mechanical ones), consider using subagent implementors, including parallel ones where they don't risk trampling on each other, to conserve your own context and focus."

## Source-Backed Findings

Native A4 routes currently cover Settings, Overview, Payouts list, Transactions, Transaction details, Disputes, Dispute details, and Dispute challenge under the Settings > Payments provider route registry in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`. The missing plugin-route surfaces mapped by the read-only subagents are: payout details, reports, card readers, Capital loans, documents, multi-currency setup, fraud-protection advanced settings, and plugin-era connect/onboarding top-level routes.

The subagent claim that dispute file routes are missing is not a product blocker after source verification. There is no separate `WooPaymentsFilesRestController`, but `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php` registers the preserved `/wc/v3/payments/file`, `/wc/v3/payments/file/{file_id}/details`, `/wc/v3/payments/file/{file_id}/content`, and public `/wc/v3/payments/file/{file_id}` routes in `register_native_settings_routes()` when `NativePaymentsRuntimeArbiter::should_native_register()` allows native route registration. Tests in `WooPaymentsRestControllerTest.php`, `WooPaymentsSettingsServiceTest.php`, and `WooPaymentsApiClientTest.php` cover file upload, details, contents, public logo access, private dispute-evidence denial, and provider upload failures.

Payout details is a real A4 gap. The reference plugin registers `/payments/payouts/details` from `client/index.js` and renders `client/deposits/details/index.tsx`; native has `getWooPaymentsDeposit()` and backend detail support through `WooPaymentsDepositsRestController`, but no `/woopayments/payouts/details` route or details page. Because payouts list is already native and row/detail navigation is a standard merchant workflow, this should be included before A4 can claim payout parity.

Card readers is a bounded frontend gap. Native backend coverage already exists in `WooPaymentsMobileRestController` for `/wc/v3/payments/readers`, `/readers/charges/{transaction_id}`, receipts, and terminal locations. The reference UI is small (`client/card-readers/index.tsx`, `client/card-readers/list/index.tsx`, and `style.scss`), and the route is capability-gated in the plugin admin menu based on card-present eligibility/readers. In native it should be provider-owned and lazily loaded; gate/empty-state behavior can be handled by account/readers data rather than a generic Core payments abstraction.

Capital is a bounded backend plus frontend gap. The reference plugin exposes `/payments/capital/active_loan_summary` and `/payments/capital/loans` from `class-wc-rest-payments-capital-controller.php`, with frontend route `/payments/loans` in `client/capital/index.tsx`. The BC manifest explicitly keeps Capital provider-specific. Native should add a provider-owned Capital REST controller and a lazy `/woopayments/loans` route, without introducing generic loans abstractions.

Reports is a heavier A4 slice and should not be mixed into A4g. The reference route is feature-gated by `reportsArea` and needs Fees, Balance, export polling, tabs, and custom date filters. Native has no reports REST controller equivalents today. This deserves its own broad slice after A4g.

Documents, multi-currency setup, and fraud-protection advanced settings need explicit A4 disposition. Documents is feature-gated by account state; fraud protection is settings-adjacent but currently a separate plugin route; multi-currency setup is tied to the multi-currency workstream and should be reconciled against the native multi-currency admin surfaces rather than assumed covered.

Plugin-era connect/onboarding top-level route semantics need a route-architecture disposition rather than a direct top-level port. The user direction is that WooPayments-specific routes should sit as provider sub-routes of WooCommerce > Settings > Payments, with old `/payments/*` paths as explicit compatibility aliases where needed. Native Settings Payments onboarding already exists; the remaining question is alias/back-compat and pre-cutover merchant navigation, not rebuilding the plugin's top-level menu.

## Slice Decision

A4g should cover payout detail parity plus the bounded Card readers and Capital Loans surfaces. It should not implement Reports, Documents, fraud-protection advanced settings, or top-level plugin menu recreation. It should explicitly keep `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` false because A4 remains open after A4g.
