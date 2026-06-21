---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 05:09
tool: writing-plans
target: H26 N7a provider-created dispute e2e coverage
reconciles:
  - analysis-h26-n7a-provider-dispute-e2e.md
  - analysis-h25-n7a-dispute-payout-multicurrency.md
  - staging-log.md
last_updated: 2026-06-18 05:52
status: final
---

# H26 N7a Provider-Created Dispute E2E Implementation Plan

Completion result: implemented and committed as git range `0a0bb92390...b7043a1d25`. Product changes are in WooCommerce Core; harness changes remain ignored local tooling. The live provider-created dispute gate passed with reference order 511 and target order 211, and the main cross-store verify loop passed 7/7 with reference order 512 and target order 212. Payout and converted-currency money fixtures remain fail-closed follow-up coverage.

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make N7a dispute coverage exercise real provider-created disputes on the reference and native target stores, while closing the source-backed native dispute-cache invalidation parity gap.

**Architecture:** Keep product behavior inside the WooPayments provider boundary and keep harness verification fail-closed. Add a small provider-owned dispute cache invalidator for the legacy WooPayments cache option keys, then harden the ignored local harness so dispute reconciliation checks provider raw source plus WooCommerce order side effects rather than non-reference dispute meta.

**Tech Stack:** WooCommerce Core PHP, WooPayments native provider classes, PHPUnit in wp-env, ignored `tools/woopayments-merge` shell/PHP/Python harness, Stripe CLI read-only raw-source verification.

---

## File Map

- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeCacheService.php`: provider-owned invalidator for `wcpay_dispute_status_counts_cache`, `wcpay_test_dispute_status_counts_cache`, and `wcpay_active_dispute_cache`.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php`: accept the cache service and invalidate dispute caches after successful reference-compatible dispute created/closed/updated handling.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`: wire the cache service into the dispute handler.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`: add RED/GREEN cache invalidation coverage for created, closed, and updated dispute events plus duplicate-update behavior if source parity requires it.
- Modify `tools/woopayments-merge/financial-reconcile.sh`: include order notes in the WC-side money-state JSON so dispute side effects can be reconciled without fake dispute meta.
- Modify `tools/woopayments-merge/financial-reconcile-normalize.py`: treat provider dispute state with no WC dispute meta as valid only when WooCommerce has dispute side-effect evidence, and keep failing when provider dispute state has no WC dispute evidence.
- Modify `tools/woopayments-merge/tests/financial-reconcile-fixtures.sh`: add fixtures for reference-compatible dispute-note state and provider-dispute-without-WC-side-effect failure.
- Modify `tools/woopayments-merge/flow-drive.sh`: support `dispute --deterministic` by using the deterministic dispute charge path on both reference and native target.
- Add `tools/woopayments-merge/dispute-e2e-gate.sh`: drive deterministic provider-created disputes on reference and target, poll with progress output for webhook-applied order side effects, then run financial reconciliation against Stripe raw source.
- Modify `tools/woopayments-merge/HARNESS.md`: document the new dispute gate, its preconditions, and the remaining payout fail-closed status.
- Add a WooCommerce changelog entry only if tracked Core product files change; ignored harness-only changes remain local session state.

## Task 1: RED Product Coverage For Dispute Cache Invalidation

- [ ] **Step 1: Add failing cache invalidation tests**

In `WooPaymentsEventIngestorTest`, add a helper that seeds the three reference WooPayments dispute cache options before processing a dispute event:

```php
private function seed_dispute_cache_options(): void {
	update_option( 'wcpay_dispute_status_counts_cache', array( 'stale' => true ), false );
	update_option( 'wcpay_test_dispute_status_counts_cache', array( 'stale' => true ), false );
	update_option( 'wcpay_active_dispute_cache', array( 'stale' => true ), false );
}
```

Add assertions that `charge.dispute.created`, `charge.dispute.closed`, and one update-style event delete those options after successful processing. Keep the expected order side effects from H25 intact.

- [ ] **Step 2: Run RED PHPUnit**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsEventIngestorTest'
```

Expected: the new tests fail because native dispute handling mutates the order but leaves the seeded dispute cache options in place.

## Task 2: Implement Provider-Owned Dispute Cache Invalidation

- [ ] **Step 1: Add the cache service**

Create `WooPaymentsDisputeCacheService` with constants for the three reference option keys and `delete_dispute_caches(): void` that calls `delete_option()` for each key. Keep it provider-owned under `Providers/WooPayments`; do not introduce a generic payments cache abstraction for a WooPayments-specific compatibility surface.

```php
final public function delete_dispute_caches(): void {
	foreach ( self::DISPUTE_CACHE_KEYS as $key ) {
		delete_option( $key );
	}
}
```

- [ ] **Step 2: Wire the service into native dispute handling**

Update `WooPaymentsEventIngestor::init()` to create or resolve `WooPaymentsDisputeCacheService` and pass it into `WooPaymentsDisputeEventHandler::init()`. Update the handler so successful `created` and `closed` processing always invalidate caches, while update-style events invalidate after a new update note is applied. If exact reference duplicate-update behavior is awkward to preserve, record the source-backed tradeoff and prefer fail-closed freshness over stale merchant dispute counts.

- [ ] **Step 3: Run GREEN product gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsApiClientTest'
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandler.php src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeCacheService.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

Expected: focused PHP tests, PHPStan, and changed-PHP lint pass with no touched-file errors.

## Task 3: Harden The Dispute Financial Oracle

- [ ] **Step 1: Add WC order-note extraction**

In `financial-reconcile.sh`, extend the WC-side JSON to include recent order notes as strings. Keep the output structured; do not parse HTML with shell regexes outside the comparator.

- [ ] **Step 2: Change dispute comparison semantics**

In `financial-reconcile-normalize.py`, update `compare_dispute()` so provider dispute IDs with no WC dispute meta can pass only when the WC order has dispute side-effect evidence such as an order note containing `dispute`. Keep these failure modes: provider dispute exists with no dispute meta and no dispute side-effect evidence; WC dispute meta points to an ID absent from provider raw source; malformed JSON blocks the gate.

- [ ] **Step 3: Add fixture coverage**

Update `financial-reconcile-fixtures.sh` so the matching fixture includes a dispute note and add two explicit cases: no WC dispute ID plus a dispute note should pass, and no WC dispute ID plus no dispute note should fail when provider dispute state exists.

- [ ] **Step 4: Run harness fixture gates**

Run:

```bash
bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh
python3 -m py_compile tools/woopayments-merge/financial-reconcile-normalize.py
bash -n tools/woopayments-merge/financial-reconcile.sh
```

Expected: all fixture and syntax gates pass.

## Task 4: Add A Deterministic Provider-Created Dispute Gate

- [ ] **Step 1: Extend the flow driver**

Update `flow-drive.sh` so `dispute --deterministic` uses the existing deterministic charge drivers with `--type=dispute`; reference uses `flow-drive-deterministic-charge.php`, target native uses `flow-drive-native-charge.php`, and the emitted JSON sets `"op":"dispute"` while preserving `order_id`, `charge_id`, `intent_id`, and status fields.

- [ ] **Step 2: Add the cross-store dispute gate**

Create `dispute-e2e-gate.sh` with `--ref "<WP>" --target "<WP>"`. The script should drive one deterministic provider-created dispute on each store, print progress per attempt, poll each order until a dispute note appears and the created-dispute status is visible, then call `financial-reconcile.sh` for each order. Classify missing Stripe CLI/raw-source access as BLOCKED, missing order side effects as FAIL, and successful reference/target side effects plus reconciliation as PASS.

- [ ] **Step 3: Document the gate**

Update `HARNESS.md` to say the dispute e2e gate now covers provider-created dispute creation/order side effects on both stores. Keep payout listed as fail-closed because payout output is not order-anchored and native Test Lab payout remains unsupported.

- [ ] **Step 4: Run syntax and live gates**

Run:

```bash
bash -n tools/woopayments-merge/flow-drive.sh
bash -n tools/woopayments-merge/dispute-e2e-gate.sh
bash tools/woopayments-merge/dispute-e2e-gate.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Expected: the live gate either passes on both stores or exposes a product/runtime defect to fix before H26 is closed. Do not weaken the gate to hide webhook, note, Stripe, or cache bugs.

## Task 5: Review, Full Verification, Commit, And Session Logs

- [ ] **Step 1: Run focused review agents**

Dispatch reliability and API/architecture review over the product diff and harness behavior. Ask them to check cache invalidation parity, duplicate webhook behavior, financial oracle semantics, fail-closed classification, and whether payout remains honestly blocked.

- [ ] **Step 2: Run final gates**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsApiClientTest|OrderPaymentLifecycleServiceTest|NativeWooPaymentsGatewayTest|WooPaymentsProviderGatewayAdapterTest'
bash tools/woopayments-merge/tests/financial-reconcile-fixtures.sh
bash tools/woopayments-merge/tests/compare-measured-gates-fixtures.sh
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
git diff --check
```

Expected: all deterministic gates pass, or any failure becomes a source-backed fix before commit. Do not make a precise performance timing claim; record only structural or large-delta smoke evidence if observed.

- [ ] **Step 3: Commit tracked product changes**

If product files changed, add a WooCommerce changelog entry and commit the tracked source/tests/changelog as one logical product commit. Keep ignored harness changes local unless the user explicitly asks to publish them.

- [ ] **Step 4: Update session logs**

Append H26 to `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` with source, tests, harness evidence, review verdicts, git range for tracked product changes, remaining payout and converted-currency status, and the explicit measurement caveat that local timing was not used as proof.
