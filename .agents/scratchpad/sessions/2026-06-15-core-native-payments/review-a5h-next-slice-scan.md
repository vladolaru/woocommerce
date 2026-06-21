---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 02:34
target: A5/A6 residual scan after A5g/A4bd
reconciles:
  - spec-conformance-baseline.md
  - staging-log.md
  - analysis-a5g-next-slice-selection.md
  - plans/2026-06-15-core-native-payments-a5.md
  - plans/2026-06-15-core-native-payments-a6.md
status: final
last_updated: 2026-06-21 02:37
---

# A5h Next Slice Scan

> **Prompt:** "Read-only A5/A6 residual scan for WooCommerce Core native WooPayments work.
>
> Repo: /Users/vladolaru/Work/a8c/woocommerce-develop-2
> Branch: exp/core-native-payments
> Constraints: do not edit files, do not access WPCOM sandbox, do not modify WPCOM, do not push, do not lint .agents.
>
> Context: A4bd just closed as a post-A4bc accumulated gate refresh. A4as already made native admin-surface readiness green after A4ar; A5f cutover rehearsal passed; A5g made rollout defaults explicit/fail-closed and proved multisite runtime ownership. The old A6 harness-removal plan is likely obsolete because the harness is intentionally restored and gitignored for local gates.
>
> Task: identify the next coherent source-backed slice after A5g/A4bd. Focus on A5/A6 readiness gaps that are still actionable locally. Check:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md latest A5g/A4bd state
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md latest entries
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5g-next-slice-selection.md
> - plans/2026-06-15-core-native-payments-a5.md and a6.md
> - relevant product code around NativePaymentsRuntimeArbiter, WooPaymentsCutoverController, and any canary/rollout/error/perf hooks if present
> - tools/woopayments-merge A5/A4 harness scripts only as evidence, not as product source
>
> Questions to answer:
> 1. What remains before A5 can be considered exited, after A5g and A4bd?
> 2. Is there a concrete next slice suitable for implementation now, or should the next step be another gate/baseline refresh?
> 3. Should A6 cleanup/removal start now? If not, why exactly?
> 4. What are the likely files/evidence paths for the recommended next slice?
>
> Write a concise report to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5h-next-slice-scan.md with scratchpad YAML frontmatter. Use verdict format: RECOMMEND_A5H / RECOMMEND_GATE_REFRESH / RECOMMEND_A6 / BLOCKED. Keep it source-backed and avoid broad speculation."

## Sources Checked

- Scratchpad state: `spec-conformance-baseline.md`, `staging-log.md`, `analysis-a5g-next-slice-selection.md`, `review-a5g-rollout-multisite.md`, and the A5f/A5g/A4bd plan/review entries. The user-listed root `plans/` path does not exist in this checkout; the canonical files are under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/`.
- Current plans: `plans/2026-06-15-core-native-payments-a5.md`, `plans/2026-06-15-core-native-payments-a6.md`, `plans/2026-06-20-core-native-payments-a5f-post-a4as-cutover-rehearsal.md`, `plans/2026-06-20-core-native-payments-a5g-rollout-multisite-gate.md`, and `plans/2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md` under the session folder.
- Product source: `plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionService.php`, `plugins/woocommerce/src/Internal/Payments/Shadow/NativePaymentsShadowMode.php`, and related focused tests.
- Harness/evidence only: `tools/woopayments-merge/HARNESS.md`, `README.md`, A5f/A5g/A4aq scripts, and latest rollups under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`.
- Git state for harness lifecycle: `git ls-files tools/woopayments-merge` reports `0`; `.git/info/exclude` explicitly ignores `/tools/woopayments-merge/`; `git status --short --ignored tools/woopayments-merge` reports the directory as ignored.

## Verdict

`RECOMMEND_GATE_REFRESH`

A5f, A5g, and A4bd collectively make the local cutover/admin-runtime baseline green under their stated limits, but they deliberately do not claim final mandatory production default-on, canary/error/perf readiness, or A6 cleanup. I did not find a source-backed local product defect after A5g that should become an implementation slice before a formal A5 exit decision. The next coherent slice is an A5h gate/baseline refresh: reconcile current product source plus the A5f/A5g/A4bd evidence and decide whether A5 exits as "local cutover readiness green, production rollout/default-on intentionally deferred and fail-closed" or remains open pending a release/default-on decision.

## Findings

### 1. What remains before A5 can be considered exited

A5f is green for the connected target-store handoff path. Its rollup, `data/a5f-post-a4as-cutover/a5f-cutover-rehearsal.json`, passed native baseline readiness, plugin-owned default-off state, real browser soft one-click disable, opt-in mandatory auto-deactivation, activation-guard expected failure, synthetic blocked mandatory path, local WPCOM readiness, owner user-token readiness, WCPay V1 transport continuity, cleanup, and a clean target debug-log scan.

A5g is green for explicit rollout defaults and multisite runtime ownership. Product source now has `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED = false` and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED = false`, and focused tests assert those defaults are fail-closed while still filter-overridable. The A5g rollup, `data/a5g-multisite-runtime/a5g-multisite-runtime-gate.json`, passed 28 phases covering inactive, per-site active, network-active, and post-deactivation ownership on a real multisite test env.

A4bd is green as the latest post-A4bc accumulated A4/N12 refresh. Its rollup, `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`, reports `status=pass`, no failures, no incomplete checks, and 13/13 checks passing across admin source, browser admin, optional admin, checkout browser, bundle, perf, and log phases.

The remaining A5 gap is not a known local product parity bug. It is the formal stage-boundary decision that A5 has either:

- exited with mandatory/native runtime defaults still intentionally false and future production rollout deferred, or
- not exited because final default-on/canary/error/perf rollout readiness still needs a release/default-on implementation and gate.

Current source backs that distinction. `NativePaymentsRuntimeArbiter::is_native_runtime_enabled()` feeds `woocommerce_native_payments_enabled` from the false default constant. `WooPaymentsCutoverController::is_mandatory_cutover_enabled()` feeds `woocommerce_woopayments_native_mandatory_cutover_enabled` from the false default constant. `maybe_auto_deactivate_plugin()` only runs when mandatory cutover is enabled, the plugin owns runtime, and `WC_ALLOW_MERGED_FEATURE_PLUGINS` is not true. `guard_woopayments_activation()` still requires mandatory cutover plus clean preflight before blocking activation.

There is limited product canary surface in `NativePaymentsShadowMode`, but it is an opt-in A1 shadow logger (`woocommerce_native_payments_shadow_mode_enabled`) whose comparison type still records `a1_projection_baseline` and `independent_native_computation=false`. That is useful context, not a source-backed final rollout/canary implementation slice.

### 2. Concrete next slice or another gate refresh

Recommend another gate/baseline refresh, not product implementation. A5h should be an A5 exit gate review over current source and the latest evidence, with no product edits unless the review finds a source-backed blocker. A4bd just refreshed the accumulated A4/N12 boundary, so rerunning the same A4 gate again immediately would be low-value unless the environment changed. The useful refresh is A5-specific: fold A5f handoff, A5g rollout/multisite, A4bd admin/checkout/perf/log freshness, and the fail-closed product defaults into one A5 exit verdict.

This keeps the evidence honest. A5g’s own plan and baseline say it does not flip the final mandatory production default and does not replace later A5 canary/perf/error gates. A4bd’s closeout says it does not relax A5g’s fail-closed rollout defaults, does not enable mandatory cutover, and does not claim final production rollout/canary/error/perf or A6 cleanup readiness.

### 3. Whether A6 cleanup/removal should start now

Do not start A6 cleanup/removal now.

The old A6 plan is obsolete in this workspace. It assumes `tools/woopayments-merge/` is a tracked transition harness to delete with `git rm -r`, but the directory is currently ignored through `.git/info/exclude` and `git ls-files tools/woopayments-merge` returns zero tracked files. Deleting it would remove local verification tools, not clean tracked source. The old plan also references `tools/woopayments-merge/a6-cleanup-audit.sh`, which is not present in the current harness listing, and it includes `markdownlint .agents/...`, which conflicts with the current instruction not to lint `.agents`.

A6 also depends on a true A5 exit and Bucket-D/sunset readiness. Current product code still contains transitional runtime and cutover components with explicit comments and defaults for a future release/default-on flip. The latest baselines explicitly say A5g and A4bd do not claim A6 cleanup readiness.

### 4. Likely files and evidence paths for the recommended A5h refresh

Primary source paths:

- `plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionService.php`
- `plugins/woocommerce/src/Internal/Payments/Shadow/NativePaymentsShadowMode.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativePaymentsRuntimeArbiterTest.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

Primary evidence paths:

- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5f-post-a4as-cutover/a5f-cutover-rehearsal.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5g-multisite-runtime/a5g-multisite-runtime-gate.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate-admin-browser-gate.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate-optional-admin-admin-browser-gate.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4bd-post-a4bc-accumulated-gate-checkout-browser-gate.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/perf-reference.json`
- `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/perf-target.json`

Harness evidence sources only:

- `tools/woopayments-merge/a5f-cutover-rehearsal.py`
- `tools/woopayments-merge/a5g-multisite-runtime-gate.py`
- `tools/woopayments-merge/a5g-multisite-runtime-state.php`
- `tools/woopayments-merge/a4aq-accumulated-gate.py`
- `tools/woopayments-merge/perf-surface-gate.sh`
- `tools/woopayments-merge/compare-measured-gates.py`
- `tools/woopayments-merge/HARNESS.md`

Recommended A5h output should be a concise A5 exit gate artifact and staging/spec-baseline update, not an A6 deletion or product-code patch unless the gate review finds a concrete blocker.
