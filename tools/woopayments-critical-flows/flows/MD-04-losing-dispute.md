# MD-04 — Losing dispute · DETERMINISTIC

Guards the dispute-lost lifecycle tail: when the merchant loses, the dispute must close as `lost`, the chargeback must stand (disputed amount plus dispute fee deducted, reflected in the order and transaction records), and the order must carry the lifecycle notes recording the loss. The dispute is provider-created deterministically (Test Lab / `pm_card_createDispute` card `4000000000000259`) and lost deterministically by submitting evidence whose `uncategorized_text` contains `losing_evidence` — Stripe test mode closes the dispute as lost via `charge.dispute.closed`. Requires the retained `wpcom-local transact listen` forwarding process and automatic local WPCOM jobs; without them the flow is honestly BLOCKED before fixture creation. The implementor's `dispute-e2e-gate.sh` already drives a deterministic dispute and financially reconciles against Stripe raw source — corroboration for the assertions below, not a substitute for this suite's own verdict. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected Stripe test account; English site locale; retained `wpcom-local transact listen` process and automatic WPCOM jobs running.
- One fresh provider-created dispute per store on a paid order of known amount.

## Layer D — deterministic state assertion

On BOTH stores:

1. Submit evidence with `uncategorized_text = losing_evidence` via the internal REST evidence endpoint (`submit: true`).
2. Poll (bounded) for the closed webhook, then assert the dispute status is `lost` via internal REST.
3. Assert the order contains the full lifecycle: dispute-created note, the WPCOM `__evidence_submitted_at` marker together with the merchant-visible dispute-updated note, and the lost note in the fees-deducted family ("payment dispute and fees have been deducted").
4. Assert the chargeback stands in store state: the order reflects the lost dispute per the reference store's behavior (refunded/chargeback record — a dispute-reasoned refund entry for the disputed amount, matching `dispute-e2e-gate.sh`'s `has_dispute_refund` probe), and no phantom "funds reinstated" note exists.
5. Assert the transaction ledger records the dispute deduction AND the dispute fee as negative entries for the charge.
6. Compare reference vs target end-state (identical final dispute status, note families, and order/refund state shape); corroborate with the `dispute-e2e-gate.sh` reconciliation artifacts where available.

## Wired exerciser and evidence boundary

`MD-04-losing-dispute.sh` selects the immutable `lost` profile and delegates to the shared non-replayable resolution contract. The gate resolves the store's exact connected Stripe account and scopes every provider read to it, creates one fresh provider-backed dispute, binds the exact order/charge/payment-intent/dispute identity, durably records `evidence_submit_armed`, and sends exactly one authenticated internal REST submission through the allowlisted WP-CLI adapter. After a trusted success, the harness never retries submission; unavailable provider/store observations or a bounded terminal-delivery timeout are `BLOCKED`, while reachable contract contradictions are `FAIL`.

Each store writes its own packet, normalized debug-log scan, execution artifact, and context-bound manifest under the current run archive. Target refuses to mutate until the exact same-run reference manifest revalidates as `PASS`, then recomputes the comparison from the current bound reference and target packets. Raw Stripe/REST responses, arbitrary metadata, free-form notes, customer data, and error bodies are not retained. `run.sh` independently revalidates every artifact, byte/payload digest, context HMAC, store, outcome, run, status, and exit code before recording the manifest as evidence.
