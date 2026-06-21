---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 14:11
last_updated: 2026-06-18 14:12
target: A4d native WooPayments money movement admin surfaces
reconciles:
  - analysis-a4-native-woopayments-admin.md
  - analysis-a4c-overview-payouts.md
  - ../2026-06-18-a4-woopayments-admin-slice/analysis.md
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-17-1311.md
status: draft
---

# A4d Native WooPayments Money Movement Analysis

## Trigger

A4a made the WooPayments provider settings section real, A4b routed provider subpages under WooCommerce > Settings > Payments, and A4c added overview plus payouts. The next admin parity gap is the reference WooPayments money-movement cluster: transactions, payment details, disputes, and dispute challenge. This cluster is higher leverage than reports/card readers because it covers merchant inspection of real money movement and dispute response workflows.

## Reference Shape

The reference WooPayments client groups `/payments/transactions`, `/payments/transactions/details`, `/payments/disputes`, `/payments/disputes/details`, and `/payments/disputes/challenge` into one `wcpay-money-movement` lazy chunk. The comment in `client/index.js` describes these routes as a tight navigation triangle from list to details to challenge and back.

Reference transaction REST routes live in `includes/admin/class-wc-rest-payments-transactions-controller.php` under `/wc/v3/payments/transactions`. They include list, summary, export creation, export URL, search autocomplete, and fraud-outcome list/summary/search/download endpoints. The list path goes through `WCPay\Core\Server\Request\List_Transactions`, whose hook is `wcpay_list_transactions_request`, default sort is `date desc`, and request mapping preserves filters such as `match`, date filters adjusted for `user_timezone`, `type_is`, `type_is_not`, `type_is_in`, `source_device_is`, `channel_is`, `customer_country_is`, `risk_level_is`, `store_currency_is`, `customer_currency_is`, `source_is`, `loan_id_is`, `search`, and `deposit_id`.

Reference dispute REST routes live in `includes/admin/class-wc-rest-payments-disputes-controller.php` under `/wc/v3/payments/disputes`. They include list, summary, export creation, export URL, detail, update, and close. The list path goes through `WCPay\Core\Server\Request\List_Disputes`, whose hook is `wcpay_list_disputes_request`; it maps `store_currency_is` to platform-facing `currency_is`, date filters to `created_*`, plus `match`, `search`, `status_is`, and `status_is_not`.

Payment details is not a pure transaction endpoint. Reference detail routes also use `/wc/v3/payments/payment_intents/{id}`, `/wc/v3/payments/charges/{id}`, `/wc/v3/payments/charges/order/{order_id}`, and `/wc/v3/payments/timeline/{id}`. Native Core already has API-client methods for a transaction, charge, dispute summary, payment intention, and timeline, but it does not yet expose the admin REST controllers for the money-movement frontend.

An explorer subagent cross-check independently reached the same slice recommendation and added useful detail. The reference transaction route family also includes authorizations, refund actions, fraud-outcome transaction routes, and transaction detail handling for `transaction_type=card_reader_fee` through the card-reader fee detail view. The reference dispute challenge route also depends on `/wc/v3/payments/file` and `/wc/v3/payments/file/{id}/details` for evidence uploads/details. Persisted table preference keys and store names are part of the surface contract: `wc/payments/transactions`, `wc/payments/authorizations`, `wc/payments/charges`, `wc/payments/paymentIntents`, `wc/payments/timeline`, `wc/payments/disputes`, `wc/payments/files`, `wc_payments_transactions_hidden_columns`, `wc_payments_transactions_blocked_hidden_columns`, `wc_payments_transactions_uncaptured_hidden_columns`, and `wc_payments_disputes_hidden_columns`. Tracks continuity includes page-view paths such as `payments_transactions`, `payments_transactions_blocked`, dispute row actions, CSV exports, fraud review events, dispute evidence events, and transaction-detail action events for surviving refund/capture/cancel flows.

## Native State

Core currently has provider routes for `/woopayments/overview` and `/woopayments/payouts` only. There are no native transactions, payment-detail, disputes, dispute-detail, or challenge routes. `WooPaymentsApiClient` already has `TRANSACTIONS_API`, `get_transaction()`, `get_charge()`, `get_payment_intention()`, `get_timeline()`, and `get_dispute_summary()`, but it does not yet provide list/summary/export/search methods for transactions or list/summary/export/detail/update/close methods for disputes. `WooPaymentsDepositsRestController` provides a useful local pattern: gated route registration, `manage_woocommerce` permission, API-exception mapping, preserved legacy request filters, and raw response envelopes.

The A4 admin readiness guard must remain false after this slice. Adding these routes should reduce the gap but not claim full admin parity because reports, card readers, documents, and remaining detail action parity are still staged work unless completed inside this slice and verified.

## Design Direction

Implement A4d as a broad money-movement slice, not a narrow table-only slice. The core ownership shape should mirror the reference chunk by adding one native money-movement lazy route group under Settings > Payments provider subroutes: `/woopayments/transactions`, `/woopayments/transactions/details`, `/woopayments/disputes`, `/woopayments/disputes/details`, and `/woopayments/disputes/challenge`.

Backend implementation should preserve the `wc/v3/payments/*` REST contract and route through native provider-owned controllers. Transactions and disputes need dedicated controllers instead of folding more behavior into the deposits controller. Request filtering should preserve existing hook names where reference exposes request objects (`wcpay_list_transactions_request`, `wcpay_list_disputes_request`) through small native request classes modeled after `WooPaymentsDepositsListRequest`.

Frontend implementation should keep money-movement UI in a dedicated WooPayments admin chunk. It should not put WooPayments-specific tables, filters, or styling in always-loaded generic Settings Payments code. The route adapter belongs next to the existing `/woopayments/overview` and `/woopayments/payouts` registrations, but page components, data helpers, filters, tables, detail panels, and styles belong under `client/admin/client/woopayments/admin/money-movement/`.

Challenge/update/close flows are money-safety and dispute-safety sensitive. If implemented in this slice, they need parity tests and browser checks. If they cannot be completed with quality in the same implementation package, the native challenge route must fail closed in an explicitly tracked follow-up and the admin readiness flag must remain false. It must not silently look complete while destructive actions are missing.

## Verification Shape

The deterministic base gate should cover PHP API-client and REST-controller contracts; frontend route registration, endpoint paths, loading/error/empty states, links, and preserved Tracks names; and focused accessibility behavior for tables, actions, and live regions. Browser checks must use Playwriter, compare target/reference for list and detail routes where data exists, and inspect network failures and logs. The restored harness remains mandatory even if it does not directly drive admin money-movement screens, because these are merchant money surfaces and prior harness gaps caused false confidence.

Subagent use should be deliberate: backend REST/API contracts and frontend route/page implementation have mostly disjoint write sets and can use implementor subagents if the plan provides strict ownership boundaries. Review subagents should cover API contract, architecture/integration, accessibility, reliability, and code quality before the slice is committed.
