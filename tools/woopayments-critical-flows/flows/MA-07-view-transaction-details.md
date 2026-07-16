# MA-07 — View transaction details · HYBRID (A + D)

Guards the transaction details page: every field the plugin shows for a charge (amount, fee, net, payment-method with brand logo, timeline, order link) must be correct and correctly formatted on native. The regression risk is a native details page with wrong/blank fields, a missing method logo (the merchant's fastest visual cue), or a broken link back to the order.

## Fixtures (both stores)

-   Connected test account; one known Visa test-card charge per store (e.g. via a `4242 4242 4242 4242` checkout or SC-01's fixture order) with recorded amount, order ID, and charge/transaction ID.

## Layer A — agent-driven browser

BOTH stores:

1. Open Payments → Transactions and click into the fixture charge (or navigate directly to its details route — reference `admin.php?page=wc-admin&path=/payments/transactions/details&id=<intent>`, target `admin.php?page=wc-settings&tab=checkout&path=/woopayments/transactions/details&id=<intent>&transaction_id=<txn>&transaction_type=charge`).
2. **Confirm the payment-method block renders the card brand logo (Visa) and the last-4 digits** — not a blank icon or raw brand string only.
3. **Confirm amount, fee, and net are each present and currency-formatted**, and that amount − fee = net.
4. **Confirm the timeline/events section renders** at least the authorized/captured events for the charge.
5. **Confirm the associated-order link resolves** to the fixture order's edit screen.
6. End state: read-only — details page fully rendered for the same fixture charge on both stores.

## Layer D — deterministic state assertion

-   Fetch the transaction/charge detail via internal REST for the fixture ID; assert `amount`, `fee`, `net`, `currency`, card `brand = visa`, `last4`, and the order reference all match the fixture ledger.
-   Assert amount − fee = net in the payload itself.
-   Assert the referenced order exists and its `_intent_id`/`_charge_id` meta points back at this transaction (bidirectional integrity).
-   Compare reference vs target: same fields populated for each store's own fixture charge.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-07-\*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Runner-verified result (2026-07-16)

-   **Reference: PASS.** The plugin rendered `$40.00`, `-$1.46`, and `$38.54`; a dedicated accessible Visa logo with last-4 `4242`; the charged/authorized timeline; and an order link that resolved to fixture order `2010`. Pre/post provider and order state was identical.
-   **Target: FAIL — UX.** Native rendered the equivalent `US$40.00`, `-US$1.46`, and `US$38.54`; Visa 4242 text; the charged/authorized timeline; and an order link that resolved to fixture order `1520`. It exposed no Visa image, SVG, accessible icon, or brand-specific class, leaving only raw brand text where the plugin provides the merchant-facing visual cue. Pre/post provider and order state was identical.
-   **Parity: FAIL — UX.** Every ledger, formatting, timeline, order-link, identity, and read-only assertion passes on both stores; only the native Visa brand-logo assertion fails.
-   Authoritative runner archive: `evidence/runs/20260716T082138Z-33267-partial/` (1 PASS / 1 FAIL / 0 BLOCKED / 0 queued; result digest `sha256:55205de827c839ceb0c0fb9b6bfe5c878b1e329278aa6796ff53ece18a66862b`).
-   Maintained status remains `PENDING`: Layer A fails on the target and Layer D is still unwired.
