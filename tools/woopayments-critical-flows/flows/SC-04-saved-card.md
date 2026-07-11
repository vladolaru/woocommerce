# SC-04 — Saved card → checkout (classic + Blocks) · HYBRID (D + A)

This is the acceptance test for B2. It must flip PASS on both stores, both layers, after N13's B2 fix. Saved cards are ON by default, so this is the common returning-shopper path.

## Fixtures (both stores)

- Connected test account; `saved_cards = yes`; "Credit/Debit card" enabled.
- A logged-in customer with ONE previously-saved card token (seed via SP-01 first).
- Cards: `4242…4242` (already saved), `4000002500003155` (3DS, for the SCA variant).

## Layer D — deterministic state assertions (run.sh / a sibling .sh)

Exercise a checkout that selects the EXISTING saved token (Store API / REST with the saved PM id, not a new PaymentMethod), then assert:

- Order status `processing`/`completed`.
- The charge used the SAVED token: `_payment_method_id` equals the saved token's PM, NOT a newly-created one.
- `_intent_id` / `_charge_id` present; amount/currency correct.
- For the 3DS variant: intent reached `requires_action` then was confirmed (not left hanging).
- Compare ref vs target: same end-state shape.

## Layer A — agent-driven browser (agent-specs/_template.md)

Because the failure is in interactive UI (radio selection, Stripe Element, 3DS modal, Blocks saved-token component) a script can't reliably judge. Agent steps, BOTH stores:

1. Log in as the seeded customer; go to checkout (run once for **classic**, once for **Blocks**).
2. Confirm the saved card is offered as a selectable option (radio / saved-token list).
3. Select the saved card (do NOT enter a new card).
4. Place order.
5. **Functional:** order completes; the SAVED token is charged (not a new card); for the 3DS card the SCA modal appears and completing it finishes the order.
6. **UX:** the saved-card affordance is discoverable and selecting it does not force the empty new-card Element; SCA prompt actually renders for saved-token purchases.

### Expected reference behavior (golden)

Classic: selecting a saved card bypasses `createPaymentMethod` and charges the token; 3DS prompts as needed. Blocks: the registered saved-token component handles fraud + next-action so SCA completes.

### Historical native regression guarded by this flow

- Classic: place-order always calls `createPaymentMethod()` on the empty Element → validation error / unintended new PM.
- Blocks: no `savedTokenComponent` → saved token renders `NullComponent`; the SCA handler is unmounted → 3DS for saved-token purchases never completes.

The B2 implementation work addresses both historical failures. A current verdict requires the context-bound `sc04-saved-card-gate.py` run: normal and 3DS saved-token checkout must pass on Classic and Blocks for both stores, with matching browser and authoritative order/token state.
