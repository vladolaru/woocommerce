---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 12:16
target: exp/core-native-payments — A4t Capital and Card Readers parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - implementation-log.md
status: implemented
---

# A4t Capital And Card Readers Parity

## Prompt

> Maybe consider using DataViews component for tables since it is a WP component. But only if it naturally make sense, even if there is a visual departure from the reference - we shouldn't try to force DataViews to look like the reference.

## Source-Backed Slice Choice

The next A4 slice should close Capital and Card Readers parity before Reports/Documents or the deeper authorizations/detail money-movement slice. The reason is not that Reports/Documents are less important; it is that they do not currently have native API/controller contracts, while Capital and Card Readers already have native routes, menu entries, bundles, REST endpoints, and first-pass pages. Leaving them visibly partial keeps A4 in a half-migrated merchant-facing state.

Explorer findings support splitting Reports and Documents into separate backend-first slices. Reports requires feature-flag gating, balance and fees reporting endpoints, export/download contracts, and a substantial frontend. Documents requires account capability gating, document download response behavior, and VAT-data gating. Combining either with this UI parity work would encourage route placeholders instead of source-backed implementation.

The money-movement authorizations/detail parity slice is also important, but it is materially larger: native authorizations list/detail/summary REST proxies, capture/cancel actions, an Uncaptured tab, richer transaction/dispute/payout rows, and a shared detail shell. That should follow as its own broad slice rather than being mixed into Capital/Card Readers.

## Capital Gaps

Native has `GET /wc/v3/payments/capital/active_loan_summary` and `GET /wc/v3/payments/capital/loans`, plus `WooPaymentsCapitalPage`, but the page is currently a minimal section/list. Reference behavior includes a test-mode notice on the loans page, active loan overview with a `View transactions` CTA, loading skeleton shape, loan table summary counts/totals/fixed fees, status chips, and all-cell row links to transactions filtered by `loan_id_is`.

There is also a backend flow gap: reference handles `wcpay-loan-offer` email entry by requesting `accounts/capital_links` with a user token and redirecting to the returned URL, or back to the WooPayments overview with `wcpay-loan-offer-error=1` on failure. Native already has an overview notice for `wcpay-loan-offer-error`, but no source-backed native handler or API-client method for `accounts/capital_links`.

## Card Readers Gaps

Native has a list endpoint and page, but the reference is a settings-style surface: a single Connected readers tab, settings layout/section wrapper, explanatory copy as one paragraph, a card-like list with Reader ID / Model / Status, and active/inactive chips. The native page already has better loading/error/empty announcements than the reference; those should be preserved while the visual shell is brought closer.

## DataViews Decision

DataViews should not be forced into this A4t slice. Capital’s reference table explicitly disables column configuration and renders all loans on one page with only a summary; Card Readers is a static settings list without filtering, pagination, search, or column preferences. A semantic table/card shell gives the right UI and accessibility behavior with less surface area and fewer unexpected controls. DataViews remains the right default for true list-management surfaces such as Transactions, Disputes, Payouts, Reports, Documents, and Uncaptured authorizations when those slices need sorting/filtering/view state.

## Review Disposition

Reference verification corrected the initial Capital-link draft contract: the preserved request hook is `wcpay_get_account_capital_link`, the request object alias is `WCPay\Core\Server\Request\Get_Account_Capital_Link`, and the link type is `capital_financing_offer`. Native now creates `accounts/capital_links` with a user token, appends `test_mode` in the query string to preserve the reference server contract, registers `connect.stripe.com` as an allowed redirect host, and handles the legacy `admin.php?wcpay-loan-offer` entry path with the reference AJAX guard. The first accessibility review also changed the Capital table plan from all-cell links to one explicit `View transactions` action column to avoid repeated same-destination tab stops; that is an intentional native quality correction while preserving route behavior.

## Acceptance Boundary

This slice closes visible parity for the already-native Capital and Card Readers surfaces plus the Capital loan-offer redirect path. It does not claim Reports/Documents parity, Uncaptured authorizations parity, richer transaction details, or final A4 exit readiness.
