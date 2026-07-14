# MD-04 — Losing dispute · DETERMINISTIC

Guards the dispute-lost lifecycle tail: when the merchant loses, the dispute must close as `lost`, the chargeback must stand (disputed amount plus dispute fee deducted, reflected in the order and transaction records), and the order must carry the lifecycle notes recording the loss. The dispute is provider-created deterministically (Test Lab / `pm_card_createDispute` card `4000000000000259`) and lost deterministically by submitting evidence whose `uncategorized_text` contains `losing_evidence` — Stripe test mode closes the dispute as lost via `charge.dispute.closed`. Requires `npm run listen` forwarding from the local Transact Platform; without it the flow is honestly BLOCKED. The implementor's `dispute-e2e-gate.sh` already drives the deterministic dispute, polls the fees-deducted note family and dispute-refund side effects, and financially reconciles against Stripe raw source — corroboration for the assertions below, not a substitute for this suite's own verdict. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account; `npm run listen` running.
- One fresh provider-created dispute per store on a paid order of known amount.

## Layer D — deterministic state assertion

On BOTH stores:

1. Submit evidence with `uncategorized_text = losing_evidence` via the internal REST evidence endpoint (`submit: true`).
2. Poll (bounded) for the closed webhook, then assert the dispute status is `lost` via internal REST.
3. Assert the order notes contain the full lifecycle: dispute-created note, evidence-submitted note, and the lost note in the fees-deducted family ("payment dispute and fees have been deducted").
4. Assert the chargeback stands in store state: the order reflects the lost dispute per the reference store's behavior (refunded/chargeback record — a dispute-reasoned refund entry for the disputed amount, matching `dispute-e2e-gate.sh`'s `has_dispute_refund` probe), and no phantom "funds reinstated" note exists.
5. Assert the transaction ledger records the dispute deduction AND the dispute fee as negative entries for the charge.
6. Compare reference vs target end-state (identical final dispute status, note families, and order/refund state shape); corroborate with the `dispute-e2e-gate.sh` reconciliation artifacts where available.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MD-04-*.sh.
