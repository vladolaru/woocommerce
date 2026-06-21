---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 10:37
target: A4/N12 remaining native WooPayments admin surfaces after A4r
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
last_updated: 2026-06-19 11:16 EEST
---

# A4s Admin Surfaces Next Slice Analysis

## Prompt

> Continue working toward the active core native WooPayments goal after A4r. Use subagents heavily, maintain high throughput, and do not skip verification gates.

## Current Line

A4r is committed locally as `ae1af56302` and `1c3f45283b`. Native admin readiness remains fail-closed. The remaining A4/N12 gaps recorded in staging are embedded Overview/dashboard extras, Reports/Documents native UI/API slices, Payouts/Transactions/Disputes list/detail parity, Card Readers/Capital framing/styling, broader copy/content parity, and the widened final A4 exit gate.

## Working Questions

- Which remaining native admin surfaces form the next broad, coherent implementation slice without mixing unrelated ownership boundaries?
- Which reference components, API routes, stores, styles, and assets define the parity contract for that slice?
- Which native files already exist and which gaps are source-backed rather than inferred from prior notes?
- What can be delegated to implementors or reviewers without overlapping write sets?

## Reports And Documents Disposition

Euler the 4th completed a read-only source map of the WooPayments Reports and Documents surfaces against native Core. The result is a source-backed disposition rather than an implementation slice for A4s: keep Reports and Documents explicitly absent until a real native UI/API port exists. The reference plugin still treats both as supported, feature-gated surfaces, so they should not be labeled deprecated or unsupported; however, native Core currently has no corresponding routes or REST controllers, so exposing links now would create dead or incomplete merchant-facing surfaces.

Reference Reports route and menu ownership live at `/payments/reports` with feature gating through `reportsArea` / `WC_Payments_Features::is_reports_area_enabled()`. The UI includes a Reports shell with Balance and Fees tabs, backed by `/wc/v3/payments/reports/fees`, `/reports/fees/summary`, `/reports/fees/download`, and `/reports/balance`, plus Reports-specific styles and a printable balance logo asset.

Reference Documents route and menu ownership live at `/payments/documents`, gated by cached account `is_documents_enabled` / `featureFlags.documents`. The UI consumes `/wc/v3/payments/documents`, `/documents/summary`, `/documents/{document_id}`, document download URLs, and VAT invoice support at `/payments/vat`, with Documents and VAT styles.

Native Core intentionally omits both today: `WooPaymentsAdminNavigationController` has no legacy redirect mapping or menu entries for Reports/Documents, `WooPaymentsAdminNavigationControllerTest` asserts they stay absent until ported, `routes.tsx` has no Reports/Documents routes, and route inventory tests enforce the current absence. The A4 admin harness also records this as an intentional no-link disposition. No gate update is needed for this slice unless the disposition changes to an actual port.

## Card Readers And Capital Sequencing

Sagan the 4th completed a read-only source map of the Card Readers and Capital/Loans surfaces. These are real A4/N12 parity gaps, but they should not be folded into the next Money Movement slice.

Card Readers native already has provider routes, legacy redirects, frontend data helpers, and backend reader endpoints. The remaining gaps are mostly shell/styling/copy parity: the reference uses a `Page` + `TabPanel` shell with a “Connected readers” tab and `SettingsLayout`/`SettingsSection`/`Card` list presentation, while native renders a simpler bordered section/table. There is one backend parity gap: reference reader registration refreshes account data after registering a reader, while native registration currently does not.

Capital native already has provider routes, legacy redirects, frontend data helpers, and Capital REST endpoints. Its gaps are broader: missing `TestModeNotice currentPage="loans"`, active-loan summary gating drift from `wcpaySettings.accountLoans.has_active_loan`, missing “View transactions” CTA, dropped reference copy for “Repaid this period (until %s)” and “of %s minimum”, weaker date/currency formatting, and an “All loans” table that is not yet a `TableCard` with summaries, sort metadata, chip status, whole-cell click affordances, and pagination behavior.

Capital also depends on Money Movement: its row links preserve `loan_id_is`, but native Transactions ignores URL query params today. Since the backend already supports `loan_id_is`, Money Movement needs URL-driven query/filter behavior first so Capital links are functional when Capital parity lands. The resulting sequence is: A4s Money Movement first, then a Card Readers shell/styling slice, then a Capital/Loans parity slice that builds on Money Movement filters.

## Money Movement Source Map

Kant the 4th completed a read-only source map of the Payouts, Transactions, Disputes, and related detail/challenge routes. The reference plugin registers `/payments/payouts`, `/payments/payouts/details`, `/payments/transactions`, `/payments/transactions/details`, `/payments/disputes`, `/payments/disputes/details`, and `/payments/disputes/challenge`. Native Core owns corresponding Settings > Payments provider routes under `/woopayments/*`, with legacy `/payments/*` limited to compatibility redirects and runtime readiness still fail-closed through the native runtime arbiter.

Native REST coverage is stronger than the current native UI: deposits, transactions, and disputes controllers already exist under `/wc/v3/payments/*`, and the frontend has wrapper modules in `overview/data.ts` and `money-movement/data.ts`. The visible gaps are in the merchant-facing shells rather than basic endpoint registration.

The source-backed gaps are:

- Payouts list: reference has `TableCard`, filters, sorting, pagination, summary totals, CSV export, persisted column visibility, status chips, test/failure/schedule notices, and spotlight promotion hooks. Native currently renders a fixed first-page simple table.
- Payout detail: reference shows payout/withdrawal overview, failed payout banner, copyable bank reference, and an embedded transaction list filtered by payout. Native currently shows a definition list and aggregate summary.
- Transactions list: reference has Transactions/Uncaptured/Blocked tabs, rich columns, filters, search/autocomplete, exports, summaries, hidden-column preferences, payment method/channel/risk/deposit visuals, and query-driven state. Native currently renders a fixed first-page simple table.
- Transaction detail: reference payment details include payment summary, method, order/customer/subscription context, refund/capture/fraud/dispute sections, recommendations, and timeline. Native currently shows only ID/type/date/amount. Some of these are likely API/backend follow-up work rather than safe frontend-only parity.
- Disputes list: reference uses `TableCard`, filters/export/summary, status chips, smart due-date copy, and action links that lead through transaction details. Native currently renders a simple table and sends actionable disputes directly to challenge.
- Dispute details redirect: reference shows loading/error states while redirecting from legacy dispute detail to transaction detail. Native has a redirect component with no visible loading/error UI.
- Dispute challenge: reference has a stepped/accordion evidence flow with confirmation, recommended document matrix, richer customer/product/shipping sections, cover-letter generation, and exact WooPayments copy. Native has a flatter fieldset-based form with upload, save draft, and submit support.
- Dispute filter API alignment needs care: reference filters use date/status/search/store-currency style params, while native exposes `currency_is` and `created_*` params. This should be reconciled against native controller schemas before porting filter UI wholesale.

The next implementation should therefore be A4s Money Movement list/query parity first, using the existing native REST contract and explicitly preserving fail-closed readiness. Detail/challenge parity should be included only where native endpoints already support the behavior cleanly; endpoint gaps should be tracked rather than papered over in the frontend.

## DataViews Consideration

The user suggested considering the WordPress DataViews component for tables if it naturally fits. Local source verification shows `@wordpress/dataviews` is available in the WooCommerce admin package (`^4.17.0`) and is intentionally bundled with admin assets. Existing WooCommerce usage appears in Settings Email via `@wordpress/dataviews/wp`, and the experimental products app uses DataViews for table/search/filter/view-config/pagination shells.

DataViews is technically a good candidate because it leaves server-query translation to the consumer, which matches the Settings > Payments provider route constraints. It also gives accessible search, filters, sorting, pagination, view config, and field hiding without recreating generic table controls. The caution is visual and behavioral parity: WooPayments reference tables have summaries, export actions, notices, status chips, row-specific routing, and money-movement-specific copy around the table. A4s should therefore prefer DataViews only where it owns the list shell naturally, while keeping summaries/exports/notices as surrounding provider UI. It should not be forced into pixel-level `TableCard` mimicry, and if it fights the reference UX the fallback is a scoped semantic table shell with the reason recorded.

## Native Query Support Baseline

Noether the 4th source-verified the native REST query contract. Safe A4s list controls are: payout/transaction/dispute pagination and sorting; `store_currency_is`; date filters with `date_before`, `date_after`, and `date_between`; payout/dispute `status_is` and `status_is_not`; transaction `type_is`, `type_is_not`, `type_is_in`, `loan_id_is`, `deposit_id`, `search`, and related source/channel/country/risk filters; dispute `search`.

The native contract has important mismatches from some intuitive UI names. Payouts do not support `search`, `currency_is`, `type_is`, `created_*`, `deposit_id`, or `loan_id_is`. Transactions do not support `status_is`, `status_is_not`, `currency_is`, or `created_*`; `search_term` is only for `/transactions/search`, not the list. Disputes accept `store_currency_is` and `date_*` at the native REST boundary and map them to platform `currency_is` and `created_*`; the UI should not send `currency_is` or `created_*` directly. Fraud outcomes/blocked have native backend support with `status`, page/sort/search, and `additional_status`, but need frontend wrappers. Uncaptured/authorizations do not have native REST list/detail/summary routes and should stay blocked/follow-up rather than exposing an inert tab.

Summary endpoints exist for deposits, transactions, and disputes, plus fraud outcomes. Export request and export URL endpoints exist for deposits, transactions, and disputes; deposits now has frontend wrappers, while transactions/disputes still need export URL polling wrappers if this slice implements full auto-download behavior. No generic native polling hook was found.
