---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 02:16
target: A4bd post-A4bc accumulated A4/N12 gate refresh
reconciles:
  - ../analysis-a4bd-post-a4bc-accumulated-gate-refresh.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
last_updated: 2026-06-21 02:31
status: complete
---

# A4bd Post-A4bc Accumulated Gate Refresh Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold the post-A4au settings parity slices through A4bc into the accumulated A4/N12 evidence baseline without inventing speculative product work.

**Architecture:** Treat the ignored local accumulated harness as the stage-boundary oracle. Product code is immutable unless a gate failure is verified against source/browser evidence and traced to a real regression. Harness changes are allowed only when the existing gate is narrower than the now-intended contract, with a focused regression proving the harness gap before the fix.

**Evidence directory:** `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate`

## Task 1: Preflight And Active Checkpoint

- [x] Verify the local target/reference containers and select the current target CLI container. Expected target CLI is `24860d14de30dc62f7b324ebef10b5fb-cli-1` unless `docker ps` shows a replacement.
- [x] Verify Playwriter has an active session and select a session id. Reuse an existing session only if it is stable; otherwise create a new session.
- [x] Append an active A4bd checkpoint to `implementation-log.md` before running the long gate, including the evidence directory and the no-product-diff expectation.

## Task 2: Run The Accumulated Gate

- [x] Run:

```bash
tools/woopayments-merge/a4aq-accumulated-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --ref-wp "docker exec -i wcpay_wp_default wp --allow-root" \
  --target-wp "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1" \
  --playwriter-session 5 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bd-post-a4bc-accumulated-gate
```

- [x] Read the rollup with `jq` before drawing conclusions. Record status, failure count, incomplete count, limitations, and per-check statuses.

## Task 3: Failure Handling

- [x] If the gate passes, do not change product code or create an empty commit. Record the new baseline in `implementation-log.md` and `staging-log.md`, and update this plan to `complete`.
- [x] If the gate fails or reports incomplete, inspect the failing evidence first. Verify whether the issue is a product regression, a stale harness expectation, a local environment problem, or an explicit limitation. Not needed; the gate reported zero failures and zero incomplete checks.
- [x] For a product regression: add focused RED coverage or a failing gate assertion, implement the narrow fix, run focused verification, then rerun the accumulated gate into a suffixed evidence directory. Not needed; no product regression surfaced.
- [x] For a harness gap: add focused ignored-harness regression coverage, fix the harness without masking product behavior, run syntax/focused harness checks, then rerun the accumulated gate into a suffixed evidence directory. Not needed; no new harness gap surfaced.
- [x] For an environment limitation: record it honestly as incomplete or blocked only if it cannot be resolved locally without WPCOM sandbox access or unsafe mutation. Not needed; limitations were recorded without blocking because the gate remained complete.

## Task 4: Review And Closeout

- [x] If the accumulated gate passes, dispatch at least one read-only reviewer focused on whether the new A4bd gate result is sufficient after A4av-A4bc and whether any result is overstated.
- [x] Reconcile the reviewer. Fix only source-backed findings.
- [x] Update `implementation-log.md`, `staging-log.md`, and this plan with final evidence paths, limits, and whether any tracked code changed.
- [x] Run `git status --short --branch --untracked-files=all` and `git diff --check -- . ':!.agents'` before closeout.

## Boundaries

Do not access or change WPCOM sandbox or WPCOM code. Do not push. Do not touch WooPayments reference code. Do not weaken gate assertions to hide product regressions. Do not lint `.agents`.
