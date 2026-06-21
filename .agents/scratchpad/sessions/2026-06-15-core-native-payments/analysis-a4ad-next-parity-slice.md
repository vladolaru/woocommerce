---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 02:20
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4ac-admin-readiness-reground.md
  - analysis-a4z-admin-exit-residuals.md
  - staging-log.md
  - spec-conformance-baseline.md
status: draft
---

# A4ad Next Parity Slice Selection

> **Prompt:** "ok. continue"

## Current Baseline

A4ac restored native admin readiness to fail-closed by default because A4ab is recorded as smoke/runtime route/chunk/log evidence, not full N12 parity. The next slice needs to move the A4/N12 baseline toward a real readiness gate rather than re-opening completed work.

N12 requires merchant reachability across account-state variants, per-surface functional/visual fidelity, copy/content fidelity, and a widened A4 exit gate before `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` may default true again. The current Playwriter gate only drives the current connected local account and checks route tokens, lazy chunks, failed responses, console issues, screenshots, and current admin nav routes. It does not mutate or seed account states and does not prove disabled-feature no-chunk/no-REST behavior.

## Explorer Findings

Three read-only explorers reported remaining source-backed A4 gaps:

- Settings residuals: WooPay disable-feedback modal, WooPay/Link legal copy, and settings VAT/tax-details query modal are still missing compared with the reference. These are merchant-facing parity gaps and should be tracked as a settings follow-up.
- Money/detail residuals: native payment/transaction details and dispute detail/outcome/action behavior are still thinner than the reference. These should be tracked as a payment-detail/dispute-action parity follow-up.
- Gate/product availability residuals: account-state and optional-feature availability are under-gated. Menu-state logic has PHP unit coverage, but direct routes still broadly register lazy chunks. Reports already uses a disabled-feature no-chunk route wrapper and REST registration gate; Capital, Card Readers, and Documents do not all follow that pattern.

## Source-Verified Findings

`tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs` defines a fixed surface matrix and current-account target/reference route checks. It records chunks and failures, but it does not vary gateway/account state or optional feature flags. Its admin navigation check only asserts required routes in the current target menu.

`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php` already gates submenu visibility by capability, native ownership, gateway enabled state, restricted account state, not-ready account state, and optional Reports/Card Readers/Capital/Documents eligibility. Unit coverage exists for no native runtime, no capability, disabled gateway, full account, restricted accounts, not-ready accounts, Reports, Documents, Card Readers, and Capital menu visibility.

`plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` registers all protected dashboard/money/optional admin routes inside the Settings > Payments route seam. Reports has a route wrapper that avoids importing its chunk when `featureFlags.reportsArea` is false. Capital, Card Readers, and Documents are still direct `Suspense` wrappers around lazy chunks, so direct deep links can load protected WooPayments chunks even when the corresponding submenu would be absent.

`WooPaymentsReportsRestController` registers only when native owns runtime and `WooPaymentsAccountService::is_reports_enabled()` is true. `WooPaymentsDocumentsRestController` similarly gates REST registration on `is_documents_enabled()`. `WooPaymentsCapitalRestController` registers routes whenever native owns runtime; it does not gate registration or request handling on account Capital eligibility. Card Readers uses the shared mobile/readers REST controller; any A4ad frontend availability guard should not casually break mobile/IPPs API continuity.

## Decision

The next slice should be **A4ad Account-State And Optional-Feature Admin Availability**.

This should be product-residual-led, not harness-only. The implementation should introduce a small native admin route availability projection and frontend route wrappers so protected dashboard/money/optional surfaces do not import chunks or call WooPayments admin REST when the account/provider state would not expose them in persistent navigation. It should preserve the provider settings route so the core Settings > Payments provider Manage flow still works. It should also add focused backend tests for the route-availability projection, frontend RED/GREEN tests for no-chunk wrappers, and a widened local gate that records account/feature availability coverage honestly.

Payment-detail/dispute-action parity and WooPay settings residual parity remain source-backed A4 follow-ups after A4ad. They are important, but A4ad is the highest-leverage next slice because it closes the precise N12/A5 readiness coverage gap that A4ac just re-opened fail-closed.
