---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 04:28
tool: source-map
target: H25 N7a remaining money matrix closure
reconciles:
  - staging-log.md
  - review-agent-findings.md
  - analysis-h24-n7a-auth-capture-drivers.md
last_updated: 2026-06-18 04:50
status: final
---

# H25 N7a Dispute, Payout, and Multi-Currency Money Matrix

## Trigger

N7(a) requires financial reconciliation beyond refunds, including charge amounts, captures/authorizations, full and partial refunds, dispute outcomes, payouts, fees, and multi-currency. H24 closed the auth/capture driver gap and widened local capture verification. This note re-grounds the remaining N7(a) matrix before the next implementation slice.

## Current Source Baseline

Native `WooPaymentsEventIngestor` still lists `charge.dispute.created`, `charge.dispute.closed`, `charge.dispute.updated`, `charge.dispute.funds_withdrawn`, and `charge.dispute.funds_reinstated` in `KNOWN_UNHANDLED_EVENT_TYPES`, and known unhandled event types throw a `RuntimeException` instead of returning success. That is a correct fail-closed baseline while native parity is absent, but it is not dispute-outcome parity with the WooPayments extension.

The reference WooPayments extension handles all five dispute webhook events in `WC_Payments_Webhook_Processing_Service`. The reference order contract is source-backed: dispute events resolve the order via `data.object.charge` against `_charge_id`; `charge.dispute.created` puts the order on hold and adds the dispute or inquiry note; `charge.dispute.updated`, `funds_withdrawn`, and `funds_reinstated` add update notes only; `charge.dispute.closed` fetches a dispute summary and either records a local WC refund for `lost` disputes or completes the order for non-lost closure statuses.

The reference dispute path does not persist dispute-specific order meta. It mutates order status, notes, and local WC refund records. Therefore the current financial reconciler's broad `dispute_id` meta comparison is not a faithful product oracle by itself: a reference-compatible order may have no dispute meta even when a provider dispute exists. H25 needs either product-side native dispute handling plus a better harness assertion for reference side effects, or a documented gate disposition if the feature remains intentionally fail-closed.

Native dispute handling must not be routed through the generic event resolver as-is. The generic resolver looks up `_charge_id` only for `charge.expired`; for other event types it first looks up `_intent_id` using the event object ID, which would be the dispute ID for dispute webhooks. Dispute handling needs an explicit charge-based order resolver.

The current reconciler already reads provider charge, intent, balance transaction, refunds, disputes, payout, fee/net, and exchange-rate fields from the Stripe CLI connected-account source. It now compares charge/capture/refund/fee/net/multi-currency/dispute/payout dimensions, but payout is intentionally weak: provider payout readability passes when WC has no payout id, and dispute comparison is meta-oriented rather than reference-side-effect-oriented.

## H25 Candidate Scope

H25 should take the dispute product gap as the next broad N7(a) slice, because it is source-backed, merchant-facing, and currently blocks native cutover by design. The slice should implement native parity for all five `charge.dispute.*` webhook events together rather than splitting by event name.

The implementation should add a native provider-level dispute side-effect path with explicit charge-id order resolution, reference-compatible notes/status transitions, idempotency by exact note or marker, local WC refund creation for lost disputes, and a native API-client method for dispute summaries if closed/lost parity needs summary amounts. It should keep no-provider-refund behavior for lost disputes because the reference `wc_create_refund()` call is local only.

The harness should be widened to verify dispute side effects honestly. A Stripe/provider dispute existing with no WC dispute meta should not be treated as a product failure by itself if the reference does the same; the gate should assert the actual reference contract: order status, notes, local refund for lost disputes, and raw provider dispute presence.

## Sidecar Findings

Noether's read-only fixture report separates payout and converted-currency coverage from the dispute product gap. Reference/Test Lab can create instant-balance charges and direct Stripe payouts, but the current flow driver cannot emit a clean order-linked payout fixture because deterministic mode excludes payout and non-deterministic payout output has payout fields without order linkage. Target/native cannot currently use the Test Lab payout or checkout simulator paths because WCPay Dev Tools still guards those operations against the Core-native runtime.

The target converted-currency product path appears present from source: native multi-currency runtime ownership exists, selected currency and manual-rate hooks exist, order/refund metadata projection exists, and the native payment adapter uses order total and order currency for provider creation. The current native flow driver creates a direct `wc_create_order()` order without setting selected currency or rates, so it cannot intentionally produce a converted-currency fixture. That is a harness gap rather than a source-backed product gap from this pass.

These sidecars should remain separate unless they become cheap/disjoint after the dispute product slice. Follow-up harness work should add a fail-closed `--require-payout` reconciler mode, deterministic payout fixture orchestration, and native multi-currency fixture flags for selected currency/rate setup.

## Measurement Stance

For N7(c)-adjacent perf evidence, local wall-clock timings are only smoke signals. They can flag big deltas worth investigating, but H25 should not claim performance parity from noisy timing numbers. Stable structural metrics such as query counts, external request counts, bundle presence/size, callback counts, autoload state, and deterministic harness facts are stronger evidence.

## H25 Outcome

H25 implemented the dispute product slice. Native now handles `charge.dispute.created`, `charge.dispute.closed`, `charge.dispute.updated`, `charge.dispute.funds_withdrawn`, and `charge.dispute.funds_reinstated` through a provider-owned dispute handler. The handler resolves orders by `_charge_id`, preserves reference-compatible notes/status transitions, creates local no-provider refunds for lost disputes, avoids dispute-specific order meta, validates required dispute payload fields before side effects, and fails closed if the local lost-dispute refund cannot be created.

The local harness still does not drive provider-created disputes, payouts, or converted-currency money fixtures. The H25 product tests and direct target-store probe verify native dispute side effects; the cross-store harness remains green for the driven charge money path and continues to warn that full dispute/payout/multi-currency coverage requires dedicated flow drivers.
