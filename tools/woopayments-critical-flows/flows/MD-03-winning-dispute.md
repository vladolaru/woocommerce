# MD-03 — Winning dispute · DETERMINISTIC

Guards the dispute-won lifecycle tail: when the merchant wins, the dispute must close as `won`, the withheld funds must be reinstated, and the order must carry the lifecycle notes recording it. The dispute is provider-created deterministically (Test Lab / `pm_card_createDispute` card `4000000000000259`) and won deterministically by submitting evidence whose `uncategorized_text` contains `winning_evidence` — Stripe test mode then closes the dispute as won via `charge.dispute.closed`. Requires `npm run listen` forwarding from the local Transact Platform; without it the flow is honestly BLOCKED. The implementor's `dispute-e2e-gate.sh` already drives the deterministic dispute, polls the funds-reinstated note family, and financially reconciles the order against Stripe raw source — corroboration for the assertions below, not a substitute for this suite's own verdict. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected test account; `npm run listen` running.
- One fresh provider-created dispute per store on a paid order of known amount (do not reuse MD-02's draft-evidence dispute).

## Layer D — deterministic state assertion

On BOTH stores:

1. Submit evidence with `uncategorized_text = winning_evidence` via the internal REST evidence endpoint (`submit: true`).
2. Poll (bounded) for the closed webhook to land, then assert the dispute status is `won` via internal REST.
3. Assert the order notes contain the full lifecycle: dispute-created note, evidence-submitted/under-review note, and the won note in the funds-reinstated family ("payment dispute funds have been reinstated").
4. Assert funds reinstatement is reflected in transactions: a dispute-reversal/reinstatement entry for the disputed amount exists and the net effect of dispute + reversal on the account balance is zero for the charge amount (dispute fee handling recorded as observed, per store).
5. Assert the order does not remain stuck `on-hold` contrary to the reference store's post-won status — reference behavior is the oracle for the final order status.
6. Compare reference vs target end-state; corroborate with the `dispute-e2e-gate.sh` reconciliation artifacts where available.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MD-03-*.sh.
