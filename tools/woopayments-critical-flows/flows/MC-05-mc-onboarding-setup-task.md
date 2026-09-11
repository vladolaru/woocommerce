# MC-05 — MC onboarding/setup task · AGENT

Guards the multi-currency onboarding/setup task surface: the guided "set up multi-currency" entry (WooCommerce admin task/inbox note and the settings-side setup surface) must be discoverable, hand off into the multi-currency settings, and complete — leaving currencies enabled. Native must preserve the task journey even where the visual surface moved; a setup entry that 404s, points at a plugin-only URL, or completes without actually enabling currencies is a regression. The resulting enabled-currency/rate state contract is owned by MC-01's Layer D; rate transport is corroborated by the implementor's `mc-rates-gate.sh`.

## Fixtures (both stores)

- Connected test account; default currency `USD`; multi-currency NOT yet set up (no extra enabled currencies, setup task not completed/dismissed), aligned across stores per HARNESS.md store-config discipline.
- Admin user with `manage_woocommerce`.
- Cached automatic rates cleared, so completion demonstrably fetches fresh rates.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce (Home / Settings → Payments area). **Confirm the multi-currency setup surface is discoverable** — the setup task, inbox note, or in-settings onboarding prompt that offers to set up multi-currency.
2. Open it. **Confirm it lands on the multi-currency setup flow**, not a broken or extension-only URL.
3. Complete the guided setup: add `GBP` and `EUR` as enabled currencies, accept automatic rates, finish.
4. **Confirm the flow reports completion** and returns to a settings state showing GBP and EUR enabled with rates.
5. Open WooCommerce → Settings → Multi-currency directly. **Confirm GBP and EUR are actually enabled with automatic rates shown** — completion must reflect real state, not just a dismissed task.
6. Revisit the entry point. **Confirm the setup surface reads complete** (task done / prompt no longer nagging) rather than re-offering setup from scratch.

End state: multi-currency set up via the guided task on both stores — GBP and EUR enabled with fresh automatic rates, task/prompt marked complete. Any dead-end, extension-only URL, or completion-without-state divergence between the stores fails the flow.

Where the native task surface visually differs from the extension's (WC Home task card vs in-settings prompt), record it as visual divergence per the rubric — the regression is a missing or broken journey, not a moved one.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
