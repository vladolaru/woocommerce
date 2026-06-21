---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 16:00
status: final
last_updated: 2026-06-20 17:46
---

# A4ar A4aq Incomplete Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two remaining A4aq incomplete checks by exercising optional WooPayments admin routes under an explicit local account-state scenario and by supplying real local money-path fixtures to the perf surface gate.

**Architecture:** Keep the harness fail-closed. Add setup orchestration around existing product behavior rather than weakening browser, REST, console, or perf assertions. All temporary local store state must be snapshotted, restored, and recorded in evidence.

**Tech Stack:** Python 3 harness orchestration, WP-CLI via local Docker containers only, existing PHP WP-CLI fixture drivers, Playwriter browser gate, existing WooCommerce/WooPayments local stores.

---

### Task 1: Add Local Account Scenario Support

**Files:**
- Create: `tools/woopayments-merge/a4-account-scenario.php`
- Modify: `tools/woopayments-merge/a4aq-accumulated-gate.py`
- Modify: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`
- Modify: `tools/woopayments-merge/HARNESS.md`
- Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ar/`

- [x] Add a small PHP WP-CLI driver that reads the current `wcpay_account_data` option, applies or restores an optional-admin scenario, and prints JSON with the previous and next values for `card_present_eligible`, `has_card_readers_available`, `capital.has_previous_loans`, and `is_documents_enabled`.

- [x] In the Python orchestrator, add a focused optional-admin browser phase after the normal admin browser phase: snapshot the target account cache, apply the optional-admin scenario, run Playwriter with `state.surfaceIds = [ 'documents', 'card-readers', 'capital' ]`, `state.storeIds = [ 'target' ]`, and a distinct evidence slug, then restore the target account cache in a `finally` block. Reference cache mutation was deliberately removed after `a4ar-admin-smoke-2` proved cache-only Capital flags create an invalid reference control.

- [x] Update the admin Playwriter gate so optional reference routes are strict when `state.strictReferenceOptional === true`; default behavior remains unchanged for the base account-state pass.

- [x] Ensure the accumulated gate only clears the admin incomplete when the focused optional-admin scenario has no unavailable target checks, no missing target chunks, no unignored failed responses, no unignored console/page errors, and no restore failure.

- [x] Verify with a focused run against only `documents`, `card-readers`, and `capital`. Expected result: either pass with available-route evidence for both viewports or a fail/incomplete with source-backed route/API reasons; no silent allowlists.

### Task 2: Add Money Fixture Orchestration For Perf

**Files:**
- Modify: `tools/woopayments-merge/a4aq-accumulated-gate.py`
- Modify: `tools/woopayments-merge/perf-surface-gate.sh` only if the existing CLI surface needs evidence fields, not to relax requirements
- Modify: `tools/woopayments-merge/HARNESS.md`
- Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ar/`

- [x] Add a Python fixture preparation step for each store that runs the existing local drivers through the validated WP-CLI command: `flow-drive-unpaid-order.php` for the unpaid process-payment order, `flow-drive-deterministic-charge.php` or `flow-drive-native-charge.php` with a successful charge for the refundable order, and the same runtime-appropriate driver with manual capture for the authorized order.

- [x] Parse each fixture driver’s JSON output and validate the preconditions before perf capture: process order exists and needs payment, refund order has the WooPayments gateway and a remaining refundable amount, capture order has the WooPayments gateway, a transaction ID, positive total, no refunds, and `_intention_status` `requires_capture`.

- [x] Pass the generated fixture IDs into `perf-surface-gate.sh capture` for reference and target. Do not hand-edit payment meta to manufacture capture state.

- [x] Record fixture IDs, operation outputs, and preflight status in the aggregate evidence. If a fixture cannot be created deterministically, mark the perf gate incomplete with the exact fixture-generation failure rather than comparing partial data.

- [x] Run perf capture and compare. `data/a4ar-perf-smoke-2` passed after the comparator was tightened to accept both-sides-preinitialized REST route snapshot evidence while keeping mixed or missing route inventories incomplete.

### Task 3: Re-run A4ar Accumulated Gate And Update Session Docs

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4ar-a4aq-incomplete-coverage.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] Run syntax checks for changed harness files: `python3 -m py_compile tools/woopayments-merge/a4aq-accumulated-gate.py tools/woopayments-merge/compare-measured-gates.py`, `php -l` for the two new PHP drivers, `bash -n tools/woopayments-merge/perf-surface-gate.sh`, and `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`.

- [x] Run local-only negative WP-CLI command validation again for remote transport and shell-control injections. The accumulated gate rejects shell metacharacters and `--ssh`; standalone perf capture rejects shell metacharacters and `--http`.

- [x] Run the A4ar accumulated gate with the known local commands and Playwriter session. `data/a4ar-full-3` passed with no failures and no incomplete checks after the optional-admin state reset and checkout resource-evidence wait fixes.

- [x] Scan target and reference Docker/debug logs from the gate window and record any PHP/WP notices, warnings, deprecations, fatals, uncaught errors, stack traces, database errors, or real 5xx lines. Target logs were clean in `data/a4ar-full-3`; reference debug log recorded four existing WooPayments plugin `credit_card_form` deprecations and the aggregate kept them as a limitation.

- [x] Update the scratchpad logs with the exact status, evidence paths, remaining limitations if any, and no broader readiness claim than the evidence supports.

### Task 4: Review Gate

**Files:**
- Create or modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ar-harness-coverage.md`

- [x] Use a subagent or inline code-review pass to review the harness changes for masking risk, restore correctness, local-only command safety, and whether the two former A4aq incompletes are genuinely exercised. Reliability re-review approved with no critical/high/medium findings; code re-review requested the autoload restore fix and approved after the fix.

- [x] Fix review findings that are source-backed and rerun the focused checks they affect. Initial review findings around optional-admin over-clearance, restore exactness, WP eval-file timeout, perf provider-ID validation, and severe reference diagnostics were fixed. A later code re-review found the optional-admin restore could rewrite `wcpay_account_data` autoload before perf; that is now fixed with a RED/GREEN regression and exact autoload restore. Regression/syntax checks passed, focused perf/browser review-fix runs passed, and full post-autoload-fix aggregate `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json` passed with zero failures and zero incomplete checks.

- [x] Record the review result and final A4ar status in the session logs. Final evidence is `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json`, status `pass`, with no failures and no incomplete checks. A4ar remains a harness coverage closure only; native admin readiness remains fail-closed.
