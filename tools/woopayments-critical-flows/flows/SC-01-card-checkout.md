# SC-01 — Card checkout, shortcode (new card) · HYBRID (D + A)

The baseline money path: a shopper pays with a new card on the classic shortcode checkout. Functional acceptance: order paid, transaction recorded, amount/currency correct. The deterministic exerciser (`flows/SC-01-card-checkout.sh`, driving `flow-drive.sh charge`) covers the state outcome but cannot judge the UX checkpoints — card fields rendering, incomplete-form errors, shared test-card instructions, and native's additive test-mode badge — so this spec adds the agent browser layer. It shares the `SC-01-card-checkout` basename so runner results for both layers land on the same flow.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled.
- Classic (shortcode) checkout page active.
- A simple in-stock product with a known price (e.g. `test-lab-beaker-001`).
- Card: `4242 4242 4242 4242`, any future expiry, any CVC/ZIP.

## Layer A — agent-driven browser

BOTH stores:

1. Add the product to the cart; go to the classic checkout.
2. **Confirm the card fields render** — the Stripe card Element (number/expiry/CVC) under the WooPayments method, interactive.
3. **On both stores, confirm the test-card instruction renders** with `4242 4242 4242 4242` near the card fields. **On native, additionally confirm exactly one `Test Mode` badge remains visible** after the card-brand logo UI hydrates. The pinned plugin's Classic checkout does not require that separate badge.
4. Fill billing details; leave the card number incomplete (e.g. `4242 4`); Place order. **Confirm an inline incomplete-card error renders** and no paid order is created.
5. Enter `4242 4242 4242 4242` with valid expiry/CVC; Place order.
6. **Functional:** the order-received page renders with the correct total; the order is paid.

## Layer D — deterministic state assertion

- Order status `processing`/`completed`.
- `_intent_id` / `_charge_id` present; `_payment_method_id` set.
- Amount/currency match the cart (product price × quantity).
- Compare ref vs target end-state.

Deterministic exerciser: WIRED — `flows/SC-01-card-checkout.sh` drives the charge and asserts the state above.

Agent oracle mode: comparable for the shared functional and test-instruction contract, with the explicitly additive native badge assertion above.

## 2026-07-21 contract correction

- The accepted run `20260718T105343Z-40055-partial` remains a historical `FAIL — UX` against the then-written requirement that both Classic owners show a separate badge. Its evidence and verdict are unchanged.
- The reference-side badge requirement was overconstrained: pinned WooPayments Classic deliberately communicates test context through the copyable test-card instruction without a separate badge.
- Native deliberately owns the stronger badge contract. The accepted missing badge exposed a real logo-hydration defect, corrected on current HEAD so the server-rendered badge survives initial hydration and resize.
- SC-01 remains `PENDING` until the corrected Layer A contract is runner-replayed on current HEAD. The source correction and this prospective oracle update do not retroactively turn the accepted run into a pass.
