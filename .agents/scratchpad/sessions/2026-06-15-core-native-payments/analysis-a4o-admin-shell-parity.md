---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 06:21
last_updated: 2026-06-19 06:51
target: exp/core-native-payments — A4o native admin shell parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4m-fraud-protection-settings-parity.md
  - staging-log.md
status: final
---

# A4o Admin Shell Parity Analysis

## Decision

A4o should be a native admin shell parity slice focused on persistent Payments submenu badges and explicit Reports/Documents disposition, not a full dashboard or reporting-surface port. This is the highest-leverage next N12 item after A4n because native already has the submenu skeleton and route ownership, while the reference still has merchant-visible badge behavior on Disputes and Transactions that native currently drops.

Scope for A4o: gate native WooPayments submenus on the gateway/provider enabled state, add native Disputes awaiting-response badge behavior, add native manual-capture Transactions authorization badge behavior, source-back the required cached count/API seams, widen the ignored A4 admin surface gate to assert badge/disposition coverage, and fix the stale provider-route registration test for the already-existing fraud-protection route. Keep `FILTER_NATIVE_ADMIN_SURFACES_READY` fail-closed.

Explicitly out of A4o: Reports UI/API, Documents UI/API, standalone top-level connect/setup badge, onboarding/incentive promotional badges, and full Overview/Payouts/Transactions/Disputes/Card Readers/Capital visual parity.

## Source-Backed Reference Findings

Standalone WooPayments registers the admin menu in `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php`. Baseline child pages are Overview, Payouts, Transactions, and Disputes; Reports is added only when `WC_Payments_Features::is_reports_area_enabled()` returns true; Documents is added only for full valid accounts when `WC_Payments_Features::is_documents_section_enabled()` returns true. Full-menu gating requires working Jetpack/WPCOM connection plus a valid Stripe account; rejected and under-review accounts deliberately get only Overview, Transactions, Disputes, and detail routes.

Reference badge behavior to port:

- Disputes badge: `add_disputes_notification_badge()` rewrites the Disputes submenu URL to include `filter=awaiting_response` and appends the unresolved-count badge. `get_disputes_awaiting_response_count()` fetches `disputes/status_counts`, uses live/test cache keys, and sums only `needs_response` plus `warning_needs_response`.
- Transactions badge: only runs when gateway setting `manual_capture` is `yes`. `get_uncaptured_transactions_count()` fetches `authorizations/summary`, uses live/test cache keys, and uses the returned `count`.

Reference behavior not to port literally:

- The standalone setup/action badge targets `wc-admin&path=/payments/connect` and the old top-level WooPayments menu shape. Native Core now routes WooPayments-specific UI as Settings > Payments provider subroutes, so any future action badge must be designed deliberately for the Core Payments parent/provider route architecture.
- Reports is disabled by default behind `_wcpay_feature_reports_area` and carries a separate report API/UI surface. Documents depends on cached `is_documents_enabled` and its own documents REST/UI surface. Both need product-surface slices before menu entries should appear.

## Native Current State

`WooPaymentsAdminNavigationController` already registers persistent native submenus under the Core Payments parent when the native runtime owns registration and the user can `manage_woocommerce`. It does not currently check the gateway/provider `enabled` setting before appending those submenus, so disabled native WooPayments can still own persistent navigation under Core Payments. Full native menu today is Overview, Payouts, Transactions, Disputes, optional Card Readers, optional Capital Loans, and Settings. Reduced rejected/under-review menu matches the reference reduced set. Legacy redirects cover overview, deposits/payouts, transactions, disputes, card readers, loans, and settings, but not Reports/Documents.

Gaps:

- No native menu badge count service exists.
- Native submenu reachability is not gated on the WooPayments provider's persisted `enabled` setting.
- `WooPaymentsApiClient` has transactions/disputes APIs but lacks source-backed `disputes/status_counts` and `authorizations/summary` methods.
- Native preserves the old dispute and authorization cache option names in `WooPaymentsAccountService`, and `WooPaymentsDisputeCacheService` deletes dispute cache keys, but there is no generic native database-cache helper for badge counts.
- The ignored `a4-admin-surface-gate.py` still proves route/chunk/single-registry architecture only; it does not assert badge behavior or Reports/Documents disposition.
- `register-provider-routes.test.tsx` is stale: the real provider routes include `/woopayments/settings/fraud-protection`, but that bootstrap expectation omits it.

## Implementation Shape

Add a public `WooPaymentsAccountService::is_gateway_enabled()` predicate that wraps the preserved gateway `enabled` setting, and use it in the admin navigation controller before appending submenu items. This keeps the menu gate explicit and provider-owned without refactoring the broader Core Payments providers list.

Add a small provider-owned badge/count service under `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments`, initialized with `WooPaymentsAccountService` and `WooPaymentsApiClient`. It should use preserved cache option names, live/test mode selection from the account service, and reference-compatible validation: arrays are acceptable cached data; fetch failures return stale valid data if present or zero counts if no valid data exists. Keep this logic out of `WooPaymentsAdminNavigationController` so menu wiring stays responsible only for presentation/reachability.

Extend `WooPaymentsApiClient` with `get_dispute_status_counts()` using `disputes/status_counts` and `get_authorizations_summary()` using `authorizations/summary`, both GET and preserving the existing `test_mode` query behavior.

Extend `WooPaymentsAdminNavigationController` to inject the badge/count service, append reference-shaped admin count badges to Disputes and Transactions, rewrite the native Disputes URL with `filter=awaiting_response`, and only show the Transactions badge when `manual_capture` is enabled.

Use tests to prove:

- Disputes badge sums `needs_response` plus `warning_needs_response`, ignores other statuses, appends the badge, and adds `filter=awaiting_response`.
- Disabled native WooPayments provider settings produce no submenus under the Core Payments parent even when the account cache is otherwise valid.
- No Disputes badge appears for zero counts or fetch failure without stale valid data.
- Transactions badge appears only when `manual_capture` is `yes` and authorization summary count is positive.
- Reports and Documents remain absent even if their legacy flags/account fields are present, with a documented disposition.
- Native admin readiness remains fail-closed.
- The A4 gate reports source/gate coverage for badge/disposition assertions.

## Verification Notes

Run focused PHP RED/GREEN tests for the new service, API client, and navigation controller. Run the ignored A4 admin-surface gate after updating it. Run a Playwriter check against target admin navigation to confirm the submenu has the expected badge/filter behavior when local options are seeded, and clean up seeded options afterwards. Run branch-level lint/static gates only after source changes are complete; do not lint scratchpad artifacts.

## Closeout Notes

A4o implemented the selected shape and kept native admin readiness fail-closed. Review fixes added two important guardrails after the first green line: cold badge-cache API failures now write a short-lived errored wrapper so admin loads do not refetch on every page view, and legacy `/payments/*` redirects now also respect the provider-enabled gate. The API client badge endpoints also run the same legacy request-object filters as the reference (`wcpay_get_dispute_status_counts` and `wc_pay_get_authorizations_summary`), so existing integrations that mutate those requests keep their compatibility seam.

The disabled-provider navigation/redirect behavior intentionally differs from the standalone extension. In Core-native ownership, WooPayments-specific persistent submenus and legacy deep-link redirects are provider-owned surfaces under the Core Payments route orchestration, so they should not claim merchant navigation when the native provider is disabled.
