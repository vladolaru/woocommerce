# MD-01 — Dispute created: note + on-hold + notify · HYBRID (A + D)

Guards the dispute-created lifecycle entry point: when the provider raises a dispute against a paid order, the order must transition to **on-hold**, a merchant-facing dispute note must land on the order, and the merchant must be notified (Disputes list shows the new needs-response dispute and the admin badge/dispute-status counts increment). The dispute is created deterministically — a checkout with the dispute-triggering test card `4000000000000259` (`pm_card_createDispute`), or the WCPay Dev Tools Test Lab dispute generator — with `npm run listen` forwarding `charge.dispute.created` from the local Transact Platform; without webhook forwarding this flow is honestly BLOCKED. The implementor's `dispute-e2e-gate.sh` (tools/woopayments-merge) already drives this deterministic dispute and polls the on-hold/note side effects on both stores — its verdict is corroboration for Layer D here, not a substitute for this suite's own assertions.

## Fixtures (both stores)

- Connected test account; `npm run listen` running from the local Transact Platform root.
- One order per store paid via card `4000000000000259` (provider auto-creates the dispute), amount recorded.

## Layer A — agent-driven browser

BOTH stores:

1. Place the dispute-triggering checkout (or seed via Test Lab), then open the order in WP Admin → WooCommerce → Orders.
2. **Confirm the order status is On hold** after the dispute webhook lands (poll/refresh; bounded wait).
3. **Confirm a dispute order note is present** in the merchant-facing note family ("Payment has been disputed…" with reason and respond-by context) — not hardcoded-missing, not a generic status note only.
4. Open Payments → Disputes (reference `admin.php?page=wc-admin&path=/payments/disputes`, target `admin.php?page=wc-settings&tab=checkout&path=/woopayments/disputes`). **Confirm the new dispute is listed as needs-response** with the correct amount and reason, and **the dispute-status counts/badge reflect the new open dispute**.
5. End state: dispute open and unanswered; order on-hold with the dispute note on both stores.

## Layer D — deterministic state assertion

- Assert the order status is `on-hold` and its notes contain the dispute-created note family.
- Assert the dispute exists via internal REST with status `needs_response`, correct amount/currency, and a charge reference matching the order's `_charge_id`.
- Assert the disputes list summary/status counts include the new dispute.
- Compare reference vs target end-state; corroborate against the `dispute-e2e-gate.sh` rollup where available.

Deterministic exerciser: `flows/MD-01-created-note-on-hold-notify.sh` drives one exact provider-backed dispute per store, dispatches the real WPCOM payment-success and dispute-created forwarding jobs in semantic order through wpcom-local's manual worker mode, restores automatic jobs on every exit, archives normalized order/dispute/count/reconciliation/delivery evidence, compares the native target with the reference extension, and leaves the current disputes open for MD-02.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Superseded evidence

The 2026-07-19 packet under deterministic run `20260719T183931Z-39549-partial` and Layer A ingestion run `20260719T184130Z-39549-partial` passed both stores, but its browser producer used substring matching for amount and fallback row identity. Independent review tightened amount/count/order/dispute matching and added executable superstring regressions, so that packet cannot support the final PASS claim.

Its context `sha256:98fc1d6dbd8aa38e817a52d57f59cddbf27cbc9c0c81de15b6654bf42c2aa580` and browser payload `sha256:4a52e1829d6f9db6d44890b55c25918fc1ea7530584e9856798419a4c70d5239` remain historical provenance only. A fresh source-bound deterministic + direct-Playwright + Layer A packet is required before this row returns to PASS.

## Verified evidence

PASS on 2026-07-19 through the runner on both stores and both assigned layers. The corrected-source context `sha256:3de9e1c1de65e8f9f90d734765b22a3846cf29e917430caac7c55850cdccb428` binds HEAD `bbc594fe5577e927843925bf78de65d2573ec6c6` and worktree `sha256:bbccf0259c48e7a8e2a91ba14c7d502add47fa75b7205cd1664cedec5321062c`.

Deterministic run `20260719T191058Z-64015-partial` created one provider-originated dispute per store, dispatched each exact WPCOM payment-success job before its exact dispute-created job, and proved the on-hold orders, merchant dispute notes, needs-response list/count state, financial reconciliation, automatic worker restoration, and clean logs. Its reference and target manifests are `sha256:759f49caff7f320546d5c0010d09f0cb4f307394473113a418ca5681db83e210` and `sha256:438ea80e08fb530c85522712e78b00695f738e1956ea3e418f062a28b4b7af02`.

The direct isolated Playwright payload `sha256:7f9ade690952cacdf14329791a6fcdf145bbc0e15d2ff020c2cd35bb35273e48` proves the boundary-exact dispute row, exact amount and badge count, merchant-facing reason, needs-response state, response action, order state, and note on both surfaces. Reference and target browser result digests are `sha256:3d6b0f5382a46042ff644d4fbce73cbf387347b78d380b3f111dc1f500d37ac5` and `sha256:fbc8f9eaee84b67d9d90b21151046b364aeb648f16d69e8f5c385e67305966ae`; failures, blockers, and cleanup failures are empty. Layer A ingestion run `20260719T191257Z-64015-partial` accepted PASS for both stores with zero queued specs and agent result `sha256:456660b7c2f9529d8ef238d7d84d2859ece255440339b9b306b99446a444d4e9`.

Both short-lived browser sessions were destroyed, WPCOM jobs remain automatic, both debug logs are regular files, and no observer artifacts remain. The latest open needs-response disputes are intentionally retained as MD-02 fixtures.
