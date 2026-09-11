# MD-03 — Winning dispute · DETERMINISTIC

Guards the dispute-won lifecycle tail: when the merchant wins, the dispute must close as `won`, the withheld funds must be reinstated, and the order must carry the lifecycle notes recording it. The dispute is provider-created deterministically (Test Lab / `pm_card_createDispute` card `4000000000000259`) and won deterministically by submitting evidence whose `uncategorized_text` contains `winning_evidence` — Stripe test mode then closes the dispute as won via `charge.dispute.closed`. Requires the retained `wpcom-local transact listen` forwarding process and automatic local WPCOM jobs; without them the flow is honestly BLOCKED before fixture creation. The implementor's `dispute-e2e-gate.sh` already drives a deterministic dispute and financially reconciles the order against Stripe raw source — corroboration for the assertions below, not a substitute for this suite's own verdict. No browser layer is required for this flow.

## Fixtures (both stores)

- Connected Stripe test account; English site locale; retained `wpcom-local transact listen` process and automatic WPCOM jobs running.
- One fresh provider-created dispute per store on a paid order of known amount (do not reuse MD-02's draft-evidence dispute).

## Layer D — deterministic state assertion

On BOTH stores:

1. Submit evidence with `uncategorized_text = winning_evidence` via the internal REST evidence endpoint (`submit: true`).
2. Poll (bounded) for the closed webhook to land, then assert the dispute status is `won` via internal REST.
3. Assert the order contains the full lifecycle: dispute-created note, the WPCOM `__evidence_submitted_at` marker together with the merchant-visible dispute-updated note, and the won note in the funds-reinstated family ("payment dispute funds have been reinstated").
4. Assert funds reinstatement is reflected in transactions: a dispute-reversal/reinstatement entry for the disputed amount exists and the net effect of dispute + reversal on the account balance is zero for the charge amount (dispute fee handling recorded as observed, per store).
5. Assert the order does not remain stuck `on-hold` contrary to the reference store's post-won status — reference behavior is the oracle for the final order status.
6. Compare reference vs target end-state; corroborate with the `dispute-e2e-gate.sh` reconciliation artifacts where available.

## Wired exerciser and evidence boundary

`MD-03-winning-dispute.sh` selects the immutable `won` profile and delegates to the shared non-replayable resolution contract. The gate resolves the store's exact connected Stripe account and scopes every provider read to it, creates one fresh provider-backed dispute, binds the exact order/charge/payment-intent/dispute identity, durably records `evidence_submit_armed`, and sends exactly one authenticated internal REST submission through the allowlisted WP-CLI adapter. After a trusted success, the harness never retries submission; unavailable provider/store observations or a bounded terminal-delivery timeout are `BLOCKED`, while reachable contract contradictions are `FAIL`.

Each store writes its own packet, normalized debug-log scan, execution artifact, and context-bound manifest under the current run archive. Target refuses to mutate until the exact same-run reference manifest revalidates as `PASS`, then recomputes the comparison from the current bound reference and target packets. Raw Stripe/REST responses, arbitrary metadata, free-form notes, customer data, and error bodies are not retained. `run.sh` independently revalidates every artifact, byte/payload digest, context HMAC, store, outcome, run, status, and exit code before recording the manifest as evidence.
