# MU-03 — Plugin update (multisite) · HYBRID (D + A)

Guards the regression where an update on multisite leaves subsites broken: the flow fails if the network update errors, if payments pages or checkout fatal on the primary or a secondary site afterwards, or if the sites end up on divergent versions. The local envs are single-site, so multisite rows need a dedicated multisite env — a local prerequisite the runner keeps BLOCKED until such an env exists (the implementor's `tools/woopayments-merge/a5g-multisite-runtime-gate.py` covers runtime state; this spec covers the flow-level behavior).

## Fixtures (both stores)

- A multisite install per store: reference runs the previous WooPayments plugin release network-activated with the current release available as an update; target runs the pre-update native/WC build with the updated core build staged.
- Two subsites (primary + `site2`), each with a connected test account state, one product, and a payable checkout.

## Layer A — agent-driven browser

BOTH stores:

1. Network Admin → Plugins (reference) / the core update surface (target), run the update.
2. **Confirm the update completes with a success notice and no error/partial-update state.**
3. On the primary site AND `site2`, load WP Admin → Payments (Overview, Transactions) and Settings → Payments. **Confirm all pages load on both sites without fatals, white screens, or admin notices about the update.**
4. On both sites, load checkout with a product in cart. **Confirm the card fields still render and a `4242…` test payment completes.**

End state: both sites on the updated version, both checkouts payable, one fresh paid order per site.

## Layer D — deterministic state assertion

- Assert version consistency: the updated plugin version (reference) / core build (target) is identical on primary and `site2`.
- Assert per-site gateway still enabled and account state intact (no re-onboarding prompt) on both sites.
- Assert `debug.log` clean on both sites across the update window; compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MU-03-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
