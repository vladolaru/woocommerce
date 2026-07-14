# ON-04 — Plugin update via plugins page · HYBRID (D + A)

Guards the update lifecycle: updating the WooPayments extension from the Plugins page must be clean — no activation/update errors, admin pages still load, checkout still works. On the reference store this is the plain extension update. On the native target the risk shape is plugin-active-alongside-native: the extension updating while the native gateway ships in core must not fatal, double-register gateways, or corrupt shared options (MA-11 covers the plugin-active settings screen; this row covers the update transition itself). Onboarded account state must survive the update on both stores.

## Fixtures (both stores)

- Connected test account in test mode; card method enabled — aligned across stores per HARNESS.md store-config discipline.
- An older WooPayments extension build installed and active, plus the current release zip available locally to update to (same from/to versions on both stores).
- A simple in-stock product; classic checkout page active.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Plugins. Update WooPayments to the current release (upload/replace flow locally). **Confirm the update completes with no error notice and the plugin stays active.**
2. Load Payments → Overview and WooCommerce → Settings → Payments. **Confirm both render without errors or blank screens post-update.**
3. Add the product to the cart and open checkout. **Confirm the WooPayments card fields render** and a `4242 4242 4242 4242` order completes.

End state: updated plugin active on both stores, admin surfaces healthy, one paid post-update order per store.

## Layer D — deterministic state assertion

- Plugin version on disk/DB reflects the target release; the plugin is active (target: native gateway registration also intact — no duplicate `woocommerce_payments` gateway IDs at checkout).
- `woocommerce_woocommerce_payments_settings` survives the update (gateway enabled state unchanged); onboarding option state intact (`wcpay_onboarding_test_mode`, account cache still connected).
- The post-update order is `processing`/`completed` with `_intent_id`/`_charge_id`.
- debug.log clean across the update window; compare ref vs target end-state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/ON-04-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
