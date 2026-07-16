# MO-01 — Manual capture from the order screen · HYBRID (A + D)

Guards the authorize-then-capture path. With WooPayments' manual-capture setting enabled ("Issue an authorization on checkout, and capture later" under the gateway's advanced settings), checkout only authorizes: the order lands `on-hold` with an "authorized" note and an uncaptured intent. The regression to catch: under native the setting doesn't take effect (auto-captures anyway), the "Capture charge" order action is missing, or capturing doesn't move the order to `processing` with the full authorized amount — authorizations silently expire after ~7 days, which is lost revenue.

## Fixtures (both stores)

- Connected test account; manual capture enabled in WooPayments settings.
- One authorized-not-captured order: test checkout with `4242 4242 4242 4242` after enabling manual capture (order `on-hold`, intent `requires_capture`).

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Settings → Payments → WooPayments (Manage). **The manual-capture control ("Issue an authorization on checkout, and capture later") is present**; enable it and save.
2. Place a storefront checkout with `4242 4242 4242 4242`.
3. WooCommerce → Orders → open the new order. **Status is On hold and an order note says the payment was authorized (not captured), naming the amount.**
4. Open the Order actions dropdown. **"Capture charge" is offered.**
5. Apply "Capture charge". **Status flips to Processing; a new order note confirms the full amount was successfully captured; no error notice.**

End state: order Processing, captured amount equals order total.

## Layer D — deterministic state assertion

- Pre-capture: assert order status `on-hold`, `_intent_id` present with intent status `requires_capture`, `_charge_id` present and uncaptured.
- Post-capture: assert order status `processing`, intent status `succeeded`, captured amount equals the order total (no partial/zero capture), and a capture order note exists.
- Compare ref vs target end-state.

Deterministic exerciser: `flows/MO-01-manual-capture-order.sh` creates one provider-backed manual authorization per store, archives secret-free pre/post order and provider state, captures the full amount through the active runtime, and compares the normalized reference/target transitions.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
