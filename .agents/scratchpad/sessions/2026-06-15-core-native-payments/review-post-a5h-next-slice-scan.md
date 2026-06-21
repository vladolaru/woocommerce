---
session: 2026-06-15-core-native-payments
type: report
by: codex
created: 2026-06-21 02:52
target: post-A5h next-slice scan
reconciles:
  - README.md
  - staging-log.md
  - spec-conformance-baseline.md
  - analysis-a4bc-next-slice-selection.md
  - analysis-a4bd-post-a4bc-accumulated-gate-refresh.md
  - analysis-a5g-next-slice-selection.md
  - analysis-a5h-post-a4bd-cutover-readiness-refresh.md
  - review-a4au-settings-inventory.md
  - review-a4bc-section-polish-inventory.md
  - review-a4bd-accumulated-gate.md
  - review-a5h-cutover-readiness-refresh.md
  - review-a5h-next-slice-scan.md
  - plans/2026-06-15-core-native-payments-a6.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - supervisor-prompt-2026-06-17-1311.md
last_updated: 2026-06-21 02:54
status: final
---

# Post-A5h Next-Slice Scan

## Scope

Read-only scan after A5h to determine whether a concrete locally actionable product or gate slice remains before any production/default-on decision, without accessing WPCOM sandbox, modifying WPCOM code, pushing, touching trunk, linting `.agents`, or treating `tools/woopayments-merge` as anything beyond ignored local harness evidence.

## Verdict

RECOMMEND_DECISION_ROLLUP.

After A5h, I do not see a concrete source-backed local product or gate slice that should run before the production/default-on release decision. The best next move is a concise decision/rollup artifact that states the local A4/A5 baseline is complete under recorded limitations, while production rollout/default-on, canary/error-rate, WPCOM production readiness, exact production perf, and release sequencing remain deferred and fail-closed.

## Source Basis

The current session `README.md` records A5h as closed at 2026-06-21 02:50 EEST with `PASS_WITH_LIMITATIONS`: A5f rerun passed 27/27 phases, A5g refresh passed 28/28 phases, the first A5f plugin-active PHP notice remains diagnostic history, and A5h does not claim production mandatory/default-on rollout, canary/error-rate, WPCOM production readiness, exact perf readiness, release sequencing, or A6 cleanup.

`staging-log.md` and `spec-conformance-baseline.md` agree with that boundary. A4bd is the latest accumulated A4/N12 refresh and reports `status=pass`, 13/13 checks passed, zero failures, and zero incomplete checks under explicit limitations. A5h then refreshed A5f connected target-store cutover rehearsal and A5g multisite runtime ownership without product-code changes and recorded the same fail-closed rollout posture.

The late settings/admin parity inventories do not expose a new product slice after A5h. `review-a4au-settings-inventory.md` listed payment-method guidance, VAT, fraud, express flags/notices, section copy/loading, and sandbox polish as residuals. `analysis-a4bc-next-slice-selection.md` and `review-a4bc-section-polish-inventory.md` show the final bounded settings residual was section loading/docs/copy polish. `analysis-a4bd-post-a4bc-accumulated-gate-refresh.md` then records that A4av through A4bc worked through the A4au settings residuals, and no fresh source-backed settings residual was stronger than the accumulated gate refresh.

The product source still encodes a deferred release/default-on decision. `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` remains `false`, with the code comment saying it stays false until final A5 stage-boundary gates approve the release/default-on flip. `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` also remains `false`; activation blocking and auto-deactivation require mandatory cutover plus clean readiness and no `WC_ALLOW_MERGED_FEATURE_PLUGINS` bypass. `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is now empty, so provider-event disposition is not a new local blocker in the current source.

## Answers

### 1. Remaining Local Product Or Gate Slice

No. A4bd and A5h together leave no concrete locally actionable product/gate slice that is source-backed enough to run before the production/default-on decision. The remaining items are decision/release-scope limitations, not undispositioned local code gaps: mandatory/default-on rollout, production canary/error-rate monitoring, WPCOM production readiness, exact production performance, and release sequencing.

### 2. Best Next Broad Chunk If One Were Needed

No product slice is recommended. If a next broad chunk is required, it should be a decision rollup, not implementation: reconcile `README.md`, `staging-log.md`, `spec-conformance-baseline.md`, `review-a4bd-accumulated-gate.md`, `review-a5h-cutover-readiness-refresh.md`, the A5h A5f/A5g rollups, and the source defaults into one explicit statement: local A4/A5 readiness baseline is green under limitations; production rollout/default-on is intentionally deferred and fail-closed.

Likely evidence inputs for that rollup are `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`, `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json`, `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json`, `NativePaymentsRuntimeArbiter.php`, `WooPaymentsCutoverController.php`, `NativePaymentsRuntimeArbiterTest.php`, and `WooPaymentsCutoverControllerTest.php`. I would not rerun the gates unless the environment or source changes; A5h just refreshed them.

### 3. If No Slice Remains

Yes: write a decision/rollup artifact. It should not claim final production readiness. It should say the local branch has a green A4/A5 baseline for accumulated admin/checkout/runtime cutover under documented limitations, and that the branch remains fail-closed for production/default-on until a release owner makes a separate rollout decision and supplies any production-only canary/error/perf/WPCOM evidence required by that decision.

### 4. A6 Status

A6 remains deferred. The old A6 plan is stale because it assumes `tools/woopayments-merge/` is tracked transition scaffolding to delete with `git rm -r`, references `tools/woopayments-merge/a6-cleanup-audit.sh`, and includes `markdownlint .agents/...`. Current repo state contradicts those assumptions: `git ls-files tools/woopayments-merge` returns zero tracked files, `.git/info/exclude` ignores `/tools/woopayments-merge/`, and no root-level `a6*` harness file is present. Deleting the harness now would remove ignored local verification infrastructure, not clean product code. A6 needs a fresh plan after a true production/default-on release decision and a current harness lifecycle decision.
