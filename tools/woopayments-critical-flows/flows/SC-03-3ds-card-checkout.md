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

## Latest runner evidence

Layer A is reference `PASS` / target `FAIL — UX` in partial run
`20260715T072351Z-34257-partial`. Reference classic and Blocks each completed
the fail→retry→succeed lifecycle with exact `requires_action` checkpoints,
explicit Complete/Fail challenge controls, specific authentication errors,
safe failed states, and paid/captured USD 40 orders. Native Blocks matched that
lifecycle.

Native classic rendered and safely failed the first challenge, but its
`blockUI` checkout overlay remained visible beyond 45 seconds. The shopper
could not activate Place order for the required retry. The resulting order is
failed, unpaid, and chargeless; its intent records the authentication failure,
and the provider latest charge did not move. This is a new product UX
regression. A second fresh native-classic fixture reproduced the stuck overlay
with ordinary simulator clicks, ruling out forced challenge controls as the
cause. Layer D remains unwired, so the matrix row stays `PENDING`.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
