# MU-01 — Network install + activate · DETERMINISTIC (D)

Guards the regression where network activation does not make WooPayments available network-wide: the flow fails if any existing or newly created subsite is missing the gateway after a Network Admin activation. No browser layer is required for this flow. The local envs (:8082 reference, :8889 target) are single-site, so multisite rows need a dedicated multisite env — a local prerequisite the runner keeps BLOCKED until such an env exists. The implementor's `tools/woopayments-merge/a5g-multisite-runtime-gate.py` covers runtime ownership state in a disposable multisite wp-env; this spec covers the flow-level behavior.

## Fixtures (both stores)

- A multisite (subdirectory) install per store: reference multisite runs the WooPayments plugin, target multisite runs native-in-core; WooCommerce active on every site.
- At least two subsites (primary + `site2`) before activation; connected test account state seeded on the network.
- Super-admin access to Network Admin; `debug.log` enabled on every site.

## Layer D — deterministic state assertion

After `wp plugin activate woocommerce-payments --network` (reference) / the native equivalent enablement (target):

- Assert network-wide availability: on every site from `wp site list`, the WooPayments gateway is registered in `WC()->payment_gateways()` and payable at checkout.
- Reference: assert `active_sitewide_plugins` (site option) contains `woocommerce-payments/woocommerce-payments.php`; target: assert the native runtime owner reports `native` on each site.
- Create a fresh subsite (`site3`) after activation and assert it also has the gateway without any per-site activation step.
- Assert the network activation control itself is truthful: reference Network Admin → Plugins lists WooPayments as "Network Active"; individual subsite plugin screens offer no stray per-site deactivate that would contradict the network state.
- Assert each site's Settings → Payments screen loads and shows the gateway as configurable (no fatal, no missing-dependency notice).
- Assert no activation-time fatals/notices in `debug.log` on any site.
- Assert the inverse in one step: network-deactivate and confirm the gateway disappears from every site simultaneously, then re-activate to restore the end-state.
- Assert a shopper-facing probe agrees: the Store API payment methods list on each site includes the WooPayments gateway while network-active.
- Compare ref vs target end-state: same per-site gateway availability map.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MU-01-*.sh.
