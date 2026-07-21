# SC-12 — Add card via another gateway (no WooPayments conflict) · HYBRID (D + A)

Coexistence guard: with a second card-capable gateway active (e.g. the WooCommerce Stripe extension in test mode), saving and using a card through that gateway must work, and WooPayments must not interfere. WooPayments owns the shopper-facing `Card` title, the alternate gateway must remain separately identifiable, and WooPayments must not raise a false "your payment information is incomplete" error when its own fields are untouched while the shopper pays via the other gateway. Functional acceptance: card saved via the alternate gateway and usable.

## Fixtures (both stores)

- Connected WooPayments test account; card method enabled.
- A second card-saving gateway installed and enabled in test mode (e.g. WooCommerce Stripe), same version on both stores.
- Logged-in customer; a simple in-stock product.
- Card: `4242 4242 4242 4242`.

## Layer A — agent-driven browser

BOTH stores:

1. My Account → Payment methods → Add payment method. **Confirm both gateways are offered**; select the OTHER gateway; save `4242 4242 4242 4242`.
2. **Functional:** the card saves under the other gateway; **confirm it is listed in Payment methods** with a success notice.
3. Go to checkout. **Confirm WooPayments renders its owned `Card` title and the other gateway keeps its own distinct label** — no duplicate or misattributed gateway entry.
4. Select the OTHER gateway (its saved card) and Place order. **Confirm WooPayments raises no false "payment information is incomplete" error** while its fields are unused.
5. **Functional:** the order is paid via the other gateway.

## Layer D — deterministic state assertion

- A token row exists for the saved card with the OTHER gateway's `gateway_id` (not WooPayments').
- The order is paid under the other gateway's id, with no WooPayments `_intent_id`/`_charge_id` on it.
- WooPayments tokens and settings are unchanged by the flow.
- Compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/SC-12-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## 2026-07-15 Layer A result

- Reference and native both complete the classic-checkout journey with the same versioned local alternate-card fixture. My Account offers WooPayments and `Alternate card` as distinct gateways; each store saves exactly one Visa 4242 token under `sc12_alternate_card` and displays the success notice plus saved-card row.
- Both stores fail the written checkout-label contract. The required WooPayments `Credit card` label is absent: reference renders `Card` with its method-count badge, native renders `Card` with its method-count badge, and each authoritative browser projection records `credit_card_label_count: 0`. This is a shared UX contract failure, not a native-only regression.
- Selecting the saved alternate token produces no false WooPayments incomplete-payment error, and each USD 40 order reaches order received as paid `processing` under `sc12_alternate_card`. Authoritative state joins the exact checked gateway, token, and order IDs; both orders contain the alternate token ID and no WooPayments `_intent_id` or `_charge_id`; WooPayments token projections and settings hashes are unchanged.
- All eight fresh browser confirmations were inspected at original resolution. The add-card images visibly show the selected alternate gateway and complete entered card fixture, and source, screenshot, debug-window, secret, and provenance checks pass.
- Runner ingest recorded 0 PASS, 2 FAIL — UX, 0 BLOCKED, and 0 queued in `tools/woopayments-critical-flows/evidence/runs/20260715T112142Z-24215-partial/`. Both rollup rows bind the accepted result as `sha256:b4318b00c1f4c8cd2868f4d6a0201f5ee8d7e1c580f839791f4e4f16b91ff9b6`.
- The matrix and README remain `PENDING` because Layer A fails the written label contract and the required Layer D exerciser is still unwired.

## 2026-07-21 contract correction

- The accepted run and its historical two `FAIL — UX` verdicts remain unchanged; they evaluated the former exact `Credit card` assertion.
- `Card` is the deliberate pinned/native shopper title. `Credit / Debit Cards` is a separate merchant-settings label, and the flow had no authority for substituting the historical literal.
- The accepted controlled fixture verifies distinct `Card` and `Alternate card` entries, alternate-gateway token/order isolation, and no false WooPayments error. It does not establish universal gateway-name clarity or assistive-technology comprehension.
- The row remains `PENDING` because Layer D is unwired. Correcting the prospective label contract does not retroactively change the accepted runner verdict.
