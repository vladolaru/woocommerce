# MU-04 — Card checkout primary + secondary · HYBRID (D + A)

Guards the regression where multisite breaks per-site payment isolation: the flow fails if checkout cannot complete independently on both the primary and a secondary site, or if orders/transactions leak across sites (an order or transaction from one site visible in the other's admin). The local envs are single-site, so multisite rows need a dedicated multisite env — a local prerequisite the runner keeps BLOCKED until such an env exists (the implementor's `tools/woopayments-merge/a5g-multisite-runtime-gate.py` covers runtime state; this spec covers the flow-level behavior).

## Fixtures (both stores)

- A multisite install per store (reference = plugin, target = native), WooPayments active with a connected test account on the primary site and on `site2`.
- Distinct products per site: primary sells "Primary Mug" $12.00, `site2` sells "Secondary Cap" $15.00; guest checkout enabled on both.

## Layer A — agent-driven browser

BOTH stores:

1. On the primary site, buy "Primary Mug" with test card `4242424242424242`. **Confirm card fields render and the order-received page shows $12.00 paid.**
2. On `site2`, buy "Secondary Cap" the same way. **Confirm card fields render and order-received shows $15.00 paid.**
3. In the primary site's admin, open Orders and Payments → Transactions. **Confirm only the $12.00 order/transaction appears — no `site2` rows (per-site isolation).**
4. In `site2`'s admin, confirm the mirror: **only the $15.00 order/transaction appears.**

End state: one paid order per site, each visible and manageable only from its own site's admin.

## Layer D — deterministic state assertion

- Assert each site's order store contains exactly its own new order (`wp wc order list --url=<site>`), `processing`/`completed`, correct total.
- Assert `_intent_id`/`_charge_id` set on each order and the two intent IDs differ.
- Assert transaction attribution per site: each site's transactions query returns only its own charge.
- Assert per-site gateway settings/account options remain independent; `debug.log` clean on both sites; compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MU-04-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
