---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 03:10
target: A5j release/default-on readiness packet
reconciles:
  - analysis-a5i-local-readiness-decision-rollup.md
  - analysis-a6-replanned-cleanup-readiness.md
  - review-a6-boundary-audit.md
  - staging-log.md
  - spec-conformance-baseline.md
status: final
last_updated: 2026-06-21 03:23
---

# A5j Release/Default-On Readiness Packet

## Prompt Trail

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A5i closed local A4/A5 readiness as green under recorded limitations. A6 replanning found a valid future cleanup candidate, but `review-a6-boundary-audit.md` controls sequencing with `HOLD_FOR_RELEASE_DECISION`: A6 product cleanup must not start until release/default-on sequencing is settled. This packet is the next non-invasive movement toward the goal: assemble the release/default-on readiness evidence, run any safe local checks that strengthen it, and clearly classify remaining production-only gaps without WPCOM code changes or sandbox access.

## Questions

- Which release/default-on requirements are already proven by local evidence?
- Which requirements can still be strengthened by local checks without product edits?
- Which requirements are production/release-owner evidence and must remain fail-closed?
- Does the current source still keep runtime and mandatory cutover defaults fail-closed?

## Initial Controller Findings

- Canonical A5 exit requires a canary cohort green on parity, error-rate, and perf before flipping the mandatory default. A6 cleanup follows A5 and requires no dead code, lint/PHPStan, final perf delta, and no Bucket-E/C regression.
- The design spec says plugin-active stores remain plugin-owned, the migration path is plugin deactivation owned by Core, mandatory cutover is gated on version/default-on flag, and real rollout needs transition-matrix coverage plus canary error-rate/perf monitoring.
- Current source remains fail-closed: `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` is `false`, `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` is `false`, and `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is an empty array.
- Fresh evidence readback: A4bd accumulated gate remains `status=pass` with 13 checks, `failures=[]`, and `incomplete=[]`; A5h A5f rerun remains `status=pass` with 27 phase results and `failures=[]`; A5h A5g remains `status=pass`, `runtime_mode=existing-tests`, 28 phase results, and `failures=[]`.
- The local harness remains ignored verification infrastructure: `git ls-files tools/woopayments-merge` returns zero tracked files and `.git/info/exclude` ignores `/tools/woopayments-merge/`.

## Interim Classification

Local evidence proves the branch is still fail-closed and locally rehearsed for the latest A4/A5 state. It does not prove production/default-on readiness because canary cohort parity, production error-rate, production WPCOM readiness, production perf, release sequencing, and live rollout monitoring are not local artifacts. The next decision is whether a targeted local rerun adds useful freshness or only repeats A5i.

## Fresh Local Guard Check

Command:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'NativePaymentsRuntimeArbiterTest|WooPaymentsCutoverControllerTest|WooPaymentsEventIngestorTest'
```

Result: passed with 123 tests and 391 assertions. This refreshes source-level guard evidence for runtime ownership, mandatory cutover defaulting, and native provider event disposition. It does not prove production default-on readiness or canary behavior.

## Fresh Target Runtime Probe

Evidence: `data/a5j-target-cutover-state.json`.

The target store probe against `http://store8889.localhost:8889` passed through WP-CLI stdin. Current payload: `runtime_owner=native`, `native_runtime_enabled=true`, `plugin_runtime_active=false`, `should_native_register=true`, `preflight_failures=[]`, `soft_notice=false`, `ready=true`, and `failures=[]`. This confirms the local target store is currently native-owned and cutover-ready under local preflight checks. It does not prove production rollout readiness.

## Requirements Audit Reconciliation

`review-a5j-release-default-on-requirements.md` returned `BLOCKED_ON_PRODUCTION_DECISION`. The audit classifies local A4/A5 readiness and cutover mechanics as locally proven, with A4bd/A5f/A5g available as rerunnable freshness gates if a release packet needs them. It classifies the actual default-on/release blockers as production/release-only: canary parity/error-rate/perf, WPCOM production readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual default/mandatory flip.

## Local Gate Opportunity Reconciliation

`review-a5j-local-gate-opportunities.md` returned `RERUN_A5_ONLY`. It recommends rerunning only `a5f-cutover-rehearsal.py` and `a5g-multisite-runtime-gate.py` for local freshness. It explicitly does not recommend rerunning the full A4aq accumulated gate unless there is a fresh product/admin/checkout source change or a release owner asks for a new full local baseline despite the known limits.

## Fresh A5f Rerun

First A5j A5f run: `data/a5j-release-default-on-readiness/a5f/a5f-cutover-rehearsal.json` failed at the final debug-log scan with one `_load_textdomain_just_in_time` PHP notice for the standalone `woocommerce-payments` plugin text domain. All functional phases before the log scan passed. The notice timestamp was `2026-06-21 00:18:00 UTC`, inside the `soft-cutover-browser-gate` phase.

Investigation: installed the existing local trace helper as `a5j-textdomain-trace.php`, cleared the target debug log, verified standalone WooPayments activation alone stayed clean, loaded the plugin-active target `wp-admin/plugins.php` page and saw only the expected `JQMIGRATE` browser log with an empty PHP debug log, then ran the isolated soft cutover browser gate and again got an empty PHP debug log. No trace stack was produced because the direct product path did not reproduce the notice. A Playwriter page inventory then showed 11 stale local target/reference admin tabs open in the shared browser context. Those local test-store tabs were closed, the trace helper was removed, the target log was cleared, and A5f was rerun.

Current A5f evidence: `data/a5j-release-default-on-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json` reports `status=pass`, `pass=true`, 27 phase results, `failures=[]`, final debug-log scan `bytes=0`, and target restored to native ownership with the standalone WooPayments plugin inactive. This rerun strengthens local cutover recency only; it does not prove production/default-on readiness.

## Fresh A5g Rerun

Evidence: `data/a5j-release-default-on-readiness/a5g/a5g-multisite-runtime-gate.json`.

Result: passed with `status=pass`, `pass=true`, `runtime_mode=existing-tests`, 28 phase results, and `failures=[]`. The post-run restore probe against the WooCommerce tests wp-env returned `home=http://store8889.localhost:8087`, `is_multisite=false`, and `active_plugins=[]`. This strengthens local multisite runtime ownership recency only; it does not prove connected-account behavior on multisite, production network rollout, canary/error-rate, or release sequencing.

## A5j Decision

A5j has a fresh local release/default-on packet: source defaults are still fail-closed, focused guard tests pass, the current target store is native-owned with empty preflight failures, A5f passed on a clean rerun after an investigated non-reproduced plugin-active notice diagnostic, and A5g passed with the tests env restored. The release/default-on decision remains blocked on production/release-only evidence: canary parity, production error-rate, production WPCOM readiness, live queue/financial/Stripe Billing data-safety, exact production perf, release sequencing, and the actual default/mandatory flip. A6 product cleanup remains held behind that decision.
