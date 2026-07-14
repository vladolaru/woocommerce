# MA-07 — View transaction details · HYBRID (A + D)

Guards the transaction details page: every field the plugin shows for a charge (amount, fee, net, payment-method with brand logo, timeline, order link) must be correct and correctly formatted on native. The regression risk is a native details page with wrong/blank fields, a missing method logo (the merchant's fastest visual cue), or a broken link back to the order.

## Fixtures (both stores)

- Connected test account; one known Visa test-card charge per store (e.g. via a `4242 4242 4242 4242` checkout or SC-01's fixture order) with recorded amount, order ID, and charge/transaction ID.

## Layer A — agent-driven browser

BOTH stores:

1. Open Payments → Transactions and click into the fixture charge (or navigate directly to its details route — reference `admin.php?page=wc-admin&path=/payments/transactions/details&id=<txn>`, target `.../path=/woopayments/transactions/details&id=<txn>`).
2. **Confirm the payment-method block renders the card brand logo (Visa) and the last-4 digits** — not a blank icon or raw brand string only.
3. **Confirm amount, fee, and net are each present and currency-formatted**, and that amount − fee = net.
4. **Confirm the timeline/events section renders** at least the authorized/captured events for the charge.
5. **Confirm the associated-order link resolves** to the fixture order's edit screen.
6. End state: read-only — details page fully rendered for the same fixture charge on both stores.

## Layer D — deterministic state assertion

- Fetch the transaction/charge detail via internal REST for the fixture ID; assert `amount`, `fee`, `net`, `currency`, card `brand = visa`, `last4`, and the order reference all match the fixture ledger.
- Assert amount − fee = net in the payload itself.
- Assert the referenced order exists and its `_intent_id`/`_charge_id` meta points back at this transaction (bidirectional integrity).
- Compare reference vs target: same fields populated for each store's own fixture charge.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-07-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
