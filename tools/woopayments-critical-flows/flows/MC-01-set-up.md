# MC-01 — Set up · HYBRID (D + A)

Guards first-time multi-currency setup: enabling currencies beyond the store default and getting automatic rates for them. Native must let the merchant add GBP and EUR alongside USD through the Multi-currency settings UI and immediately show truthful automatic rates fetched over the WooPayments transport. The implementor's `mc-rates-gate.sh` (via MC-06) corroborates the rate transport itself and `converted-currency-gate.sh` corroborates the downstream converted charges — this row owns the setup UI plus the enabled-currencies/rate state it must leave behind.

## Fixtures (both stores)

- Connected test account; store default currency `USD`; multi-currency starting with NO extra enabled currencies (clear `wcpay_multi_currency_enabled_currencies`), aligned across stores per HARNESS.md store-config discipline.
- Cached automatic rates cleared so the setup fetch is observable.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → WooCommerce → Settings → Multi-currency (the WooPayments multi-currency settings screen). **Confirm the setup UI renders** with the add-currency affordance.
2. Add `GBP` and `EUR`. **Confirm both appear in the enabled-currencies list with an automatic exchange rate displayed** (a real numeric rate, not a placeholder or error).
3. Save. Reload the screen. **Confirm GBP and EUR persist as enabled with their rates still shown.**
4. Visit the storefront shop page. **Confirm prices still render correctly in USD** (setup alone must not change the default presentation).

End state: USD default with GBP and EUR enabled and automatic rates cached, identically on both stores.

## Layer D — deterministic state assertion

- `wcpay_multi_currency_enabled_currencies` contains exactly `GBP` and `EUR` (default `USD` implicit).
- Cached automatic rates exist for GBP and EUR (per-currency rate available to the frontend; `mc-rates-gate.sh` output for USD→GBP,EUR corroborates the same cache keys).
- Per-currency exchange-rate type defaults to `automatic` (`wcpay_multi_currency_exchange_rate_gbp`/`_eur` unset or `automatic`).
- Compare ref vs target enabled-currency and rate state.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MC-01-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).

## Latest runner evidence (2026-07-19)

- Accepted result: `sha256:0a195914362236b7f03ef09c769dcb69d9f57c28f386563a72ad0b999865da78`, bound to context `387612e4-2a24-46f0-9851-2b86cf9e8da9` (`sha256:c617a770180b2ee36afde257a76471b8d41eb938e8c70fde5ddfeddf4f49fae6`) at source `954c6a140e36510664aa6088dcdbe35972cc9be5`. Every raw state, fixture, and browser artifact carries the same capture-time source/store/context binding plus a distinct UUIDv4 and millisecond timestamp. The accepted sequence strictly proves original state before staging, staging before browser interaction, and browser interaction before post-state capture; original enabled-currency and cache hashes also join exactly to the fixture pre-state.
- Runner archive: `evidence/runs/20260718T235156Z-3608-partial` — reference PASS, target PASS, parity PASS; 2 passed, 0 failed, 0 blocked, and 0 MC-01 agent specs queued.
- Reference: the plugin-owned settings UI started with USD only, enabled EUR at `0.88` and GBP at `0.75`, preserved both automatic rates after reload, and rendered `CF Simple` (product `688`) at `$20.00` in USD.
- Target: the native settings UI completed the same journey and state transition, then rendered `CF Simple` (product `113`) at `$20.00` in USD. Its table layout is intentionally native rather than a copy of the plugin's card rows.
- Diagnostics are preserved rather than summarized as clean. Reference emitted its existing duplicate settings-store, `useSelect`, Stripe.js/local-HTTP wallet, and modal-focus warnings. Target emitted the shared `useSelect` warning and two product-page 404s corresponding to visibly broken theme placeholder images; all captured multi-currency responses were HTTP 200. The fixture narrowly clears the stale store-currency-change notice before capture so the UI no longer contradicts the automatic-rate state.
- Layer D remains unwired. The live post-state serializes the same set as `[EUR, GBP, USD]` on reference and `[USD, EUR, GBP]` on target, while the deterministic contract above describes USD as implicit. A future Layer-D exerciser must resolve that representation wording and compare set semantics without weakening the required USD-default plus EUR/GBP-enabled state.
