# MC-06 — Automatic rates refresh · DETERMINISTIC

Native multi-currency must refresh automatic rates through the same WooPayments transport semantics as the extension.

## Fixtures

- Reference and target stores start with default currency `USD`.
- Enable multi-currency automatic rates for `GBP` and `EUR`.
- Clear the cached rates option before the refresh.

## Layer D

Run `mc-rates-gate.sh --currency-from USD --currencies-to GBP,EUR` against reference and target. The gate is fail-closed: provider unavailability, missing cache payloads, or rate mismatches fail the flow.

Required deterministic evidence:

- Reference and target report provider `woopayments`.
- Both stores refresh the same `GBP` and `EUR` rate keys.
- Cached rate payloads match after normalization.

## Layer A

No browser layer is required for the rate-transport acceptance. UI setup/edit flows remain covered by MC-01 and MC-02.

## Verdict

BLOCKED until `mc-rates-gate.sh` has run against both stores with matching `USD` to `GBP`/`EUR` evidence.
