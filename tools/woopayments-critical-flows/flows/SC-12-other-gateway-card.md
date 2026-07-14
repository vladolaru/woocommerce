# SC-12 — Add card via another gateway (no WooPayments conflict) · HYBRID (D + A)

Coexistence guard: with a second card-capable gateway active (e.g. the WooCommerce Stripe extension in test mode), saving and using a card through that gateway must work, and WooPayments must not interfere — only WooPayments' own entry carries its "Credit card" label, and WooPayments must not raise a false "your payment information is incomplete" error when its own fields are untouched while the shopper pays via the other gateway. Functional acceptance: card saved via the alternate gateway and usable.

## Fixtures (both stores)

- Connected WooPayments test account; card method enabled.
- A second card-saving gateway installed and enabled in test mode (e.g. WooCommerce Stripe), same version on both stores.
- Logged-in customer; a simple in-stock product.
- Card: `4242 4242 4242 4242`.

## Layer A — agent-driven browser

BOTH stores:

1. My Account → Payment methods → Add payment method. **Confirm both gateways are offered**; select the OTHER gateway; save `4242 4242 4242 4242`.
2. **Functional:** the card saves under the other gateway; **confirm it is listed in Payment methods** with a success notice.
3. Go to checkout. **Confirm exactly one WooPayments "Credit card" entry renders** — the other gateway keeps its own distinct label, no duplicate/misattributed card entries.
4. Select the OTHER gateway (its saved card) and Place order. **Confirm WooPayments raises no false "payment information is incomplete" error** while its fields are unused.
5. **Functional:** the order is paid via the other gateway.

## Layer D — deterministic state assertion

- A token row exists for the saved card with the OTHER gateway's `gateway_id` (not WooPayments').
- The order is paid under the other gateway's id, with no WooPayments `_intent_id`/`_charge_id` on it.
- WooPayments tokens and settings are unchanged by the flow.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-12-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
