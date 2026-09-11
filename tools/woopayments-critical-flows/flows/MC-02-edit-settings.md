# MC-02 — Edit settings · DETERMINISTIC

Guards persistence of per-currency multi-currency settings edits: switching a currency from automatic to a manual rate, and setting price rounding and charm pricing, must round-trip through the settings write path and survive reload — on native exactly as on the extension. The acceptance is pure stored-state persistence (the settings UI surface itself is exercised by MC-01), so No browser layer is required for this flow. The implementor's `converted-currency-gate.sh` corroborates that stored rates actually drive converted charges; this row owns the edit → persist contract.

## Fixtures (both stores)

- Connected test account; default currency `USD`; `GBP` and `EUR` enabled (`wcpay_multi_currency_enabled_currencies`), aligned across stores per HARNESS.md store-config discipline.
- GBP starting from automatic rates with no manual overrides (per-currency options cleared).
- A simple in-stock product with a known USD price of `$10.00` for the computed-price check.

## Layer D — deterministic state assertion

Drive the settings update path (REST `update-single-currency-settings` / equivalent service call) on BOTH stores with: GBP → exchange-rate type `manual`, manual rate `0.80`, price rounding `1.00`, price charm `-0.01`. Then assert:

- `wcpay_multi_currency_exchange_rate_gbp` = `manual`.
- `wcpay_multi_currency_manual_rate_gbp` = `0.80`.
- `wcpay_multi_currency_price_rounding_gbp` = `1.00` and `wcpay_multi_currency_price_charm_gbp` = `-0.01`.
- EUR options untouched (edit is scoped to the edited currency).
- An invalid manual rate (`0`, negative, non-numeric) is rejected and does NOT overwrite the stored rate — fail-closed on bad input.
- Frontend-facing GBP price for the fixture product reflects manual rate + rounding + charm (`$10.00` × `0.80` → round to `1.00` → charm `-0.01` = `£7.99`).
- Persistence proven across process boundaries: options re-read via a fresh WP-CLI invocation, not the in-request cache.
- `wcpay_multi_currency_enabled_currencies` unchanged by the edit (editing a currency never toggles enablement).
- Compare ref vs target stored options and computed GBP price; any divergence fails the flow.

Until the exerciser is wired the runner keeps this flow BLOCKED — never assumed-pass.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MC-02-*.sh.
