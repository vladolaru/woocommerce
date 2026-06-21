---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 00:19
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - plans/2026-06-17-core-native-payments-b3y.md
status: final
last_updated: 2026-06-17 00:42
---

# Core Native Payments B3z Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate the real local WooPayments plugin-to-native soft cutover path end to end, including Settings Payments and checkout frontend parity after handoff.

**Architecture:** This slice treats the target store as the product-level integration gate: temporarily activate the WooPayments plugin on the target while native runtime enablement remains on, prove the arbiter gives plugin runtime ownership before handoff, run the nonce-protected soft cutover action, then prove the target returns to native ownership without regressing the generic WooCommerce Payments settings page or WooPayments checkout surfaces. No code changes are planned; if a product regression appears, pause the handoff, capture root cause, add failing coverage, implement the minimal fix, and rerun the full gate set.

**Tech Stack:** WooCommerce Core PHP, wp-env Docker WP-CLI, WooCommerce admin/browser verification, restored local WooPayments merge harness, log scanning.

---

### Task 1: Snapshot Baseline State and Logs

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-17-core-native-payments-b3z.md`

- [x] **Step 1: Confirm tracked worktree cleanliness**

Run:

```bash
git status --short
```

Expected: no tracked source diffs. Ignored scratchpad and harness files may remain local.

- [x] **Step 2: Snapshot target native-owned state**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 eval '$c=wc_get_container(); $a=$c->get( Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::class ); $p=$c->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::class ); $co=$c->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class ); echo "owner=".$a->get_runtime_owner()."\n"; echo "native_enabled=".($a->is_native_runtime_enabled()?"yes":"no")."\n"; echo "plugin_runtime=".($a->is_plugin_runtime_active()?"yes":"no")."\n"; echo "provider_ready=".($p->can_process_payments()?"yes":"no")."\n"; echo "preflight=".implode(",", $co->get_preflight_failures())."\n";'
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 plugin status woocommerce-payments
```

Expected: `owner=native`, native enabled, plugin runtime inactive, provider ready, empty preflight, and WooPayments plugin inactive.

- [x] **Step 3: Snapshot target log baselines**

Run:

```bash
docker logs --since 2m 24860d14de30dc62f7b324ebef10b5fb-wordpress-1 > "$TMPDIR/b3z-target-wordpress-before.log" 2>&1 || true
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-wordpress-1 sh -lc 'test -f /var/www/html/wp-content/debug.log && stat -c "%Y %s" /var/www/html/wp-content/debug.log || true'
```

Expected: baseline captured for later comparison. Any existing notices must be timestamp-dispositioned before final claims.

### Task 2: Validate Plugin-Active Preflight

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-17-core-native-payments-b3z.md`

- [x] **Step 1: Temporarily activate WooPayments on the target local store**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 plugin activate woocommerce-payments
```

Expected: plugin activates locally. This must not touch the reference store or WPCOM.

- [x] **Step 2: Prove plugin runtime owns while native remains ready**

Run the same state probe from Task 1 Step 2.

Expected: `owner=plugin`, native enabled, plugin runtime active, provider ready, empty preflight. If preflight is non-empty or provider readiness breaks, stop and root-cause before cutover.

- [x] **Step 3: Prove soft cutover is visible to capable administrators**

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 eval '$c=wc_get_container(); $co=$c->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class ); echo "soft_notice=".($co->should_show_soft_cutover_notice()?"yes":"no")."\n"; echo "preflight=".implode(",", $co->get_preflight_failures())."\n";'
```

Expected: `soft_notice=yes` and empty preflight.

- [x] **Step 4: Browser-check Settings Payments while plugin-owned**

Open `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout` in the authenticated browser session.

Expected: provider list loads, WooPayments appears once in the generic provider flow, and there are no fresh JS console errors or failed Settings Payments provider requests.

### Task 3: Execute Soft Cutover and Re-Verify Native UX

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-17-core-native-payments-b3z.md`

- [x] **Step 1: Execute the same nonce-protected disable action used by the admin notice**

Run a WP-CLI request that sets the same query action and nonce consumed by `WooPaymentsCutoverController::handle_admin_init()`:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 eval '$c=wc_get_container(); $co=$c->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::class ); $_GET[ Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::QUERY_ACTION ] = Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::ACTION_DISABLE; $_GET[ Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::NONCE_NAME ] = wp_create_nonce( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController::NONCE_ACTION ); $_REQUEST = $_GET; $co->handle_admin_init();'
```

Expected: command exits 0 and deactivates WooPayments. The controller redirects through `wp_safe_redirect()` and exits after the state change, so verify state in the next step.

- [x] **Step 2: Prove target returned to native ownership**

Run the state and plugin probes from Task 1 Step 2.

Expected: `owner=native`, plugin inactive, native enabled, provider ready, empty preflight.

- [x] **Step 3: Browser-check Settings Payments after native handoff**

Open `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout`.

Expected: provider list loads with one Core-owned WooPayments row, Test account badge/state still present, no duplicate `_wc_pes_woopayments` suggestion, and no fresh JS console errors or failed provider requests.

- [x] **Step 4: Browser-check classic and Blocks checkout after native handoff**

Open the target classic checkout and target Blocks checkout URLs used in B3x.

Expected: WooPayments remains selectable; the account test mode UI remains visible; classic shows `Card + 3`, Core card icons, and copyable `4242 4242 4242 4242`; Blocks shows the Test Mode badge, `Card`, card icons, and copyable test card instructions.

### Task 4: Run Harness, Logs, and Decide Whether Code Is Needed

**Files:**
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-17-core-native-payments-b3z.md`
- If needed: production/test files implicated by a reproduced regression.

- [x] **Step 1: Run the restored cross-store harness**

Run:

```bash
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Expected: PASS all deterministic gates.

- [x] **Step 2: Scan logs since the baseline**

Run:

```bash
docker logs --since 10m 24860d14de30dc62f7b324ebef10b5fb-wordpress-1 2>&1 | rg -i 'PHP (Notice|Warning|Deprecated|Fatal)|Fatal error|Uncaught|Stack trace|database error|doing_it_wrong|Undefined '
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-wordpress-1 sh -lc 'test -f /var/www/html/wp-content/debug.log && tail -n 200 /var/www/html/wp-content/debug.log || true'
```

Expected: no fresh undispositioned notices, warnings, fatals, stack traces, or database errors from the B3z window. Known local `sendmail` noise may be dispositioned only if it is the same local mail-send failure already seen during order email handling.

- [x] **Step 3: If a regression appears, switch to TDD fix mode**

Use `systematic-debugging` to identify root cause, then add the narrow failing test before editing production code. Re-run the relevant focused test, static checks, browser checks, harness, log scan, and request a code review before committing.

- [x] **Step 4: Record final verification and leave target native-owned**

Update the implementation log, staging log, README, and this plan with final evidence. Do not commit scratchpad-only verification notes unless explicitly requested.
