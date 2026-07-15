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

## 2026-07-15 Layer A result

- Reference and native both fail the functional contract on classic and Blocks checkout. Each $1,000,000 Visa 4242 submission reached order received and persisted as a paid `processing` order backed by a succeeded, fully captured provider charge for 100,000,000 cents. No amount-too-high error rendered, and the shopper did not remain on checkout.
- The $100 controls passed on all four store/surface combinations. Each reached order received and reconciled to a paid order plus succeeded/captured intent and charge, isolating the failure to the missing amount ceiling rather than general checkout health.
- All eight browser confirmations were inspected at original resolution. Fixture, ordered browser-journey, order/provider, debug-window, screenshot, secret, and provenance checks pass. Target diagnostics contain only the exact allowlisted WooCommerce placeholder-image 404 pairs.
- Runner ingest recorded 0 PASS, 2 FAIL, 0 BLOCKED, and 0 queued in `tools/woopayments-critical-flows/evidence/runs/20260715T103633Z-75383-partial/`. Both rollup rows bind the accepted result as `sha256:ebac518f3ca22063fcf966489834289c08af2c7e8cd869600372d77f9c32ef04`.
- This is a shared reference/native contract failure, not a native parity regression. The matrix remains `PENDING`; Layer D is still unwired, and Layer A failed independently.
