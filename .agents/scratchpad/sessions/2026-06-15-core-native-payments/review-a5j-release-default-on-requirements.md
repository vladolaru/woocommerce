---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex
created: 2026-06-21 03:11
target: A5j release/default-on requirements after A5i and A6 boundary hold
reconciles:
  - analysis-a5i-local-readiness-decision-rollup.md
  - review-a6-boundary-audit.md
  - review-a6-bucket-d-source-audit.md
  - review-a6-facade-callers-audit.md
  - staging-log.md
last_updated: 2026-06-21 03:13
status: final
---

# A5j Release/Default-On Requirements Audit

## Prompt

> Read-only A5j release/default-on requirements audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source/docs reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Identify the exact release/default-on requirements that remain after A5i and A6 boundary hold. Read at least:
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md A5/A6 and §0.6 invariant sections.
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/design-spec.md §4.5, §4.5a, §5.
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-boundary-audit.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md A5h/A5i/A6 entries
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5j-release-default-on-requirements.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: READY_FOR_RELEASE_DECISION_PACKET / NEEDS_LOCAL_EVIDENCE / BLOCKED_ON_PRODUCTION_DECISION. For each requirement, classify as local-evidence-proven, local-evidence-rerunnable, or production/release-only. Cite source paths/artifacts. Do not overclaim production readiness.

## Verdict

BLOCKED_ON_PRODUCTION_DECISION.

The local release/default-on packet is source-backed enough to state that A4/A5 local readiness is green under recorded limitations and that no additional source-backed local product or harness slice is currently required before a release-owner decision. It is not a production/default-on readiness claim. The branch remains fail-closed because the runtime default and mandatory cutover default are still false in source, and the remaining decisive requirements are production/release-only: canary parity/error-rate/perf, WPCOM production readiness, exact production perf, live-data/queue safety, release sequencing, and the actual default/mandatory flip. A6 product cleanup is held behind that same sequencing and must not be used as a substitute for the release decision.

## Source Basis

Read the required canonical and session artifacts: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md` §0.6 and A5/A6 (`:44-50`, `:133-151`, `:222-232`), `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/design-spec.md` §4.5/§4.5a/§5 (`:367-416`, `:454-494`), `analysis-a5i-local-readiness-decision-rollup.md`, `review-a6-boundary-audit.md`, and `staging-log.md` A5h/A5i/A6 entries (`:1414-1445`). I also checked the BC manifest and A6 source/facade audits because the A6 boundary cites Bucket C/E, Tracks, and Stripe Billing data-safety constraints.

## Requirement Classification

| Requirement | Classification | Evidence and remaining requirement |
|---|---|---|
| Runtime mutual exclusion / one payments runtime, no dual registration, no double-charge | local-evidence-proven | Canonical invariant requires one payments runtime at every stage (`implementation-plan.md:44-50`) and design §4.5 makes plugin-wins mandatory while the plugin is active (`design-spec.md:367-380`). Current source encodes that rule in `NativePaymentsRuntimeArbiter.php:12-32` and keeps `DEFAULT_NATIVE_RUNTIME_ENABLED = false` until the release/default-on flip (`NativePaymentsRuntimeArbiter.php:86-104`). Tests cover plugin-wins, network-active plugin-wins, native ownership only when plugin absent and enabled, and fail-closed default (`NativePaymentsRuntimeArbiterTest.php:98-183`). A5f/A5g local gates also prove plugin/native ownership transitions in single-site and multisite states (`staging-log.md:1417-1421`, `:1431-1432`). No production readiness is implied beyond this local invariant proof. |
| Native runtime default remains fail-closed until release owner flips it | local-evidence-proven | Source default is explicitly false and comments tie the future flip to final A5 release/default-on gates (`NativePaymentsRuntimeArbiter.php:97-104`). Focused tests assert the default is false and that the rollout filter receives that default (`NativePaymentsRuntimeArbiterTest.php:176-202`). A5i records the same fail-closed default (`analysis-a5i-local-readiness-decision-rollup.md:41-45`, `staging-log.md:1431-1433`). The remaining flip is release-only. |
| Soft cutover notice, one-click disable, post-disable reassurance, and plugin-owned overlap | local-evidence-proven | Canonical A5 requires the soft notice and one-click disable (`implementation-plan.md:133-143`; `design-spec.md:391-398`). Source renders the soft and success notices (`WooPaymentsCutoverController.php:689-711`) and deactivates the standalone plugin only through cutover (`WooPaymentsCutoverController.php:330-355`). A5f rerun passed `soft-cutover-browser-gate`, `post-soft-native-state`, and default-off reactivation phases with no failures (`a5f-rerun-1/a5f-cutover-rehearsal.json`, 27 phases; `staging-log.md:1417-1419`). This proves local mechanics, not release rollout. |
| Mandatory auto-deactivation and activation guard mechanics under an explicit rollout gate | local-evidence-proven | Canonical A5 mandatory phase is version/default-on gated (`implementation-plan.md:138-145`; `design-spec.md:399-407`). Source blocks reactivation only when mandatory cutover is enabled, preflight is ready, and the developer bypass is absent (`WooPaymentsCutoverController.php:364-381`), and auto-deactivates only under the same fail-closed conditions (`WooPaymentsCutoverController.php:461-485`, `:622-630`). Tests prove default-off, filter-enabled guard behavior, mandatory auto-deactivation without user plugin caps, success notices, failed auto-deactivation cleanup, bypass behavior, and network deactivation (`WooPaymentsCutoverControllerTest.php:320-389`, `:391-535`). A5f rerun passed mandatory browser and activation-guard phases (`staging-log.md:1419`). The production mandatory flip remains separate. |
| Cutover preflight blocks unsafe local states | local-evidence-proven | Source preflight blocks disabled native runtime, unavailable native transport, platform connection failures, unavailable admin surfaces, undispositioned provider events, undispositioned operational queue hooks, unavailable financial remediation, invalid filters, and legacy Stripe Billing markers (`WooPaymentsCutoverController.php:389-458`, `:520-630`). `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is empty (`WooPaymentsEventIngestor.php:34-40`), and default pending operational queue hooks are empty (`WooPaymentsCutoverController.php:126-132`). Tests prove event/queue blockers, platform connection blockers, financial remediation blockers, legacy Stripe Billing blockers, and clean-store allowance (`WooPaymentsCutoverControllerTest.php:540-671`, `:720-814`). A5f local rehearsal additionally passed local WPCOM readiness, owner-token readiness, transport continuity, synthetic blocker proof, and final log scan (`staging-log.md:1419`). Production WPCOM/data/queue safety is not proven by these local checks. |
| Accumulated local A4/N12 + A5 readiness baseline | local-evidence-proven | A5i fresh evidence records A4bd `status=pass`, 13/13 checks, `failures=[]`, `incomplete=[]`; A5h A5f rerun `status=pass`, 27 phases, `failures=[]`; and A5h A5g `status=pass`, 28 phases in `existing-tests` mode, `failures=[]` (`analysis-a5i-local-readiness-decision-rollup.md:39-45`, `staging-log.md:1431`). A5h limitations still apply: optional admin routes are not full reference content parity, checkout coverage has caveats, settings tokens are representative, and perf is coarse smoke evidence (`staging-log.md:1422-1423`). |
| Local cutover and multisite evidence freshness if release owner asks for a new packet timestamp | local-evidence-rerunnable | The rerunnable artifacts are the A4bd accumulated gate, A5f cutover rehearsal, and A5g multisite runtime gate (`review-post-a5h-next-slice-scan.md:55-59`, `analysis-a5i-local-readiness-decision-rollup.md:39-45`). A5i says not to rerun unless source or environment changed (`analysis-a5i-local-readiness-decision-rollup.md:29`). No current local evidence gap requires a rerun, but these are the right local gates if the release packet needs freshness. |
| Release owner default-on / mandatory rollout decision | production/release-only | A5 canonical exit is not local gate pass; it is “canary cohort green on parity + error-rate + perf before flipping the mandatory default” (`implementation-plan.md:145`). A5i explicitly says production rollout/default-on, mandatory rollout, and release sequencing remain deferred and fail-closed (`analysis-a5i-local-readiness-decision-rollup.md:49-53`; `staging-log.md:1433`). Current source keeps both rollout defaults false (`NativePaymentsRuntimeArbiter.php:97-104`; `WooPaymentsCutoverController.php:47-55`). |
| Canary parity, error-rate, and production perf before mandatory default flip | production/release-only | The plan requires canary parity/error-rate/perf before mandatory default (`implementation-plan.md:145`). Design §5 says perf claims must be measured per surface, with canary native-only wall time and DB query count compared against baseline for charge-path perf (`design-spec.md:477-494`). Local A4/A5 gates are useful evidence but do not supply production canary error rates or exact production performance (`staging-log.md:1423`, `analysis-a5i-local-readiness-decision-rollup.md:51`). |
| WPCOM production readiness, connected account owner-token readiness, and connected-plugin registry safety | production/release-only | Design §4.5 says Jetpack connection identity is largely non-blocking but native still must ensure registry safety and route/queue continuity across the swap (`design-spec.md:374-378`). A5f proved local WPCOM readiness and owner-token readiness against local env (`staging-log.md:1419`), while A5i explicitly defers WPCOM production readiness (`analysis-a5i-local-readiness-decision-rollup.md:51`; `staging-log.md:1433`). Do not treat local WPCOM checks as production WPCOM approval. |
| Live Action Scheduler/webhook/queue safety and financial migration completion at production cutover | production/release-only | Canonical A5 requires webhooks and AS jobs continue/drain, financial migrations applied, owner-token and registry verified (`implementation-plan.md:139-143`). BC manifest requires preserving/draining live operational hooks and treating fee remediation as DROP-AFTER-MIGRATION only after effects are complete (`bc-manifest.md:86-90`). A5f local transport continuity and remediation scheduling prove mechanics, but live production backlog/drain state and affected-order completion are release-only. |
| Legacy Stripe Billing / WCPay native subscriptions live-data safety | production/release-only | BC manifest says the Stripe Billing engine is DROP but gated on proving no live merchant data path is broken or migration has run (`bc-manifest.md:111-115`). Source still blocks cutover when legacy markers exist (`WooPaymentsCutoverController.php:451-456`, `:723-730`) and tests make the blocker non-removable by filters and mandatory auto-deactivation (`WooPaymentsCutoverControllerTest.php:720-798`). A6 audits classify the settings toggle as future cleanup but keep the marker guard until release/default-on data-safety evidence exists (`review-a6-bucket-d-source-audit.md:75-77`, `review-a6-boundary-audit.md:37-39`). |
| Bucket C/E preservation, including WC Subscriptions integration, persisted IDs/meta/routes, and surviving Tracks contracts | local-evidence-proven | The non-negotiable invariants require Bucket-E byte-identical contracts and Bucket-C integrations (`implementation-plan.md:44-50`). BC manifest preserves WC Subscriptions integration and hard external data/contracts (`bc-manifest.md:27-31`, `:80-95`) and requires Tracks name/props continuity for surviving surfaces (`bc-manifest.md:98-106`). A4bd/A5h/A5i provide local packet evidence under limitations (`staging-log.md:1416-1423`, `:1431-1433`). Production telemetry pipeline confirmation, if required by release owners, remains release-only. |
| Exact production performance and approved budgets across §5.3 surface matrix | production/release-only | Design §5.3 requires per-surface gates and written reasons for skipped surfaces, including checkout subpaths, process/refund/capture, REST boot, admin enqueue, gateway registration, options/autoload, AS scheduling/drain, and distribution footprint (`design-spec.md:477-494`). A4bd/A5h record local large-delta/perf-smoke limitations, not exact production latency proof (`staging-log.md:1423`; `review-a5h-cutover-readiness-refresh.md:98-105`). |
| A6 cleanup execution and first cleanup slice | production/release-only | Canonical dependency order is A5 cutover then A6 cleanup (`implementation-plan.md:147-151`, `:222-232`). A6 boundary verdict is `HOLD_FOR_RELEASE_DECISION` and says not to begin product-code cleanup until production/default-on, mandatory rollout, canary/error-rate, WPCOM production readiness, exact production perf, and release sequencing are settled (`review-a6-boundary-audit.md:21-39`; `staging-log.md:1438-1444`). Source inventory found a future first A6a candidate, but the A6a plan is explicitly held, not active (`plans/2026-06-21-core-native-payments-a6a-remove-deprecated-bundled-subscriptions-settings.md:13-18`). |

## Decision Packet Shape

The honest packet line is: local A4/A5 readiness is green under recorded limitations; native runtime and mandatory cutover remain fail-closed; no concrete source-backed local product/harness slice remains before a production/default-on decision; A6 cleanup is held for release/default-on sequencing; production/default-on remains blocked on a release-owner decision with canary parity, error-rate, production WPCOM, live data/queue safety, exact perf, and release sequencing evidence.
