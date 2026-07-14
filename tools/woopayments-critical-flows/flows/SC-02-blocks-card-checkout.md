# SC-02 — Card checkout, Blocks (new card) · HYBRID (D + A)

Blocks twin of SC-01: the WooPayments payment integration must mount its Payment Element inside the Checkout block — a different integration surface than the classic form and historically the riskier one on native (cf. the B2 Blocks `savedTokenComponent` gap). Functional acceptance: order paid, transaction recorded. UX checkpoints: Payment Element mounts, test-mode badge renders, card errors surface through the Blocks error UI.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled.
- Checkout page using the **Checkout block** (not the shortcode).
- A simple in-stock product with a known price.
- Card: `4242 4242 4242 4242`, any future expiry, any CVC/ZIP.

## Layer A — agent-driven browser

BOTH stores:

1. Add the product to the cart; open the Blocks checkout.
2. **Confirm the WooPayments Payment Element mounts** in the payment step — card fields interactive, no stuck loading placeholder, no mount errors in the console.
3. **Confirm the test-mode badge/notice renders** for the card method.
4. Fill address; attempt Place Order with the card fields empty/incomplete. **Confirm an actionable card error renders** (inline on the Element or in the Blocks checkout error area) and no paid order is created.
5. Enter `4242 4242 4242 4242` with valid expiry/CVC; Place Order.
6. **Functional:** the order-received page renders; the order is paid with the correct total.

## Layer D — deterministic state assertion

- Order status `processing`/`completed`; `_intent_id` / `_charge_id` present.
- Amount/currency match the cart; order created via the Blocks/Store API checkout path.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-02-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
