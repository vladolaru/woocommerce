---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 05:08
tool: source-map
target: H26 N7a provider-created dispute e2e coverage
reconciles:
  - analysis-h25-n7a-dispute-payout-multicurrency.md
  - staging-log.md
  - review-agent-findings.md
last_updated: 2026-06-18 05:52
status: final
---

# H26 N7a Provider-Created Dispute E2E Coverage

## Trigger

H25 implemented native `charge.dispute.*` order side effects, but N7a remains fail-closed because the harness still does not drive provider-created disputes on both stores and the financial comparator still treats dispute state as order-meta-oriented. The latest supervisor/user guidance also says to avoid overstating unreliable measurements: use timing as smoke only and prefer deterministic or structural evidence when deciding whether a gate is trustworthy.

## Source-Backed Findings

The local harness already has the pieces to create an order-backed provider dispute, but they are not exposed as a deterministic dispute gate. `flow-drive.sh` supports deterministic `charge|refund|capture`, while reference deterministic charge maps `--type=dispute` to WCPay Dev Tools `PM_DISPUTE` and native deterministic charge maps `--type=dispute` to Stripe `pm_card_createDispute`. The useful H26 driver should therefore be a deterministic dispute wrapper around an actual charge flow, not the existing non-deterministic `wp wcpay-dev test-lab disputes` path.

The reference WooPayments extension clears dispute database caches after every created, closed, or updated dispute webhook via `Database_Cache::delete_dispute_caches()`, deleting `wcpay_dispute_status_counts_cache`, `wcpay_test_dispute_status_counts_cache`, and `wcpay_active_dispute_cache`. Native H25 handles order notes/status/refunds but has no equivalent invalidation boundary, so merchant dispute-list/count surfaces can stay stale after native webhooks even when the order side effect is correct.

The existing native product path should keep dispute IDs out of order meta. H25 intentionally matched the reference order contract: dispute webhooks mutate status, notes, and local refunds; they do not persist `_dispute_id`. Therefore the N7a comparator must not fail merely because provider disputes exist while WC has no dispute ID meta. It should validate provider dispute presence plus WC side effects when a dispute is expected.

Payout should remain fail-closed rather than be forced into H26. The reference Test Lab can create payouts, but current `flow-drive.sh payout` only emits order-backed rows and drops payout-only results. The native Test Lab payout operation is also explicitly unsupported today. A useful payout gate needs order-anchored instant-balance fixture design before it can honestly back a PASS.

## H26 Scope Recommendation

H26 should combine one small product fix with one broad verification hardening slice: add a WooPayments provider-owned dispute-cache invalidator and call it from native dispute webhook handling, then extend the ignored local harness so deterministic provider-created disputes can be driven on both reference and target, polled against Stripe raw source, and checked against reference-compatible order side effects.

The harness must fail closed. If Stripe never exposes the provider dispute, the store never receives the webhook, or the order does not reach the expected side-effect state, the gate should fail or block with explicit progress output. It should not mask product bugs by rewriting expected order state, fabricating dispute meta, or accepting provider-readable disputes as equivalent to merchant-facing WooCommerce side effects.

## Measurement Stance

No runtime timing claim should be made for H26. The reliable evidence is provider-created dispute objects, webhook-applied order status/notes/refunds, cache-option invalidation, static checks, focused PHP regressions, and raw Stripe reconciliation. Local timings can only be recorded as smoke if a large delta appears while running the broader harness.

## Completion Notes

H26 closed the source-backed dispute-cache invalidation gap and widened the ignored local harness to drive deterministic provider-created disputes on both stores. The final gate uses durable order-history facts for the created-dispute `on-hold` transition, created/update dispute notes, and Stripe raw-source amount/reason matching, while not asserting final current order status because payment/dispute event ordering is asynchronous. Payout linkage and converted-currency money fixtures remain fail-closed N7a gaps.
