---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 15:45
target: A4w native WooPayments reports parity
reconciles:
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
last_updated: 2026-06-19 17:21
---

# A4w Reports Parity Source Map

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A4v closed the native Documents/VAT surface and left Reports as the only still-absent WooPayments admin surface explicitly called out in the A4/N12 caveat. The next slice is therefore scoped to source-verify whether Reports can be ported as a bounded native admin UI/API package under the Settings > Payments provider route seam, while keeping final A4 exit gating and richer money-movement detail parity separate.

## Working Questions

- What exactly does the reference WooPayments Reports surface register, render, and request?
- Which native Core routes, REST controllers, API-client helpers, menus, and admin chunks already exist for Reports, if any?
- Which account-state gates control Reports reachability in the reference?
- Can Reports be restored as a coherent bounded A4w slice without mixing in broader settings parity or final A4 exit-gate work?

## Findings

### Closure

A4w was committed locally as `64cbc2b10a` (`feat(payments): add native WooPayments Reports surface`) after the focused gates, browser proof, and A4 admin-surface harness passed. Git range: `97c401afc9...64cbc2b10a`. This closes the bounded Reports slice only; the widened A4/N12 feature-parity gate remains open.

### Reference WooPayments Surface

The extension registers Reports only behind the disabled-by-default `_wcpay_feature_reports_area` option. `WC_Payments_Features::is_reports_area_enabled()` reads that flag, `WC_Payments_Admin` adds the `/payments/reports` child page only when it is enabled, and `client/reports/page-config.ts` similarly adds the client route only when `wcpaySettings.featureFlags.reportsArea` is true.

The visible reference Reports page has two tabs: Balance and Fees. It records `page_view` with `path: payments_reports`, records `wcpay_reports_tab_change`, and keeps the tab in the `tab` query arg. Balance is the default tab.

Reference REST controllers are under `/wc/v3/payments/reports/*`:

- `payments/reports/balance` reads `date_start`, `date_end`, and `currency`, and proxies `Get_Reporting_Balance_Summary`.
- `payments/reports/fees` lists fee-bearing transactions, strips `customer` from each row, and defaults transaction type filtering to charge/payment/refund/dispute/fee/network-cost types.
- `payments/reports/fees/summary` proxies transaction summary with the same fees filters.
- `payments/reports/fees/download` and `/download/{export_id}` start and poll the CSV export through the existing transactions export backend.
- `payments/reports/transactions` and `payments/reports/authorizations` exist in the extension but the Reports UI source map only routes Balance and Fees through the Reports page; native already has separate Transactions and Authorizations surfaces from earlier A4 slices.

Reference frontend Reports already uses `@wordpress/dataviews/wp`. Balance composes DataViews to render only the native date filter controls and table layout, with scoped styling to match the bespoke balance summary. Fees uses DataViews as the full table/search/filter/pagination surface and adds a custom date popover, accessible load/error announcements, empty states, and CSV export hooks.

The reference source-map subagent confirmed the concrete registration path: `client/index.js` lazy-loads `ReportsPage`, `client/reports/page-config.ts` registers `/payments/reports` with nav id `wc-payments-reports` and `manage_woocommerce`, and `WC_Payments_Admin::add_payments_menu()` adds the PHP child page only when `_wcpay_feature_reports_area` is enabled. The account reachability gate is stricter than the flag alone: the full menu requires a working Jetpack connection and valid Stripe account, while rejected or under-review accounts get a limited menu without Reports. That means native should treat Reports as a full-menu surface, not a limited-account surface.

Reference merchant-visible copy and interactions that matter for parity: the shell labels are `Reports`, `Balance`, `Fees`, and `View your reconciliation reports.`; Balance exposes `Balance summary`, `Print`, `Export`, print-specific copy, reload/error/empty states, and balance-row labels; Fees exposes `Search fees`, DataViews field labels, date filter presets, reload/error/empty states, export confirmation, and export progress notices. Tracks coverage includes `page_view`, `wcpay_reports_tab_change`, balance date/load/reload/export/print events, fees load/reload/filter/search/date/export events, and the existing `wcpay_csv_export_click` row type/source pair.

### Native Core State

Native Core explicitly omits Reports today: `WooPaymentsAdminNavigationController` has a "Reports legacy routes are intentionally absent until their native UI/API surfaces are ported" comment and no `/payments/reports` legacy redirect.

Native Core already owns the adjacent money-movement and documents machinery:

- `WooPaymentsTransactionsRestController` registers `/wc/v3/payments/transactions`, summary, export, export URL, search, fraud-outcome, and detail routes, and preserves the legacy `wcpay_list_transactions_request` filter through `WooPaymentsTransactionsListRequest`.
- `WooPaymentsApiClient` already has `get_transactions`, `get_transactions_summary`, `get_transactions_export`, and `get_transactions_export_url`.
- `WooPaymentsDocumentsRestController` shows the current route style: bounded provider REST controller, `manage_woocommerce` permission, account-feature gating in `register()`, filtered platform params, and 400-599 exception status preservation.
- Native admin client already has DataViews wrappers for money movement and direct DataViews usage for Documents.

The native source-map subagent found no existing Reports surface. The absence is explicit in `WooPaymentsAdminNavigationController`, where a comment says Reports legacy routes are intentionally absent until their native UI/API surfaces are ported, and the PHP test suite currently asserts Reports is absent. Native route registration is centralized through the Settings > Payments provider route seam: `settings-embed/index.tsx`, `settings-payments/register-provider-routes.ts`, `settings-payments/index.tsx`, and `woopayments/admin/routes.tsx`. Current native WooPayments routes include Overview, Payouts, Transactions, Disputes, Card Readers, Capital, Documents, and Settings, but no Reports.

Native REST registration currently includes deposits, payment details, authorizations, transactions, disputes, capital, and documents. There is no Reports REST controller and `WooPaymentsApiClient` has no `REPORTING_API` or `get_reporting_balance_summary()` helper. Existing `WooPaymentsTransactionsRestController` and `WooPaymentsDocumentsRestController` are the closest implementation patterns for request mapping, permission checks, feature gating, export helpers, and platform exception handling.

The A4 admin surface harness was widened before implementation so Reports is no longer allowed to be silently absent. The RED run at `data/a4w-admin-surface-gate-red.json` failed on the expected missing pieces: `settings-payments-woopayments-reports` source chunk, `/woopayments/reports` admin route, `is_reports_enabled()` account-service contract, and the built Reports chunk.

### Provisional Port Boundary

A4w should be a bounded Reports slice, not a broad Payments settings refactor. The natural boundary is:

- Add the native Reports menu item and legacy redirect only when the native equivalent of the Reports flag is enabled and the gateway/account state would otherwise show the full WooPayments menu.
- Add `/woopayments/reports` as a Settings > Payments provider sub-route next to Overview, Payouts, Transactions, Disputes, Card Readers, Capital, Documents, and Settings.
- Add native `/wc/v3/payments/reports/balance`, `/wc/v3/payments/reports/fees`, `/fees/summary`, `/fees/download`, and `/fees/download/{export_id}` routes with source-compatible request/response behavior.
- Prefer DataViews because both reference tabs already use it and because DataViews is the core-owned WP component for these table interactions; keep visual parity targeted to behavior, grouping, copy, controls, and merchant usability, not forced pixel matching against legacy table CSS.
- Keep `/payments/reports/transactions` and `/payments/reports/authorizations` out of scope unless source verification shows the Reports UI calls them; the native money-movement and uncaptured-authorization slices already own those surfaces.

### Open Verification Questions

- Whether native already localizes a feature flag equivalent for Reports, or whether A4w needs to add a core-owned account/option gate matching `_wcpay_feature_reports_area`.
- Whether the platform endpoint used by `Get_Reporting_Balance_Summary` is directly reachable through `WooPaymentsApiClient::request()` with the existing V1 API path naming, or needs a small request method to preserve the reference route.
- Which exact admin bundle registration path should own the Reports chunk so WooPayments-specific assets remain split and naturally built through the existing WooCommerce workflow.

### Implementation Takeover Notes

The frontend worker landed the Reports route, data/query helpers, and an initial page using `@wordpress/dataviews/wp` for both the Balance summary rows and Fees table. That is a natural fit: the reference Reports surface already uses DataViews, and the native route seam should use WordPress-owned table controls without forcing the old WooPayments visual details.

The main contract gap found during takeover is Fees response shape. The native REST controller intentionally returns the reference-compatible Fees list as a plain array, while the partial frontend expected a wrapped `{ data, total_count }` shape. The frontend should accept the plain array and derive pagination counts from the summary endpoint, rather than changing the backend to a non-reference list envelope just for the native client.

The route registration also needs a small source-order cleanup: `/woopayments/reports` should sit next to Transactions in `routes.tsx` instead of being appended after Documents and relying only on the `order` field to appear in the right place.

### Contract Constraints From Supervisor/BC Map

The spec-mapping subagent returned a read-only contract pass with these A4w obligations:

- Reports remains a WooPayments provider admin surface behind the WooPayments seam; it should not be promoted into generic WooCommerce Analytics or generic reports.
- Merchant-visible parity covers function, visuals, grouping, copy, controls, loading/error/empty states, tooltips, and route reachability.
- Native owned routes target Settings > Payments `/woopayments/*`; legacy `/payments/*` routes are compatibility aliases only.
- Tracks parity is required for surviving Reports events, including event names, trigger frequency, and frozen props where applicable.
- REST route shape is BC only where externally consumed; bundled-client-only REST can be adapted when the client updates in lockstep and merchant behavior is preserved.
- No WPCOM/Transact server work, no standalone plugin/reference changes, no Stripe Billing revival, and no multi-currency domain work beyond preserving Reports data the surface already reads.
- Required A4w evidence includes a staging-log entry, source/test gate details, Playwriter browser proof on reference `:8082` and native `:8889`, console/log/failed-response checks, updated A4 admin-surface gate JSON with route/chunk and raw/gzip bundle measurements, BC disposition for touched Reports hooks/routes/Tracks rows, and admin perf/bundle evidence.

### Implemented Native Reports Surface

Native now exposes Reports as a WooPayments-owned Settings > Payments provider sub-route, not as a generic WooCommerce Analytics page. The backend adds `WooPaymentsAccountService::is_reports_enabled()`, core-owned shared settings for `wcSettings.admin.woopaymentsSettings.featureFlags.reportsArea`, Reports menu/legacy redirect support, `WooPaymentsReportingBalanceSummaryRequest`, `WooPaymentsApiClient::get_reporting_balance_summary()`, and `WooPaymentsReportsRestController` routes for Balance, Fees list, Fees summary, Fees export start, and Fees export polling.

The frontend registers `/woopayments/reports` in the existing WooPayments provider route seam and lazy-loads the dedicated `settings-payments-woopayments-reports` chunk. DataViews is used where it naturally fits: Balance summary rows and date filtering, and the Fees searchable/filterable table. The implementation keeps the visible Reports shell, Balance/Fees tabs, date range, print/export controls, search, Fees columns, route query synchronization, live status regions, and WooPayments Tracks events in the native chunk.

### Review Fixes Closed

The a11y review found Balance focus loss on reload and conditionally mounted export live regions. The implementation now preserves the visible controls during non-initial reloads, passes DataViews loading state, keeps export status live regions mounted, and adds regressions for focus retention and live-region presence.

The API-contract review found legacy `/payments/reports&tab=fees` deep links could clobber Settings `tab=checkout`, and Fees search no longer mapped `Order #...`/`Subscription #...` search tokens to charge IDs. The legacy redirect now maps `tab` to `report_tab`, and the Reports controller reuses `WooPaymentsMoneyMovementOrderService::map_transaction_search_params()` for list, summary, and export filters.

The performance review found disabled Reports still read/refreshed account data, Fees export bypassed the shared download/polling defaults, and direct disabled Reports routes still imported the Reports chunk. The account-service flag check now short-circuits before account reads, Reports export uses `runWooPaymentsExport()` defaults, and a lightweight route guard renders unavailable state without importing the Reports chunk when the core-owned shared flag is false.

### Verification Evidence

Focused PHP passed: `WooPaymentsAccountServiceTest|WooPaymentsAdminNavigationControllerTest|WooPaymentsApiClientTest|WooPaymentsReportsRestControllerTest` with 145 tests and 829 assertions. Focused admin Jest passed for routes, Reports page, Reports data/query, and money-movement export with 5 suites and 34 tests. Syntax passed for changed PHP files; targeted admin ESLint passed; `@woocommerce/admin-library ts:check` passed; targeted Stylelint passed for Reports SCSS; `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` passed; PHPStan passed for touched WooPayments production PHP; `pnpm --filter=@woocommerce/plugin-woocommerce build:admin` passed with existing unrelated webpack cache serialization warnings.

The A4 admin surface harness passed and wrote `data/a4w-admin-surface-gate-green.json`. Native bundle measurements in that run: settings `10632` raw / `2997` gzip bytes, overview `46511` raw / `12007` gzip bytes, payouts `9398` raw / `3131` gzip bytes, money-movement `29150` raw / `8015` gzip bytes, card-readers `2593` raw / `1042` gzip bytes, capital `11445` raw / `3469` gzip bytes, documents `21149` raw / `6941` gzip bytes, and reports `25984` raw / `7856` gzip bytes.

Playwriter native browser proof loaded `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Freports&report_tab=fees&a4w_after_perf=1`, confirmed the Reports shell and Fees DataViews table, and captured the dedicated Reports chunk plus `/wc/v3/payments/reports/fees` and `/fees/summary` returning 200 with `user_timezone=%2B03%3A00`. The native page exposed `wcSettings.admin.woopaymentsSettings.featureFlags.reportsArea: true`. A temporary local option flip to `_wcpay_feature_reports_area=0` proved the direct route rendered `Reports are unavailable.`, exposed `reportsArea: false`, and made no Reports chunk or Reports REST requests; the option was restored to `1` afterward.

Browser console output contained only `JQMIGRATE` and Chrome's existing `Permissions policy violation: unload is not allowed in this document.` item. The target `debug.log` mtime stayed at `2026-06-19 13:53 UTC`, before the final browser window; no new Reports PHP notices, warnings, fatals, or parse errors were written. The repeated `_load_textdomain_just_in_time` notices in the log predate this browser verification and remain broader environment/admin bootstrap noise, not a Reports-specific regression.

### Residual Status

A4w is implemented and verified in the worktree, but it is not committed. A4/N12 remains open for the broader reopened A4 feature-parity follow-up and final A4 exit gate; native admin readiness stays fail-closed until that stage boundary passes. WPCOM remained read-only/off-limits, no WPCOM sandbox access occurred, no WPCOM code changes were made, and Stripe CLI was not used for this slice.
