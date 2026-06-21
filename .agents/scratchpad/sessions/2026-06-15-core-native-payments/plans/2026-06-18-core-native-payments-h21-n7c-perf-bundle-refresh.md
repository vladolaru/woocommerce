---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 02:18
target: H21 N7c perf and bundle refresh
reconciles:
  - analysis-h21-n7c-perf-bundle-refresh.md
  - review-agent-findings.md
  - staging-log.md
status: draft
---

# H21 N7c Perf And Bundle Refresh

## Scope

H21 closes the concrete N7c coverage gap that survived H20: refresh bundle/perf evidence against the current native WooPayments runtime, fix source-backed styling or asset-loading regressions surfaced by the refresh, and record which measurements are deterministic versus advisory. This is not a full A4/A5 start.

## Plan

1. Capture current reference and target bundle/perf profiles, then classify each failure as implemented, intentional bundle split/consolidation, current code gap, or still incomplete.
2. Add failing coverage for the current classic card checkout gap: rendering WooPayments payment fields must enqueue a core-owned `wc-woopayments-checkout` style alongside the existing script.
3. Add the classic card checkout stylesheet through the legacy/classic asset source pipeline and register it through the normal WooCommerce frontend style registry, with bridge fallback registration for late/test contexts.
4. Update the bundle gate mapping and H21 bundle budget so `classic-card.css` is no longer silently allowed missing. Keep unrelated multi-currency/admin asset gaps tracked rather than papering them over as perf proof.
5. Rebuild classic assets, rerun focused PHP/Jest/lint/PHPStan/build gates, refresh bundle/perf captures, and use browser evidence to verify the style loads only on the classic checkout card surface.
6. Update `analysis-h21-n7c-perf-bundle-refresh.md`, `review-agent-findings.md`, `staging-log.md`, `implementation-log.md`, and the spec-conformance baseline with the final H21 verdict.

## Measurement Rules

Raw/gzip byte deltas, missing/present assets, enqueue behavior, autoload bytes, callback counts, route/controller counts, and query/external-request counts are actionable. Local wall-clock timings are smoke for large regressions only unless they repeat stably. Missing capture/auth fixtures or preinitialized REST route timing keep the measured perf gate incomplete instead of green.
