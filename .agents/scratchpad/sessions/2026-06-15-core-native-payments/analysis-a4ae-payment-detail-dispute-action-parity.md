---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 03:16
last_updated: 2026-06-20 04:03
reconciles:
  - analysis-a4ad-next-parity-slice.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - spec-conformance-baseline.md
status: final
---

# A4ae Payment Detail and Dispute Action Parity

> **Prompt:** "ok. continue"

## Baseline

A4ad closed direct-route/account-state availability for native WooPayments admin routes, but the recorded caveat remains explicit: payment-detail/dispute-action parity and WooPay/settings residual parity are still open. The higher-risk next slice is the payment-detail/dispute decision layer because it sits on merchant dispute response choices and money-adjacent actions.

Native admin readiness remains fail-closed. A4ae must improve parity without treating the final N12 visual/copy/detail gate as passed.

## Source Findings

`plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` currently resolves `pi_*`, `ch_*`/`py_*`, and `txn_*` identifiers, normalizes basic charge/intent fields, and renders a compact details list plus timeline. It does not render a dispute decision block, a dispute status/action summary, accept-dispute controls, status-specific resolution copy, or detail-level Tracks events for dispute choices.

`plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx` currently routes actionable dispute rows directly to `/woopayments/disputes/challenge`. The reference sends the merchant through transaction details first for urgent context, issuer/customer/payment summary, accept/refund/challenge choices, and compliance caveats before evidence entry.

`plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts` already exposes `closeWooPaymentsDispute()`, and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputesRestController.php` already logs and proxies close-dispute mutations through `/wc/v3/payments/disputes/:id/close`. The missing gap is native UI usage, not backend route availability.

Reference source confirms the intended decision layer. `client/payment-details/summary/index.tsx` renders dispute details under the payment summary, choosing `DisputeAwaitingResponseDetails` for awaiting-response statuses and `DisputeResolutionFooter` for resolved/under-review statuses. `client/payment-details/dispute-details/dispute-awaiting-response-details.tsx` provides challenge/submit-evidence, accept-dispute, inquiry refund entry, help links, staged-evidence continuation, Visa compliance acknowledgement, and defensive disabled states. `client/payment-details/dispute-details/dispute-resolution-footer.tsx` renders status-specific copy for under-review, won, lost, warning-under-review, and warning-closed disputes.

Native tests already cover the current gap. `money-movement-pages.test.tsx` asserts actionable dispute rows go directly to challenge, and transaction-detail tests only assert the current compact detail/timeline behavior. A4ae can TDD the route change and the transaction detail dispute decision block in the existing suite.

## Scope Decision

A4ae should make native transaction details the dispute decision hub for the parts that have a clean native backend contract today: actionable dispute rows deep-link to transaction details; transaction details renders dispute context/actions when a charge or payment intent carries `dispute`; merchants can challenge/continue via the existing native challenge route; merchants can accept a non-inquiry dispute through the existing close-dispute endpoint with success/error notices; resolved and under-review disputes show status-specific guidance and submitted-evidence links.

A4ae should not half-port refund modal behavior or detail-level capture/cancel behavior. Refund and capture actions are real money-moving UX. The reference has broader surrounding machinery (`RefundModal`, partial-refund order handoff, authorization fetch/notice, fraud approve/block copy, and event tracking). Native has list-level authorization actions and backend refund processing elsewhere, but no native detail refund modal seam in this route. Those remain tracked A4 follow-ups unless the slice expands with full TDD, browser, and money-safety verification.

## Implementation Units

Add a small dispute-detail component module beside the money-movement page rather than growing `transaction-details-page.tsx` further. The transaction page should keep responsibility for loading/normalizing detail data and pass a normalized dispute plus charge context into the component.

Extend `types.ts` for the raw charge/payment-intent fields the component needs: `dispute`, `amount_refunded`, `refunded`, `captured`, `balance_transaction.currency`, `application_fee_amount`, `balance_transactions`, `issuer_evidence`, and dispute metadata keys. Keep the types permissive because platform responses vary and native should fail soft in display-only contexts.

Extend the page data mock in `money-movement-pages.test.tsx` to include `closeWooPaymentsDispute`, and add RED tests before production edits for: actionable dispute rows route to transaction details; awaiting-response dispute details render `Dispute details`, `Challenge dispute`, and `Accept dispute`; accepting opens a confirmation modal and calls the close endpoint; close failures surface an error notice; warning inquiries render `Submit evidence` and `Issue refund` as a disabled/deferred explanatory action rather than silently omitting context; resolved disputes render status-specific guidance and a submitted-evidence link where appropriate.

## Verification Plan

Focused Jest is the primary TDD gate for this frontend slice. After implementation, run the focused money-movement page tests and the data helper tests if the data mock/import changes touch API helpers. Run targeted ESLint for touched TS/TSX files, admin `ts:check`, and `git diff --check -- . ':!.agents'`.

Browser proof should use Playwriter against the target store and, where available, a real dispute/payment detail route from the local data. If the current account lacks a suitable actionable dispute, record that honestly and use local API/store probes plus mocked Jest coverage for action rendering, then still load the native money detail route to verify no failed responses, console errors, page errors, or debug-log entries.

The A4/N12 final gate remains open after A4ae. Remaining source-backed follow-ups include detail-level refund/capture/fraud-review parity and WooPay/settings residual parity.

## Closeout

A4ae is implemented and locally verified. Actionable dispute rows now route to transaction details with an accessible `Respond now ... from transaction details` label, and transaction details now preserves dispute fields from charge/payment-intent responses and renders a focused dispute decision section before the timeline. The decision section covers awaiting-response disputes, warning inquiries, accept-dispute confirmation, close-endpoint success/error notices, resolved/under-review copy, and submitted-evidence links.

The backend reliability fix is intentionally narrow: `WooPaymentsDisputesRestController::close_dispute()` deletes stale dispute list/count caches only after the platform close succeeds, and keeps them if the platform call fails. This avoids leaving the merchant on stale actionable-dispute counts after accepting a dispute while preserving failure visibility.

Review findings were source-backed and fixed. A11y review found two async focus edge cases around dismissing the accept modal while close was pending; both now have RED/GREEN regressions. The normal success path focuses the persistent `Dispute details` heading, a merchant who moved focus outside the dispute flow is not pulled back, and focus lost to `body` or still inside the dispute action area is restored to the heading after the action UI is replaced. Reliability and general code review approved with no findings after the cache-invalidation fix.

Final local evidence: focused Jest passed with 29 tests, focused PHP passed with 34 tests and 219 assertions, targeted ESLint passed, admin type lint passed, admin style lint passed, PHP syntax passed, changed-file PHPCS passed, PHPStan passed for the disputes controller, admin bundle build passed with only existing unrelated webpack cache warnings, Playwriter loaded the target disputes list and transaction detail decision hub and opened/cancelled the accept modal without mutating dispute state, browser console logs were clean, target Docker PHP log scans were clean, changelog validation passed with existing PHP 8.4 vendor deprecation noise, `git diff --check -- . ':!.agents'` passed, and branch lint passed with existing broad ignored-file JS warnings only. A4ae is committed locally as `9232b9d5eb` plus changelog `0ca1d25347`, with git range `0c3f94350c...0ca1d25347`.

This does not complete the full A4/N12 admin parity gate. Refund modal parity, detail-level capture/cancel/fraud-review actions, broader payment-detail visual/copy comparison, and WooPay/settings residual parity remain outside A4ae and should be handled as later reopened-A4 work. Native admin readiness remains fail-closed.
