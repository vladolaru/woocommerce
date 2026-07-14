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
4. Open Payments → Disputes (reference `admin.php?page=wc-admin&path=/payments/disputes`, target `.../path=/woopayments/disputes`). **Confirm the new dispute is listed as needs-response** with the correct amount and reason, and **the dispute-status counts/badge reflect the new open dispute**.
5. End state: dispute open and unanswered; order on-hold with the dispute note on both stores.

## Layer D — deterministic state assertion

- Assert the order status is `on-hold` and its notes contain the dispute-created note family.
- Assert the dispute exists via internal REST with status `needs_response`, correct amount/currency, and a charge reference matching the order's `_charge_id`.
- Assert the disputes list summary/status counts include the new dispute.
- Compare reference vs target end-state; corroborate against the `dispute-e2e-gate.sh` rollup where available.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MD-01-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
