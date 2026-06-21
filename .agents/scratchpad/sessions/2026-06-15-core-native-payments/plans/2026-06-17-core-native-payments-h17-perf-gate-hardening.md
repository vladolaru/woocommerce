---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 20:33
tool: writing-plans
target: H17/N7c perf gate hardening
status: final
last_updated: 2026-06-17 20:58
---

# Perf Gate Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the explicit N7c perf-gate gaps for capture-path timing and REST payment-controller measurement so the perf gate is exercised instead of reported as unavailable.

**Architecture:** Keep the gate local-only and conservative. The existing `perf-surface-gate.sh` measures money-path operations by invoking gateway methods once with outbound HTTP blocked; extend the same pattern to capture via an explicit fixture order, and measure REST payment route callback object count after `rest_api_init` as a defensible local proxy for controller registration/instantiation pressure.

**Tech Stack:** Bash harness, inline WP-CLI PHP probe, Python comparator fixture tests, WooCommerce/WooPayments gateway methods, scratchpad evidence.

---

## Files

- Modify: `tools/woopayments-merge/perf-surface-gate.sh` to accept `--capture-order-id`, measure capture with blocked outbound HTTP, and report measured REST payment callback object counts instead of `not_implemented`.
- Modify: `tools/woopayments-merge/compare-measured-gates.py` only if the existing comparator needs clearer failure output; otherwise leave it unchanged because it already compares `capture` and `controller_instantiation_count`.
- Modify: `tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh` to add RED coverage for unmeasured REST controller counts and controller-count regressions.
- Modify: `tools/woopayments-merge/HARNESS.md` to document the new `--capture-order-id` fixture and the REST controller-count proxy.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md` and `staging-log.md` to record plan, gates, and residual N7c status.

## Tasks

### Task 1: RED Comparator Coverage For REST Controller Measurement

- [x] Add fixture support in `tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh` for `controller_instantiation_status`.
- [x] Add a failing assertion that `controller_instantiation_status=not_implemented` exits `3` and prints `INCOMPLETE rest_boot: controller_instantiation_count`.
- [x] Add a failing assertion that target `controller_instantiation_count` greater than reference exits `1` and prints `FAIL  rest_boot: controller_instantiation_count`.
- [x] Run `bash tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh` and confirm RED before changing the probe if the new fixture expectations are not already covered.

### Task 2: GREEN Perf Probe Capture And REST Count

- [x] Extend `perf-surface-gate.sh capture` argument parsing to accept `--capture-order-id`, validate it as a positive integer, pass it into the WP-CLI probe, and print it in progress output.
- [x] In the inline PHP probe, add `$capture_order_id` from WP-CLI args with environment fallback.
- [x] Implement `$measure_capture` following `$measure_refund`: require a real WooPayments authorization fixture, use the native `PaymentProcessingService::capture()` path or the legacy `capture_charge()` path as appropriate, measure one blocked-HTTP invocation, and return `status=measured` with queries, external request count, median time, order status before/after, and `measurement_mode=single_invocation_blocked_http`.
- [x] Replace the current `capture` `requires_fixture` result with `$measure_capture()`.
- [x] After `rest_api_init`, inspect `$server->get_routes()` for payment-related routes and count unique object callbacks/classes as `controller_instantiation_count` with `controller_instantiation_status=measured`. Keep `route_count` and `payment_route_count` unchanged.
- [x] Re-run `bash tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh` and confirm GREEN.

### Task 3: Runtime Capture Of The New Perf Measurements

- [x] Run `bash -n tools/woopayments-merge/perf-surface-gate.sh` and `python3 -m py_compile tools/woopayments-merge/compare-measured-gates.py`.
- [x] Run `perf-surface-gate.sh capture` without fixture IDs against reference and target and confirm only the money-path fixture probes remain `requires_fixture`, while `rest_boot.controller_instantiation_status` is `measured`.
- [x] If suitable reference/target capture fixture order IDs already exist in the staging log or current stores, run capture with those IDs. If they do not exist, record capture as still fixture-blocked rather than creating fake data.
- [x] Compare the captures with `perf-surface-gate.sh compare --ref <json> --target <json>` and record PASS/FAIL/INCOMPLETE precisely.

### Task 4: Review, Docs, And Commit

- [x] Run focused review via a performance/toolchain reviewer or equivalent subagent over the harness changes.
- [x] Update `HARNESS.md`, `implementation-log.md`, and `staging-log.md` with exact commands and residual gaps.
- [x] Run `git diff --check`.
- [x] Leave ignored harness/scratchpad changes uncommitted; no tracked source changes were produced by this slice.

## Self-Review

- Spec coverage: this plan addresses the N7c perf rows that were explicitly marked unmeasured: capture timing and REST controller-instantiation count. It does not claim to close N7a financial auth/capture/dispute/payout/multi-currency coverage or N7c bundle missing-asset failures.
- Closeout: harness files are ignored local tooling by design, so there was no tracked source commit. The capture probe is implemented but remains fail-closed at runtime until a real authorized `requires_capture` fixture exists; REST controller snapshot count is measured, while isolated route-registration timing remains fail-closed because the current WP-CLI runtime preinitializes REST.
- Placeholder scan: no TODO/TBD placeholders are present; fixture unavailability is treated as fail-closed evidence, not success.
- Architecture check: the gate remains local-only, does not call remote WPCOM, and blocks external HTTP during money-path timing probes to avoid live provider mutation during perf measurement.
