# SC-07 — $1M cart amount limit · HYBRID (D + A)

WooPayments refuses single transactions at/above $1,000,000 (provider amount ceiling). Over-limit checkout must be blocked with an explicit error rendered below the WooPayments method — not a silent failure and never an attempted charge. Functional acceptance: no order reaches a paid state over the limit, while an under-limit control checkout still completes. UX checkpoint: the shopper sees the amount-too-high error directly under the WooPayments method.

## Fixtures (both stores)

- Connected test account in test mode; "Credit/Debit card" enabled; store currency USD.
- Two simple products, identical on both stores: one priced $1,000,000.00 (over limit) and one at $100.00 (control).
- Card: `4242 4242 4242 4242`.

## Layer A — agent-driven browser

BOTH stores (classic; repeat on Blocks if both surfaces are active):

1. Add the $1,000,000 product; go to checkout.
2. **Confirm the amount-limit error renders below/at the WooPayments method** (on load of the payment section or on submitting with `4242 4242 4242 4242` — either is acceptable, but the error must be attached to the method and state the amount is too high).
3. Attempt Place order. **Functional:** checkout is blocked; the shopper stays on checkout; no order reaches a paid state.
4. Control: empty the cart, add the $100 product, pay with the same card. **Functional:** the control order completes normally (proves the block is limit-specific, not a broken gateway).

## Layer D — deterministic state assertion

- Over-limit attempt: no `processing`/`completed` order and no `_charge_id` recorded for it.
- Control order: paid, `_intent_id`/`_charge_id` present, amount $100 + applicable costs.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-07-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
