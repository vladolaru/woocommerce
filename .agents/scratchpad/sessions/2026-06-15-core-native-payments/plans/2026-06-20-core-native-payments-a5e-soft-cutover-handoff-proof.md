---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 01:16
last_updated: 2026-06-20 01:58
target: A5e soft cutover handoff proof
reconciles:
  - analysis-a5e-next-slice-selection.md
  - plans/2026-06-15-core-native-payments-a5.md
  - spec-conformance-baseline.md
  - staging-log.md
status: final
---

# Core Native Payments A5e Soft Cutover Handoff Proof Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the WooPayments plugin-to-native soft cutover path end to end in the local target store before any future mandatory cutover default flip.

**Architecture:** A5e is a harness-first verification slice. It uses a small WP-CLI state probe plus a Playwriter browser gate against `store8889.localhost:8889` with the standalone WooPayments plugin active and the native runtime release filter enabled through a local mu-plugin. The gate must not force native transport, provider-event, operational-queue, financial-remediation, platform, or legacy-subscription readiness; those stay product-owned checks so the harness cannot hide implementation bugs.

**Tech Stack:** WooCommerce Core PHP/DI, WordPress WP-CLI inside wp-env, Playwriter browser automation, local ignored `tools/woopayments-merge` harness scripts, scratchpad JSON evidence.

---

## File Structure

- Create `tools/woopayments-merge/a5-cutover-state.php`: local WP-CLI state probe that emits non-secret JSON for runtime owner, active plugin slugs, native runtime enablement, preflight failures, soft notice visibility, and current-user cutover capability.
- Create `tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs`: local Playwriter gate that loads the target admin with the plugin active, verifies the soft notice, clicks the real nonce-protected `Disable WooPayments` link/button, checks the success notice, and writes JSON evidence.
- Optionally create `tools/woopayments-merge/a5-mandatory-cutover-mu-plugin.php`: local-only mu-plugin that enables both native runtime and mandatory cutover for the activation-guard/auto-deactivation proof. Do not install it during the normal soft-cutover click path.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md` with A5e evidence and status.
- Modify product files only if a gate exposes a real defect. Likely product files, if needed, are `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` and `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`.

## Task 1: Build the State Probe

- [x] **Step 1: Add `a5-cutover-state.php` with read-only state output**

The probe must boot inside WP-CLI and emit JSON with:

```json
{
  "ready": true,
  "home_url": "http://store8889.localhost:8889",
  "current_user_id": 1,
  "active_plugin_slugs": ["woocommerce", "woocommerce-payments"],
  "runtime_owner": "plugin",
  "native_runtime_enabled": true,
  "plugin_runtime_active": true,
  "should_native_register": false,
  "preflight_failures": [],
  "soft_notice": true
}
```

The implementation should read services from the WooCommerce container where possible, and use reflection only for private `WooPaymentsCutoverController::is_cutover_ready()`-adjacent details that have no public equivalent. It must not add filters other than those already installed by the local mu-plugin for the current run.

- [x] **Step 2: Run the probe with the standalone WooPayments plugin active**

Use the target CLI container and copy the script into a temporary container path if the repo root is not mounted:

```bash
docker compose -f plugins/woocommerce/docker-compose.yml run --rm cli wp plugin activate woocommerce-payments --allow-root
docker compose -f plugins/woocommerce/docker-compose.yml run --rm cli wp eval-file /tmp/woopayments-a5e/a5-cutover-state.php --user=1 --allow-root
```

Expected: `runtime_owner` is `plugin`, `plugin_runtime_active` is true, `should_native_register` is false, `preflight_failures` is empty, and `soft_notice` is true. If the plugin cannot be active in the target env, stop and fix the local env or product defect rather than weakening the gate.

## Task 2: Build and Run the Browser Handoff Gate

- [x] **Step 1: Read the current Playwriter documentation**

Run:

```bash
playwriter skill
```

Use the session id parsing style already proven by the A4 browser gate. If `playwriter` is not on PATH, use `npx --yes playwriter@latest`.

- [x] **Step 2: Add the Playwriter gate**

The script must:

- Open `http://store8889.localhost:8889/wp-admin/plugins.php`.
- Fail if the page is not an authenticated WP Admin page.
- Capture response failures with status `>=400`, except expected static noise already justified in the A4 gate.
- Capture console errors/warnings with the same strictness as the A4 gate.
- Assert the soft cutover notice text and the real `Disable WooPayments` control are visible.
- Click the real control, following the product-generated nonce URL.
- Assert the redirected page shows the success notice.
- Assert the WooPayments plugin row is inactive or absent as an active plugin.
- Write evidence to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5e-soft-cutover-browser-gate.json`.

- [x] **Step 3: Run the browser gate**

Run:

```bash
npx --yes playwriter@latest -s <session-id> -f tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs --timeout 300000
```

Expected: the script exits 0 and records the success-notice evidence. If it fails on UX, nonce handling, redirects, active plugin state, console errors, or network errors, inspect and fix the product or local environment cause before proceeding.

## Task 3: Prove Native Ownership After Deactivation

- [x] **Step 1: Re-run the state probe after the browser click**

Expected JSON:

```json
{
  "runtime_owner": "native",
  "native_runtime_enabled": true,
  "plugin_runtime_active": false,
  "should_native_register": true,
  "preflight_failures": [],
  "soft_notice": false
}
```

- [x] **Step 2: Prove WooPayments settings still load through the native route**

Run the existing A4 browser gate or a focused route check for `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`. Expected: no 4xx/5xx, WooPayments settings content is visible, and no standalone-plugin route is required.

## Task 4: Prove Mandatory and Negative Guards Still Fail Closed

- [x] **Step 1: Mandatory auto-deactivation proof**

Reactivate the standalone plugin, install the local mandatory mu-plugin that enables native runtime plus mandatory cutover, visit a WP Admin page, and assert the plugin deactivates only when the same clean preflight passes.

- [x] **Step 2: Activation guard proof**

With the mandatory mu-plugin installed and preflight clean, attempt to reactivate `woocommerce-payments`. Expected: activation is blocked by `WooPaymentsCutoverController::guard_woopayments_activation()`.

- [x] **Step 3: Negative blocker proof**

Install a temporary local mu-plugin that adds one synthetic preflight failure through `woocommerce_woopayments_native_cutover_preflight_failures`, reactivate WooPayments, visit WP Admin, and assert auto-deactivation does not happen and the blocked notice appears. Remove the blocker afterward. This exercises the fail-closed path without weakening any real product check.

## Task 5: Logs, Existing Tests, and Documentation

- [x] **Step 1: Clear current target debug logs before the proof window**

Clear target `wp-content/debug.log` and record a Docker log timestamp before running gates so fresh warnings are not buried under historical noise.

- [x] **Step 2: Run focused PHP coverage**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsCutoverControllerTest|NativePaymentsRuntimeArbiterTest|WooPaymentsPlatformConnectionServiceTest|WooPaymentsOperationalQueueServiceTest|WooPaymentsEventIngestorTest|NativeWooPaymentsGatewayTest'
```

Expected: all tests pass. If product changes were needed, also run `php -l`, PHPStan on changed PHP files, and changed-file lint.

- [x] **Step 3: Scan fresh logs**

Search only the proof window for PHP notices, warnings, fatals, uncaught exceptions, database errors, and HTTP 5xx in the target store and local WPCOM/Transact containers. Any source-backed warning or notice from the changed path must be fixed or explicitly tracked if unrelated.

- [x] **Step 4: Update session docs**

Record the A5e evidence in `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md`. Keep hard-wrap-free paragraphs. Note whether product code changed or whether A5e closed as harness/evidence only.

- [x] **Step 5: Commit only Git-visible product or committed harness/doc changes if applicable**

Do not force-add ignored scratchpad or ignored harness artifacts. If product files changed, commit one logical product change with a conventional message and a WooCommerce changelog entry. If no product files changed, leave the local harness evidence uncommitted and record that A5e was proof-only.

## Task 6: Next-Slice Reminder

- [ ] **Step 1: After A5e is fully done, check N8 and reopen A4 for feature parity per N12**

Before starting later A5 or A6 work, revisit `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md` N8 and `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md`. Use that review to reopen A4 feature parity deliberately rather than treating A4ab/A5d as exhaustive A4 completion.

## Self-Review

This plan covers the A5e source-backed gap from `analysis-a5e-next-slice-selection.md`: the plugin-active soft cutover path has not been browser-proven. It does not change mandatory cutover defaults, does not force real readiness checks in the harness, does not touch WPCOM, and keeps B3 residual cleanup tracked but non-blocking for this slice. There are no placeholders or unowned product edits.
