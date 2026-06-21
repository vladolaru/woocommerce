---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 20:10
last_updated: 2026-06-20 20:27
reconciles:
  - analysis-a4au-post-a4at-accumulated-gate-refresh.md
status: final
---

# A4au Post-A4at Accumulated Gate Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold the A4at provider-route reachability patch into the accumulated A4/N12 evidence baseline without inventing speculative product work.

**Architecture:** This slice treats the harness as an oracle and product code as immutable unless a gate surfaces a verified source-backed regression. The full accumulated gate remains the broad contract, while the focused A4at alias matrix remains an adjacent route-specific proof because the accumulated admin browser gate exercises provider routes rather than plugin-era deep-link aliases.

**Tech Stack:** WooCommerce Core PHP/React sources, ignored local `tools/woopayments-merge` harness, Playwriter, local target wp-env store at `http://store8889.localhost:8889`, reference WooPayments store at `http://localhost:8082`, Docker WP-CLI.

---

### Task 1: Preflight And Evidence Setup

**Files:**
- Read: `tools/woopayments-merge/HARNESS.md`
- Read: `tools/woopayments-merge/a4aq-accumulated-gate.py`
- Read: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify later: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

- [x] **Step 1: Verify the local containers and Playwriter session**

Run:

```bash
docker ps --format '{{.Names}} {{.Ports}}' | rg 'wcpay_wp_default|8889|cli-1'
npx playwriter@latest skill
```

Expected: the reference container `wcpay_wp_default` is present, a target CLI container ending in `-cli-1` is present, the target web container exposes `8889`, and Playwriter documentation has been read before browser automation.

- [x] **Step 2: Record active status**

Append an A4au active entry to `implementation-log.md` before running long gates, with the selected evidence directory and the fact that no product code changes are planned unless the gate fails.

### Task 2: Run The Full Accumulated Gate

**Files:**
- Execute: `tools/woopayments-merge/a4aq-accumulated-gate.py`
- Output: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4au-post-a4at-accumulated-gate/a4aq-accumulated-gate.json`

- [x] **Step 1: Run the orchestrator**

Run, replacing the target CLI container only if `docker ps` shows a different current local target CLI name:

```bash
tools/woopayments-merge/a4aq-accumulated-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --ref-wp "docker exec -i wcpay_wp_default wp --allow-root" \
  --target-wp "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1" \
  --playwriter-session 1 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4au-post-a4at-accumulated-gate
```

Expected: the command streams progress and writes an evidence file after each phase. Exit `0` means pass, exit `3` means incomplete coverage to investigate, and exit `1` means a failure to fix or explicitly disposition.

- [x] **Step 2: Read the rollup before drawing conclusions**

Run:

```bash
jq '{status, failures, incomplete, limitations, checks: [.checks[] | {id, status, failures, incomplete_reasons}]}' .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4au-post-a4at-accumulated-gate/a4aq-accumulated-gate.json
```

Expected: conclusions are based on the JSON rollup, not on terminal impressions.

### Task 3: Refresh A4at Alias Proof

**Files:**
- Read: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4at-route-reachability-browser.json`
- Create or update if needed: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4au-post-a4at-accumulated-gate/a4at-route-reachability-browser.json`

- [x] **Step 1: Decide whether existing evidence is fresh enough**

Check the A4at evidence timestamp and commit ordering. If it was produced before commit `9cb43c2d03` but after the final fragment fix, copy it into the A4au evidence directory with a note. If any ambiguity remains, rerun the route matrix using Playwriter.

- [x] **Step 2: Validate the alias contract**

Assert the six route outcomes: setup aliases land on `/woopayments/overview`, fraud protection lands on `/woopayments/settings/fraud-protection`, multi-currency lands on `/woopayments/settings#advanced`, additional payment methods lands on `/woopayments/settings#payment-methods`, every result has `unexpectedLogs=[]`, and `actualSection=null`.

### Task 4: Failure Handling Or Closeout

**Files:**
- Modify if a source-backed failure exists: product or harness files identified by the failing gate
- Modify always: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify always: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify always: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] **Step 1: If the gate fails, verify and fix without masking**

For each failure, inspect source/browser evidence, reproduce the focused failure, add or update a focused test/gate if product code changes are needed, implement the narrow fix, and rerun the focused gate before rerunning the accumulated gate.

- [x] **Step 2: If the gate passes, record the baseline**

Update `implementation-log.md`, `staging-log.md`, and `README.md` to state that A4at has been folded into the accumulated A4/N12 baseline, including the evidence path, exit status, limitations, route-alias coverage note, log status, and whether any product code changed.

- [x] **Step 3: Commit only if tracked product or committed harness files changed**

If this slice only creates ignored scratchpad/harness evidence, do not create an empty commit. If tracked code changes were necessary, use the WooCommerce commit workflow, one logical change per commit, and no push.
