---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 16:05
tool: writing-plans
target: N7 verification coverage hardening before A4/A5
status: draft
---

# N7 Verification Coverage Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Widen the local merge verification gates so the N6/N7 spec-conformance baseline rests on exercised financial, Bucket-C Subscriptions, perf, and bundle evidence rather than narrow runbook availability.

**Architecture:** Keep the harness local-only under `tools/woopayments-merge`, fail closed on missing raw-source access or missing fixture data, and separate gate drivers from gate reconcilers so reference and target can be compared without masking product regressions. Treat the Stripe CLI as read-only raw source and the two local stores as the only e2e targets. Do not touch WPCOM code or the reference WC/WooPayments source trees.

**Tech Stack:** Bash harness scripts, small Python helpers for JSON comparison, WP-CLI probes, Stripe CLI read commands, WooCommerce/WooPayments local stores, and the existing WooCommerce build/performance tooling.

---

## File Structure

- Modify: `tools/woopayments-merge/financial-reconcile.sh` to reconcile charge amount/currency, captured/authorized state, full and partial refunds, fee/net metadata, dispute references, payout linkage when present, and multi-currency meta against Stripe raw source for every provided order.
- Create: `tools/woopayments-merge/financial-reconcile-normalize.py` to compare WC order JSON against Stripe charge/payment-intent/balance-transaction JSON without brittle shell regex parsing.
- Create: `tools/woopayments-merge/tests/financial-reconcile-fixtures.sh` to run local fixture tests for the normalizer and prove RED/GREEN behavior without Stripe/WP connectivity.
- Modify: `tools/woopayments-merge/flow-drive.sh` only if needed to emit additional operation IDs for refund/dispute/payout flows; preserve existing output shape.
- Create: `tools/woopayments-merge/subscriptions-renewal-conformance.sh` after the sidecar source investigation returns; this gate should drive or identify a real WC Subscriptions renewal on reference and target, then assert success, order/meta, and email evidence.
- Create or modify: a perf/bundle measurement script after the sidecar source investigation returns; it should record actual numbers for the §5.3 touched surfaces and bundle outputs and make `verify.sh` report BLOCKED/FAIL instead of implying coverage when numbers are absent.
- Modify: `tools/woopayments-merge/HARNESS.md`, `tools/woopayments-merge/README.md`, and `tools/woopayments-merge/verify.sh` to describe and run the widened gates honestly.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`, `staging-log.md`, `implementation-log.md`, and `README.md` to record the retroactive baseline result and the N7 gate coverage status.

## Task 1: Financial Reconciler Fixture Tests

**Files:**
- Create: `tools/woopayments-merge/financial-reconcile-normalize.py`
- Create: `tools/woopayments-merge/tests/financial-reconcile-fixtures.sh`

- [ ] **Step 1: Write a failing fixture test for charge amount and refund coverage**

Create fixture JSON for one WC order and Stripe raw source where the WC order total is `5000 usd`, captured amount is `5000 usd`, and refunds total `1200 usd`. The test must assert PASS when totals match and FAIL when the Stripe charge amount is changed to `4900`.

- [ ] **Step 2: Write failing fixture tests for fee/net, dispute, payout, and multi-currency fields**

Add fixture cases that fail when `_wcpay_transaction_fee`, `_wcpay_net`, `_wcpay_multi_currency_stripe_exchange_rate`, `_wcpay_multi_currency_order_exchange_rate`, dispute IDs, or payout IDs diverge while present on either side. Missing optional provider data should produce `BLOCKED` only when the corresponding WC order claims that state exists.

- [ ] **Step 3: Run the fixture tests and confirm RED**

Run: `bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh`

Expected: FAIL because the normalizer/comparator has not been implemented yet.

## Task 2: Financial Reconciler Implementation

**Files:**
- Modify: `tools/woopayments-merge/financial-reconcile-normalize.py`
- Modify: `tools/woopayments-merge/financial-reconcile.sh`

- [ ] **Step 1: Implement the JSON comparator**

Implement a Python comparator that reads a WC-side JSON document and Stripe-side JSON documents, normalizes supported currencies to minor units, compares charge amount/currency, captured/authorized state, refunded amount/refund count, transaction fee/net values when provider balance transactions expose them, dispute IDs/statuses when present, payout IDs/statuses when present, and multi-currency exchange-rate meta when present.

- [ ] **Step 2: Replace shell regex parsing in `financial-reconcile.sh` with structured JSON**

Make the WP-CLI probe emit structured JSON containing order ID, total, currency, status, transaction ID, `_charge_id`, `_intent_id`, `_wcpay_payment_transaction_id`, `_wcpay_transaction_fee`, `_wcpay_net`, `_wcpay_intent_currency`, multi-currency meta, refund rows, notes with fee/dispute/payout hints, and order meta keys relevant to money state. Make Stripe CLI reads request charge JSON, payment intent JSON when the order has an intent ID, balance transaction JSON when available, refunds list, disputes list filtered by payment intent or charge when possible, and payout JSON when a balance transaction has payout linkage.

- [ ] **Step 3: Preserve fail-closed behavior**

Keep exit `3` for missing Stripe CLI access, missing connected account, unparseable raw source, and provider objects that are required by WC state but cannot be read. Keep exit `1` for actual WC/provider divergence. Exit `0` only when all requested orders have been reconciled across the widened matrix or explicitly report unsupported optional dimensions as absent on both sides.

- [ ] **Step 4: Run fixture tests and confirm GREEN**

Run: `bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh`

Expected: PASS.

## Task 3: Financial Matrix Runtime Gate

**Files:**
- Modify: `tools/woopayments-merge/flow-drive.sh`
- Modify: `tools/woopayments-merge/verify.sh`
- Modify: `tools/woopayments-merge/HARNESS.md`

- [ ] **Step 1: Verify available Test Lab operation outputs**

Run the Test Lab help/status commands for reference and target through WP-CLI and confirm which operations produce order IDs, charge IDs, refund IDs, dispute IDs, and payout IDs. Do not assume deterministic support beyond charge until the command output proves it.

- [ ] **Step 2: Extend flow driving only where source supports it**

If Test Lab emits structured IDs for refunds, disputes, and payouts, make `flow-drive.sh` preserve those IDs in JSON lines. If a matrix dimension cannot be driven deterministically yet, make the widened gate report BLOCKED for that dimension instead of PASS.

- [ ] **Step 3: Wire the widened financial gate into `verify.sh`**

Rename the gate label to make coverage explicit, for example `financial reconciliation matrix`, and make the summary no longer say deterministic Tier A/B coverage when the matrix is blocked. Keep the old charge fixture but add refund/dispute/payout/capture/multi-currency fixture IDs as the driver supports them.

- [ ] **Step 4: Run the widened financial gate on reference and target**

Run the relevant `tools/woopayments-merge/verify.sh --ref ... --target ...` command and record exact order IDs, charge IDs, covered matrix dimensions, and any BLOCKED dimensions in `staging-log.md`.

## Task 4: Bucket-C Subscriptions Conformance Gate

**Files:**
- Create: `tools/woopayments-merge/subscriptions-renewal-conformance.sh`
- Modify: `tools/woopayments-merge/verify.sh`
- Modify: `tools/woopayments-merge/HARNESS.md`

- [ ] **Step 1: Integrate the sidecar source findings**

Use the Subscriptions subagent result to pick the safest driver path. The gate must produce or identify a real subscription renewal order on each store and must not fake success through metadata mutation alone.

- [ ] **Step 2: Write the failing conformance probe**

Add a script that fails when WC Subscriptions is unavailable, when WooPayments does not advertise subscriptions support, when no renewal order is produced, when renewal payment meta is missing or mismatched, or when renewal/failed-renewal email evidence is absent.

- [ ] **Step 3: Run reference and target probes**

Run the gate against `http://localhost:8082` reference and `http://store8889.localhost:8889` target WP-CLI runners. Record PASS/FAIL/BLOCKED, renewal order IDs, parent subscription IDs, meta keys checked, and email evidence source.

## Task 5: Perf And Bundle Measurements

**Files:**
- Modify or create: `tools/woopayments-merge/perf-baseline.sh` and companion scripts after sidecar analysis returns.
- Modify: `tools/woopayments-merge/verify.sh`
- Modify: `tools/woopayments-merge/HARNESS.md`

- [ ] **Step 1: Integrate the sidecar source findings**

Use the perf/bundle subagent result to identify existing commands for checkout render, product/cart/checkout/account multi-currency surfaces, admin provider pages, process-payment timing/query count, and bundle asset sizes.

- [ ] **Step 2: Make missing measurements fail closed**

Change the harness so broad perf and bundle-size coverage is not described as PASS unless actual numbers are captured and compared. If a surface cannot be measured yet, report BLOCKED with the missing command or precondition.

- [ ] **Step 3: Record numbers in staging log**

Run the chosen commands and record reference vs target numbers, tolerances, and verdicts. If the local tooling cannot measure a surface, record the invariant as explicitly unverified and block A4/A5.

## Task 6: Baseline Documentation And Review

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [ ] **Step 1: Update baseline docs before reporting**

Record that prior `financial reconciliation passed` entries are refunds-only until the widened matrix passes, and that N7 blocks A4/A5 until the widened financial, Subscriptions, perf, and bundle gates have exercised the accumulated runtime.

- [ ] **Step 2: Run adversarial reviews**

Dispatch at least one reliability reviewer over the financial gate, one API/BC reviewer over Subscriptions, and one performance/toolchain reviewer over perf/bundle. Reconcile findings against source before changing labels.

- [ ] **Step 3: Record the final N7 verdict**

Update `staging-log.md` with exact commands, outputs, order IDs, matrix coverage, blocked dimensions, and reviewer verdicts. Do not advance to A4/A5 unless the widened gates are PASS or explicitly tracked fail-closed slices.

## Self-Review

- Spec coverage: N7(a) is covered by Tasks 1-3; N7(b) by Task 4; N7(c) by Task 5; staging-log and baseline recording by Task 6.
- Placeholder scan: no `TBD`, `TODO`, or “implement later” placeholders are present. Task 4 and Task 5 intentionally depend on running sidecar source investigations before choosing exact commands, and the plan records fail-closed behavior if those commands are unavailable.
- Type consistency: scripts live under `tools/woopayments-merge`; scratchpad docs stay under `.agents/scratchpad/sessions/2026-06-15-core-native-payments`; no docs/superpowers usage.
