# MU-02 — Manual install (multisite) · DETERMINISTIC (D)

Guards the regression where per-site activation stops working on multisite: the flow fails if activating WooPayments on one subsite leaks the gateway onto sibling sites, or if the site-level activation control is lost (forced network-wide only). No browser layer is required for this flow. The local envs are single-site, so multisite rows need a dedicated multisite env — a local prerequisite the runner keeps BLOCKED until such an env exists. The implementor's `tools/woopayments-merge/a5g-multisite-runtime-gate.py` covers runtime ownership state; this spec covers the flow-level behavior.

## Fixtures (both stores)

- A multisite install per store (reference = plugin, target = native), WooCommerce active on every site, WooPayments NOT network-activated.
- Two subsites: `site-a` (activation target) and `site-b` (isolation control); connected test account state seeded for `site-a` only.
- Super-admin access; `debug.log` enabled on both sites.

## Layer D — deterministic state assertion

After activating/enabling WooPayments on `site-a` only (`wp plugin activate woocommerce-payments --url=<site-a>` on reference; the per-site native enablement on target):

- Assert `site-a` has the WooPayments gateway registered and payable at checkout.
- Assert `site-b` does NOT expose the gateway: not in `WC()->payment_gateways()`, not offered at checkout, and reference `active_plugins` on `site-b` lacks the plugin.
- Assert site-level control both ways: deactivate on `site-a` and confirm the gateway disappears there while nothing changes on `site-b`.
- Assert the site-level control is truthful in `site-a`'s plugins screen: activate/deactivate offered per site (reference), and installing from Network Admin without network-activating leaves both sites gateway-free until the per-site step runs.
- Assert per-site settings isolation: `site-a` gateway/account options exist as site options and no counterpart appears on `site-b`.
- Assert `site-a`'s account connection state does not surface on `site-b` (no onboarding-complete or account widgets there).
- Assert a shopper-facing probe agrees: the Store API payment methods list includes WooPayments on `site-a` and excludes it on `site-b`.
- Assert `debug.log` clean on both sites throughout activate/deactivate/re-activate.
- Compare ref vs target end-state: identical per-site availability and isolation map.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MU-02-*.sh.
