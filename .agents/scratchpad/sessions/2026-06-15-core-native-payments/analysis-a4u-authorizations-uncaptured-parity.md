---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 13:04
target: exp/core-native-payments — A4u authorizations and uncaptured transaction parity
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4s-admin-surfaces-next-slice.md
  - analysis-a4t-capital-card-readers-parity.md
status: draft
---

# A4u Authorizations And Uncaptured Parity

## Prompt

> Maybe consider using DataViews component for tables since it is a WP component. But only if it naturally make sense, even if there is a visual departure from the reference - we shouldn't try to force DataViews to look like the reference.

## Source-Backed Slice Choice

The next A4 slice should close the Uncaptured authorizations gap on the native Transactions surface. After A4s, native Transactions, Disputes, Payouts, and Payout Details have list/query/export shells, but native still only exposes an authorizations summary for the admin menu badge. The full merchant-facing Uncaptured tab, list/detail API, and capture/cancel actions remain missing, while the reference exposes `GET /wc/v3/payments/authorizations`, `GET /wc/v3/payments/authorizations/summary`, `GET /wc/v3/payments/authorizations/{payment_intent_id}`, `POST /wc/v3/payments/orders/{order_id}/capture_authorization`, and `POST /wc/v3/payments/orders/{order_id}/cancel_authorization`.

This is a money-path and operations surface, so it has higher immediate leverage than Reports/Documents. Reports are completely absent in native and should be a separate slice because they need balance/fees reporting contracts and report-specific exports. Documents are riskier still because they include binary response streaming, VAT submission/account refresh behavior, and document-download Tracks parity. Combining those with authorizations would make the slice too broad and would invite placeholders.

## Native State

Native already has `WooPaymentsApiClient::get_authorizations_summary()` for the badge. Native also has payment lifecycle capture/cancel support below the admin surface through `PaymentProcessingService` and `WooPaymentsProviderGatewayAdapter`, plus order-service logic around manual-capture orders. What is absent is the admin REST/UI contract: no `/wc/v3/payments/authorizations` collection/detail endpoints, no capture/cancel admin mutations matching the reference route shape, no Uncaptured tab under the Transactions route, no authorization row types, and no action-state/error mapping in the money-movement frontend.

The existing native money-movement frontend in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement` already owns the right list infrastructure: URL query serialization, DataViews presentation state, export polling, notices, and view preferences. Authorizations should reuse that infrastructure where it naturally fits rather than introducing a second list stack.

## Reference Contract

Reference Uncaptured authorizations live as a Transactions tab, not a separate top-level menu item. The reference data store maps `capture_by` sorting to `created`, lists authorizations through `/wc/v3/payments/authorizations`, reads detail through `/wc/v3/payments/authorizations/{payment_intent_id}`, and submits actions through order-scoped routes. Capture/cancel responses update authorization state, invalidate authorizations summary/list and related transaction/timeline/payment-intent stores, and show success or specific failure notices. The reference action error mapping includes missing order, refunded uncapturable orders, order/intent mismatch, uncapturable payment, amount-too-small capture failures, cancel failures, and generic server failures.

The reference list columns include authorized date, capture-by date, order, risk, amount, customer email/country, and a capture action. Native can keep a Core/WP DataViews visual departure if the table remains accessible and operationally equivalent.

## DataViews Decision

DataViews naturally fits this slice. Uncaptured authorizations are a queryable list with sorting, pagination, search/filter state, row actions, and durable presentation preferences. Unlike A4t Capital/Card Readers, this is a real list-management surface. The implementation should add an authorizations DataViews configuration that reuses the A4s table wrapper and query utilities, while keeping WooPayments-specific API semantics, action handling, and notices in WooPayments-owned modules.

## Architecture Boundary

Add native authorizations REST as a provider-owned controller behind `NativePaymentsRuntimeArbiter`. The controller should use the existing `WooPaymentsApiClient` transport and the existing native capture/cancel services instead of reviving plugin gateway ownership. The action routes should preserve the reference REST paths for API compatibility while internally using native provider/payment-processing abstractions, with clear error mapping and no silent success on unsupported order/payment states.

This slice should include the minimal shared frontend action/detail foundation needed for the Uncaptured tab, but it should not attempt full transaction/dispute/payout detail parity, the Blocked fraud tab, timeline parity, or charge-from-order detail fallbacks. Those remain follow-up A4 slices.

## Follow-Up Disposition

Reports native UI/API remains open as a separate A4 slice. Documents native UI/API remains open as a separate A4 slice with stricter download/VAT gating. Full money-movement detail polish remains open after A4u: transaction detail breakdowns, dispute detail parity, payout detail parity, timeline, payment-method details, order/customer context, and Blocked tab parity. The final widened A4/N12 exit gate remains fail-closed until those are dispositioned and source/browser verified.
