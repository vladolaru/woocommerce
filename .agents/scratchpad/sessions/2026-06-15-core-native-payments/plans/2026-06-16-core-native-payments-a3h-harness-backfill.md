---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 18:42
reconciles:
  - implementation-log.md
  - staging-log.md
status: draft
---

# A3h Harness Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backfill every harness/browser gate that was previously skipped because `tools/woopayments-merge` was missing, fix any real regressions the restored gates expose, and stop treating missing-harness evidence as acceptable going forward.

**Architecture:** Treat the restored harness as the first source of truth, but verify the harness itself before trusting cross-store output. Keep transition-only harness/probe fixes in `tools/woopayments-merge/` local and gitignored unless a product change needs a committed fix under `plugins/woocommerce/`. For native checkout evidence, separate three states: reference plugin, target plugin-wins dormant native, and target native-owned runtime with the plugin inactive and native explicitly enabled.

**Tech Stack:** WooCommerce Core PHP, WooPayments dev-tools Test Lab, WP-CLI, restored `tools/woopayments-merge` Bash/PHP/Python harness, Playwright/browser runbooks where deterministic gates stop.

---

### Task 1: Inventory and Trust the Restored Harness

**Files:**
- Read: `tools/woopayments-merge/HARNESS.md`
- Read: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Read: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

- [ ] **Step 1: Identify skipped gates**

Run:

```bash
rg -n "missing local harness|Harness/browser runbook|verify\\.sh|HARNESS\\.md|browser runbook|blocked" .agents/scratchpad/sessions/2026-06-15-core-native-payments/{implementation-log.md,staging-log.md}
```

Expected: A3e, A3f, and A3g include explicit missing-harness/browser-gate blocks.

- [ ] **Step 2: Confirm the restored reference self-check**

Run:

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: PASS with drift, flow-drive, Bucket-E self parity, perf, and financial reconciliation all green.

- [ ] **Step 3: Confirm target plugin-wins self-check**

Run:

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp"
```

Expected: PASS while the target WooPayments plugin is active, proving the working clone does not break the legacy plugin-owned runtime.

### Task 2: Repair Any Harness Defects Before Judging Product Regressions

**Files:**
- Modify: `tools/woopayments-merge/verify.sh`
- Modify: `tools/woopayments-merge/parity-diff.sh` if needed
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

- [ ] **Step 1: Reproduce the cross-store defect**

Run:

```bash
tools/woopayments-merge/verify.sh --ref "docker exec -i wcpay_wp_default wp --allow-root" --target "docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp"
```

Expected current failure: Bucket-E cross-store asks the target for a reference-only order ID and reports `not_found`.

- [ ] **Step 2: Fix the local harness comparison primitive**

Update the restored local harness so cross-store mode either drives corresponding orders on both stores and compares a normalized preserve surface, or explicitly labels the current same-ID comparison as a shadow-only gate and refuses cross-store with an incomplete precondition instead of a false product failure. Do not commit this harness directory unless a later product PR deliberately includes it.

- [ ] **Step 3: Rerun the repaired gate**

Run the same cross-store command. Expected: either PASS for a valid comparable plugin-vs-plugin surface, or INCOMPLETE with an honest precondition message. It must not report a product regression from a target `not_found` for a reference-only order.

### Task 3: Backfill Native-Owned Runtime Evidence

**Files:**
- Modify product files under `plugins/woocommerce/` only if a native runtime regression is found.
- Modify tests under `plugins/woocommerce/tests/php/` before product fixes.
- Modify checkout assets under normal WooCommerce build inputs only if a browser-flow regression is found.

- [ ] **Step 1: Create a reversible local native-runtime toggle**

Use a local-only target-store helper to enable `woocommerce_native_payments_enabled` for all target requests and deactivate `woocommerce-payments/woocommerce-payments.php` on the target only. Record the exact commands and the restore commands in the implementation log.

- [ ] **Step 2: Verify native gateway registration and account readiness**

Use WP-CLI to assert the target owner is `native`, `woocommerce_payments` appears in available gateways, and the provider reports process-ready using the native `WooPaymentsAccountService`.

- [ ] **Step 3: Drive server-side money gates where the harness supports native**

Run the deterministic gates that can operate in native-owned target mode. If an existing harness primitive still relies on the WooPayments plugin Test Lab command and cannot drive native, record it as an incomplete harness capability and create a targeted local probe instead of pretending it passed.

- [ ] **Step 4: Drive browser checkout runbooks**

Use browser automation or existing Playwright specs to drive at least the A3-owned card surfaces: classic card checkout, Blocks card checkout, zero-total setup intent, saved-token checkout, and callback hash handling. Capture resulting order IDs, then run Bucket-E and financial reconciliation on those orders.

### Task 4: Fix Regressions Test-First

**Files:**
- Test first in the closest existing PHPUnit/Jest/Playwright suite.
- Modify the minimum production files needed under `plugins/woocommerce/`.
- Add a WooCommerce changelog entry if product code changes.

- [ ] **Step 1: For each red gate, write a failing regression test**

Expected: the focused test fails for the observed reason before production code changes.

- [ ] **Step 2: Implement the smallest product fix**

Expected: the focused test turns green and no unrelated behavior changes.

- [ ] **Step 3: Rerun the original red gate**

Expected: the gate that found the issue is green or honestly incomplete due to an external precondition, with stronger evidence than the original skip.

### Task 5: Close the Backfill Package

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `plugins/woocommerce/changelog/*` only if product code changed.

- [ ] **Step 1: Run focused and changed-file quality gates**

Run the relevant focused PHPUnit/Jest/Playwright checks, PHPStan for touched production PHP, changed PHP lint, JS lint/build for touched assets, and `git diff --check`.

- [ ] **Step 2: Run review gates**

Dispatch a fresh code-review/spec-review subagent for any product diff and validate findings against source before committing.

- [ ] **Step 3: Update logs**

Replace the A3e/A3f/A3g missing-harness notes with backfilled evidence entries that distinguish PASS, FAIL fixed, or INCOMPLETE preconditions.

- [ ] **Step 4: Commit product changes only**

If product code changed, commit the product diff and changelog. Keep gitignored harness helpers local unless intentionally promoted.
