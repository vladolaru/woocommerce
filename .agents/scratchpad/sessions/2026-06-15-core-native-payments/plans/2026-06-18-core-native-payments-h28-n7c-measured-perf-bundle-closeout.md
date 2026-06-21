---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 07:01
target: H28 N7c measured perf and bundle closeout
reconciles:
  - analysis-h28-n7c-measured-perf-bundle-closeout.md
  - analysis-h21-n7c-perf-bundle-refresh.md
  - review-agent-findings.md
last_updated: 2026-06-18 07:34
status: final
---

# H28 N7c Measured Perf And Bundle Closeout

## Scope

H28 closes the next trustworthy N7c chunk before A4/A5: refresh bundle and perf evidence against the current native runtime, fix the source-backed Core-owned multi-currency settings styling/build gap, and record the remaining multi-currency admin/setup/editor asset dispositions honestly. It does not claim exact local latency parity and does not make provider-event cutover green.

## Plan

1. Add RED coverage proving the multi-currency settings asset manifest/controller expects a style companion and that the wp-admin entry imports a stylesheet through the normal admin build.
2. Add `style.scss` for the Core native multi-currency settings UI and import it from the entry, using restrained WooCommerce admin styles scoped to `.woocommerce-multi-currency-settings`.
3. Extend `MultiCurrencySettingsProjectionService` and `MultiCurrencySettingsController` so the settings style is registered/enqueued through `WCAdminAssets::register_style()` when the bundle is available, while keeping test seams explicit.
4. Update the ignored bundle gate mapping/budget so `multi-currency-admin.css` maps to the generated Core admin CSS and is no longer `allow_missing`. Re-run bundle captures for reference and target and classify analytics/switcher/setup separately.
5. Create fresh disposable process, refund, and manual-capture fixtures on the reference and target stores, then run `perf-surface-gate.sh` captures with all three money-path fixture IDs. Treat timing as coarse smoke only and keep REST route timing incomplete if it remains preinitialized.
6. Run focused PHP/Jest/style/build/static checks for the product changes and harness syntax/fixture checks for the ignored gate changes.
7. Request at least one review agent for H28 focused on frontend/bundle correctness and one focused on performance-evidence honesty, fix source-backed findings, then update `analysis-h28`, `review-agent-findings`, `staging-log`, `implementation-log`, and `spec-conformance-baseline`.

## Measurement Rules

Bundle asset presence and byte deltas are deterministic enough to gate. Query counts, callback counts, external-request counts, and autoload bytes are useful structural perf signals. Local timing medians are noisy stop gaps for large deltas only; they should not be reported as precise performance proof, and missing fixture or route-isolation coverage keeps the gate incomplete rather than green.

## Closeout

H28 is implemented and committed in the branch range `3043c12df0...26b23f75ad`, with a follow-up branch changelog normalization commit `0d527ea115` so the accumulated native-payments changelog entries pass current changelogger validation. The scoped H28 bundle gate is green for `multi-currency-admin.css`; the scoped money-path perf probes have no structural regression failures; N7c remains explicitly incomplete for isolated REST route-registration timing and for the remaining multi-currency analytics/switcher/setup asset dispositions.
