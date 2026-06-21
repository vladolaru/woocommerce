---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 02:55
target: A5i local readiness decision rollup
reconciles:
  - ../analysis-a5i-local-readiness-decision-rollup.md
  - ../review-post-a5h-next-slice-scan.md
  - ../review-a5h-cutover-readiness-refresh.md
  - ../review-a4bd-accumulated-gate.md
last_updated: 2026-06-21 02:58
status: complete
---

# A5i Local Readiness Decision Rollup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record the post-A5h decision line: local A4/A5 readiness baseline is green under documented limitations, while production/default-on rollout and A6 cleanup remain deferred and fail-closed.

**Architecture:** A5i is a decision/documentation slice only. It reconciles the already-fresh A4bd/A5h evidence and source defaults without rerunning gates or changing product code. It writes the decision into the session's durable artifacts so future agents do not infer stale A6 cleanup or production readiness.

**Tech Stack:** Scratchpad Markdown decision artifacts, existing A4bd/A5h JSON rollups, source-default inspection already recorded in A5h.

---

## Task 1: Evidence Sanity Check

- [x] Read `review-post-a5h-next-slice-scan.md` and confirm the verdict is `RECOMMEND_DECISION_ROLLUP`.
- [x] Recheck the current A4bd, A5f rerun, and A5g rollup summaries with `jq` so the decision uses fresh evidence values.
- [x] Confirm `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` remain false from the A5h source check or by a focused `rg` if needed.

## Task 2: Write The Decision Line

- [x] Update `analysis-a5i-local-readiness-decision-rollup.md` with the final decision and limitations.
- [x] Add an `A5i Local Readiness Decision Rollup` entry to `staging-log.md` above the append marker.
- [x] Add an A5i addendum to `spec-conformance-baseline.md` and include `review-post-a5h-next-slice-scan.md` in `reconciles`.
- [x] Update `README.md` current status and `implementation-log.md` with the A5i decision and no-code-change outcome.

## Task 3: Closeout Hygiene

- [x] Mark this plan complete.
- [x] Run:

```bash
git status --short --branch --untracked-files=all
git diff --check -- . ':!.agents'
```

- [x] Do not commit if only ignored scratchpad docs/evidence changed.

## Boundaries

No product code changes. No WPCOM sandbox access. No WPCOM code changes. No Stripe CLI use. No push. No trunk work. Do not lint `.agents`. Do not delete or modify `tools/woopayments-merge` for A6 cleanup.
