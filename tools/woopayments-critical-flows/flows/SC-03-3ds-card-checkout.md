# SC-03 — 3DS-required card, classic + Blocks · A (+D assert)

SCA path: card `4000002500003155` requires 3DS authentication on every payment. The Stripe challenge modal is interactive UI a deterministic script handles poorly, so the browser layer is primary. Guards the intent lifecycle (`requires_action` → confirmed) and the challenge-modal wiring on both checkout surfaces: completing SCA must pay the order; failing the challenge must surface an error and move no money.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled.
- Both checkout surfaces reachable: classic shortcode and Blocks.
- A simple in-stock product. Card: `4000002500003155` (3DS challenge required).

## Layer A — agent-driven browser

BOTH stores, run once on **classic** and once on **Blocks**:

1. Add the product; go to checkout; fill billing details; enter `4000002500003155` with valid expiry/CVC; Place order.
2. **Confirm the 3DS challenge modal renders** (Stripe test challenge iframe with Complete/Fail controls).
3. Fail path first: click **Fail authentication**. **Confirm a checkout error renders** (authentication-failed family), the shopper stays on checkout, and no paid order exists.
4. Place order again; this time click **Complete authentication**.
5. **Functional:** the order-received page renders; the order is paid.

## Layer D — deterministic state assertion

- Success order: status `processing`/`completed`; `_intent_id`/`_charge_id` present; the intent passed through `requires_action` and was confirmed (not left hanging).
- Fail path: no paid order from the failed attempt — any created order remains `pending`/`failed` with no `_charge_id`.
- Amount/currency correct on the success order.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
