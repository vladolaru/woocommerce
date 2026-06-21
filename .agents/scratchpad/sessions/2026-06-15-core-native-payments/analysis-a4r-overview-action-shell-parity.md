---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 09:43
target: exp/core-native-payments A4r Overview action shell parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4q-overview-shell-parity.md
  - implementation-log.md
  - staging-log.md
status: draft
---

# A4r Overview Action Shell Parity

## Prompt And Constraint

This analysis continues the reopened A4/N12 native admin parity stream after A5c was closed and native admin readiness was returned to fail-closed. The user explicitly asked to reopen A4 after A5c because feature parity remains, to follow `supervisor-prompt-2026-06-18-2344-N12.md`, and to keep using the `2026-06-15-core-native-payments` session location for session docs.

## Source-Backed Current State

Native Overview currently renders the generic `WooPaymentsAccountSettings`, `SpotlightPromotion`, `AccountBalancesCard`, and `PayoutsOverviewCard` from `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`. A4q restored the financial summary cards and instant-payout action, but the route still lacks the reference Overview's action shell around that financial content.

Native Overview data in `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts` only covers deposits overview/list/summary/detail and instant payout. There is no native Overview shell projection equivalent to the reference `wcpaySettings.accountStatus`, task visibility, WPCOM reconnect URL, or connection-success modal dismissal state.

The settings option write side already exists. `plugins/woocommerce/client/admin/client/woopayments/settings/data/actions.ts` exposes `saveOption()`, and `WooPaymentsSettingsService::ALLOWED_OPTIONS` allowlists `woocommerce_dismissed_todo_tasks`, `woocommerce_remind_me_later_todo_tasks`, `woocommerce_deleted_todo_tasks`, and `wcpay_connection_success_modal_dismissed`. A4r should reuse this rather than adding a duplicate option transport.

The native REST controller already exposes `/wc-admin/settings/payments/woopayments/account` for account summary and `/wc/v3/payments/settings` plus `/wc/v3/payments/settings/{option_name}` for settings/option compatibility. A4r needs a narrow read projection, likely `/wc-admin/settings/payments/woopayments/overview`, rather than stuffing plugin-era globals into `window.wcpaySettings`.

`WooPaymentsAccountService::get_cached_account_data()` preserves the reference stability guardrail: normal reads may fall back to stale cached account data when the provider is unavailable, while strict refresh paths are reserved for webhook/account-event reliability. Any A4r projection should use this existing account service and project only safe fields. It must not force live account refresh on Overview mount and must not expose publishable keys or raw platform credentials.

Native disputes transport already exists under `/wc/v3/payments/disputes` and the money-movement frontend already has `getWooPaymentsDisputes()`. A4r can reuse this endpoint for the urgent dispute task with the reference query names instead of introducing a separate disputes API.

The reference plugin Overview renders its major sections from `client/overview/index.js`: top/query notices, test/sandbox notices, optional embedded Connect notification banner, Welcome, task list, account balances, deposits overview, account details, dispute readiness, active loan summary, inbox notifications, connection-success modal, and spotlight promotion. The coherent next slice is the Overview action shell after A4q financial cards, not the full remaining dashboard.

## Subagent Findings Incorporated

Hypatia mapped target/native state and recommended a small Core-owned Overview projection plus safe shell parity tasks. Missing target pieces are account-status projection, `overviewTasksVisibility` read shape, `wpcomReconnectUrl` projection if derivable locally, connection-success modal state, and embedded Connect notification support. Hypatia explicitly recommended deferring embedded Connect notifications, inbox notifications, full account details, and WPCOM-backed new endpoints from A4r.

Cicero mapped reference Overview and recommended making A4r the "overview action shell after financial summary cards": page gates/notices, task list, update-business-details/reconnect/dispute tasks, update-business-details modal, connection-success modal, option persistence, settings/disputes data hooks, and required overview/task/modal styles. Cicero recommended leaving deposits overview, dispute readiness, inbox notifications, active loan summary, and card readers for follow-up slices because they are distinct or larger surfaces.

Cicero accidentally wrote a separate scratchpad session at `.agents/scratchpad/sessions/2026-06-19-woopayments-overview-shell-map/analysis.md`. The source-backed findings from that read-only pass are incorporated here so the active working trail remains under `2026-06-15-core-native-payments` going forward.

## A4r Scope Decision

A4r will restore the action shell around the already-ported financial cards: top query notices where native can preserve the reference copy, a task list with persisted hide/delete/snooze behavior, update-business-details/finish-setup task, reconnect task when a URL exists, urgent dispute task, a go-live task that triggers the existing native account-mode activation flow, connection-success modal, and scoped Overview styles. This is broad enough to restore merchant remediation flows without pulling unrelated dashboard surfaces into one unstable chunk.

A4r will add a narrow Overview projection owned by Core. The projection should include account status fields needed by tasks, task visibility options, connection-success dismissal, setup/settings URLs, account mode flags, and `wpcom_reconnect_url` only if the native platform connection layer can derive it safely. Missing `wpcom_reconnect_url` must result in no reconnect task, not an inferred URL.

A4r will not implement embedded Connect notification banner, inbox notifications, active loan summary, dispute readiness card, full AccountDetails card, Reports/Documents, or Card Readers. These remain explicit A4/N12 follow-up slices.

Native admin readiness remains fail-closed after A4r. Passing A4r is not sufficient to default `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` to true.

## Testing Targets

Backend RED tests should cover the new overview projection shape: task visibility option reads, account status projection from the existing cache, safe omission of secrets, connection-success dismissal, setup/overview/settings URLs, and fail-closed reconnect URL behavior when not derivable.

REST RED tests should cover `GET /wc-admin/settings/payments/woopayments/overview` success, capability denial, and service exception handling.

Frontend RED tests should cover data-client paths, task rendering/filtering by deleted/dismissed/snoozed state, `saveOption()` calls plus undo notices, update-details actions for incomplete setup versus account-link update, urgent dispute task link targets under Settings > Payments provider subroutes, connection-success modal dismissal, and no shell tasks when the projection fails.

Browser proof should compare target Overview against reference for the action shell paths that are present in the current local account state, then use deterministic Jest/PHP tests for variants that local data cannot naturally trigger.
