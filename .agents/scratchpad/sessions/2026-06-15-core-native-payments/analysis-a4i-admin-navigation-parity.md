---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 00:21
target: A4i native WooPayments admin navigation parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4h-exit-gate.md
  - spec-conformance-baseline.md
  - staging-log.md
status: final
last_updated: 2026-06-19 00:56
---

# A4i Admin Navigation Parity Analysis

## Trigger

The user asked to add a bottom task so that after A5c is fully done, A4 is reopened for feature parity, and to read/follow `supervisor-prompt-2026-06-18-2344-N12.md`. A5c is committed, and N12 now blocks A5 cutover readiness until A4 proves merchant reachability, functional/visual fidelity, copy/content fidelity, SCSS fidelity, and a widened A4 exit gate.

## Source-Backed Findings

N12's immediate reachability blocker is real. Native currently registers the WooPayments React routes under Settings > Payments (`/woopayments/settings`, `/woopayments/overview`, `/woopayments/payouts`, `/woopayments/payouts/details`, `/woopayments/transactions`, `/woopayments/transactions/details`, `/woopayments/disputes`, `/woopayments/disputes/details`, `/woopayments/disputes/challenge`, `/woopayments/card-readers`, and `/woopayments/loans`) in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`, but `PaymentsController::add_menu()` only creates one top-level Payments item pointing at `admin.php?page=wc-settings&tab=checkout&from=WCADMIN_PAYMENT_MENU`. There are no persistent WooPayments submenus in native.

The reference plugin's `WC_Payments_Admin::add_payments_menu()` registers a stateful Payments menu category. Full valid accounts get Overview, Payouts, Transactions, Disputes, conditional Reports, conditional Card Readers, conditional Capital Loans, conditional Documents, plus Settings. Rejected and under-review accounts get a reduced menu with Overview, Transactions, Disputes, and hidden detail pages. Not connected or partially onboarded accounts get Onboarding or Continue onboarding entries. Every visible entry is `manage_woocommerce` gated.

The native menu should not reintroduce plugin-era `/payments/*` route ownership. N12's route architecture direction still stands: persistent navigation entries should deep-link to canonical Settings > Payments provider sub-routes with `page=wc-settings&tab=checkout&path=/woopayments/...`. The current onboarding modal already opens when the Settings > Payments query path includes `/woopayments/onboarding`, so an onboarding submenu can use that canonical target without adding a new standalone page.

The current native readiness predicate in `PaymentsController::is_woopayments_account_onboarded()` is intentionally legacy-runtime scoped. It asks `WooPaymentsLegacyRuntime::is_loaded()` and `is_account_onboarded_from_cache()` so the standalone extension can own the plugin-era menu while it is active. That predicate is not sufficient for native post-cutover menu reachability because native ownership occurs when `NativePaymentsRuntimeArbiter::should_native_register()` is true and the plugin is inactive.

Native account data has enough foundations for a clean state projection but lacks a few reference-equivalent predicates. `WooPaymentsAccountService` already preserves defensive cache refresh/backoff and exposes `has_account()`, `has_working_account()`, `can_process_payments()`, account type helpers, test-mode helpers, gateway setting reads, and default currency. It does not expose `is_account_rejected()`, `is_account_under_review()`, `is_details_submitted()`, or a reference-width "valid account for full admin menu" helper. The reference defines rejected as connected with `status` starting `rejected`, under review as connected with `status === under_review`, details submitted as `details_submitted === true`, and valid account as connected + details submitted + `capabilities.card_payments` present and not `unrequested`.

The A5c `WooPaymentsPlatformConnectionService` can report granular cutover connection readiness, but its public method requires owner user-token readiness for cutover and is not the right menu predicate as-is. For menu parity, the reference full menu uses working Jetpack/WPCOM connection plus valid account; a native menu service should either add a non-cutover `has_working_connection()` helper or keep connection readiness out of the first account-state projection and leave failed connection action-badging as the next menu parity step. The implementation must not call remote WPCOM or mutate WPCOM state.

Reference badging has three behaviors after menu registration: setup badge after three days unless hidden or force-shown by broken connection/account state, disputes awaiting-response badge that also appends `filter=awaiting_response` to the Disputes submenu URL, and transactions badge when manual capture is enabled and uncaptured transactions exist. This should be preserved in native, but the first reachability implementation should not fake counts. If deterministic local counts are not already exposed through native caches/routes, badges should be a tracked follow-up inside A4i rather than hard-coded.

## Implementation Direction

Create a provider-specific native admin navigation controller under the existing WooPayments Settings > Payments provider namespace, not inside the generic `PaymentsController`. Keep `PaymentsController` responsible for the top-level Core Payments menu and the generic provider list. The new controller should run only when `NativePaymentsRuntimeArbiter::should_native_register()` is true, should require `manage_woocommerce`, and should add WooPayments submenus under the existing Core Payments parent slug so provider-specific routes funnel through Core Payments settings orchestration.

Add narrowly-scoped account-state helpers to `WooPaymentsAccountService` so the menu state is source-backed and reusable by future WooPayments admin surfaces. This preserves the battle-tested defensive cache behavior rather than re-reading raw options in a new controller. The first green implementation should cover full, reduced, onboarding, and no-native-runtime menu states. It should leave `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` false; A4i improves reachability but does not close N12's broader functional/visual/copy/SCSS parity gate.

## Proposed Slice Boundary

A4i should close "persistent admin navigation reachability" for the native routes that already exist and add a gate that proves those routes are reachable from WP admin navigation without typing URLs. It should not attempt the full settings-page component/SCSS hoist in the same commit. The next reopened A4 slices should handle settings component/SCSS parity, dashboard visual/UX parity, copy/content parity, and final widened A4 exit gate.

## Outcome

A4i implemented the persistent admin navigation reachability slice. `WooPaymentsAdminNavigationController` now registers WooPayments submenus under the Core Payments parent only when the native runtime owns registration, gates visible entries with `manage_woocommerce`, and links every entry to canonical Settings > Payments provider paths. `WooPaymentsAccountService` now exposes the reference-backed account-state helpers needed for full, reduced, onboarding, card-reader, and Capital menu variants while keeping reads behind the preserved cached-account boundary.

Browser evidence on the target store proved direct route rendering for overview, payouts, transactions, disputes, and settings. The actual WP admin interaction also worked: hovering the top-level Payments menu and clicking Payouts landed on `admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts&from=PAYMENTS_MENU_ITEM` with `Payout history` visible. The only browser console entry was the existing Chrome permissions-policy `unload` message, not a PHP/WP notice.

Review evidence is recorded from the closed subagents. The reference-integrity reviewer approved with no critical/high/medium findings and verified paths, parent slug, helper URL shape, and reference account-state semantics. The WordPress architecture reviewer found one medium issue in the first implementation: `register()` added the `admin_menu` hook before native ownership gating. That was fixed test-first, and the final controller gates both registration and callback execution.

This closes only the N12 Surface 1 persistent navigation reachability chunk for routes that already exist. It does not close A4 feature parity. Settings component/SCSS parity, dashboard visual/UX parity, copy/content parity, reference badging, Reports/Documents disposition, and the final widened A4 exit gate remain open before `FILTER_NATIVE_ADMIN_SURFACES_READY` can default true again.
