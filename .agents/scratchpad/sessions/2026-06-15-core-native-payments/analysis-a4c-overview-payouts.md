---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 12:43
last_updated: 2026-06-18 12:47
target: A4c native WooPayments overview and payouts
reconciles:
  - analysis-a4-native-woopayments-admin.md
  - analysis-a4b-admin-dashboard-surface.md
  - staging-log.md
  - spec-conformance-baseline.md
  - supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4c Overview And Payouts Analysis

> **Prompt:** "Continue working toward the active thread goal."

## Current State

A4a gave Core a native WooPayments account/settings summary under `wc-admin/settings/payments/woopayments/account`. A4b added a provider-neutral Settings Payments route seam and registered `/woopayments/overview`, but the current overview component still wraps `WooPaymentsAccountSettings`. That is useful route infrastructure, not real dashboard parity.

The canonical route ownership correction still stands: native provider admin routes should live under WooCommerce > Settings > Payments as provider subroutes such as `admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview`, not as a recreated plugin-era top-level `/payments/*` app. Compatibility aliases for plugin-era paths can be added deliberately later, but they should not define the Core-owned route boundary.

## Reference Overview Contract

The reference WooPayments overview renders from `/Users/vladolaru/Work/a8c/woocommerce-payments/client/overview/index.js`. Its first meaningful business cards consume `wcpaySettings`, `/wc/v3/payments/settings`, active disputes through `/wc/v3/payments/disputes`, deposit overview data through `/wc/v3/payments/deposits/overview-all`, recent payouts through `/wc/v3/payments/deposits`, dispute readiness, and optional Capital data. A full overview parity slice is therefore larger than just rendering account status.

The deposits/payouts portion is the highest-leverage first real dashboard chunk. The reference data store calls `/wc/v3/payments/deposits/overview-all`, `/wc/v3/payments/deposits`, `/wc/v3/payments/deposits/summary`, `/wc/v3/payments/deposits/{id}`, and a POST to `/wc/v3/payments/deposits` for manual instant payout. The overview card itself needs only `overview-all` plus a recent list query with `store_currency_is`, `orderby=date`, `order=desc`, and `per_page=3`. The response shape includes `deposit.last_paid`, `balance.pending`, `balance.available`, `balance.instant`, and `account.deposits_enabled`, `account.deposits_schedule`, and `account.default_currency`.

## Native Source Gaps

Native `WooPaymentsApiClient` already supports low-level `get_charge()`, `get_dispute_summary()`, and `get_transaction()`, but it does not yet expose deposit/payout list, summary, detail, overview-all, or manual payout calls. There is no provider-owned admin REST controller under the native WooPayments namespace for the preserved `/wc/v3/payments/deposits*` routes.

The existing settings-specific REST controller under `Internal\Admin\Settings\PaymentsProviders\WooPayments` should remain focused on Settings Payments onboarding/account routes. A4c should add provider-owned admin runtime endpoints under `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments`, while still mounting the React UI through the Settings Payments provider route seam from A4b.

## Slice Direction

A4c should port a real native Overview + Payouts foundation: add native API-client methods and a provider-owned `/wc/v3/payments/deposits*` REST controller for the read-only overview/list/detail/summary subset, register a `/woopayments/payouts` provider subroute as a real target for the overview CTA, and replace the current overview wrapper with account summary plus a native payouts overview card that consumes those preserved endpoints. Manual instant payout and full payout detail/export can remain explicitly unimplemented in this slice unless the source checks show the overview depends on them.

The UI should stay separately bundled: overview remains `settings-payments-woopayments-overview`, and payouts should get its own lazy chunk. The code must keep `woocommerce_woopayments_native_admin_surfaces_ready` false because overview+payouts foundation is still not all A4 surfaces.

## Explorer Findings

Backend contract explorer confirmed the preserved API boundary should stay under `wc/v3/payments/deposits*`, even though the Core-native UI should use payouts terminology and mount under `/woopayments/*`. The minimum route set for a real overview+payouts foundation is `GET /wc/v3/payments/deposits/overview-all`, `GET /wc/v3/payments/deposits`, `GET /wc/v3/payments/deposits/summary`, and `GET /wc/v3/payments/deposits/{id}` if any recent payout rows or payout-history rows link to details. The list endpoint must preserve the plugin envelope `{ data, total_count }`, and query parameters should use platform-facing names such as `page`, `pagesize`, `sort`, and `direction`; Core should not normalize those to `per_page`, `orderby`, or a WP collection envelope at the backend boundary.

Frontend contract explorer confirmed the smallest meaningful A4c parity surface is the overview money surface: account balance plus payouts, not the whole plugin-era `/payments` app. The current Core route is still a route shell because `/woopayments/overview` only renders account settings. The first real page should render balance and payout cards, including loading, empty hidden state for new accounts with no funds, waiting-period pending funds state, suspended-payouts state, minimum-payout and negative-balance notices, recent payout rows with dispatch date/status/amount, and a history action only if `/woopayments/payouts` is a real route.

The UI decomposition should keep provider admin code under `plugins/woocommerce/client/admin/client/woopayments/admin/`, separate overview data helpers from current settings helpers, scope styles under the native overview root instead of importing plugin globals, and preserve lazy chunks. The reference Tracks names for equivalent merchant actions should remain `wcpay_overview_deposits_view_history_click`, `wcpay_overview_deposits_change_schedule_click`, and `wcpay_account_details_link_clicked`, with plugin-era paths translated to Settings Payments provider subroutes.

## Verification Targets

Tests should prove API-client method path/query behavior, REST route registration/permission/error behavior, JS data helpers call preserved `/wc/v3/payments/deposits*` endpoints, overview renders loading/error/empty/real payout states without relying on plugin globals, `/woopayments/payouts` is registered as a separate route, and the overview CTA navigates through the Core Settings Payments route seam. Browser proof should compare target overview/payout states against the reference store for the same local account data and record any remaining parity gaps.
