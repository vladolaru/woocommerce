# SC-01 — Card checkout, shortcode (new card) · HYBRID (D + A)

The baseline money path: a shopper pays with a new card on the classic shortcode checkout. Functional acceptance: order paid, transaction recorded, amount/currency correct. The deterministic exerciser (`flows/SC-01-card-checkout.sh`, driving `flow-drive.sh charge`) covers the state outcome but cannot judge the UX checkpoints — card fields rendering, incomplete-form errors, test-mode badge + test-card copy — so this spec adds the agent browser layer. It shares the `SC-01-card-checkout` basename so runner results for both layers land on the same flow.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled.
- Classic (shortcode) checkout page active.
- A simple in-stock product with a known price (e.g. `test-lab-beaker-001`).
- Card: `4242 4242 4242 4242`, any future expiry, any CVC/ZIP.

## Layer A — agent-driven browser

BOTH stores:

1. Add the product to the cart; go to the classic checkout.
2. **Confirm the card fields render** — the Stripe card Element (number/expiry/CVC) under the WooPayments method, interactive.
3. **Confirm the test-mode badge/notice and the test-card copy render** (a "Test mode" indicator plus the hint naming `4242 4242 4242 4242` near the card fields).
4. Fill billing details; leave the card number incomplete (e.g. `4242 4`); Place order. **Confirm an inline incomplete-card error renders** and no paid order is created.
5. Enter `4242 4242 4242 4242` with valid expiry/CVC; Place order.
6. **Functional:** the order-received page renders with the correct total; the order is paid.

## Layer D — deterministic state assertion

- Order status `processing`/`completed`.
- `_intent_id` / `_charge_id` present; `_payment_method_id` set.
- Amount/currency match the cart (product price × quantity).
- Compare ref vs target end-state.

Deterministic exerciser: WIRED — `flows/SC-01-card-checkout.sh` drives the charge and asserts the state above.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
