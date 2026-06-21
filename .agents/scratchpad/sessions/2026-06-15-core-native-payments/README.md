---
session: 2026-06-15-core-native-payments
type: readme
by: codex
created: 2026-06-15 19:07
status: draft
last_updated: 2026-06-21 03:42
---

# Core Native Payments Session

## Current Status

As of 2026-06-21 03:42 EEST, the read-only A5l blocker audit is final in `analysis-a5l-blocked-audit.md` and `review-a5l-blocker-audit.md` with verdict `BLOCKED_THRESHOLD_MET`. The audit found the same material blocker repeated across the A5i -> A6 -> A5j -> A5k continuation sequence: local A4/A5 readiness is green under recorded limitations, A6a is a valid future cleanup but held by plugin rollback/coexistence and release/default-on sequencing, and no concrete local product, harness, docs, or evidence slice would materially advance the full objective without production/release-owner evidence. The exact blocker is missing production/release-owner evidence and decisioning for canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual native runtime/default/mandatory flips. This is a blocked-state finding only, not a goal-completion or production-readiness claim. Final hygiene outside `.agents` is clean: branch-ahead only, no product diff, and `git diff --check` clean. No product or harness files were edited, no WPCOM access occurred, no push/commit occurred, and `.agents` was not linted.

> **Prompt:** "Read-only A5l blocker audit stress test for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code or harness code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source/docs reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Stress-test whether the controller may legitimately mark the active long-running goal blocked after repeated A5j/A5k production-only stop lines, or whether there is still meaningful local work that advances the objective without production/release-owner evidence. Read at least:
> - analysis-a5j-release-default-on-readiness.md
> - analysis-a5k-next-local-slice-after-a5j.md
> - review-a5k-release-sequencing-next-move.md
> - review-a5k-n12-admin-parity-residuals.md
> - review-a5k-a6-cleanup-boundary.md
> - staging-log.md A5i/A6/A5j/A5k entries
> - spec-conformance-baseline.md A5i/A5j/A5k addenda
> - current source for runtime default, mandatory cutover default, admin surfaces readiness, provider event set, A6a subscriptions flag.
>
> Questions:
> - Does current evidence show the same blocker has repeated across at least three consecutive goal continuations?
> - Is the blocker a true impasse rather than hard/slow work?
> - Is there any concrete local product, harness, docs, or evidence slice that would materially advance the full objective without production/default-on evidence?
> - If blocked, state the exact blocker and the evidence. If not blocked, name the next local action.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5l-blocker-audit.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include verdict: BLOCKED_THRESHOLD_MET / NOT_BLOCKED_LOCAL_ACTION_EXISTS / NEEDS_MORE_EVIDENCE. Cite exact artifacts/source. Do not overclaim goal completion."

As of 2026-06-21 03:35 EEST, A5k is final in `analysis-a5k-next-local-slice-after-a5j.md`. The three read-only audits converged: `review-a5k-release-sequencing-next-move.md` returned `PRODUCTION_DECISION_ONLY`, `review-a5k-n12-admin-parity-residuals.md` returned `NO_LOCAL_A4_BLOCKER`, and `review-a5k-a6-cleanup-boundary.md` returned `HOLD_A6_FOR_RELEASE`. Current source and evidence do not expose a local A4/A5/A6 product or harness slice to run before the production/default-on decision. A6a remains a valid future cleanup slice, but is held because removing the deprecated bundled-subscriptions settings contract now would remove Core's mirror of the standalone extension's disable-only `_wcpay_feature_subscriptions` rollback/coexistence path while plugin-wins remains part of the fail-closed safety model. Final hygiene outside `.agents` is clean: branch ahead only, no product diff, and diff-check clean. No product or harness files were edited, no WPCOM access occurred, no push/commit occurred, and `.agents` was not linted.

As of 2026-06-21 03:31 EEST, the read-only A5k A6 cleanup-boundary audit is final in `review-a5k-a6-cleanup-boundary.md` with verdict `HOLD_A6_FOR_RELEASE`. The future A6a deprecated bundled WooPayments Subscriptions settings cleanup remains source-backed, but it should not start while the branch is fail-closed/default-off and the unmodified WooPayments extension can still own runtime on rollback. Current Core exposes the same disable-only `_wcpay_feature_subscriptions` settings contract as the plugin; removing it now would remove a merchant-facing deprecation/disable path and could leave stale plugin-owned state meaningful when the standalone plugin reclaims runtime. No product or harness files were edited, no WPCOM access occurred, no push/commit occurred, and `.agents` was not linted.

> **Prompt:** "Read-only A5k A6 cleanup-boundary audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code or harness code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source/docs reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Stress-test whether A6 product cleanup really must remain held after A5j, with special attention to the future A6a deprecated bundled WooPayments Subscriptions settings cleanup candidate. Read at least:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a6-replanned-cleanup-readiness.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-boundary-audit.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-bucket-d-source-audit.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-21-core-native-payments-a6a-remove-deprecated-bundled-subscriptions-settings.md
> - implementation-plan.md A6 and bc-manifest Bucket D/C sections at /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/
> - current source for `_wcpay_feature_subscriptions`, `is_wcpay_subscriptions_enabled`, `WooPaymentsLegacySubscriptionsGuard`, and WC Subscriptions renewal integration.
>
> Questions:
> - Is the deprecated bundled-subscriptions settings cleanup safe before release/default-on, or would it change merchant-facing behavior while the branch is still fail-closed/default-off?
> - If safe, what exact local TDD/gate slice should run now?
> - If unsafe/held, what source-backed reason makes it release-sequencing dependent rather than just conservatism?
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5k-a6-cleanup-boundary.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include verdict: START_A6A_NOW / HOLD_A6_FOR_RELEASE / NEEDS_MORE_SOURCE_EVIDENCE. Cite exact current source and artifact evidence."

As of 2026-06-21 03:30 EEST, the read-only A5k release-sequencing audit is final in `review-a5k-release-sequencing-next-move.md` with verdict `PRODUCTION_DECISION_ONLY`. The audit found no source-backed local product, harness, or cleanup slice that should run before the production/default-on release decision. Local A4/A5 readiness and A5j freshness gates are already evidenced under recorded limitations; A6 has a source-backed future cleanup candidate but remains held for release/default-on sequencing; the current blockers are release-owner/production evidence: canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual default/mandatory flip. No product code or harness code was edited, no WPCOM sandbox access occurred, no push/commit occurred, and `.agents` was not linted.

> **Prompt:** "Read-only A5k release-sequencing audit for WooCommerce Core native WooPayments merge.
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
> Determine whether, after A5j, there is any source-backed local slice that should run before the production/default-on release decision, or whether the current blocker is genuinely production/release-only. Read at least:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5j-release-default-on-readiness.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md A4bd/A5h/A5i/A5j addenda
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md A4bd/A5h/A5i/A6/A5j entries
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md N8/N9/N10 sections
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md A5/A6 and §0.6 sections.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5k-release-sequencing-next-move.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include verdict: LOCAL_SLICE_AVAILABLE / PRODUCTION_DECISION_ONLY / REOPEN_A4_OR_A6. For each claimed remaining requirement, classify local-actionable, release-only, or already-evidenced, and cite exact current artifacts/source. Do not overclaim; verify against current files where possible."

As of 2026-06-21 03:26 EEST, the A5j local release/default-on packet is final under the recorded limits. Focused guard PHPUnit passed for `NativePaymentsRuntimeArbiterTest`, `WooPaymentsCutoverControllerTest`, and `WooPaymentsEventIngestorTest` with 123 tests and 391 assertions; the fresh target probe in `data/a5j-target-cutover-state.json` reports native ownership, standalone WooPayments inactive, empty preflight failures, `ready: true`, and no failures. `review-a5j-release-default-on-requirements.md` returned `BLOCKED_ON_PRODUCTION_DECISION`; `review-a5j-local-gate-opportunities.md` returned `RERUN_A5_ONLY`.

The A5j A5f connected target-store rehearsal initially failed only at final debug-log scan because one fresh standalone `woocommerce-payments` textdomain notice appeared during the soft-cutover browser window. That failure is retained as diagnostic history in `data/a5j-release-default-on-readiness/a5f/a5f-cutover-rehearsal.json`. Activation alone, plugin-active `wp-admin/plugins.php`, and isolated soft cutover did not reproduce the notice under a local trace helper. After closing stale local target/reference tabs in the shared Playwriter session, removing the trace helper, and clearing the target log, the clean rerun at `data/a5j-release-default-on-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json` passed with 27 phases, no failures, target restored native-owned, standalone WooPayments inactive, and final target debug log at 0 bytes.

The A5j A5g multisite runtime rerun at `data/a5j-release-default-on-readiness/a5g/a5g-multisite-runtime-gate.json` passed with 28 phases, no failures, and `runtime_mode=existing-tests`; the post-run restore probe reports `home=http://store8889.localhost:8087`, `is_multisite=false`, and `active_plugins=[]`. A5j does not claim production/default-on readiness, production WPCOM readiness, canary/error-rate readiness, exact production perf, live queue/financial/Stripe Billing data safety, release sequencing, or A6 cleanup readiness. A6 product cleanup remains held until the release/default-on decision is settled. No product or harness files were edited for A5j, no WPCOM sandbox access occurred, no Stripe writes occurred, no push/commit occurred, and `.agents` was not linted.

As of 2026-06-21 03:14 EEST, the read-only A5j local gate opportunity audit is final in `review-a5j-local-gate-opportunities.md` with verdict `RERUN_A5_ONLY`. If the release/default-on packet needs one more local freshness stamp after A5i, the useful rerun is A5f connected target-store cutover rehearsal plus A5g multisite runtime ownership, not the full A4aq accumulated gate. The audit explicitly says this only strengthens local cutover recency and does not prove production/default-on rollout, canary/error-rate, production WPCOM readiness, exact production perf, or release sequencing.

> **Prompt:** "Read-only A5j local gate opportunity audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code or harness code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source/docs reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Find which ignored local harness gates or existing scripts can be rerun safely now to strengthen the release/default-on decision packet after A5i, without pretending to prove production rollout. Read at least:
> - tools/woopayments-merge/HARNESS.md
> - tools/woopayments-merge scripts list and relevant names.
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md A4bd/A5h/A5i/A6 entries
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5j-local-gate-opportunities.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: RERUN_A5_ONLY / RERUN_ACCUMULATED_PLUS_A5 / NO_USEFUL_LOCAL_RERUN. For each candidate command, explain what it proves, what it does not prove, expected environment risk, and whether it is worth running now. Cite files/artifacts/scripts."

As of 2026-06-21 03:13 EEST, `review-a5j-release-default-on-requirements.md` is final with verdict `BLOCKED_ON_PRODUCTION_DECISION`. The audit classifies local A4/A5 readiness, fail-closed defaults, mutual exclusion, soft/mandatory cutover mechanics, and deterministic preflight behavior as locally proven, with A4bd/A5f/A5g available as rerunnable freshness gates if a release owner asks. It keeps production/default-on blocked on release-owner evidence: canary parity/error-rate/perf, WPCOM production readiness, live queue/financial/Stripe Billing data-safety, exact production perf, release sequencing, and the actual default/mandatory flip. A6 cleanup remains held for release/default-on sequencing. No product files were edited, no WPCOM sandbox access occurred, no push/commit occurred, and `.agents` was not linted.

> **Prompt:** "Read-only A5j release/default-on requirements audit for WooCommerce Core native WooPayments merge.
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
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5j-release-default-on-requirements.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: READY_FOR_RELEASE_DECISION_PACKET / NEEDS_LOCAL_EVIDENCE / BLOCKED_ON_PRODUCTION_DECISION. For each requirement, classify as local-evidence-proven, local-evidence-rerunnable, or production/release-only. Cite source paths/artifacts. Do not overclaim production readiness."

As of 2026-06-21 03:08 EEST, A6 cleanup execution is held for release/default-on sequencing. All three A6 read-only audits are reconciled: `review-a6-boundary-audit.md` returned `HOLD_FOR_RELEASE_DECISION`, `review-a6-bucket-d-source-audit.md` returned `CLEAR_FIRST_SLICE` for the deprecated bundled WooPayments Subscriptions settings surface, and `review-a6-facade-callers-audit.md` returned `NO_SAFE_CODE_CLEANUP` for facade/runtime cleanup. The future first A6a slice is documented in `plans/2026-06-21-core-native-payments-a6a-remove-deprecated-bundled-subscriptions-settings.md`, but that plan is marked held, not active. No product files were edited; only ignored scratchpad docs changed.

As of 2026-06-21 03:07 EEST, the read-only A6 Bucket-D source audit is final in `review-a6-bucket-d-source-audit.md` with verdict `CLEAR_FIRST_SLICE`. The safe first cleanup slice is the deprecated WooPayments Subscriptions settings surface around `_wcpay_feature_subscriptions`; the source audit found the Stripe Billing engine, product/price sync writers, migration runners, vendored `subscriptions-core`, and vendored/duplicated runtime libraries already absent from tracked Core source. It explicitly keeps WC Subscriptions gateway integration, Stripe Billing marker guards, retired invoice-event alarms, the canceled-authorization fee remediation runner, plugin coexistence branches, and classic/block checkout assets out of the first slice until release/default-on or API/schema evidence is available. No product files were edited, no WPCOM access occurred, no pushes/commits occurred, and `.agents` was not linted.

> **Prompt:** "Read-only A6 Bucket-D source audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Identify tracked WooCommerce Core code/assets/tests that still look like A6 Bucket-D cleanup candidates after A5i. Focus on deprecated/duplicated WooPayments surfaces from the canonical plan and bc-manifest:
> - WCPay native Stripe Billing engine remnants, subscriptions-core remnants, invoice/subscription/product-sync meta writers that should be retired, while preserving WC Subscriptions integration.
> - WC cross-version compat branches that only existed for plugin cross-version support.
> - vendored/duplicated libraries or duplicated JS/runtime assets now redundant in core.
> - one-shot migration runners that should not remain after A5 unless still needed for data safety.
>
> Read at least:
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md A6 and Bucket D sections.
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md Bucket D sections.
> - Current source under plugins/woocommerce/src/Internal/Payments and client WooPayments paths.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-bucket-d-source-audit.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: CLEAR_FIRST_SLICE / NO_SAFE_CODE_CLEANUP / NEED_MORE_EVIDENCE. For each candidate, cite exact current source paths and explain whether it is safe to remove now, unsafe until release/default-on, or already removed. Keep it concise and source-backed."

As of 2026-06-21 03:06 EEST, the read-only A6 transitional facade/caller audit is final in `review-a6-facade-callers-audit.md` with verdict `NO_SAFE_CODE_CLEANUP`. The audit found no tracked `LegacyContainer` or global production `WC_Payments`/`WCPay`/`wcpay_*` facade definitions to delete, but `LegacyProxy` remains core-wide infrastructure, `WooPaymentsLegacyRuntime` and adapters still have active production callers, legacy request aliases are wired to preserved filters/tests, and `wcpay_`/`woocommerce_payments` strings are largely gateway IDs, settings/options/meta, queue hooks/groups, telemetry/hook compatibility, or subscriptions/Stripe-Billing data-safety guards. No product files were edited, no WPCOM access occurred, no pushes/commits occurred, and `.agents` was not linted.

As of 2026-06-21 03:04 EEST, `review-a6-boundary-audit.md` is final. Verdict: `HOLD_FOR_RELEASE_DECISION`. A6 product-code cleanup should not start now, and the old harness-deletion plan should not be executed. The audit separates safe pre-release work as decision/audit documentation and read-only inventory only; release-bound cleanup remains gated on mandatory/default-on/release sequencing, Bucket C/E preservation, live Stripe Billing data-safety checks, Tracks retirement disposition, and final perf/no-regression evidence. The stale cleanup target is `tools/woopayments-merge`, which remains ignored local verification infrastructure with zero tracked files.

> **Prompt:** "Read-only A6 boundary audit for WooCommerce Core native WooPayments merge.
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
> Audit whether A6 cleanup should begin with product code, a release/default-on decision, or another gate write-back. Use current A5i status and canonical docs to separate:
> - cleanup that is safe before production/default-on,
> - cleanup that must wait until mandatory cutover/default-on/release sequencing,
> - cleanup that is actually stale because it targets ignored local harness files.
>
> Read at least:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5i-local-readiness-decision-rollup.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md A5h/A5i entries
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md A5h/A5i addenda
> - implementation-plan.md A5/A6 sections and bc-manifest Bucket D sections.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-boundary-audit.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: START_A6_CODE / WRITE_A6_DECISION_ONLY / HOLD_FOR_RELEASE_DECISION. Cite exact evidence. Keep it concise and source-backed."

As of 2026-06-21 02:58 EEST, A5i is closed as the local readiness decision rollup after A5h. Fresh checks confirmed A4bd remains `status=pass` with 13/13 checks and zero failures/incomplete checks, A5h A5f rerun remains `status=pass` with 27 phases and zero failures, and A5h A5g remains `status=pass` with 28 phases in `existing-tests` mode and zero failures. Source defaults remain fail-closed: native runtime default false, mandatory cutover default false, and native provider known-unhandled event types empty. Decision: local A4/A5 readiness baseline is green under the recorded limitations, but production/default-on rollout, mandatory rollout, canary/error-rate, WPCOM production readiness, exact production perf, release sequencing, and A6 cleanup remain deferred and fail-closed. Final hygiene passed with no Git-visible diff outside branch-ahead status and no diff-check issues outside `.agents`; no commit was needed. A6 remains deferred because the old harness-deletion plan is stale and `tools/woopayments-merge` is ignored local verification infrastructure with zero tracked files.

> **Prompt:** "Read-only A6 transitional facade/caller audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Audit A6 candidates around god-class indirection and transitional preserve-as-facade shims. Determine whether any `LegacyProxy`, `LegacyContainer`, `WC_Payments`-style facade, global functions/classes, or native compatibility shims remain in WooCommerce Core tracked source and whether internal callers have migrated away enough to remove or deprecate them now. Distinguish external compatibility surfaces that must remain until release/public deprecation from internal-only scaffolding that can be deleted.
>
> Read at least:
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md A6 + Bucket D/facade sections.
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md preserve-as-facade sections.
> - Current source/tests search results for `LegacyProxy`, `LegacyContainer`, `WC_Payments`, `WC_Payments::`, `wcpay_`, and `woocommerce_payments` under plugins/woocommerce.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-facade-callers-audit.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: CLEAR_FIRST_SLICE / NO_SAFE_CODE_CLEANUP / NEED_MORE_EVIDENCE. For each finding, cite source paths and caller evidence. Keep it concise and source-backed."

> **Prompt:** "Read-only A4bd gate review for WooCommerce Core native WooPayments work.
>
> Context and constraints:
> - Repo: /Users/vladolaru/Work/a8c/woocommerce-develop-2
> - Branch: exp/core-native-payments
> - Do not edit product code. Do not access WPCOM sandbox. Do not modify WPCOM. Do not push. Do not lint .agents.
> - Session docs must stay under .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Task:
> Review whether the A4bd post-A4bc accumulated A4/N12 gate result is sufficient to close this slice, or whether any pass/limitation is overstated and needs a source-backed follow-up before closeout. Focus on accumulated evidence after A4av-A4bc settings parity work; do not re-review every product diff line-by-line.
>
> Primary files to read:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-21-core-native-payments-a4bd-post-a4bc-accumulated-gate-refresh.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4bd-post-a4bc-accumulated-gate-refresh.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate-admin-browser-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate-optional-admin-admin-browser-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/a4bd-post-a4bc-accumulated-gate-checkout-browser-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/bundle-reference.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/bundle-target.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/perf-reference.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate/perf-target.json
>
> Also skim latest relevant implementation-log A4bc/A4bd entries and staging-log append area if needed.
>
> Output:
> - Write your review to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4bd-accumulated-gate.md with proper scratchpad YAML frontmatter.
> - Include a verdict: PASS / PASS_WITH_LIMITATIONS / FAIL.
> - If you find a blocker, cite the exact evidence/source path and state the minimum follow-up. If no blocker, say what limitations should be recorded honestly.
> - Keep the report concise and source-backed."

As of 2026-06-21 00:50 EEST, the A4ba read-only code review of the current uncommitted advanced fraud UI diff is final in `review-a4ba-code.md`. It found one medium merchant-facing parity bug: the new allowed-countries notice renders `wcSettings.countries` labels without decoding HTML entities, unlike the WooPayments reference and WooCommerce country data. The A4ba accessibility review is final in `review-a4ba-a11y.md`; it found no critical/high blockers and three medium findings around forced-colors focus visibility, hidden currency cues, and undecoded country names. The focused Jest suite passed with 21 tests in the code-review pass, `git diff --check` was clean for the scoped modified files, no product files were edited by the reviewers, no WPCOM sandbox was accessed, and no commits or pushes occurred. A4az remains closed and committed locally as source/tests `edd99f99c8` (`fix(payments): refresh native fraud ruleset cache`) plus changelog `eafa398f37` (`chore(payments): add fraud ruleset refresh changelog`), with git range `565abbcaad...eafa398f37`.

> **Prompt:** "Review the current uncommitted A4ba frontend diff in /Users/vladolaru/Work/a8c/woocommerce-develop-2 for accessibility regressions only. Scope: plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx, style.scss, and the focused Jest test file. The implementation restores native WooPayments Advanced Fraud settings UI parity: loading skeleton/status, aria-busy while saving, linked notices, allowed-countries notice, purchase/order threshold controls and inline notices. Constraints: read-only review, do not edit product files, do not access WPCOM/sandbox, no commits/pushes. Verify against source and tests; focus on keyboard, labels, aria-describedby/live regions, link accessible names, focus rings, and mobile/responsive accessibility. Write findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-a11y.md with scratchpad frontmatter, then summarize critical/high/medium findings in your final response. If none, say so and mention residual low risks."

> **Prompt:** "Read-only source check for the next reopened-A4/N12 fraud residual after A4ay in `/Users/vladolaru/Work/a8c/woocommerce-develop-2`.
>
> Goal: verify the remaining fraud-protection parity gaps against current native WooCommerce Core and the read-only WooPayments client reference, then recommend the next coherent implementation slice.
>
> Please inspect at least:
> - Native: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
> - Native: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`
> - Native: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/*`
> - Native tests under `plugins/woocommerce/client/admin/client/woopayments/settings/test/`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/`
>
> Questions:
> 1. Is fraud ruleset GET/refresh/defaulting still a source-backed native gap after A4ay? Include exact native/reference files and behavior.
> 2. Is advanced fraud loading/card-detail parity still source-backed? Include concrete missing merchant-facing details.
> 3. Which of those should be the next bounded slice for throughput and risk, and why?
>
> Write your findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4az-fraud-residual-source-check.md` with scratchpad frontmatter (`session: 2026-06-15-core-native-payments`, `type: review`, `by: subagent:<your name>`, current local date from `date`, `status: final`). No hard-wrapped prose. Do not edit product code, do not access WPCOM sandbox, do not push."

> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

> **Prompt:** "Maybe consider using DataViews component for tables since it is a WP component. But only if it naturally make sense, even if there is a visual departure from the reference - we shouldn't try to force DataViews to look like the reference."

> **Prompt:** "Make sure you record the status of the current implementation slice so it survives compactions and you don't go backwards after compactions."

> **Prompt:** "ok. continue"

> **Prompt:** "Merge the WooPayments client into WooCommerce core as a truly native payments runtime (Option C), end-to-end, with no human in the loop.
>
> Work in ~/Work/a8c/woocommerce-develop-2 on the current branch (exp/core-native-payments).
>
>   Read first and follow exactly (these override your priors):
>
>   - ~/Work/a8c/ai-prompts/goals/woopayments-merge/design-spec.md — architecture
>     (§4.5 runtime arbiter, §4.5a cutover/auto-deactivation modeled on src/Packages.php, §5 perf).
>   - .../follow-up/implementation-plan.md — staged plan A0–A6, per-stage gates, and the §0 operating model (read §0 in full: no-HITL substrate, subagent decomposition, JIT planning, fail-closed gates, invariants).
>   - .../follow-up/bc-manifest.md + bc-extraction/ — the backward-compat contract + the 5 buckets
>     + the non-negotiable preserve sets (incl. Tracks/telemetry continuity, §0.3).
>   - .../follow-up/staging-log.md — what's already done (A0) and the gate-evidence format to extend.
>   - .../follow-up/harness-capability-audit.md — exactly what the harness can vs cannot verify
>     deterministically; never read a green gate as more than its stated coverage.
>   - ~/Work/a8c/woocommerce-develop-2/tools/woopayments-merge/HARNESS.md — local env topology + the
>     one-command verification loop (verify.sh) + the runbooks.
>
> Status: A0 (runtime arbiter + verification harness + BC manifest) is built, validated, and committed.
> Produce a just-in-time task plan for A1 (use the project planning skill), then implement A1→A6 in order, planning each stage just before doing it.
>
>   Operating rules:
>
>   - No HITL. Decide from the docs, code, and sensible defaults; proceed. You own the local env — create products/orders/customers, drive checkout/refund/dispute/payout, toggle settings, run WP-CLI. You can also use the browser-interaction skill to drive testing and investigations through the browser.
>   - Local only: target env http://store8889.localhost:8889 (this checkout) and reference oracle http://localhost:8082 (pristine WC + unmodified plugin). Never touch the remote WPCOM sandbox.
>     The ONLY external read allowed is the Stripe CLI (raw account/event data), when needed. Both of these local environments are wired to use the local WPCOM env harness as their WPCOM/Transact platform target (using the wpcom-local CLI).
>   - Use subagents heavily — for implementation (per-capability, isolated where they'd collide) AND for spec and code-review gates throughout (adversarial verification before any "done" counts). Validate every "done" against the harness, and every harness/subagent finding against source. Beware false confidence: a green narrow gate is not a broad guarantee.
>   - Gate everything with tools/woopayments-merge/verify.sh + the individual gates (fail-closed). Never advance a stage on a red gate or an undispositioned BC surface. Honor RULE 0 (zero merchant-facing
>     regression), RULE 1 (no perf regression), and Tracks telemetry continuity (sink-based gate). For surfaces the deterministic gates don't cover (browser checkout incl. 3DS/SCA, broad perf, bundle size), run the documented runbooks and judge. Record gate evidence in the staging log for each stage.
>   - You have the freedom to create probes or other types of helpers in the local environment to help with your work (investigations, performance grading, overcoming gaps or issues in the deterministic testing harness - it is not perfect).
>   - Changelog entries + lint + PHPStan clean before each commit; commit per the project conventions.
>   - Start an implementation log in the current session and keep it updated as you proceed - capture your decisions, changes from the initial specs grounded in your reasoning.
>
>   Done = native owns the payments runtime, the unmodified plugin is cleanly superseded (core deactivates it at cutover), zero merchant-facing regression, deprecated/duplicated code dropped, multi-currency extracted as its own domain, and the public 3PD API track sequenced off the critical path."

> **Prompt:** "Make sure that the WooPayments checkout surfaces (classic or block based) factor in whether the current WooPayments connected account is a test mode account (it should surface specific details about test cards and test badge - check the reference checkout). This behavior needs to be maintained - like I said, no merchant or shopper facing regressions or functionality gaps are allowed."

> **Prompt:** "Also, why isn't http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout loading with the providers list (including WooPayments) like http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout is? Are you absolutely sure it is a limitation of the http://store8889.localhost:8889/ local env or is it something we broke? The frontend UX of the WooCommerce > Settings > Payments page should not be affected by our changes - it needs to work as it used to work because it is not WooPayments specific (WooPayments is a provider just like any other, even if now it is a WC native provider). Think deeply about how we wire that in in a proper way."

> **Prompt:** "When investigating platform side logic, use the local WPCOM repo clone at ~/Work/a8c/wpcom (read only) that is mounted in the local WPCOM env both stores are using."

> **Prompt:** "Do the WooPay buttons get the proper styling that they do on the reference store?"

> **Prompt:** "Having a button that is not properly styled is unacceptable regression."

> **Prompt:** "When we are implementing frontend work, CSS styling needs to be factored in and properly handled, on equal footing - the merge into core can't be only about functionality."

> **Prompt:** "Make sure you keep things bundled separately so the core maintains its frontend performance and adaptability to actually enabled features (e.g. WooPay may be disabled by the merchant)"

> **Prompt:** "Once you are fully done with B3ai, we need to take a step back and reground ourselves to maintain the trajectory towards our goal and continue doing so with high quality, coherence, and predictibility.
>
> A supervisor agent looked at your current progress and provided these instructions to realign mid-flight: ~/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md Read and carefully consider them, course correct, and continue working diligently toward your overall goal.
>
> We have come a long way and made great progress (while learning and improving many things - keep learning as you progress about the right way to approach things to merge WooPayments in the WooCommerce core in a native way while maintaining FULL functional and stylistic parity with the reference store across all the surfaces and workflows that WooPayments currently impacts in a WC store, both on the WP admin/merchant side and the shopper side with product, cart, checkout, order, my account, etc pages). I have full confidence that with the two local WC envs, the local WPCOM/Transact env (no WPCOM code changes or SSH sandbox access are allowed), and the read only Stripe CLI access, browser access, and the testing harness, you have everything you need to get it done, completely."

> **Prompt:** "One addition to the supervisor instructions shared earlier:
>
> N6 — Run a one-time retroactive spec-conformance baseline before starting A4/A5.
>
>   The forward stage-boundary gate (N5) is the right standing process, but A1–A3 and B0–B2 are already
>   declared "done" without ever passing it — the H1–H12 backfill remediated A3 reactively, not against a
>   conformance checklist. Before A4/A5 begins, run the N5 gate once, retroactively, over the accumulated
>   payments + multi-currency runtime: check it against design-spec.md, every touched-surface bucket
>   disposition in bc-manifest.md, and the implementation-plan §0.6 invariants. Record the result in
>   staging-log.md as the spec-conformance baseline.
>
>   This is not re-doing the work — keep it at the contract/architecture level, not a line-by-line re-review.
>   The point is to establish a known-good line so every later stage gate diffs against an audited baseline
>   instead of an assumed one. Fail-closed: anything it surfaces becomes a tracked slice (H## or its true
>   canonical stage) before A4/A5 proceed. Verify any finding against source before acting on it."

> **Prompt:** "N7 — Close the verification-coverage gaps the baseline exposed (harden the gates, not just the code).
>
>   The N6 baseline showed that several "passed" gates are narrower than the claims they backed. Before
>   A4/A5, widen them so the spec-conformance baseline rests on real coverage.
>
>   (a) Extend financial reconciliation beyond refunds — highest leverage. financial-reconcile.sh reconciles
>   only WC-side refund records against Stripe (confirmed at its header, financial-reconcile.sh:5-7), yet it
>   self-describes as "the money-safety oracle — RULE 0 on the money path" and is recorded as "financial
>   reconciliation passed 7/7" in every package entry. §0.6 invariant #5 (no money moves without
>   verification) and design-spec §6.3 R12 require the full matrix: charge amounts, captures/authorizations,
>   full + partial refunds, dispute outcomes, payouts, fees, and multi-currency. Extend the reconciler to
>   cover these (test-mode account, raw Stripe CLI source), then re-run it over the accumulated money paths
>   as part of the baseline. Until then, treat every prior "financial reconciliation passed" as refunds-only,
>   not money-safety-complete.
>
>   (b) Add the Bucket-C / WC Subscriptions end-to-end conformance check. The baseline dispositioned A
>   (payments) and B (multi-currency) but not C. Workstream C requires a real WC-Subscriptions renewal
>   end-to-end on native — assert success + correct order/meta + renewal/failed-renewal emails — diffed
>   against the reference. The renewal code exists (scheduled_subscription_payment, supports[]), but it has
>   never been conformance-gated. Add it to the baseline and to the standing stage gate.
>
>   (c) Treat perf and bundle-size as exercised, not merely available. verify.sh marks broad perf and
>   bundle-size as runbook/judgment gates outside the deterministic PASS. §0.6 invariant #4 (per-surface perf
>   preserved) needs an actual exercised measurement across the touched A3/B2 surfaces against the §5.3
>   baseline — not "the runbook exists." Record the numbers, or mark the invariant explicitly unverified.
>
>   Fail-closed: each is a coverage gap that blocks a trustworthy A4/A5 spec gate — widen the gate, re-run it
>   over the accumulated runtime, and record results in the staging log. Verify each against source first;
>   (a) is most urgent because it retroactively touches every money path already shipped as "done.""

> **Prompt:** "You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on the ignored local harness only. Do not touch WPCOM or any remote sandbox. Do not modify WooCommerce product code. Do not revert other changes; this workspace may have concurrent edits.
>
> Task: implement a first-pass Bucket-C WC Subscriptions conformance gate scaffold in disjoint new files under tools/woopayments-merge only. Use Bohr's source-backed findings below. Create scripts that are honest and fail-closed if preconditions are missing; do not mask implementation bugs.
>
> Context:
> - Harness dir: tools/woopayments-merge (gitignored)
> - Scratchpad session: .agents/scratchpad/sessions/2026-06-15-core-native-payments
> - Target local store: http://store8889.localhost:8889, WP command usually `docker exec -i <target-cli-container> wp --allow-root --user=1`
> - Reference store: http://localhost:8082, WP command `docker exec -i wcpay_wp_default wp --allow-root`
> - Native target must keep separate WooPayments plugin inactive.
> - Bohr findings: Native gateway adds supports and registers scheduled_subscription_payment/failing_payment_method_updated hooks when WC Subscriptions is active. Reference trait does same. Renewal-driving pattern: `WC_Subscriptions_Manager::process_renewal()`, unschedule pending renewal after renewal order exists, then `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`, then `$subscription->get_last_order( 'all', 'renewal' )`. Capture email evidence with `woocommerce_mail_callback_params` and block real transport with `woocommerce_mail_callback`. Full conformance will need browser-created subscriptions; this scaffold may accept existing subscription IDs and fail closed if not provided.
>
> Write ownership:
> - You may create: tools/woopayments-merge/subscriptions-renewal-gate.sh
> - You may create: tools/woopayments-merge/subscriptions-renewal-drive.php
> - You may create/update a short section in tools/woopayments-merge/HARNESS.md only if needed.
> - Do not edit financial-reconcile.*, flow-drive.sh, verify.sh, or product code.
>
> Expected behavior:
> - `subscriptions-renewal-gate.sh` should support a preflight mode that checks WC Subscriptions active, WooPayments native/plugin state, gateway supports, and hook registration on both `--ref` and `--target` WP commands.
> - It should support a compare/drive mode accepting explicit `--ref-subscription-id` and `--target-subscription-id`, run the PHP driver on both sides, and compare normalized JSON facts. If no IDs are provided, exit non-zero with a clear message that browser-created subscription IDs are required.
> - PHP driver should drive renewal for one subscription ID, capture normalized facts: success boolean, subscription id/status, renewal order id/status, payment method, whether renewal belongs to subscription, whether wcs_order_contains_renewal exists/true, key payment meta presence, token/customer meta presence, captured email ids/subjects/classes normalized enough for parity.
> - Keep scripts portable Bash/PHP and fail-closed. Add `bash -n`/`php -l` checks yourself.
>
> Return a concise summary with changed files and commands run."

> **Prompt:** "Make sure you don't over-index on what is reliably measurable. Best to be honest about the possibilities and not chase unreliable numbers. Or at least use them honestly as stop gaps for big deltas in performance to inform that we may be doing something wrong, if their variability is high. But give it a good shot first."

> **Prompt:** "Review the current uncommitted WooCommerce Core diff for H29 native WooPayments provider refund webhooks. Do not edit files. Do not access or modify WPCOM; only read local repos if useful. Scope:
>
> Primary workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2
> Reference WooPayments client (read-only): /Users/vladolaru/Work/a8c/woocommerce-payments
>
> Changed files to inspect:
> - plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php
> - plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandler.php (new, untracked)
> - plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php
>
> Goal: native WooPayments should handle charge.refunded and charge.refund.updated with behavioral parity to the WooPayments extension, then remove only those two events from KNOWN_UNHANDLED_EVENT_TYPES. It must preserve merchant-facing notes/meta, fail closed for malformed or unsafe events, and not generalize refund semantics into the generic payment lifecycle abstraction.
>
> Reference paths likely relevant:
> - /Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-webhook-processing-service.php
> - /Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-order-service.php
> - /Users/vladolaru/Work/a8c/woocommerce-payments/includes/constants/class-refund-failure-reason.php
>
> Verification already run by main agent after current changes:
> - focused ingestor test: 52 tests, 196 assertions, passed
> - broad payment set: 138 tests, 596 assertions, passed
> - direct PHPCS on handler + ingestor test, passed
> - PHPStan on production files, passed
>
> Please report findings first, ordered by severity. Focus on source-backed parity gaps and API/hook behavior. If no issues, say so and note residual risk."

> **Prompt:** "When identifying bigger chunks of implementation (including more mechanical ones), consider using subagent implementors, including parallel ones where they don't risk trampling on each other, to conserve your own context and focus."

> **Prompt:** "Add a task at the bottom of your current task list that, once you are fully done with A5c, I want you to re-open A4 because there is work to be done for feature parity. Read and follow this .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md"

> **Prompt:** "You are implementing a bounded WooCommerce Core A4r slice in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. You are not alone in the codebase: do not revert edits made by others, and adjust to concurrent changes. Do not access or modify WPCOM. Do not push. Do not commit; leave changes in the worktree and report changed paths.
>
> Ownership: Backend PHP only. Do not edit TS/JS/SCSS files. Write tests first and verify RED before implementation.
>
> Goal: Add a small native WooPayments Overview projection and REST route for the Overview action shell.
>
> Read these first:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4r-overview-action-shell-parity.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-19-core-native-payments-a4r-overview-action-shell-parity.md Task 1 only
> - plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php existing account summary tests
> - plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php existing account summary route tests
> - plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php get_account_summary()
> - plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php /account route
> - plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php cached account getters
>
> Implement Task 1 from the plan:
> 1. RED tests in WooPaymentsServiceTest for overview projection shape and no-account fail-closed shape.
> 2. RED tests in WooPaymentsRestControllerTest for GET /wc-admin/settings/payments/woopayments/overview success, capability denial, and service exception handling.
> 3. Create plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php with init( WooPaymentsService $woopayments, WooPaymentsAccountService $account_service ) and get_overview(): array.
> 4. Wire WooPaymentsRestController with optional WooPaymentsOverviewService dependency, route registration near /account, get_overview() handler, and lazy resolver.
>
> Projection requirements:
> - Use WooPaymentsService::get_account_summary() and WooPaymentsAccountService::get_cached_account_data(). Do not force refresh.
> - Return account, account_status, show_update_details_task, overview_tasks_visibility, is_connection_success_modal_dismissed, wpcom_reconnect_url, urls.
> - Do not expose publishable keys, secrets, or raw account data.
> - wpcom_reconnect_url should be '' for now unless an explicit native seam exists; do not invent it.
> - Account status logic should follow reference Stripe status logic: explicit scalar account_data['status'] wins; disabled_reason pending_verification/fields_needed/rejected/other maps as in plan; past_due -> restricted; currently_due with deadline -> restricted_soon; eventually_due without deadline -> enabled; connected otherwise -> complete; no account -> not_connected.
> - Task visibility option keys in the response must be dismissed_todo_tasks, deleted_todo_tasks, remind_me_later_todo_tasks.
> - show_update_details_task true only for restricted_soon with current_deadline or restricted with past_due, matching reference, but also allow unfinished setup to produce a complete-setup frontend task by exposing details_submitted false and status restricted when account data is present but details are not submitted.
>
> Run the focused PHP tests you add. If broader failures happen, report them and do not hide them. Final response: status, changed files, tests run with pass/fail, and any concerns."

> **Prompt:** "Correction to Task 1: because the projection belongs in the new `WooPaymentsOverviewService`, prefer creating `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewServiceTest.php` for projection unit coverage instead of adding a `get_overview()` method to `WooPaymentsService` or testing projection through `WooPaymentsServiceTest`. Keep `WooPaymentsService::get_account_summary()` as an injected dependency used by the new overview service. Still update `WooPaymentsRestControllerTest` for the route."

> **Prompt:** "You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. User constraints: WPCOM code changes are off limits; no WPCOM sandbox access; no push; scratchpad docs only under .agents/scratchpad/sessions/2026-06-15-core-native-payments. Do not revert other agents' changes.
>
> Task: Implement the A4s shared money-movement list/query infrastructure with TDD, disjoint from page-specific implementation.
>
> Required source context:
> - Plan: .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-19-core-native-payments-a4s-money-movement-list-query-parity.md
> - Analysis: .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4s-admin-surfaces-next-slice.md
> - Existing native files: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/{data.ts,types.ts,utils.ts,table.tsx}
> - Existing tests: plugins/woocommerce/client/admin/client/woopayments/admin/test/{money-movement-data.test.ts,money-movement-pages.test.tsx}
> - Local DataViews examples: plugins/woocommerce/client/admin/client/settings-email/settings-email-listing-listview.tsx and packages/js/experimental-products-app/src/product-list/index.tsx
>
> Write set you own only:
> - New/modified: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/query.ts
> - New/modified: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dataviews.tsx (or a small shared list-shell file if DataViews does not fit naturally)
> - Modified: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts only as needed for shared list/query types
> - New tests: plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-query.test.ts

> - New tests: plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-dataviews.test.tsx only if you create a shared DataViews/list-shell component
> Do not edit transactions-page.tsx, disputes-page.tsx, payouts.tsx, payout-details.tsx, style.scss, routes.tsx, PHP files, harness, or docs.
>
> Implementation requirements:
> 1. Use TDD. Add RED tests first and run them to confirm expected failure before production changes.
> 2. Query helpers must parse/serialize provider subroute query state for Settings > Payments React Router locations, not @woocommerce/navigation global wc-admin paths. Preserve default page=1, pagesize/perPage mapping, sort, direction, search, and known filter params: loan_id_is, deposit_id, currency_is, store_currency_is, type_is, status_is, status_is_not, created_after, created_before.
> 3. Provide helpers to convert between native REST query shape (page/pagesize/sort/direction/search/filter params) and DataViews view shape (page/perPage/sort/search/filters/fields) if DataViews is natural. Use @wordpress/dataviews/wp if using DataViews in admin client.
> 4. If you use DataViews, keep summaries/exports/notices outside the component and expose a thin wrapper that accepts fields, rows, view, onChangeView, total, isLoading, empty, optional toolbar actions/header. Do not force DataViews to look exactly like the WooPayments reference TableCard.
> 5. If DataViews cannot naturally support this, create only the query helpers and a minimal semantic list-shell scaffold, and explain why in your final report. Do not overbuild.
> 6. Keep accessibility: semantic buttons/links, labels for search/filters, stable loading/error region if present.
> 7. Run focused JS tests from plugins/woocommerce/client/admin with pnpm run test:js -- money-movement-query and any dataviews test you add. If tests fail for unrelated pre-existing reasons, report exact output.
>
> Final report: list tests run, RED failure observed, files changed, and your DataViews decision with reasoning. Do not commit."

> **Prompt:** "Work in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. You are not alone in the codebase: do not revert or overwrite unrelated edits, and adapt to existing changes. Do not touch WPCOM, remote sandboxes, the standalone WooPayments reference repo, or trunk. Do not push. Do not commit.
>
> Task: Implement A4y Task 1 helper/model parity for the native WooPayments dispute challenge wizard. Narrow write ownership:
> - You may modify: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts
> - You may create: plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts
> - You may create: plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts
> - You may minimally update exports/imports only if required for tests, but do not edit dispute-evidence-form.tsx or other UI files.
>
> Source requirements from the current plan .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-19-core-native-payments-a4y-dispute-challenge-parity.md Task 1:
> 1. Add product type options matching reference values/labels: physical_product, digital_product_or_service, offline_service, event, booking_reservation, subscription, multiple, other.
> 2. Add reason/product/refund/duplicate-aware recommended document helpers for at least the reference-covered reasons: fraudulent, product_not_received, subscription_canceled, product_unacceptable, duplicate, credit_not_processed. Keep labels/descriptions merchant-facing and source-inspired; do not add placeholder text.
> 3. Add needsShipping(reason, productType) with reference rule: shipping only for physical_product and not for duplicate, subscription_canceled, or credit_not_processed.
> 4. Preserve existing buildEvidencePayload clearing behavior exactly.
> 5. Create generateDisputeCoverLetter() in the new helper file. It must be deterministic, not rely on WPCOM/plugin globals, and include dispute id, reason, customer/order facts when available, product description, shipping details when applicable, and refund/duplicate status when supplied.
> 6. Add focused Jest tests in the new test file covering needsShipping, recommended document field keys for common cases, buildEvidencePayload preservation/clearing, and cover letter deterministic content.
>
> Use existing project patterns and imports from nearby tests. Run the focused test if possible:
> pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-evidence-fields.test.ts --runInBand
>
> Return: status, files changed, tests run with outcomes, and any concerns. Do not claim broader A4y completion."

> **Prompt:** "You are reviewing an uncommitted WooCommerce Core A4y slice in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Read-only review only: do not edit files, do not access or modify WPCOM, do not push or commit.
>
> User constraints: WPCOM sandbox and WPCOM repo code changes are off limits. Local WooPayments client clone at /Users/vladolaru/Work/a8c/woocommerce-payments may be read-only reference source if needed. Scratchpad docs live under .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Scope to review:
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx only as used by the form
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx
>
> Review goal: API/payload contract review for the native WooPayments dispute challenge/new-evidence wizard. Check that buildEvidencePayload still preserves clearing behavior, metadata writes, evidence keys accepted by Stripe/WooPayments, submit vs draft payloads, upload field keys, duplicate/refund status behavior, Visa compliance payload behavior, Tracks event names/properties, and failure handling do not break existing native REST contracts or reference-compatible behavior. Pay special attention to the recent correction: there must be no unsupported `refund_receipt_documentation` evidence key in submitted payloads; duplicate/refund receipt branches should use `duplicate_charge_documentation`.
>
> Return findings first, ordered by severity. Include exact file:line references and source-backed reasoning. If no Critical/Important findings, say so and list residual runtime/browser risks. Do not claim gates passed unless you ran them."

> **Prompt:** "Read-only architecture/API review for the current uncommitted A4aj slice in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Do not edit product files, do not commit, do not push, do not access any WPCOM sandbox, and do not modify ~/Work/a8c/wpcom.
>
> Context: This slice restores the native WooPayments Express Checkout settings preview for the Apple Pay / Google Pay settings route. WooPayments is now a native WooCommerce core-owned surface, but must stay bundled/lazy per route and not leak WooPayments-specific frontend assets globally. The local target browser proof passed the filtered A4 gate for target settings-express-payment-request on desktop/mobile. Evidence paths:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aj-admin-browser-gate.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aj-express-preview-browser.json
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aj-express-preview-target.png
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aj-express-preview-target-mobile.png
>
> Review these files, including the new untracked file:
> - plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php
> - plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php
> - plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/appearance-settings.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/style.scss
> - plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx
>
> Focus: architecture boundaries, REST/settings contract shape, native core ownership, route-level/lazy asset scope, avoiding checkout/frontend coupling in admin, and future provider/payment-method extensibility. Also verify whether the publishable key/account ID/locale payload is exposed at the right abstraction level and with no unnecessary Stripe Billing/WooPay coupling.
>
> Write your findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4aj-architecture.md with scratchpad frontmatter (session 2026-06-15-core-native-payments, type review, by subagent:architecture-reviewer, status draft; use current local time from date). No hard-wrapped prose. Then return a concise summary with severity-ranked findings. If no issues, say so and mention residual risks."

## Artifacts

- `implementation-log.md` records decisions, evidence, and stage progress.
- `plans/` stores the just-in-time work-package plans for this session.

## Latest progress

A4as is implemented, decision-reviewed, code-reviewed, locally verified, and committed as `e500c60f96` (`fix(payments): mark native admin surfaces ready`), with git range `6cbb4314c2...e500c60f96`. It closes the explicit post-A4ar native admin readiness decision: `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` now defaults true after the accumulated A4/N12 gate passed, while the explicit false filter remains the fail-closed override. RED/GREEN evidence proved the old default failed the new expectation and the false override still blocks. Focused `WooPaymentsCutoverControllerTest` passed with 37 tests and 91 assertions; syntax, changed-file PHPCS, production PHPStan, changelog validation, diff check excluding `.agents`, and branch lint passed. Target preflight evidence in `data/a4as-cutover-state.json` reports native ownership, empty `preflight_failures`, empty `failures`, and `ready: true`; `soft_notice` is false because the standalone WooPayments plugin is not active. Parfit the 6th returned `STAND` on the decision and Dewey the 6th approved the code diff with no critical/high/medium findings. The changelog entry already exists in earlier commit `2555f71968`. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4ar is closed as the coverage closure for A4aq's two incomplete gaps. The ignored local harness now snapshots/restores a target-only optional account-state scenario for Documents/Card Readers/Capital, creates real local process/refund/capture WooPayments order fixtures for both stores before perf capture, accepts REST boot only as both-sides-preinitialized route snapshot evidence with inventory present, resets shared Playwriter state after optional admin, and waits for checkout required asset evidence before failing logo resource assertions. After review found masking risks, the harness was tightened for exact restore verification including autoload preservation, bounded WP eval-file calls, exact optional-admin unavailable-set matching, required optional available-route proof, real refund/capture provider IDs, and fail-closed severe reference diagnostics. Full post-autoload-fix aggregate `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json` passed with no failures and no incomplete checks: 57 base admin checks, 7 optional-admin checks, 24 checkout checks, bundle, perf, and log scan all passed. Final stricter perf fixture orders were reference `592`/`593`/`594` and target `291`/`292`/`293`; target/reference `wcpay_account_data` autoload values stayed `off`, target logs were clean, and the reference emitted five existing non-severe WooPayments plugin `credit_card_form` deprecations recorded as a limitation. Reliability re-review and focused code re-review approved with no critical/high/medium findings. Native admin readiness remains fail-closed and no product/WPCOM changes were made in A4ar.

A4ap is implemented, verified locally, and committed as source/tests `746469525d` plus changelog `6cbb4314c2`, with git range `c3b737269f...6cbb4314c2`. It closes accumulated checkout/admin parity residuals after A4ao: protected admin routes now fail closed unless explicitly allowed, WooPay first-party auth and split WooPay bundles restore Connect preemptive sessions, preferred-card rendering, OTP fallback, disabled product preflight, and scoped styling, card checkout restores test-card copy fallback/success behavior, card-owned Stripe Elements wallet/Link/terms options, Blocks floating-label appearance parity, saved-card control parity, and reference-style WooPayments card-brand artwork. Fresh focused gates passed for Blocks card, classic card, Blocks WooPay, classic WooPay, admin routes, `WooPaymentsCheckoutBridgeTest`, `WooPaymentsWooPaySessionServiceTest`, scoped frontend/PHP static checks, PHPStan, changelog validation, and diff check. Playwriter proved native checkout against the same-width reference: the compact one-row Stripe field layout is viewport-responsive, native now has the reference-style card icons plus JCB/UnionPay overflow, no standard saved-payment checkbox when WooPay owns save-my-info, and a styled 48px purple WooPay express button from the split bundle. Target runtime logs were clean. Native admin readiness remains fail-closed; A4/N12 is still open and no push occurred.

A4ao is implemented, review-fixed, browser/log verified, and committed locally as source/tests `e34b3f96f2` plus changelog `c3b737269f`, with git range `bdc86d6ea1...c3b737269f`. The source-backed analysis is `analysis-a4ao-blocks-express-checkout-element-parity.md` and the JIT plan is `plans/2026-06-20-core-native-payments-a4ao-blocks-express-checkout-element-parity.md`. This slice restores native Blocks Express Checkout Element parity after the classic card checkout work: provider-owned Store API cart extension data for `extensions.wcpay.express_checkout_methods`, cart-aware ECE method availability, mounted Stripe Elements options with `loader: 'never'`, manual capture, subscription setup-future-usage, appearance, and locale, reference-compatible Stripe minor-unit conversion, wallet line items and shipping rates, shipping address/rate Store API update handling, checkout confirmation parity, scoped ECE overflow/focus styling, and WooPay express support forwarding for subscription-capable carts. Final review found and the implementation fixed two high regressions before commit: Store API ECE methods are now currency-fresh but location-blind so checkout-enabled/cart-disabled stores still show checkout wallets, and subscription-provided shipping rates now select the filtered package ID instead of falling back to package `0`. Final gates are green: combined focused PHP 51 tests / 199 assertions, combined Blocks Jest 26 tests, exact frontend/backend static gates, Blocks bundle build, changelog validation, branch lint, diff check, and post-build Playwriter target checkout smoke with clean browser/log scans. Blocks type lint still prints unrelated pre-existing TypeScript errors despite exiting 0, so it is recorded as a caveat rather than a clean type assertion. Native admin readiness remains fail-closed and this does not touch WPCOM, trunk, or generic WooCommerce Blocks architecture beyond the necessary provider seam.

A4an is implemented, browser/log verified, and committed locally as source/tests `983ee15254` (`fix(payments): restore reports balance reader costs`) plus changelog `bdc86d6ea1` (`chore(payments): add reports balance changelog`), with git range `d101c1d251...bdc86d6ea1`. It closes the remaining Reports Balance `Reader costs` row-contract gap after A4w/A4al: native Reports now maps the full reference Balance reconciliation rows in order, labels `total_charges_captured` as `Total charges captured`, labels `reader_fees` as `Reader costs`, preserves the zero-activity anchor rows, and avoids announcing a misleading loaded-row count when all rows are zero. Fresh gates are green: focused Reports Jest 20 tests, focused Reports REST PHPUnit 9 tests / 43 assertions, exact-file ESLint, admin type lint, changed-file PHPCS, production controller PHPStan, admin bundle build, A4 admin surface gate with Reports `28239` raw / `8332` gzip bytes, Playwriter live and controlled Reports proofs with `Reader costs` visible in the controlled row fixture, target `debug.log` at 0 bytes, clean target PHP/error and actual-5xx scans, changelog validation, diff check excluding `.agents`, branch lint with known broad ignored-file JS warnings plus PHP clean, and post-commit Git-visible status clean. Native admin readiness remains fail-closed; this is not the final A4/N12 exit gate. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4am is implemented, review-fixed, browser/log verified, and committed locally as source/tests `fb2a0b2e88` (`fix(payments): restore classic card checkout parity`) plus changelog `d101c1d251` (`chore(payments): add classic checkout parity changelog`), with git range `995c3e332e...d101c1d251`. It closes the card-owned native classic checkout parity gaps after A4al: Stripe Elements now gets `loader: 'never'`, cached classic appearance, allowed font CSS rules, and manual card Payment Element options; classic card logos hydrate from provider config with visible brands plus an accessible overflow dialog; checkout-fragment resize listeners are cleaned up; classic CSS owns the logo popover and legacy float correction; and FR Cartes Bancaires is exposed through checkout config and gateway icons using a tracked Core-owned SVG. Review fixes closed dialog focus/ARIA behavior and stale resize listener accumulation. Fresh gates are green: focused legacy Jest 15 tests, exact-file ESLint, targeted SCSS Stylelint, classic asset build, focused PHP 31 tests / 124 assertions plus 8 tests / 104 assertions, changed-file PHPCS, production PHPStan, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint with known broad ignored-file JS warnings plus PHP clean. Playwriter session `83` verified the target classic checkout row, card-logo overflow, dialog focus, and Escape focus return; target `debug.log` stayed empty and only background WooCommerce Subscriptions queue DEBUG logs were non-empty. Native admin readiness remains fail-closed; broader checkout matrix coverage, Reports reader-fee aggregation, accumulated account-state/browser/log/bundle breadth, harness checkout visual parity hardening, and the final A4/N12 exit gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4al is implemented, review-fixed, browser/log/source-gate verified, and committed locally as `995c3e332e` (`fix(payments): restore payment detail residual parity`), with git range `84791320b2...995c3e332e`. It closes the next residual native payment-detail parity slice after A4ak: `metadata.charge_type=card_reader_fee` now routes to a dedicated reader-fee detail branch, reader-charge summaries render a `Card readers` table with CSV export plus distinct loading/empty/error/timeout states, normal payment details render a bounded richer timeline mapper, and non-card payment methods show method-specific/detail rows from fields already present in the native provider payload. Review fixes closed the parent live-region over-announcement on reader-fee routes, a flaky reader-row async assertion, and reliability gaps around abort/timeout/error/empty behavior. Fresh gates are green: focused admin Jest 69 tests, exact-file ESLint, admin type lint, admin bundle build, widened A4 admin surface gate, Playwriter normal charge detail proof, Playwriter synthetic reader-fee error proof, clean target/reference debug and Docker log scans, changelog validation with existing PHP 8.4 vendor deprecation noise, `git diff --check -- . ':!.agents'`, and branch lint with known broad ignored-file JS warnings plus PHP clean. The local target has no real `card_reader_fee` transaction row, so browser success table proof remains unavailable and covered by Jest/source/harness instead. Native admin readiness remains fail-closed; checkout/card visual parity, Reports reader-fee aggregation, accumulated account-state/browser/log/bundle breadth, and the final A4/N12 exit gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4ak is implemented, review-fixed, browser/log/source-gate verified, and committed locally as source/tests `d567290579` (`fix(payments): restore payment detail summary parity`) plus changelog `84791320b2` (`chore(payments): add payment detail summary changelog`), with git range `becafbb2cd...84791320b2`. It restores native WooPayments payment-detail summary composition: Summary, Identifiers, current-account test-mode notice, self-describing order/subscription links, missing-order notice with live status, card/card-present/interac-present payment-method detail sections, generic non-card method fallback, richer billing/metadata/payment-method normalization, and sales-channel derivation from payment-method/channel metadata instead of row type. Fresh gates are green: focused admin Jest 47 tests, exact-file ESLint, admin type lint, targeted Stylelint, admin bundle build, widened A4 source/chunk gate, Playwriter desktop/mobile proof with current money-movement asset and empty failed responses/console/page errors, clean target/reference debug logs and strict target/local-WPCOM log scans, changelog validation with existing PHP 8.4 vendor deprecation noise, diff checks excluding `.agents`, staged diff checks, and branch lint with known broad ignored-file JS warnings plus PHP clean. Native admin readiness remains fail-closed; `card_reader_fee`, full timeline mapper parity, broader payment-method variants, checkout/card visual parity, account-state/browser/log/bundle breadth, and the final A4/N12 exit gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4aj is implemented, review-fixed, browser/log verified, and committed locally as product/tests `240b80c7dd` (`fix(payments): restore express checkout preview`) plus changelog `becafbb2cd` (`chore(payments): add express checkout preview changelog`), with git range `761690b187...becafbb2cd`. It restores the native WooPayments Apple Pay / Google Pay settings preview by exposing a read-only `express_checkout_preview.stripe` payload from the settings service, rendering the settings-local WooPay preview, and mounting Stripe's Express Checkout Element with native checkout-style `loader: 'never'` options when HTTPS and Stripe config allow it. The preview stays scoped to the WooPayments settings chunk and falls back to reference-style notices when no express buttons are enabled, the page is HTTP, config is incomplete, Stripe.js fails, or no wallets are available. Review fixes closed the failed Stripe.js retry blank-state and the WooPay preview accessible-name mismatch. Fresh gates are green: focused PHP 28 tests / 240 assertions, focused admin Jest 22 tests, exact-file ESLint, admin type lint, targeted Stylelint, PHP syntax, production PHPStan, changed-file PHPCS, admin bundle build, filtered A4 Playwriter browser gate for target desktop/mobile, focused preview screenshots, clean target PHP/debug-log scan aside from background Subscriptions debug entries, changelog validation, diff check excluding `.agents`, and branch lint with the known broad ignored-file JS warnings plus PHP clean. Native admin readiness remains fail-closed; live HTTPS wallet availability is source/Jest-covered rather than deterministic browser-proven in the HTTP local target. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4ai is implemented, review-fixed, browser/log verified, and committed locally as source/tests `19b03bb680` plus changelog `761690b187`, with git range `f382f3a290...761690b187`. It restores native transaction-detail refund actions for order-backed captured WooPayments charges without porting the reference plugin's direct no-order charge-refund escape hatch. Native `/wc/v3/payments/refund` now requires a valid WooCommerce order, positive remaining amount, and `_charge_id` equality before calling `wc_create_refund( refund_payment => true, restock_items => true )`, preserving the native gateway/provider refund pipeline. Payment detail `charges` and `payment_intents` are now enriched with local order context through `WooPaymentsMoneyMovementOrderService`, including `intent.charge` and `intent.charges.data[]`, after browser proof showed the detail endpoint lacked order data even though the transactions list had it. The frontend adds `Transaction actions`, `Refund in full`, the `Refund transaction` modal, optional open-inquiry copy, reason radios, partial-refund order handoff, route-aware reloads/notices/focus restoration, and a defensive HPOS/legacy order-URL ID fallback. Fresh gates are green: focused PHP 40 tests / 254 assertions, focused admin Jest 44 tests, exact-file ESLint, admin type lint, targeted Stylelint, PHP syntax, changed-file PHPCS, production PHPStan for the payment-detail controller and order service, admin bundle build, changelog validation, diff check excluding `.agents`, and branch lint with known broad ignored-file JS warnings plus PHP clean. Playwriter session `78` verified the target native detail page and non-mutating refund modal; evidence is `data/a4ai-refund-modal-browser.json` and `data/a4ai-refund-modal-target.png`. Target `debug.log` stayed 0 bytes, target web/WooCommerce logs had no PHP diagnostics or actual 5xx lines, WPCOM web logs were clean, and WPCOM jobs retained unrelated background wpcom-local PHP 8.4/database noise. Native admin readiness remains fail-closed; live Stripe Elements settings preview, broader payment-detail visual/copy comparison, and final accumulated N12 gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4ah is implemented, review-fixed, browser/log verified, and committed locally as source/tests `7c045db09d` (`fix(payments): restore detail authorization actions`) plus changelog `f382f3a290` (`chore(payments): add detail authorization changelog`), with git range `30cdbcf46f...f382f3a290`. It restores native transaction-detail authorization actions by loading authorization state for eligible uncaptured PaymentIntent details, rendering the normal `Capture` notice, rendering fraud-review `Block transaction` and `Approve transaction` actions, reusing the existing guarded order-scoped authorization endpoints, refreshing details/timeline/authorization data after success, and surfacing authorization load/action failures. Review fixes closed a stale-route failure-notice gap and a potential focus-steal after user-moved focus. Fresh gates are green: focused admin Jest 38 tests, focused PHP 34 tests / 219 assertions, exact-file ESLint, admin type lint, targeted Stylelint, admin bundle build, changelog validation, diff check excluding `.agents`, branch lint with known broad ignored-file JS warnings plus PHP clean, Playwriter session `77` target proof for transactions/uncaptured/current PaymentIntent detail route with screenshot/evidence in `data/a4ah-transaction-detail-browser.*`, target `debug.log` stayed 0 bytes, and target/local WCPay simulator Docker scans found no PHP/WP diagnostics. Native admin readiness remains fail-closed; refund modal parity, live Stripe Elements settings preview, broader payment-detail visual/copy comparison, and the final N12 gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A4ag is implemented, review-fixed, browser/log verified, and committed locally as `377a6cbe76` (`fix(payments): restore settings merchant parity`) plus changelog `30cdbcf46f` (`chore(payments): add settings parity changelog`), with git range `6870a9caec...30cdbcf46f`. It closes the next settings merchant-parity bundle: manual capture now requires the reference-style confirmation before enabling and disables immediately, failed saves focus the first known server-detail field, field-detail save failures no longer emit a duplicate raw `server_error` notice, express checkout descriptions render the WooPay/Apple/Google/Link/Amazon legal links, Amazon Pay overview actionability follows method status, and Apple Pay / Google Pay can surface the synthetic duplicate cluster. The backend duplicate-detection architecture now uses an explicit provider-level declaration filter for native duplicate method ids, with payment-request heuristics kept narrow and fail-closed. Fresh gates are green: focused PHP 28 tests / 238 assertions, admin settings Jest 64 tests across page/data files, targeted ESLint, admin type lint, PHP syntax, changed-file PHPCS, production PHPStan, admin bundle build, changelog validation, branch JS/PHP lint, diff check excluding `.agents`, Playwriter settings proof with express links and manual-capture modal/cancel path, and target `debug.log` stayed 0 bytes after the browser pass. Native admin readiness remains fail-closed; live Stripe Elements preview, refund modal parity, detail-level capture/cancel/fraud-review actions, and the final N12 gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, or scratchpad linting occurred.

A4af is implemented, review-approved, locally verified, and committed as `7faed3e4fe` (`fix(payments): show woopay disable feedback`) plus changelog `6870a9caec` (`chore(payments): add woopay feedback changelog`), with git range `0ca1d25347...6870a9caec`. It restores the reference WooPay disable-feedback contract in native WooPayments settings: native settings now records and returns `woopay_last_disable_date`, preserves existing disable dates, avoids false writes when WooPay is omitted, merges server-returned settings fields back into the shared data store after save, and opens the scoped `WooPay feedback` modal only after a successful save disables WooPay outside the seven-day throttle. Browser proof on the target settings route opened the modal and survey iframe, saved `data/a4af-woopay-disable-feedback-browser.json` plus `data/a4af-woopay-disable-feedback-modal.png`, and restored the target option afterward. Fresh gates are green: PHP 23 tests / 231 assertions, admin Jest 54 tests, targeted ESLint, admin type/style lint, PHP syntax, PHPCS, PHPStan, admin bundle build, changelog validation, diff check, branch lint, clean target debug log scan, and a11y/API-contract reviews with no critical/high/medium findings. Native admin readiness remains fail-closed; refund/capture/fraud-review detail actions, manual-capture confirmation/save-error focus, express overview residuals, and the final N12 gate remain open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, or scratchpad linting occurred.

A4ad is committed locally as `845b9018c7` (`fix(payments): gate native admin routes by account state`) plus changelog `0c3f94350c` (`chore(payments): add admin availability changelog`), with git range `39cd54073d...0c3f94350c`. It closes the direct-route availability gap found after A4ac: native WooPayments now preloads `adminRouteAvailability` from existing account-service predicates, protected admin routes check that projection before lazy chunk import, unavailable protected routes render the accessible `This WooPayments admin area is unavailable.` status, provider settings/express/fraud settings remain loadable through the Core Settings > Payments seam, legacy `/payments/*` redirects consume the same availability projection, and Capital REST/loan-offer handlers now require full admin account access plus previous-loan eligibility. Review findings were fixed, including the too-narrow Capital guard and ignored Playwriter harness filter/page-error gaps. Current gates are green: focused PHP 112 tests / 484 assertions, focused route Jest 22 tests, targeted ESLint, syntax, PHPStan, PHPCS, admin `ts:check`, admin bundle build, changelog validation, diff check, branch lint, full browser matrix `data/a4ad-admin-browser-gate.json` with 57 checks / 0 failures, post-Capital-fix target proof `data/a4ad-capital-target-postfix-admin-browser-gate.json` with 3 checks / 0 failures, and target `debug.log` stayed empty. Native admin readiness remains fail-closed; A4/N12 parity follow-ups remain open. Post-commit Git-visible state is clean; no push attempted.

A4ae is committed locally as `9232b9d5eb` (`fix(payments): route dispute responses through details`) plus changelog `0ca1d25347` (`chore(payments): add dispute detail actions changelog`), with git range `0c3f94350c...0ca1d25347`. It closes the native payment-detail/dispute-action parity slice by routing actionable dispute rows through transaction details, rendering the native `Dispute details` decision hub there, preserving charge/payment-intent dispute fields, wiring accept-dispute to the existing close endpoint, and invalidating stale dispute caches only after platform success. Review fixes closed the async accept-modal focus paths with RED/GREEN regressions, and the final focused gates are green: admin Jest 29 tests, PHP 34 tests / 219 assertions, targeted ESLint, admin type/style lint, PHP syntax, PHPCS, PHPStan, admin bundle build, Playwriter target proof, clean browser console, clean target PHP log scan, changelog validation, diff check, and branch lint with only the known broad ignored-file JS warnings. Refund modal parity, detail-level capture/cancel/fraud-review actions, broader visual/copy comparison, and WooPay/settings residual parity remain A4/N12 follow-ups. Native admin readiness remains fail-closed. Post-commit Git-visible state is clean; no push attempted.

A4ac is committed locally as `4d1d643d05` (`fix(payments): keep native admin readiness gated`) plus changelog `39cd54073d` (`chore(payments): add admin readiness reground changelog`), with git range `7896e66ac7...39cd54073d`. Re-reading N8/N12 showed A5d's native-admin readiness flip was ahead of the documented parity contract: A4ab is smoke/runtime route/chunk/log evidence, not full merchant-facing parity, and N12 requires fail-closed readiness until merchant reachability plus functional/visual/copy parity explicitly pass. The product default is restored so `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` defaults false again; explicit `true` remains available for controlled gates. RED/GREEN evidence is recorded in `analysis-a4ac-admin-readiness-reground.md` and `plans/2026-06-20-core-native-payments-a4ac-admin-readiness-reground.md`; focused `WooPaymentsCutoverControllerTest` passed with 37 tests and 91 assertions, the related readiness suite passed with 171 tests and 575 assertions, syntax/PHPStan/PHPCS passed, changelog validation passed, branch lint passed, and target preflight proof in `data/a4ac-admin-readiness-preflight.json` shows native ownership with `preflight_failures=["native_admin_surfaces_unavailable"]`, `ready=false`, and `soft_notice=false`. Decision reviewer Bohr the 5th returned STAND and architecture reviewer Feynman the 5th approved. Target `debug.log` stayed empty and target/local-WPCOM log scans were clean. Post-commit Git-visible state is clean. Next action is continue source-backed A4/N12 parity work from the corrected fail-closed readiness baseline.

A5e is committed locally as `5a2a042da5` (`fix(payments): persist native cutover notices`) plus changelog `7896e66ac7` (`chore(payments): add cutover notice changelog`), with git range `2555f71968...7896e66ac7`. It proves the plugin-to-native handoff in the local target store: soft cutover passed through the product disable action in `data/a5e-soft-cutover-browser-gate.json`, mandatory auto-deactivation passed from a plugin-owned starting state in `data/a5e-mandatory-auto-deactivation-browser-check.json`, and final target state in `data/a5e-state-after-mandatory-rerun-final.json` is native-owned with WooPayments inactive and empty preflight failures. A5e also fixed a real cutover success-notice lifecycle bug by distinguishing trusted same-request status from query/transient status, persisting mandatory success across a possible next request, failing closed for stale success while the plugin runtime is active, clearing stored success on failed deactivation, and avoiding success replay after same-request rendering. Fresh gates passed: RED/GREEN same-request overlap regression, focused `WooPaymentsCutoverControllerTest` with 37 tests and 91 assertions, related readiness suite with 170 tests and 573 assertions, PHP syntax, changed-file PHPCS, production PHPStan, changelog validation, `git diff --check -- . ':!.agents'`, branch lint, Playwriter mandatory proof with 3 checks / 0 failed responses / 0 console issues, target `debug.log` at 0 bytes, target/local-WPCOM log scans clean, and reliability/WordPress architecture re-reviews approved. Mandatory cutover remains default-off. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, trunk work, or scratchpad linting occurred.

A5d is committed locally as `e7612bf9e7` (`fix(payments): allow native admin readiness after gate`) plus changelog `2555f71968` (`chore(payments): add admin readiness changelog`), with git range `c293d2f1cc...2555f71968`. It flips only the native admin-surface readiness default after A4ab's source/chunk/browser/log gate, while preserving the explicit fail-closed filter path and all other cutover blockers. Fresh gates passed: RED/GREEN cutover controller test, focused `WooPaymentsCutoverControllerTest` with 32 tests and 78 assertions, related readiness suite with 158 tests and 542 assertions, PHP syntax, changed-file PHPCS, PHPStan, A4 source/chunk gate `data/a5d-admin-surface-gate.json`, strict Playwriter matrix `data/a5d-admin-browser-gate.json` with 57 checks and 0 failures, A5 local WPCOM readiness, user-token readiness, transport continuity, target preflight with empty failures, target `debug.log` empty, focused target/local-WPCOM log scans clean, reliability and WordPress architecture reviews approved, changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and post-commit status. Mandatory cutover remains default-off, and this is not a new full pixel/copy/account-state parity claim. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, or trunk work occurred.

A4ab is committed locally as `410ad0e3ec` (`fix(payments): close native admin gate regressions`) plus changelog `c293d2f1cc` (`chore(payments): add native admin gate changelog`), with git range `44c9640bac...c293d2f1cc`. The slice closed the widened native admin smoke/runtime exit gate after fixing the native Overview heading hierarchy and the multi-currency analytics early-currency-resolution notice. The ignored Playwriter harness now enforces target lazy chunk resource timing and only waives `useSelect` warnings on the reference store. Final gates passed: focused Overview Jest 24 tests, focused account-settings Jest 10 tests, focused PHP 11 tests / 29 assertions, adjacent multi-currency PHP 76 tests / 213 assertions, PHP syntax, PHPCS, PHPStan, admin `ts:check`, targeted ESLint/Stylelint, admin bundle build, source/chunk gate, strict Playwriter matrix 57 checks / 0 failures, target `debug.log` empty, a11y/reliability/E2E review disposition, changelog validation, `git diff --check`, and branch lint. At A4ab close, native admin readiness remained fail-closed until A5d; A4ab is not a full pixel/copy/account-state parity claim. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, or trunk work occurred.

A4aa is committed locally as `99453ab9fc` (`fix(payments): restore settings contact validation parity`) plus changelog `44c9640bac` (`chore(payments): add settings contact parity changelogs`), with git range `9c735d2cb0...44c9640bac`. The slice restored native WooPayments settings contact/notification/advanced parity: notification email warning/format/confirmation validation and Save gating, transaction saved-card/statement/customer-support helper copy, support email and phone validation through the shared `PhoneNumberInput`/`validatePhoneNumber` path, dev-mode debug behavior, fail-closed deprecated bundled-subscriptions enablement, and stale Reports/Documents provider-route test correction. Final gates passed: focused admin Jest 2 suites / 43 tests, focused components Jest 2 suites / 13 tests, admin `ts:check`, components type lint, targeted ESLint/Stylelint, components/admin bundle builds, WooCommerce/components changelog validation, `git diff --check -- . ':!.agents'`, branch lint, Playwriter target settings validation proof with empty failed responses/console, target `debug.log` empty, a11y/reference reviews approved, and post-commit A4 admin-surface gate evidence in `data/a4aa-admin-surface-gate.json`. Native admin readiness remains fail-closed and the widened A4/N12 exit gate is still open. No WPCOM sandbox access, WPCOM code changes, Stripe CLI use, push, or trunk work occurred.

A4z is committed locally as `eca988cef8` (`feat(payments): add native overview dashboard parity`) plus changelog `9c735d2cb0` (`chore(payments): add overview dashboard parity changelog`), with git range `966cd9a723...9c735d2cb0`. The plan was `plans/2026-06-19-core-native-payments-a4z-overview-dashboard-residual-parity.md` and the slice restored Overview/dashboard residual parity, not final A4/N12 readiness. Native Overview projection now adds account details, fees, feature flags, active-loan projection, embedded account-session backend seams for future embedded UI, and default-on dispute readiness; frontend renders Account details, Active loan summary, Dispute readiness, and lazy WooCommerce notes-store-backed Inbox, with the unused embedded account-session page probe removed. Final gates were green before commit: focused backend PHPUnit passed with 116 tests and 695 assertions; focused Overview Jest passed with 34 tests; targeted ESLint, targeted Stylelint, admin `ts:check`, admin `build:project:bundle`, `a4-admin-surface-gate.py`, changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch` all passed. Final review gates passed: performance and API-contract approved, and accessibility approved after Inbox heading hierarchy, multi-note dismissal focus, and delayed async dismissal focus guarding fixes. Playwriter session `57` verified the rebuilt target Overview route with Balance, Payouts, Payout history, Account details, Dispute readiness, Inbox, WooPayments settings, Total balance, and Available funds visible; no failed responses; no `/payments/accounts/session` call; only the known Chrome `unload` permissions-policy warning. Final harness evidence is `data/a4z-admin-surface-gate-final.json` with native Overview `52520` raw / `13356` gzip bytes and reference `overview-js` `63268` raw / `17975` gzip bytes. Target/reference debug logs stayed empty after clearing, and target/reference/local-WPCOM Docker scans had no fresh PHP/WP errors or actual 4xx/5xx responses. Post-commit sanity showed no Git-visible product changes and `git diff --check -- . ':!.agents'` passed. Do not reopen A4y, do not backtrack to the older 28/32-test states, and keep A4/N12 readiness fail-closed. No WPCOM sandbox access, no WPCOM code changes, no Stripe CLI use, no push, and no scratchpad linting.

A4y is committed locally as `20f407ccf3` (`feat(payments): add native dispute challenge parity`) plus changelog `966cd9a723` (`chore(payments): add dispute challenge parity changelog`), with git range `43d5ced7e5...966cd9a723`. It is implemented, review-fixed, browser/log verified, and not pushed; native admin readiness remains fail-closed until the widened A4/N12 exit gate. Final gates passed before commit: focused A4y Jest passed with 2 suites and 45 tests, targeted ESLint passed, `ts:check` passed, targeted Stylelint passed, `build:project:bundle` passed with the known unrelated email-editor/tour-kit webpack cache warnings, `a4-admin-surface-gate.py` passed with `data/a4y-admin-surface-gate-post-cover-letter-fix.json`, changelog validation passed with existing PHP 8.4 vendor deprecation noise, `git diff --check -- . ':!.agents'` passed, and branch lint passed with the known branch-wide JS ignored-file warnings. Playwriter verified the target review step for actionable dispute `du_1TjifiJd67Ti1EoIyvnBCdrw` after clearing the stale local draft textarea without saving: the regenerated formal cover letter contains the subject/greeting and no `Refund status:` or `Duplicate status:` lines; desktop and mobile screenshots are in `data/a4y-native-dispute-challenge-review-generated-desktop-top.png` and `data/a4y-native-dispute-challenge-review-generated-mobile-top.png`. Target container and local WPCOM log scans for the current UTC browser window found no HTTP 4xx/5xx and no fresh PHP/WP notices, warnings, fatals, or database errors. Post-commit sanity is clean: `git status --short --untracked-files=all` returned no Git-visible changes and `git diff --check -- . ':!.agents'` passed. Next action after compaction: continue the reopened A4/N12 parity plan; do not rerun or backtrack A4y unless new evidence appears.

B3ah is implemented, review-approved, and committed as `687b2bb74d` (`fix(payments): preserve native woopay runtime sessions`) plus changelog `68baab9890` (`chore(payments): add woopay session changelog`), with git range `4e1addc080...68baab9890`. B3ai is implemented, reviewer-approved, and committed as `0cfefce03c` (`fix(payments): add native woopay frontend parity`) plus changelog `1894182a09` (`chore(payments): add woopay frontend changelog`), with git range `68baab9890e46137a64a6c1d965c22e0017833be...1894182a0959b97f2d61770e2f1ed1840bf6a87b`. Split Core WooPayments card and WooPay frontend bundles are in normal WooCommerce build workflows, WooPay renders with branded markup/CSS only when enabled, card PaymentElement rendering is sibling-level and uses the shared appearance/fonts/options pipeline, WooPay account eligibility and save-user guards match the extension behavior, product-page WooPay adds the selected product and selected variation attributes before init, and modern CSS colors are normalized before Stripe Elements to avoid appearance warnings. Fresh tests, lint, PHPStan, build, browser, log scans, review, and the restored harness passed; no push was attempted.

A5a, A5b, and A5c are committed. A5c closes platform connection and WCPay V1 transport-continuity readiness: native preflight requires local WPCOM/Jetpack connectivity, blog ID, connection owner, and owner user token; account update requests use the preserved WCPay V1 user agent, owner user-token auth, idempotency, `X-Request-Initiated`, and bounded idempotent transport retries. Focused PHPUnit, PHPCS, PHPStan, changelog validation, branch lint, diff check, wpcom-local readiness, and target WP-CLI A5 probes passed. A5c git range: `3679904d57...de3773cb89`.

N12 supersedes the earlier A5a admin-readiness assumption. `FILTER_NATIVE_ADMIN_SURFACES_READY` now defaults fail-closed again, and A4 has been reopened for feature parity before A5 cutover can be called ready. A4i is committed and proves persistent admin navigation reachability for existing native routes. A4j is committed as `42846f540a` plus changelog `47527b39fc`, with git range `1400d54ed9...47527b39fc`; it closes the shared settings payment-method/BNPL row foundation with native row composition, Core-owned icons, card required/card-brand rendering including Cartes Bancaires, country-aware Afterpay/Clearpay settings branding, manual-capture conflict handling with row-level disabled reasons, and activation-modal gating. A4k is committed as `9a4789f3cd` plus changelog `624ca6c82d`, with git range `47527b39fc...624ca6c82d`; it closes fee pills/details, duplicate notices, split-gateway duplicate classification, and the adjacent `book` express button normalizer. A4l is committed as `1bc7b94a48` plus changelog `c40efec701`, with git range `624ca6c82d...c40efec701`; it adds native express checkout Customize subpages for WooPay, Apple Pay / Google Pay, and Amazon Pay under the Core Settings > Payments route seam, with browser/build/test evidence recorded in `staging-log.md`. A4 remains open for payout bank-account settings, fraud Basic/Advanced UI, sandbox switch-to-live notice, save busy overlay parity, source-backed PM promotions, dashboard parity, Reports/Documents disposition, reference menu badges, and the widened A4 exit gate against the reference store.

A4i is now committed as the first reopened-A4 parity chunk: native WooPayments admin routes that already exist are reachable from persistent WP admin navigation under the Core Payments parent, with native-runtime gating, `manage_woocommerce` gating, reference-backed account-state variants, canonical Settings > Payments provider route URLs, live target browser proof, and a widened A4 admin-surface harness check. This does not close N12. Settings page component/SCSS parity, dashboard visual/UX parity, copy/content parity, reference menu badging, Reports/Documents disposition, and the final widened A4 exit gate remain open before admin surface readiness can flip.

H23 is committed as `98e02db4cd` (`fix(payments): preserve subscription authentication renewals`) plus changelog `7a54cd0736` (`chore(payments): add subscription authentication changelog`), with git range `628d95d8ad...7a54cd0736`. Native scheduled subscription renewals now consume the neutral checkout outcome, preserve the WooPayments failed-renewal authentication hook and email IDs/template override names, fail customer-action renewals with hook-exception hardening, and expose a provider-level `charge_id` outcome field while keeping legacy `_charge_id` meta fallback. Final PHP/static/preflight gates passed; PHPStan remains blocked only by unrelated existing unmatched ignored-baseline patterns, and browser-created failed-renewal compare remains unclaimed until the local browser flow is stable.

H24 is committed as `42cc040d39` (`fix(payments): preserve manual capture intent handling`) plus changelog `df8060dfb8` (`chore(payments): add auth capture changelog`), with git range `7a54cd0736...df8060dfb8`. Native now sends manual capture only for non-renewal checkout when the merchant setting requires it, keeps scheduled renewals automatic like the reference extension, stores authorized PaymentIntent references for later capture/cancel, and the ignored harness can drive deterministic reference/target auth/capture orders through widened financial reconciliation.

H25 is committed as `afb2c92b72` (`fix(payments): handle native dispute webhooks`), changelog `69d9fd9215` (`chore(payments): add dispute webhook changelog`), and lint correction `0a0bb92390` (`fix(payments): document dispute webhook failures`), with git range `df8060dfb891...0a0bb92390`. Native now handles the five `charge.dispute.*` webhook events with provider-owned charge-id order resolution, reference-compatible dispute notes/status/local refunds, required-field fail-closed validation, and preserved dispute-summary API access. Final PHP/static/harness gates passed, including cross-store `verify.sh` 7/7 and a direct target dispute-created probe. N7a is still not fully green: provider-created dispute, payout, and converted-currency flow drivers remain open coverage gaps.

H26 and H27 strengthened the N7a money matrix. H26 added native dispute-cache invalidation and provider-created dispute e2e harness coverage; H27 preserved converted settlement meta, widened converted-currency/manual-capture reconciliation, and added WCPay Dev Tools native payout support on its feature branch. H27 still records honest payout observability limits because manual Test Lab payouts did not expose deterministic per-order membership on either reference or target.

H28 is committed through `0d527ea115` after measured perf/bundle closeout and changelog-format cleanup. It moves multi-currency settings CSS into the normal Core admin build, fixes failed-capture status and Sift scheduling regressions exposed by the perf probe, and records bundle/perf evidence with the explicit caveat that isolated REST route-registration timing remains unverified because both stores preinitialize REST in WP-CLI. Timing numbers are smoke-only for large deltas, not precise latency proof.

H29 is committed as `443a879b66` (`fix(payments): handle native WooPayments refund webhooks`), retry hardening `eb6e0269d8` (`fix(payments): harden native refund webhook retries`), and changelog `14d1e4b5fe` (`chore(payments): add refund webhook changelog`), with git range `0d527ea115...14d1e4b5fe`. Native now handles `charge.refunded` and `charge.refund.updated` with WooPayments-provider side effects, including local refund creation/deletion, `_wcpay_refund_*` metadata, split-UPE ownership, explicit multi-currency notes, retry idempotency, strict `captured` validation, and fail-closed malformed payloads. Final focused and broad PHP/static/branch gates passed; no frontend bundle or runtime timing claim is made for this backend webhook slice. Remaining provider-event blockers are account, invoice, and notification events.
> **Prompt:** "Focused A4az re-review in `/Users/vladolaru/Work/a8c/woocommerce-develop-2` on branch `exp/core-native-payments`.
>
> Scope: review the current uncommitted A4az diff only, especially the post-review fixes for native WooPayments fraud ruleset refresh/defaulting parity.
>
> Files to inspect:
> - `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
> - `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
> - `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
> - Related tests under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/` and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/`
>
> Previously found reliability issue was that malformed successful platform `ruleset_config` arrays were cached; this should now fail closed and log. Also verify the new logging helper cannot interrupt settings responses, refresh happens at most once per service instance, no connected account avoids platform calls, cached transient avoids platform calls, not-found initializes Basic, unexpected failures leave the transient unset and surface the reference-style `"error"` sentinel.
>
> Do not edit files. Do not access WPCOM sandbox. Do not push. Write findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4az-reliability-rereview.md` with scratchpad frontmatter (`session: 2026-06-15-core-native-payments`, `type: review`, `by: subagent:reliability-reviewer`, current local time from `date`, `status: final`). No hard-wrapped prose. Return severity-ranked findings; if no critical/high/medium issues, say so and list residual risks."

> **Prompt:** "Read-only source verification for the next reopened-A4/N12 fraud UI parity slice in `/Users/vladolaru/Work/a8c/woocommerce-develop-2`.
>
> Goal: verify the remaining advanced fraud UI residual after A4az. Do not edit files, do not access WPCOM sandbox, do not push.
>
> Inspect current native Core and the read-only WooPayments client reference:
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/*`
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`
> - Native Core tests: `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx` and `settings-page.test.tsx`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/`
>
> Questions:
> 1. What exact advanced fraud loading-state, error-state, rule-card detail, copy, interaction, or styling behaviors still differ between native and reference?
> 2. Which differences are merchant-facing and source-backed enough to include in the next coherent implementation slice?
> 3. Which should remain out of scope because they are non-critical, already covered, or require a broader design change?
>
> Write findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-advanced-fraud-ui-source-check.md` with scratchpad frontmatter (`session: 2026-06-15-core-native-payments`, `type: review`, `by: subagent:explorer`, current local time from `date`, `status: final`). No hard-wrapped prose. Return a concise severity/prioritization summary."

> **Prompt:** "Review the current uncommitted A4ba diff in /Users/vladolaru/Work/a8c/woocommerce-develop-2 for correctness/reliability/maintainability. Scope: plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx, style.scss, tests, and changelog. The goal is frontend parity with the WooPayments reference advanced fraud settings UI without backend changes: loading shell, busy state, linked guidance, allowed countries notice, threshold controls/help/notices, currency prefix styling. Constraints: read-only review, do not edit product files, do not access WPCOM/sandbox, no commits/pushes. Verify source-backed issues only; focus on regressions, state handling, save semantics, route/bundle behavior, and maintainability of new helpers. Write findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-code.md with scratchpad frontmatter, then summarize critical/high/medium findings in final response. If none, say so and mention residual low risks."
