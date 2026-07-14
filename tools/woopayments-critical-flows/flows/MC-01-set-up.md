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
